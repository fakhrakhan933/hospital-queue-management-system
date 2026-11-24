<?php
/**
 * Call Next Patient Module
 * Role Authorization: Admin, Queue Operator
 * Implements Algorithm from Section 12 of Requirements Document
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/LinkedListQueue.php';
require_once __DIR__ . '/../classes/PriorityQueue.php';
require_once __DIR__ . '/../classes/HashTable.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

// Enforce role authorization
requireRole(['admin', 'operator']);

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();
$departments = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC")->fetchAll();
$selectedDeptId = filter_var($_GET['dept'] ?? ($departments[0]['department_id'] ?? 1), FILTER_VALIDATE_INT);

// Department details
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = :id");
$deptStmt->execute([':id' => $selectedDeptId]);
$dept = $deptStmt->fetch();

$message = '';
$messageType = '';

// Handle Call Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'call_next') {
    // 1. CSRF Protection Check
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Security validation failed. Please refresh the page and try again.";
        $messageType = "danger";
    } else {
        $targetDeptId = filter_var($_POST['department_id'] ?? $selectedDeptId, FILTER_VALIDATE_INT);
        $selectedDoctorId = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);

        // Step 1: Pre-check if an Available doctor exists in the selected department.
        // If none is available, do NOT remove or call any patient and display a clear message.
        if ($selectedDoctorId) {
            $docCheckStmt = $pdo->prepare("SELECT doctor_id, name FROM doctors WHERE doctor_id = :doc_id AND department_id = :dept_id AND availability = 'Available'");
            $docCheckStmt->execute([':doc_id' => $selectedDoctorId, ':dept_id' => $targetDeptId]);
            $availableDoctor = $docCheckStmt->fetch();
        } else {
            $docCheckStmt = $pdo->prepare("SELECT doctor_id, name FROM doctors WHERE department_id = :dept_id AND availability = 'Available' LIMIT 1");
            $docCheckStmt->execute([':dept_id' => $targetDeptId]);
            $availableDoctor = $docCheckStmt->fetch();
        }

        if (!$availableDoctor) {
            $message = "Cannot call patient: No doctor is currently available in " . htmlspecialchars($dept['department_name'] ?? 'this department', ENT_QUOTES, 'UTF-8') . ". Please update doctor availability before calling.";
            $messageType = "danger";
        } else {
            // Step 2: Available doctor exists -> Start a DB transaction
            try {
                $pdo->beginTransaction();

                // Re-lock the doctor row inside the transaction to avoid concurrency conflicts
                $docLockStmt = $pdo->prepare("SELECT doctor_id, name FROM doctors WHERE doctor_id = :doc_id AND availability = 'Available' FOR UPDATE");
                $docLockStmt->execute([':doc_id' => $availableDoctor['doctor_id']]);
                $lockedDoctor = $docLockStmt->fetch();

                if (!$lockedDoctor) {
                    $pdo->rollBack();
                    $message = "Selected doctor became unavailable concurrently. Please select another doctor or retry.";
                    $messageType = "warning";
                } else {
                    // Step 3: In-memory structure selection:
                    // Select highest-priority Waiting entry from PriorityQueue (or oldest from LinkedListQueue if none)
                    $heap = QueueRebuilder::rebuildPriorityQueue($pdo, $targetDeptId);
                    $fifo = QueueRebuilder::rebuildNormalQueue($pdo, $targetDeptId);
                    $hashTable = QueueRebuilder::rebuildHashTable($pdo);

                    $candidate = null;
                    $isEmergencyCandidate = false;

                    if (!$heap->isEmpty()) {
                        $candidate = $heap->peekMax();
                        $isEmergencyCandidate = true;
                    } elseif (!$fifo->isEmpty()) {
                        $candidate = $fifo->peek();
                        $isEmergencyCandidate = false;
                    }

                    if (!$candidate) {
                        $pdo->rollBack();
                        $message = "No waiting patients currently in the " . htmlspecialchars($dept['department_name'] ?? 'department', ENT_QUOTES, 'UTF-8') . " queue.";
                        $messageType = "warning";
                    } else {
                        // Step 4: Re-read the row inside the transaction with a lock/status check (status = 'Waiting')
                        $checkStmt = $pdo->prepare("SELECT queue_id, token_number, status, patient_id FROM queue_entries WHERE queue_id = :qid FOR UPDATE");
                        $checkStmt->execute([':qid' => $candidate['queue_id']]);
                        $freshRecord = $checkStmt->fetch();

                        if (!$freshRecord || $freshRecord['status'] !== 'Waiting') {
                            // Status mismatch! Rollback and rebuild in-memory structures from MySQL safely
                            $pdo->rollBack();
                            $heap = QueueRebuilder::rebuildPriorityQueue($pdo, $targetDeptId);
                            $fifo = QueueRebuilder::rebuildNormalQueue($pdo, $targetDeptId);
                            $hashTable = QueueRebuilder::rebuildHashTable($pdo);

                            $message = "Status conflict detected: Token {$candidate['token_number']} was already called or cancelled. In-memory queues were safely rebuilt from database. Please try calling again.";
                            $messageType = "warning";
                        } else {
                            // Step 5: Valid candidate! Update database records atomically
                            // Set queue_entries.status = 'Called' and doctor_id
                            $updateQ = $pdo->prepare("UPDATE queue_entries SET status = 'Called', doctor_id = :doc_id WHERE queue_id = :qid");
                            $updateQ->execute([':doc_id' => $lockedDoctor['doctor_id'], ':qid' => $candidate['queue_id']]);

                            // Set doctors.availability = 'Busy'
                            $updateDoc = $pdo->prepare("UPDATE doctors SET availability = 'Busy' WHERE doctor_id = :doc_id");
                            $updateDoc->execute([':doc_id' => $lockedDoctor['doctor_id']]);

                            // Write to queue_logs
                            $logStmt = $pdo->prepare("
                                INSERT INTO queue_logs (queue_id, action, action_time, performed_by, notes)
                                VALUES (:qid, 'Called', NOW(), :uid, :notes)
                            ");
                            $logStmt->execute([
                                ':qid' => $candidate['queue_id'],
                                ':uid' => $user['user_id'] ?? null,
                                ':notes' => sprintf("Assigned to %s (Token %s)", $lockedDoctor['name'], $candidate['token_number'])
                            ]);

                            // Commit transaction
                            $pdo->commit();

                            // Step 6: Remove from the in-memory queue & update HashTable
                            if ($isEmergencyCandidate) {
                                $heap->extractMax();
                            } else {
                                $fifo->dequeue();
                            }

                            $candidate['status'] = 'Called';
                            $candidate['doctor_id'] = $lockedDoctor['doctor_id'];
                            $candidate['doctor_name'] = $lockedDoctor['name'];
                            $hashTable->insert($candidate['token_number'], $candidate);

                            $message = sprintf(
                                "Patient <strong>%s</strong> (Token: <span class='token-badge'>%s</span>) called successfully and assigned to <strong>%s</strong>!",
                                htmlspecialchars($candidate['patient_name'], ENT_QUOTES, 'UTF-8'),
                                htmlspecialchars($candidate['token_number'], ENT_QUOTES, 'UTF-8'),
                                htmlspecialchars($lockedDoctor['name'], ENT_QUOTES, 'UTF-8')
                            );
                            $messageType = "success";
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Safe recovery on exception: rebuild in-memory structures
                $heap = QueueRebuilder::rebuildPriorityQueue($pdo, $targetDeptId);
                $fifo = QueueRebuilder::rebuildNormalQueue($pdo, $targetDeptId);
                $hashTable = QueueRebuilder::rebuildHashTable($pdo);

                $message = "Error calling patient: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                $messageType = "danger";
            }
        }
    }
}

// Fetch available doctors for current department
$availableDocsStmt = $pdo->prepare("SELECT doctor_id, name, specialization FROM doctors WHERE department_id = :dept_id AND availability = 'Available' ORDER BY name ASC");
$availableDocsStmt->execute([':dept_id' => $selectedDeptId]);
$availableDocs = $availableDocsStmt->fetchAll();

// Peek at current next candidate for UI preview
$peekEmerg = $pdo->prepare("
    SELECT q.*, p.name AS patient_name 
    FROM queue_entries q JOIN patients p ON q.patient_id = p.patient_id 
    WHERE q.department_id = :dept_id AND q.status = 'Waiting' AND q.queue_type = 'Emergency' 
    ORDER BY q.urgency_level DESC, q.arrival_time ASC LIMIT 1
");
$peekEmerg->execute([':dept_id' => $selectedDeptId]);
$nextEmergency = $peekEmerg->fetch();

$peekNorm = $pdo->prepare("
    SELECT q.*, p.name AS patient_name 
    FROM queue_entries q JOIN patients p ON q.patient_id = p.patient_id 
    WHERE q.department_id = :dept_id AND q.status = 'Waiting' AND q.queue_type = 'Normal' 
    ORDER BY q.arrival_time ASC LIMIT 1
");
$peekNorm->execute([':dept_id' => $selectedDeptId]);
$nextNormal = $peekNorm->fetch();

$nextCandidate = $nextEmergency ?: $nextNormal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Call Next Patient - Hospital Queue System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Clean Role-Based Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="../dashboard.php">
                <i class="bi bi-hospital me-2 fs-4"></i>
                <span class="fw-bold">Hospital Queue System</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
                    <?php if (hasRole(['admin', 'receptionist'])): ?>
                        <li class="nav-item"><a class="nav-link" href="add_patient.php"><i class="bi bi-person-plus me-1"></i> Register Patient</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="queue.php"><i class="bi bi-list-ol me-1"></i> Queues</a></li>
                    <?php if (hasRole(['admin', 'operator'])): ?>
                        <li class="nav-item"><a class="nav-link active" href="call_patient.php"><i class="bi bi-telephone-outbound me-1"></i> Call Next</a></li>
                        <li class="nav-item"><a class="nav-link" href="doctors.php"><i class="bi bi-person-badge me-1"></i> Doctors</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="search.php"><i class="bi bi-search me-1"></i> Search Token</a></li>
                    <li class="nav-item"><a class="nav-link" href="history.php"><i class="bi bi-clock-history me-1"></i> History</a></li>
                    <?php if (hasRole('admin')): ?>
                        <li class="nav-item"><a class="nav-link" href="reports.php"><i class="bi bi-bar-chart me-1"></i> Reports</a></li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-white text-end d-none d-md-block">
                        <div class="fw-bold small"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="badge bg-light text-dark text-uppercase" style="font-size: 0.72rem;"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <form action="../logout.php" method="POST" class="d-inline m-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-outline-light btn-sm px-3" data-confirm="Are you sure you want to log out?">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-4" style="max-width: 850px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1">Call Next Patient</h3>
                <p class="text-muted mb-0">Prioritized dispatch using Max Heap &amp; FIFO Queue logic</p>
            </div>
            <a href="queue.php?dept=<?= (int)$selectedDeptId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ol me-1"></i> View Department Queue
            </a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill me-2"></i><?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Department Switcher -->
        <div class="card shadow-sm mb-4">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <label for="deptSelect" class="form-label mb-0 fw-semibold">Select Department:</label>
                    <select id="deptSelect" class="form-select w-auto" onchange="window.location.href='call_patient.php?dept=' + this.value">
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>" <?= (int)$d['department_id'] === $selectedDeptId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['department_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <span class="badge <?= count($availableDocs) > 0 ? 'bg-success' : 'bg-danger' ?> px-3 py-2">
                        <?= count($availableDocs) ?> Doctor(s) Available
                    </span>
                </div>
            </div>
        </div>

        <!-- Next Eligible Candidate Card -->
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="bi bi-person-check-fill me-2"></i>Next Eligible Patient (Selection Policy Preview)
            </div>
            <div class="card-body p-4 text-center">
                <?php if ($nextCandidate): ?>
                    <div class="mb-3">
                        <span class="token-badge <?= $nextCandidate['queue_type'] === 'Emergency' ? 'emergency' : '' ?> fs-2 px-4 py-2">
                            <?= htmlspecialchars($nextCandidate['token_number'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($nextCandidate['patient_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                    <p class="text-muted mb-3">Arrival: <?= htmlspecialchars(date('H:i:s', strtotime($nextCandidate['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></p>

                    <div>
                        <?php if ($nextCandidate['queue_type'] === 'Emergency'): ?>
                            <span class="badge bg-danger fs-6 px-3 py-2">
                                <i class="bi bi-lightning-fill"></i> Emergency Urgency Level <?= (int)$nextCandidate['urgency_level'] ?> (Max Heap Priority)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary fs-6 px-3 py-2">
                                <i class="bi bi-arrow-right"></i> Normal FIFO Waiting Line (Oldest Arrival)
                            </span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="py-4 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                        <h5>No Waiting Patients</h5>
                        <p class="small mb-0">The waiting line for <?= htmlspecialchars($dept['department_name'] ?? 'this department', ENT_QUOTES, 'UTF-8') ?> is currently empty.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Call Action Form -->
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="bi bi-gear-fill me-2"></i>Dispatch Patient to Doctor
            </div>
            <div class="card-body p-4">
                <?php if (count($availableDocs) === 0): ?>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong>No Available Doctors!</strong> All doctors in <?= htmlspecialchars($dept['department_name'] ?? '', ENT_QUOTES, 'UTF-8') ?> are currently Busy or Off Duty.
                        Please <a href="doctors.php" class="alert-link">update doctor availability</a> before calling.
                    </div>
                <?php endif; ?>

                <form method="POST" action="call_patient.php?dept=<?= (int)$selectedDeptId ?>">
                    <!-- CSRF Protection -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="call_next">
                    <input type="hidden" name="department_id" value="<?= (int)$selectedDeptId ?>">

                    <div class="mb-3">
                        <label for="doctor_id" class="form-label fw-semibold">Assign to Doctor:</label>
                        <select class="form-select" id="doctor_id" name="doctor_id" <?= count($availableDocs) === 0 ? 'disabled' : '' ?>>
                            <?php if (count($availableDocs) > 0): ?>
                                <option value="">-- Automatically select first available doctor --</option>
                                <?php foreach ($availableDocs as $doc): ?>
                                    <option value="<?= (int)$doc['doctor_id'] ?>">
                                        <?= htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($doc['specialization'] ?? 'Specialist', ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled selected>No doctors available</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-success w-100 py-3 fw-bold fs-5 shadow-sm" <?= (count($availableDocs) === 0 || !$nextCandidate) ? 'disabled' : '' ?>>
                        <i class="bi bi-megaphone-fill me-2"></i> Call Next Eligible Patient
                    </button>
                </form>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
