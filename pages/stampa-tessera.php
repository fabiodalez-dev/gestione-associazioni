<?php
// pages/stampa-tessera.php - v2.0 (SaaS)
// Config è già caricato da index.php

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    http_response_code(403);
    die('Accesso negato.');
}

$associazione_id = $_SESSION['associazione_id'];
$tessera_id = $_GET['id'] ?? null;

if (!$tessera_id) {
    die('ID Tessera non specificato.');
}

// Load Composer autoload and QR helper
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/../includes/qrcode_helper.php';

// Recupera i dati della tessera, del socio e dell'associazione
$sql = "
    SELECT
        t.id as tessera_id, t.numero_tessera, t.anno_validita, t.data_emissione, t.data_scadenza,
        s.nome as socio_nome, s.cognome as socio_cognome, s.data_nascita,
        a.nome as associazione_nome, a.logo_url
    FROM tessere t
    JOIN soci s ON t.socio_id = s.id
    JOIN associazioni a ON t.associazione_id = a.id
    WHERE t.id = ? AND t.associazione_id = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tessera_id, $associazione_id]);
$data = $stmt->fetch();

if (!$data) {
    http_response_code(404);
    die('Tessera non trovata o non appartenente a questa associazione.');
}

// Generate QR code data URI
$qr_data_uri = generateQrDataUri(buildTesseraVerificationUrl($data['tessera_id']), 3);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Stampa Tessera <?php echo htmlspecialchars($data['numero_tessera']); ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .card-container {
            width: 350px;
            height: 210px;
            border-radius: 15px;
            background: #fff;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            padding: 20px;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        .card-header {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .card-header .logo {
            width: 40px;
            height: 40px;
            margin-right: 10px;
            background-color: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: #495057;
        }
        .card-header h1 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            color: #333;
        }
        .card-body {
            display: flex;
            flex-grow: 1;
        }
        .member-photo {
            width: 80px;
            height: 100px;
            background-color: #e9ecef;
            border-radius: 8px;
            margin-right: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 0.8rem;
        }
        .member-details {
            font-size: 0.9rem;
        }
        .member-details .name {
            font-weight: 700;
            font-size: 1.1rem;
            color: #000;
        }
        .member-details p {
            margin: 4px 0;
            color: #555;
        }
        .card-footer {
            text-align: center;
            font-size: 0.7rem;
            color: #999;
            margin-top: auto;
        }
        .qr-code {
            width: 50px;
            height: 50px;
            margin-left: auto;
            object-fit: contain;
        }

        @media print {
            body {
                background-color: #fff;
            }
            .card-container {
                box-shadow: none;
                border: 1px solid #ccc;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>

    <div class="card-container">
        <div class="card-header">
            <div class="logo">LOGO</div>
            <h1><?php echo htmlspecialchars($data['associazione_nome']); ?></h1>
        </div>
        <div class="card-body">
            <div class="member-photo">Foto</div>
            <div class="member-details">
                <p class="name"><?php echo htmlspecialchars($data['socio_nome'] . ' ' . $data['socio_cognome']); ?></p>
                <p><strong>N. Tessera:</strong> <?php echo htmlspecialchars($data['numero_tessera']); ?></p>
                <p><strong>Validità:</strong> <?php echo htmlspecialchars($data['anno_validita']); ?></p>
                <p><strong>Scadenza:</strong> <?php echo date('d/m/Y', strtotime($data['data_scadenza'])); ?></p>
            </div>
            <img class="qr-code" src="<?php echo htmlspecialchars($qr_data_uri); ?>" alt="QR Verifica">
        </div>
        <div class="card-footer">
            Tessera Associativa Personale
        </div>
    </div>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <button class="no-print" onclick="window.print()" style="position: fixed; top: 20px; right: 20px; padding: 8px 20px; cursor: pointer; background-color: #FF7B11; color: #fff; border: none; border-radius: 6px; font-family: 'Inter', sans-serif; font-size: 0.9rem; font-weight: 600; box-shadow: 0 2px 8px rgba(255,123,17,0.3);"><i class="bi bi-printer me-1"></i> Stampa</button>

</body>
</html>
