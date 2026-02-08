<?php
// pages/comunicazioni.php - multitenant safe

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}

$is_super_admin = ($_SESSION['user_role'] ?? '') === 'super_admin';
$associazione_id = $_SESSION['associazione_id'];

// Handle form submission for sending communication
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_communication'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $recipient_type = $_POST['recipient_type'] ?? 'all';

        try {
            // Build base query scoped by associazione
            $recipients = [];
            if ($recipient_type === 'all') {
                $stmt = $pdo->prepare("SELECT email, nome, cognome FROM soci WHERE associazione_id = ? AND email IS NOT NULL AND email != '' AND stato = 'Attivo'");
                $stmt->execute([$associazione_id]);
                $recipients = $stmt->fetchAll();
            } elseif ($recipient_type === 'category') {
                $category_id = $_POST['category_id'] ?? null;
                if ($category_id) {
                    $stmt = $pdo->prepare("SELECT email, nome, cognome FROM soci WHERE associazione_id = ? AND email IS NOT NULL AND email != '' AND categoria_socio_id = ? AND stato = 'Attivo'");
                    $stmt->execute([$associazione_id, $category_id]);
                    $recipients = $stmt->fetchAll();
                }
            } elseif ($recipient_type === 'section') {
                $section_id = $_POST['section_id'] ?? null;
                if ($section_id) {
                    $stmt = $pdo->prepare("SELECT email, nome, cognome FROM soci WHERE associazione_id = ? AND email IS NOT NULL AND email != '' AND sede_id = ? AND stato = 'Attivo'");
                    $stmt->execute([$associazione_id, $section_id]);
                    $recipients = $stmt->fetchAll();
                }
            }

            // Simulazione invio
            $sent_count = count($recipients);
            $message = "Comunicazione inviata con successo a $sent_count destinatari!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('comunicazioni.php send PDOException: ' . $e->getMessage());
            $message = "Errore durante l'invio. Riprova più tardi.";
            $messageType = "error";
        }
    }
}

// Get categories and sections for dropdowns scoped by association (or empty if none)
try {
    $stmt = $pdo->prepare("SELECT id, nome FROM categorie_socio WHERE associazione_id = ? ORDER BY nome");
    $stmt->execute([$associazione_id]);
    $categories = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, nome FROM sedi WHERE associazione_id = ? ORDER BY nome");
    $stmt->execute([$associazione_id]);
    $sections = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('comunicazioni.php fetch PDOException: ' . $e->getMessage());
    die("Errore nel recupero dati. Riprova più tardi.");
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Comunicazioni</h1>
    <p class="text-muted">Gestisci le comunicazioni verso i soci</p>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Invia Nuova Comunicazione</h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="send_communication" value="1">
            <div class="mb-3">
                <label class="form-label">Oggetto</label>
                <input type="text" class="form-control" name="subject" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Messaggio</label>
                <textarea class="form-control" name="message" rows="6" required></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Destinatari</label>
                <select class="form-select" name="recipient_type" id="recipient_type" required>
                    <option value="all">Tutti i soci attivi</option>
                    <option value="category">Per categoria socio</option>
                    <option value="section">Per sezione</option>
                </select>
            </div>
            <div class="mb-3 d-none" id="category_select">
                <label class="form-label">Categoria</label>
                <select class="form-select" name="category_id">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3 d-none" id="section_select">
                <label class="form-label">Sezione</label>
                <select class="form-select" name="section_id">
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo $sec['id']; ?>"><?php echo htmlspecialchars($sec['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i> Invia Comunicazione
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Storico Comunicazioni</h5>
    </div>
    <div class="card-body">
        <p>Questa sezione mostrerà lo storico delle comunicazioni inviate.</p>
        <p>In una versione futura, qui verranno visualizzate tutte le comunicazioni inviate con data, destinatari e contenuto.</p>
    </div>
</div>

<script>
    var recipientType = document.getElementById('recipient_type');
    var categorySelect = document.getElementById('category_select');
    var sectionSelect = document.getElementById('section_select');

    if (recipientType && categorySelect && sectionSelect) {
        recipientType.addEventListener('change', function() {
            categorySelect.classList.add('d-none');
            sectionSelect.classList.add('d-none');

            if (this.value === 'category') {
                categorySelect.classList.remove('d-none');
            } else if (this.value === 'section') {
                sectionSelect.classList.remove('d-none');
            }
        });
    }
</script>
