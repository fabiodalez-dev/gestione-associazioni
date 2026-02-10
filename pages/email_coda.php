<?php
// pages/email_coda.php — Email queue management

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}

require_once __DIR__ . '/../includes/EmailService.php';

$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza: token CSRF non valido.';
        $messageType = 'danger';
    } else {
        $emailService = new EmailService($pdo, $associazione_id);

        if (isset($_POST['cancel_id'])) {
            $stmtC = $pdo->prepare('UPDATE email_queue SET status = "cancelled" WHERE id = ? AND associazione_id = ? AND status = "pending"');
            $stmtC->execute([cleanInput($_POST['cancel_id']), $associazione_id]);
            $message = 'Email annullata.';
            $messageType = 'success';
        } elseif (isset($_POST['cancel_all'])) {
            $stmtCA = $pdo->prepare('UPDATE email_queue SET status = "cancelled" WHERE associazione_id = ? AND status = "pending"');
            $stmtCA->execute([$associazione_id]);
            $count = $stmtCA->rowCount();
            $message = $count . ' email annullate.';
            $messageType = 'success';
        } elseif (isset($_POST['retry_id'])) {
            $stmtR = $pdo->prepare('UPDATE email_queue SET status = "pending", attempts = 0, error_message = NULL WHERE id = ? AND associazione_id = ? AND status = "failed"');
            $stmtR->execute([cleanInput($_POST['retry_id']), $associazione_id]);
            $message = 'Email rimessa in coda.';
            $messageType = 'success';
        }
    }
}

// Stats
$stats = ['pending' => 0, 'sending' => 0, 'sent_today' => 0, 'failed' => 0];
try {
    $stmtS = $pdo->prepare('SELECT status, COUNT(*) as cnt FROM email_queue WHERE associazione_id = ? AND status IN ("pending","sending","failed") GROUP BY status');
    $stmtS->execute([$associazione_id]);
    foreach ($stmtS->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['cnt'];
    }
    $stmtST = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE associazione_id = ? AND status = "sent" AND DATE(sent_at) = CURDATE()');
    $stmtST->execute([$associazione_id]);
    $stats['sent_today'] = (int)$stmtST->fetchColumn();
} catch (PDOException $e) {
    error_log('email_coda.php stats: ' . $e->getMessage());
}

// Queue items
$page_num = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page_num - 1) * $perPage;

$stmtQ = $pdo->prepare('SELECT eq.*, s.nome as socio_nome, s.cognome as socio_cognome FROM email_queue eq LEFT JOIN soci s ON s.id = eq.socio_id WHERE eq.associazione_id = ? ORDER BY FIELD(eq.status, "sending","pending","failed","sent","cancelled"), eq.priority ASC, eq.created_at DESC LIMIT ? OFFSET ?');
$stmtQ->bindValue(1, $associazione_id);
$stmtQ->bindValue(2, $perPage, PDO::PARAM_INT);
$stmtQ->bindValue(3, $offset, PDO::PARAM_INT);
$stmtQ->execute();
$queueItems = $stmtQ->fetchAll();

$stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE associazione_id = ?');
$stmtTotal->execute([$associazione_id]);
$totalItems = (int)$stmtTotal->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-hourglass-split me-2"></i>Coda Invio Email</h1>
    <div class="btn-toolbar">
        <button type="button" class="btn btn-primary me-2" id="btnProcessQueue">
            <i class="bi bi-play-fill me-1"></i>Elabora Coda
        </button>
        <form method="POST" class="d-inline" onsubmit="return confirm('Annullare tutte le email in attesa?')">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="cancel_all" value="1">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Annulla Pendenti</button>
        </form>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Stats bar -->
<div class="row mb-4">
    <div class="col-md-3"><div class="card text-center"><div class="card-body py-2"><h4 class="text-warning mb-0"><?php echo $stats['pending']; ?></h4><small class="text-muted">Pendenti</small></div></div></div>
    <div class="col-md-3"><div class="card text-center"><div class="card-body py-2"><h4 class="text-info mb-0"><?php echo $stats['sending']; ?></h4><small class="text-muted">In corso</small></div></div></div>
    <div class="col-md-3"><div class="card text-center"><div class="card-body py-2"><h4 class="text-success mb-0"><?php echo $stats['sent_today']; ?></h4><small class="text-muted">Inviate oggi</small></div></div></div>
    <div class="col-md-3"><div class="card text-center"><div class="card-body py-2"><h4 class="text-danger mb-0"><?php echo $stats['failed']; ?></h4><small class="text-muted">Fallite</small></div></div></div>
</div>

<!-- Process progress -->
<div id="processProgress" class="d-none mb-3">
    <div class="progress" style="height:24px;">
        <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="progressBar" role="progressbar" style="width:0%"></div>
    </div>
    <small class="text-muted" id="progressText"></small>
</div>

<!-- Queue table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Priorità</th>
                        <th>Destinatario</th>
                        <th>Oggetto</th>
                        <th>Stato</th>
                        <th>Tentativi</th>
                        <th>Creata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($queueItems)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nessuna email in coda.</td></tr>
                    <?php else: ?>
                        <?php foreach ($queueItems as $item): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?php echo (int)$item['priority']; ?></span></td>
                            <td>
                                <?php echo htmlspecialchars($item['to_email']); ?>
                                <?php if ($item['socio_nome']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['socio_nome'] . ' ' . $item['socio_cognome']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(mb_strimwidth($item['subject'], 0, 50, '...')); ?></td>
                            <td>
                                <?php
                                $badgeMap = ['pending' => 'warning', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger', 'cancelled' => 'secondary'];
                                $badge = $badgeMap[$item['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($item['status']); ?></span>
                                <?php if ($item['error_message']): ?>
                                    <br><small class="text-danger"><?php echo htmlspecialchars(mb_strimwidth($item['error_message'], 0, 60, '...')); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$item['attempts']; ?>/<?php echo (int)$item['max_attempts']; ?></td>
                            <td><small><?php echo date('d/m H:i', strtotime($item['created_at'])); ?></small></td>
                            <td>
                                <?php if ($item['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="cancel_id" value="<?php echo htmlspecialchars($item['id']); ?>"><button class="btn btn-sm btn-outline-danger" title="Annulla"><i class="bi bi-x"></i></button></form>
                                <?php elseif ($item['status'] === 'failed'): ?>
                                    <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="retry_id" value="<?php echo htmlspecialchars($item['id']); ?>"><button class="btn btn-sm btn-outline-warning" title="Riprova"><i class="bi bi-arrow-repeat"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?php echo $i === $page_num ? 'active' : ''; ?>"><a class="page-link" href="index.php?page=email-coda&p=<?php echo $i; ?>"><?php echo $i; ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var btnProcess = document.getElementById('btnProcessQueue');
    if (btnProcess) {
        btnProcess.addEventListener('click', function() {
            btnProcess.disabled = true;
            var prog = document.getElementById('processProgress');
            prog.classList.remove('d-none');
            var bar = document.getElementById('progressBar');
            var txt = document.getElementById('progressText');
            bar.style.width = '10%';
            txt.textContent = 'Elaborazione in corso...';

            var formData = new FormData();
            formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');

            fetch('api/email_queue_process.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    bar.style.width = '100%';
                    bar.classList.remove('progress-bar-animated');
                    txt.textContent = 'Inviate: ' + (data.sent || 0) + ' | Fallite: ' + (data.failed || 0) + ' | Rimanenti: ' + (data.remaining || 0);
                    setTimeout(function() { location.reload(); }, 2000);
                })
                .catch(function() {
                    txt.textContent = 'Errore durante elaborazione.';
                    btnProcess.disabled = false;
                });
        });
    }
});
</script>
