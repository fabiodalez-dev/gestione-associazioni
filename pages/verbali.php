<?php
// pages/verbali.php - v2.0 (SaaS multi-tenant)

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } elseif (isset($_POST['delete_id'])) {
        // Delete verbale
        $deleteId = $_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM verbali WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$deleteId, $associazione_id]);
            $message = "Verbale eliminato con successo!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('verbali.php delete: ' . $e->getMessage());
            $message = "Errore durante l'eliminazione. Riprova.";
            $messageType = "error";
        }
    } elseif (isset($_POST['approve_id'])) {
        // Approve verbale
        $approveId = $_POST['approve_id'];
        try {
            $stmt = $pdo->prepare("UPDATE verbali SET approvato = 1, approvato_da = ?, data_approvazione = CURDATE() WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$_SESSION['user_id'], $approveId, $associazione_id]);
            $message = "Verbale approvato con successo!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('verbali.php approve: ' . $e->getMessage());
            $message = "Errore durante l'approvazione. Riprova.";
            $messageType = "error";
        }
    } else {
        // Add or update verbale
        $id = $_POST['id'] ?? null;
        $titolo = sanitizeInput($_POST['titolo']);
        $data_riunione = $_POST['data_riunione'];
        $tipo_riunione = $_POST['tipo_riunione'];
        $presenti = sanitizeInput($_POST['presenti']);
        $ordine_del_giorno = sanitizeInput($_POST['ordine_del_giorno']);
        $deliberazioni = sanitizeInput($_POST['deliberazioni']);

        try {
            if ($id) {
                // Update existing verbale
                $stmt = $pdo->prepare("UPDATE verbali SET titolo = ?, data_riunione = ?, tipo_riunione = ?, presenti = ?, ordine_del_giorno = ?, deliberazioni = ?, updated_at = NOW() WHERE id = ? AND associazione_id = ?");
                $stmt->execute([$titolo, $data_riunione, $tipo_riunione, $presenti, $ordine_del_giorno, $deliberazioni, $id, $associazione_id]);
                $message = "Verbale aggiornato con successo!";
            } else {
                // Insert new verbale
                $new_id = generateUuid();
                $stmt = $pdo->prepare("INSERT INTO verbali (id, associazione_id, titolo, data_riunione, tipo_riunione, presenti, ordine_del_giorno, deliberazioni, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$new_id, $associazione_id, $titolo, $data_riunione, $tipo_riunione, $presenti, $ordine_del_giorno, $deliberazioni]);
                $message = "Verbale aggiunto con successo!";
            }
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('verbali.php save: ' . $e->getMessage());
            $message = "Errore durante il salvataggio. Riprova.";
            $messageType = "error";
        }
    }
}

// Get verbali list
try {
    $searchTerm = $_GET['search'] ?? '';
    $typeFilter = $_GET['type'] ?? 'all';

    $sql = "SELECT v.*, CONCAT(u.nome, ' ', u.cognome) as approvato_username FROM verbali v
            LEFT JOIN utenti u ON v.approvato_da = u.id
            WHERE v.associazione_id = ?";
    $params = [$associazione_id];

    if ($searchTerm) {
        $sql .= " AND (v.titolo LIKE ? OR v.presenti LIKE ?)";
        $params = array_merge($params, ["%$searchTerm%", "%$searchTerm%"]);
    }

    if ($typeFilter !== 'all') {
        $sql .= " AND v.tipo_riunione = ?";
        $params[] = $typeFilter;
    }

    $sql .= " ORDER BY v.data_riunione DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $verbali = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('verbali.php fetch: ' . $e->getMessage());
    die("Errore nel recupero dati. Riprova più tardi.");
}

// Get verbale for editing
$editingVerbale = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM verbali WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_GET['edit'], $associazione_id]);
        $editingVerbale = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('verbali.php edit fetch: ' . $e->getMessage());
        $message = "Errore durante il caricamento del verbale. Riprova.";
        $messageType = "error";
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Verbali</h1>
    <p class="text-muted">Gestisci i verbali delle riunioni e assemblee</p>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#verbaleModal">
        <i class="bi bi-plus-lg"></i> Nuovo Verbale
    </button>
    <a href="index.php?page=verbali&action=export" class="btn btn-outline-secondary">
        <i class="bi bi-download"></i> Esporta PDF
    </a>
</div>

<!-- Search and Filter -->
<div class="row mb-3">
    <div class="col-md-6">
        <form method="GET">
            <input type="hidden" name="page" value="verbali">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Cerca per titolo o partecipanti..." name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
    <div class="col-md-6">
        <form method="GET">
            <input type="hidden" name="page" value="verbali">
            <select class="form-select" name="type" onchange="this.form.submit()">
                <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>Tutti i tipi</option>
                <option value="Assemblea Ordinaria" <?php echo $typeFilter === 'Assemblea Ordinaria' ? 'selected' : ''; ?>>Assemblea Ordinaria</option>
                <option value="Assemblea Straordinaria" <?php echo $typeFilter === 'Assemblea Straordinaria' ? 'selected' : ''; ?>>Assemblea Straordinaria</option>
                <option value="Consiglio Direttivo" <?php echo $typeFilter === 'Consiglio Direttivo' ? 'selected' : ''; ?>>Consiglio Direttivo</option>
                <option value="Commissione" <?php echo $typeFilter === 'Commissione' ? 'selected' : ''; ?>>Commissione</option>
            </select>
        </form>
    </div>
</div>

<!-- Verbali Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Elenco Verbali</h5>
        <p class="text-muted mb-0"><?php echo count($verbali); ?> verbali trovati</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Data Riunione</th>
                        <th>Tipo</th>
                        <th>Stato</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($verbali as $verbale): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($verbale['titolo']); ?></strong>
                                    <div class="text-muted small">
                                        Partecipanti: <?php echo htmlspecialchars(substr($verbale['presenti'], 0, 50)) . (strlen($verbale['presenti']) > 50 ? '...' : ''); ?>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo formatDate($verbale['data_riunione']); ?></td>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($verbale['tipo_riunione']); ?></span>
                            </td>
                            <td>
                                <?php if ($verbale['approvato']): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-check-circle"></i> Approvato
                                    </span>
                                    <div class="text-muted small">
                                        da <?php echo htmlspecialchars($verbale['approvato_username'] ?? 'N/D'); ?>
                                        il <?php echo $verbale['data_approvazione'] ? formatDate($verbale['data_approvazione']) : ''; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-warning">
                                        <i class="bi bi-clock"></i> In attesa
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $verbale['id']; ?>">
                                    <i class="bi bi-eye"></i> Visualizza
                                </button>
                                <a href="index.php?page=verbali&edit=<?php echo $verbale['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Modifica
                                </a>
                                <?php if (!$verbale['approvato']): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="approve_id" value="<?php echo $verbale['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Approvare questo verbale?')">
                                            <i class="bi bi-check"></i> Approva
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo verbale?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $verbale['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Elimina
                                    </button>
                                </form>
                            </td>
                        </tr>

                        <!-- View Modal for each verbale -->
                        <div class="modal fade" id="viewModal<?php echo $verbale['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><?php echo htmlspecialchars($verbale['titolo']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <strong>Data Riunione:</strong> <?php echo formatDate($verbale['data_riunione']); ?>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Tipo:</strong> <?php echo htmlspecialchars($verbale['tipo_riunione']); ?>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <strong>Partecipanti:</strong>
                                            <p class="mt-1"><?php echo nl2br(htmlspecialchars($verbale['presenti'])); ?></p>
                                        </div>

                                        <div class="mb-3">
                                            <strong>Ordine del Giorno:</strong>
                                            <p class="mt-1"><?php echo nl2br(htmlspecialchars($verbale['ordine_del_giorno'])); ?></p>
                                        </div>

                                        <div class="mb-3">
                                            <strong>Deliberazioni:</strong>
                                            <p class="mt-1"><?php echo nl2br(htmlspecialchars($verbale['deliberazioni'])); ?></p>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                                        <button type="button" class="btn btn-primary" onclick="window.print()">
                                            <i class="bi bi-printer"></i> Stampa
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Verbale Modal -->
<div class="modal fade" id="verbaleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $editingVerbale ? 'Modifica Verbale' : 'Nuovo Verbale'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" value="<?php echo $editingVerbale['id'] ?? ''; ?>">

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Titolo</label>
                            <input type="text" class="form-control" name="titolo" value="<?php echo htmlspecialchars($editingVerbale['titolo'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Data Riunione</label>
                            <input type="date" class="form-control" name="data_riunione" value="<?php echo htmlspecialchars($editingVerbale['data_riunione'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Tipo Riunione</label>
                        <select class="form-select" name="tipo_riunione" required>
                            <option value="">Seleziona tipo...</option>
                            <option value="Assemblea Ordinaria" <?php echo ($editingVerbale && $editingVerbale['tipo_riunione'] === 'Assemblea Ordinaria') ? 'selected' : ''; ?>>Assemblea Ordinaria</option>
                            <option value="Assemblea Straordinaria" <?php echo ($editingVerbale && $editingVerbale['tipo_riunione'] === 'Assemblea Straordinaria') ? 'selected' : ''; ?>>Assemblea Straordinaria</option>
                            <option value="Consiglio Direttivo" <?php echo ($editingVerbale && $editingVerbale['tipo_riunione'] === 'Consiglio Direttivo') ? 'selected' : ''; ?>>Consiglio Direttivo</option>
                            <option value="Commissione" <?php echo ($editingVerbale && $editingVerbale['tipo_riunione'] === 'Commissione') ? 'selected' : ''; ?>>Commissione</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Partecipanti</label>
                        <textarea class="form-control" name="presenti" rows="3" placeholder="Elenco dei partecipanti..."><?php echo htmlspecialchars($editingVerbale['presenti'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ordine del Giorno</label>
                        <textarea class="form-control" name="ordine_del_giorno" rows="4" placeholder="Punti all'ordine del giorno..."><?php echo htmlspecialchars($editingVerbale['ordine_del_giorno'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Deliberazioni</label>
                        <textarea class="form-control" name="deliberazioni" rows="4" placeholder="Deliberazioni e decisioni prese..."><?php echo htmlspecialchars($editingVerbale['deliberazioni'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?php echo $editingVerbale ? 'Aggiorna' : 'Salva'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editingVerbale): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var verbaleModal = new bootstrap.Modal(document.getElementById('verbaleModal'));
        verbaleModal.show();
    });
</script>
<?php endif; ?>
