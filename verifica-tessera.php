<?php
/**
 * verifica-tessera.php - Public tessera verification page (no auth required).
 * Accessed by scanning the QR code on a tessera associativa.
 * Supports JSON response with ?json=1 parameter for scanner-tessera.php.
 */
require_once __DIR__ . '/config.php';

$tessera_id = $_GET['t'] ?? '';
$stato = 'no_param'; // no_param | not_found | valida | scaduta
$tessera = null;

if ($tessera_id !== '') {
    // Validate UUID format
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $tessera_id)) {
        $stato = 'not_found';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT t.numero_tessera, t.anno_validita, t.data_emissione, t.data_scadenza, t.stato,
                       s.nome AS socio_nome, s.cognome AS socio_cognome,
                       a.nome AS associazione_nome
                FROM tessere t
                JOIN soci s ON t.socio_id = s.id
                JOIN associazioni a ON t.associazione_id = a.id
                WHERE t.id = ?
            ");
            $stmt->execute([$tessera_id]);
            $tessera = $stmt->fetch();

            if (!$tessera) {
                $stato = 'not_found';
            } elseif ($tessera['stato'] === 'Attiva' && $tessera['data_scadenza'] >= date('Y-m-d')) {
                $stato = 'valida';
            } else {
                $stato = 'scaduta';
            }
        } catch (PDOException $e) {
            error_log('verifica-tessera.php PDOException: ' . $e->getMessage());
            $stato = 'not_found';
        }
    }
}

// JSON response mode for scanner-tessera.php
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $response = ['stato' => $stato];
    if ($tessera) {
        $response['tessera'] = [
            'numero_tessera' => $tessera['numero_tessera'],
            'anno_validita' => $tessera['anno_validita'],
            'data_scadenza' => $tessera['data_scadenza'],
            'stato_tessera' => $tessera['stato'],
            'socio_nome' => $tessera['socio_nome'],
            'socio_cognome' => $tessera['socio_cognome'],
            'associazione_nome' => $tessera['associazione_nome'],
        ];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Tessera Associativa</title>
    <meta name="theme-color" content="#FF7B11">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f5f6fa; }
        .verify-card { max-width: 480px; margin: 60px auto; }
        .status-icon { font-size: 4rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="verify-card">
            <div class="card shadow-sm border-0">
                <div class="card-body text-center p-4">

                    <?php if ($stato === 'no_param'): ?>
                        <i class="bi bi-qr-code-scan status-icon text-muted"></i>
                        <h4 class="mt-3">Verifica Tessera Associativa</h4>
                        <p class="text-muted">Scansiona il QR code sulla tessera per verificarne la validità.</p>
                        <a href="scanner-tessera.php" class="btn btn-primary mt-2">
                            <i class="bi bi-camera me-1"></i> Scansiona con fotocamera
                        </a>

                    <?php elseif ($stato === 'not_found'): ?>
                        <i class="bi bi-exclamation-triangle status-icon text-warning"></i>
                        <h4 class="mt-3">Tessera Non Trovata</h4>
                        <p class="text-muted">Il codice scansionato non corrisponde a nessuna tessera registrata.</p>

                    <?php elseif ($stato === 'valida'): ?>
                        <i class="bi bi-check-circle-fill status-icon text-success"></i>
                        <h4 class="mt-3 text-success">Tessera Valida</h4>
                        <hr>
                        <div class="text-start">
                            <p><strong>Associazione:</strong> <?php echo htmlspecialchars($tessera['associazione_nome']); ?></p>
                            <p><strong>Socio:</strong> <?php echo htmlspecialchars($tessera['socio_nome'] . ' ' . $tessera['socio_cognome']); ?></p>
                            <p><strong>N. Tessera:</strong> <?php echo htmlspecialchars($tessera['numero_tessera']); ?></p>
                            <p><strong>Anno:</strong> <?php echo htmlspecialchars($tessera['anno_validita']); ?></p>
                            <p><strong>Valida fino al:</strong> <?php echo date('d/m/Y', strtotime($tessera['data_scadenza'])); ?></p>
                        </div>

                    <?php elseif ($stato === 'scaduta'): ?>
                        <i class="bi bi-x-circle-fill status-icon text-danger"></i>
                        <h4 class="mt-3 text-danger">Tessera Scaduta / Non Attiva</h4>
                        <hr>
                        <div class="text-start">
                            <p><strong>Associazione:</strong> <?php echo htmlspecialchars($tessera['associazione_nome']); ?></p>
                            <p><strong>Socio:</strong> <?php echo htmlspecialchars($tessera['socio_nome'] . ' ' . $tessera['socio_cognome']); ?></p>
                            <p><strong>N. Tessera:</strong> <?php echo htmlspecialchars($tessera['numero_tessera']); ?></p>
                            <p><strong>Scadenza:</strong> <?php echo date('d/m/Y', strtotime($tessera['data_scadenza'])); ?></p>
                            <p><strong>Stato:</strong> <?php echo htmlspecialchars($tessera['stato']); ?></p>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            <p class="text-center text-muted mt-3 small">Gestione Associazioni &mdash; Sistema di verifica tessere</p>
        </div>
    </div>
</body>
</html>
