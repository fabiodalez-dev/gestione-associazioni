<?php
/**
 * API Endpoint: Tessere (Membership Cards)
 *
 * Endpoints:
 * - GET /api/v1/tessere.php?id={id} - Get membership card details
 * - GET /api/v1/tessere.php?numero_tessera={numero} - Get card by card number
 * - GET /api/v1/tessere.php?action=search&{filters} - Search membership cards
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../auth.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Enable CORS (configure as needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Metodo non consentito. Usa GET', 405);
}

// Authenticate API key
$auth = authenticateApiKey($pdo);
if (!$auth['success']) {
    sendErrorResponse($auth['message'], $auth['http_code']);
}

// Check permission for 'tessere' resource
if (!hasPermission($auth, 'tessere')) {
    sendErrorResponse('Permesso negato per accedere ai dati delle tessere', 403);
}

$associazione_id = $auth['associazione_id'];
$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            if (isset($_GET['id'])) {
                getTesseraById($pdo, $associazione_id);
            } elseif (isset($_GET['numero_tessera'])) {
                getTesseraByNumber($pdo, $associazione_id);
            } else {
                sendErrorResponse('Parametro id o numero_tessera richiesto', 400);
            }
            break;

        case 'search':
            searchTessere($pdo, $associazione_id);
            break;

        default:
            sendErrorResponse('Azione non valida', 400);
    }

} catch (Exception $e) {
    error_log("API Error in tessere.php: " . $e->getMessage());
    sendErrorResponse('Errore interno del server', 500);
}

/**
 * Get membership card by ID
 */
function getTesseraById($pdo, $associazione_id) {
    $tessera_id = $_GET['id'];

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.numero_tessera,
            t.anno_validita,
            t.data_emissione,
            t.data_scadenza,
            t.tipo_scadenza,
            t.stato,
            t.qr_code_url,
            t.template_tessera,
            s.id as socio_id,
            s.numero_socio,
            s.nome as socio_nome,
            s.cognome as socio_cognome,
            s.email as socio_email,
            s.codice_fiscale as socio_codice_fiscale,
            ts.nome as tipo_socio,
            se.nome as sede_nome
        FROM tessere t
        INNER JOIN soci s ON t.socio_id = s.id
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE t.id = :tessera_id AND t.associazione_id = :associazione_id
    ");

    $stmt->execute([
        ':tessera_id' => $tessera_id,
        ':associazione_id' => $associazione_id
    ]);

    $tessera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tessera) {
        sendErrorResponse('Tessera non trovata', 404);
    }

    // Calculate expiration status
    $now = time();
    $scadenza = strtotime($tessera['data_scadenza']);
    $is_expired = $scadenza < $now;
    $giorni_scadenza = $is_expired ? 0 : floor(($scadenza - $now) / 86400);

    $response = [
        'success' => true,
        'data' => [
            'tessera' => $tessera,
            'status' => [
                'is_expired' => $is_expired,
                'giorni_scadenza' => $giorni_scadenza,
                'is_active' => $tessera['stato'] === 'Attiva' && !$is_expired
            ]
        ]
    ];

    sendJsonResponse($response);
}

/**
 * Get membership card by card number
 */
function getTesseraByNumber($pdo, $associazione_id) {
    $numero_tessera = $_GET['numero_tessera'];

    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.numero_tessera,
            t.anno_validita,
            t.data_emissione,
            t.data_scadenza,
            t.tipo_scadenza,
            t.stato,
            t.qr_code_url,
            t.template_tessera,
            s.id as socio_id,
            s.numero_socio,
            s.nome as socio_nome,
            s.cognome as socio_cognome,
            s.email as socio_email,
            s.codice_fiscale as socio_codice_fiscale,
            ts.nome as tipo_socio,
            se.nome as sede_nome
        FROM tessere t
        INNER JOIN soci s ON t.socio_id = s.id
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE t.numero_tessera = :numero_tessera AND t.associazione_id = :associazione_id
    ");

    $stmt->execute([
        ':numero_tessera' => $numero_tessera,
        ':associazione_id' => $associazione_id
    ]);

    $tessera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tessera) {
        sendErrorResponse('Tessera non trovata', 404);
    }

    // Calculate expiration status
    $now = time();
    $scadenza = strtotime($tessera['data_scadenza']);
    $is_expired = $scadenza < $now;
    $giorni_scadenza = $is_expired ? 0 : floor(($scadenza - $now) / 86400);

    $response = [
        'success' => true,
        'data' => [
            'tessera' => $tessera,
            'status' => [
                'is_expired' => $is_expired,
                'giorni_scadenza' => $giorni_scadenza,
                'is_active' => $tessera['stato'] === 'Attiva' && !$is_expired
            ]
        ]
    ];

    sendJsonResponse($response);
}

/**
 * Search membership cards
 */
function searchTessere($pdo, $associazione_id) {
    $where = ["t.associazione_id = :associazione_id"];
    $params = [':associazione_id' => $associazione_id];

    // Build dynamic search filters
    if (isset($_GET['socio_id']) && $_GET['socio_id'] !== '') {
        $where[] = "t.socio_id = :socio_id";
        $params[':socio_id'] = $_GET['socio_id'];
    }

    if (isset($_GET['numero_tessera']) && $_GET['numero_tessera'] !== '') {
        $where[] = "t.numero_tessera LIKE :numero_tessera";
        $params[':numero_tessera'] = '%' . $_GET['numero_tessera'] . '%';
    }

    if (isset($_GET['anno_validita']) && $_GET['anno_validita'] !== '') {
        $where[] = "t.anno_validita = :anno_validita";
        $params[':anno_validita'] = intval($_GET['anno_validita']);
    }

    if (isset($_GET['stato']) && $_GET['stato'] !== '') {
        $where[] = "t.stato = :stato";
        $params[':stato'] = $_GET['stato'];
    }

    if (isset($_GET['tipo_scadenza']) && $_GET['tipo_scadenza'] !== '') {
        $where[] = "t.tipo_scadenza = :tipo_scadenza";
        $params[':tipo_scadenza'] = $_GET['tipo_scadenza'];
    }

    // Filter by expiration
    if (isset($_GET['scadute']) && $_GET['scadute'] === '1') {
        $where[] = "t.data_scadenza < CURDATE()";
    }

    if (isset($_GET['attive']) && $_GET['attive'] === '1') {
        $where[] = "t.stato = 'Attiva' AND t.data_scadenza >= CURDATE()";
    }

    // Filter by member name
    if (isset($_GET['socio_nome']) && $_GET['socio_nome'] !== '') {
        $where[] = "s.nome LIKE :socio_nome";
        $params[':socio_nome'] = '%' . $_GET['socio_nome'] . '%';
    }

    if (isset($_GET['socio_cognome']) && $_GET['socio_cognome'] !== '') {
        $where[] = "s.cognome LIKE :socio_cognome";
        $params[':socio_cognome'] = '%' . $_GET['socio_cognome'] . '%';
    }

    // Generic search
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $where[] = "(t.numero_tessera LIKE :q OR s.nome LIKE :q OR s.cognome LIKE :q OR s.numero_socio LIKE :q)";
        $params[':q'] = '%' . $_GET['q'] . '%';
    }

    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 20;
    $offset = ($page - 1) * $limit;

    $whereClause = implode(' AND ', $where);

    // Get total count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM tessere t
        INNER JOIN soci s ON t.socio_id = s.id
        WHERE $whereClause
    ");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get results
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.numero_tessera,
            t.anno_validita,
            t.data_emissione,
            t.data_scadenza,
            t.tipo_scadenza,
            t.stato,
            s.id as socio_id,
            s.numero_socio,
            s.nome as socio_nome,
            s.cognome as socio_cognome,
            ts.nome as tipo_socio,
            se.nome as sede_nome
        FROM tessere t
        INNER JOIN soci s ON t.socio_id = s.id
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE $whereClause
        ORDER BY t.data_emissione DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $tessere = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'data' => $tessere,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ];

    sendJsonResponse($response);
}
