<?php
// pages/email_log.php — Email sending history with filters

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}

$associazione_id = $_SESSION['associazione_id'];

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterTemplate = $_GET['template'] ?? '';
$filterSearch = cleanInput($_GET['q'] ?? '');
$filterFrom = $_GET['from'] ?? '';
$filterTo = $_GET['to'] ?? '';

// Build query
$where = ['el.associazione_id = ?'];
$params = [$associazione_id];

if ($filterStatus && in_array($filterStatus, ['sent', 'failed'], true)) {
    $where[] = 'el.status = ?';
    $params[] = $filterStatus;
}
if ($filterTemplate) {
    $where[] = 'el.template_codice = ?';
    $params[] = $filterTemplate;
}
if ($filterSearch) {
    $where[] = '(el.to_email LIKE ? OR el.to_name LIKE ? OR el.subject LIKE ?)';
    $like = '%' . $filterSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filterFrom) {
    $where[] = 'DATE(el.sent_at) >= ?';
    $params[] = $filterFrom;
}
if ($filterTo) {
    $where[] = 'DATE(el.sent_at) <= ?';
    $params[] = $filterTo;
}

$whereClause = implode(' AND ', $where);

// Pagination
$page_num = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;
$offset = ($page_num - 1) * $perPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM email_log el WHERE $whereClause");
$stmtTotal->execute($params);
$totalItems = (int)$stmtTotal->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));

$sql = "SELECT el.*, s.nome as socio_nome, s.cognome as socio_cognome, u.nome as utente_nome, u.cognome as utente_cognome
        FROM email_log el
        LEFT JOIN soci s ON s.id = el.socio_id
        LEFT JOIN utenti u ON u.id = el.sent_by
        WHERE $whereClause
        ORDER BY el.sent_at DESC
        LIMIT $perPage OFFSET $offset";
$stmtLog = $pdo->prepare($sql);
$stmtLog->execute($params);
$logs = $stmtLog->fetchAll();

// Template codici for filter dropdown
$stmtCodici = $pdo->prepare('SELECT DISTINCT template_codice FROM email_log WHERE associazione_id = ? AND template_codice IS NOT NULL ORDER BY template_codice');
$stmtCodici->execute([$associazione_id]);
$templateCodici = $stmtCodici->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-clock-history me-2"></i>Storico Email</h1>
    <span class="badge bg-secondary"><?php echo $totalItems; ?> risultati</span>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="email-log">
            <div class="col-md-2">
                <label class="form-label">Stato</label>
                <select class="form-select form-select-sm" name="status">
                    <option value="">Tutti</option>
                    <option value="sent" <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>>Inviate</option>
                    <option value="failed" <?php echo $filterStatus === 'failed' ? 'selected' : ''; ?>>Fallite</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Template</label>
                <select class="form-select form-select-sm" name="template">
                    <option value="">Tutti</option>
                    <?php foreach ($templateCodici as $tc): ?>
                        <option value="<?php echo htmlspecialchars($tc); ?>" <?php echo $filterTemplate === $tc ? 'selected' : ''; ?>><?php echo htmlspecialchars($tc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Da</label>
                <input type="date" class="form-control form-control-sm" name="from" value="<?php echo htmlspecialchars($filterFrom); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">A</label>
                <input type="date" class="form-control form-control-sm" name="to" value="<?php echo htmlspecialchars($filterTo); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Cerca</label>
                <input type="text" class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Email, nome, oggetto...">
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- Log table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data</th>
                        <th>Destinatario</th>
                        <th>Oggetto</th>
                        <th>Template</th>
                        <th>Stato</th>
                        <th>Inviata da</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nessuna email trovata.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><small><?php echo date('d/m/Y H:i', strtotime($log['sent_at'])); ?></small></td>
                            <td>
                                <?php echo htmlspecialchars($log['to_email']); ?>
                                <?php if ($log['socio_nome']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($log['socio_nome'] . ' ' . $log['socio_cognome']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(mb_strimwidth($log['subject'], 0, 50, '...')); ?></td>
                            <td><?php echo $log['template_codice'] ? '<span class="badge bg-light text-dark">' . htmlspecialchars($log['template_codice']) . '</span>' : '-'; ?></td>
                            <td>
                                <span class="badge bg-<?php echo $log['status'] === 'sent' ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars($log['status']); ?></span>
                                <?php if ($log['error_message']): ?>
                                    <br><small class="text-danger"><?php echo htmlspecialchars(mb_strimwidth($log['error_message'], 0, 40, '...')); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?php echo $log['utente_nome'] ? htmlspecialchars($log['utente_nome'] . ' ' . $log['utente_cognome']) : 'Sistema'; ?></small></td>
                            <td>
                                <button class="btn btn-sm btn-outline-secondary" onclick="viewDetail('<?php echo htmlspecialchars($log['id']); ?>')" title="Dettagli"><i class="bi bi-eye"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3"><ul class="pagination justify-content-center">
    <?php
    $qsBase = http_build_query(array_filter(['page' => 'email-log', 'status' => $filterStatus, 'template' => $filterTemplate, 'q' => $filterSearch, 'from' => $filterFrom, 'to' => $filterTo]));
    for ($i = 1; $i <= $totalPages; $i++): ?>
        <li class="page-item <?php echo $i === $page_num ? 'active' : ''; ?>"><a class="page-link" href="index.php?<?php echo $qsBase; ?>&p=<?php echo $i; ?>"><?php echo $i; ?></a></li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<!-- Detail modal -->
<div class="modal fade" id="detailModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Dettaglio Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="detailBody">
        <div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>
    </div>
</div></div></div>

<script>
function viewDetail(logId) {
    var modal = new bootstrap.Modal(document.getElementById('detailModal'));
    var body = document.getElementById('detailBody');
    body.textContent = 'Caricamento...';
    modal.show();

    fetch('api/email_preview.php?log_id=' + encodeURIComponent(logId) + '&csrf_token=<?php echo generateCSRFToken(); ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                body.textContent = '';
                var dl = document.createElement('dl');
                dl.className = 'row mb-3';

                var fields = [
                    ['Data', data.sent_at],
                    ['Destinatario', data.to_email + (data.to_name ? ' (' + data.to_name + ')' : '')],
                    ['Oggetto', data.subject],
                    ['Template', data.template_codice || '-'],
                    ['Stato', data.status]
                ];
                fields.forEach(function(f) {
                    var dt = document.createElement('dt');
                    dt.className = 'col-sm-3';
                    dt.textContent = f[0];
                    var dd = document.createElement('dd');
                    dd.className = 'col-sm-9';
                    dd.textContent = f[1];
                    dl.appendChild(dt);
                    dl.appendChild(dd);
                });
                body.appendChild(dl);

                if (data.body_html) {
                    var iframe = document.createElement('iframe');
                    iframe.style.cssText = 'width:100%;height:400px;border:1px solid #ddd;border-radius:4px;';
                    iframe.setAttribute('sandbox', 'allow-same-origin');
                    body.appendChild(iframe);
                    var doc = iframe.contentDocument || iframe.contentWindow.document;
                    doc.open();
                    doc.write(data.body_html);
                    doc.close();
                }
            } else {
                body.textContent = data.message || 'Errore nel caricamento.';
            }
        })
        .catch(function() {
            body.textContent = 'Errore di rete.';
        });
}
</script>
