<?php
/**
 * Doctor Management & Availability Module
 * Role Authorization: Admin, Queue Operator
 * Manages doctors, department assignments, and availability status
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Enforce role authorization
requireRole(['admin', 'operator']);

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();
$msg = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Validate CSRF Protection
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } else {
        $action = $_POST['action'] ?? '';

        // A. Update Doctor Availability
        if ($action === 'update_status') {
            $doctorId = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);
            $newStatus = $_POST['availability'] ?? 'Available';

            if ($doctorId && in_array($newStatus, ['Available', 'Busy', 'Off Duty'], true)) {
                $stmt = $pdo->prepare("UPDATE doctors SET availability = :status WHERE doctor_id = :id");
                $stmt->execute([':status' => $newStatus, ':id' => $doctorId]);
                $msg = "Doctor availability updated successfully.";
            } else {
                $error = "Invalid doctor availability update request.";
            }
        }

        // B. Update Doctor Department Assignment
        elseif ($action === 'update_department') {
            $doctorId = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT);
            $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);

            if ($doctorId && $departmentId) {
                // Verify department exists
                $chk = $pdo->prepare("SELECT department_id FROM departments WHERE department_id = :id");
                $chk->execute([':id' => $departmentId]);
                if ($chk->fetch()) {
                    $stmt = $pdo->prepare("UPDATE doctors SET department_id = :dept_id WHERE doctor_id = :id");
                    $stmt->execute([':dept_id' => $departmentId, ':id' => $doctorId]);
                    $msg = "Doctor department assignment updated successfully.";
                } else {
                    $error = "Selected department does not exist.";
                }
            } else {
                $error = "Please select both a valid doctor and department.";
            }
        }

        // C. Add New Doctor
        elseif ($action === 'add_doctor') {
            $docName = trim($_POST['name'] ?? '');
            $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
            $specialization = trim($_POST['specialization'] ?? '');
            $availability = $_POST['availability'] ?? 'Available';

            if (empty($docName) || !$departmentId) {
                $error = "Please provide the doctor's name and select a department.";
            } elseif (!in_array($availability, ['Available', 'Busy', 'Off Duty'], true)) {
                $availability = 'Available';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO doctors (name, department_id, specialization, availability, created_at)
                    VALUES (:name, :dept_id, :spec, :avail, NOW())
                ");
                $stmt->execute([
                    ':name'    => $docName,
                    ':dept_id' => $departmentId,
                    ':spec'    => !empty($specialization) ? $specialization : 'General Practitioner',
                    ':avail'   => $availability
                ]);
                $msg = "Doctor '{$docName}' added to roster successfully.";
            }
        }
    }
}

// Fetch all clinical departments
$departments = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC")->fetchAll();

// Fetch all doctors with their current department assignment
$stmt = $pdo->query("
    SELECT doc.*, d.department_name, d.department_code 
    FROM doctors doc
    JOIN departments d ON doc.department_id = d.department_id
    ORDER BY d.department_name ASC, doc.name ASC
");
$doctors = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Management - Hospital Queue System</title>
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
                        <li class="nav-item"><a class="nav-link" href="call_patient.php"><i class="bi bi-telephone-outbound me-1"></i> Call Next</a></li>
                        <li class="nav-item"><a class="nav-link active" href="doctors.php"><i class="bi bi-person-badge me-1"></i> Doctors</a></li>
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
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div>
                <h3 class="fw-bold mb-1">Doctor Management &amp; Availability</h3>
                <p class="text-muted mb-0">Manage doctor roster, department assignments, and consultation readiness</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary shadow-sm" data-bs-toggle="collapse" data-bs-target="#addDoctorForm">
                    <i class="bi bi-person-plus-fill me-1"></i> Add Doctor
                </button>
                <div style="width: 260px;">
                    <input type="text" id="tableSearchInput" data-target-table="#doctorTable" class="form-control" placeholder="Search doctor or department...">
                </div>
            </div>
        </div>

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

        <!-- Add Doctor Collapsible Form -->
        <div class="collapse mb-4" id="addDoctorForm">
            <div class="card shadow-sm border-primary">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-person-plus me-2"></i>Register New Doctor
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="doctors.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="add_doctor">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="docName" class="form-label fw-semibold">Doctor Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="docName" name="name" placeholder="e.g. Dr. Arthur Conan" required>
                            </div>
                            <div class="col-md-4">
                                <label for="docDept" class="form-label fw-semibold">Department Assignment <span class="text-danger">*</span></label>
                                <select class="form-select" id="docDept" name="department_id" required>
                                    <option value="" disabled selected>-- Select Department --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= (int)$d['department_id'] ?>">
                                            <?= htmlspecialchars($d['department_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($d['department_code'], ENT_QUOTES, 'UTF-8') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="docSpec" class="form-label fw-semibold">Specialization</label>
                                <input type="text" class="form-control" id="docSpec" name="specialization" placeholder="e.g. Consultant Cardiologist">
                            </div>
                            <div class="col-md-4">
                                <label for="docAvail" class="form-label fw-semibold">Initial Availability</label>
                                <select class="form-select" id="docAvail" name="availability">
                                    <option value="Available" selected>Available</option>
                                    <option value="Busy">Busy</option>
                                    <option value="Off Duty">Off Duty</option>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex align-items-end">
                                <button type="submit" class="btn btn-success px-4 py-2 fw-semibold">
                                    <i class="bi bi-save me-1"></i> Save Doctor
                                </button>
                                <button type="button" class="btn btn-outline-secondary ms-2 py-2" data-bs-toggle="collapse" data-bs-target="#addDoctorForm">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Doctors Roster Table -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-custom align-middle mb-0" id="doctorTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Doctor Name</th>
                                <th>Department Assignment</th>
                                <th>Specialization</th>
                                <th>Current Status</th>
                                <th class="text-end">Update Availability</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($doctors as $idx => $doc): ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td class="fw-bold">
                                        <i class="bi bi-person-fill text-secondary me-1"></i>
                                        <?= htmlspecialchars($doc['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td>
                                        <!-- Department Switcher Dropdown Form -->
                                        <form method="POST" action="doctors.php" class="d-inline-flex align-items-center m-0">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="update_department">
                                            <input type="hidden" name="doctor_id" value="<?= (int)$doc['doctor_id'] ?>">
                                            
                                            <select name="department_id" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                                                <?php foreach ($departments as $d): ?>
                                                    <option value="<?= (int)$d['department_id'] ?>" <?= (int)$d['department_id'] === (int)$doc['department_id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($d['department_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($d['department_code'], ENT_QUOTES, 'UTF-8') ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= htmlspecialchars($doc['specialization'] ?? 'General', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php 
                                            $badgeClass = match($doc['availability']) {
                                                'Available' => 'badge-avail-available',
                                                'Busy' => 'badge-avail-busy',
                                                'Off Duty' => 'badge-avail-offduty',
                                                default => 'bg-secondary'
                                            };
                                        ?>
                                        <span class="badge <?= $badgeClass ?> px-3 py-1"><?= htmlspecialchars($doc['availability'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="doctors.php" class="d-inline-flex gap-1 justify-content-end m-0">
                                            <!-- CSRF Protection -->
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="doctor_id" value="<?= (int)$doc['doctor_id'] ?>">
                                            
                                            <button type="submit" name="availability" value="Available" class="btn btn-sm <?= $doc['availability'] === 'Available' ? 'btn-success' : 'btn-outline-success' ?>" title="Set Available">
                                                Available
                                            </button>
                                            <button type="submit" name="availability" value="Busy" class="btn btn-sm <?= $doc['availability'] === 'Busy' ? 'btn-danger' : 'btn-outline-danger' ?>" title="Set Busy">
                                                Busy
                                            </button>
                                            <button type="submit" name="availability" value="Off Duty" class="btn btn-sm <?= $doc['availability'] === 'Off Duty' ? 'btn-secondary' : 'btn-outline-secondary' ?>" title="Set Off Duty">
                                                Off Duty
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/script.js"></script>
</body>
</html>
