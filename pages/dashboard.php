<?php
// pages/dashboard.php - v2.0 (SaaS)

// Assicurati che l'utente sia loggato; per super_admin consenti accesso anche senza associazione selezionata
if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

$is_super_admin = ($_SESSION['user_role'] ?? '') === 'super_admin';
$associazione_id = $_SESSION['associazione_id'] ?? null;
$has_assoc = !empty($associazione_id);

// Super admin: switch associazione (dal sidebar link)
if ($is_super_admin && isset($_GET['switch_assoc'])) {
    unset($_SESSION['associazione_id'], $_SESSION['associazione_nome']);
    redirect('index.php?page=dashboard');
}

// Super admin: gestione cambio associazione dal dashboard
if ($is_super_admin && isset($_GET['assoc_id'])) {
    $assocParam = $_GET['assoc_id'];
    if ($assocParam === 'all' || $assocParam === '') {
        unset($_SESSION['associazione_id'], $_SESSION['associazione_nome']);
        redirect('index.php?page=dashboard');
    } else {
        $stmt = $pdo->prepare("SELECT nome FROM associazioni WHERE id = ? LIMIT 1");
        $stmt->execute([$assocParam]);
        if ($row = $stmt->fetch()) {
            $_SESSION['associazione_id'] = $assocParam;
            $_SESSION['associazione_nome'] = $row['nome'];
            redirect('index.php?page=dashboard');
        }
    }
}
$stats = [];

try {
    if (!$has_assoc && $is_super_admin) {
        // Panoramica globale su tutte le associazioni
        $stats['total_soci'] = (int)$pdo->query("SELECT COUNT(*) FROM soci")->fetchColumn();
        $stats['soci_attivi'] = (int)$pdo->query("SELECT COUNT(*) FROM soci WHERE stato = 'Attivo'")->fetchColumn();
        $stats['eventi_mese'] = (int)$pdo->query("SELECT COUNT(*) FROM eventi WHERE MONTH(data_evento) = MONTH(CURDATE()) AND YEAR(data_evento) = YEAR(CURDATE())")->fetchColumn();
        $stats['quote_pagate'] = (int)$pdo->query("SELECT COUNT(*) FROM quote WHERE stato = 'Pagata' AND anno = YEAR(CURDATE())")->fetchColumn();
        $stats['scadenze_imminenti'] = (int)$pdo->query("SELECT COUNT(*) FROM tessere WHERE stato = 'Attiva' AND data_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
        $prossimi_eventi = $pdo->query("SELECT titolo, data_evento FROM eventi WHERE data_evento >= CURDATE() ORDER BY data_evento ASC LIMIT 5")->fetchAll();
    } else {
        // Totale soci per l'associazione corrente
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM soci WHERE associazione_id = ?");
        $stmt->execute([$associazione_id]);
        $stats['total_soci'] = $stmt->fetchColumn();

    // Soci attivi
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM soci WHERE associazione_id = ? AND stato = 'Attivo'");
    $stmt->execute([$associazione_id]);
    $stats['soci_attivi'] = $stmt->fetchColumn();

    // Eventi questo mese
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM eventi WHERE associazione_id = ? AND MONTH(data_evento) = MONTH(CURDATE()) AND YEAR(data_evento) = YEAR(CURDATE())");
    $stmt->execute([$associazione_id]);
    $stats['eventi_mese'] = $stmt->fetchColumn();

    // Quote pagate quest'anno
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quote WHERE associazione_id = ? AND stato = 'Pagata' AND anno = YEAR(CURDATE())");
    $stmt->execute([$associazione_id]);
    $stats['quote_pagate'] = $stmt->fetchColumn();

    // Scadenze imminenti (prossimi 30 giorni)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tessere WHERE associazione_id = ? AND stato = 'Attiva' AND data_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $stmt->execute([$associazione_id]);
    $stats['scadenze_imminenti'] = $stmt->fetchColumn();

    // Prossimi eventi
    $stmt = $pdo->prepare("SELECT titolo, data_evento FROM eventi WHERE associazione_id = ? AND data_evento >= CURDATE() ORDER BY data_evento ASC LIMIT 5");
    $stmt->execute([$associazione_id]);
    $prossimi_eventi = $stmt->fetchAll();
    }

} catch (PDOException $e) {
    // Gestione errore
    error_log('dashboard.php PDOException: ' . $e->getMessage());
    echo "<div class=\"alert alert-danger\">Errore nel caricamento dei dati della dashboard. Riprova più tardi.</div>";
}

?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1>Dashboard</h1>
            <p class="page-description">
                <?php if ($has_assoc): ?>
                    Panoramica generale di <strong><?php echo htmlspecialchars($_SESSION['associazione_nome'] ?? ''); ?></strong>
                <?php else: ?>
                    Benvenuto Super Admin. Panoramica globale di tutte le associazioni.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="text-muted small">Ultimo aggiornamento: <?php echo date('d/m/Y H:i'); ?></span>
        </div>
    </div>
</div>

<!-- Statistiche principali -->
<?php if ($has_assoc || $is_super_admin): ?>
<div class="row" data-animate="scroll">
    <div class="col-md-4 mb-4">
        <div class="card text-center stat-card" data-stat-value="<?php echo $stats['total_soci'] ?? 0; ?>">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-people-fill me-2"></i>Totale Soci</h5>
                <p class="card-text display-4 stat-number"><?php echo $stats['total_soci'] ?? 0; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card text-center text-success stat-card" data-stat-value="<?php echo $stats['soci_attivi'] ?? 0; ?>">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-person-check-fill me-2"></i>Soci Attivi</h5>
                <p class="card-text display-4 stat-number"><?php echo $stats['soci_attivi'] ?? 0; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card text-center text-danger stat-card" data-stat-value="<?php echo $stats['scadenze_imminenti'] ?? 0; ?>">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Scadenze Imminenti</h5>
                <p class="card-text display-4 stat-number"><?php echo $stats['scadenze_imminenti'] ?? 0; ?></p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($is_super_admin): ?>
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Associazioni</h5>
    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=associazioni">Gestisci</a>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($pdo->query("SELECT id, nome FROM associazioni WHERE attiva = 1 ORDER BY nome")->fetchAll() as $a): ?>
        <div class="col-md-4">
          <div class="border rounded p-3 d-flex justify-content-between align-items-center">
            <div class="fw-semibold"><?php echo htmlspecialchars($a['nome']); ?></div>
            <a href="index.php?page=dashboard&assoc_id=<?php echo $a['id']; ?>" class="btn btn-sm btn-primary">Entra</a>
          </div>
        </div>
      <?php endforeach; ?>
      <div class="col-md-4">
        <div class="border rounded p-3 d-flex justify-content-between align-items-center">
          <div class="fw-semibold">Tutte le associazioni</div>
          <a href="index.php?page=dashboard&assoc_id=all" class="btn btn-sm btn-outline-primary">Vista Globale</a>
        </div>
      </div>
    </div>
  </div>
  <div class="card-footer small text-muted">Suggerimento: usa "Entra" per impostare il contesto dell'associazione sulla dashboard.</div>
  </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-calendar-event me-2"></i>Prossimi Eventi</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($prossimi_eventi)): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($prossimi_eventi as $evento):
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo htmlspecialchars($evento['titolo']); ?>
                                <span class="badge bg-primary rounded-pill"><?php echo date('d/m/Y', strtotime($evento['data_evento'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else:
                ?>
                    <p class="text-muted">Nessun evento in programma.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><i class="bi bi-graph-up me-2"></i>Statistiche Quote</h5>
            </div>
            <div class="card-body">
                <!-- Qui andrà un grafico generato con Chart.js -->
                <canvas id="quoteChart"></canvas>
                <p class="text-muted text-center mt-2">Grafico in fase di implementazione.</p>
            </div>
        </div>
    </div>
</div>

<!-- Grafico quote - da implementare -->
<script>
    // Placeholder per futura implementazione con Chart.js
    // Il canvas #quoteChart è pronto per l'uso
</script>
