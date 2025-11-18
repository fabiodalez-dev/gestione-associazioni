<?php
// pages/config_gruppi.php - v2.0 (SaaS)

if (!isUserLoggedIn(['admin_associazione', 'super_admin'])) {
    redirect('auth/login.php');
}

// Per super_admin, permetti selezione associazione
if ($_SESSION['user_role'] === 'super_admin') {
    $associazione_id = $_GET['assoc_id'] ?? null;
    if (!$associazione_id) {
        // Mostra selezione associazione
        $stmt = $pdo->query("SELECT id, nome FROM associazioni WHERE attiva = 1 ORDER BY nome");
        $associazioni = $stmt->fetchAll();
        
        if (empty($associazioni)) {
            $error = "Nessuna associazione trovata. Crea prima un'associazione.";
        }
    }
} else {
    // Per altri ruoli, usa l'associazione dalla sessione
    if (!isset($_SESSION['associazione_id'])) {
        redirect('auth/login.php');
    }
    $associazione_id = $_SESSION['associazione_id'];
}
$message = '';
$messageType = '';

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
        $messageType = 'danger';
    } else {
    $id = $_POST['id'] ?? null;
    $nome_gruppo = sanitizeInput($_POST['nome_gruppo']);
    $descrizione = sanitizeInput($_POST['descrizione']);
    
    $filtri = [];
    if (!empty($_POST['filtro_stato'])) $filtri['stato'] = sanitizeInput($_POST['filtro_stato']);
    if (!empty($_POST['filtro_citta'])) $filtri['citta'] = sanitizeInput($_POST['filtro_citta']);
    $filtri_json = json_encode($filtri);

    if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM gruppi_dinamici WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        $message = "Gruppo eliminato."; $messageType = "success";
    } elseif ($id) {
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

<?php if ($_SESSION['user_role'] === 'super_admin' && !$associazione_id): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-building"></i> Seleziona Associazione</h5>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-warning"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if (!empty($associazioni)): ?>
                <p>Seleziona l'associazione per configurare i gruppi dinamici:</p>
                <div class="row">
                    <?php foreach ($associazioni as $assoc): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($assoc['nome']); ?></h6>
                                    <a href="?page=config_gruppi&assoc_id=<?php echo urlencode($assoc['id']); ?>" 
                                       class="btn btn-primary">Configura Gruppi</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div><?php endif; ?>

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
                        <a href="index.php?page=config_gruppi&edit=<?php echo $gruppo['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $gruppo['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
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
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingGroup['id'] ?? ''; ?>">
            <div class="mb-3"><label>Nome Gruppo</label><input type="text" name="nome_gruppo" class="form-control" value="<?php echo htmlspecialchars($editingGroup['nome_gruppo'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control"><?php echo htmlspecialchars($editingGroup['descrizione'] ?? ''); ?></textarea></div>
            <hr><p class="text-muted">Imposta Filtri:</p>
            <div class="mb-3"><label>Stato Socio</label><select name="filtro_stato" class="form-select"><option value="">Qualsiasi</option><option value="Attivo" <?php echo ($filtri_editing['stato'] ?? '') == 'Attivo' ? 'selected' : ''; ?>>Attivo</option><option value="Sospeso">Sospeso</option></select></div>
            <div class="mb-3"><label>Città di residenza</label><input type="text" name="filtro_citta" class="form-control" value="<?php echo htmlspecialchars($filtri_editing['citta'] ?? ''); ?>"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingGroup): ?><script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('groupModal')).show());</script><?php endif; ?>

<?php endif; ?>