<?php
/**
 * Screen 5: Token Search via In-Memory Hash Table
 * Demonstrates Separate Chaining Hash Table for O(1) Token Lookup with DB Fallback & Dynamic Rebuild
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../classes/HashTable.php';
require_once __DIR__ . '/../classes/QueueRebuilder.php';

requireLogin('../index.php');

$user = currentUser();
$userRole = $user['role'];
$userName = $user['full_name'];
$csrfToken = getCsrfToken();

$pdo = getDBConnection();

// 1. Rebuild separate-chaining Hash Table from MySQL active/historical records
$hashTable = QueueRebuilder::rebuildHashTable($pdo);

// 2. Handle search query
$searchTerm = strtoupper(trim($_GET['token'] ?? ''));
$searchResult = null;
$tokenLogs = [];
$lookupMethod = null; // 'hash_table' or 'db_fallback'

if (!empty($searchTerm)) {
    // Step A: In-memory O(1) Hash Table Lookup
    $searchResult = $hashTable->search($searchTerm);

    if ($searchResult) {
        $lookupMethod = 'hash_table';
    } else {
        // Step B: Database Fallback & Dynamic Hash Table Rebuild
        $fallbackStmt = $pdo->prepare("
            SELECT 
                q.queue_id,
                q.patient_id,
                q.department_id,
                q.doctor_id,
                q.token_number,
                q.urgency_level,
                q.queue_type,
                q.arrival_time,
                q.status,
                p.name AS patient_name,
                p.age,
                p.gender,
                p.phone,
                p.address,
                d.department_name,
                d.department_code,
                doc.name AS doctor_name
            FROM queue_entries q
            JOIN patients p ON q.patient_id = p.patient_id
            JOIN departments d ON q.department_id = d.department_id
            LEFT JOIN doctors doc ON q.doctor_id = doc.doctor_id
            WHERE UPPER(TRIM(q.token_number)) = :tok
            LIMIT 1
        ");
        $fallbackStmt->execute([':tok' => $searchTerm]);
        $dbRow = $fallbackStmt->fetch();

        if ($dbRow) {
            // Re-insert into in-memory Hash Table (dynamic rebuild)
            $hashTable->insert($searchTerm, $dbRow);
            $searchResult = $dbRow;
            $lookupMethod = 'db_fallback';
        }
    }

    if ($searchResult) {
        // Fetch complete audit logs lifecycle for this queue entry
        $logStmt = $pdo->prepare("
            SELECT l.*, u.full_name AS staff_name
            FROM queue_logs l
            LEFT JOIN users u ON l.performed_by = u.user_id
            WHERE l.queue_id = :qid
            ORDER BY l.log_id ASC
        ");
        $logStmt->execute([':qid' => $searchResult['queue_id']]);
        $tokenLogs = $logStmt->fetchAll();
    }
}

// Fetch a quick sample of active tokens for quick staff reference
$activeTokensStmt = $pdo->query("
    SELECT token_number, status, queue_type, urgency_level
    FROM queue_entries
    WHERE status IN ('Waiting', 'Called', 'In Consultation')
    ORDER BY arrival_time DESC
    LIMIT 8
");
$activeTokens = $activeTokensStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Token Status Lookup - Hospital Queue System</title>
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
                    <li class="nav-item"><a class="nav-link active" href="search.php"><i class="bi bi-search me-1"></i> Search Token</a></li>
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

    <main class="container py-4" style="max-width: 900px;">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h3 class="fw-bold mb-1"><i class="bi bi-search text-primary me-2"></i>Token Status Lookup</h3>
                <p class="text-muted mb-0">Separate-chaining Hash Table with MySQL fallback and dynamic rebuild</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-light text-secondary border px-3 py-2">
                    <i class="bi bi-cpu me-1"></i>Hash Table Bucket Index: <?= (int)$hashTable->getCount() ?> Items
                </span>
            </div>
        </div>

        <!-- Search Form Card -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <form method="GET" action="search.php" class="mb-3">
                    <label for="tokenInput" class="form-label fw-bold">Enter Token Number:</label>
                    <div class="input-group input-group-lg">
                        <span class="input-group-text bg-white"><i class="bi bi-upc-scan text-primary"></i></span>
                        <input type="text" class="form-control" id="tokenInput" name="token" placeholder="e.g. GM-001, PD-001, OR-001" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" required autofocus>
                        <button class="btn btn-primary px-4 fw-semibold" type="submit">
                            <i class="bi bi-search me-1"></i> Search Token
                        </button>
                    </div>
                </form>

                <?php if (!empty($activeTokens)): ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="text-muted small fw-semibold">Quick Lookup:</span>
                        <?php foreach ($activeTokens as $at): ?>
                            <a href="search.php?token=<?= urlencode($at['token_number']) ?>" class="badge text-decoration-none <?= $at['queue_type'] === 'Emergency' ? 'bg-danger text-white' : 'bg-light text-dark border' ?>">
                                <?= htmlspecialchars($at['token_number'], ENT_QUOTES, 'UTF-8') ?>
                                <small class="opacity-75">(<?= htmlspecialchars($at['status'], ENT_QUOTES, 'UTF-8') ?>)</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Search Results Section -->
        <?php if (!empty($searchTerm)): ?>
            <?php if ($searchResult): ?>
                <div class="card shadow-sm mb-4 border-success">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-check-circle-fill fs-5 me-2"></i>
                            <span class="fw-bold">Token Record Verified</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($lookupMethod === 'hash_table'): ?>
                                <span class="badge bg-light text-success fw-bold">
                                    <i class="bi bi-lightning-charge-fill me-1"></i>O(1) Hash Table Hit
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark fw-bold">
                                    <i class="bi bi-database-check me-1"></i>DB Fallback &amp; Dynamic Rebuilt
                                </span>
                            <?php endif; ?>
                            <span class="token-badge <?= $searchResult['queue_type'] === 'Emergency' ? 'emergency' : '' ?>">
                                <?= htmlspecialchars($searchResult['token_number'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="text-muted small text-uppercase fw-semibold">Patient Name:</span>
                                <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($searchResult['patient_name'], ENT_QUOTES, 'UTF-8') ?></h4>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted small text-uppercase fw-semibold">Current Queue Status:</span>
                                <div>
                                    <?php 
                                        $badgeClass = match($searchResult['status']) {
                                            'Waiting' => 'badge-status-waiting',
                                            'Called' => 'badge-status-called',
                                            'In Consultation' => 'badge-status-consultation',
                                            'Completed' => 'badge-status-completed',
                                            'Cancelled' => 'badge-status-cancelled',
                                            default => 'bg-secondary text-white'
                                        };
                                    ?>
                                    <span class="badge <?= $badgeClass ?> fs-6 px-3 py-1">
                                        <?= htmlspecialchars($searchResult['status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Department:</span>
                                <div class="fw-semibold text-dark">
                                    <?= htmlspecialchars($searchResult['department_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="badge bg-light text-secondary border ms-1"><?= htmlspecialchars($searchResult['department_code'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Queue Scheduling Type:</span>
                                <div>
                                    <?php if ($searchResult['queue_type'] === 'Emergency'): ?>
                                        <span class="badge bg-danger">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>Emergency (Staff Urgency <?= (int)$searchResult['urgency_level'] ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">
                                            <i class="bi bi-arrow-right-circle me-1"></i>Normal FIFO
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Assigned Doctor:</span>
                                <div class="fw-semibold">
                                    <?= !empty($searchResult['doctor_name']) ? htmlspecialchars($searchResult['doctor_name'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Not assigned yet</span>' ?>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Demographics:</span>
                                <div><?= (int)$searchResult['age'] ?> yrs / <?= htmlspecialchars($searchResult['gender'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Contact Phone:</span>
                                <div><?= htmlspecialchars($searchResult['phone'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="col-md-4">
                                <span class="text-muted small text-uppercase fw-semibold">Arrival Timestamp:</span>
                                <div><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($searchResult['arrival_time'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>

                        <!-- Audit History Lifecycle of this Token -->
                        <h6 class="fw-bold mt-4 mb-2 text-dark"><i class="bi bi-clock-history me-1"></i>Token Audit Lifecycle (queue_logs)</h6>
                        <?php if (empty($tokenLogs)): ?>
                            <p class="text-muted small mb-0">No historical log entries recorded for this token.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush border rounded-3">
                                <?php foreach ($tokenLogs as $tl): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                        <div>
                                            <span class="badge bg-primary me-2"><?= htmlspecialchars($tl['action'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="small fw-semibold text-dark"><?= htmlspecialchars($tl['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if (!empty($tl['staff_name'])): ?>
                                                <span class="text-muted small">by <?= htmlspecialchars($tl['staff_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-muted small"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($tl['action_time'])), ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning shadow-sm">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>
                    No queue record matching token <strong>"<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>"</strong> was found in the in-memory hash table or database storage.
                </div>
            <?php endif; ?>
        <?php endif; ?>
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
