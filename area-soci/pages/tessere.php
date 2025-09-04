<?php
// area-soci/pages/tessere.php

if (!isset($socio_loggato)) {
    redirect('login.php');
}

$socio_id = $socio_loggato['id'];
$numero_socio = $socio_loggato['numero_socio'] ?? '';

$stmt = $pdo->prepare("SELECT t.* FROM tessere t WHERE t.socio_id = ? ORDER BY t.anno_validita DESC, t.data_emissione DESC");
$stmt->execute([$socio_id]);
$tessere = $stmt->fetchAll();

function tesseraStatus($t) {
    if (!empty($t['stato']) && $t['stato'] !== 'Attiva') return $t['stato'];
    if (!empty($t['data_scadenza'])) {
        $scad = strtotime($t['data_scadenza']);
        if ($scad < time()) return 'Scaduta';
        if ($scad < strtotime('+30 days')) return 'In Scadenza';
    }
    return 'Attiva';
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Le Mie Tessere</h1>
</div>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-striped table-hover align-middle">
        <thead>
          <tr>
            <th>Anno</th>
            <th>Numero Tessera</th>
            <th>Emissione</th>
            <th>Scadenza</th>
            <th>Tipo</th>
            <th>Stato</th>
            <th class="text-end">Documento</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($tessere)): ?>
          <tr><td colspan="7" class="text-center text-muted">Nessuna tessera disponibile.</td></tr>
        <?php else: ?>
          <?php foreach ($tessere as $t): 
            $status = tesseraStatus($t);
            $badge = ['Attiva'=>'success','In Scadenza'=>'warning','Scaduta'=>'danger'][$status] ?? 'secondary';
            $download = '';
            if ($numero_socio) {
                $expected = 'uploads/documents/' . 'tessera_' . preg_replace('/[^A-Za-z0-9_-]/','', $numero_socio) . '_' . $t['anno_validita'] . '.pdf';
                $abs = APP_ROOT . '/' . $expected;
                if (is_file($abs)) {
                    $download = '<a class="btn btn-sm btn-outline-secondary" href="' . htmlspecialchars('../' . $expected) . '" target="_blank"><i class="bi bi-file-earmark-pdf"></i> PDF</a>';
                }
            }
          ?>
            <tr>
              <td><?php echo htmlspecialchars($t['anno_validita']); ?></td>
              <td><?php echo htmlspecialchars($t['numero_tessera']); ?></td>
              <td><?php echo $t['data_emissione'] ? date('d/m/Y', strtotime($t['data_emissione'])) : '-'; ?></td>
              <td><?php echo $t['data_scadenza'] ? date('d/m/Y', strtotime($t['data_scadenza'])) : '-'; ?></td>
              <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($t['tipo_scadenza']); ?></span></td>
              <td><span class="badge bg-<?php echo $badge; ?>"><?php echo $status; ?></span></td>
              <td class="text-end"><?php echo $download ?: '<span class="text-muted">—</span>'; ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

