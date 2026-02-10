<?php
// pages/email_templates.php — Template editor with GrapesJS
// Full implementation in Phase 5

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

// Seed defaults if no templates exist
$stmtCnt = $pdo->prepare('SELECT COUNT(*) FROM email_templates WHERE associazione_id = ?');
$stmtCnt->execute([$associazione_id]);
if ((int)$stmtCnt->fetchColumn() === 0) {
    $emailService = new EmailService($pdo, $associazione_id);
    $emailService->seedDefaultTemplates();
}

// Load all templates
$stmtAll = $pdo->prepare('SELECT id, codice, nome, oggetto, corpo_html, corpo_json, attivo FROM email_templates WHERE associazione_id = ? ORDER BY FIELD(codice, "benvenuto","scadenza_tessera","scadenza_quota","assemblea","comunicazione","rinnovo_tessera","pagamento_quota")');
$stmtAll->execute([$associazione_id]);
$templates = $stmtAll->fetchAll();

// Current template to edit
$currentCodice = $_GET['t'] ?? ($templates[0]['codice'] ?? 'benvenuto');
$currentTpl = null;
foreach ($templates as $t) {
    if ($t['codice'] === $currentCodice) {
        $currentTpl = $t;
        break;
    }
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza: token CSRF non valido.';
        $messageType = 'danger';
    } else {
        try {
            $codice = cleanInput($_POST['codice'] ?? '');
            $oggetto = cleanInput($_POST['oggetto'] ?? '');
            $corpoHtml = $_POST['corpo_html'] ?? '';
            $corpoJson = $_POST['corpo_json'] ?? '';
            $attivo = isset($_POST['attivo']) ? 1 : 0;

            $stmtUpd = $pdo->prepare('UPDATE email_templates SET oggetto=?, corpo_html=?, corpo_json=?, attivo=?, updated_at=NOW() WHERE associazione_id=? AND codice=?');
            $stmtUpd->execute([$oggetto, $corpoHtml, $corpoJson, $attivo, $associazione_id, $codice]);
            $message = 'Template salvato con successo.';
            $messageType = 'success';

            // Reload
            $stmtAll->execute([$associazione_id]);
            $templates = $stmtAll->fetchAll();
            foreach ($templates as $t) {
                if ($t['codice'] === $codice) {
                    $currentTpl = $t;
                    $currentCodice = $codice;
                    break;
                }
            }
        } catch (Exception $e) {
            error_log('email_templates.php save error: ' . $e->getMessage());
            $message = 'Errore durante il salvataggio.';
            $messageType = 'danger';
        }
    }
}

$placeholders = getEmailPlaceholders($currentCodice);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-palette me-2"></i>Template Email</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Template tabs -->
<ul class="nav nav-tabs mb-3">
    <?php foreach ($templates as $t): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $t['codice'] === $currentCodice ? 'active' : ''; ?>" href="index.php?page=email-templates&t=<?php echo urlencode($t['codice']); ?>">
                <?php echo htmlspecialchars($t['nome']); ?>
                <?php if (!$t['attivo']): ?><span class="badge bg-secondary ms-1">Off</span><?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($currentTpl): ?>
<form method="POST" id="templateForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="save_template" value="1">
    <input type="hidden" name="codice" value="<?php echo htmlspecialchars($currentCodice); ?>">
    <input type="hidden" name="corpo_html" id="corpoHtmlField" value="">
    <input type="hidden" name="corpo_json" id="corpoJsonField" value="">

    <div class="row mb-3">
        <div class="col-md-8">
            <label class="form-label">Oggetto</label>
            <input type="text" class="form-control" name="oggetto" value="<?php echo htmlspecialchars($currentTpl['oggetto'] ?? ''); ?>">
            <small class="text-muted">Placeholder disponibili: <?php echo implode(', ', array_map(function($p){ return '{' . $p . '}'; }, $placeholders)); ?></small>
        </div>
        <div class="col-md-4 d-flex align-items-end gap-2">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="attivo" id="tplAttivo" <?php echo $currentTpl['attivo'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="tplAttivo">Attivo</label>
            </div>
        </div>
    </div>

    <!-- GrapesJS Editor -->
    <link rel="stylesheet" href="assets/vendor/grapesjs/grapes.min.css">
    <link rel="stylesheet" href="assets/css/email-editor.css">
    <div id="gjs" style="height:500px; border:1px solid #ddd; border-radius:4px;"></div>

    <div class="mt-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary" onclick="prepareSubmit()"><i class="bi bi-check-lg me-1"></i>Salva Template</button>
        <button type="button" class="btn btn-outline-secondary" onclick="previewTemplate()"><i class="bi bi-eye me-1"></i>Anteprima</button>
        <div class="ms-auto">
            <div class="dropdown d-inline-block">
                <button class="btn btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-braces me-1"></i>Inserisci Placeholder
                </button>
                <ul class="dropdown-menu">
                    <?php foreach ($placeholders as $ph): ?>
                        <li><a class="dropdown-item" href="#" onclick="insertPlaceholder('<?php echo $ph; ?>');return false;">{<?php echo $ph; ?>}</a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</form>

<!-- Preview modal -->
<div class="modal fade" id="previewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Anteprima Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-0"><iframe id="previewFrame" style="width:100%;height:500px;border:none;" sandbox="allow-same-origin"></iframe></div>
</div></div></div>

<script src="assets/vendor/grapesjs/grapes.min.js"></script>
<script src="assets/vendor/grapesjs/grapesjs-preset-newsletter.min.js"></script>
<script>
var editor = grapesjs.init({
    container: '#gjs',
    height: '500px',
    plugins: ['gjs-preset-newsletter'],
    pluginsOpts: {
        'gjs-preset-newsletter': {
            modalTitleImport: 'Importa HTML',
            modalTitleExport: 'Esporta HTML',
        }
    },
    storageManager: false,
    assetManager: {
        upload: false,
        uploadName: 'files',
    }
});

// Load existing template
var existingJson = <?php echo json_encode($currentTpl['corpo_json'] ?? ''); ?>;
var existingHtml = <?php echo json_encode($currentTpl['corpo_html'] ?? ''); ?>;
if (existingJson) {
    try {
        var projectData = JSON.parse(existingJson);
        editor.loadProjectData(projectData);
    } catch(e) {
        if (existingHtml) {
            editor.setComponents(existingHtml);
        }
    }
} else if (existingHtml) {
    editor.setComponents(existingHtml);
}

function prepareSubmit() {
    document.getElementById('corpoHtmlField').value = editor.getHtml() + '<style>' + editor.getCss() + '</style>';
    document.getElementById('corpoJsonField').value = JSON.stringify(editor.getProjectData());
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

function previewTemplate() {
    var html = editor.getHtml() + '<style>' + editor.getCss() + '</style>';
    var frame = document.getElementById('previewFrame');
    var doc = frame.contentDocument || frame.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>
<?php endif; ?>
