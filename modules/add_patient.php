<?php
/**
 * Screen 3: Patient Registration & Token Generation
 * Role Authorization: Admin, Receptionist
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Enforce role authorization
requireRole(['admin', 'receptionist']);

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// Fetch departments for dropdown using prepared statement
$deptStmt = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC");
$departments = $deptStmt->fetchAll();

$success = $_GET['success'] ?? '';
$token = $_GET['token'] ?? '';
$error = $_GET['error'] ?? '';
$preselectedDept = filter_var($_GET['dept'] ?? null, FILTER_VALIDATE_INT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Patient - Hospital Queue System</title>
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
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
                    <?php if (hasRole(['admin', 'receptionist'])): ?>
                        <li class="nav-item"><a class="nav-link active" href="add_patient.php"><i class="bi bi-person-plus me-1"></i> Register Patient</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="queue.php"><i class="bi bi-list-ol me-1"></i> Queues</a></li>
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

    <main class="container py-4" style="max-width: 820px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-1">Patient Registration</h3>
                <p class="text-muted mb-0">Capture patient details and issue a unique arrival token</p>
            </div>
            <a href="../dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>

        <!-- Success Alert -->
        <?php if (!empty($success) && !empty($token)): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill fs-3 me-3 text-success"></i>
                    <div>
                        <h5 class="alert-heading mb-1 fw-bold">Token Generated Successfully!</h5>
                        <div>Patient Token Number: <strong class="fs-4 text-dark token-badge ms-1"><?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?></strong></div>
                        <div class="small text-muted mt-1">
                            The patient has been assigned a unique token and placed in the department waiting queue.
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Registration Form Card -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-bottom py-3">
                <span class="fw-bold fs-6"><i class="bi bi-person-lines-fill text-primary me-2"></i>Patient &amp; Queue Visit Details</span>
            </div>
            <div class="card-body p-4">
                <form action="../actions/register.php" method="POST" novalidate>
                    <!-- CSRF Protection Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="row g-3">
                        <!-- Patient Full Name -->
                        <div class="col-md-12">
                            <label for="name" class="form-label fw-semibold">Patient Full Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                                <input type="text" class="form-control" id="name" name="name" placeholder="e.g. John Doe" required autofocus>
                            </div>
                        </div>

                        <!-- Age -->
                        <div class="col-md-6">
                            <label for="age" class="form-label fw-semibold">Age <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="age" name="age" min="0" max="130" placeholder="e.g. 35" required>
                        </div>

                        <!-- Gender -->
                        <div class="col-md-6">
                            <label for="gender" class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="" disabled selected>-- Select Gender --</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Phone -->
                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-semibold">Phone Number</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-telephone"></i></span>
                                <input type="tel" class="form-control" id="phone" name="phone" placeholder="e.g. 0300-1234567">
                            </div>
                        </div>

                        <!-- Department Selection -->
                        <div class="col-md-6">
                            <label for="department_id" class="form-label fw-semibold">Department <span class="text-danger">*</span></label>
                            <select class="form-select" id="department_id" name="department_id" required>
                                <option value="" disabled <?= !$preselectedDept ? 'selected' : '' ?>>-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= (int)$dept['department_id'] ?>" <?= $preselectedDept === (int)$dept['department_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['department_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($dept['department_code'], ENT_QUOTES, 'UTF-8') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Residential Address -->
                        <div class="col-md-12">
                            <label for="address" class="form-label fw-semibold">Residential Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-geo-alt"></i></span>
                                <input type="text" class="form-control" id="address" name="address" placeholder="e.g. House #12, Street 3, Islamabad">
                            </div>
                        </div>

                        <!-- Queue Type & Priority Separation -->
                        <div class="col-12"><hr class="my-3"></div>

                        <div class="col-md-6">
                            <label for="queue_type" class="form-label fw-semibold">Queue Classification <span class="text-danger">*</span></label>
                            <select class="form-select" id="queue_type" name="queue_type" required>
                                <option value="Normal" selected>Normal Queue (FIFO Waiting Line)</option>
                                <option value="Emergency">Emergency-designated (Max Heap Priority)</option>
                            </select>
                            <div class="form-text">Normal patients are served in arrival order. Emergency cases enter priority heap.</div>
                        </div>

                        <!-- Staff-Entered Urgency Level (Emergency only) -->
                        <div class="col-md-6" id="urgency_container" style="display: none;">
                            <label for="urgency_level" class="form-label fw-semibold text-danger">
                                Staff-Assigned Urgency (1 - 5) <span class="text-danger">*</span>
                            </label>
                            <select class="form-select border-danger" id="urgency_level" name="urgency_level">
                                <option value="1">1 - Minor Urgency (Scheduling Tier 1)</option>
                                <option value="2">2 - Low Urgency (Scheduling Tier 2)</option>
                                <option value="3">3 - Moderate Urgency (Scheduling Tier 3)</option>
                                <option value="4">4 - High Urgency (Scheduling Tier 4)</option>
                                <option value="5" selected>5 - Critical / Maximum Urgency (Scheduling Tier 5)</option>
                            </select>
                            <div class="form-text text-danger small mt-1">
                                <i class="bi bi-exclamation-octagon me-1"></i>
                                Higher value receives scheduling priority in Max Heap. Equal urgencies break ties by arrival time.
                            </div>
                        </div>

                        <!-- Academic Scope Disclaimer -->
                        <div class="col-12 mt-3">
                            <div class="p-3 bg-light border rounded-3 small text-muted">
                                <i class="bi bi-info-circle-fill text-primary me-1"></i>
                                <strong>Academic Scheduling Scope Note:</strong>
                                Urgency levels are staff-entered demonstration and queue-scheduling values, not clinical triage or medical advice. Real clinical staff and hospital emergency escalation protocols always take priority.
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="col-12 mt-4 d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4 py-2 fw-semibold shadow-sm">
                                <i class="bi bi-ticket-perforated me-1"></i> Register &amp; Generate Token
                            </button>
                            <button type="reset" class="btn btn-outline-secondary px-3 py-2">
                                Clear Form
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
