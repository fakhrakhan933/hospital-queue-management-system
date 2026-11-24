<?php
/**
 * Authentication, Role Authorization, and CSRF Protection Helper
 * Hospital Queue Management System
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve existing CSRF token
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate submitted CSRF token using timing-attack safe comparison
 */
function validateCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if current user is authenticated
 */
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['username']) && !empty($_SESSION['role']);
}

/**
 * Enforce authentication on protected pages.
 * Redirects unauthenticated visitors to login page.
 */
function requireLogin(string $redirectPath = 'index.php'): void {
    if (!isLoggedIn()) {
        header("Location: " . $redirectPath);
        exit;
    }
}

/**
 * Check if the current user has any of the required roles.
 * 'admin' is treated as a super-user with access to all sections.
 *
 * @param string|array $allowedRoles
 * @return bool
 */
function hasRole(string|array $allowedRoles): bool {
    if (!isLoggedIn()) {
        return false;
    }
    $currentRole = $_SESSION['role'] ?? '';
    if ($currentRole === 'admin') {
        return true;
    }
    if (is_array($allowedRoles)) {
        return in_array($currentRole, $allowedRoles, true);
    }
    return $currentRole === $allowedRoles;
}

/**
 * Enforce role-based access control (RBAC).
 * Returns HTTP 403 Forbidden if user lacks sufficient privilege.
 *
 * @param string|array $allowedRoles
 * @param string|null $fallbackRedirect
 */
function requireRole(string|array $allowedRoles, ?string $fallbackRedirect = null): void {
    requireLogin();
    if (!hasRole($allowedRoles)) {
        if ($fallbackRedirect !== null) {
            header("Location: " . $fallbackRedirect);
            exit;
        }
        http_response_code(403);
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 - Access Denied</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css" rel="stylesheet">
        </head>
        <body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">
            <div class="card shadow-sm border-danger p-4 text-center" style="max-width: 500px;">
                <div class="text-danger mb-3"><i class="bi bi-shield-lock-fill fs-1"></i></div>
                <h3 class="fw-bold text-dark">Access Denied</h3>
                <p class="text-muted">You do not have permission to access this page with your current role (<strong>' . htmlspecialchars($_SESSION['role'] ?? 'Guest') . '</strong>).</p>
                <div class="mt-3">
                    <a href="dashboard.php" class="btn btn-primary px-4 me-2"><i class="bi bi-speedometer2 me-1"></i> Return to Dashboard</a>
                    <a href="logout.php" class="btn btn-outline-secondary">Switch Account</a>
                </div>
            </div>
        </body>
        </html>';
        exit;
    }
}

/**
 * Return sanitized current user info
 */
function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id'   => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'role'      => $_SESSION['role'],
        'full_name' => $_SESSION['full_name'] ?? $_SESSION['username']
    ];
}
