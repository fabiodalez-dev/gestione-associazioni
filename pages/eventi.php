<?php
// pages/eventi.php - v2.0 (SaaS)

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM eventi WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        $message = "Evento eliminato con successo.";
        $messageType = "success";
    } else {
        $id = $_POST['id'] ?? null;
        $titolo = sanitizeInput($_POST['titolo']);
        $descrizione = sanitizeInput($_POST['descrizione']);
        $data_evento = $_POST['data_evento'];
        $luogo = sanitizeInput($_POST['luogo']);

        if ($id) {
            $stmt = $pdo->prepare("UPDATE eventi SET titolo=?, descrizione=?, data_evento=?, luogo=? WHERE id=? AND associazione_id=?");
            $stmt->execute([$titolo, $descrizione, $data_evento, $luogo, $id, $associazione_id]);
            $message = "Evento aggiornato con successo.";
        } else {
            $new_id = generateUuid();
            $stmt = $pdo->prepare("INSERT INTO eventi (id, associazione_id, titolo, descrizione, data_evento, luogo) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$new_id, $associazione_id, $titolo, $descrizione, $data_evento, $luogo]);
            $message = "Evento creato con successo.";
        }
        $messageType = "success";
    }
}

// Recupero Dati
$editingEvent = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM eventi WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingEvent = $stmt->fetch();
    if ($editingEvent) {
        $editingEvent['data_evento_form'] = date('Y-m-d\TH:i', strtotime($editingEvent['data_evento']));
    }
}

$searchTerm = $_GET['search'] ?? '';
$sql = "SELECT * FROM eventi WHERE associazione_id = ?";
$params = [$associazione_id];
if (!empty($searchTerm)) {
    $sql .= " AND (titolo LIKE ? OR descrizione LIKE ? OR luogo LIKE ?)";
    $searchTermWild = "%$searchTerm%";
    array_push($params, $searchTermWild, $searchTermWild, $searchTermWild);
}
$sql .= " ORDER BY data_evento DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Eventi</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventModal"><i class="bi bi-plus-lg"></i> Nuovo Evento</button>
</div>

<div class="responsive-table-wrapper">
    <table class="table-desktop">
        <thead><tr><th>Evento</th><th>Data e Ora</th><th>Luogo</th><th class="text-end">Azioni</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($event['titolo']); ?></strong></td>
                <td><?php echo date('d/m/Y H:i', strtotime($event['data_evento'])); ?></td>
                <td><?php echo htmlspecialchars($event['luogo']); ?></td>
                <td class="text-end">
                    <a href="index.php?page=partecipanti&evento_id=<?php echo $event['id']; ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-people"></i></a>
                    <a href="index.php?page=eventi&edit=<?php echo $event['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo evento?')"><input type="hidden" name="delete_id" value="<?php echo $event['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="table-mobile">
        <?php foreach ($events as $event): ?>
        <div class="table-card">
            <div class="card-header-section">
                <div class="card-primary-info">
                    <h5 class="card-title"><?php echo htmlspecialchars($event['titolo']); ?></h5>
                    <div class="card-subtitle"><?php echo date('d/m/Y H:i', strtotime($event['data_evento'])); ?></div>
                </div>
            </div>
            <div class="card-content">
                <div class="card-field"><span class="field-label">Luogo</span><span class="field-value"><?php echo htmlspecialchars($event['luogo']); ?></span></div>
            </div>
            <div class="card-actions">
                <a href="index.php?page=partecipanti&evento_id=<?php echo $event['id']; ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-people me-1"></i>Partecipanti</a>
                <a href="index.php?page=eventi&edit=<?php echo $event['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifica</a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo evento?')"><input type="hidden" name="delete_id" value="<?php echo $event['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button></form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="eventModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingEvent ? 'Modifica' : 'Nuovo'; ?> Evento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingEvent['id'] ?? ''; ?>">
            <div class="mb-3"><label>Titolo</label><input type="text" name="titolo" class="form-control" value="<?php echo htmlspecialchars($editingEvent['titolo'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Data e Ora</label><input type="datetime-local" name="data_evento" class="form-control" value="<?php echo $editingEvent['data_evento_form'] ?? ''; ?>" required></div>
            <div class="mb-3"><label>Luogo</label><input type="text" name="luogo" class="form-control" value="<?php echo htmlspecialchars($editingEvent['luogo'] ?? ''); ?>"></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control" rows="4"><?php echo htmlspecialchars($editingEvent['descrizione'] ?? ''); ?></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary">Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingEvent): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('eventModal')).show());</script>
<?php endif; ?>
