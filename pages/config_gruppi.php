<?php
// pages/config_gruppi.php - v2.0 (SaaS)

if (!isUserLoggedIn(['admin_associazione', 'super_admin'])) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}
$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        try {
            if (isset($_POST['delete_id'])) {
                $stmt = $pdo->prepare("DELETE FROM gruppi_dinamici WHERE id = ? AND associazione_id = ?");
                $stmt->execute([$_POST['delete_id'], $associazione_id]);
                $message = "Gruppo eliminato."; $messageType = "success";
            } else {
                $id = $_POST['id'] ?? null;
                $nome_gruppo = sanitizeInput($_POST['nome_gruppo'] ?? '');
                $descrizione = sanitizeInput($_POST['descrizione'] ?? '');

                $filtri = [];
                if (!empty($_POST['filtro_stato'])) $filtri['stato'] = sanitizeInput($_POST['filtro_stato']);
                if (!empty($_POST['filtro_citta'])) $filtri['citta'] = sanitizeInput($_POST['filtro_citta']);
                $filtri_json = json_encode($filtri);

                if ($id) {
                    $stmt = $pdo->prepare("UPDATE gruppi_dinamici SET nome_gruppo=?, descrizione=?, filtri_json=? WHERE id=? AND associazione_id=?");
                    $stmt->execute([$nome_gruppo, $descrizione, $filtri_json, $id, $associazione_id]);
                    $message = "Gruppo aggiornato."; $messageType = "success";
                } else {
                    $new_id = generateUuid();
                    $stmt = $pdo->prepare("INSERT INTO gruppi_dinamici (id, associazione_id, nome_gruppo, descrizione, filtri_json) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$new_id, $associazione_id, $nome_gruppo, $descrizione, $filtri_json]);
                    $message = "Gruppo creato."; $messageType = "success";
                }
            }
        } catch (PDOException $e) {
            error_log('config_gruppi.php PDOException: ' . $e->getMessage());
            $message = "Errore durante l'operazione. Riprova più tardi.";
            $messageType = "danger";
        }
    }
}

// Recupero Dati
$editingGroup = null;
$filtri_editing = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM gruppi_dinamici WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingGroup = $stmt->fetch();
    if($editingGroup) $filtri_editing = json_decode($editingGroup['filtri_json'], true);
}

$stmt_gruppi = $pdo->prepare("SELECT * FROM gruppi_dinamici WHERE associazione_id = ? ORDER BY nome_gruppo ASC");
$stmt_gruppi->execute([$associazione_id]);
$gruppi = $stmt_gruppi->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Gruppi Dinamici</h1>
</div>


<?php if ($message): ?><div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#groupModal"><i class="bi bi-plus-lg"></i> Nuovo Gruppo</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Nome Gruppo</th><th>Filtri Applicati</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($gruppi as $gruppo): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($gruppo['nome_gruppo']); ?></strong></td>
                    <td><small class="font-monospace"><?php echo htmlspecialchars($gruppo['filtri_json']); ?></small></td>
                    <td class="text-end">
                        <a href="index.php?page=config_gruppi&edit=<?php echo htmlspecialchars($gruppo['id'], ENT_QUOTES); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo gruppo?')"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($gruppo['id'], ENT_QUOTES); ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="groupModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingGroup ? 'Modifica' : 'Nuovo'; ?> Gruppo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($editingGroup['id'] ?? '', ENT_QUOTES); ?>">
            <div class="mb-3"><label>Nome Gruppo</label><input type="text" name="nome_gruppo" class="form-control" value="<?php echo htmlspecialchars($editingGroup['nome_gruppo'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control"><?php echo htmlspecialchars($editingGroup['descrizione'] ?? ''); ?></textarea></div>
            <hr><p class="text-muted">Imposta Filtri:</p>
            <div class="mb-3"><label>Stato Socio</label><select name="filtro_stato" class="form-select"><option value="">Qualsiasi</option><option value="Attivo" <?php echo ($filtri_editing['stato'] ?? '') == 'Attivo' ? 'selected' : ''; ?>>Attivo</option><option value="Sospeso" <?php echo ($filtri_editing['stato'] ?? '') == 'Sospeso' ? 'selected' : ''; ?>>Sospeso</option></select></div>
            <div class="mb-3"><label>Città di residenza</label><input type="text" name="filtro_citta" class="form-control" value="<?php echo htmlspecialchars($filtri_editing['citta'] ?? ''); ?>"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingGroup): ?><script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('groupModal')).show());</script><?php endif; ?>