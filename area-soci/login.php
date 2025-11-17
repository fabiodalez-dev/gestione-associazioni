<?php
require_once '../config.php';

$error = '';
$success = '';

// Show timeout message if session expired
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error = 'Sessione scaduta. Effettua nuovamente il login.';
}

// Logica di login
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Errore di sicurezza. Riprova.';
        logSecurityEvent($pdo, 'csrf_failed', 'CSRF token invalid on socio login', ['email' => $email], 'critical');
    } elseif (empty($email) || empty($password)) {
        $error = 'Email e password sono obbligatori.';
    } else {
        // Rate Limiting Check
        try {
            checkLoginRateLimit($pdo, $email, 'socio');

            // Query login
            $stmt = $pdo->prepare("SELECT * FROM soci WHERE email = ? AND password_hash IS NOT NULL");
            $stmt->execute([$email]);
            $socio = $stmt->fetch();

            if ($socio && password_verify($password, $socio['password_hash'])) {
                // LOGIN SUCCESS
                clearLoginAttempts($pdo, $email, 'socio');

                $_SESSION['socio_id'] = $socio['id'];
                $_SESSION['socio_nome'] = $socio['nome'] . ' ' . $socio['cognome'];
                $_SESSION['socio_associazione_id'] = $socio['associazione_id'];

                // Log security event
                logSecurityEvent($pdo, 'login_success', 'Socio login successful', [
                    'email' => $email,
                    'socio_id' => $socio['id']
                ], 'info');

                redirect('index.php');
            } else {
                // LOGIN FAILED - Generic message
                $error = 'Email o password non corretti.';
                recordLoginAttempt($pdo, $email, 'socio');
                logSecurityEvent($pdo, 'login_failed', 'Socio login attempt failed', ['email' => $email], 'warning');
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            logSecurityEvent($pdo, 'login_rate_limit', 'Rate limit exceeded for socio login', ['email' => $email], 'warning');
        }
    }
}

// Logica per richiesta reset password
if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    $email = cleanInput($_POST['email_reset'] ?? '');

    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token_reset'] ?? '')) {
        $error = 'Errore di sicurezza. Riprova.';
        logSecurityEvent($pdo, 'csrf_failed', 'CSRF token invalid on password reset', ['email' => $email], 'critical');
    } elseif (empty($email)) {
        $error = 'Email obbligatoria.';
    } else {
        // Rate Limiting for password reset (prevent abuse)
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            checkLoginRateLimit($pdo, $ip, 'socio'); // Use IP for rate limiting resets

            $stmt = $pdo->prepare("SELECT id, associazione_id FROM soci WHERE email = ?");
            $stmt->execute([$email]);
            $socio = $stmt->fetch();

            // Always show success message (don't reveal if email exists - security)
            if ($socio) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // Token valido per 1 ora
                $stmt = $pdo->prepare("UPDATE soci SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
                $stmt->execute([$token, $expires, $socio['id']]);

                // Log security event
                logSecurityEvent($pdo, 'password_reset_requested', 'Password reset token generated', [
                    'email' => $email,
                    'socio_id' => $socio['id']
                ], 'info');

                // In un'app reale, qui si invierebbe una email.
                // Per ora, mostriamo il link direttamente (SOLO PER SVILUPPO!)
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $reset_link = "$protocol://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['PHP_SELF']) . "/set-password.php?token=$token";
                $success = "Se l'email è presente nel sistema, riceverai un link per impostare la password.<br><strong>SVILUPPO:</strong> <a href='$reset_link' target='_blank'>Clicca qui per impostare la password</a>";
            } else {
                // Don't reveal if email exists - show same message
                $success = "Se l'email è presente nel sistema, riceverai un link per impostare la password.";
                logSecurityEvent($pdo, 'password_reset_failed', 'Password reset attempted for non-existent email', ['email' => $email], 'warning');
            }
        } catch (Exception $e) {
            $error = "Troppe richieste. " . $e->getMessage();
            logSecurityEvent($pdo, 'password_reset_rate_limit', 'Rate limit exceeded for password reset', ['email' => $email], 'warning');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Area Riservata</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .login-card { border-radius: 15px; }
    </style>
</head>
<body>
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow-lg login-card" style="width: 500px;">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <i class="bi bi-person-circle fs-1 text-primary"></i>
                <h3 class="mt-2">Area Riservata Soci</h3>
                <p class="text-muted">Accedi al tuo account</p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <ul class="nav nav-pills nav-fill mb-3" id="loginTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="login-tab" data-bs-toggle="tab" data-bs-target="#login-panel" type="button">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Login
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="reset-tab" data-bs-toggle="tab" data-bs-target="#reset-panel" type="button">
                        <i class="bi bi-key me-1"></i> Reset Password
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Login Tab -->
                <div class="tab-pane fade show active" id="login-panel" role="tabpanel">
                    <form method="POST" action="login.php">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                        <div class="mb-3">
                            <label for="email" class="form-label">
                                <i class="bi bi-envelope me-1"></i>Email
                            </label>
                            <input type="email" name="email" id="email" class="form-control" placeholder="tua.email@esempio.it" required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">
                                <i class="bi bi-lock me-1"></i>Password
                            </label>
                            <input type="password" name="password" id="password" class="form-control" placeholder="La tua password" required>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Accedi
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Reset Password Tab -->
                <div class="tab-pane fade" id="reset-panel" role="tabpanel">
                    <p class="text-muted small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Se è il tuo primo accesso o hai dimenticato la password, inserisci la tua email per ricevere un link per impostarla.
                    </p>
                    <form method="POST" action="login.php">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="csrf_token_reset" value="<?php echo generateCSRFToken(); ?>">

                        <div class="mb-3">
                            <label for="email_reset" class="form-label">
                                <i class="bi bi-envelope me-1"></i>La tua Email
                            </label>
                            <input type="email" name="email_reset" id="email_reset" class="form-control" placeholder="tua.email@esempio.it" required>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-secondary btn-lg">
                                <i class="bi bi-send me-2"></i>Invia Link Reset
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <hr class="my-4">
            <p class="text-center text-muted small mb-0">
                <i class="bi bi-shield-check me-1"></i>Accesso sicuro protetto
            </p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
