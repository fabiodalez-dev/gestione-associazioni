<?php
// pages/tipi-socio.php - v2.0 (SaaS)

if (!isUserLoggedIn(['admin_associazione', 'super_admin'])) {
    redirect('auth/login.php');
}

// Assicura colonne costo tessera
ensureTesseraCostColumns($pdo);

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
    // Super admin: consenti assoc_id via POST per mantenere contesto
    if (($_SESSION['user_role'] ?? '') === 'super_admin' && isset($_POST['assoc_id'])) {
        $associazione_id = $_POST['assoc_id'];
    }
    $id = $_POST['id'] ?? null;
    $nome = cleanInput($_POST['nome'] ?? '');
    $descrizione = cleanInput($_POST['descrizione'] ?? '');
    $costo_tessera = isset($_POST['costo_tessera']) && $_POST['costo_tessera'] !== ''
        ? number_format((float)$_POST['costo_tessera'], 2, '.', '')
        : null;

    if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM tipi_socio WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        // JS redirect to avoid header already sent
        $redir = 'index.php?page=tipi-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
        echo '<script>window.location.href='.json_encode($redir).';</script>';
        exit;
    } elseif ($id) {
        $stmt = $pdo->prepare("UPDATE tipi_socio SET nome=?, descrizione=?, costo_tessera=? WHERE id=? AND associazione_id=?");
        $stmt->execute([$nome, $descrizione, $costo_tessera, $id, $associazione_id]);
        $redir = 'index.php?page=tipi-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
        echo '<script>window.location.href='.json_encode($redir).';</script>';
        exit;
    } else {
        $new_id = generateUuid();
        $stmt = $pdo->prepare("INSERT INTO tipi_socio (id, associazione_id, nome, descrizione, costo_tessera) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$new_id, $associazione_id, $nome, $descrizione, $costo_tessera]);
        $redir = 'index.php?page=tipi-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
        echo '<script>window.location.href='.json_encode($redir).';</script>';
        exit;
    }
}

// Recupero Dati (solo se abbiamo un associazione_id)
$editingType = null;
$tipi_socio = [];

if ($associazione_id) {
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM tipi_socio WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_GET['edit'], $associazione_id]);
        $editingType = $stmt->fetch();
    }

    $stmt_tipi = $pdo->prepare("SELECT * FROM tipi_socio WHERE associazione_id = ? ORDER BY nome ASC");
    $stmt_tipi->execute([$associazione_id]);
    $tipi_socio = $stmt_tipi->fetchAll();
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Tipi di Socio</h1>
    <p class="text-muted">Gestisci i diversi tipi di socio per la tua associazione</p>
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
                <p>Seleziona l'associazione per configurare i tipi socio:</p>
                <div class="row">
                    <?php foreach ($associazioni as $assoc): ?>
                        <div class="col-md-6 mb-3">
                            <div class="card border">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($assoc['nome']); ?></h6>
                                    <a href="?page=tipi-socio&assoc_id=<?php echo urlencode($assoc['id']); ?>" 
                                       class="btn btn-primary">Configura Tipi</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php return; // Stop here if no association selected ?>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#typeModal"><i class="bi bi-plus-lg"></i> Nuovo Tipo Socio</button>
    <?php if ($_SESSION['user_role'] === 'super_admin' && $associazione_id): ?>
        <a href="?page=tipi-socio" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Cambia Associazione
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Nome</th><th>Descrizione</th><th>Costo Tessera</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($tipi_socio as $tipo): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($tipo['nome']); ?></strong></td>
                    <td>
                        <?php 
                        $desc = $tipo['descrizione'] ?? '';
                        for ($i=0; $i<3; $i++) { $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                        echo htmlspecialchars($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        ?>
                    </td>
                    <td><?php echo isset($tipo['costo_tessera']) ? '€ ' . number_format((float)$tipo['costo_tessera'], 2, ',', '.') : '<span class="text-muted">—</span>'; ?></td>
                    <td class="text-end">
                        <a href="index.php?page=tipi-socio&assoc_id=<?php echo urlencode($associazione_id); ?>&edit=<?php echo $tipo['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo tipo di socio?')">
                            <input type="hidden" name="assoc_id" value="<?php echo htmlspecialchars($associazione_id); ?>">
                            <input type="hidden" name="delete_id" value="<?php echo $tipo['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="typeModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingType ? 'Modifica' : 'Nuovo'; ?> Tipo Socio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <div class="modal-body">
            <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                <input type="hidden" name="assoc_id" value="<?php echo htmlspecialchars($associazione_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="id" value="<?php echo $editingType['id'] ?? ''; ?>">
            <div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($editingType['nome'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control" rows="3"><?php 
                $ed = $editingType['descrizione'] ?? '';
                for ($i=0; $i<3; $i++) { $ed = html_entity_decode($ed, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                echo htmlspecialchars($ed, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ?></textarea></div>
            <div class="mb-3">
                <label>Costo Tessera (override per tipo)</label>
                <div class="input-group">
                    <span class="input-group-text">€</span>
                    <input type="number" step="0.01" min="0" name="costo_tessera" class="form-control" value="<?php echo htmlspecialchars($editingType['costo_tessera'] ?? ''); ?>" placeholder="es. 25.00">
                </div>
                <div class="form-text">Se vuoto, verrà usato il costo predefinito dell'associazione.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingType): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('typeModal')).show());</script>
<?php endif; ?>
