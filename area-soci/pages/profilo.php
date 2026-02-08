<?php
// area-soci/pages/profilo.php

// Assicurati che $socio_loggato sia disponibile da area-soci/index.php
if (!isset($socio_loggato)) {
    redirect('login.php');
}

$message = '';
$messageType = '';

// Gestione aggiornamento profilo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
    $socio_id = $socio_loggato['id'];
    $telefono = sanitizeInput($_POST['telefono']);
    $indirizzo = sanitizeInput($_POST['indirizzo']);
    $citta = sanitizeInput($_POST['citta']);
    $cap = sanitizeInput($_POST['cap']);

    try {
        $stmt = $pdo->prepare("UPDATE soci SET telefono=?, indirizzo=?, citta=?, cap=? WHERE id=?");
        $stmt->execute([$telefono, $indirizzo, $citta, $cap, $socio_id]);
        $message = "Profilo aggiornato con successo!";
        $messageType = "success";
        
        // Aggiorna i dati del socio_loggato per riflettere le modifiche immediatamente
        $socio_loggato['telefono'] = $telefono;
        $socio_loggato['indirizzo'] = $indirizzo;
        $socio_loggato['citta'] = $citta;
        $socio_loggato['cap'] = $cap;

    } catch (PDOException $e) {
        error_log('profilo.php PDOException: ' . $e->getMessage());
        $message = "Errore durante l'aggiornamento del profilo. Riprova più tardi.";
        $messageType = "danger";
    }
    }
}

// Recupera campi personalizzati e tags per l'associazione
$stmt_campi = $pdo->prepare("SELECT cp.nome_campo, vcp.valore FROM valori_campi_personalizzati vcp JOIN campi_personalizzati cp ON vcp.campo_id = cp.id WHERE vcp.socio_id = ?");
$stmt_campi->execute([$socio_loggato['id']]);
$campi_valorizzati = $stmt_campi->fetchAll();

$stmt_tags = $pdo->prepare("SELECT t.nome_tag, t.colore FROM socio_tags st JOIN tags t ON st.tag_id = t.id WHERE st.socio_id = ?");
$stmt_tags->execute([$socio_loggato['id']]);
$tags = $stmt_tags->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Il Mio Profilo</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><h5>Dati Anagrafici</h5></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Nome</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['nome']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Cognome</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['cognome']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['email']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Numero Socio</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['numero_socio']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Data di Nascita</label><input type="date" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['data_nascita']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Data Iscrizione</label><input type="date" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['data_iscrizione']); ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Stato</label><input type="text" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['stato']); ?>" disabled></div>
            </div>
            
            <h5 class="mt-4">Dati di Contatto e Residenza</h5>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Telefono</label><input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['telefono'] ?? ''); ?>"></div>
                <div class="col-md-6"><label class="form-label">Indirizzo</label><input type="text" name="indirizzo" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['indirizzo'] ?? ''); ?>"></div>
                <div class="col-md-4"><label class="form-label">Città</label><input type="text" name="citta" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['citta'] ?? ''); ?>"></div>
                <div class="col-md-2"><label class="form-label">CAP</label><input type="text" name="cap" class="form-control" value="<?php echo htmlspecialchars($socio_loggato['cap'] ?? ''); ?>"></div>
            </div>
            <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Modifiche</button>
        </form>
    </div>
</div>

<?php if (!empty($campi_valorizzati) || !empty($tags)): ?>
<div class="card mb-4">
    <div class="card-header"><h5>Dati Personalizzati e Tag</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach ($campi_valorizzati as $cv): ?>
            <div class="col-md-6"><strong><?php echo htmlspecialchars($cv['nome_campo']); ?>:</strong><p><?php echo htmlspecialchars($cv['valore']); ?></p></div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($tags)): ?>
        <h6 class="mt-4">I Miei Tag:</h6>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($tags as $tag): ?>
                <span class="badge" style="background-color: <?php echo htmlspecialchars($tag['colore']); ?>; color: white;"><?php echo htmlspecialchars($tag['nome_tag']); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
