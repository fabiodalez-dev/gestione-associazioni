<?php
require_once '../config.php';

$error = '';
$success = '';

// Logica di login
if (isset($_POST['action']) && $_POST['action'] == 'login') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM soci WHERE email = ? AND password_hash IS NOT NULL");
    $stmt->execute([$email]);
    $socio = $stmt->fetch();

    if ($socio && password_verify($password, $socio['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['socio_id'] = $socio['id'];
        $_SESSION['socio_nome'] = $socio['nome'] . ' ' . $socio['cognome'];
        redirect('index.php');
    } else {
        $error = 'Credenziali non valide.';
    }
}

// Logica per richiesta reset password
if (isset($_POST['action']) && $_POST['action'] == 'reset_password') {
    $email = sanitizeInput($_POST['email_reset']);
    $stmt = $pdo->prepare("SELECT id FROM soci WHERE email = ?");
    $stmt->execute([$email]);
    $socio = $stmt->fetch();

    if ($socio) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // Token valido per 1 ora
        $stmt = $pdo->prepare("UPDATE soci SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
        $stmt->execute([$token, $expires, $socio['id']]);

        $safe_host = preg_replace('/[^a-zA-Z0-9.\-:_]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $reset_link = "http://{$safe_host}" . dirname($_SERVER['PHP_SELF']) . "/set-password.php?token=$token";
        $escaped_link = htmlspecialchars($reset_link);
        $success = "Link per impostare la password generato (normalmente verrebbe inviato via email): <br><a href='$escaped_link'>$escaped_link</a>";
    } else {
        $error = 'Nessun socio trovato con questa email.';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Riservata - Login</title>
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        @font-face {
            font-family: 'Inter';
            src: url('../assets/fonts/InterVariable.woff2') format('woff2');
            font-weight: 100 900;
            font-style: normal;
            font-display: swap;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #F3F4F6;
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-font-smoothing: antialiased;
        }
        .login-wrapper { width: 100%; max-width: 440px; padding: 1rem; }
        .login-brand { text-align: center; margin-bottom: 2rem; }
        .login-brand-icon {
            width: 48px; height: 48px;
            background: #FF7B11;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .login-brand-icon i { color: #FFFFFF; font-size: 1.5rem; }
        .login-brand h1 { font-size: 1.25rem; font-weight: 700; color: #111827; margin: 0 0 0.25rem; }
        .login-brand p { font-size: 0.875rem; color: #9CA3AF; margin: 0; }
        .login-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .form-label { font-size: 0.8125rem; font-weight: 500; color: #4B5563; margin-bottom: 0.375rem; }
        .form-control {
            border: 1px solid #D1D5DB; border-radius: 6px;
            padding: 0.5rem 0.75rem; font-size: 16px; color: #111827;
            transition: border-color 150ms, box-shadow 150ms;
            min-height: 44px;
        }
        .form-control:focus { border-color: #FF7B11; box-shadow: 0 0 0 3px rgba(255,123,17,0.15); outline: none; }
        .btn-login {
            width: 100%; background: #FF7B11; border: none; color: #FFFFFF;
            font-weight: 600; font-size: 0.875rem; padding: 0.625rem 1rem;
            border-radius: 6px; cursor: pointer; transition: background 150ms;
            min-height: 44px;
        }
        .btn-login:hover { background: #E86A00; }
        .btn-secondary-custom {
            width: 100%; background: #F3F4F6; border: 1px solid #D1D5DB; color: #4B5563;
            font-weight: 500; font-size: 0.875rem; padding: 0.625rem 1rem;
            border-radius: 6px; cursor: pointer; transition: all 150ms;
            min-height: 44px;
        }
        .btn-secondary-custom:hover { background: #E5E7EB; border-color: #9CA3AF; }
        .nav-tabs { border-bottom: 1px solid #E5E7EB; margin-bottom: 1.5rem; }
        .nav-tabs .nav-link {
            border: none; color: #9CA3AF; font-weight: 500; font-size: 0.8125rem;
            padding: 0.625rem 1rem; border-bottom: 2px solid transparent;
            cursor: pointer; background: none;
        }
        .nav-tabs .nav-link:hover { color: #4B5563; }
        .nav-tabs .nav-link.active { color: #FF7B11; border-bottom-color: #FF7B11; }
        .alert-danger {
            border: none; border-left: 3px solid #DC2626;
            background: #FEE2E2; color: #7F1D1D;
            border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.8125rem; margin-bottom: 1rem;
        }
        .alert-success {
            border: none; border-left: 3px solid #16A34A;
            background: #DCFCE7; color: #14532D;
            border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.8125rem; margin-bottom: 1rem;
        }
        .alert-success a { color: #FF7B11; font-weight: 500; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-icon">
                <i class="bi bi-person-fill"></i>
            </div>
            <h1>Area Riservata Soci</h1>
            <p>Accedi alla tua area personale</p>
        </div>

        <?php if($error) echo "<div class='alert-danger'>" . htmlspecialchars($error) . "</div>"; ?>
        <?php if($success) echo "<div class='alert-success'>$success</div>"; ?>

        <div class="login-card">
            <nav>
                <div class="nav nav-tabs" id="nav-tab">
                    <button class="nav-link active" id="nav-login-tab" data-bs-toggle="tab" data-bs-target="#nav-login">Login</button>
                    <button class="nav-link" id="nav-reset-tab" data-bs-toggle="tab" data-bs-target="#nav-reset">Reset Password</button>
                </div>
            </nav>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="nav-login">
                    <form method="POST">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="nome@email.it" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" placeholder="La tua password" required>
                        </div>
                        <button type="submit" class="btn-login">Accedi</button>
                    </form>
                </div>
                <div class="tab-pane fade" id="nav-reset">
                    <p style="font-size: 0.8125rem; color: #6B7280; margin-bottom: 1rem;">Inserisci la tua email per ricevere un link per impostare o resettare la password.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="reset_password">
                        <div class="mb-3">
                            <label class="form-label">La tua Email</label>
                            <input type="email" name="email_reset" class="form-control" placeholder="nome@email.it" required>
                        </div>
                        <button type="submit" class="btn-secondary-custom">Invia Link</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
