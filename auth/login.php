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
    <title>Login - Associazione Soci Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .login-container { min-height: 100vh; }
        .login-card { max-width: 400px; width: 100%; }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center login-container">
        <div class="card shadow-sm login-card">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <i class="bi bi-people-fill fs-1 text-primary"></i>
                    <h4 class="mt-2">Gestione Soci</h4>
                    <p class="text-muted">Accedi al tuo account</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="login.php">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Accedi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>