<?php
/**
 * Action: Register Patient, Generate Token, and Update Queues
 * Role Authorization: Admin, Receptionist
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/LinkedListQueue.php';
require_once __DIR__ . '/../classes/PriorityQueue.php';
require_once __DIR__ . '/../classes/HashTable.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

// 1. Enforce Role Authorization
requireRole(['admin', 'receptionist']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../modules/add_patient.php');
    exit;
}

// 2. CSRF Security Validation
$submittedCsrf = $_POST['csrf_token'] ?? '';
if (!validateCsrfToken($submittedCsrf)) {
    header('Location: ../modules/add_patient.php?error=' . urlencode('Security validation failed (invalid or expired session token).'));
    exit;
}

// 3. Extract & Validate Form Inputs
$name = trim($_POST['name'] ?? '');
$age = filter_var($_POST['age'] ?? null, FILTER_VALIDATE_INT);
$gender = trim($_POST['gender'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
$address = trim($_POST['address'] ?? '');
$queueType = trim($_POST['queue_type'] ?? 'Normal');

// Validation rules
if (empty($name) || strlen($name) > 120) {
    header('Location: ../modules/add_patient.php?error=' . urlencode('Please enter a valid patient full name (up to 120 characters).'));
    exit;
}

if ($age === false || $age < 0 || $age > 130) {
    header('Location: ../modules/add_patient.php?error=' . urlencode('Please enter a valid patient age between 0 and 130.'));
    exit;
}

if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
    header('Location: ../modules/add_patient.php?error=' . urlencode('Please select a valid gender option.'));
    exit;
}

if (!$departmentId || $departmentId <= 0) {
    header('Location: ../modules/add_patient.php?error=' . urlencode('Please select a valid hospital department.'));
    exit;
}

if (!in_array($queueType, ['Normal', 'Emergency'], true)) {
    $queueType = 'Normal';
}

// Staff-assigned urgency level: only applicable for Emergency cases (1-5)
$urgencyLevel = null;
if ($queueType === 'Emergency') {
    $urgencyLevel = filter_var($_POST['urgency_level'] ?? 5, FILTER_VALIDATE_INT);
    if ($urgencyLevel === false || $urgencyLevel < 1 || $urgencyLevel > 5) {
        $urgencyLevel = 5; // Default to maximum priority if unspecified
    }
}

$userId = $_SESSION['user_id'] ?? null;

try {
    $pdo = getDBConnection();

    // 4. Begin Database Transaction for Atomic Registration & Safe Token Generation
    $pdo->beginTransaction();

    // Verify department exists and obtain its code with row lock
    $deptStmt = $pdo->prepare("SELECT department_id, department_name, department_code FROM departments WHERE department_id = :dept_id FOR UPDATE");
    $deptStmt->execute([':dept_id' => $departmentId]);
    $dept = $deptStmt->fetch();

    if (!$dept) {
        $pdo->rollBack();
        header('Location: ../modules/add_patient.php?error=' . urlencode('The selected department does not exist.'));
        exit;
    }

    $deptCode = strtoupper(trim($dept['department_code'] ?: 'TK'));

    // A. Insert into patients table
    $patientStmt = $pdo->prepare("
        INSERT INTO patients (name, age, gender, phone, address, created_at)
        VALUES (:name, :age, :gender, :phone, :address, NOW())
    ");
    $patientStmt->execute([
        ':name'    => $name,
        ':age'     => $age,
        ':gender'  => $gender,
        ':phone'   => !empty($phone) ? $phone : null,
        ':address' => !empty($address) ? $address : null
    ]);
    $patientId = (int)$pdo->lastInsertId();

    // B. Safely generate a unique department-based token (e.g. GM-001) with UNIQUE constraint protection
    $seqStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM queue_entries 
        WHERE department_id = :dept_id
    ");
    $seqStmt->execute([':dept_id' => $departmentId]);
    $nextSequence = (int)$seqStmt->fetchColumn() + 1;

    // Guarantee uniqueness by checking existence and incrementing monotonically
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM queue_entries WHERE token_number = :token");
    do {
        $tokenNumber = sprintf("%s-%03d", $deptCode, $nextSequence);
        $checkStmt->execute([':token' => $tokenNumber]);
        $isDuplicate = (int)$checkStmt->fetchColumn() > 0;
        if ($isDuplicate) {
            $nextSequence++;
        }
    } while ($isDuplicate);

    // C. Insert into queue_entries table (status = 'Waiting')
    $queueStmt = $pdo->prepare("
        INSERT INTO queue_entries (patient_id, department_id, token_number, urgency_level, queue_type, arrival_time, status)
        VALUES (:patient_id, :department_id, :token_number, :urgency_level, :queue_type, NOW(), 'Waiting')
    ");
    $queueStmt->execute([
        ':patient_id'    => $patientId,
        ':department_id' => $departmentId,
        ':token_number'  => $tokenNumber,
        ':urgency_level' => $urgencyLevel,
        ':queue_type'    => $queueType
    ]);
    $queueId = (int)$pdo->lastInsertId();

    // D. Log operation to queue_logs
    $logStmt = $pdo->prepare("
        INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
        VALUES (:queue_id, 'Registered', NOW(), :performed_by, :notes)
    ");
    $logNotes = sprintf(
        "Registered as %s patient%s",
        $queueType,
        ($urgencyLevel !== null ? " (Staff Urgency Level: {$urgencyLevel})" : "")
    );
    $logStmt->execute([
        ':queue_id'     => $queueId,
        ':performed_by' => $userId,
        ':notes'        => $logNotes
    ]);

    // Commit all persistent changes atomically
    $pdo->commit();

    // 5. In-Memory Data Structure Operations:
    // Insert into LinkedListQueue or PriorityQueue plus HashTable
    $entryRecord = [
        'queue_id'        => $queueId,
        'patient_id'      => $patientId,
        'department_id'   => $departmentId,
        'doctor_id'       => null,
        'token_number'    => $tokenNumber,
        'urgency_level'   => $urgencyLevel,
        'queue_type'      => $queueType,
        'arrival_time'    => date('Y-m-d H:i:s'),
        'status'          => 'Waiting',
        'patient_name'    => $name,
        'age'             => $age,
        'gender'          => $gender,
        'phone'           => $phone,
        'department_name' => $dept['department_name'],
        'department_code' => $deptCode
    ];

    // Rebuild active in-memory department queue state
    $deptQueues = QueueRebuilder::rebuildDepartmentState($pdo, $departmentId);

    if ($queueType === 'Emergency') {
        // Emergency-designated cases enter Max Heap PriorityQueue
        $deptQueues['priorityQueue']->insert($entryRecord);
    } else {
        // Normal cases enter FIFO LinkedListQueue
        $deptQueues['normalQueue']->enqueue($entryRecord);
    }

    // Fast O(1) Token Lookup Table
    $deptQueues['hashTable']->insert($tokenNumber, $entryRecord);

    // Record to Undo Stack in session for reversible recovery
    if (!isset($_SESSION['undo_stack'])) {
        $_SESSION['undo_stack'] = [];
    }
    $_SESSION['undo_stack'][] = [
        'action_type'     => 'register',
        'queue_id'        => $queueId,
        'patient_id'      => $patientId,
        'token_number'    => $tokenNumber,
        'department_id'   => $departmentId,
        'timestamp'       => time()
    ];

    // Redirect to registration form with success query parameter
    header('Location: ../modules/add_patient.php?success=1&token=' . urlencode($tokenNumber));
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Patient registration failure: " . $e->getMessage());
    header('Location: ../modules/add_patient.php?error=' . urlencode('Registration failed: ' . $e->getMessage()));
    exit;
}
