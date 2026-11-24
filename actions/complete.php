<?php
/**
 * Action: Mark Consultation Completed
 * Role Authorization: Admin, Queue Operator
 * Frees doctor back to Available status, appends to ServedList, and logs event
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/ServedList.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

// Enforce role authorization
requireRole(['admin', 'operator']);

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

    // 1. Re-read queue entry with row lock
    $stmt = $pdo->prepare("SELECT * FROM queue_entries WHERE queue_id = :id FOR UPDATE");
    $stmt->execute([':id' => $queueId]);
    $entry = $stmt->fetch();

    if (!$entry) {
        $pdo->rollBack();
        header('Location: ' . $redirectPrefix . 'error=' . urlencode('Queue entry not found.'));
        exit;
    }

    if (!in_array($entry['status'], ['Called', 'In Consultation'], true)) {
        $pdo->rollBack();
        header('Location: ' . $redirectPrefix . 'error=' . urlencode('Only active consultations (Called/In Consultation) can be completed.'));
        exit;
    }

    // 2. Mark status Completed in database
    $upQ = $pdo->prepare("UPDATE queue_entries SET status = 'Completed' WHERE queue_id = :id");
    $upQ->execute([':id' => $queueId]);

    // 3. Free Doctor back to Available status
    if (!empty($entry['doctor_id'])) {
        $upDoc = $pdo->prepare("UPDATE doctors SET availability = 'Available' WHERE doctor_id = :doc_id");
        $upDoc->execute([':doc_id' => $entry['doctor_id']]);
    }

    // 4. Log the completion to queue_logs
    $logStmt = $pdo->prepare("
        INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
        VALUES (:qid, 'Completed', NOW(), :uid, :notes)
    ");
    $logStmt->execute([
        ':qid'          => $queueId,
        ':uid'          => $_SESSION['user_id'] ?? null,
        ':notes'        => 'Consultation marked completed; doctor availability restored to Available'
    ]);

    // Commit transaction
    $pdo->commit();

    // 5. Append to in-memory ServedList
    $servedList = QueueRebuilder::rebuildServedList($pdo, 50);
    $servedList->append([
        'queue_id'       => $queueId,
        'token_number'   => $entry['token_number'],
        'queue_type'     => $entry['queue_type'],
        'arrival_time'   => $entry['arrival_time'],
        'completed_time' => date('Y-m-d H:i:s'),
        'doctor_id'      => $entry['doctor_id']
    ]);

    header('Location: ' . $redirectPrefix . 'msg=' . urlencode("Consultation for Token {$entry['token_number']} marked completed. Doctor is now Available."));
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Consultation completion failure: " . $e->getMessage());
    header('Location: ' . $redirectPrefix . 'error=' . urlencode('Error completing consultation: ' . $e->getMessage()));
    exit;
}
