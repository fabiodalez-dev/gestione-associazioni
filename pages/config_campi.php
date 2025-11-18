<?php
// pages/config_campi.php - v2.0 (SaaS)

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
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
    if (isset($_POST['action']) && $_POST['action'] === 'add_preset' && isset($_POST['preset'])) {
        // Gestione preset campi
        $preset_fields = [
            'professione' => ['nome' => 'Professione', 'tipo' => 'text', 'desc' => 'Professione del socio'],
            'titolo_studio' => ['nome' => 'Titolo di Studio', 'tipo' => 'select', 'desc' => 'Titolo di studio conseguito', 'opzioni' => "Licenza Media\nDiploma\nLaurea Triennale\nLaurea Magistrale\nMaster\nDottorato"],
            'data_diploma' => ['nome' => 'Data Diploma/Laurea', 'tipo' => 'date', 'desc' => 'Data conseguimento titolo'],
            'emergency_contact' => ['nome' => 'Contatto di Emergenza', 'tipo' => 'text', 'desc' => 'Nome e telefono del contatto di emergenza'],
            'specializzazione' => ['nome' => 'Specializzazione', 'tipo' => 'text', 'desc' => 'Area di specializzazione professionale'],
            'sito_web' => ['nome' => 'Sito Web', 'tipo' => 'url', 'desc' => 'Sito web personale o aziendale']
        ];
        
        $added_count = 0;
        foreach ($_POST['preset'] as $preset_key) {
            if (isset($preset_fields[$preset_key])) {
                $field = $preset_fields[$preset_key];
                $new_id = generateUuid();
                try {
                    $stmt = $pdo->prepare("INSERT INTO campi_personalizzati (id, associazione_id, nome_campo, tipo_campo, descrizione, opzioni, obbligatorio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$new_id, $associazione_id, $field['nome'], $field['tipo'], $field['desc'], $field['opzioni'] ?? '', 0]);
                    $added_count++;
                } catch (PDOException $e) {
                    // Campo già esistente, ignora
                }
            }
        }
        $message = "$added_count campi aggiunti con successo.";
        $messageType = "success";
    } else {
        $id = $_POST['id'] ?? null;
        $nome_campo = sanitizeInput($_POST['nome_campo']);
        $tipo_campo = sanitizeInput($_POST['tipo_campo']);
        $descrizione = sanitizeInput($_POST['descrizione'] ?? '');
        $opzioni = sanitizeInput($_POST['opzioni'] ?? '');
        $obbligatorio = isset($_POST['obbligatorio']) ? 1 : 0;

        if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM campi_personalizzati WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        $message = "Campo eliminato con successo.";
        $messageType = "success";
    } elseif ($id) {
        $stmt = $pdo->prepare("UPDATE campi_personalizzati SET nome_campo=?, tipo_campo=?, descrizione=?, opzioni=?, obbligatorio=? WHERE id=? AND associazione_id=?");
        $stmt->execute([$nome_campo, $tipo_campo, $descrizione, $opzioni, $obbligatorio, $id, $associazione_id]);
        $message = "Campo aggiornato con successo.";
        $messageType = "success";
    } else {
        $new_id = generateUuid();
        $stmt = $pdo->prepare("INSERT INTO campi_personalizzati (id, associazione_id, nome_campo, tipo_campo, descrizione, opzioni, obbligatorio) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$new_id, $associazione_id, $nome_campo, $tipo_campo, $descrizione, $opzioni, $obbligatorio]);
        $message = "Campo creato con successo.";
        $messageType = "success";
        }
    }
    }
}

// Recupero Dati
$editingField = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM campi_personalizzati WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingField = $stmt->fetch();
}

$stmt_campi = $pdo->prepare("SELECT * FROM campi_personalizzati WHERE associazione_id = ? ORDER BY nome_campo ASC");
$stmt_campi->execute([$associazione_id]);
$campi = $stmt_campi->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Gestione Campi Personalizzati</h1>
        <p class="text-muted">Aggiungi campi extra all'anagrafica dei soci</p>
    </div>
    <div class="btn-toolbar" role="toolbar">
        <div class="btn-group me-2">
            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#presetModal"><i class="bi bi-collection"></i> Preset</button>
        </div>
    </div>
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
                <p>Seleziona l'associazione per configurare i campi personalizzati:</p>
                <div class="row">
                    <?php foreach ($associazioni as $assoc): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($assoc['nome']); ?></h6>
                                    <a href="?page=config_campi&assoc_id=<?php echo urlencode($assoc['id']); ?>" 
                                       class="btn btn-primary">Configura Campi</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fieldModal"><i class="bi bi-plus-lg"></i> Nuovo Campo</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Nome Campo</th><th>Tipo</th><th>Descrizione</th><th>Obbligatorio</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($campi as $campo): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($campo['nome_campo']); ?></strong></td>
                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($campo['tipo_campo']); ?></span></td>
                    <td><small class="text-muted"><?php echo htmlspecialchars($campo['descrizione'] ?? ''); ?></small></td>
                    <td><?php echo $campo['obbligatorio'] ? '<span class="badge bg-success">Sì</span>' : '<span class="badge bg-light text-dark">No</span>'; ?></td>
                    <td class="text-end">
                        <a href="index.php?page=config_campi&edit=<?php echo $campo['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo campo? Verranno persi tutti i dati associati.')">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $campo['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="fieldModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingField ? 'Modifica' : 'Nuovo'; ?> Campo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingField['id'] ?? ''; ?>">
            <div class="mb-3"><label>Nome Campo</label><input type="text" name="nome_campo" class="form-control" value="<?php echo htmlspecialchars($editingField['nome_campo'] ?? ''); ?>" required></div>
            <div class="mb-3">
                <label>Tipo Campo</label>
                <select name="tipo_campo" class="form-select" required>
                    <option value="">-- Seleziona Tipo --</option>
                    <option value="text" <?php echo ($editingField['tipo_campo'] ?? '') == 'text' ? 'selected' : ''; ?>>Testo</option>
                    <option value="email" <?php echo ($editingField['tipo_campo'] ?? '') == 'email' ? 'selected' : ''; ?>>Email</option>
                    <option value="tel" <?php echo ($editingField['tipo_campo'] ?? '') == 'tel' ? 'selected' : ''; ?>>Telefono</option>
                    <option value="number" <?php echo ($editingField['tipo_campo'] ?? '') == 'number' ? 'selected' : ''; ?>>Numero</option>
                    <option value="date" <?php echo ($editingField['tipo_campo'] ?? '') == 'date' ? 'selected' : ''; ?>>Data</option>
                    <option value="url" <?php echo ($editingField['tipo_campo'] ?? '') == 'url' ? 'selected' : ''; ?>>URL</option>
                    <option value="textarea" <?php echo ($editingField['tipo_campo'] ?? '') == 'textarea' ? 'selected' : ''; ?>>Testo Lungo</option>
                    <option value="select" <?php echo ($editingField['tipo_campo'] ?? '') == 'select' ? 'selected' : ''; ?>>Selezione</option>
                    <option value="checkbox" <?php echo ($editingField['tipo_campo'] ?? '') == 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                </select>
            </div>
            <div id="selectOptions" class="mb-3" style="display: <?php echo ($editingField['tipo_campo'] ?? '') == 'select' ? 'block' : 'none'; ?>;">
                <label>Opzioni (una per riga)</label>
                <textarea name="opzioni" class="form-control" rows="4" placeholder="Opzione 1&#10;Opzione 2&#10;Opzione 3"><?php echo htmlspecialchars($editingField['opzioni'] ?? ''); ?></textarea>
            </div>
            <div class="mb-3">
                <label>Descrizione/Aiuto</label>
                <input type="text" name="descrizione" class="form-control" value="<?php echo htmlspecialchars($editingField['descrizione'] ?? ''); ?>" placeholder="Testo di aiuto per il campo">
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="obbligatorio" id="obbligatorio" value="1" <?php echo ($editingField['obbligatorio'] ?? 0) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="obbligatorio">Campo Obbligatorio</label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</div></div>
</div>

<!-- Modal Preset Campi -->
<div class="modal fade" id="presetModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Campi Predefiniti</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <p>Seleziona i campi che vuoi aggiungere all'anagrafica:</p>
        <form method="POST" id="presetForm">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="add_preset">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="professione" id="preset_professione">
                        <label class="form-check-label" for="preset_professione">Professione</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="titolo_studio" id="preset_titolo">
                        <label class="form-check-label" for="preset_titolo">Titolo di Studio</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="data_diploma" id="preset_diploma">
                        <label class="form-check-label" for="preset_diploma">Data Diploma/Laurea</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="emergency_contact" id="preset_emergency">
                        <label class="form-check-label" for="preset_emergency">Contatto di Emergenza</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="specializzazione" id="preset_spec">
                        <label class="form-check-label" for="preset_spec">Specializzazione</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="preset[]" value="sito_web" id="preset_web">
                        <label class="form-check-label" for="preset_web">Sito Web</label>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="submit" form="presetForm" class="btn btn-primary">Aggiungi Campi Selezionati</button>
    </div>
</div></div>
</div>

<script>
// Gestione dynamic form per tipo select
document.addEventListener('DOMContentLoaded', function() {
    const tipoSelect = document.querySelector('select[name="tipo_campo"]');
    const selectOptions = document.getElementById('selectOptions');
    
    if (tipoSelect && selectOptions) {
        tipoSelect.addEventListener('change', function() {
            if (this.value === 'select') {
                selectOptions.style.display = 'block';
            } else {
                selectOptions.style.display = 'none';
            }
        });
    }
});
</script>

<?php if ($editingField): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('fieldModal')).show());</script>
<?php endif; ?>

<?php endif; ?>