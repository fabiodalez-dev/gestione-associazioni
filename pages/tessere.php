<?php
// pages/tessere.php - v2.0 (SaaS)

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}

$is_super_admin = ($_SESSION['user_role'] ?? '') === 'super_admin';
$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Assicura colonne costo tessera
ensureTesseraCostColumns($pdo);

// Carica configurazione associazione per tipo scadenza
$stmt_cfg = $pdo->prepare("SELECT tipo_scadenza_default FROM associazioni WHERE id = ? LIMIT 1");
$stmt_cfg->execute([$associazione_id]);
$tipo_scadenza_default = $stmt_cfg->fetchColumn() ?: 'solare';

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } elseif (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM tessere WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        $message = "Tessera eliminata.";
        $messageType = "success";

    } elseif (isset($_POST['generate_all'])) {
        $currentYear = $_POST['anno_validita'] ?? date('Y');
        $bulk_evento_id = !empty($_POST['evento_creazione_id']) ? $_POST['evento_creazione_id'] : null;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id FROM soci WHERE stato = 'Attivo' AND associazione_id = ? AND id NOT IN (SELECT socio_id FROM tessere WHERE anno_validita = ? AND associazione_id = ?)");
            $stmt->execute([$associazione_id, $currentYear, $associazione_id]);
            $soci_da_tesserare = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $generated_count = 0;
            foreach ($soci_da_tesserare as $socio_id) {
                $count_stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM tessere WHERE associazione_id = ? AND anno_validita = ?");
                $count_stmt->execute([$associazione_id, $currentYear]);
                $count = ((int)$count_stmt->fetch()['cnt']) + $generated_count + 1;
                $numero_tessera = $currentYear . str_pad((string)$count, 4, '0', STR_PAD_LEFT);

                $data_emissione = date('Y-m-d');
                $data_scadenza = ($tipo_scadenza_default === 'solare') ? $currentYear . '-12-31' : date('Y-m-d', strtotime('+1 year'));

                $insert_stmt = $pdo->prepare("INSERT INTO tessere (id, associazione_id, socio_id, numero_tessera, anno_validita, data_emissione, data_scadenza, tipo_scadenza, evento_creazione_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->execute([generateUuid(), $associazione_id, $socio_id, $numero_tessera, $currentYear, $data_emissione, $data_scadenza, $tipo_scadenza_default, $bulk_evento_id]);
                $generated_count++;
            }
            $pdo->commit();
            $message = "Generate $generated_count nuove tessere per l'anno $currentYear.";
            $messageType = "success";
        } catch (\Exception $e) {
            $pdo->rollBack();
            error_log('tessere.php generate_all error: ' . $e->getMessage());
            $message = "Errore durante la generazione delle tessere.";
            $messageType = "danger";
        }

    } else { // Aggiunta o Modifica
        $id = $_POST['id'] ?? null;
        $socio_id = $_POST['socio_id'];
        // Verifica che il socio appartenga all'associazione corrente
        $check_socio = $pdo->prepare("SELECT id FROM soci WHERE id = ? AND associazione_id = ?");
        $check_socio->execute([$socio_id, $associazione_id]);
        if (!$check_socio->fetch()) {
            $message = "Errore: socio non valido per questa associazione.";
            $messageType = "danger";
        } else {
        $numero_tessera = cleanInput($_POST['numero_tessera'] ?? '');
        $anno_validita = $_POST['anno_validita'];
        $data_emissione = $_POST['data_emissione'];
        $data_scadenza = $_POST['data_scadenza'];
        $stato = $_POST['stato'];
        // Forziamo il tipo scadenza a quello dell'associazione
        $tipo_scadenza = $tipo_scadenza_default;
        // Se non fornita, calcoliamo la data scadenza coerente con il tipo
        if (empty($data_scadenza)) {
            if ($tipo_scadenza === 'annuale') {
                $data_scadenza = date('Y-m-d', strtotime($data_emissione . ' +1 year'));
            } else {
                $data_scadenza = $anno_validita . '-12-31';
            }
        }

        $evento_creazione_id = !empty($_POST['evento_creazione_id']) ? $_POST['evento_creazione_id'] : null;

        if ($id) {
            $stmt = $pdo->prepare("UPDATE tessere SET socio_id=?, numero_tessera=?, anno_validita=?, data_emissione=?, data_scadenza=?, stato=?, tipo_scadenza=?, evento_creazione_id=? WHERE id=? AND associazione_id=?");
            $stmt->execute([$socio_id, $numero_tessera, $anno_validita, $data_emissione, $data_scadenza, $stato, $tipo_scadenza, $evento_creazione_id, $id, ($associazione_id)]);
            $message = "Tessera aggiornata.";
        } else {
            $new_id = generateUuid();
            $stmt = $pdo->prepare("INSERT INTO tessere (id, associazione_id, socio_id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato, tipo_scadenza, evento_creazione_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$new_id, ($associazione_id), $socio_id, $numero_tessera, $anno_validita, $data_emissione, $data_scadenza, $stato, $tipo_scadenza, $evento_creazione_id]);
            $message = "Tessera creata.";

            // Best-effort: queue rinnovo_tessera email
            try {
                require_once __DIR__ . '/../includes/EmailService.php';
                require_once __DIR__ . '/../includes/email_helpers.php';
                $emailSvc = new EmailService($pdo, $associazione_id);
                $smtpCfg = $emailSvc->loadSmtpConfig();
                if ($emailSvc->isConfigured() && $smtpCfg !== null && !empty($smtpCfg['auto_rinnovo_tessera'])) {
                    $tpl = $emailSvc->getTemplate('rinnovo_tessera');
                    if ($tpl !== null && !empty($tpl['attivo'])) {
                        $ph = buildPlaceholderValues($pdo, $associazione_id, $socio_id, [
                            'NUMERO_TESSERA' => $numero_tessera,
                            'ANNO_VALIDITA' => $anno_validita,
                            'DATA_SCADENZA' => $data_scadenza,
                        ]);
                        $rendered = $emailSvc->renderTemplate('rinnovo_tessera', $ph);
                        if ($rendered !== null) {
                            $socioStmt = $pdo->prepare('SELECT nome, cognome, email FROM soci WHERE id = ? AND associazione_id = ?');
                            $socioStmt->execute([$socio_id, $associazione_id]);
                            $socioRow = $socioStmt->fetch();
                            if ($socioRow && !empty($socioRow['email'])) {
                                $emailSvc->queueEmail(
                                    $socioRow['email'],
                                    $socioRow['nome'] . ' ' . $socioRow['cognome'],
                                    $rendered['subject'], $rendered['body'],
                                    $socio_id, generateUuid(), 'rinnovo_tessera', 3
                                );
                            }
                        }
                    }
                }
            } catch (\Throwable $emailErr) {
                error_log('tessere.php email rinnovo error: ' . $emailErr->getMessage());
            }
        }
        $messageType = "success";
        }
    }
}

// Recupero Dati
$editingTessera = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tessere WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingTessera = $stmt->fetch();
}

// Filtri
$anno_filter = $_GET['anno'] ?? date('Y');
$searchTerm = $_GET['search'] ?? '';
$stato_filter = $_GET['stato'] ?? 'all';

$stmt_soci = $pdo->prepare("SELECT id, CONCAT(cognome, ' ', nome) as nome_completo FROM soci WHERE associazione_id = ? AND stato = 'Attivo' ORDER BY cognome, nome");
$stmt_soci->execute([$associazione_id]);
$soci_attivi = $stmt_soci->fetchAll();

// Carica eventi per dropdown evento_creazione
$stmt_eventi = $pdo->prepare("SELECT id, titolo, data_evento FROM eventi WHERE associazione_id = ? ORDER BY data_evento DESC");
$stmt_eventi->execute([$associazione_id]);
$eventi_disponibili = $stmt_eventi->fetchAll();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
$anno_filter = $_GET['anno'] ?? date('Y');
$searchTerm = $_GET['search'] ?? '';
$stato_filter = $_GET['stato'] ?? 'all';

// Use the new filter variables
if (!empty($searchTerm)) {
    $q = $searchTerm;
}
if ($stato_filter !== 'all') {
    $status = $stato_filter;
}

$sql = "SELECT t.*, s.nome, s.cognome, s.numero_socio, a.nome AS associazione_nome, ts.nome AS tipo_socio,
               ev.titolo AS evento_titolo
        FROM tessere t
        JOIN soci s ON t.socio_id = s.id
        LEFT JOIN associazioni a ON a.id = t.associazione_id
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN eventi ev ON t.evento_creazione_id = ev.id
        WHERE 1=1";
$params = [];

$sql .= " AND t.associazione_id = ?";
$params[] = $associazione_id;

// Filtro per anno (nuovo approccio)
$sql .= " AND t.anno_validita = ?";
$params[] = $anno_filter;

if ($q !== '') {
    $sql .= " AND (
        s.nome LIKE ? OR s.cognome LIKE ? OR
        CONCAT(s.cognome, ' ', s.nome) LIKE ? OR CONCAT(s.nome, ' ', s.cognome) LIKE ? OR
        s.numero_socio LIKE ? OR t.numero_tessera LIKE ?
    )";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

// Filtro stato
if ($status !== 'all') {
    if ($status === 'Scaduta') {
        $sql .= " AND (t.stato = 'Scaduta' OR (t.data_scadenza IS NOT NULL AND t.data_scadenza < CURDATE()))";
    } else {
        $sql .= " AND t.stato = ?";
        $params[] = $status;
    }
}
$sql .= " ORDER BY t.anno_validita DESC, s.cognome ASC";
$stmt_tessere = $pdo->prepare($sql);
$stmt_tessere->execute($params);
$tessere = $stmt_tessere->fetchAll();

// AJAX response mode — return only the results HTML + count as JSON
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    if (empty($tessere)): ?>
<div class="table-empty">
    <i class="bi bi-credit-card"></i>
    <h5>Nessuna tessera trovata</h5>
    <p class="text-muted">Non ci sono tessere che corrispondono ai criteri di ricerca.</p>
</div>
<?php else: ?>
<div class="responsive-table-wrapper">
    <table class="table-desktop">
        <thead>
            <tr>
                <th>Socio</th>
                <th>Associazione</th>
                <th>N. Tessera</th>
                <th>Anno</th>
                <th>Scadenza</th>
                <th>Stato</th>
                <th>Evento</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tessere as $t):
            $status = $t['stato'];
            $badge_class = 'secondary';
            if ($status === 'Attiva') {
                if (!empty($t['data_scadenza']) && strtotime($t['data_scadenza']) < time()) {
                    $status = 'Scaduta'; $badge_class = 'danger';
                } else { $badge_class = 'success'; }
            } elseif ($status === 'Scaduta') { $badge_class = 'danger'; }
            elseif ($status === 'Sospesa') { $badge_class = 'warning'; }
        ?>
            <tr>
                <td>
                    <div><?php echo htmlspecialchars($t['cognome'] . ' ' . $t['nome']); ?></div>
                    <small class="text-muted font-monospace"><?php echo htmlspecialchars($t['numero_socio']); ?></small>
                </td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($t['associazione_nome'] ?? ''); ?></span></td>
                <td>
                    <div class="font-monospace"><?php echo htmlspecialchars($t['numero_tessera']); ?></div>
                    <?php if (!empty($t['template_tessera'])): ?><small class="text-muted"><?php echo htmlspecialchars($t['template_tessera']); ?></small><?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($t['anno_validita']); ?></td>
                <td>
                    <?php if (!empty($t['data_scadenza'])): ?>
                        <div><?php echo date('d/m/Y', strtotime($t['data_scadenza'])); ?></div>
                        <?php
                        $scad = strtotime($t['data_scadenza']); $today = strtotime(date('Y-m-d'));
                        $soon = strtotime("+30 days", $today);
                        if ($scad < $today): ?><span class="badge bg-danger">Scaduta</span>
                        <?php elseif ($scad <= $soon): ?><span class="badge bg-warning text-dark">In scadenza</span><?php endif; ?>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td><?php if (!empty($t['evento_titolo'])): ?><span class="badge bg-info text-dark"><?php echo htmlspecialchars($t['evento_titolo']); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="text-end">
                    <a href="index.php?page=genera-tessera-pdf&tessera_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-success" title="Genera PDF" target="_blank"><i class="bi bi-file-pdf"></i></a>
                    <a href="index.php?page=tessere&edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifica"><i class="bi bi-pencil"></i></a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa tessera?')">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="delete_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="table-mobile">
        <?php foreach ($tessere as $t):
            $status = $t['stato']; $badge_class = 'secondary';
            if ($status === 'Attiva') {
                if (!empty($t['data_scadenza']) && strtotime($t['data_scadenza']) < time()) { $status = 'Scaduta'; $badge_class = 'danger'; }
                else { $badge_class = 'success'; }
            } elseif ($status === 'Scaduta') { $badge_class = 'danger'; }
            elseif ($status === 'Sospesa') { $badge_class = 'warning'; }
        ?>
        <div class="table-card">
            <div class="card-header-section">
                <div class="card-primary-info">
                    <h5 class="card-title"><?php echo htmlspecialchars($t['cognome'] . ' ' . $t['nome']); ?></h5>
                    <div class="card-subtitle"><?php echo htmlspecialchars($t['numero_socio']); ?></div>
                </div>
                <div class="card-status"><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span></div>
            </div>
            <div class="card-content">
                <div class="card-field"><span class="field-label">N. Tessera</span><span class="field-value font-monospace"><?php echo htmlspecialchars($t['numero_tessera']); ?></span></div>
                <div class="card-field"><span class="field-label">Anno</span><span class="field-value"><?php echo htmlspecialchars($t['anno_validita']); ?></span></div>
                <div class="card-field"><span class="field-label">Scadenza</span><span class="field-value"><?php echo !empty($t['data_scadenza']) ? date('d/m/Y', strtotime($t['data_scadenza'])) : '—'; ?></span></div>
                <?php if (!empty($t['evento_titolo'])): ?>
                <div class="card-field"><span class="field-label">Evento</span><span class="field-value"><span class="badge bg-info text-dark"><?php echo htmlspecialchars($t['evento_titolo']); ?></span></span></div>
                <?php endif; ?>
            </div>
            <div class="card-actions">
                <a href="index.php?page=genera-tessera-pdf&tessera_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-success" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
                <a href="index.php?page=tessere&edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifica</a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa tessera?')">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="delete_id" value="<?php echo $t['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif;
    $html = ob_get_clean();
    echo json_encode(['count' => count($tessere), 'html' => $html]);
    exit;
}

// Aggregazioni per grafici (per tipo tessera e ricavi)
$agg_sql = "SELECT COALESCE(ts.nome, t.template_tessera, 'Default') AS tipo,
                   COUNT(*) AS cnt,
                   SUM(COALESCE(ts.costo_tessera, a.costo_tessera, 0)) AS ricavi
            FROM tessere t
            JOIN soci s ON t.socio_id = s.id
            LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
            JOIN associazioni a ON a.id = t.associazione_id
            WHERE t.anno_validita = ?";

$agg_params = [$anno_filter];

if ($associazione_id) { 
    $agg_sql .= " AND t.associazione_id = ?"; 
    $agg_params[] = $associazione_id; 
}

if ($q !== '') {
    $agg_sql .= " AND (s.nome LIKE ? OR s.cognome LIKE ? OR CONCAT(s.cognome, ' ', s.nome) LIKE ? OR CONCAT(s.nome, ' ', s.cognome) LIKE ? OR s.numero_socio LIKE ? OR t.numero_tessera LIKE ?)";
    $like = "%$q%";
    array_push($agg_params, $like, $like, $like, $like, $like, $like);
}

if ($status !== 'all') {
    if ($status === 'Scaduta') {
        $agg_sql .= " AND (t.stato = 'Scaduta' OR (t.data_scadenza IS NOT NULL AND t.data_scadenza < CURDATE()))";
    } else {
        $agg_sql .= " AND t.stato = ?"; 
        $agg_params[] = $status;
    }
}
$agg_sql .= " GROUP BY tipo ORDER BY cnt DESC";
$stmt_agg = $pdo->prepare($agg_sql);
$stmt_agg->execute($agg_params);
$agg_rows = $stmt_agg->fetchAll();
$chart_labels = array_map(fn($r)=>$r['tipo'], $agg_rows);
$chart_counts = array_map(fn($r)=> (int)$r['cnt'], $agg_rows);
$chart_revenue = array_map(fn($r)=> (int)round((float)$r['ricavi']), $agg_rows);

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <div>
        <h1 class="h2">Gestione Tessere</h1>
        <p class="text-muted">Gestisci le tessere annuali dei soci con strumenti avanzati</p>
    </div>
    <span class="badge bg-primary" id="tessereCount"><?php echo count($tessere); ?> <?php echo count($tessere) === 1 ? 'tessera' : 'tessere'; ?></span>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Filtri -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtri di Ricerca</h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3" id="tessereFiltersForm" data-ajax-filter>
            <input type="hidden" name="page" value="tessere">
            
            <div class="col-md-6 col-lg-3">
                <label class="form-label fw-semibold">
                    <i class="bi bi-calendar me-1"></i>Anno
                </label>
                <select class="form-select" name="anno">
                    <?php 
                    // Get available years
                    $years_stmt = $pdo->prepare("SELECT DISTINCT anno_validita FROM tessere WHERE associazione_id = ? ORDER BY anno_validita DESC");
                    $years_stmt->execute([$associazione_id]);
                    $available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Add current year if not present
                    $current_year = date('Y');
                    if (!in_array($current_year, $available_years)) {
                        $available_years[] = $current_year;
                        rsort($available_years);
                    }
                    
                    foreach ($available_years as $year): ?>
                        <option value="<?php echo $year; ?>" <?php echo ($anno_filter == $year) ? 'selected' : ''; ?>><?php echo $year; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-6 col-lg-3">
                <label class="form-label fw-semibold">
                    <i class="bi bi-search me-1"></i>Cerca
                </label>
                <input type="text" class="form-control" name="search" placeholder="Nome socio..." value="<?php echo htmlspecialchars($searchTerm); ?>">
            </div>
            
            <div class="col-md-6 col-lg-3">
                <label class="form-label fw-semibold">
                    <i class="bi bi-funnel me-1"></i>Stato
                </label>
                <select class="form-select" name="stato">
                    <option value="all" <?php echo ($stato_filter === 'all') ? 'selected' : ''; ?>>Tutti gli stati</option>
                    <option value="Attiva" <?php echo ($stato_filter === 'Attiva') ? 'selected' : ''; ?>>Attiva</option>
                    <option value="Scaduta" <?php echo ($stato_filter === 'Scaduta') ? 'selected' : ''; ?>>Scaduta</option>
                    <option value="Sospesa" <?php echo ($stato_filter === 'Sospesa') ? 'selected' : ''; ?>>Sospesa</option>
                    <option value="Annullata" <?php echo ($stato_filter === 'Annullata') ? 'selected' : ''; ?>>Annullata</option>
                </select>
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex gap-2">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tesseraModal"><i class="bi bi-plus-lg"></i> Nuova Tessera</button>
            <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#bulkGenerateModal"><i class="bi bi-magic"></i> Genera Tessere <?php echo date('Y'); ?></button>
    </div>
</div>

<div id="tessereResults">
<div class="responsive-table-wrapper">
    <!-- Desktop Table View -->
    <table class="table-desktop">
        <thead>
            <tr>
                <th>Socio</th>
                <th>Associazione</th>
                <th>N. Tessera</th>
                <th>Anno</th>
                <th>Scadenza</th>
                <th>Stato</th>
                <th>Evento</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tessere as $t):
            $status = $t['stato'];
            $badge_class = 'secondary';
            if ($status === 'Attiva') {
                if (!empty($t['data_scadenza']) && strtotime($t['data_scadenza']) < time()) {
                    $status = 'Scaduta';
                    $badge_class = 'danger';
                } else {
                    $badge_class = 'success';
                }
            } elseif ($status === 'Scaduta') {
                $badge_class = 'danger';
            } elseif ($status === 'Sospesa') {
                $badge_class = 'warning';
            } else {
                $badge_class = 'secondary';
            }
        ?>
            <tr>
                <td>
                    <div><?php echo htmlspecialchars($t['cognome'] . ' ' . $t['nome']); ?></div>
                    <small class="text-muted font-monospace"><?php echo htmlspecialchars($t['numero_socio']); ?></small>
                </td>
                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($t['associazione_nome'] ?? ''); ?></span></td>
                <td>
                    <div class="font-monospace"><?php echo htmlspecialchars($t['numero_tessera']); ?></div>
                    <?php if (!empty($t['template_tessera'])): ?>
                        <small class="text-muted"><?php echo htmlspecialchars($t['template_tessera']); ?></small>
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($t['anno_validita']); ?></td>
                <td>
                    <?php if (!empty($t['data_scadenza'])): ?>
                        <div><?php echo date('d/m/Y', strtotime($t['data_scadenza'])); ?></div>
                        <?php 
                        $scad = strtotime($t['data_scadenza']);
                        $today = strtotime(date('Y-m-d'));
                        $daysNotice = 30;
                        $soon = strtotime("+$daysNotice days", $today);
                        if ($scad < $today): ?>
                            <span class="badge bg-danger">Scaduta</span>
                        <?php elseif ($scad <= $soon): ?>
                            <span class="badge bg-warning text-dark">In scadenza</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td><?php if (!empty($t['evento_titolo'])): ?><span class="badge bg-info text-dark"><?php echo htmlspecialchars($t['evento_titolo']); ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                <td class="text-end">
                    <a href="index.php?page=genera-tessera-pdf&tessera_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-success" title="Genera PDF" target="_blank"><i class="bi bi-file-pdf"></i></a>
                    <a href="index.php?page=tessere&edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary" title="Modifica"><i class="bi bi-pencil"></i></a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa tessera?')">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="delete_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Mobile Card View -->
    <div class="table-mobile">
        <?php foreach ($tessere as $t):
            $status = $t['stato'];
            $badge_class = 'secondary';
            if ($status === 'Attiva') {
                if (!empty($t['data_scadenza']) && strtotime($t['data_scadenza']) < time()) {
                    $status = 'Scaduta';
                    $badge_class = 'danger';
                } else {
                    $badge_class = 'success';
                }
            } elseif ($status === 'Scaduta') {
                $badge_class = 'danger';
            } elseif ($status === 'Sospesa') {
                $badge_class = 'warning';
            } else {
                $badge_class = 'secondary';
            }
        ?>
        <div class="table-card">
            <div class="card-header-section">
                <div class="card-primary-info">
                    <h5 class="card-title"><?php echo htmlspecialchars($t['cognome'] . ' ' . $t['nome']); ?></h5>
                    <div class="card-subtitle"><?php echo htmlspecialchars($t['numero_socio']); ?></div>
                </div>
                <div class="card-status">
                    <span class="badge bg-<?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span>
                </div>
            </div>
            <div class="card-content">
                <div class="card-field"><span class="field-label">N. Tessera</span><span class="field-value font-monospace"><?php echo htmlspecialchars($t['numero_tessera']); ?></span></div>
                <div class="card-field"><span class="field-label">Anno</span><span class="field-value"><?php echo htmlspecialchars($t['anno_validita']); ?></span></div>
                <?php if (!empty($t['template_tessera'])): ?>
                <div class="card-field"><span class="field-label">Tipo</span><span class="field-value"><?php echo htmlspecialchars($t['template_tessera']); ?></span></div>
                <?php endif; ?>
                <div class="card-field"><span class="field-label">Scadenza</span><span class="field-value"><?php echo !empty($t['data_scadenza']) ? date('d/m/Y', strtotime($t['data_scadenza'])) : '—'; ?></span></div>
                <?php if (!empty($t['evento_titolo'])): ?>
                <div class="card-field"><span class="field-label">Evento</span><span class="field-value"><span class="badge bg-info text-dark"><?php echo htmlspecialchars($t['evento_titolo']); ?></span></span></div>
                <?php endif; ?>
                <?php if (!empty($t['associazione_nome'])): ?>
                <div class="card-field"><span class="field-label">Associazione</span><span class="field-value"><span class="badge bg-secondary"><?php echo htmlspecialchars($t['associazione_nome']); ?></span></span></div>
                <?php endif; ?>
            </div>
            <div class="card-actions">
                <a href="index.php?page=genera-tessera-pdf&tessera_id=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-success" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
                <a href="index.php?page=tessere&edit=<?php echo $t['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifica</a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa tessera?')">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="delete_id" value="<?php echo $t['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</div><!-- /tessereResults -->

<div class="row mt-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5>Tesserati per Tipo</h5></div>
            <div class="card-body"><canvas id="tessereCountChart" height="140"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6 mt-3 mt-lg-0">
        <div class="card">
            <div class="card-header"><h5>Ricavi per Tipo</h5></div>
            <div class="card-body"><canvas id="tessereRevenueChart" height="140"></canvas></div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        const labels = <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>;
        const counts = <?php echo json_encode($chart_counts); ?>;
        const revenue = <?php echo json_encode($chart_revenue); ?>;

        const ctxCount = document.getElementById('tessereCountChart').getContext('2d');
        new Chart(ctxCount, {
            type: 'bar',
            data: { labels, datasets: [{ label: 'Tesserati', data: counts, backgroundColor: '#0d6efd' }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: (v)=> Math.trunc(v) } } } }
        });

        const ctxRev = document.getElementById('tessereRevenueChart').getContext('2d');
        new Chart(ctxRev, {
            type: 'bar',
            data: { labels, datasets: [{ label: 'Ricavi (€)', data: revenue, backgroundColor: '#20c997' }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: (v)=> Math.trunc(v) } } } }
        });
    </script>
</div>

<script>
(function() {
    const form = document.getElementById('tessereFiltersForm');
    if (!form) return;

    const resultsContainer = document.getElementById('tessereResults');
    const countBadge = document.getElementById('tessereCount');
    let debounceTimer = null;

    // Prevent normal form submit
    form.addEventListener('submit', function(e) { e.preventDefault(); fetchTessere(); });

    // Debounced search input
    const searchInput = form.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchTessere, 300);
        });
    }

    // Instant change on all selects
    form.querySelectorAll('select').forEach(function(sel) {
        sel.addEventListener('change', fetchTessere);
    });

    function fetchTessere() {
        const data = new FormData(form);
        const params = new URLSearchParams();
        for (const [key, value] of data.entries()) {
            params.append(key, value);
        }
        params.set('ajax', '1');

        // The HTML is server-rendered with htmlspecialchars() on all outputs,
        // same code path as the full page load, from the same origin.
        fetch('index.php?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (resultsContainer) resultsContainer.innerHTML = json.html; // same-origin server-rendered HTML
                if (countBadge) countBadge.textContent = json.count + ' ' + (json.count === 1 ? 'tessera' : 'tessere');

                // Update browser URL (without ajax param)
                params.delete('ajax');
                history.replaceState(null, '', 'index.php?' + params.toString());
            })
            .catch(function(err) { console.error('AJAX filter error:', err); });
    }
})();
</script>

<!-- Modal -->
<div class="modal fade" id="tesseraModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingTessera ? 'Modifica' : 'Crea'; ?> Tessera</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingTessera['id'] ?? ''; ?>">
            <div class="mb-3">
                <label>Socio</label>
                <select name="socio_id" class="form-select" required>
                    <?php foreach ($soci_attivi as $socio): ?>
                    <option value="<?php echo $socio['id']; ?>" <?php echo ($editingTessera['socio_id'] ?? '') == $socio['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($socio['nome_completo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label>Numero Tessera</label><input type="text" name="numero_tessera" class="form-control" value="<?php echo escapeOutput($editingTessera['numero_tessera'] ?? ('' . date('Y') . '0001')); ?>" required></div>
            <div class="row">
                <div class="col-md-6 mb-3"><label>Anno Validità</label><input type="number" name="anno_validita" class="form-control" value="<?php echo $editingTessera['anno_validita'] ?? date('Y'); ?>" required></div>
                <div class="col-md-6 mb-3">
                    <label>Tipo Scadenza</label>
                    <div class="form-control-plaintext">
                        <span class="badge bg-secondary text-uppercase"><?php echo htmlspecialchars($tipo_scadenza_default); ?></span>
                        <input type="hidden" name="tipo_scadenza" value="<?php echo htmlspecialchars($tipo_scadenza_default); ?>">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label>Data Emissione</label><input type="date" name="data_emissione" class="form-control" value="<?php echo htmlspecialchars($editingTessera['data_emissione'] ?? date('Y-m-d')); ?>" required></div>
                <div class="col-md-6 mb-3"><label>Data Scadenza</label><input type="date" name="data_scadenza" class="form-control" value="<?php echo htmlspecialchars($editingTessera['data_scadenza'] ?? date('Y') . '-12-31'); ?>" required></div>
            </div>
            <div class="mb-3"><label>Stato</label><select name="stato" class="form-select">
                <option value="Attiva" <?php echo ($editingTessera['stato'] ?? '') === 'Attiva' ? 'selected' : ''; ?>>Attiva</option>
                <option value="Sospesa" <?php echo ($editingTessera['stato'] ?? '') === 'Sospesa' ? 'selected' : ''; ?>>Sospesa</option>
                <option value="Annullata" <?php echo ($editingTessera['stato'] ?? '') === 'Annullata' ? 'selected' : ''; ?>>Annullata</option>
            </select></div>
            <div class="mb-3">
                <label>Evento di creazione <small class="text-muted">(opzionale)</small></label>
                <select name="evento_creazione_id" class="form-select">
                    <option value="">— Nessun evento —</option>
                    <?php foreach ($eventi_disponibili as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>" <?php echo ($editingTessera['evento_creazione_id'] ?? '') === $ev['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ev['titolo'] . ' (' . date('d/m/Y', strtotime($ev['data_evento'])) . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
        </div>
    </form>
</div>
</div>
</div>

<!-- Bulk Generate Modal -->
<div class="modal fade" id="bulkGenerateModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Genera Tessere <?php echo date('Y'); ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST" onsubmit="return confirm('Generare tessere per i soci attivi senza tessera?')">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="anno_validita" value="<?php echo date('Y'); ?>">
        <div class="modal-body">
            <p>Verranno generate tessere per tutti i soci attivi che non hanno ancora una tessera per l'anno <?php echo date('Y'); ?>.</p>
            <div class="mb-3">
                <label>Evento associato <small class="text-muted">(opzionale)</small></label>
                <select name="evento_creazione_id" class="form-select">
                    <option value="">— Nessun evento —</option>
                    <?php foreach ($eventi_disponibili as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>"><?php echo htmlspecialchars($ev['titolo'] . ' (' . date('d/m/Y', strtotime($ev['data_evento'])) . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" name="generate_all" class="btn btn-success"><i class="bi bi-magic me-1"></i>Genera Tessere</button>
        </div>
    </form>
</div>
</div>
</div>

<?php if ($editingTessera): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('tesseraModal')).show());</script>
<?php endif; ?>
