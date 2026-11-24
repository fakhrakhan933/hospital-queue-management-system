<?php
/**
 * Screen 6: History & Audit Log Module
 * Demonstrates ServedList (Linked List with Tail Pointer) & Stack for Undo
 * Supports date/department filters, cancelled entries, and daily summary counts
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/ServedList.php';
require_once __DIR__ . '/../classes/Stack.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

requireLogin('../index.php');

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// 1. Filter parameters
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
$selectedDept = filter_var($_GET['dept_id'] ?? null, FILTER_VALIDATE_INT);

// Fetch all active departments for the filter dropdown
$deptStmt = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC");
$departments = $deptStmt->fetchAll();

// 2. Daily Summary Counts with date & department filters
$summarySql = "
    SELECT 
        COUNT(q.queue_id) AS total_registered,
        SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) AS total_completed,
        SUM(CASE WHEN q.status = 'Cancelled' THEN 1 ELSE 0 END) AS total_cancelled,
        SUM(CASE WHEN q.status = 'Waiting' THEN 1 ELSE 0 END) AS total_waiting
    FROM queue_entries q
    WHERE 1=1
";
$summaryParams = [];

if (!empty($selectedDate)) {
    $summarySql .= " AND DATE(q.arrival_time) = :sdate";
    $summaryParams[':sdate'] = $selectedDate;
}
if (!empty($selectedDept)) {
    $summarySql .= " AND q.department_id = :sdept";
    $summaryParams[':sdept'] = $selectedDept;
}

$sumStmt = $pdo->prepare($summarySql);
$sumStmt->execute($summaryParams);
$summaryCounts = $sumStmt->fetch() ?: [
    'total_registered' => 0,
    'total_completed'  => 0,
    'total_cancelled'  => 0,
    'total_waiting'    => 0
];

// 3. Rebuild ServedList (Custom Linked List with Tail Pointer) from MySQL
// Demonstrates custom Linked List data structure
$servedList = QueueRebuilder::rebuildServedList($pdo, 100);

// Filter served entries if department filter is active
$allServedEntries = $servedList->toArray();
if (!empty($selectedDept)) {
    $filteredServed = array_filter($allServedEntries, function ($item) use ($pdo, $selectedDept) {
        // Look up department_id
        return isset($item['department_name']);
    });
}

// 4. Query Cancelled Entries with filters
$cancelledSql = "
    SELECT 
        q.queue_id,
        q.token_number,
        q.queue_type,
        q.arrival_time,
        p.name AS patient_name,
        d.department_name,
        (
            SELECT action_time 
            FROM queue_logs 
            WHERE queue_id = q.queue_id AND action = 'Cancelled' 
            ORDER BY log_id DESC 
            LIMIT 1
        ) AS cancelled_time,
        (
            SELECT notes 
            FROM queue_logs 
            WHERE queue_id = q.queue_id AND action = 'Cancelled' 
            ORDER BY log_id DESC 
            LIMIT 1
        ) AS cancel_notes
    FROM queue_entries q
    JOIN patients p ON q.patient_id = p.patient_id
    JOIN departments d ON q.department_id = d.department_id
    WHERE q.status = 'Cancelled'
";
$cancelledParams = [];
if (!empty($selectedDate)) {
    $cancelledSql .= " AND DATE(q.arrival_time) = :cdate";
    $cancelledParams[':cdate'] = $selectedDate;
}
if (!empty($selectedDept)) {
    $cancelledSql .= " AND q.department_id = :cdept";
    $cancelledParams[':cdept'] = $selectedDept;
}
$cancelledSql .= " ORDER BY q.queue_id DESC LIMIT 50";
$cancelledStmt = $pdo->prepare($cancelledSql);
$cancelledStmt->execute($cancelledParams);
$cancelledEntries = $cancelledStmt->fetchAll();

// 5. Query Audit Logs with filters
$logsSql = "
    SELECT l.*, q.token_number, u.full_name AS staff_name
    FROM queue_logs l
    JOIN queue_entries q ON l.queue_id = q.queue_id
    LEFT JOIN users u ON l.performed_by = u.user_id
    WHERE 1=1
";
$logsParams = [];
if (!empty($selectedDate)) {
    $logsSql .= " AND DATE(l.action_time) = :ldate";
    $logsParams[':ldate'] = $selectedDate;
}
if (!empty($selectedDept)) {
    $logsSql .= " AND q.department_id = :ldept";
    $logsParams[':ldept'] = $selectedDept;
}
$logsSql .= " ORDER BY l.log_id DESC LIMIT 40";
$logsStmt = $pdo->prepare($logsSql);
$logsStmt->execute($logsParams);
$auditLogs = $logsStmt->fetchAll();

// 6. Inspect Undo Stack
$sessionStack = $_SESSION['undo_stack'] ?? [];
$undoCount = count($sessionStack);
$topUndoAction = $undoCount > 0 ? end($sessionStack) : null;

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue History &amp; Audit Logs - Hospital Queue System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
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
                        <li class="nav-item"><a class="nav-link" href="call_patient.php"><i class="bi bi-telephone-outbound me-1"></i> Call Next</a></li>
                        <li class="nav-item"><a class="nav-link" href="doctors.php"><i class="bi bi-person-badge me-1"></i> Doctors</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="search.php"><i class="bi bi-search me-1"></i> Search Token</a></li>
                    <li class="nav-item"><a class="nav-link active" href="history.php"><i class="bi bi-clock-history me-1"></i> History</a></li>
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
        <!-- Header & Action Bar -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Queue History &amp; Audit Logs</h3>
                <p class="text-muted mb-0">Served patient linked list, cancellations, and system audit trail</p>
            </div>
            
            <?php if (hasRole(['admin', 'receptionist', 'operator'])): ?>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($undoCount > 0): ?>
                        <a href="../actions/undo.php?csrf_token=<?= urlencode($csrfToken) ?>" class="btn btn-warning shadow-sm fw-bold" data-confirm="Undo most recent action (<?= htmlspecialchars($topUndoAction['action_type'] === 'cancel' ? 'Cancellation' : 'Registration', ENT_QUOTES, 'UTF-8') ?> for Token <?= htmlspecialchars($topUndoAction['token_number'], ENT_QUOTES, 'UTF-8') ?>)?">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Undo Last Action (<?= htmlspecialchars($topUndoAction['token_number'], ENT_QUOTES, 'UTF-8') ?>)
                            <span class="badge bg-dark ms-1"><?= (int)$undoCount ?> on Stack</span>
                        </a>
                    <?php else: ?>
                        <button class="btn btn-outline-secondary btn-sm" disabled title="No recent reversible actions in the undo stack">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Undo Stack Empty
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Alerts -->
        <?php if (!empty($msg)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="history.php" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <label for="date" class="form-label small fw-bold mb-1">Filter by Date:</label>
                        <input type="date" class="form-control form-control-sm" id="date" name="date" value="<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-md-5">
                        <label for="dept_id" class="form-label small fw-bold mb-1">Filter by Department:</label>
                        <select class="form-select form-select-sm" id="dept_id" name="dept_id">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>" <?= $selectedDept === (int)$d['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['department_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($d['department_code'], ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2 pt-3">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                            <i class="bi bi-funnel me-1"></i> Apply Filter
                        </button>
                        <a href="history.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-circle me-1"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Daily Summary Metric Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card stat-primary p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Total Registered</div>
                    <div class="stat-number text-primary"><?= (int)$summaryCounts['total_registered'] ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-success p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Served &amp; Completed</div>
                    <div class="stat-number text-success"><?= (int)$summaryCounts['total_completed'] ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-warning p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Cancelled Visits</div>
                    <div class="stat-number text-warning"><?= (int)$summaryCounts['total_cancelled'] ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-info p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Remaining Waiting</div>
                    <div class="stat-number text-info"><?= (int)$summaryCounts['total_waiting'] ?></div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-3" id="historyTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-bold" id="served-tab" data-bs-toggle="pill" data-bs-target="#served-pane" type="button" role="tab">
                    <i class="bi bi-check2-all me-1"></i> Served Patients (ServedList)
                    <span class="badge bg-success ms-1"><?= (int)$servedList->getSize() ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="cancelled-tab" data-bs-toggle="pill" data-bs-target="#cancelled-pane" type="button" role="tab">
                    <i class="bi bi-x-octagon me-1"></i> Cancelled Entries
                    <span class="badge bg-secondary ms-1"><?= count($cancelledEntries) ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-bold" id="logs-tab" data-bs-toggle="pill" data-bs-target="#logs-pane" type="button" role="tab">
                    <i class="bi bi-journal-text me-1"></i> System Audit Trail (queue_logs)
                    <span class="badge bg-primary ms-1"><?= count($auditLogs) ?></span>
                </button>
            </li>
        </ul>

        <!-- Tab Contents -->
        <div class="tab-content" id="historyTabContent">
            <!-- 1. Served Patients Linked List Tab -->
            <div class="tab-pane fade show active" id="served-pane" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-check-circle-fill text-success me-2"></i>Served Patients (Custom Linked List Data Structure)</span>
                        <input type="text" id="tableSearchInput" data-target-table="#servedTable" class="form-control form-control-sm" placeholder="Filter served patients..." style="max-width: 260px;">
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-custom align-middle mb-0" id="servedTable">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient Name</th>
                                        <th>Department</th>
                                        <th>Queue Type</th>
                                        <th>Attending Doctor</th>
                                        <th>Arrival Time</th>
                                        <th>Completed Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                        $servedEntries = $servedList->toArray();
                                        if (empty($servedEntries)): 
                                    ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No completed consultations recorded in ServedList.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($servedEntries as $entry): ?>
                                            <tr>
                                                <td>
                                                    <a href="search.php?token=<?= urlencode($entry['token_number']) ?>" class="token-badge text-decoration-none <?= ($entry['queue_type'] ?? '') === 'Emergency' ? 'emergency' : '' ?>">
                                                        <?= htmlspecialchars($entry['token_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </td>
                                                <td class="fw-bold text-dark"><?= htmlspecialchars($entry['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($entry['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <?php if (($entry['queue_type'] ?? '') === 'Emergency'): ?>
                                                        <span class="badge bg-danger">Emergency</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Normal</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-semibold text-primary"><?= htmlspecialchars($entry['doctor_name'] ?? 'Doctor', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars(date('H:i:s', strtotime($entry['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="small text-success fw-bold">
                                                    <?= htmlspecialchars(date('H:i:s', strtotime($entry['completed_time'] ?? $entry['arrival_time'])), ENT_QUOTES, 'UTF-8') ?>
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

            <!-- 2. Cancelled Entries Tab -->
            <div class="tab-pane fade" id="cancelled-pane" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-x-octagon-fill text-danger me-2"></i>Cancelled Queue Entries</span>
                        <span class="badge bg-secondary"><?= count($cancelledEntries) ?> entries</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-custom align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient Name</th>
                                        <th>Department</th>
                                        <th>Queue Type</th>
                                        <th>Arrival Time</th>
                                        <th>Cancellation Reason / Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cancelledEntries)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">No cancelled entries found for the selected filter.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($cancelledEntries as $ce): ?>
                                            <tr>
                                                <td>
                                                    <a href="search.php?token=<?= urlencode($ce['token_number']) ?>" class="token-badge text-decoration-none">
                                                        <?= htmlspecialchars($ce['token_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </td>
                                                <td class="fw-bold"><?= htmlspecialchars($ce['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($ce['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($ce['queue_type'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </td>
                                                <td class="small text-muted"><?= htmlspecialchars(date('H:i:s', strtotime($ce['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($ce['cancel_notes'] ?? 'Cancelled by staff', ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. Full Audit Log Tab -->
            <div class="tab-pane fade" id="logs-pane" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-journal-check text-primary me-2"></i>System Action Audit Trail (queue_logs)</span>
                        <span class="badge bg-primary"><?= count($auditLogs) ?> Log Entries</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead>
                                    <tr>
                                        <th>Log ID</th>
                                        <th>Token</th>
                                        <th>Action</th>
                                        <th>Action Time</th>
                                        <th>Performed By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($auditLogs)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">No audit logs found for the selected criteria.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($auditLogs as $log): ?>
                                            <tr>
                                                <td class="text-muted">#<?= (int)$log['log_id'] ?></td>
                                                <td>
                                                    <a href="search.php?token=<?= urlencode($log['token_number']) ?>" class="token-badge small py-0 text-decoration-none">
                                                        <?= htmlspecialchars($log['token_number'], ENT_QUOTES, 'UTF-8') ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $actionBadge = match($log['action']) {
                                                            'Registered' => 'bg-info text-dark',
                                                            'Called' => 'bg-primary text-white',
                                                            'In Consultation' => 'bg-warning text-dark',
                                                            'Completed' => 'bg-success text-white',
                                                            'Cancelled' => 'bg-secondary text-white',
                                                            'Undo' => 'bg-dark text-white',
                                                            default => 'bg-light text-dark border'
                                                        };
                                                    ?>
                                                    <span class="badge <?= $actionBadge ?>"><?= htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </td>
                                                <td class="text-muted"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['action_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($log['staff_name'] ?? 'System', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-muted"><?= htmlspecialchars($log['notes'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
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
    </main>

    <footer class="mt-auto">
        <div class="container text-center">
            <span class="text-muted">Hospital Queue Management System &bull; DSA-Based Academic Project</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
