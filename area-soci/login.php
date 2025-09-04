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
        
        // In un'app reale, qui si invierebbe una email.
        // Per ora, mostriamo il link direttamente.
        $reset_link = "http://{$_SERVER['HTTP_HOST']}" . dirname($_SERVER['PHP_SELF']) . "/set-password.php?token=$token";
        $success = "Link per impostare la password generato (normalmente verrebbe inviato via email): <br><a href='$reset_link'>$reset_link</a>";
    } else {
        $error = 'Nessun socio trovato con questa email.';
    }
}
?>
<!DOCTYPE html><html lang="it"><head><title>Login Area Riservata</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body>
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow" style="width: 450px;">
        <div class="card-body p-5">
            <h3 class="card-title text-center mb-4">Area Riservata Soci</h3>
            <?php if($error) echo "<div class='alert alert-danger'>$error</div>"; ?>
            <?php if($success) echo "<div class='alert alert-success'>$success</div>"; ?>
            <nav><div class="nav nav-tabs" id="nav-tab"><button class="nav-link active" id="nav-login-tab" data-bs-toggle="tab" data-bs-target="#nav-login">Login</button><button class="nav-link" id="nav-reset-tab" data-bs-toggle="tab" data-bs-target="#nav-reset">Imposta/Reset Password</button></div></nav>
            <div class="tab-content p-3 border border-top-0">
                <div class="tab-pane fade show active" id="nav-login">
                    <form method="POST"><input type="hidden" name="action" value="login">
                        <div class="mb-3"><label>Email</label><input type="email" name="email" class="form-control" required></div>
                        <div class="mb-3"><label>Password</label><input type="password" name="password" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary w-100">Accedi</button>
                    </form>
                </div>
                <div class="tab-pane fade" id="nav-reset">
                    <p class="text-muted small">Se è il tuo primo accesso o hai dimenticato la password, inserisci la tua email per ricevere un link per impostarla.</p>
                    <form method="POST"><input type="hidden" name="action" value="reset_password">
                        <div class="mb-3"><label>La tua Email</label><input type="email" name="email_reset" class="form-control" required></div>
                        <button type="submit" class="btn btn-secondary w-100">Invia Link</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>