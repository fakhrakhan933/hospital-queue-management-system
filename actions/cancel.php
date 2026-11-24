<?php
/**
 * Action: Cancel a Waiting Queue Entry
 * Role Authorization: Admin, Receptionist, Queue Operator
 * Safely cancels waiting entries, updates audit logs, and pushes to undo stack
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/Stack.php';

// Enforce role authorization
requireRole(['admin', 'receptionist', 'operator']);

// Validate CSRF Token
$submittedCsrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
$deptId = filter_var($_POST['dept'] ?? $_GET['dept'] ?? null, FILTER_VALIDATE_INT);
$redirectPrefix = $deptId ? "../modules/queue.php?dept=$deptId&" : "../modules/queue.php?";

if (!validateCsrfToken($submittedCsrf)) {
    header('Location: ' . $redirectPrefix . 'error=' . urlencode('Security validation failed (invalid CSRF token).'));
    exit;
}

$queueId = filter_var($_POST['queue_id'] ?? $_GET['queue_id'] ?? null, FILTER_VALIDATE_INT);
if (!$queueId) {
    header('Location: ' . $redirectPrefix . 'error=' . urlencode('Invalid queue ID.'));
    exit;
}

try {
    $pdo = getDBConnection();
    $pdo->beginTransaction();

    // 1. Re-check status = 'Waiting' inside transaction with row lock to prevent race conditions
    $stmt = $pdo->prepare("SELECT queue_id, token_number, status, department_id FROM queue_entries WHERE queue_id = :id FOR UPDATE");
    $stmt->execute([':id' => $queueId]);
    $entry = $stmt->fetch();

    if (!$entry) {
        $pdo->rollBack();
        header('Location: ' . $redirectPrefix . 'error=' . urlencode('Queue entry not found.'));
        exit;
    }

    if ($entry['status'] !== 'Waiting') {
        $pdo->rollBack();
        header('Location: ' . $redirectPrefix . 'error=' . urlencode('Only waiting entries can be cancelled. Current status is ' . htmlspecialchars($entry['status'])));
        exit;
    }

    // 2. Update status to Cancelled
    $upStmt = $pdo->prepare("UPDATE queue_entries SET status = 'Cancelled' WHERE queue_id = :id");
    $upStmt->execute([':id' => $queueId]);

    // 3. Write event to queue_logs
    $logStmt = $pdo->prepare("
        INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
        VALUES (:qid, 'Cancelled', NOW(), :uid, :notes)
    ");
    $logStmt->execute([
        ':qid'          => $queueId,
        ':uid'          => $_SESSION['user_id'] ?? null,
        ':notes'        => 'Waiting entry cancelled by staff'
    ]);

    // 4. Save to Undo Stack in session for reversible recovery
    if (!isset($_SESSION['undo_stack'])) {
        $_SESSION['undo_stack'] = [];
    }
    $_SESSION['undo_stack'][] = [
        'action_type'     => 'cancel',
        'queue_id'        => $queueId,
        'previous_status' => 'Waiting',
        'token_number'    => $entry['token_number'],
        'timestamp'       => time()
    ];

    $pdo->commit();

    header('Location: ' . $redirectPrefix . 'msg=' . urlencode("Token {$entry['token_number']} cancelled successfully. You can undo this action from History."));
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Cancellation failure: " . $e->getMessage());
    header('Location: ' . $redirectPrefix . 'error=' . urlencode('Error cancelling entry: ' . $e->getMessage()));
    exit;
}
