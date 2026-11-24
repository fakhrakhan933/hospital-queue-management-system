<?php
/**
 * Screen 7: Reports & Analytics Module
 * Role Authorization: Admin Only
 * Displays comprehensive daily summary counts, department breakdowns,
 * served patient lists, and cancelled visits with date & department filters.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Enforce role authorization (Admin only)
requireRole('admin');

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// 1. Date and Department Filter Parameters
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));
$selectedDept = filter_var($_GET['dept_id'] ?? null, FILTER_VALIDATE_INT);

// Fetch departments for filter dropdown
$deptListStmt = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC");
$departments = $deptListStmt->fetchAll();

// 2. Department Breakdown SQL with date and optional department filter
$deptSql = "
    SELECT 
        d.department_id,
        d.department_name,
        d.department_code,
        COUNT(q.queue_id) AS total_visits,
        SUM(CASE WHEN q.queue_type = 'Normal' THEN 1 ELSE 0 END) AS normal_visits,
        SUM(CASE WHEN q.queue_type = 'Emergency' THEN 1 ELSE 0 END) AS emergency_visits,
        SUM(CASE WHEN q.status = 'Completed' THEN 1 ELSE 0 END) AS completed_visits,
        SUM(CASE WHEN q.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_visits,
        SUM(CASE WHEN q.status = 'Waiting' THEN 1 ELSE 0 END) AS waiting_visits,
        SUM(CASE WHEN q.status IN ('Called', 'In Consultation') THEN 1 ELSE 0 END) AS active_visits
    FROM departments d
    LEFT JOIN queue_entries q ON d.department_id = q.department_id 
        " . (!empty($selectedDate) ? "AND DATE(q.arrival_time) = :report_date" : "") . "
    WHERE 1=1
";
$deptParams = [];
if (!empty($selectedDate)) {
    $deptParams[':report_date'] = $selectedDate;
}
if (!empty($selectedDept)) {
    $deptSql .= " AND d.department_id = :dept_filter";
    $deptParams[':dept_filter'] = $selectedDept;
}

$deptSql .= " GROUP BY d.department_id, d.department_name, d.department_code ORDER BY total_visits DESC, d.department_name ASC";
$deptReportStmt = $pdo->prepare($deptSql);
$deptReportStmt->execute($deptParams);
$deptReports = $deptReportStmt->fetchAll();

// Calculate Grand Totals
$totalVisits = 0;
$totalNormal = 0;
$totalEmergency = 0;
$totalCompleted = 0;
$totalCancelled = 0;
$totalWaiting = 0;

foreach ($deptReports as $r) {
    $totalVisits += (int)$r['total_visits'];
    $totalNormal += (int)$r['normal_visits'];
    $totalEmergency += (int)$r['emergency_visits'];
    $totalCompleted += (int)$r['completed_visits'];
    $totalCancelled += (int)$r['cancelled_visits'];
    $totalWaiting += (int)$r['waiting_visits'];
}
$overallCompletionRate = $totalVisits > 0 ? round(($totalCompleted / $totalVisits) * 100, 1) : 0;

// 3. Query Served Patients for the selected filter
$servedSql = "
    SELECT 
        q.queue_id,
        q.token_number,
        q.queue_type,
        q.arrival_time,
        p.name AS patient_name,
        d.department_name,
        doc.name AS doctor_name,
        (
            SELECT action_time 
            FROM queue_logs 
            WHERE queue_id = q.queue_id AND action = 'Completed' 
            ORDER BY log_id DESC 
            LIMIT 1
        ) AS completed_time
    FROM queue_entries q
    JOIN patients p ON q.patient_id = p.patient_id
    JOIN departments d ON q.department_id = d.department_id
    LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
    WHERE q.status = 'Completed'
";
$servedParams = [];
if (!empty($selectedDate)) {
    $servedSql .= " AND DATE(q.arrival_time) = :sdate";
    $servedParams[':sdate'] = $selectedDate;
}
if (!empty($selectedDept)) {
    $servedSql .= " AND q.department_id = :sdept";
    $servedParams[':sdept'] = $selectedDept;
}
$servedSql .= " ORDER BY q.queue_id DESC LIMIT 20";
$servedStmt = $pdo->prepare($servedSql);
$servedStmt->execute($servedParams);
$servedPatients = $servedStmt->fetchAll();

// 4. Query Cancelled Entries for the selected filter
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
$cancelledSql .= " ORDER BY q.queue_id DESC LIMIT 20";
$cancelledStmt = $pdo->prepare($cancelledSql);
$cancelledStmt->execute($cancelledParams);
$cancelledVisits = $cancelledStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports &amp; Analytics - Hospital Queue System</title>
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
                    <li class="nav-item"><a class="nav-link" href="history.php"><i class="bi bi-clock-history me-1"></i> History</a></li>
                    <?php if (hasRole('admin')): ?>
                        <li class="nav-item"><a class="nav-link active" href="reports.php"><i class="bi bi-bar-chart me-1"></i> Reports</a></li>
                    <?php endif; ?>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <div class="text-white text-end d-none d-md-block">
                        <div class="fw-bold small"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="badge bg-danger text-white text-uppercase" style="font-size: 0.72rem;"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></span>
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
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold mb-1"><i class="bi bi-bar-chart-line text-primary me-2"></i>Daily Queue Performance Reports</h3>
                <p class="text-muted mb-0">Departmental patient throughput, emergency breakdowns, and completion analytics</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print / Export Report
                </button>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="reports.php" class="row g-2 align-items-center">
                    <div class="col-md-4">
                        <label for="date" class="form-label small fw-bold mb-1">Select Report Date:</label>
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
                            <i class="bi bi-filter me-1"></i> Update Report
                        </button>
                        <a href="reports.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Today
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Metric Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card stat-primary p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Total Registered</div>
                    <div class="stat-number text-primary"><?= (int)$totalVisits ?></div>
                    <div class="small text-muted mt-1"><?= (int)$totalNormal ?> Normal &bull; <?= (int)$totalEmergency ?> Emergency</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-success p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Completed Consultations</div>
                    <div class="stat-number text-success"><?= (int)$totalCompleted ?></div>
                    <div class="small text-muted mt-1">Completion Rate: <?= $overallCompletionRate ?>%</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-warning p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Cancelled Visits</div>
                    <div class="stat-number text-warning"><?= (int)$totalCancelled ?></div>
                    <div class="small text-muted mt-1">Reversible via Stack Undo</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card stat-info p-3">
                    <div class="text-muted small text-uppercase fw-semibold">Remaining Waiting</div>
                    <div class="stat-number text-info"><?= (int)$totalWaiting ?></div>
                    <div class="small text-muted mt-1">Active in queue pipeline</div>
                </div>
            </div>
        </div>

        <!-- Department Breakdown Table -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-table text-primary me-2"></i>Department Summary Breakdown (<?= htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8') ?>)</span>
                <span class="badge bg-light text-dark border"><?= count($deptReports) ?> Departments Listed</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th class="text-center">Total Visits</th>
                                <th class="text-center">Normal (FIFO)</th>
                                <th class="text-center">Emergency (Max Heap)</th>
                                <th class="text-center">Completed</th>
                                <th class="text-center">Cancelled</th>
                                <th class="text-center">Waiting</th>
                                <th class="text-center" style="width: 180px;">Completion Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deptReports)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No department records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($deptReports as $r): ?>
                                    <?php 
                                        $rate = $r['total_visits'] > 0 ? round(($r['completed_visits'] / $r['total_visits']) * 100, 1) : 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($r['department_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="badge bg-light text-secondary border ms-1"><?= htmlspecialchars($r['department_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td class="text-center fw-bold"><?= (int)$r['total_visits'] ?></td>
                                        <td class="text-center"><?= (int)$r['normal_visits'] ?></td>
                                        <td class="text-center text-danger fw-bold"><?= (int)$r['emergency_visits'] ?></td>
                                        <td class="text-center text-success fw-bold"><?= (int)$r['completed_visits'] ?></td>
                                        <td class="text-center text-muted"><?= (int)$r['cancelled_visits'] ?></td>
                                        <td class="text-center text-info fw-bold"><?= (int)$r['waiting_visits'] ?></td>
                                        <td class="text-center">
                                            <div class="progress" style="height: 18px;">
                                                <div class="progress-bar <?= $rate >= 50 ? 'bg-success' : 'bg-warning text-dark' ?>" style="width: <?= (int)$rate ?>%;">
                                                    <?= $rate ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Side-by-side Served & Cancelled detail lists -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-check2-circle text-success me-2"></i>Recently Completed Consultations</span>
                        <span class="badge bg-success"><?= count($servedPatients) ?> Listed</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Department</th>
                                        <th>Doctor</th>
                                        <th>Completed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($servedPatients)): ?>
                                        <tr><td colspan="5" class="text-center py-3 text-muted">No completed consultations for this filter.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($servedPatients as $sp): ?>
                                            <tr>
                                                <td><a href="search.php?token=<?= urlencode($sp['token_number']) ?>" class="token-badge small py-0 text-decoration-none"><?= htmlspecialchars($sp['token_number'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                                <td class="fw-bold"><?= htmlspecialchars($sp['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($sp['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($sp['doctor_name'] ?? 'Doctor', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-success"><?= htmlspecialchars(date('H:i:s', strtotime($sp['completed_time'] ?? $sp['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold"><i class="bi bi-x-octagon text-danger me-2"></i>Recently Cancelled Visits</span>
                        <span class="badge bg-secondary"><?= count($cancelledVisits) ?> Listed</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead>
                                    <tr>
                                        <th>Token</th>
                                        <th>Patient</th>
                                        <th>Department</th>
                                        <th>Cancelled At</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cancelledVisits)): ?>
                                        <tr><td colspan="5" class="text-center py-3 text-muted">No cancelled visits for this filter.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($cancelledVisits as $cv): ?>
                                            <tr>
                                                <td><a href="search.php?token=<?= urlencode($cv['token_number']) ?>" class="token-badge small py-0 text-decoration-none"><?= htmlspecialchars($cv['token_number'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                                <td class="fw-bold"><?= htmlspecialchars($cv['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($cv['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-muted"><?= htmlspecialchars(date('H:i:s', strtotime($cv['cancelled_time'] ?? $cv['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="text-muted"><?= htmlspecialchars($cv['cancel_notes'] ?? 'Cancelled by staff', ENT_QUOTES, 'UTF-8') ?></td>
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
            <span class="text-muted">Hospital Queue Management System &bull; Admin Analytics &bull; Academic DSA Architecture</span>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
