<?php
// auth/login.php - Gestione Login v2.0 (SaaS)

// Includi il file di configurazione che avvia già la sessione
require_once '../config.php';

// Se l'utente è già loggato, reindirizzalo alla dashboard
if (isUserLoggedIn()) {
    redirect('../index.php?page=dashboard');
}

$error = '';

// Gestione del form di login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email e password sono obbligatori.';
    } else {
        try {
            // Cerca l'utente nella nuova tabella `utenti`
            $stmt = $pdo->prepare("SELECT id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo FROM utenti WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Verifica l'utente e la password
            if ($user && password_verify($password, $user['password_hash'])) {
                if (!$user['attivo']) {
                    $error = 'Il tuo account è stato disattivato.';
                } else {
                    // Rigenera ID sessione per prevenire session fixation
                    session_regenerate_id(true);

                    // Imposta le variabili di sessione
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_nome_completo'] = $user['nome'] . ' ' . $user['cognome'];
                    $_SESSION['user_role'] = $user['ruolo'];
                    $_SESSION['associazione_id'] = $user['associazione_id']; // Fondamentale per il SaaS

                    // Carica il nome dell'associazione in sessione (se non è un super_admin)
                    if ($user['associazione_id']) {
                        $stmt_assoc = $pdo->prepare("SELECT nome FROM associazioni WHERE id = ?");
                        $stmt_assoc->execute([$user['associazione_id']]);
                        $_SESSION['associazione_nome'] = $stmt_assoc->fetchColumn();
                    } else {
                        $_SESSION['associazione_nome'] = null; // Super admin senza associazione specifica
                    }

                    // Aggiorna l'ultimo login
                    $stmt_update = $pdo->prepare("UPDATE utenti SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt_update->execute([$user['id']]);

                    // Reindirizza alla dashboard
                    redirect('../index.php?page=dashboard');
                }
            } else {
                $error = 'Credenziali non valide.';
            }
        } catch (PDOException $e) {
            // In produzione, loggare l'errore invece di mostrarlo
            $error = 'Errore del sistema di autenticazione. Riprova più tardi.';
            // error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gestione Associazioni</title>
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
        .login-wrapper {
            width: 100%;
            max-width: 400px;
            padding: 1rem;
        }
        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-brand-icon {
            width: 48px;
            height: 48px;
            background: #FF7B11;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        .login-brand-icon i {
            color: #FFFFFF;
            font-size: 1.5rem;
        }
        .login-brand h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin: 0 0 0.25rem;
        }
        .login-brand p {
            font-size: 0.875rem;
            color: #9CA3AF;
            margin: 0;
        }
        .login-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .form-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #4B5563;
            margin-bottom: 0.375rem;
        }
        .form-control {
            border: 1px solid #D1D5DB;
            border-radius: 6px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: #111827;
            transition: border-color 150ms, box-shadow 150ms;
        }
        .form-control:focus {
            border-color: #FF7B11;
            box-shadow: 0 0 0 3px rgba(255,123,17,0.15);
            outline: none;
        }
        .form-control::placeholder { color: #9CA3AF; }
        .btn-login {
            width: 100%;
            background: #FF7B11;
            border: none;
            color: #FFFFFF;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.625rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background 150ms;
        }
        .btn-login:hover { background: #E86A00; }
        .btn-login:active { transform: translateY(0); }
        .alert {
            border: none;
            border-left: 3px solid #DC2626;
            background: #FEE2E2;
            color: #7F1D1D;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.8125rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-brand">
            <div class="login-brand-icon">
                <i class="bi bi-people-fill"></i>
            </div>
            <h1>Gestione Associazioni</h1>
            <p>Accedi al pannello di gestione</p>
        </div>

        <?php if ($error): ?>
            <div class="alert" role="alert">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="login-card">
            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" placeholder="nome@associazione.it" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" placeholder="La tua password" required>
                </div>
                <button type="submit" class="btn-login">Accedi</button>
            </form>
        </div>
    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
