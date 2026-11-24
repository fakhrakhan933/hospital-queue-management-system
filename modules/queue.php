<?php
/**
 * Screen 4: Queue Monitor & Operations
 * Demonstrates Linked-list Queue (FIFO) and Max Heap (Priority Queue) in Action
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/LinkedListQueue.php';
require_once __DIR__ . '/../classes/PriorityQueue.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

requireLogin('../index.php');

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// Fetch all departments for selector
$deptStmt = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC");
$departments = $deptStmt->fetchAll();

// Current selected department (default to first)
$selectedDeptId = filter_var($_GET['dept'] ?? ($departments[0]['department_id'] ?? 1), FILTER_VALIDATE_INT);

// Department details
$deptInfoStmt = $pdo->prepare("SELECT department_id, department_name, department_code FROM departments WHERE department_id = :id");
$deptInfoStmt->execute([':id' => $selectedDeptId]);
$currentDept = $deptInfoStmt->fetch();

// Rebuild In-Memory Queues from Database via QueueRebuilder (Persistence & Recovery Layer)
$normalQueue = QueueRebuilder::rebuildNormalQueue($pdo, $selectedDeptId);
$priorityQueue = QueueRebuilder::rebuildPriorityQueue($pdo, $selectedDeptId);

// 3. Available Doctors for this Department
$docStmt = $pdo->prepare("
    SELECT doctor_id, name, specialization, availability
    FROM doctors
    WHERE department_id = :dept_id
    ORDER BY availability ASC, name ASC
");
$docStmt->execute([':dept_id' => $selectedDeptId]);
$doctors = $docStmt->fetchAll();

$availableDoctorCount = 0;
foreach ($doctors as $doc) {
    if ($doc['availability'] === 'Available') $availableDoctorCount++;
}

// 4. Currently Called / In Consultation for this Department
$activeConsultStmt = $pdo->prepare("
    SELECT q.*, p.name AS patient_name, doc.name AS doctor_name
    FROM queue_entries q
    JOIN patients p ON q.patient_id = p.patient_id
    LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
    WHERE q.department_id = :dept_id AND q.status IN ('Called', 'In Consultation')
    ORDER BY q.arrival_time DESC
");
$activeConsultStmt->execute([':dept_id' => $selectedDeptId]);
$activeConsultations = $activeConsultStmt->fetchAll();

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Monitor - <?= htmlspecialchars($currentDept['department_name'] ?? 'Department', ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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
                    <li class="nav-item"><a class="nav-link active" href="queue.php"><i class="bi bi-list-ol me-1"></i> Queues</a></li>
                    <?php if (hasRole(['admin', 'operator'])): ?>
                        <li class="nav-item"><a class="nav-link" href="call_patient.php"><i class="bi bi-telephone-outbound me-1"></i> Call Next</a></li>
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

    <main class="container-fluid px-4 py-4">
        <!-- Messages -->
        <?php if (!empty($msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Department Header & Switcher -->
        <div class="card shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-primary text-white p-3 rounded-3 fs-3">
                        <i class="bi bi-building"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0"><?= htmlspecialchars($currentDept['department_name'] ?? 'Department', ENT_QUOTES, 'UTF-8') ?></h4>
                        <span class="badge bg-secondary">Code: <?= htmlspecialchars($currentDept['department_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge bg-success ms-1"><?= (int)$availableDoctorCount ?> Doctor(s) Available</span>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2">
                    <label for="deptSelect" class="form-label mb-0 fw-semibold">Switch Department:</label>
                    <select id="deptSelect" class="form-select w-auto" onchange="window.location.href='queue.php?dept=' + this.value">
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= (int)$d['department_id'] ?>" <?= (int)$d['department_id'] === $selectedDeptId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['department_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (hasRole(['admin', 'operator'])): ?>
                        <a href="call_patient.php?dept=<?= (int)$selectedDeptId ?>" class="btn btn-success shadow-sm">
                            <i class="bi bi-megaphone me-1"></i> Call Next Patient
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- In Consultation Row -->
        <?php if (!empty($activeConsultations)): ?>
            <div class="card shadow-sm border-info mb-4">
                <div class="card-header bg-info bg-opacity-10 text-dark fw-bold">
                    <i class="bi bi-person-video2 me-2"></i>Currently Called / In Consultation
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Token</th>
                                    <th>Patient</th>
                                    <th>Type</th>
                                    <th>Doctor Assigned</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activeConsultations as $ac): ?>
                                    <tr>
                                        <td><span class="token-badge"><?= htmlspecialchars($ac['token_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="fw-bold"><?= htmlspecialchars($ac['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?= $ac['queue_type'] === 'Emergency' ? '<span class="badge bg-danger">Emergency</span>' : '<span class="badge bg-secondary">Normal</span>' ?>
                                        </td>
                                        <td><i class="bi bi-person-fill-check me-1 text-success"></i><?= htmlspecialchars($ac['doctor_name'] ?? 'Assigned Doctor', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="badge bg-primary"><?= htmlspecialchars($ac['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-end">
                                            <?php if (hasRole(['admin', 'operator'])): ?>
                                                <a href="../actions/complete.php?queue_id=<?= (int)$ac['queue_id'] ?>&dept=<?= (int)$selectedDeptId ?>&csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-sm btn-success" data-confirm="Mark consultation completed?">
                                                    <i class="bi bi-check-lg"></i> Mark Completed
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Queues Split: Priority Heap vs Normal FIFO -->
        <div class="row g-4">
            <!-- 1. Emergency Max Heap Priority Queue -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-danger h-100">
                    <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i>Emergency Priority Queue (Max Heap)</span>
                        <span class="badge bg-white text-danger fw-bold"><?= (int)$priorityQueue->getSize() ?> Waiting</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-2 bg-light small text-muted border-bottom">
                            <i class="bi bi-info-circle me-1"></i>Sorted by Urgency (5 high down to 1), ties broken by arrival time.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Urgency</th>
                                        <th>Arrival</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                        $emergencyItems = $priorityQueue->toArray();
                                        if (empty($emergencyItems)): 
                                    ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                No waiting emergency cases.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($emergencyItems as $item): ?>
                                            <tr>
                                                <td><span class="token-badge emergency"><?= htmlspecialchars($item['token_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($item['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="badge bg-danger rounded-pill px-3 py-1">
                                                        Level <?= (int)$item['urgency_level'] ?>
                                                    </span>
                                                </td>
                                                <td class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime($item['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end">
                                                    <?php if (hasRole(['admin', 'receptionist', 'operator'])): ?>
                                                        <a href="../actions/cancel.php?queue_id=<?= (int)$item['queue_id'] ?>&dept=<?= (int)$selectedDeptId ?>&csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-sm btn-outline-danger" data-confirm="Cancel this waiting entry?">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Normal FIFO Queue -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-primary h-100">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-people-fill me-2"></i>Normal Waiting Queue (FIFO Linked List)</span>
                        <span class="badge bg-white text-primary fw-bold"><?= (int)$normalQueue->getSize() ?> Waiting</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="p-2 bg-light small text-muted border-bottom">
                            <i class="bi bi-info-circle me-1"></i>Served strictly in arrival order (First-In, First-Out).
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Pos</th>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Arrival</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                        $normalItems = $normalQueue->toArray();
                                        if (empty($normalItems)): 
                                    ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">
                                                No normal patients waiting.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $pos = 1; foreach ($normalItems as $item): ?>
                                            <tr>
                                                <td class="fw-bold text-muted"><?= $pos++ ?></td>
                                                <td><span class="token-badge"><?= htmlspecialchars($item['token_number'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($item['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime($item['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-end">
                                                    <?php if (hasRole(['admin', 'receptionist', 'operator'])): ?>
                                                        <a href="../actions/cancel.php?queue_id=<?= (int)$item['queue_id'] ?>&dept=<?= (int)$selectedDeptId ?>&csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-sm btn-outline-danger" data-confirm="Cancel this waiting entry?">
                                                            <i class="bi bi-x-circle"></i> Cancel
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Department Doctors Roster -->
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Department Doctors Roster</span>
                <?php if (hasRole(['admin', 'operator'])): ?>
                    <a href="doctors.php" class="btn btn-sm btn-outline-primary">Manage Availability</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Doctor Name</th>
                                <th>Specialization</th>
                                <th>Availability Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($doctors)): ?>
                                <tr><td colspan="3" class="text-center py-3 text-muted">No doctors registered in this department.</td></tr>
                            <?php else: ?>
                                <?php foreach ($doctors as $doc): ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($doc['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>
                                            <?php 
                                                $availClass = match($doc['availability']) {
                                                    'Available' => 'badge-avail-available',
                                                    'Busy' => 'badge-avail-busy',
                                                    'Off Duty' => 'badge-avail-offduty',
                                                    default => 'bg-secondary'
                                                };
                                            ?>
                                            <span class="badge <?= $availClass ?> px-3 py-1"><?= htmlspecialchars($doc['availability'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
