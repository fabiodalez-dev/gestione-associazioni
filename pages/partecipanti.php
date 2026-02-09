<?php
// pages/partecipanti.php

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}
$associazione_id = $_SESSION['associazione_id'];

$message = '';
$messageType = '';

// Check if evento_id is provided
if (!isset($_GET['evento_id'])) {
    header('Location: index.php?page=eventi');
    exit;
}

$evento_id = $_GET['evento_id'];

// Get event details
try {
    $stmt = $pdo->prepare("SELECT * FROM eventi WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$evento_id, $associazione_id]);
    $evento = $stmt->fetch();

    if (!$evento) {
        header('Location: index.php?page=eventi');
        exit;
    }
} catch (PDOException $e) {
    error_log('partecipanti.php: ' . $e->getMessage());
    die("Errore nel recupero dati. Riprova più tardi.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di validazione. Riprova.";
        $messageType = "error";
    } elseif (isset($_POST['add_all_soci'])) {
        // Add all active soci to event
        try {
            $stmt = $pdo->prepare("SELECT id FROM soci WHERE stato = 'Attivo' AND associazione_id = ?");
            $stmt->execute([$associazione_id]);
            $soci = $stmt->fetchAll();

            $added = 0;
            foreach ($soci as $socio) {
                try {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO eventi_partecipanti (id, associazione_id, evento_id, socio_id, stato_partecipazione, created_at) VALUES (?, ?, ?, ?, 'Non Partecipa', NOW())");
                    $stmt->execute([generateUuid(), $associazione_id, $evento_id, $socio['id']]);
                    if ($stmt->rowCount() > 0) $added++;
                } catch (PDOException $e) {
                    // Ignore duplicate entries
                }
            }

            $message = "Aggiunti $added soci all'evento!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('partecipanti.php: ' . $e->getMessage());
            $message = "Errore. Riprova più tardi.";
            $messageType = "error";
        }
    } elseif (isset($_POST['update_partecipazione'])) {
        // Update participation status
        $partecipante_id = $_POST['partecipante_id'];
        $stato = $_POST['stato_partecipazione'];
        $note = sanitizeInput($_POST['note']);

        try {
            $stmt = $pdo->prepare("UPDATE eventi_partecipanti SET stato_partecipazione = ?, note = ?, data_conferma = NOW() WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$stato, $note, $partecipante_id, $associazione_id]);
            $message = "Partecipazione aggiornata con successo!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('partecipanti.php: ' . $e->getMessage());
            $message = "Errore. Riprova più tardi.";
            $messageType = "error";
        }
    } elseif (isset($_POST['add_socio'])) {
        // Add single socio to event
        $socio_id = $_POST['socio_id'];
        $stato = $_POST['stato_partecipazione'];
        $note = sanitizeInput($_POST['note']);

        try {
            $stmt = $pdo->prepare("INSERT INTO eventi_partecipanti (id, associazione_id, evento_id, socio_id, stato_partecipazione, note, data_conferma, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE stato_partecipazione = VALUES(stato_partecipazione), note = VALUES(note), data_conferma = NOW()");
            $stmt->execute([generateUuid(), $associazione_id, $evento_id, $socio_id, $stato, $note]);
            $message = "Socio aggiunto all'evento!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('partecipanti.php: ' . $e->getMessage());
            $message = "Errore. Riprova più tardi.";
            $messageType = "error";
        }
    } elseif (isset($_POST['remove_partecipante'])) {
        // Remove participant
        $partecipante_id = $_POST['remove_partecipante'];
        try {
            $stmt = $pdo->prepare("DELETE FROM eventi_partecipanti WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$partecipante_id, $associazione_id]);
            $message = "Partecipante rimosso dall'evento!";
            $messageType = "success";
        } catch (PDOException $e) {
            error_log('partecipanti.php: ' . $e->getMessage());
            $message = "Errore. Riprova più tardi.";
            $messageType = "error";
        }
    }
}

// Get participants list
try {
    $statusFilter = $_GET['status'] ?? 'all';

    $sql = "SELECT ep.*, CONCAT(s.nome, ' ', s.cognome) as socio_name, s.numero_socio, s.email, s.telefono
            FROM eventi_partecipanti ep
            JOIN soci s ON ep.socio_id = s.id
            WHERE ep.evento_id = ? AND ep.associazione_id = ?";
    $params = [$evento_id, $associazione_id];

    if ($statusFilter !== 'all') {
        $sql .= " AND ep.stato_partecipazione = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY s.cognome, s.nome";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $partecipanti = $stmt->fetchAll();

    // Get soci not yet added to this event
    $stmt = $pdo->prepare("
        SELECT s.id, CONCAT(s.nome, ' ', s.cognome, ' (', s.numero_socio, ')') as nome_completo
        FROM soci s
        WHERE s.stato = 'Attivo'
        AND s.associazione_id = ?
        AND s.id NOT IN (
            SELECT ep.socio_id
            FROM eventi_partecipanti ep
            WHERE ep.evento_id = ?
        )
        ORDER BY s.cognome, s.nome
    ");
    $stmt->execute([$associazione_id, $evento_id]);
    $sociDisponibili = $stmt->fetchAll();

    // Statistics
    $confermati = count(array_filter($partecipanti, function($p) { return $p['stato_partecipazione'] === 'Confermato'; }));
    $forse = count(array_filter($partecipanti, function($p) { return $p['stato_partecipazione'] === 'Forse'; }));
    $nonPartecipa = count(array_filter($partecipanti, function($p) { return $p['stato_partecipazione'] === 'Non Partecipa'; }));

    // Count tessere create at this event
    $stmt_tc = $pdo->prepare("SELECT COUNT(*) FROM tessere WHERE evento_creazione_id = ? AND associazione_id = ?");
    $stmt_tc->execute([$evento_id, $associazione_id]);
    $tessere_create_evento = (int)$stmt_tc->fetchColumn();

} catch (PDOException $e) {
    error_log('partecipanti.php fetch: ' . $e->getMessage());
    die("Errore nel recupero dati. Riprova più tardi.");
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Partecipanti: <?php echo htmlspecialchars($evento['titolo']); ?></h1>
        <p class="text-muted">
            <i class="bi bi-calendar"></i> <?php echo formatDate($evento['data_evento']); ?>
            <span class="ms-3">
                <a href="index.php?page=eventi" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Torna agli Eventi
                </a>
            </span>
        </p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <h5 class="card-title text-success">Confermati</h5>
                <h2 class="display-6"><?php echo $confermati; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <h5 class="card-title text-warning">Forse</h5>
                <h2 class="display-6"><?php echo $forse; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <h5 class="card-title text-danger">Non Partecipa</h5>
                <h2 class="display-6"><?php echo $nonPartecipa; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <h5 class="card-title text-info">Totale</h5>
                <h2 class="display-6"><?php echo count($partecipanti); ?></h2>
            </div>
        </div>
    </div>
    <?php if ($tessere_create_evento > 0): ?>
    <div class="col-md-6 col-lg-3 mt-3">
        <div class="card border-primary">
            <div class="card-body text-center">
                <h5 class="card-title text-primary"><i class="bi bi-credit-card me-1"></i>Tessere create</h5>
                <h2 class="display-6"><?php echo $tessere_create_evento; ?></h2>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="d-flex justify-content-between mb-3">
    <div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSocioModal">
            <i class="bi bi-plus-lg"></i> Aggiungi Socio
        </button>
        <?php if (count($sociDisponibili) > 0): ?>
            <form method="POST" class="d-inline ms-2">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="add_all_soci" value="1">
                <button type="submit" class="btn btn-outline-primary" onclick="return confirm('Aggiungere tutti i soci attivi a questo evento?')">
                    <i class="bi bi-people"></i> Aggiungi Tutti i Soci
                </button>
            </form>
        <?php endif; ?>
    </div>
    <div>
        <div class="btn-group" role="group">
            <a href="index.php?page=partecipanti&evento_id=<?php echo htmlspecialchars($evento_id, ENT_QUOTES); ?>&status=all" class="btn btn-<?php echo $statusFilter === 'all' ? 'primary' : 'outline-primary'; ?>">Tutti</a>
            <a href="index.php?page=partecipanti&evento_id=<?php echo htmlspecialchars($evento_id, ENT_QUOTES); ?>&status=Confermato" class="btn btn-<?php echo $statusFilter === 'Confermato' ? 'success' : 'outline-success'; ?>">Confermati</a>
            <a href="index.php?page=partecipanti&evento_id=<?php echo htmlspecialchars($evento_id, ENT_QUOTES); ?>&status=Forse" class="btn btn-<?php echo $statusFilter === 'Forse' ? 'warning' : 'outline-warning'; ?>">Forse</a>
            <a href="index.php?page=partecipanti&evento_id=<?php echo htmlspecialchars($evento_id, ENT_QUOTES); ?>&status=Non Partecipa" class="btn btn-<?php echo $statusFilter === 'Non Partecipa' ? 'danger' : 'outline-danger'; ?>">Non Partecipa</a>
        </div>
    </div>
</div>

<!-- Participants Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Elenco Partecipanti</h5>
        <p class="text-muted mb-0"><?php echo count($partecipanti); ?> partecipanti</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>Contatti</th>
                        <th>Stato</th>
                        <th>Data Conferma</th>
                        <th>Note</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($partecipanti as $partecipante): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($partecipante['socio_name']); ?></strong>
                                    <div class="text-muted small">N. Socio: <?php echo htmlspecialchars($partecipante['numero_socio']); ?></div>
                                </div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($partecipante['email']); ?></div>
                                <?php if ($partecipante['telefono']): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($partecipante['telefono']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = 'secondary';
                                switch ($partecipante['stato_partecipazione']) {
                                    case 'Confermato': $badgeClass = 'success'; break;
                                    case 'Forse': $badgeClass = 'warning'; break;
                                    case 'Non Partecipa': $badgeClass = 'danger'; break;
                                }
                                ?>
                                <span class="badge bg-<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($partecipante['stato_partecipazione']); ?></span>
                            </td>
                            <td>
                                <?php if ($partecipante['data_conferma']): ?>
                                    <?php echo date('d/m/Y H:i', strtotime($partecipante['data_conferma'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($partecipante['note']): ?>
                                    <span class="text-truncate d-inline-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($partecipante['note']); ?>">
                                        <?php echo htmlspecialchars($partecipante['note']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo htmlspecialchars($partecipante['id'], ENT_QUOTES); ?>">
                                    <i class="bi bi-pencil"></i> Modifica
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Rimuovere questo partecipante?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="remove_partecipante" value="<?php echo htmlspecialchars($partecipante['id'], ENT_QUOTES); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Rimuovi
                                    </button>
                                </form>
                            </td>
                        </tr>

                        <!-- Edit Modal for each participant -->
                        <div class="modal fade" id="editModal<?php echo htmlspecialchars($partecipante['id'], ENT_QUOTES); ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Modifica Partecipazione: <?php echo htmlspecialchars($partecipante['socio_name']); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="update_partecipazione" value="1">
                                            <input type="hidden" name="partecipante_id" value="<?php echo htmlspecialchars($partecipante['id'], ENT_QUOTES); ?>">

                                            <div class="mb-3">
                                                <label class="form-label">Stato Partecipazione</label>
                                                <select class="form-select" name="stato_partecipazione" required>
                                                    <option value="Confermato" <?php echo $partecipante['stato_partecipazione'] === 'Confermato' ? 'selected' : ''; ?>>Confermato</option>
                                                    <option value="Forse" <?php echo $partecipante['stato_partecipazione'] === 'Forse' ? 'selected' : ''; ?>>Forse</option>
                                                    <option value="Non Partecipa" <?php echo $partecipante['stato_partecipazione'] === 'Non Partecipa' ? 'selected' : ''; ?>>Non Partecipa</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Note</label>
                                                <textarea class="form-control" name="note" rows="3"><?php echo htmlspecialchars($partecipante['note']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Aggiorna</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Socio Modal -->
<div class="modal fade" id="addSocioModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Aggiungi Socio all'Evento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="add_socio" value="1">

                    <div class="mb-3">
                        <label class="form-label">Socio</label>
                        <select class="form-select" name="socio_id" required>
                            <option value="">Seleziona un socio</option>
                            <?php foreach ($sociDisponibili as $socio): ?>
                                <option value="<?php echo htmlspecialchars($socio['id'], ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($socio['nome_completo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (count($sociDisponibili) === 0): ?>
                            <div class="form-text text-muted">Tutti i soci attivi sono già stati aggiunti a questo evento.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Stato Partecipazione</label>
                        <select class="form-select" name="stato_partecipazione" required>
                            <option value="Non Partecipa">Non Partecipa</option>
                            <option value="Forse">Forse</option>
                            <option value="Confermato">Confermato</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <textarea class="form-control" name="note" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary" <?php echo count($sociDisponibili) === 0 ? 'disabled' : ''; ?>><i class="bi bi-plus-lg me-1"></i>Aggiungi</button>
                </div>
            </form>
        </div>
    </div>
</div>
