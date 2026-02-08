<?php
// pages/quote.php - v2.0 (SaaS)

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

// Funzione per determinare lo stato della quota dinamicamente
function getQuotaStatus($quota) {
    if (!empty($quota['data_pagamento'])) {
        return 'Pagata';
    }
    if (strtotime($quota['data_scadenza']) < time()) {
        return 'Scaduta';
    }
    if (strtotime($quota['data_scadenza']) < strtotime('+30 days')) {
        return 'In Scadenza';
    }
    return 'Da Pagare';
}

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } elseif (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM quote WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['delete_id'], $associazione_id]);
        $message = "Quota eliminata con successo.";
        $messageType = "success";
    } elseif (isset($_POST['pay_id'])) {
        $stmt = $pdo->prepare("UPDATE quote SET data_pagamento = ? WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_POST['payment_date'], $_POST['pay_id'], $associazione_id]);
        
        // Logga attività
        $stmt_socio = $pdo->prepare("SELECT socio_id, importo, anno FROM quote WHERE id = ?");
        $stmt_socio->execute([$_POST['pay_id']]);
        $quota_info = $stmt_socio->fetch();
        if ($quota_info) {
            logSocioActivity($pdo, $associazione_id, $quota_info['socio_id'], 'Pagamento Quota', "Pagata quota di €{$quota_info['importo']} per l'anno {$quota_info['anno']}.");
        }

        $message = "Pagamento registrato con successo.";
        $messageType = "success";

        // Best-effort: queue pagamento_quota email
        if ($quota_info) {
            try {
                require_once __DIR__ . '/../includes/EmailService.php';
                require_once __DIR__ . '/../includes/email_helpers.php';
                $emailSvc = new EmailService($pdo, $associazione_id);
                $smtpCfg = $emailSvc->loadSmtpConfig();
                if ($emailSvc->isConfigured() && $smtpCfg !== null && !empty($smtpCfg['auto_pagamento_quota'])) {
                    $tpl = $emailSvc->getTemplate('pagamento_quota');
                    if ($tpl !== null && !empty($tpl['attivo'])) {
                        $ph = buildPlaceholderValues($pdo, $associazione_id, $quota_info['socio_id'], [
                            'IMPORTO' => $quota_info['importo'],
                            'ANNO' => $quota_info['anno'],
                            'DATA_PAGAMENTO' => $_POST['payment_date'],
                        ]);
                        $rendered = $emailSvc->renderTemplate('pagamento_quota', $ph);
                        if ($rendered !== null) {
                            $socioStmt = $pdo->prepare('SELECT nome, cognome, email FROM soci WHERE id = ? AND associazione_id = ?');
                            $socioStmt->execute([$quota_info['socio_id'], $associazione_id]);
                            $socioRow = $socioStmt->fetch();
                            if ($socioRow && !empty($socioRow['email'])) {
                                $emailSvc->queueEmail(
                                    $socioRow['email'],
                                    $socioRow['nome'] . ' ' . $socioRow['cognome'],
                                    $rendered['subject'], $rendered['body'],
                                    $quota_info['socio_id'], generateUuid(), 'pagamento_quota', 3
                                );
                            }
                        }
                    }
                }
            } catch (\Throwable $emailErr) {
                error_log('quote.php email pagamento error: ' . $emailErr->getMessage());
            }
        }
    } else {
        $id = $_POST['id'] ?? null;
        $socio_id = $_POST['socio_id'];
        $anno = $_POST['anno'];
        $importo = $_POST['importo'];
        $data_scadenza = $_POST['data_scadenza'];
        $tipo = sanitizeInput($_POST['tipo']);

        if ($id) {
        $stmt = $pdo->prepare("UPDATE quote SET socio_id=?, anno=?, importo=?, data_scadenza=?, tipo=? WHERE id=? AND associazione_id=?");
        $stmt->execute([$socio_id, $anno, $importo, $data_scadenza, $tipo, $id, $associazione_id]);
            $message = "Quota aggiornata.";
        } else {
            $new_id = generateUuid();
            $stmt = $pdo->prepare("INSERT INTO quote (id, associazione_id, socio_id, anno, importo, data_scadenza, tipo) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$new_id, ($associazione_id), $socio_id, $anno, $importo, $data_scadenza, $tipo]);
            $message = "Quota creata.";
        }
        $messageType = "success";
    }
}

// Recupero Dati
$editingQuota = null;
$editingSocioName = '';
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT q.*, CONCAT(s.cognome, ' ', s.nome) AS socio_nome FROM quote q LEFT JOIN soci s ON q.socio_id = s.id WHERE q.id = ? AND q.associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingQuota = $stmt->fetch();
    if ($editingQuota) {
        $editingSocioName = $editingQuota['socio_nome'] ?? '';
    }
}

$payingQuota = null;
if (isset($_GET['pay'])) {
    $stmt = $pdo->prepare("SELECT q.*, s.nome, s.cognome FROM quote q JOIN soci s ON q.socio_id = s.id WHERE q.id = ? AND q.associazione_id = ?");
    $stmt->execute([$_GET['pay'], $associazione_id]);
    $payingQuota = $stmt->fetch();
}

$stmt_anni = $pdo->prepare("SELECT DISTINCT anno FROM quote WHERE associazione_id = ? ORDER BY anno DESC");
$stmt_anni->execute([$associazione_id]);
$availableYears = $stmt_anni->fetchAll(PDO::FETCH_COLUMN);

// Fetch e filtro quote
$statusFilter = $_GET['status'] ?? 'Tutti';
$yearFilter = $_GET['year'] ?? 'Tutti';

$sql = "SELECT q.*, s.nome, s.cognome, a.nome AS associazione_nome FROM quote q JOIN soci s ON q.socio_id = s.id JOIN associazioni a ON a.id = q.associazione_id WHERE 1=1";
$params = [];
$sql .= " AND q.associazione_id = ?"; $params[] = $associazione_id;

if ($yearFilter !== 'Tutti') {
    $sql .= " AND q.anno = ?";
    $params[] = $yearFilter;
}

$sql .= " ORDER BY q.data_scadenza DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all_quotes = $stmt->fetchAll();

$quote_filtrate = array_filter($all_quotes, function($q) use ($statusFilter) {
    if ($statusFilter === 'Tutti') return true;
    return getQuotaStatus($q) === $statusFilter;
});

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Quote e Pagamenti</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quotaModal"><i class="bi bi-plus-lg"></i> Aggiungi Quota</button>
</div>

<!-- Filtri -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-center">
            <input type="hidden" name="page" value="quote">
            <div class="col-md-6">
                <label class="form-label">Filtra per Stato</label>
                <div class="btn-group w-100 flex-wrap text-wrap filter-btn-group">
                    <?php
                    $stati = ['Tutti', 'Pagata', 'Da Pagare', 'In Scadenza', 'Scaduta'];
                    foreach ($stati as $stato) {
                        $active = ($statusFilter === $stato) ? 'active' : '';
                        echo "<a href=\"?page=quote&status=$stato&year=$yearFilter\" class=\"btn btn-outline-primary $active\" style=\"white-space:normal;\">$stato</a>";
                    }
                    ?>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Filtra per Anno</label>
                <select name="year" class="form-select" onchange="this.form.submit()">
                    <option value="Tutti">Tutti gli anni</option>
                    <?php foreach ($availableYears as $year): ?>
                    <option value="<?php echo $year; ?>" <?php echo ($yearFilter == $year) ? 'selected' : ''; ?>><?php echo $year; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- Elenco Quote: Desktop + Mobile -->
<div class="responsive-table-wrapper">
    <table class="table-desktop">
        <thead>
            <tr>
                <th>Socio</th><th>Associazione</th><th>Tipo</th><th>Anno</th><th>Importo</th><th>Scadenza</th><th>Stato</th><th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($quote_filtrate as $q):
            $status = getQuotaStatus($q);
            $badge_class = ['Pagata' => 'success', 'Da Pagare' => 'info', 'In Scadenza' => 'warning', 'Scaduta' => 'danger'][$status] ?? 'secondary';
        ?>
            <tr>
                <td><?php echo htmlspecialchars($q['cognome'] . ' ' . $q['nome']); ?></td>
                <td><?php echo htmlspecialchars($q['associazione_nome']); ?></td>
                <td><?php echo htmlspecialchars($q['tipo']); ?></td>
                <td><?php echo $q['anno']; ?></td>
                <td>€<?php echo number_format($q['importo'], 2, ',', '.'); ?></td>
                <td><?php echo date('d/m/Y', strtotime($q['data_scadenza'])); ?></td>
                <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo $status; ?></span></td>
                <td class="text-end">
                    <?php if ($status !== 'Pagata'): ?>
                    <a href="index.php?page=quote&pay=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i> Paga</a>
                    <?php endif; ?>
                    <a href="index.php?page=quote&edit=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa quota?')"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $q['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="table-mobile">
        <?php foreach ($quote_filtrate as $q):
            $status = getQuotaStatus($q);
            $badge_class = ['Pagata' => 'success', 'Da Pagare' => 'info', 'In Scadenza' => 'warning', 'Scaduta' => 'danger'][$status] ?? 'secondary';
        ?>
        <div class="table-card">
            <div class="card-header-section">
                <div class="card-primary-info">
                    <h5 class="card-title"><?php echo htmlspecialchars($q['cognome'] . ' ' . $q['nome']); ?></h5>
                    <div class="card-subtitle">€<?php echo number_format($q['importo'], 2, ',', '.'); ?></div>
                </div>
                <div class="card-status">
                    <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $status; ?></span>
                </div>
            </div>
            <div class="card-content">
                <div class="card-field"><span class="field-label">Associazione</span><span class="field-value"><span class="badge bg-secondary"><?php echo htmlspecialchars($q['associazione_nome']); ?></span></span></div>
                <div class="card-field"><span class="field-label">Tipo</span><span class="field-value"><?php echo htmlspecialchars($q['tipo']); ?></span></div>
                <div class="card-field"><span class="field-label">Anno</span><span class="field-value"><?php echo $q['anno']; ?></span></div>
                <div class="card-field"><span class="field-label">Scadenza</span><span class="field-value"><?php echo date('d/m/Y', strtotime($q['data_scadenza'])); ?></span></div>
            </div>
            <div class="card-actions">
                <?php if ($status !== 'Pagata'): ?>
                <a href="index.php?page=quote&pay=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg me-1"></i>Paga</a>
                <?php endif; ?>
                <a href="index.php?page=quote&edit=<?php echo $q['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Modifica</a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa quota?')"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $q['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Elimina</button></form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Aggiunta/Modifica -->
<div class="modal fade" id="quotaModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingQuota ? 'Modifica' : 'Crea'; ?> Quota</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingQuota['id'] ?? ''; ?>">
            <div class="mb-3">
                <label>Socio</label>
                <div class="autocomplete-wrapper">
                    <input type="hidden" name="socio_id" id="quotaSocioId" value="<?php echo htmlspecialchars($editingQuota['socio_id'] ?? ''); ?>" required>
                    <input type="text" class="form-control" id="quotaSocioSearch" autocomplete="off" placeholder="Cerca socio per nome o cognome..." value="<?php echo htmlspecialchars($editingSocioName); ?>">
                    <div class="autocomplete-results" id="quotaSocioResults"></div>
                </div>
            </div>
            <div class="row"><div class="col-md-6 mb-3"><label>Anno</label><input type="number" name="anno" class="form-control" value="<?php echo $editingQuota['anno'] ?? date('Y'); ?>" required></div><div class="col-md-6 mb-3"><label>Importo (€)</label><input type="number" step="0.01" name="importo" class="form-control" value="<?php echo $editingQuota['importo'] ?? '50.00'; ?>" required></div></div>
            <div class="mb-3"><label>Data Scadenza</label><input type="date" name="data_scadenza" class="form-control" value="<?php echo htmlspecialchars($editingQuota['data_scadenza'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Tipo</label><input type="text" name="tipo" class="form-control" value="<?php echo htmlspecialchars($editingQuota['tipo'] ?? 'Quota Associativa'); ?>" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
        </div>
    </form>
</div></div>
</div>

<!-- Modal Pagamento -->
<?php if ($payingQuota): ?>
<div class="modal fade" id="paymentModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Registra Pagamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="pay_id" value="<?php echo $payingQuota['id']; ?>">
            <p><strong>Socio:</strong> <?php echo htmlspecialchars($payingQuota['cognome'] . ' ' . $payingQuota['nome']); ?></p>
            <p><strong>Importo:</strong> €<?php echo number_format($payingQuota['importo'], 2, ",", "."); ?></p>
            <div class="mb-3"><label>Data Pagamento</label><input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-success">Conferma Pagamento</button>
        </div>
    </form>
</div></div>
</div>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('paymentModal')).show());</script>
<?php endif; ?>

<script>
(function() {
    const searchInput = document.getElementById('quotaSocioSearch');
    const hiddenInput = document.getElementById('quotaSocioId');
    const resultsDiv = document.getElementById('quotaSocioResults');
    if (!searchInput || !hiddenInput || !resultsDiv) return;

    let debounceTimer = null;

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 1) {
            resultsDiv.classList.remove('show');
            return;
        }
        debounceTimer = setTimeout(function() {
            fetch('api/soci_search.php?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    resultsDiv.textContent = '';
                    if (!Array.isArray(data) || data.length === 0) {
                        var noRes = document.createElement('div');
                        noRes.className = 'autocomplete-no-results';
                        noRes.textContent = 'Nessun socio trovato';
                        resultsDiv.appendChild(noRes);
                        resultsDiv.classList.add('show');
                        return;
                    }
                    data.forEach(function(item) {
                        var div = document.createElement('div');
                        div.className = 'autocomplete-item';
                        div.textContent = item.nome_completo;
                        div.addEventListener('mousedown', function(e) {
                            e.preventDefault();
                            hiddenInput.value = item.id;
                            searchInput.value = item.nome_completo;
                            resultsDiv.classList.remove('show');
                        });
                        resultsDiv.appendChild(div);
                    });
                    resultsDiv.classList.add('show');
                })
                .catch(function() { resultsDiv.classList.remove('show'); });
        }, 300);
    });

    searchInput.addEventListener('blur', function() {
        setTimeout(function() { resultsDiv.classList.remove('show'); }, 200);
    });

    searchInput.addEventListener('focus', function() {
        if (resultsDiv.childElementCount > 0) resultsDiv.classList.add('show');
    });

    // Clear hidden value if user clears the text
    searchInput.addEventListener('change', function() {
        if (this.value.trim() === '') {
            hiddenInput.value = '';
        }
    });

    // Form validation: ensure socio is selected
    var quotaForm = searchInput.closest('form');
    if (quotaForm) {
        quotaForm.addEventListener('submit', function(e) {
            if (!hiddenInput.value) {
                e.preventDefault();
                searchInput.classList.add('is-invalid');
                searchInput.focus();
            } else {
                searchInput.classList.remove('is-invalid');
            }
        });
    }
})();
</script>

<?php if ($editingQuota): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('quotaModal')).show());</script>
<?php endif; ?>
