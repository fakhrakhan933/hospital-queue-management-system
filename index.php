<?php
/**
 * Login Page - Hospital Queue Management System
 * Screen 1: Authentication, Role Assignment, and CSRF Protection
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

// If already authenticated, redirect to main dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$usernameVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($submittedCsrf)) {
        $error = 'Security check failed (invalid or expired session token). Please refresh and try again.';
    } else {
        $usernameVal = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($usernameVal) || empty($password)) {
            $error = 'Please enter both username and password.';
        } else {
            try {
                $pdo = getDBConnection();
                // Prepared statement to fetch user safely
                $stmt = $pdo->prepare("
                    SELECT user_id, username, password_hash, role, full_name 
                    FROM users 
                    WHERE username = :username 
                    LIMIT 1
                ");
                $stmt->execute([':username' => $usernameVal]);
                $user = $stmt->fetch();

                // Verify password hash
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Prevent session fixation attacks
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    // Refresh CSRF token for authenticated session
                    unset($_SESSION['csrf_token']);
                    getCsrfToken();

                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid username or password.';
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = 'A database error occurred. Please verify your database connection.';
            }
        }
    }
}

// Generate active CSRF token for the login form
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Hospital Queue Management System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-wrapper">
    <div class="container d-flex justify-content-center align-items-center">
        <div class="card auth-card bg-white shadow-lg border-0">
            <div class="card-header text-center">
                <div class="mb-2 text-primary">
                    <i class="bi bi-hospital fs-1"></i>
                </div>
                <h4 class="fw-bold mb-1 text-dark">Hospital Queue System</h4>
                <p class="text-muted small">Data Structures &amp; Algorithms Based Web Application</p>
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php" novalidate>
                    <!-- CSRF Protection Token -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                            <input 
                                type="text" 
                                class="form-control" 
                                id="username" 
                                name="username" 
                                placeholder="Enter username" 
                                required 
                                autofocus 
                                value="<?= htmlspecialchars($usernameVal, ENT_QUOTES, 'UTF-8') ?>"
                            >
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label fw-semibold">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                            <input 
                                type="password" 
                                class="form-control" 
                                id="password" 
                                name="password" 
                                placeholder="Enter password" 
                                required
                            >
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold mt-2 shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </button>
                </form>

                <hr class="my-4">

                <div class="bg-light p-3 rounded-3 border">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold small text-secondary"><i class="bi bi-key me-1"></i>Demo Accounts:</span>
                        <span class="badge bg-secondary">One-Click Fill</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="fillCredentials('admin', 'admin123')">
                            <i class="bi bi-shield-lock me-1"></i>Admin
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="fillCredentials('receptionist', 'reception123')">
                            <i class="bi bi-person-badge me-1"></i>Receptionist
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="fillCredentials('operator', 'operator123')">
                            <i class="bi bi-headset me-1"></i>Queue Operator
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 Bundle JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
    <script>
        function fillCredentials(user, pass) {
            document.getElementById('username').value = user;
            document.getElementById('password').value = pass;
        }
    </script>
</body>
</html>
