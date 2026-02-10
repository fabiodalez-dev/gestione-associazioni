<?php
require_once '../config.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$socio = null;

if (empty($token)) {
    $error = "Token non valido o mancante.";
} else {
    $stmt = $pdo->prepare("SELECT * FROM soci WHERE password_reset_token = ? AND password_reset_expires > NOW()");
    $stmt->execute([$token]);
    $socio = $stmt->fetch();
    if (!$socio) {
        $error = "Token non valido o scaduto. Richiedi un nuovo link.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $socio) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Errore di sicurezza: token CSRF non valido.";
    } else {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if ($password !== $password_confirm || strlen($password) < 8) {
        $error = "Le password non corrispondono o sono troppo corte (min. 8 caratteri).";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE soci SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?");
        $stmt->execute([$password_hash, $socio['id']]);
        $success = "Password impostata con successo! Ora puoi effettuare il login.";
    }
    }
}
?>
<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#FF7B11"><title>Imposta Password</title><link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet"><style>.form-control{font-size:16px;min-height:44px}.btn{min-height:44px}</style></head><body>
<div class="container d-flex justify-content-center align-items-center vh-100 px-3">
    <div class="card shadow" style="max-width: 450px; width: 100%;">
        <div class="card-body p-5">
            <h3 class="card-title text-center mb-4">Imposta la tua Password</h3>
            <?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><a href='login.php' class='btn btn-primary w-100'>Vai al Login</a><?php else: ?>
                <?php if(!$error): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3"><label>Nuova Password</label><input type="password" name="password" class="form-control" required></div>
                    <div class="mb-3"><label>Conferma Password</label><input type="password" name="password_confirm" class="form-control" required></div>
                    <button type="submit" class="btn btn-primary w-100">Imposta Password</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body></html>
