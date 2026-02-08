<?php
// pages/configurazioni.php - v2.0 (SaaS)

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}
$associazione_id = $_SESSION['associazione_id'];

// Assicura colonne costo tessera
ensureTesseraCostColumns($pdo);
$message = '';
$messageType = '';

// Verifica se la tabella per i template tessera è presente (per evitare errori pre-migrazione)
$has_tessera_templates = tableExists($pdo, 'tessera_templates');

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        try {
            // CRUD Sedi (sezione impostazioni)
            if (isset($_POST['sedi_action']) && $associazione_id) {
                if ($_POST['sedi_action'] === 'delete' && !empty($_POST['delete_id'])) {
                    $stmt = $pdo->prepare("DELETE FROM sedi WHERE id = ? AND associazione_id = ?");
                    $stmt->execute([$_POST['delete_id'], $associazione_id]);
                    $message = "Sede eliminata con successo."; $messageType = "success";
                } elseif ($_POST['sedi_action'] === 'save') {
                    $sid = $_POST['id'] ?? null;
                    $nome = cleanInput($_POST['nome'] ?? '');
                    $indirizzo = cleanInput($_POST['indirizzo'] ?? '');
                    $citta = cleanInput($_POST['citta'] ?? '');
                    $provincia = cleanInput($_POST['provincia'] ?? '');
                    $cap = cleanInput($_POST['cap'] ?? '');
                    $responsabile = cleanInput($_POST['responsabile'] ?? '');
                    if ($sid) {
                        $stmt = $pdo->prepare("UPDATE sedi SET nome=?, indirizzo=?, citta=?, provincia=?, cap=?, responsabile=? WHERE id=? AND associazione_id=?");
                        $stmt->execute([$nome, $indirizzo, $citta, $provincia, $cap, $responsabile, $sid, $associazione_id]);
                        $message = "Sede aggiornata con successo."; $messageType = "success";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, provincia, cap, responsabile) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([generateUuid(), $associazione_id, $nome, $indirizzo, $citta, $provincia, $cap, $responsabile]);
                        $message = "Sede creata con successo."; $messageType = "success";
                    }
                }
            }
            // Upload Logo Associazione
            if (isset($_POST['upload_logo']) && isset($_FILES['logo_file'])) {
            $errors = validateFileUpload($_FILES['logo_file'], ['jpg', 'jpeg', 'png'], 2 * 1024 * 1024);
            if (empty($errors)) {
                $safeName = generateSecureFileName($_FILES['logo_file']['name'], 'logo_');
                $targetPath = UPLOADS_PATH . '/logos/' . $safeName;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                    $relative = 'uploads/logos/' . $safeName;
                    $stmt = $pdo->prepare("UPDATE associazioni SET logo_url = ? WHERE id = ?");
                    $stmt->execute([$relative, $associazione_id]);
                    $message = "Logo aggiornato con successo.";
                    $messageType = "success";
                    $associazione['logo_url'] = $relative;
                } else {
                    $message = "Errore nel salvataggio del file.";
                    $messageType = "danger";
                }
            } else {
                $message = implode(' | ', $errors);
                $messageType = "danger";
            }
        }

        if (isset($_POST['update_info'])) {
            $sql = "UPDATE associazioni SET nome=?, partita_iva=?, codice_fiscale=?, indirizzo=?, citta=?, provincia=?, cap=?, email=?, telefono=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                cleanInput($_POST['nome'] ?? ''),
                cleanInput($_POST['partita_iva'] ?? ''),
                cleanInput($_POST['codice_fiscale'] ?? ''),
                cleanInput($_POST['indirizzo'] ?? ''),
                cleanInput($_POST['citta'] ?? ''),
                cleanInput($_POST['provincia'] ?? ''),
                cleanInput($_POST['cap'] ?? ''),
                cleanInput($_POST['email'] ?? ''),
                cleanInput($_POST['telefono'] ?? ''),
                $associazione_id
            ]);
            $message = "Dati anagrafici aggiornati con successo.";
        } elseif (isset($_POST['update_tesseramento'])) {
            $sql = "UPDATE associazioni SET tipo_scadenza_default=?, giorni_notifica_scadenza=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                sanitizeInput($_POST['tipo_scadenza_default']),
                filter_var($_POST['giorni_notifica_scadenza'], FILTER_VALIDATE_INT),
                $associazione_id
            ]);
            $message = "Impostazioni di tesseramento aggiornate.";
        } elseif (isset($_POST['update_template'])) {
            $sql = "UPDATE associazioni SET template_email_scadenza=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([cleanInput($_POST['template_email_scadenza'] ?? ''), $associazione_id]);
            $message = "Template email aggiornato.";
        } elseif (isset($_POST['save_tessera_template'])) {
            if (!$has_tessera_templates) {
                $message = "Funzionalità non installata. Esegui la migrazione DB per abilitare i template della tessera.";
                $messageType = "warning";
            } else {
                $tipo_id = $_POST['tipo_id'] !== '' ? $_POST['tipo_id'] : null;
                $contenuto = $_POST['contenuto'] ?? '';
                // Upsert separato per tipo specifico o default
                if ($tipo_id !== null) {
                    $check = $pdo->prepare("SELECT id FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id = ? LIMIT 1");
                    $check->execute([$associazione_id, $tipo_id]);
                } else {
                    $check = $pdo->prepare("SELECT id FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id IS NULL LIMIT 1");
                    $check->execute([$associazione_id]);
                }
                $existing = $check->fetch();
                if ($existing) {
                    $upd = $pdo->prepare("UPDATE tessera_templates SET contenuto = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$contenuto, $existing['id']]);
                    $message = "Template tessera aggiornato.";
                } else {
                    $ins = $pdo->prepare("INSERT INTO tessera_templates (id, associazione_id, tipo_socio_id, titolo, contenuto, attivo) VALUES (?, ?, ?, 'Template Tessera', ?, 1)");
                    $ins->execute([generateUuid(), $associazione_id, $tipo_id, $contenuto]);
                    $message = "Template tessera creato.";
                }
                $messageType = "success";
            }
        }
        } catch (PDOException $e) {
            error_log('configurazioni.php PDOException: ' . $e->getMessage());
            $message = "Errore durante l'aggiornamento. Riprova più tardi.";
            $messageType = "danger";
        }
    }
}

// Recupero dati associazione
$stmt = $pdo->prepare("SELECT * FROM associazioni WHERE id = ?");
$stmt->execute([$associazione_id]);
$associazione = $stmt->fetch();

// Tipi socio per selezione template tessera
$tipi_stmt = $pdo->prepare("SELECT id, nome FROM tipi_socio WHERE associazione_id = ? ORDER BY nome");
$tipi_stmt->execute([$associazione_id]);
$tipi_socio = $tipi_stmt->fetchAll();

// Tipo selezionato (GET)
$selected_tipo_id = $_GET['tipo_id'] ?? '';

// Template corrente per il tipo selezionato o default (solo se tabella presente)
$template_corrente = '';
if ($has_tessera_templates) {
    if ($selected_tipo_id !== '') {
        $tpl_stmt = $pdo->prepare("SELECT contenuto FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id = ? LIMIT 1");
        $tpl_stmt->execute([$associazione_id, $selected_tipo_id]);
    } else {
        $tpl_stmt = $pdo->prepare("SELECT contenuto FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id IS NULL LIMIT 1");
        $tpl_stmt->execute([$associazione_id]);
    }
    $template_corrente = ($row = $tpl_stmt->fetch()) ? $row['contenuto'] : '';
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Impostazioni Associazione</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>


<div class="row">
    <div class="col-lg-8">
        <!-- Card Sedi -->
        <div class="card mb-4" id="sedi">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Sedi</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sedeModal">Nuova Sede</button>
            </div>
            <div class="card-body">
                <?php 
                $sedi = [];
                $stmt_sedi = $pdo->prepare("SELECT * FROM sedi WHERE associazione_id = ? ORDER BY nome");
                $stmt_sedi->execute([$associazione_id]);
                $sedi = $stmt_sedi->fetchAll();
                ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Nome</th><th>Indirizzo</th><th>Città</th><th>Provincia</th><th>CAP</th><th>Responsabile</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                        <?php foreach ($sedi as $s): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($s['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['indirizzo'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['citta'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['provincia'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['cap'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['responsabile'] ?? ''); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sedeModal" data-id="<?php echo $s['id']; ?>" data-nome="<?php echo htmlspecialchars($s['nome']); ?>" data-indirizzo="<?php echo htmlspecialchars($s['indirizzo']); ?>" data-citta="<?php echo htmlspecialchars($s['citta']); ?>" data-provincia="<?php echo htmlspecialchars($s['provincia']); ?>" data-cap="<?php echo htmlspecialchars($s['cap']); ?>" data-responsabile="<?php echo htmlspecialchars($s['responsabile']); ?>"><i class="bi bi-pencil"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa sede?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="sedi_action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Card Dati Anagrafici -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Dati Anagrafici</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3"><label>Nome Associazione</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($associazione['nome'] ?? ''); ?>"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Partita IVA</label><input type="text" name="partita_iva" class="form-control" value="<?php echo htmlspecialchars($associazione['partita_iva'] ?? ''); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Codice Fiscale</label><input type="text" name="codice_fiscale" class="form-control" value="<?php echo htmlspecialchars($associazione['codice_fiscale'] ?? ''); ?>"></div>
                    </div>
                    <div class="mb-3"><label>Indirizzo</label><input type="text" name="indirizzo" class="form-control" value="<?php echo htmlspecialchars($associazione['indirizzo'] ?? ''); ?>"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Città</label><input type="text" name="citta" class="form-control" value="<?php echo htmlspecialchars($associazione['citta'] ?? ''); ?>"></div>
                        <div class="col-md-6 col-lg-3 mb-3"><label>Provincia</label><input type="text" name="provincia" class="form-control" value="<?php echo htmlspecialchars($associazione['provincia'] ?? ''); ?>"></div>
                        <div class="col-md-6 col-lg-3 mb-3"><label>CAP</label><input type="text" name="cap" class="form-control" value="<?php echo htmlspecialchars($associazione['cap'] ?? ''); ?>"></div>
                    </div>
                     <div class="row">
                        <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($associazione['email'] ?? ''); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Telefono</label><input type="tel" name="telefono" class="form-control" value="<?php echo htmlspecialchars($associazione['telefono'] ?? ''); ?>"></div>
                    </div>
                    <button type="submit" name="update_info" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salva Dati Anagrafici</button>
                </form>
            </div>
        </div>

        <!-- Card Impostazioni Tesseramento -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Impostazioni Tesseramento e Quote</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label>Tipo Scadenza Predefinita</label>
                        <select name="tipo_scadenza_default" class="form-select">
                            <option value="solare" <?php echo ($associazione['tipo_scadenza_default'] == 'solare') ? 'selected' : ''; ?>>Anno Solare (31 Dicembre)</option>
                            <option value="annuale" <?php echo ($associazione['tipo_scadenza_default'] == 'annuale') ? 'selected' : ''; ?>>Annuale (365 giorni da iscrizione)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Giorni Preavviso Scadenza</label>
                        <input type="number" name="giorni_notifica_scadenza" class="form-control" value="<?php echo htmlspecialchars($associazione['giorni_notifica_scadenza'] ?? '30'); ?>">
                        <div class="form-text">Quanti giorni prima della scadenza inviare la notifica.</div>
                    </div>
                    <button type="submit" name="update_tesseramento" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Impostazioni Tesseramento</button>
                </form>
            </div>
        </div>

         <!-- Card Template Email -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Template Email Scadenza</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-text mb-2">Placeholder disponibili: {NOME_SOCIO}, {COGNOME_SOCIO}, {DATA_SCADENZA}, {IMPORTO_QUOTA}</div>
                    <textarea name="template_email_scadenza" class="form-control" rows="10"><?php echo htmlspecialchars($associazione['template_email_scadenza'] ?? 'Ciao {NOME_SOCIO}, ti ricordiamo che la tua quota scade il {DATA_SCADENZA}.'); ?></textarea>
                    <button type="submit" name="update_template" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Template</button>
                </form>
            </div>
        </div>

        <!-- Card Template Tessera per Tipo Socio -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Template Tessera (per Tipo Socio)</h5></div>
            <div class="card-body">
                <?php if (!$has_tessera_templates): ?>
                    <div class="alert alert-warning">
                        Funzionalità non installata. Esegui la migrazione del database per abilitare i template tessera.
                        <a href="migrate.php?confirm=yes" target="_blank" class="alert-link">Esegui migrazione</a>
                    </div>
                <?php else: ?>
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="page" value="configurazioni">
                    <div class="col-md-6">
                        <label class="form-label">Tipo Socio</label>
                        <select name="tipo_id" class="form-select">
                            <option value="">Default per tutti</option>
                            <?php foreach ($tipi_socio as $tipo): ?>
                                <option value="<?php echo htmlspecialchars($tipo['id']); ?>" <?php echo ($selected_tipo_id === $tipo['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-folder2-open me-1"></i>Carica</button>
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="save_tessera_template" value="1">
                    <input type="hidden" name="tipo_id" value="<?php echo htmlspecialchars($selected_tipo_id); ?>">
                    <div class="form-text mb-2">Placeholder disponibili: {ASSOCIAZIONE_NOME}, {ASSOCIAZIONE_CODICE_FISCALE}, {ASSOCIAZIONE_INDIRIZZO}, {NOME}, {COGNOME}, {NOME_COMPLETO}, {NUMERO_SOCIO}, {TIPO_SOCIO}, {CATEGORIA_SOCIO}, {NUMERO_TESSERA}, {ANNO_VALIDITA}, {DATA_EMISSIONE}, {DATA_SCADENZA}</div>
                    <textarea name="contenuto" class="form-control" rows="10"><?php echo htmlspecialchars($template_corrente ?: "Il/La sottoscritto/a {NOME_COMPLETO}, tessera n. {NUMERO_TESSERA}, è iscritto/a all'associazione {ASSOCIAZIONE_NOME} per l'anno {ANNO_VALIDITA}."); ?></textarea>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Template Tessera</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Logo Associazione</h5></div>
            <div class="card-body">
                <?php if (!empty($associazione['logo_url'])): ?>
                    <div class="mb-2"><img src="<?php echo htmlspecialchars($associazione['logo_url']); ?>" alt="Logo" style="max-width: 100%; height: auto; border:1px solid #ddd; padding:6px; border-radius:6px;"></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Carica nuovo logo (PNG/JPG, max 2MB)</label>
                        <input type="file" name="logo_file" class="form-control" accept="image/png,image/jpeg">
                    </div>
                    <button type="submit" name="upload_logo" class="btn btn-outline-primary">Carica Logo</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Altre Configurazioni</h5></div>
            <div class="list-group list-group-flush">
                <a href="index.php?page=sezioni" class="list-group-item list-group-item-action">Gestione Sedi</a>
                <a href="index.php?page=tipi-socio" class="list-group-item list-group-item-action">Gestione Tipi Socio</a>
                <a href="index.php?page=categorie-socio" class="list-group-item list-group-item-action">Gestione Categorie Socio</a>
                <a href="index.php?page=amministratori" class="list-group-item list-group-item-action">Gestione Utenti e Permessi</a>
            </div>
        </div>
    </div>
</div>
