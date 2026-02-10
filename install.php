<?php
/**
 * Associazione Soci Manager - Web Installer
 * Multi-step wizard: Requirements → DB Config → Tables → Admin → Done
 */
define('INSTALLER_ACTIVE', true);

// Block re-running if already installed
if (file_exists(__DIR__ . '/.installed')) {
    header('Location: index.php');
    exit;
}

// Start session for step tracking
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// Increase limits for installation
ini_set('memory_limit', '512M');
set_time_limit(300);

// CSRF helpers (standalone — config.php is not loaded yet)
function installerCsrfToken(): string {
    if (!isset($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function installerCsrfCheck(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['install_csrf']) && hash_equals($_SESSION['install_csrf'], $token);
}

// UUID helper
function installerUuid(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Determine current step (1-5)
$step = (int)($_GET['step'] ?? $_SESSION['install_step'] ?? 1);
if ($step < 1) $step = 1;
if ($step > 5) $step = 5;

// Prevent skipping steps
$maxAllowed = (int)($_SESSION['install_max_step'] ?? 1);
if ($step > $maxAllowed) {
    $step = $maxAllowed;
}

$errors = [];

// ============================================================================
// STEP PROCESSING (POST handlers)
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!installerCsrfCheck()) {
        $errors[] = 'Token CSRF non valido. Ricarica la pagina.';
    } else {
        $postStep = (int)($_POST['step'] ?? 1);

        // --- STEP 1: Requirements check ---
        if ($postStep === 1) {
            $allPassed = true;
            if (version_compare(PHP_VERSION, '7.4.0', '<')) $allPassed = false;
            foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo'] as $ext) {
                if (!extension_loaded($ext)) $allPassed = false;
            }
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0755, true);
            }
            if (!is_writable($uploadsDir) && !is_writable(dirname($uploadsDir))) $allPassed = false;
            if (!is_writable(__DIR__)) $allPassed = false;

            if ($allPassed) {
                $_SESSION['install_max_step'] = 2;
                header('Location: install.php?step=2');
                exit;
            } else {
                $errors[] = 'Alcuni requisiti non sono soddisfatti. Correggi i problemi e riprova.';
                $step = 1;
            }
        }

        // --- STEP 2: Database configuration ---
        if ($postStep === 2) {
            $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
            $dbPort = trim($_POST['db_port'] ?? '3306');
            $dbName = trim($_POST['db_name'] ?? 'associazione_soci_saas');
            $dbUser = trim($_POST['db_user'] ?? 'root');
            $dbPass = $_POST['db_pass'] ?? '';
            $createDb = isset($_POST['create_db']);

            if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
                $errors[] = 'Host, nome database e utente sono obbligatori.';
                $step = 2;
            } else {
                try {
                    // Connect without database first
                    $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
                    $pdo = new PDO($dsn, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);

                    if ($createDb) {
                        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                        $dbName = $safeName;
                    }

                    // Test connecting to the actual database
                    $dsn2 = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
                    $pdo2 = new PDO($dsn2, $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);

                    // Store in session
                    $_SESSION['install_db'] = [
                        'host' => $dbHost,
                        'port' => $dbPort,
                        'name' => $dbName,
                        'user' => $dbUser,
                        'pass' => $dbPass,
                    ];

                    $_SESSION['install_max_step'] = 3;
                    header('Location: install.php?step=3');
                    exit;
                } catch (PDOException $e) {
                    error_log('install.php DB connection error: ' . $e->getMessage());
                    $errors[] = 'Errore di connessione al database. Verifica le credenziali e riprova.';
                    $step = 2;
                }
            }
        }

        // --- STEP 3: Create tables ---
        if ($postStep === 3) {
            $db = $_SESSION['install_db'] ?? null;
            if (!$db) {
                $errors[] = 'Configurazione database mancante. Torna al passaggio 2.';
                $step = 2;
            } else {
                try {
                    $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);

                    $schemaFile = __DIR__ . '/database/schema.sql';
                    if (!file_exists($schemaFile)) {
                        $errors[] = 'File schema non trovato: database/schema.sql';
                        $step = 3;
                    } else {
                        // Drop existing tables to avoid charset/collation conflicts
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                        $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($existingTables as $table) {
                            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                        }
                        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                        $sql = file_get_contents($schemaFile);

                        // Remove comments
                        $sql = preg_replace('/--[^\n]*/', '', $sql);
                        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

                        // Split by semicolons
                        $statements = array_filter(array_map('trim', explode(';', $sql)));

                        $executed = 0;
                        $tableErrors = [];

                        foreach ($statements as $stmtSql) {
                            if (empty($stmtSql) || strlen($stmtSql) < 5) continue;
                            try {
                                $pdo->exec($stmtSql);
                                $executed++;
                            } catch (PDOException $e) {
                                $msg = $e->getMessage();
                                // Silently skip duplicate key/index errors
                                if (strpos($msg, '1061') !== false || strpos($msg, 'Duplicate key name') !== false) {
                                    $executed++;
                                    continue;
                                }
                                $tableErrors[] = $msg;
                            }
                        }

                        // Run migration file if exists
                        $migrationFile = __DIR__ . '/migrations/update_users_table.php';
                        if (file_exists($migrationFile)) {
                            require_once $migrationFile;
                            if (function_exists('runUserTableMigration')) {
                                ob_start();
                                runUserTableMigration($pdo);
                                ob_end_clean();
                            }
                        }

                        // Record initial schema migration
                        try {
                            $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE migration_name = ?");
                            $checkStmt->execute(['initial_schema_v2_multitenant']);
                            if ((int)$checkStmt->fetchColumn() === 0) {
                                $ins = $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
                                $ins->execute(['initial_schema_v2_multitenant']);
                            }
                        } catch (PDOException $e) {
                            // Non-critical
                        }

                        if (!empty($tableErrors)) {
                            foreach ($tableErrors as $te) {
                                $errors[] = $te;
                            }
                            $step = 3;
                        } else {
                            $_SESSION['install_tables_count'] = $executed;
                            $_SESSION['install_max_step'] = 4;
                            header('Location: install.php?step=4');
                            exit;
                        }
                    }
                } catch (PDOException $e) {
                    error_log('install.php schema execution error: ' . $e->getMessage());
                    $errors[] = 'Errore durante la creazione delle tabelle. Verifica i permessi del database.';
                    $step = 3;
                }
            }
        }

        // --- STEP 4: Admin account ---
        if ($postStep === 4) {
            $nome = trim($_POST['nome'] ?? '');
            $cognome = trim($_POST['cognome'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';

            if (empty($nome) || empty($cognome) || empty($email) || empty($password)) {
                $errors[] = 'Tutti i campi sono obbligatori.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Indirizzo email non valido.';
            } elseif (strlen($password) < 8) {
                $errors[] = 'La password deve avere almeno 8 caratteri.';
            } elseif ($password !== $passwordConfirm) {
                $errors[] = 'Le password non coincidono.';
            }

            if (empty($errors)) {
                $db = $_SESSION['install_db'] ?? null;
                if (!$db) {
                    $errors[] = 'Configurazione database mancante.';
                    $step = 2;
                } else {
                    try {
                        $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4";
                        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_EMULATE_PREPARES => false,
                        ]);

                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $userId = installerUuid();

                        // Insert into utenti (primary auth table)
                        $insertUtenti = $pdo->prepare("INSERT INTO utenti (id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo) VALUES (?, NULL, ?, ?, ?, ?, 'super_admin', 1)");
                        $insertUtenti->execute([$userId, $nome, $cognome, $email, $hash]);

                        // Insert into users (legacy table) for backward compat
                        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nome . $cognome));
                        if (empty($username)) $username = 'admin';
                        $insertUsers = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES (?, ?, ?, 'super_admin', NULL)");
                        $insertUsers->execute([$username, $email, $hash]);

                        $_SESSION['install_max_step'] = 5;
                        header('Location: install.php?step=5');
                        exit;
                    } catch (PDOException $e) {
                        $msg = $e->getMessage();
                        if (strpos($msg, 'Duplicate entry') !== false) {
                            $errors[] = 'Un utente con questa email esiste già.';
                        } else {
                            error_log('install.php admin creation error: ' . $msg);
                            $errors[] = 'Errore nella creazione dell\'account. Riprova più tardi.';
                        }
                    }
                }
            }
            $step = 4;
        }

        // --- STEP 5: Finalize ---
        if ($postStep === 5) {
            $db = $_SESSION['install_db'] ?? null;
            if (!$db) {
                $errors[] = 'Configurazione database mancante.';
                $step = 2;
            } else {
                // Write .env file
                $sessionSecret = bin2hex(random_bytes(32));
                $envContent = "# Generato dall'installer - " . date('Y-m-d H:i:s') . "\n"
                    . "DB_HOST={$db['host']}\n"
                    . "DB_PORT={$db['port']}\n"
                    . "DB_NAME={$db['name']}\n"
                    . "DB_USER={$db['user']}\n"
                    . "DB_PASS={$db['pass']}\n"
                    . "APP_ENV=production\n"
                    . "SESSION_SECRET={$sessionSecret}\n";

                $envPath = __DIR__ . '/.env';
                if (file_put_contents($envPath, $envContent) === false) {
                    $errors[] = 'Impossibile scrivere il file .env';
                }

                // Create upload directories
                $dirs = [__DIR__ . '/uploads', __DIR__ . '/uploads/logos'];
                foreach ($dirs as $dir) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                }

                if (empty($errors)) {
                    // Create lock file only on success
                    file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

                    // Clear session install data
                    unset($_SESSION['install_db'], $_SESSION['install_step'], $_SESSION['install_max_step'], $_SESSION['install_csrf']);
                    $_SESSION['install_done'] = true;
                }
            }
        }
    }
}

// Step labels
$stepLabels = [
    1 => 'Requisiti',
    2 => 'Database',
    3 => 'Tabelle',
    4 => 'Admin',
    5 => 'Completamento',
];

$pageTitle = 'Installazione - ' . $stepLabels[$step];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <style>
        :root { --brand: #FF7B11; --brand-dark: #e06800; }
        body { background: #f5f6fa; min-height: 100vh; }
        .installer-header { background: var(--brand); color: #fff; padding: 1.5rem 0; margin-bottom: 2rem; }
        .installer-header h1 { font-size: 1.5rem; margin: 0; }
        .step-indicator { display: flex; justify-content: center; gap: .5rem; margin-bottom: 2rem; }
        .step-badge { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
                      font-weight: 600; font-size: .9rem; border: 2px solid #dee2e6; color: #6c757d; background: #fff; }
        .step-badge.active { border-color: var(--brand); background: var(--brand); color: #fff; }
        .step-badge.done { border-color: #198754; background: #198754; color: #fff; }
        .step-line { width: 40px; align-self: center; height: 2px; background: #dee2e6; }
        .step-line.done { background: #198754; }
        .card { border: none; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
        .btn-brand { background: var(--brand); border-color: var(--brand); color: #fff; }
        .btn-brand:hover { background: var(--brand-dark); border-color: var(--brand-dark); color: #fff; }
        .check-ok { color: #198754; font-weight: 600; }
        .check-fail { color: #dc3545; font-weight: 600; }
        .table-checks td { vertical-align: middle; }
    </style>
</head>
<body>
    <div class="installer-header text-center">
        <h1>Associazione Soci Manager &mdash; Installazione</h1>
    </div>
    <div class="container" style="max-width: 680px;">
        <!-- Step indicator -->
        <div class="step-indicator">
            <?php foreach ($stepLabels as $num => $label): ?>
                <?php if ($num > 1): ?>
                    <div class="step-line <?= $num <= $step ? 'done' : '' ?>"></div>
                <?php endif; ?>
                <div class="step-badge <?= $num < $step ? 'done' : ($num === $step ? 'active' : '') ?>"
                     title="<?= htmlspecialchars($label) ?>">
                    <?php if ($num < $step): ?>&#10003;<?php else: ?><?= $num ?><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Errors / Success -->
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?>
                    <div><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STEP 1: System Requirements -->
        <!-- ================================================================ -->
        <?php if ($step === 1): ?>
        <?php
            $checks = [];
            $checks['PHP >= 7.4'] = version_compare(PHP_VERSION, '7.4.0', '>=');
            foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'fileinfo'] as $ext) {
                $checks["Estensione: {$ext}"] = extension_loaded($ext);
            }
            $uploadsDir = __DIR__ . '/uploads';
            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
            $checks['Directory uploads/ scrivibile'] = is_writable($uploadsDir);
            $checks['Directory root scrivibile (per .env)'] = is_writable(__DIR__);
            $allOk = !in_array(false, $checks, true);
        ?>
        <div class="card p-4">
            <h4 class="mb-3">Passaggio 1 &mdash; Requisiti di Sistema</h4>
            <table class="table table-checks mb-3">
                <thead><tr><th>Requisito</th><th class="text-end">Stato</th></tr></thead>
                <tbody>
                <?php foreach ($checks as $label => $ok): ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td class="text-end"><?= $ok ? '<span class="check-ok">OK</span>' : '<span class="check-fail">MANCANTE</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td>Versione PHP attuale</td>
                    <td class="text-end"><code><?= PHP_VERSION ?></code></td>
                </tr>
                </tbody>
            </table>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= installerCsrfToken() ?>">
                <input type="hidden" name="step" value="1">
                <button type="submit" class="btn btn-brand w-100" <?= $allOk ? '' : 'disabled' ?>>
                    Avanti &rarr;
                </button>
                <?php if (!$allOk): ?>
                    <small class="text-muted d-block mt-2">Correggi i requisiti mancanti e ricarica la pagina.</small>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STEP 2: Database Configuration -->
        <!-- ================================================================ -->
        <?php if ($step === 2): ?>
        <div class="card p-4">
            <h4 class="mb-3">Passaggio 2 &mdash; Configurazione Database</h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= installerCsrfToken() ?>">
                <input type="hidden" name="step" value="2">
                <div class="row g-3">
                    <div class="col-8">
                        <label class="form-label">Host</label>
                        <input type="text" name="db_host" class="form-control" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1') ?>">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Porta</label>
                        <input type="text" name="db_port" class="form-control" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nome Database</label>
                        <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? 'associazione_soci_saas') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Utente</label>
                        <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Password</label>
                        <input type="password" name="db_pass" class="form-control" value="">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_db" id="createDb" checked>
                            <label class="form-check-label" for="createDb">Crea database se non esiste</label>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-brand w-100 mt-3">Testa Connessione &amp; Avanti &rarr;</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STEP 3: Create Tables -->
        <!-- ================================================================ -->
        <?php if ($step === 3): ?>
        <div class="card p-4">
            <h4 class="mb-3">Passaggio 3 &mdash; Creazione Tabelle</h4>
            <p class="text-muted">Verranno create 24 tabelle nel database <strong><?= htmlspecialchars($_SESSION['install_db']['name'] ?? '') ?></strong>.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= installerCsrfToken() ?>">
                <input type="hidden" name="step" value="3">
                <button type="submit" class="btn btn-brand w-100">Crea Tabelle &rarr;</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STEP 4: Admin Account -->
        <!-- ================================================================ -->
        <?php if ($step === 4): ?>
        <div class="card p-4">
            <h4 class="mb-3">Passaggio 4 &mdash; Account Amministratore</h4>
            <p class="text-muted">Crea il primo account super admin per accedere al sistema.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= installerCsrfToken() ?>">
                <input type="hidden" name="step" value="4">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Nome</label>
                        <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Cognome</label>
                        <input type="text" name="cognome" class="form-control" required value="<?= htmlspecialchars($_POST['cognome'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <small class="text-muted">Minimo 8 caratteri</small>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Conferma Password</label>
                        <input type="password" name="password_confirm" class="form-control" required minlength="8">
                    </div>
                </div>
                <button type="submit" class="btn btn-brand w-100 mt-3">Crea Account &rarr;</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- ================================================================ -->
        <!-- STEP 5: Completion -->
        <!-- ================================================================ -->
        <?php if ($step === 5): ?>
        <div class="card p-4 text-center">
            <?php if (isset($_SESSION['install_done'])): ?>
                <div class="mb-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="#198754" viewBox="0 0 16 16">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM6.97 11.03a.75.75 0 0 0 1.07 0l3.992-3.992a.75.75 0 0 0-1.07-1.07L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.07 1.07L6.97 11.03z"/>
                    </svg>
                </div>
                <h4 class="text-success mb-3">Installazione Completata!</h4>
                <p class="text-muted">Il sistema è pronto. Il file <code>.env</code> è stato generato e il database è stato configurato.</p>
                <a href="auth/login.php" class="btn btn-brand btn-lg mt-2">Vai al Login &rarr;</a>
            <?php else: ?>
                <h4 class="mb-3">Passaggio 5 &mdash; Completamento</h4>
                <p class="text-muted">Genera il file <code>.env</code>, crea le directory necessarie e completa l'installazione.</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= installerCsrfToken() ?>">
                    <input type="hidden" name="step" value="5">
                    <button type="submit" class="btn btn-brand btn-lg w-100">Completa Installazione</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-4 mb-5">
            <small class="text-muted">Associazione Soci Manager &copy; <?= date('Y') ?></small>
        </div>
    </div>
    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
