<?php
// api/soci_search.php - Autocomplete search endpoint for active soci

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato']);
    exit;
}

$associazione_id = $_SESSION['associazione_id'];
$q = trim($_GET['q'] ?? '');

if ($q === '') {
    echo json_encode([]);
    exit;
}

try {
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        "SELECT id, CONCAT(cognome, ' ', nome) AS nome_completo
         FROM soci
         WHERE associazione_id = ? AND stato = 'Attivo'
           AND (nome LIKE ? OR cognome LIKE ? OR CONCAT(cognome, ' ', nome) LIKE ? OR CONCAT(nome, ' ', cognome) LIKE ?)
         ORDER BY cognome, nome
         LIMIT 20"
    );
    $stmt->execute([$associazione_id, $like, $like, $like, $like]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    error_log('soci_search.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
}
