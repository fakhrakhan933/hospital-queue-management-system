<?php
/**
 * Action: Undo Last Supported Reversible Action (Stack LIFO)
 * Role Authorization: Admin, Receptionist, Queue Operator
 *
 * Reverses supported recent operations (recent Cancelled entry or Waiting insertion)
 * using the custom LIFO Stack data structure.
 * Re-verifies DB state under row-level lock and records an audit log entry.
 * Explicitly guards against corrupting audit history or unsafely undoing completed consultations.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/Stack.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

// 1. Enforce Role Authorization
requireRole(['admin', 'receptionist', 'operator']);

// 2. Validate CSRF Token
$submittedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
if (!validateCsrfToken($submittedCsrf)) {
    header('Location: ../modules/history.php?error=' . urlencode('Security validation failed (invalid or expired CSRF token).'));
    exit;
}

// 3. Initialize Stack and load session actions
$stack = new Stack();
$sessionStack = $_SESSION['undo_stack'] ?? [];
foreach ($sessionStack as $actionItem) {
    $stack->push($actionItem);
}

if ($stack->isEmpty()) {
    header('Location: ../modules/history.php?error=' . urlencode('No recent reversible actions in the undo stack.'));
    exit;
}

// 4. Pop the most recent action (LIFO)
$lastAction = $stack->pop();
$actionType = $lastAction['action_type'] ?? '';
$queueId = filter_var($lastAction['queue_id'] ?? null, FILTER_VALIDATE_INT);
$tokenNumber = $lastAction['token_number'] ?? 'Unknown';

if (!$queueId) {
    // Remove invalid top item from session stack
    $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
    header('Location: ../modules/history.php?error=' . urlencode('Invalid entry found on the undo stack.'));
    exit;
}

try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    // Re-verify current database state with row lock (FOR UPDATE)
    $stmt = $pdo->prepare("
        SELECT queue_id, token_number, status, department_id, patient_id 
        FROM queue_entries 
        WHERE queue_id = :id 
        FOR UPDATE
    ");
    $stmt->execute([':id' => $queueId]);
    $entry = $stmt->fetch();

    if (!$entry) {
        $pdo->rollBack();
        $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
        header('Location: ../modules/history.php?error=' . urlencode("Cannot undo: Queue entry for Token {$tokenNumber} no longer exists."));
        exit;
    }

    // STRICT SAFETY CHECK: Completed consultations can NEVER be undone
    if ($entry['status'] === 'Completed') {
        $pdo->rollBack();
        $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
        header('Location: ../modules/history.php?error=' . urlencode("Security restriction: Token {$entry['token_number']} has already completed consultation and cannot be undone."));
        exit;
    }

    $userId = $_SESSION['user_id'] ?? null;
    $successMessage = '';

    if ($actionType === 'cancel') {
        // Reverse a recent cancellation: restore status from 'Cancelled' back to 'Waiting'
        if ($entry['status'] !== 'Cancelled') {
            $pdo->rollBack();
            $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
            header('Location: ../modules/history.php?error=' . urlencode("Cannot undo: Token {$entry['token_number']} is currently in '{$entry['status']}' state (expected 'Cancelled')."));
            exit;
        }

        // Restore to Waiting status
        $restoreStmt = $pdo->prepare("UPDATE queue_entries SET status = 'Waiting' WHERE queue_id = :id");
        $restoreStmt->execute([':id' => $queueId]);

        // Record Undo audit log
        $logStmt = $pdo->prepare("
            INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
            VALUES (:qid, 'Undo', NOW(), :uid, :notes)
        ");
        $logStmt->execute([
            ':qid'   => $queueId,
            ':uid'   => $userId,
            ':notes' => "Reversed cancellation via Stack LIFO undo for Token {$entry['token_number']}. Restored to Waiting queue."
        ]);

        $pdo->commit();

        // Safely update in-memory data structures from DB
        QueueRebuilder::rebuildDepartmentState($pdo, (int)$entry['department_id']);

        // Update session stack
        $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));

        $successMessage = "Successfully undid cancellation for Token {$entry['token_number']}. Restored to Waiting queue.";

    } elseif ($actionType === 'register') {
        // Reverse a recent Waiting insertion / registration
        if ($entry['status'] !== 'Waiting') {
            $pdo->rollBack();
            $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
            header('Location: ../modules/history.php?error=' . urlencode("Cannot undo registration: Token {$entry['token_number']} is currently '{$entry['status']}' (can only undo while Waiting)."));
            exit;
        }

        // Safely mark as Cancelled so audit trail remains intact
        $cancelStmt = $pdo->prepare("UPDATE queue_entries SET status = 'Cancelled' WHERE queue_id = :id");
        $cancelStmt->execute([':id' => $queueId]);

        // Record Undo audit log
        $logStmt = $pdo->prepare("
            INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
            VALUES (:qid, 'Undo', NOW(), :uid, :notes)
        ");
        $logStmt->execute([
            ':qid'   => $queueId,
            ':uid'   => $userId,
            ':notes' => "Reversed Waiting registration via Stack LIFO undo for Token {$entry['token_number']}."
        ]);

        $pdo->commit();

        // Safely update in-memory data structures from DB
        QueueRebuilder::rebuildDepartmentState($pdo, (int)$entry['department_id']);

        // Update session stack
        $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));

        $successMessage = "Successfully undid registration for Token {$entry['token_number']}. Removed from active Waiting queue.";

    } else {
        $pdo->rollBack();
        $_SESSION['undo_stack'] = array_values(array_slice($sessionStack, 0, -1));
        header('Location: ../modules/history.php?error=' . urlencode('Unsupported action type for Stack undo.'));
        exit;
    }

    header('Location: ../modules/history.php?msg=' . urlencode($successMessage));
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Undo operation failure: " . $e->getMessage());
    header('Location: ../modules/history.php?error=' . urlencode('Undo failed: ' . $e->getMessage()));
    exit;
}
