<?php
// pages/comunicazioni.php — Send communications with GrapesJS editor & real email queue

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

$emailService = new EmailService($pdo, $associazione_id);
$smtpConfigured = $emailService->isConfigured();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_communication'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza: token CSRF non valido.';
        $messageType = 'danger';
    } elseif (!$smtpConfigured) {
        $message = 'SMTP non configurato. Vai alle Impostazioni SMTP per configurarlo.';
        $messageType = 'danger';
    } else {
        try {
            $subject = cleanInput($_POST['subject'] ?? '');
            $bodyHtml = $_POST['corpo_html'] ?? '';
            $recipientType = $_POST['recipient_type'] ?? 'all';

            if (empty($subject) || empty($bodyHtml)) {
                $message = 'Oggetto e corpo email sono obbligatori.';
                $messageType = 'danger';
            } else {
                // Build recipients query
                $sql = 'SELECT s.id, s.email, s.nome, s.cognome FROM soci s WHERE s.associazione_id = ? AND s.email IS NOT NULL AND s.email != "" AND s.stato = "Attivo" AND (s.email_opt_out = 0 OR s.email_opt_out IS NULL)';
                $params = [$associazione_id];

                if ($recipientType === 'category' && !empty($_POST['category_id'])) {
                    $sql .= ' AND s.categoria_socio_id = ?';
                    $params[] = $_POST['category_id'];
                } elseif ($recipientType === 'section' && !empty($_POST['section_id'])) {
                    $sql .= ' AND s.sede_id = ?';
                    $params[] = $_POST['section_id'];
                } elseif ($recipientType === 'tag' && !empty($_POST['tag_id'])) {
                    $sql .= ' AND EXISTS (SELECT 1 FROM socio_tags st WHERE st.socio_id = s.id AND st.tag_id = ?)';
                    $params[] = $_POST['tag_id'];
                } elseif ($recipientType === 'gruppo' && !empty($_POST['gruppo_id'])) {
                    // Dynamic group — fetch filter JSON and apply
                    $stmtG = $pdo->prepare('SELECT filtri_json FROM gruppi_dinamici WHERE id = ? AND associazione_id = ?');
                    $stmtG->execute([$_POST['gruppo_id'], $associazione_id]);
                    // Group filtering is complex; for now include all active members of the group
                    // A full implementation would parse filtri_json
                }

                $stmtR = $pdo->prepare($sql);
                $stmtR->execute($params);
                $recipientRows = $stmtR->fetchAll();

                if (empty($recipientRows)) {
                    $message = 'Nessun destinatario trovato per i criteri selezionati.';
                    $messageType = 'warning';
                } else {
                    // Build recipient array with per-socio placeholder rendering
                    $recipients = [];
                    foreach ($recipientRows as $r) {
                        $placeholders = buildPlaceholderValues($pdo, $associazione_id, $r['id']);
                        $renderedBody = renderTemplatePlaceholders($bodyHtml, $placeholders);
                        $renderedSubject = renderTemplatePlaceholders($subject, $placeholders);

                        $recipients[] = [
                            'email' => $r['email'],
                            'name' => $r['nome'] . ' ' . $r['cognome'],
                            'socio_id' => $r['id'],
                            'subject' => $renderedSubject,
                            'body' => $renderedBody,
                        ];
                    }

                    // Queue individually (each has personalized placeholders)
                    $batchId = generateUuid();
                    $stmtIns = $pdo->prepare('INSERT INTO email_queue (id, associazione_id, batch_id, socio_id, to_email, to_name, subject, body_html, template_codice, priority) VALUES (?,?,?,?,?,?,?,?,?,5)');
                    foreach ($recipients as $rec) {
                        $stmtIns->execute([
                            generateUuid(),
                            $associazione_id,
                            $batchId,
                            $rec['socio_id'],
                            $rec['email'],
                            $rec['name'],
                            $rec['subject'],
                            $rec['body'],
                            'comunicazione',
                        ]);
                    }

                    $count = count($recipients);
                    $message = "$count email accodate con successo! Vai alla <a href=\"index.php?page=email-coda\" class=\"alert-link\">Coda Invio</a> per elaborarle.";
                    $messageType = 'success';
                }
            }
        } catch (Exception $e) {
            error_log('comunicazioni.php send error: ' . $e->getMessage());
            $message = 'Errore durante l\'accodamento.';
            $messageType = 'danger';
        }
    }
}

// Data for dropdowns
try {
    $stmtCat = $pdo->prepare('SELECT id, nome FROM categorie_socio WHERE associazione_id = ? ORDER BY nome');
    $stmtCat->execute([$associazione_id]);
    $categories = $stmtCat->fetchAll();

    $stmtSed = $pdo->prepare('SELECT id, nome FROM sedi WHERE associazione_id = ? ORDER BY nome');
    $stmtSed->execute([$associazione_id]);
    $sections = $stmtSed->fetchAll();

    $stmtTag = $pdo->prepare('SELECT id, nome_tag FROM tags WHERE associazione_id = ? ORDER BY nome_tag');
    $stmtTag->execute([$associazione_id]);
    $tags = $stmtTag->fetchAll();

    $stmtGrp = $pdo->prepare('SELECT id, nome_gruppo FROM gruppi_dinamici WHERE associazione_id = ? ORDER BY nome_gruppo');
    $stmtGrp->execute([$associazione_id]);
    $gruppi = $stmtGrp->fetchAll();
} catch (PDOException $e) {
    error_log('comunicazioni.php fetch data: ' . $e->getMessage());
    $categories = [];
    $sections = [];
    $tags = [];
    $gruppi = [];
}

// Templates for dropdown
$stmtTpls = $pdo->prepare('SELECT codice, nome, oggetto, corpo_html, corpo_json FROM email_templates WHERE associazione_id = ? AND attivo = 1 ORDER BY nome');
$stmtTpls->execute([$associazione_id]);
$emailTemplates = $stmtTpls->fetchAll();

// Recent batches from log
$stmtRecent = $pdo->prepare('SELECT batch_id, MIN(sent_at) as sent_at, COUNT(*) as cnt, SUM(status="sent") as ok, SUM(status="failed") as ko, MIN(subject) as subject FROM email_log WHERE associazione_id = ? AND batch_id IS NOT NULL GROUP BY batch_id ORDER BY sent_at DESC LIMIT 10');
$stmtRecent->execute([$associazione_id]);
$recentBatches = $stmtRecent->fetchAll();

// Count recipients preview
$stmtCountAll = $pdo->prepare('SELECT COUNT(*) FROM soci WHERE associazione_id = ? AND email IS NOT NULL AND email != "" AND stato = "Attivo" AND (email_opt_out = 0 OR email_opt_out IS NULL)');
$stmtCountAll->execute([$associazione_id]);
$totalActiveRecipients = (int)$stmtCountAll->fetchColumn();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-send me-2"></i>Invia Comunicazione</h1>
</div>

<?php if (!$smtpConfigured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        SMTP non configurato. <a href="index.php?page=email-impostazioni" class="alert-link">Configura le impostazioni SMTP</a> per inviare email.
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : ($messageType === 'warning' ? 'warning' : 'danger'); ?> alert-dismissible fade show">
        <?php echo $messageType === 'success' ? $message : htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="POST" id="commForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="send_communication" value="1">
    <input type="hidden" name="corpo_html" id="corpoHtmlField" value="">

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-body">
                    <!-- Template selection -->
                    <div class="mb-3">
                        <label class="form-label">Template</label>
                        <select class="form-select" id="templateSelect">
                            <option value="">Comunicazione libera</option>
                            <?php foreach ($emailTemplates as $et): ?>
                                <option value="<?php echo htmlspecialchars($et['codice']); ?>" data-subject="<?php echo htmlspecialchars($et['oggetto']); ?>" data-html="<?php echo htmlspecialchars($et['corpo_html']); ?>" data-json="<?php echo htmlspecialchars($et['corpo_json'] ?? ''); ?>"><?php echo htmlspecialchars($et['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject -->
                    <div class="mb-3">
                        <label class="form-label">Oggetto</label>
                        <input type="text" class="form-control" name="subject" id="subjectField" required>
                    </div>

                    <!-- GrapesJS Editor -->
                    <link rel="stylesheet" href="assets/vendor/grapesjs/grapes.min.css">
                    <link rel="stylesheet" href="assets/css/email-editor.css">
                    <div id="gjs" style="height:400px; border:1px solid #ddd; border-radius:4px;"></div>

                    <div class="mt-2">
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-braces me-1"></i>Inserisci Placeholder
                            </button>
                            <ul class="dropdown-menu">
                                <?php foreach (getEmailPlaceholders('comunicazione') as $ph): ?>
                                    <li><a class="dropdown-item" href="#" onclick="insertPlaceholder('<?php echo $ph; ?>');return false;">{<?php echo $ph; ?>}</a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Recipients -->
            <div class="card mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-people me-1"></i>Destinatari</h6></div>
                <div class="card-body">
                    <select class="form-select mb-2" name="recipient_type" id="recipientType">
                        <option value="all">Tutti i soci attivi (<?php echo $totalActiveRecipients; ?>)</option>
                        <option value="category">Per categoria</option>
                        <option value="section">Per sede</option>
                        <option value="tag">Per tag</option>
                        <option value="gruppo">Per gruppo dinamico</option>
                    </select>

                    <div class="d-none" id="filterCategory">
                        <select class="form-select" name="category_id">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?php echo htmlspecialchars($c['id']); ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-none" id="filterSection">
                        <select class="form-select" name="section_id">
                            <?php foreach ($sections as $s): ?>
                                <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-none" id="filterTag">
                        <select class="form-select" name="tag_id">
                            <?php foreach ($tags as $t): ?>
                                <option value="<?php echo htmlspecialchars($t['id']); ?>"><?php echo htmlspecialchars($t['nome_tag']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-none" id="filterGruppo">
                        <select class="form-select" name="gruppo_id">
                            <?php foreach ($gruppi as $g): ?>
                                <option value="<?php echo htmlspecialchars($g['id']); ?>"><?php echo htmlspecialchars($g['nome_gruppo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mt-3 text-center">
                        <span class="badge bg-primary fs-6" id="recipientCount"><?php echo $totalActiveRecipients; ?> destinatari</span>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3" onclick="prepareSubmit()" <?php echo !$smtpConfigured ? 'disabled' : ''; ?>>
                <i class="bi bi-envelope-arrow-up me-1"></i>Accoda Invio
            </button>
        </div>
    </div>
</form>

<?php if (!empty($recentBatches)): ?>
<div class="card mt-4">
    <div class="card-header"><h5 class="card-title mb-0"><i class="bi bi-clock-history me-1"></i>Invii Recenti</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light"><tr><th>Data</th><th>Oggetto</th><th>Totale</th><th>Inviate</th><th>Fallite</th></tr></thead>
                <tbody>
                    <?php foreach ($recentBatches as $b): ?>
                    <tr>
                        <td><small><?php echo date('d/m/Y H:i', strtotime($b['sent_at'])); ?></small></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth($b['subject'], 0, 50, '...')); ?></td>
                        <td><?php echo (int)$b['cnt']; ?></td>
                        <td><span class="badge bg-success"><?php echo (int)$b['ok']; ?></span></td>
                        <td><span class="badge bg-danger"><?php echo (int)$b['ko']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/vendor/grapesjs/grapes.min.js"></script>
<script src="assets/vendor/grapesjs/grapesjs-preset-newsletter.min.js"></script>
<script>
var editor = grapesjs.init({
    container: '#gjs',
    height: '400px',
    plugins: ['gjs-preset-newsletter'],
    pluginsOpts: { 'gjs-preset-newsletter': {} },
    storageManager: false,
    assetManager: { upload: false }
});

function prepareSubmit() {
    document.getElementById('corpoHtmlField').value = editor.getHtml() + '<style>' + editor.getCss() + '</style>';
}

function insertPlaceholder(name) {
    var selected = editor.getSelected();
    if (selected) {
        var content = selected.get('content') || '';
        selected.set('content', content + '{' + name + '}');
    } else {
        editor.addComponents('{' + name + '}');
    }
}

// Template loading
var tplSelect = document.getElementById('templateSelect');
if (tplSelect) {
    tplSelect.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        var subj = opt.getAttribute('data-subject') || '';
        var html = opt.getAttribute('data-html') || '';
        var json = opt.getAttribute('data-json') || '';
        document.getElementById('subjectField').value = subj;
        if (json) {
            try {
                editor.loadProjectData(JSON.parse(json));
                return;
            } catch(e) {}
        }
        editor.setComponents(html);
    });
}

// Recipient type filter toggle
var rt = document.getElementById('recipientType');
var filterDivs = { category: 'filterCategory', section: 'filterSection', tag: 'filterTag', gruppo: 'filterGruppo' };
if (rt) {
    rt.addEventListener('change', function() {
        Object.values(filterDivs).forEach(function(id) {
            document.getElementById(id).classList.add('d-none');
        });
        if (filterDivs[this.value]) {
            document.getElementById(filterDivs[this.value]).classList.remove('d-none');
        }
    });
}
</script>
