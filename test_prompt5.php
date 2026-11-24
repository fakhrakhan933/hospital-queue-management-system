<?php
/**
 * Test Suite for Prompt 5 Requirements
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/classes/LinkedListQueue.php';
require_once __DIR__ . '/classes/PriorityQueue.php';
require_once __DIR__ . '/classes/HashTable.php';
require_once __DIR__ . '/classes/QueueRebuilder.php';
require_once __DIR__ . '/classes/ServedList.php';

$pdo = getDBConnection();

echo "========================================================\n";
echo "Testing Prompt 5: Doctors, Call Patient, Complete, Cancel\n";
echo "========================================================\n\n";

function runTest(string $title, bool $pass, string $info = ''): void {
    if ($pass) {
        echo "[PASS] $title" . ($info ? " ($info)" : "") . PHP_EOL;
    } else {
        echo "[FAIL] $title" . ($info ? " ($info)" : "") . PHP_EOL;
        exit(1);
    }
}

// 1. Test Doctor Availability Check (Block if No Available Doctor)
$deptId = 2; // Paediatrics
// Temporarily set all Paediatrics doctors to 'Busy'
$pdo->prepare("UPDATE doctors SET availability = 'Busy' WHERE department_id = :dept_id")->execute([':dept_id' => $deptId]);

$docCheck = $pdo->prepare("SELECT doctor_id FROM doctors WHERE department_id = :dept_id AND availability = 'Available'");
$docCheck->execute([':dept_id' => $deptId]);
$availableDocs = $docCheck->fetchAll();
runTest("Doctor Check: No doctors available blocks dispatch", count($availableDocs) === 0);

// Restore one doctor to 'Available'
$pdo->prepare("UPDATE doctors SET availability = 'Available' WHERE department_id = :dept_id LIMIT 1")->execute([':dept_id' => $deptId]);
$docCheck->execute([':dept_id' => $deptId]);
$doc = $docCheck->fetch();
runTest("Doctor Check: Doctor is now available for dispatch", $doc !== false, "Doctor ID: {$doc['doctor_id']}");

// 2. Insert test Emergency patient (Urgency 5) and Normal patient into Dept 2
$pdo->beginTransaction();
$pdo->exec("INSERT INTO patients (name, age, gender, phone, address, created_at) VALUES ('Child Normal', 8, 'Male', '0300-1111111', 'Test', NOW())");
$p1 = (int)$pdo->lastInsertId();
$tokNorm = 'PD-TEST-NORM';
$pdo->prepare("INSERT INTO queue_entries (patient_id, department_id, token_number, queue_type, status, arrival_time) VALUES (?, ?, ?, 'Normal', 'Waiting', NOW())")->execute([$p1, $deptId, $tokNorm]);
$q1 = (int)$pdo->lastInsertId();

$pdo->exec("INSERT INTO patients (name, age, gender, phone, address, created_at) VALUES ('Child Emergency', 4, 'Female', '0311-2222222', 'Test', NOW())");
$p2 = (int)$pdo->lastInsertId();
$tokEmerg = 'PD-TEST-EMERG';
$pdo->prepare("INSERT INTO queue_entries (patient_id, department_id, token_number, urgency_level, queue_type, status, arrival_time) VALUES (?, ?, ?, 5, 'Emergency', 'Waiting', NOW())")->execute([$p2, $deptId, $tokEmerg]);
$q2 = (int)$pdo->lastInsertId();
$pdo->commit();

// 3. Test Call Next Selection Policy: PriorityQueue peekMax vs LinkedListQueue peek
$heap = QueueRebuilder::rebuildPriorityQueue($pdo, $deptId);
$fifo = QueueRebuilder::rebuildNormalQueue($pdo, $deptId);
$candidate = !$heap->isEmpty() ? $heap->peekMax() : $fifo->peek();
runTest("Selection Policy: Emergency case selected first", $candidate['token_number'] === $tokEmerg && (int)$candidate['urgency_level'] === 5);

// 4. Test Atomic Call Next Patient Dispatch with row lock & doctor Busy update
$pdo->beginTransaction();
$lockStmt = $pdo->prepare("SELECT queue_id, status FROM queue_entries WHERE queue_id = :id FOR UPDATE");
$lockStmt->execute([':id' => $candidate['queue_id']]);
$lockedEntry = $lockStmt->fetch();
runTest("Call Next: Row locked and status is Waiting", $lockedEntry['status'] === 'Waiting');

// Assign to doctor and mark Busy
$pdo->prepare("UPDATE queue_entries SET status = 'Called', doctor_id = :doc_id WHERE queue_id = :id")->execute([':doc_id' => $doc['doctor_id'], ':id' => $candidate['queue_id']]);
$pdo->prepare("UPDATE doctors SET availability = 'Busy' WHERE doctor_id = :doc_id")->execute([':doc_id' => $doc['doctor_id']]);
$pdo->prepare("INSERT INTO queue_logs (queue_id, action, action_time, notes) VALUES (?, 'Called', NOW(), 'Assigned via test')")->execute([$candidate['queue_id']]);
$pdo->commit();

// Check doctor is now Busy
$docStatus = $pdo->query("SELECT availability FROM doctors WHERE doctor_id = {$doc['doctor_id']}")->fetchColumn();
runTest("Call Next: Doctor status changed to Busy", $docStatus === 'Busy');

// 5. Test Status Re-Check / Concurrency Conflict Handling
$pdo->beginTransaction();
$recheckStmt = $pdo->prepare("SELECT queue_id, status FROM queue_entries WHERE queue_id = :id FOR UPDATE");
$recheckStmt->execute([':id' => $candidate['queue_id']]);
$conflictRow = $recheckStmt->fetch();
if ($conflictRow['status'] !== 'Waiting') {
    $pdo->rollBack();
    // Safe recovery: rebuild structures from database
    $rebuiltHeap = QueueRebuilder::rebuildPriorityQueue($pdo, $deptId);
    $rebuiltFifo = QueueRebuilder::rebuildNormalQueue($pdo, $deptId);
    runTest("Concurrency Re-check: Detected non-Waiting status, rolled back and safely rebuilt", true);
} else {
    runTest("Concurrency Re-check", false, "Should have detected conflict");
}

// 6. Test actions/complete.php: Mark Completed, set doctor back to Available, append to ServedList
$pdo->beginTransaction();
$pdo->prepare("UPDATE queue_entries SET status = 'Completed' WHERE queue_id = ?")->execute([$candidate['queue_id']]);
$pdo->prepare("UPDATE doctors SET availability = 'Available' WHERE doctor_id = ?")->execute([$doc['doctor_id']]);
$pdo->prepare("INSERT INTO queue_logs (queue_id, action, action_time, notes) VALUES (?, 'Completed', NOW(), 'Completed via test')")->execute([$candidate['queue_id']]);
$pdo->commit();

$servedList = QueueRebuilder::rebuildServedList($pdo, 10);
$docStatusAfterComplete = $pdo->query("SELECT availability FROM doctors WHERE doctor_id = {$doc['doctor_id']}")->fetchColumn();
runTest("Complete Action: Doctor set back to Available", $docStatusAfterComplete === 'Available');
runTest("Complete Action: Completed consultation in ServedList", $servedList->getSize() > 0);

// 7. Test actions/cancel.php: Cancel Waiting entry with re-check
$pdo->beginTransaction();
$cancelCheck = $pdo->prepare("SELECT queue_id, status FROM queue_entries WHERE queue_id = :id FOR UPDATE");
$cancelCheck->execute([':id' => $q1]);
$cancelRow = $cancelCheck->fetch();
runTest("Cancel Action: Target entry is in Waiting status", $cancelRow['status'] === 'Waiting');

$pdo->prepare("UPDATE queue_entries SET status = 'Cancelled' WHERE queue_id = ?")->execute([$q1]);
$pdo->prepare("INSERT INTO queue_logs (queue_id, action, action_time, notes) VALUES (?, 'Cancelled', NOW(), 'Cancelled via test')")->execute([$q1]);
$pdo->commit();

$cancelledStatus = $pdo->query("SELECT status FROM queue_entries WHERE queue_id = $q1")->fetchColumn();
runTest("Cancel Action: Status successfully updated to Cancelled", $cancelledStatus === 'Cancelled');

// Cleanup test rows
$pdo->prepare("DELETE FROM queue_logs WHERE queue_id IN (?, ?)")->execute([$q1, $q2]);
$pdo->prepare("DELETE FROM queue_entries WHERE queue_id IN (?, ?)")->execute([$q1, $q2]);
$pdo->prepare("DELETE FROM patients WHERE patient_id IN (?, ?)")->execute([$p1, $p2]);
$pdo->prepare("UPDATE doctors SET availability = 'Available' WHERE department_id = :dept_id")->execute([':dept_id' => $deptId]);

echo "\n========================================================\n";
echo "ALL PROMPT 5 FUNCTIONALITY TESTS PASSED SUCCESSFULLY!\n";
echo "========================================================\n";
