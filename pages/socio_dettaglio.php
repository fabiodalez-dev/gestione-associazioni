<?php
// pages/socio_dettaglio.php - v2.2 (SaaS con Documenti e Storico)

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

$associazione_id = $_SESSION['associazione_id'];
$socio_id = $_GET['id'] ?? null;
$message = '';
$messageType = '';

if (!$socio_id) redirect('index.php?page=soci');

// --- GESTIONE AZIONI POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } elseif (isset($_POST['action']) && $_POST['action'] == 'upload_documento') {
        if (isset($_FILES['file_documento']) && $_FILES['file_documento']['error'] == 0) {
            $file = $_FILES['file_documento'];
            $descrizione = sanitizeInput($_POST['descrizione_documento']);
            $data_scadenza = !empty($_POST['data_scadenza_documento']) ? $_POST['data_scadenza_documento'] : null;
            
            // Validazione sicura del file
            $upload_errors = validateFileUpload($file);
            
            if (empty($upload_errors)) {
                $upload_dir = UPLOADS_PATH . "/documents/$associazione_id/$socio_id/";
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                
                // Nome file sicuro
                $secure_filename = generateSecureFileName($file['name'], 'doc_');
                $target_file = $upload_dir . $secure_filename;

                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $new_doc_id = generateUuid();
                    $stmt = $pdo->prepare("INSERT INTO documenti_socio (id, socio_id, associazione_id, nome_file, percorso_file, descrizione, data_scadenza, caricato_da) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$new_doc_id, $socio_id, $associazione_id, $secure_filename, $target_file, $descrizione, $data_scadenza, $_SESSION['user_id']]);
                    logSocioActivity($pdo, $associazione_id, $socio_id, 'Caricamento Documento', "Caricato il file: " . sanitizeInput($file['name']));
                    $message = "Documento caricato con successo."; $messageType = "success";
                } else {
                    $message = "Errore nel caricamento del file."; $messageType = "danger";
                }
            } else {
                $message = "Errore validazione file: " . implode(', ', $upload_errors); 
                $messageType = "danger";
            }
        }
    }
}

// --- RECUPERO DATI ---
$stmt = $pdo->prepare("SELECT * FROM soci WHERE id = ? AND associazione_id = ?");
$stmt->execute([$socio_id, $associazione_id]);
$socio = $stmt->fetch();
if (!$socio) redirect('index.php?page=404');

$stmt_campi = $pdo->prepare("SELECT cp.nome_campo, vcp.valore FROM valori_campi_personalizzati vcp JOIN campi_personalizzati cp ON vcp.campo_id = cp.id WHERE vcp.socio_id = ?");
$stmt_campi->execute([$socio_id]);
$campi_valorizzati = $stmt_campi->fetchAll();

$stmt_tags = $pdo->prepare("SELECT t.nome_tag, t.colore FROM socio_tags st JOIN tags t ON st.tag_id = t.id WHERE st.socio_id = ?");
$stmt_tags->execute([$socio_id]);
$tags = $stmt_tags->fetchAll();

$stmt_quote = $pdo->prepare("SELECT * FROM quote WHERE socio_id = ? ORDER BY anno DESC");
$stmt_quote->execute([$socio_id]);
$quote = $stmt_quote->fetchAll();

$stmt_tessere = $pdo->prepare("SELECT * FROM tessere WHERE socio_id = ? ORDER BY anno_validita DESC");
$stmt_tessere->execute([$socio_id]);
$tessere = $stmt_tessere->fetchAll();

$stmt_documenti = $pdo->prepare("SELECT * FROM documenti_socio WHERE socio_id = ? ORDER BY created_at DESC");
$stmt_documenti->execute([$socio_id]);
$documenti_socio = $stmt_documenti->fetchAll();

$stmt_storico = $pdo->prepare("SELECT st.*, u.email as utente_email FROM storico_attivita_socio st LEFT JOIN utenti u ON st.utente_id = u.id WHERE st.socio_id = ? ORDER BY st.data_attivita DESC");
$stmt_storico->execute([$socio_id]);
$storico = $stmt_storico->fetchAll();

?>

<div class="pt-3 pb-2 mb-3">
    <div class="d-flex align-items-center">
        <i class="bi bi-person-circle display-4 me-3"></i>
        <div>
            <h1 class="h2 mb-0"><?php echo htmlspecialchars($socio['nome'] . ' ' . $socio['cognome']); ?></h1>
            <p class="text-muted">N. Socio: <?php echo htmlspecialchars($socio['numero_socio']); ?></p>
        </div>
        <div class="ms-auto">
            <?php foreach ($tags as $tag): ?>
                <span class="badge fs-6 me-1" style="background-color: <?php echo $tag['colore']; ?>; color: white;"><?php echo htmlspecialchars($tag['nome_tag']); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<ul class="nav nav-tabs" id="socioTab" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" id="anagrafica-tab" data-bs-toggle="tab" data-bs-target="#anagrafica" type="button">Anagrafica</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="quote-tessere-tab" data-bs-toggle="tab" data-bs-target="#quote-tessere" type="button">Quote e Tessere</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="documenti-tab" data-bs-toggle="tab" data-bs-target="#documenti" type="button">Documenti</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="storico-tab" data-bs-toggle="tab" data-bs-target="#storico" type="button">Storico</button></li>
</ul>

<div class="tab-content" id="socioTabContent">
    <div class="tab-pane fade show active" id="anagrafica" role="tabpanel"><div class="card mt-3"><div class="card-body row g-3">
        <div class="col-md-6"><strong>Email:</strong><p><?php echo htmlspecialchars($socio['email']); ?></p></div>
        <div class="col-md-6"><strong>Telefono:</strong><p><?php echo htmlspecialchars($socio['telefono'] ?? 'N/D'); ?></p></div>
        <div class="col-md-6"><strong>Data di Nascita:</strong><p><?php echo date('d/m/Y', strtotime($socio['data_nascita'])); ?></p></div>
        <div class="col-md-6"><strong>Data Iscrizione:</strong><p><?php echo date('d/m/Y', strtotime($socio['data_iscrizione'])); ?></p></div>
        <div class="col-md-6"><strong>Stato:</strong><p><span class="badge bg-success"><?php echo htmlspecialchars($socio['stato']); ?></span></p></div>
        <?php foreach ($campi_valorizzati as $cv): ?>
        <div class="col-md-6"><strong><?php echo htmlspecialchars($cv['nome_campo']); ?>:</strong><p><?php echo htmlspecialchars($cv['valore']); ?></p></div>
        <?php endforeach; ?>
    </div></div></div>

    <div class="tab-pane fade" id="quote-tessere" role="tabpanel">
        <div class="card mt-3"><div class="card-header"><h5>Quote</h5></div><div class="card-body"><table class="table table-sm"><thead><tr><th>Anno</th><th>Importo</th><th>Stato</th><th>Data Pagamento</th></tr></thead><tbody>
        <?php foreach($quote as $q): ?><tr><td><?php echo $q['anno']; ?></td><td>€<?php echo $q['importo']; ?></td><td><?php echo $q['stato']; ?></td><td><?php echo $q['data_pagamento'] ? date('d/m/Y', strtotime($q['data_pagamento'])) : '-'; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
        <div class="card mt-3"><div class="card-header"><h5>Tessere</h5></div><div class="card-body"><table class="table table-sm"><thead><tr><th>Numero</th><th>Anno</th><th>Scadenza</th><th>Stato</th><th>Costo</th></tr></thead><tbody>
        <?php 
        // Calcola il costo per ogni tessera: override del tipo socio o default associazione
        $stmt_cost = $pdo->prepare("SELECT ts.costo_tessera as tipo_costo, a.costo_tessera as assoc_costo FROM soci s LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id LEFT JOIN associazioni a ON s.associazione_id = a.id WHERE s.id = ? AND s.associazione_id = ? LIMIT 1");
        $stmt_cost->execute([$socio_id, $associazione_id]);
        $costRow = $stmt_cost->fetch() ?: [];
        $baseCost = isset($costRow['tipo_costo']) && $costRow['tipo_costo'] !== null ? (float)$costRow['tipo_costo'] : (isset($costRow['assoc_costo']) && $costRow['assoc_costo'] !== null ? (float)$costRow['assoc_costo'] : null);
        foreach($tessere as $t): ?><tr><td><?php echo $t['numero_tessera']; ?></td><td><?php echo $t['anno_validita']; ?></td><td><?php echo date('d/m/Y', strtotime($t['data_scadenza'])); ?></td><td><?php echo $t['stato']; ?></td><td><?php echo $baseCost !== null ? '€ ' . number_format($baseCost, 2, ',', '.') : '—'; ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </div>

    <div class="tab-pane fade" id="documenti" role="tabpanel">
        <div class="card mt-3">
            <div class="card-header"><h5>Carica Nuovo Documento</h5></div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="upload_documento">
                    <div class="mb-3"><label>File</label><input type="file" name="file_documento" class="form-control" required></div>
                    <div class="mb-3"><label>Descrizione</label><input type="text" name="descrizione_documento" class="form-control" placeholder="Es. Certificato medico agonistico"></div>
                    <div class="mb-3"><label>Data Scadenza (opzionale)</label><input type="date" name="data_scadenza_documento" class="form-control"></div>
                    <button type="submit" class="btn btn-primary">Carica</button>
                </form>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-header"><h5>Documenti Caricati</h5></div>
            <div class="card-body">
                <table class="table table-sm"><thead><tr><th>File</th><th>Descrizione</th><th>Scadenza</th><th>Caricato il</th><th>Azioni</th></tr></thead><tbody>
            <?php foreach($documenti_socio as $doc): ?>
                <tr>
                    <td><a href="#"><?php echo htmlspecialchars($doc['nome_file']); ?></a></td>
                    <td><?php echo htmlspecialchars($doc['descrizione']); ?></td>
                    <td><?php echo $doc['data_scadenza'] ? date('d/m/Y', strtotime($doc['data_scadenza'])) : '-'; ?></td>
                    <td><?php echo date('d/m/Y', strtotime($doc['created_at'])); ?></td>
                    <td><a href="#" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div></div>
    </div>

    <div class="tab-pane fade" id="storico" role="tabpanel">
        <div class="card mt-3"><div class="card-header"><h5>Storico Attività</h5></div><div class="card-body">
            <ul class="list-group list-group-flush">
                <?php 
                $stmt_storico = $pdo->prepare("SELECT st.*, u.email as utente_email FROM storico_attivita_socio st LEFT JOIN utenti u ON st.utente_id = u.id WHERE st.socio_id = ? ORDER BY st.data_attivita DESC");
                $stmt_storico->execute([$socio_id]);
                $storico = $stmt_storico->fetchAll();
                if(empty($storico)):
                ?>
                    <li class="list-group-item text-muted">Nessuna attività registrata.</li>
                <?php else: foreach($storico as $item):
                ?>
                <li class="list-group-item">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($item['tipo_attivita']); ?></strong> - <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($item['data_attivita'])); ?></small></p>
                    <p class="mb-0"><?php echo htmlspecialchars($item['descrizione']); ?></p>
                    <?php if($item['utente_email']): ?><small class="text-muted">Eseguito da: <?php echo htmlspecialchars($item['utente_email']); ?></small><?php endif; ?>
                </li>
                <?php endforeach; endif; ?>
            </ul>
        </div></div>
    </div>
</div>
