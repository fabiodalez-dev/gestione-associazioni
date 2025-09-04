<?php
// area-soci/pages/quote.php

// Assicurati che $socio_loggato sia disponibile da area-soci/index.php
if (!isset($socio_loggato)) {
    redirect('login.php');
}

// Funzione per determinare lo stato della quota dinamicamente (copia da pages/quote.php)
function getQuotaStatusSocioArea($quota) {
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

// Recupera tutte le quote del socio
$stmt_quote = $pdo->prepare("SELECT * FROM quote WHERE socio_id = ? ORDER BY anno DESC, data_scadenza DESC");
$stmt_quote->execute([$socio_loggato['id']]);
$quote_socio = $stmt_quote->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Le Mie Quote</h1>
</div>

<div class="card mb-4">
    <div class="card-header"><h5>Riepilogo Quote</h5></div>
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Anno</th>
                    <th>Tipo</th>
                    <th>Importo</th>
                    <th>Scadenza</th>
                    <th>Stato</th>
                    <th>Data Pagamento</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($quote_socio)): ?>
                    <tr><td colspan="6" class="text-center text-muted">Nessuna quota registrata.</td></tr>
                <?php else: ?>
                    <?php foreach ($quote_socio as $q): 
                        $status = getQuotaStatusSocioArea($q);
                        $badge_class = ['Pagata' => 'success', 'Da Pagare' => 'info', 'In Scadenza' => 'warning', 'Scaduta' => 'danger'][$status] ?? 'secondary';
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($q['anno']); ?></td>
                            <td><?php echo htmlspecialchars($q['tipo']); ?></td>
                            <td>€<?php echo number_format($q['importo'], 2, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($q['data_scadenza'])); ?></td>
                            <td><span class="badge bg-<?php echo $badge_class; ?>"><?php echo $status; ?></span></td>
                            <td><?php echo $q['data_pagamento'] ? date('d/m/Y', strtotime($q['data_pagamento'])) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h5>Pagamento Quote</h5></div>
    <div class="card-body">
        <p>Qui potresti trovare opzioni per pagare le tue quote online (funzionalità in sviluppo).</p>
    </div>
</div>
