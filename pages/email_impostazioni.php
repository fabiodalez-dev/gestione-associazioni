<?php
// pages/email_impostazioni.php — SMTP settings + auto-notification toggles

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}

require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/email_helpers.php';

$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Load current SMTP settings
$stmt = $pdo->prepare('SELECT * FROM smtp_settings WHERE associazione_id = ? LIMIT 1');
$stmt->execute([$associazione_id]);
$smtp = $stmt->fetch();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza: token CSRF non valido.';
        $messageType = 'danger';
    } else {
        try {
            $host = cleanInput($_POST['smtp_host'] ?? '');
            $port = (int)($_POST['smtp_port'] ?? 587);
            $user = cleanInput($_POST['smtp_user'] ?? '');
            $encryption = in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $_POST['smtp_encryption'] : 'tls';
            $fromEmail = cleanInput($_POST['from_email'] ?? '');
            $fromName = cleanInput($_POST['from_name'] ?? '');
            $replyEmail = cleanInput($_POST['reply_to_email'] ?? '') ?: null;
            $replyName = cleanInput($_POST['reply_to_name'] ?? '') ?: null;
            $maxPerHour = max(1, (int)($_POST['max_per_hour'] ?? 100));

            $autoBenvenuto = isset($_POST['auto_benvenuto']) ? 1 : 0;
            $autoScadTessera = isset($_POST['auto_scadenza_tessera']) ? 1 : 0;
            $autoScadQuota = isset($_POST['auto_scadenza_quota']) ? 1 : 0;
            $autoRinnovoTessera = isset($_POST['auto_rinnovo_tessera']) ? 1 : 0;
            $autoPagamentoQuota = isset($_POST['auto_pagamento_quota']) ? 1 : 0;

            // Handle password: only update if user provided a new one
            $passwordChanged = !empty($_POST['smtp_pass']);
            if ($passwordChanged) {
                $encryptedPass = encryptValue($_POST['smtp_pass']);
            } elseif ($smtp) {
                $encryptedPass = $smtp['smtp_pass_encrypted'];
            } else {
                $encryptedPass = '';
            }

            if ($smtp) {
                // UPDATE
                $sql = 'UPDATE smtp_settings SET smtp_host=?, smtp_port=?, smtp_user=?, smtp_pass_encrypted=?, smtp_encryption=?, from_email=?, from_name=?, reply_to_email=?, reply_to_name=?, max_per_hour=?, auto_benvenuto=?, auto_scadenza_tessera=?, auto_scadenza_quota=?, auto_rinnovo_tessera=?, auto_pagamento_quota=?, updated_at=NOW() WHERE associazione_id=?';
                $stmtSave = $pdo->prepare($sql);
                $stmtSave->execute([$host, $port, $user, $encryptedPass, $encryption, $fromEmail, $fromName, $replyEmail, $replyName, $maxPerHour, $autoBenvenuto, $autoScadTessera, $autoScadQuota, $autoRinnovoTessera, $autoPagamentoQuota, $associazione_id]);
            } else {
                // INSERT
                $sql = 'INSERT INTO smtp_settings (id, associazione_id, smtp_host, smtp_port, smtp_user, smtp_pass_encrypted, smtp_encryption, from_email, from_name, reply_to_email, reply_to_name, max_per_hour, auto_benvenuto, auto_scadenza_tessera, auto_scadenza_quota, auto_rinnovo_tessera, auto_pagamento_quota) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
                $stmtSave = $pdo->prepare($sql);
                $stmtSave->execute([generateUuid(), $associazione_id, $host, $port, $user, $encryptedPass, $encryption, $fromEmail, $fromName, $replyEmail, $replyName, $maxPerHour, $autoBenvenuto, $autoScadTessera, $autoScadQuota, $autoRinnovoTessera, $autoPagamentoQuota]);

                // Seed default templates on first SMTP setup
                $emailService = new EmailService($pdo, $associazione_id);
                $emailService->seedDefaultTemplates();
            }

            $message = 'Impostazioni SMTP salvate con successo.';
            $messageType = 'success';

            // Reload settings
            $stmt->execute([$associazione_id]);
            $smtp = $stmt->fetch();
        } catch (Exception $e) {
            error_log('email_impostazioni.php save error: ' . $e->getMessage());
            $message = 'Errore durante il salvataggio.';
            $messageType = 'danger';
        }
    }
}

// Stats
$pendingCount = 0;
$sentToday = 0;
$failedToday = 0;
try {
    $stmtP = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE associazione_id = ? AND status = "pending"');
    $stmtP->execute([$associazione_id]);
    $pendingCount = (int)$stmtP->fetchColumn();

    $stmtST = $pdo->prepare('SELECT COUNT(*) FROM email_log WHERE associazione_id = ? AND status = "sent" AND DATE(sent_at) = CURDATE()');
    $stmtST->execute([$associazione_id]);
    $sentToday = (int)$stmtST->fetchColumn();

    $stmtFT = $pdo->prepare('SELECT COUNT(*) FROM email_log WHERE associazione_id = ? AND status = "failed" AND DATE(sent_at) = CURDATE()');
    $stmtFT->execute([$associazione_id]);
    $failedToday = (int)$stmtFT->fetchColumn();
} catch (PDOException $e) {
    error_log('email_impostazioni.php stats error: ' . $e->getMessage());
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-gear me-2"></i>Impostazioni SMTP</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

<div class="row">
    <!-- Left column: SMTP config -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-hdd-network me-1"></i>Configurazione SMTP</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Host SMTP</label>
                        <input type="text" class="form-control" name="smtp_host" value="<?php echo htmlspecialchars($smtp['smtp_host'] ?? ''); ?>" placeholder="smtp.gmail.com" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Porta</label>
                        <input type="number" class="form-control" name="smtp_port" value="<?php echo (int)($smtp['smtp_port'] ?? 587); ?>" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Utente SMTP</label>
                        <input type="text" class="form-control" name="smtp_user" value="<?php echo htmlspecialchars($smtp['smtp_user'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Crittografia</label>
                        <select class="form-select" name="smtp_encryption">
                            <option value="tls" <?php echo ($smtp['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>STARTTLS (porta 587)</option>
                            <option value="ssl" <?php echo ($smtp['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (porta 465)</option>
                            <option value="none" <?php echo ($smtp['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>Nessuna</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password SMTP</label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="smtp_pass" id="smtpPass" placeholder="<?php echo $smtp ? '••••••• (lascia vuoto per non modificare)' : 'Inserisci la password'; ?>">
                        <button class="btn btn-outline-secondary" type="button" onclick="var f=document.getElementById('smtpPass');f.type=f.type==='password'?'text':'password'"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Email mittente</label>
                        <input type="email" class="form-control" name="from_email" value="<?php echo htmlspecialchars($smtp['from_email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nome mittente</label>
                        <input type="text" class="form-control" name="from_name" value="<?php echo htmlspecialchars($smtp['from_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Reply-To Email <small class="text-muted">(opzionale)</small></label>
                        <input type="email" class="form-control" name="reply_to_email" value="<?php echo htmlspecialchars($smtp['reply_to_email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Reply-To Nome <small class="text-muted">(opzionale)</small></label>
                        <input type="text" class="form-control" name="reply_to_name" value="<?php echo htmlspecialchars($smtp['reply_to_name'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-bell me-1"></i>Notifiche Automatiche</h5></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Limite invii per ora</label>
                    <input type="number" class="form-control" name="max_per_hour" value="<?php echo (int)($smtp['max_per_hour'] ?? 100); ?>" min="1" max="1000" style="max-width:200px;">
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auto_benvenuto" id="autoBenvenuto" <?php echo ($smtp['auto_benvenuto'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="autoBenvenuto">Email di benvenuto ai nuovi soci</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auto_scadenza_tessera" id="autoScadTess" <?php echo ($smtp['auto_scadenza_tessera'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="autoScadTess">Promemoria scadenza tessera</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auto_scadenza_quota" id="autoScadQuota" <?php echo ($smtp['auto_scadenza_quota'] ?? 1) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="autoScadQuota">Promemoria quote da pagare</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auto_rinnovo_tessera" id="autoRinnTess" <?php echo ($smtp['auto_rinnovo_tessera'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="autoRinnTess">Conferma rinnovo tessera</label>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="auto_pagamento_quota" id="autoPagQuota" <?php echo ($smtp['auto_pagamento_quota'] ?? 0) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="autoPagQuota">Conferma pagamento quota</label>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva Impostazioni</button>
    </div>

    <!-- Right column: test & stats -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-send-check me-1"></i>Test Connessione</h5></div>
            <div class="card-body">
                <?php if ($smtp && $smtp['smtp_host']): ?>
                    <div class="mb-3">
                        <label class="form-label">Email destinatario test</label>
                        <input type="email" class="form-control" id="testEmail" placeholder="test@example.com">
                    </div>
                    <button type="button" class="btn btn-outline-primary w-100" id="btnTestSmtp">
                        <i class="bi bi-envelope-arrow-up me-1"></i>Invia Email Test
                    </button>
                    <div id="testResult" class="mt-2"></div>
                    <?php if ($smtp['is_verified']): ?>
                        <div class="mt-2"><span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Verificato</span>
                        <?php if ($smtp['last_test_at']): ?>
                            <small class="text-muted ms-1"><?php echo date('d/m/Y H:i', strtotime($smtp['last_test_at'])); ?></small>
                        <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">Salva prima la configurazione SMTP per poter inviare un test.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-graph-up me-1"></i>Statistiche Rapide</h5></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>In coda (pending)</span>
                    <span class="badge bg-warning text-dark"><?php echo $pendingCount; ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Inviate oggi</span>
                    <span class="badge bg-success"><?php echo $sentToday; ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Fallite oggi</span>
                    <span class="badge bg-danger"><?php echo $failedToday; ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var btnTest = document.getElementById('btnTestSmtp');
    if (btnTest) {
        btnTest.addEventListener('click', function() {
            var email = document.getElementById('testEmail').value.trim();
            if (!email) { alert('Inserisci un indirizzo email.'); return; }
            var resultDiv = document.getElementById('testResult');
            resultDiv.textContent = '';
            var spinner = document.createElement('div');
            spinner.className = 'spinner-border spinner-border-sm text-primary';
            spinner.setAttribute('role', 'status');
            resultDiv.appendChild(spinner);
            resultDiv.appendChild(document.createTextNode(' Invio in corso...'));
            btnTest.disabled = true;

            var formData = new FormData();
            formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
            formData.append('email', email);

            fetch('api/email_test.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultDiv.textContent = '';
                    var alertDiv = document.createElement('div');
                    alertDiv.className = 'alert py-1 px-2 mb-0 alert-' + (data.success ? 'success' : 'danger');
                    alertDiv.textContent = data.message || (data.success ? 'Test riuscito!' : 'Errore nel test.');
                    resultDiv.appendChild(alertDiv);
                    btnTest.disabled = false;
                })
                .catch(function() {
                    resultDiv.textContent = '';
                    var alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger py-1 px-2 mb-0';
                    alertDiv.textContent = 'Errore di rete.';
                    resultDiv.appendChild(alertDiv);
                    btnTest.disabled = false;
                });
        });
    }
});
</script>
