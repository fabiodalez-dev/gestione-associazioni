<?php
// area-soci/pages/dashboard.php

// Assicurati che $socio_loggato sia disponibile da area-soci/index.php
if (!isset($socio_loggato)) {
    redirect('login.php');
}

// Recupera la prossima quota in scadenza
$prossima_quota = null;
$stmt_quota = $pdo->prepare("SELECT * FROM quote WHERE socio_id = ? AND data_pagamento IS NULL AND data_scadenza >= CURDATE() ORDER BY data_scadenza ASC LIMIT 1");
$stmt_quota->execute([$socio_loggato['id']]);
$prossima_quota = $stmt_quota->fetch();

// Recupera lo stato della tessera
$tessera_attiva = null;
$stmt_tessera = $pdo->prepare("SELECT * FROM tessere WHERE socio_id = ? AND stato = 'Attiva' ORDER BY anno_validita DESC LIMIT 1");
$stmt_tessera->execute([$socio_loggato['id']]);
$tessera_attiva = $stmt_tessera->fetch();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Dashboard Socio</h1>
</div>

<div class="alert alert-info" role="alert">
    Benvenuto, <strong><?php echo htmlspecialchars($socio_loggato['nome']); ?></strong>! Questa è la tua area riservata.
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Stato Tessera</h5>
                <?php if ($tessera_attiva): ?>
                    <p class="card-text">La tua tessera (N. <?php echo htmlspecialchars($tessera_attiva['numero_tessera']); ?>) è <strong>Attiva</strong> fino al <?php echo date('d/m/Y', strtotime($tessera_attiva['data_scadenza'])); ?>.</p>
                <?php else: ?>
                    <p class="card-text">Non hai una tessera attiva. Contatta l'associazione per il rinnovo o l'emissione.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Prossima Quota</h5>
                <?php if ($prossima_quota): ?>
                    <p class="card-text">La tua prossima quota di <strong>€<?php echo htmlspecialchars($prossima_quota['importo']); ?></strong> scade il <?php echo date('d/m/Y', strtotime($prossima_quota['data_scadenza'])); ?>.</p>
                    <p class="card-text text-muted"><small>Tipo: <?php echo htmlspecialchars($prossima_quota['tipo']); ?></small></p>
                <?php else: ?>
                    <p class="card-text">Non hai quote in scadenza. Sei in regola con i pagamenti!</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5>Comunicazioni Recenti</h5></div>
    <div class="card-body">
        <p>Qui verranno visualizzate le comunicazioni importanti dall'associazione.</p>
    </div>
</div>
