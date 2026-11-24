<?php
/**
 * Main Dashboard - Hospital Queue Management System
 * Screen 2: System Overview & Department Queues with Role-Based Navigation
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// Enforce login on protected dashboard route
requireLogin('index.php');

$user = currentUser();
$userRole = $user['role'] ?? 'receptionist';
$userName = $user['full_name'] ?? $user['username'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// 1. Fetch Today's Summary Metrics (Filtered by CURDATE)
$metricsStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total_registered,
        SUM(CASE WHEN status = 'Waiting' THEN 1 ELSE 0 END) AS total_waiting,
        SUM(CASE WHEN status IN ('Called', 'In Consultation') THEN 1 ELSE 0 END) AS total_called,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS total_completed,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS total_cancelled
    FROM queue_entries
    WHERE DATE(arrival_time) = CURDATE()
");
$metrics = $metricsStmt->fetch() ?: [
    'total_registered' => 0,
    'total_waiting'    => 0,
    'total_called'     => 0,
    'total_completed'  => 0,
    'total_cancelled'  => 0
];

// 2. Fetch Department-wise Queue Summary (Normal Waiting, Priority Waiting, Available Doctors)
$deptStmt = $pdo->query("
    SELECT 
        d.department_id,
        d.department_name,
        d.department_code,
        SUM(CASE WHEN q.status = 'Waiting' AND q.queue_type = 'Normal' THEN 1 ELSE 0 END) AS normal_waiting,
        SUM(CASE WHEN q.status = 'Waiting' AND q.queue_type = 'Emergency' THEN 1 ELSE 0 END) AS priority_waiting,
        (
            SELECT COUNT(*) 
            FROM doctors doc 
            WHERE doc.department_id = d.department_id 
              AND doc.availability = 'Available'
        ) AS available_doctors
    FROM departments d
    LEFT JOIN queue_entries q ON d.department_id = q.department_id AND DATE(q.arrival_time) = CURDATE()
    GROUP BY d.department_id, d.department_name, d.department_code
    ORDER BY d.department_id ASC
");
$departments = $deptStmt->fetchAll();

// 3. Fetch Recent Queue Activity
$recentStmt = $pdo->query("
    SELECT 
        q.queue_id,
        q.token_number,
        q.queue_type,
        q.urgency_level,
        q.status,
        q.arrival_time,
        p.name AS patient_name,
        d.department_name,
        doc.name AS doctor_name
    FROM queue_entries q
    JOIN patients p ON q.patient_id = p.patient_id
    JOIN departments d ON q.department_id = d.department_id
    LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
    WHERE DATE(q.arrival_time) = CURDATE()
    ORDER BY q.queue_id DESC
    LIMIT 8
");
$recentEntries = $recentStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Main Dashboard - Hospital Queue Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Clean Role-Based Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <i class="bi bi-hospital me-2 fs-4"></i>
                <span class="fw-bold">Hospital Queue System</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <!-- Dashboard: Accessible to All Roles -->
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>

                    <!-- Register Patient: Admin & Receptionist -->
                    <?php if (hasRole(['admin', 'receptionist'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="modules/add_patient.php">
                                <i class="bi bi-person-plus me-1"></i> Register Patient
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Queues: Accessible to All Roles -->
                    <li class="nav-item">
                        <a class="nav-link" href="modules/queue.php">
                            <i class="bi bi-list-ol me-1"></i> Queues
                        </a>
                    </li>

                    <!-- Call Next: Admin & Queue Operator -->
                    <?php if (hasRole(['admin', 'operator'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="modules/call_patient.php">
                                <i class="bi bi-telephone-outbound me-1"></i> Call Next
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Doctor Management: Admin & Queue Operator -->
                    <?php if (hasRole(['admin', 'operator'])): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="modules/doctors.php">
                                <i class="bi bi-person-badge me-1"></i> Doctors
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Search Token: Accessible to All Roles -->
                    <li class="nav-item">
                        <a class="nav-link" href="modules/search.php">
                            <i class="bi bi-search me-1"></i> Search Token
                        </a>
                    </li>

                    <!-- History & Logs: Accessible to All Roles -->
                    <li class="nav-item">
                        <a class="nav-link" href="modules/history.php">
                            <i class="bi bi-clock-history me-1"></i> History
                        </a>
                    </li>

                    <!-- Reports: Admin Exclusive -->
                    <?php if (hasRole('admin')): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="modules/reports.php">
                                <i class="bi bi-bar-chart me-1"></i> Reports
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <!-- User Profile & Logout -->
                <div class="d-flex align-items-center gap-3">
                    <div class="text-white text-end d-none d-md-block">
                        <div class="fw-bold small"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php
                            $roleBadgeClass = match($userRole) {
                                'admin' => 'bg-danger text-white',
                                'operator' => 'bg-info text-dark',
                                'receptionist' => 'bg-success text-white',
                                default => 'bg-light text-dark'
                            };
                            $roleIcon = match($userRole) {
                                'admin' => 'bi-shield-check',
                                'operator' => 'bi-headset',
                                'receptionist' => 'bi-person-badge',
                                default => 'bi-person'
                            };
                        ?>
                        <span class="badge <?= $roleBadgeClass ?> text-uppercase" style="font-size: 0.72rem;">
                            <i class="bi <?= $roleIcon ?> me-1"></i><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>

                    <!-- Secure Logout Form -->
                    <form action="logout.php" method="POST" class="d-inline m-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-outline-light btn-sm px-3" data-confirm="Are you sure you want to log out?">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container-fluid px-4 py-4">
        <!-- Breadcrumb & Welcome Bar -->
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h3 class="fw-bold mb-1">Queue Operations Dashboard</h3>
                <p class="text-muted mb-0">
                    Logged in as <strong><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></strong> (Role: <span class="badge <?= $roleBadgeClass ?>"><?= htmlspecialchars(ucfirst($userRole), ENT_QUOTES, 'UTF-8') ?></span>)
                </p>
            </div>
            <div class="d-flex gap-2">
                <?php if (hasRole(['admin', 'receptionist'])): ?>
                    <a href="modules/add_patient.php" class="btn btn-primary shadow-sm">
                        <i class="bi bi-plus-circle me-1"></i> New Patient Token
                    </a>
                <?php endif; ?>
                <?php if (hasRole(['admin', 'operator'])): ?>
                    <a href="modules/call_patient.php" class="btn btn-success shadow-sm">
                        <i class="bi bi-megaphone me-1"></i> Call Next Patient
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Stat Cards: Today Registered, Waiting, Called, Completed -->
        <div class="row g-3 mb-4">
            <!-- 1. Today Registered -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card stat-primary h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Today Registered</div>
                            <div class="stat-number text-primary"><?= (int)$metrics['total_registered'] ?></div>
                            <small class="text-muted">Total arrivals today</small>
                        </div>
                        <div class="stat-icon text-primary"><i class="bi bi-people"></i></div>
                    </div>
                </div>
            </div>

            <!-- 2. Waiting in Queue -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card stat-warning h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Waiting in Queue</div>
                            <div class="stat-number text-warning"><?= (int)$metrics['total_waiting'] ?></div>
                            <small class="text-muted">Awaiting consultation</small>
                        </div>
                        <div class="stat-icon text-warning"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                </div>
            </div>

            <!-- 3. Called / In Consult -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card stat-info h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Called / In Consult</div>
                            <div class="stat-number text-info"><?= (int)$metrics['total_called'] ?></div>
                            <small class="text-muted">Currently with doctors</small>
                        </div>
                        <div class="stat-icon text-info"><i class="bi bi-person-check"></i></div>
                    </div>
                </div>
            </div>

            <!-- 4. Consulted Today (Completed) -->
            <div class="col-sm-6 col-xl-3">
                <div class="card stat-card stat-success h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase">Completed Today</div>
                            <div class="stat-number text-success"><?= (int)$metrics['total_completed'] ?></div>
                            <small class="text-muted">Successfully treated</small>
                        </div>
                        <div class="stat-icon text-success"><i class="bi bi-check2-circle"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Department Queue Snapshot Table -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <span class="fs-6 fw-bold"><i class="bi bi-building me-2"></i>Department Queue Status</span>
                        <a href="modules/queue.php" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i> Full Monitor
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-custom align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="text-center">Normal Waiting (FIFO)</th>
                                    <th class="text-center">Priority Waiting (Max Heap)</th>
                                    <th class="text-center">Available Doctors</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $dept): ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><?= htmlspecialchars($dept['department_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="badge bg-light text-secondary ms-1"><?= htmlspecialchars($dept['department_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary rounded-pill px-3 py-2 fs-6">
                                                <?= (int)$dept['normal_waiting'] ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$dept['priority_waiting'] > 0): ?>
                                                <span class="badge bg-danger rounded-pill px-3 py-2 fs-6">
                                                    <i class="bi bi-lightning-fill me-1"></i><?= (int)$dept['priority_waiting'] ?> Emergency
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border rounded-pill px-3 py-2">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int)$dept['available_doctors'] > 0): ?>
                                                <span class="badge bg-success rounded-pill px-3 py-2">
                                                    <?= (int)$dept['available_doctors'] ?> Available
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill px-3 py-2">None Available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="modules/queue.php?dept=<?= (int)$dept['department_id'] ?>" class="btn btn-outline-secondary" title="View Queue">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <?php if (hasRole(['admin', 'operator'])): ?>
                                                    <a href="modules/call_patient.php?dept=<?= (int)$dept['department_id'] ?>" class="btn btn-outline-success" title="Call Next">
                                                        <i class="bi bi-telephone-outbound"></i> Call
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (hasRole(['admin', 'receptionist'])): ?>
                                                    <a href="modules/add_patient.php?dept=<?= (int)$dept['department_id'] ?>" class="btn btn-outline-primary" title="Register Patient">
                                                        <i class="bi bi-plus-lg"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Role Access & System Architecture Panel -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-light">
                        <span class="fs-6 fw-bold"><i class="bi bi-shield-lock me-2"></i>Active Role Privileges</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="small text-muted text-uppercase fw-semibold">Current Account:</span>
                            <div class="d-flex align-items-center mt-1">
                                <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-circle me-2">
                                    <i class="bi bi-person-fill fs-5"></i>
                                </div>
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <span class="badge <?= $roleBadgeClass ?>"><?= htmlspecialchars(ucfirst($userRole), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="small">
                            <div class="fw-bold text-dark mb-2">Available Capabilities:</div>
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2 text-success">
                                    <i class="bi bi-check-circle-fill me-1"></i> View Live Department Queues &amp; Stats
                                </li>
                                <li class="mb-2 <?= hasRole(['admin', 'receptionist']) ? 'text-success' : 'text-muted' ?>">
                                    <i class="bi <?= hasRole(['admin', 'receptionist']) ? 'bi-check-circle-fill' : 'bi-dash-circle' ?> me-1"></i> Register Patients &amp; Generate Tokens
                                </li>
                                <li class="mb-2 <?= hasRole(['admin', 'operator']) ? 'text-success' : 'text-muted' ?>">
                                    <i class="bi <?= hasRole(['admin', 'operator']) ? 'bi-check-circle-fill' : 'bi-dash-circle' ?> me-1"></i> Call Next Patient &amp; Doctor Assignment
                                </li>
                                <li class="mb-2 <?= hasRole(['admin', 'operator']) ? 'text-success' : 'text-muted' ?>">
                                    <i class="bi <?= hasRole(['admin', 'operator']) ? 'bi-check-circle-fill' : 'bi-dash-circle' ?> me-1"></i> Manage Doctor Availability
                                </li>
                                <li class="mb-2 text-success">
                                    <i class="bi bi-check-circle-fill me-1"></i> Hash Table Fast Token Search
                                </li>
                                <li class="mb-2 <?= hasRole('admin') ? 'text-success' : 'text-muted' ?>">
                                    <i class="bi <?= hasRole('admin') ? 'bi-check-circle-fill' : 'bi-dash-circle' ?> me-1"></i> Daily Reports &amp; Analytics
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Queue Activity -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fs-6 fw-bold"><i class="bi bi-clock-history me-2"></i>Today's Recent Token Activity</span>
                <a href="modules/history.php" class="btn btn-sm btn-outline-secondary">View All History</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Patient Name</th>
                            <th>Department</th>
                            <th>Type / Urgency</th>
                            <th>Arrival Time</th>
                            <th>Doctor Assigned</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentEntries)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    No queue records found for today yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentEntries as $entry): ?>
                                <tr>
                                    <td>
                                        <span class="token-badge <?= $entry['queue_type'] === 'Emergency' ? 'emergency' : '' ?>">
                                            <?= htmlspecialchars($entry['token_number'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="fw-semibold"><?= htmlspecialchars($entry['patient_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($entry['department_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($entry['queue_type'] === 'Emergency'): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-lightning-fill"></i> Urgency <?= (int)$entry['urgency_level'] ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= htmlspecialchars(date('H:i', strtotime($entry['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= $entry['doctor_name'] ? htmlspecialchars($entry['doctor_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">None</span>' ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $statusBadge = match($entry['status']) {
                                                'Waiting' => 'bg-warning text-dark',
                                                'Called' => 'bg-info text-dark',
                                                'In Consultation' => 'bg-primary text-white',
                                                'Completed' => 'bg-success text-white',
                                                'Cancelled' => 'bg-secondary text-white',
                                                default => 'bg-light text-dark'
                                            };
                                        ?>
                                        <span class="badge <?= $statusBadge ?>"><?= htmlspecialchars($entry['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer>
        <div class="container-fluid px-4 text-center">
            <span>Hospital Queue Management System &copy; <?= date('Y') ?> | Data Structures &amp; Algorithms Prototype</span>
        </div>
    </footer>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>
