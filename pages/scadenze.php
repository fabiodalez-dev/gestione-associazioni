<?php
// pages/scadenze.php
include 'config.php';

// Create scadenze table if not exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS scadenze (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titolo VARCHAR(255) NOT NULL,
        descrizione TEXT,
        data_scadenza DATE NOT NULL,
        tipo_scadenza ENUM('Quote', 'Documenti', 'Eventi', 'Generale') NOT NULL,
        priorita ENUM('Bassa', 'Media', 'Alta', 'Critica') DEFAULT 'Media',
        stato ENUM('Attiva', 'Completata', 'Annullata') DEFAULT 'Attiva',
        assegnato_a INT,
        promemoria_giorni INT DEFAULT 7,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Table might already exist
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
        $messageType = 'danger';
    } else {
    if (isset($_POST['delete_id'])) {
        // Delete scadenza
        $deleteId = $_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM scadenze WHERE id = ?");
            $stmt->execute([$deleteId]);
            $message = "Scadenza eliminata con successo!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Errore durante l'eliminazione: " . $e->getMessage();
            $messageType = "error";
        }
    } elseif (isset($_POST['complete_id'])) {
        // Complete scadenza
        $completeId = $_POST['complete_id'];
        try {
            $stmt = $pdo->prepare("UPDATE scadenze SET stato = 'Completata', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$completeId]);
            $message = "Scadenza marcata come completata!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Errore durante l'aggiornamento: " . $e->getMessage();
            $messageType = "error";
        }
    } else {
        // Add or update scadenza
        $id = $_POST['id'] ?? null;
        $titolo = sanitizeInput($_POST['titolo']);
        $descrizione = sanitizeInput($_POST['descrizione']);
        $data_scadenza = $_POST['data_scadenza'];
        $tipo_scadenza = $_POST['tipo_scadenza'];
        $priorita = $_POST['priorita'];
        $stato = $_POST['stato'];
        $assegnato_a = $_POST['assegnato_a'] ?: null;
        $promemoria_giorni = $_POST['promemoria_giorni'];
        
        try {
            if ($id) {
                // Update existing scadenza
                $stmt = $pdo->prepare("UPDATE scadenze SET titolo = ?, descrizione = ?, data_scadenza = ?, tipo_scadenza = ?, priorita = ?, stato = ?, assegnato_a = ?, promemoria_giorni = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$titolo, $descrizione, $data_scadenza, $tipo_scadenza, $priorita, $stato, $assegnato_a, $promemoria_giorni, $id]);
                $message = "Scadenza aggiornata con successo!";
            } else {
                // Insert new scadenza
                $stmt = $pdo->prepare("INSERT INTO scadenze (titolo, descrizione, data_scadenza, tipo_scadenza, priorita, stato, assegnato_a, promemoria_giorni, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([$titolo, $descrizione, $data_scadenza, $tipo_scadenza, $priorita, $stato, $assegnato_a, $promemoria_giorni]);
                $message = "Scadenza aggiunta con successo!";
            }
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Errore durante il salvataggio: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Get scadenze list
try {
    $searchTerm = $_GET['search'] ?? '';
    $statusFilter = $_GET['status'] ?? 'all';
    $typeFilter = $_GET['type'] ?? 'all';
    $priorityFilter = $_GET['priority'] ?? 'all';
    
    $sql = "SELECT s.*, u.username as assegnato_username 
            FROM scadenze s 
            LEFT JOIN users u ON s.assegnato_a = u.id 
            WHERE 1=1";
    $params = [];
    
    if ($searchTerm) {
        $sql .= " AND (s.titolo LIKE ? OR s.descrizione LIKE ?)";
        $params = array_merge($params, ["%$searchTerm%", "%$searchTerm%"]);
    }
    
    if ($statusFilter !== 'all') {
        $sql .= " AND s.stato = ?";
        $params[] = $statusFilter;
    }
    
    if ($typeFilter !== 'all') {
        $sql .= " AND s.tipo_scadenza = ?";
        $params[] = $typeFilter;
    }
    
    if ($priorityFilter !== 'all') {
        $sql .= " AND s.priorita = ?";
        $params[] = $priorityFilter;
    }
    
    $sql .= " ORDER BY 
                CASE s.stato 
                    WHEN 'Attiva' THEN 1 
                    WHEN 'Annullata' THEN 2 
                    WHEN 'Completata' THEN 3 
                END,
                CASE s.priorita 
                    WHEN 'Critica' THEN 1 
                    WHEN 'Alta' THEN 2 
                    WHEN 'Media' THEN 3 
                    WHEN 'Bassa' THEN 4 
                END,
                s.data_scadenza ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scadenze = $stmt->fetchAll();
    
    // Get users for assignment dropdown
    $stmt = $pdo->query("SELECT id, username, email FROM users ORDER BY username");
    $users = $stmt->fetchAll();
    
} catch (PDOException $e) {
    die("Error fetching scadenze: " . $e->getMessage());
}

// Get scadenza for editing
$editingScadenza = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM scadenze WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $editingScadenza = $stmt->fetch();
    } catch (PDOException $e) {
        $message = "Errore durante il caricamento della scadenza: " . $e->getMessage();
        $messageType = "error";
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Scadenze</h1>
    <p class="text-muted">Monitora scadenze, promemoria e attività in sospeso</p>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#scadenzaModal">
        <i class="bi bi-plus-lg"></i> Nuova Scadenza
    </button>
    <a href="index.php?page=scadenze&action=export" class="btn btn-outline-secondary">
        <i class="bi bi-download"></i> Esporta CSV
    </a>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h5 class="card-title text-danger">Critiche</h5>
                <h2 class="display-6"><?php echo count(array_filter($scadenze, function($s) { return $s['priorita'] === 'Critica' && $s['stato'] === 'Attiva'; })); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h5 class="card-title text-warning">In Scadenza</h5>
                <h2 class="display-6"><?php echo count(array_filter($scadenze, function($s) { return $s['stato'] === 'Attiva' && strtotime($s['data_scadenza']) <= strtotime('+7 days'); })); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h5 class="card-title text-info">Attive</h5>
                <h2 class="display-6"><?php echo count(array_filter($scadenze, function($s) { return $s['stato'] === 'Attiva'; })); ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h5 class="card-title text-success">Completate</h5>
                <h2 class="display-6"><?php echo count(array_filter($scadenze, function($s) { return $s['stato'] === 'Completata'; })); ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="row mb-3 mt-4">
    <div class="col-md-6 col-lg-3">
        <form method="GET">
            <input type="hidden" name="page" value="scadenze">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Cerca..." name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                <button class="btn btn-outline-secondary" type="submit">Cerca</button>
            </div>
        </form>
    </div>
    <div class="col-md-6 col-lg-2">
        <form method="GET">
            <input type="hidden" name="page" value="scadenze">
            <select class="form-select" name="status" onchange="this.form.submit()">
                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Tutti gli stati</option>
                <option value="Attiva" <?php echo $statusFilter === 'Attiva' ? 'selected' : ''; ?>>Attiva</option>
                <option value="Completata" <?php echo $statusFilter === 'Completata' ? 'selected' : ''; ?>>Completata</option>
                <option value="Annullata" <?php echo $statusFilter === 'Annullata' ? 'selected' : ''; ?>>Annullata</option>
            </select>
        </form>
    </div>
    <div class="col-md-6 col-lg-2">
        <form method="GET">
            <input type="hidden" name="page" value="scadenze">
            <select class="form-select" name="type" onchange="this.form.submit()">
                <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>Tutti i tipi</option>
                <option value="Quote" <?php echo $typeFilter === 'Quote' ? 'selected' : ''; ?>>Quote</option>
                <option value="Documenti" <?php echo $typeFilter === 'Documenti' ? 'selected' : ''; ?>>Documenti</option>
                <option value="Eventi" <?php echo $typeFilter === 'Eventi' ? 'selected' : ''; ?>>Eventi</option>
                <option value="Generale" <?php echo $typeFilter === 'Generale' ? 'selected' : ''; ?>>Generale</option>
            </select>
        </form>
    </div>
    <div class="col-md-6 col-lg-2">
        <form method="GET">
            <input type="hidden" name="page" value="scadenze">
            <select class="form-select" name="priority" onchange="this.form.submit()">
                <option value="all" <?php echo $priorityFilter === 'all' ? 'selected' : ''; ?>>Tutte le priorità</option>
                <option value="Critica" <?php echo $priorityFilter === 'Critica' ? 'selected' : ''; ?>>Critica</option>
                <option value="Alta" <?php echo $priorityFilter === 'Alta' ? 'selected' : ''; ?>>Alta</option>
                <option value="Media" <?php echo $priorityFilter === 'Media' ? 'selected' : ''; ?>>Media</option>
                <option value="Bassa" <?php echo $priorityFilter === 'Bassa' ? 'selected' : ''; ?>>Bassa</option>
            </select>
        </form>
    </div>
</div>

<!-- Scadenze Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Elenco Scadenze</h5>
        <p class="text-muted mb-0"><?php echo count($scadenze); ?> scadenze trovate</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Scadenza</th>
                        <th>Tipo</th>
                        <th>Priorità</th>
                        <th>Assegnato a</th>
                        <th>Stato</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($scadenze as $scadenza): ?>
                        <?php
                        $giorni = floor((strtotime($scadenza['data_scadenza']) - time()) / (60 * 60 * 24));
                        $isScaduta = $giorni < 0;
                        $isInScadenza = $giorni <= $scadenza['promemoria_giorni'] && $giorni >= 0;
                        ?>
                        <tr class="<?php echo $isScaduta && $scadenza['stato'] === 'Attiva' ? 'table-danger' : ($isInScadenza && $scadenza['stato'] === 'Attiva' ? 'table-warning' : ''); ?>">
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($scadenza['titolo']); ?></strong>
                                    <?php if ($scadenza['descrizione']): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars(substr($scadenza['descrizione'], 0, 80)) . (strlen($scadenza['descrizione']) > 80 ? '...' : ''); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div><?php echo formatDate($scadenza['data_scadenza']); ?></div>
                                <div class="small">
                                    <?php if ($scadenza['stato'] === 'Attiva'): ?>
                                        <?php if ($giorni < 0): ?>
                                            <span class="text-danger">Scaduta da <?php echo abs($giorni); ?> giorni</span>
                                        <?php elseif ($giorni === 0): ?>
                                            <span class="text-warning">Scade oggi</span>
                                        <?php elseif ($giorni <= $scadenza['promemoria_giorni']): ?>
                                            <span class="text-warning">Scade tra <?php echo $giorni; ?> giorni</span>
                                        <?php else: ?>
                                            <span class="text-muted">Tra <?php echo $giorni; ?> giorni</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($scadenza['tipo_scadenza']); ?></span>
                            </td>
                            <td>
                                <?php
                                $priorityClass = 'secondary';
                                switch ($scadenza['priorita']) {
                                    case 'Critica': $priorityClass = 'danger'; break;
                                    case 'Alta': $priorityClass = 'warning'; break;
                                    case 'Media': $priorityClass = 'info'; break;
                                    case 'Bassa': $priorityClass = 'secondary'; break;
                                }
                                ?>
                                <span class="badge bg-<?php echo $priorityClass; ?>"><?php echo htmlspecialchars($scadenza['priorita']); ?></span>
                            </td>
                            <td>
                                <?php if ($scadenza['assegnato_username']): ?>
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($scadenza['assegnato_username']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Non assegnato</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusClass = 'secondary';
                                switch ($scadenza['stato']) {
                                    case 'Attiva': $statusClass = 'success'; break;
                                    case 'Completata': $statusClass = 'primary'; break;
                                    case 'Annullata': $statusClass = 'dark'; break;
                                }
                                ?>
                                <span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($scadenza['stato']); ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($scadenza['stato'] === 'Attiva'): ?>
                                    <form method="POST" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="complete_id" value="<?php echo $scadenza['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-success" onclick="return confirm('Marcare come completata?')">
                                            <i class="bi bi-check"></i> Completa
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="index.php?page=scadenze&edit=<?php echo $scadenza['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Modifica
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questa scadenza?')">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $scadenza['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Elimina
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Scadenza Modal -->
<div class="modal fade" id="scadenzaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $editingScadenza ? 'Modifica Scadenza' : 'Nuova Scadenza'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?php echo $editingScadenza['id'] ?? ''; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Titolo</label>
                        <input type="text" class="form-control" name="titolo" value="<?php echo htmlspecialchars($editingScadenza['titolo'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descrizione</label>
                        <textarea class="form-control" name="descrizione" rows="3"><?php echo htmlspecialchars($editingScadenza['descrizione'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Data Scadenza</label>
                            <input type="date" class="form-control" name="data_scadenza" value="<?php echo htmlspecialchars($editingScadenza['data_scadenza'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Promemoria (giorni prima)</label>
                            <input type="number" class="form-control" name="promemoria_giorni" value="<?php echo htmlspecialchars($editingScadenza['promemoria_giorni'] ?? '7'); ?>" min="1">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tipo</label>
                            <select class="form-select" name="tipo_scadenza" required>
                                <option value="">Seleziona tipo...</option>
                                <option value="Quote" <?php echo ($editingScadenza && $editingScadenza['tipo_scadenza'] === 'Quote') ? 'selected' : ''; ?>>Quote</option>
                                <option value="Documenti" <?php echo ($editingScadenza && $editingScadenza['tipo_scadenza'] === 'Documenti') ? 'selected' : ''; ?>>Documenti</option>
                                <option value="Eventi" <?php echo ($editingScadenza && $editingScadenza['tipo_scadenza'] === 'Eventi') ? 'selected' : ''; ?>>Eventi</option>
                                <option value="Generale" <?php echo ($editingScadenza && $editingScadenza['tipo_scadenza'] === 'Generale') ? 'selected' : ''; ?>>Generale</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Priorità</label>
                            <select class="form-select" name="priorita">
                                <option value="Bassa" <?php echo ($editingScadenza && $editingScadenza['priorita'] === 'Bassa') ? 'selected' : ''; ?>>Bassa</option>
                                <option value="Media" <?php echo ($editingScadenza && $editingScadenza['priorita'] === 'Media') ? 'selected' : ''; ?>>Media</option>
                                <option value="Alta" <?php echo ($editingScadenza && $editingScadenza['priorita'] === 'Alta') ? 'selected' : ''; ?>>Alta</option>
                                <option value="Critica" <?php echo ($editingScadenza && $editingScadenza['priorita'] === 'Critica') ? 'selected' : ''; ?>>Critica</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Assegna a</label>
                            <select class="form-select" name="assegnato_a">
                                <option value="">Non assegnato</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo ($editingScadenza && $editingScadenza['assegnato_a'] == $user['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['username']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Stato</label>
                            <select class="form-select" name="stato">
                                <option value="Attiva" <?php echo ($editingScadenza && $editingScadenza['stato'] === 'Attiva') ? 'selected' : ''; ?>>Attiva</option>
                                <option value="Completata" <?php echo ($editingScadenza && $editingScadenza['stato'] === 'Completata') ? 'selected' : ''; ?>>Completata</option>
                                <option value="Annullata" <?php echo ($editingScadenza && $editingScadenza['stato'] === 'Annullata') ? 'selected' : ''; ?>>Annullata</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><?php echo $editingScadenza ? 'Aggiorna' : 'Salva'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editingScadenza): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var scadenzaModal = new bootstrap.Modal(document.getElementById('scadenzaModal'));
        scadenzaModal.show();
    });
</script>
<?php endif; ?>