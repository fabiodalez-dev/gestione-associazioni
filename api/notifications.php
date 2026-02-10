<?php
// api/notifications.php - v2.0 (SaaS)

require_once '../config.php';

header('Content-Type: application/json');

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato']);
    exit;
}

$associazione_id = $_SESSION['associazione_id'];

try {
    $notifications = [];

    // Tessere in scadenza (prossimi 30 giorni)
    $stmt = $pdo->prepare("
        SELECT t.id, CONCAT(s.nome, ' ', s.cognome) as socio_name, t.data_scadenza
        FROM tessere t 
        JOIN soci s ON t.socio_id = s.id
        WHERE t.associazione_id = ? AND t.stato = 'Attiva'
        AND t.data_scadenza BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY t.data_scadenza ASC
        LIMIT 5
    ");
    $stmt->execute([$associazione_id]);
    $tessere_in_scadenza = $stmt->fetchAll();

    foreach ($tessere_in_scadenza as $tessera) {
        $notifications[] = [
            'id' => 'tessera_' . $tessera['id'],
            'tipo' => 'Tessera in Scadenza',
            'messaggio' => $tessera['socio_name'],
            'link' => 'index.php?page=tessere'
        ];
    }

    // Quote non pagate e scadute
    // (Questa logica può essere espansa)
    $stmt = $pdo->prepare("
        SELECT q.id, CONCAT(s.nome, ' ', s.cognome) as socio_name, q.data_scadenza
        FROM quote q
        JOIN soci s ON q.socio_id = s.id
        WHERE q.associazione_id = ? AND q.data_pagamento IS NULL AND q.data_scadenza < CURDATE()
        ORDER BY q.data_scadenza DESC
        LIMIT 5
    ");
    $stmt->execute([$associazione_id]);
    $quote_scadute = $stmt->fetchAll();

    foreach ($quote_scadute as $quota) {
        $notifications[] = [
            'id' => 'quota_' . $quota['id'],
            'tipo' => 'Quota Scaduta',
            'messaggio' => $quota['socio_name'] . ' - Scad. ' . date('d/m/Y', strtotime($quota['data_scadenza'])),
            'link' => 'index.php?page=quote'
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => count($notifications),
        'notifications' => $notifications
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log('notifications.php PDOException: ' . $e->getMessage());
    echo json_encode(['error' => 'Errore database. Riprova più tardi.']);
}
