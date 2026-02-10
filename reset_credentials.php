<?php
/**
 * Reset Credentials Script
 * Questo script permette di resettare le credenziali degli utenti
 *
 * IMPORTANTE: Eliminare questo file dopo l'uso per motivi di sicurezza!
 * Accessibile SOLO da CLI per motivi di sicurezza.
 */

// Block web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Accesso negato. Questo script è utilizzabile solo da linea di comando.';
    exit(1);
}

// Includi la configurazione
require_once 'config.php';

$message = '';
$messageType = '';

// Se viene inviato il form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_admin') {
        try {
            // Resetta l'admin di default
            $new_password = 'admin123';
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

            // Cerca l'utente admin nella tabella utenti
            $stmt = $pdo->prepare("SELECT id FROM utenti WHERE email = ?");
            $stmt->execute(['admin@test.com']);
            $user = $stmt->fetch();

            if ($user) {
                // Aggiorna la password esistente
                $stmt = $pdo->prepare("UPDATE utenti SET password_hash = ?, attivo = 1 WHERE email = ?");
                $stmt->execute([$password_hash, 'admin@test.com']);
                $message = "Password resettata con successo!<br>Email: admin@test.com<br>Password: admin123";
                $messageType = 'success';
            } else {
                // Crea un nuovo utente admin
                $id = generateUuid();
                $stmt = $pdo->prepare("INSERT INTO utenti (id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo) VALUES (?, NULL, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$id, 'Super', 'Admin', 'admin@test.com', $password_hash, 'super_admin']);
                $message = "Utente admin creato con successo!<br>Email: admin@test.com<br>Password: admin123";
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            error_log('reset_credentials.php PDOException: ' . $e->getMessage());
            $message = "Errore database. Controlla i log per dettagli.";
            $messageType = 'danger';
        }
    } elseif ($action === 'create_custom') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $nome = trim($_POST['nome'] ?? 'Utente');
        $cognome = trim($_POST['cognome'] ?? 'Admin');

        if (empty($email) || empty($password)) {
            $message = "Email e password sono obbligatori!";
            $messageType = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Email non valida!";
            $messageType = 'danger';
        } else {
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Verifica se l'utente esiste già
                $stmt = $pdo->prepare("SELECT id FROM utenti WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    // Aggiorna la password esistente
                    $stmt = $pdo->prepare("UPDATE utenti SET password_hash = ?, nome = ?, cognome = ?, attivo = 1 WHERE email = ?");
                    $stmt->execute([$password_hash, $nome, $cognome, $email]);
                    $message = "Password aggiornata con successo!<br>Email: " . htmlspecialchars($email);
                    $messageType = 'success';
                } else {
                    // Crea un nuovo utente
                    $id = generateUuid();
                    $stmt = $pdo->prepare("INSERT INTO utenti (id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo) VALUES (?, NULL, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$id, $nome, $cognome, $email, $password_hash, 'super_admin']);
                    $message = "Utente creato con successo!<br>Email: " . htmlspecialchars($email);
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                error_log('reset_credentials.php PDOException: ' . $e->getMessage());
            $message = "Errore database. Controlla i log per dettagli.";
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'list_users') {
        try {
            $stmt = $pdo->query("SELECT id, nome, cognome, email, ruolo, attivo FROM utenti ORDER BY created_at DESC");
            $users = $stmt->fetchAll();

            if (empty($users)) {
                $message = "Nessun utente trovato nel database.";
                $messageType = 'info';
            }
        } catch (PDOException $e) {
            error_log('reset_credentials.php PDOException: ' . $e->getMessage());
            $message = "Errore database. Controlla i log per dettagli.";
            $messageType = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Credenziali</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding: 20px; }
        .container { max-width: 800px; }
        .card { margin-bottom: 20px; }
        .security-warning {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="security-warning">
            <h5><i class="bi bi-exclamation-triangle-fill text-warning"></i> ATTENZIONE ALLA SICUREZZA!</h5>
            <p class="mb-0">Questo script permette di modificare le credenziali degli utenti. <strong>Eliminalo immediatamente dopo l'uso!</strong></p>
        </div>

        <h1 class="mb-4"><i class="bi bi-shield-lock"></i> Reset Credenziali</h1>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
                <?php echo $message; // Contains intentional HTML (<br> tags) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Resetta Admin di Default -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-arrow-clockwise"></i> Resetta Admin di Default</h5>
            </div>
            <div class="card-body">
                <p>Resetta o crea l'utente admin di default con credenziali predefinite.</p>
                <form method="POST">
                    <input type="hidden" name="action" value="reset_admin">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-clockwise"></i> Resetta Admin (admin@test.com / admin123)
                    </button>
                </form>
            </div>
        </div>

        <!-- Crea/Aggiorna Utente Personalizzato -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-person-plus"></i> Crea/Aggiorna Utente Personalizzato</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="create_custom">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="nome" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="nome" name="nome" value="Utente">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="cognome" class="form-label">Cognome</label>
                            <input type="text" class="form-control" id="cognome" name="cognome" value="Admin">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password *</label>
                        <input type="text" class="form-control" id="password" name="password" required>
                        <small class="form-text text-muted">Usa una password sicura!</small>
                    </div>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-person-plus"></i> Crea/Aggiorna Utente
                    </button>
                </form>
            </div>
        </div>

        <!-- Lista Utenti -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-list"></i> Lista Utenti</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="list_users">
                    <button type="submit" class="btn btn-outline-info mb-3">
                        <i class="bi bi-list"></i> Mostra Tutti gli Utenti
                    </button>
                </form>

                <?php if (isset($users) && !empty($users)): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Ruolo</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['ruolo'] === 'super_admin' ? 'danger' : 'primary'; ?>">
                                                <?php echo htmlspecialchars($user['ruolo']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['attivo'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['attivo'] ? 'Attivo' : 'Disattivo'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="auth/login.php" class="btn btn-outline-primary">
                <i class="bi bi-box-arrow-in-right"></i> Vai al Login
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-house"></i> Torna alla Home
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
