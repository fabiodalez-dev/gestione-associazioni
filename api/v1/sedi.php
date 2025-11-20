<?php
/**
 * API Endpoint: Sedi (Locations/Branches)
 *
 * Endpoints:
 * - GET /api/v1/sedi.php?id={id} - Get sede details
 * - GET /api/v1/sedi.php?action=list - List all sedi
 * - GET /api/v1/sedi.php?action=search&{filters} - Search sedi
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

// Check permission for 'sedi' resource
if (!hasPermission($auth, 'sedi')) {
    sendErrorResponse('Permesso negato per accedere ai dati delle sedi', 403);
}

$associazione_id = $auth['associazione_id'];
$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            getSedeById($pdo, $associazione_id);
            break;

        case 'list':
            listSedi($pdo, $associazione_id);
            break;

        case 'search':
            searchSedi($pdo, $associazione_id);
            break;

        default:
            sendErrorResponse('Azione non valida', 400);
    }

} catch (Exception $e) {
    error_log("API Error in sedi.php: " . $e->getMessage());
    sendErrorResponse('Errore interno del server', 500);
}

/**
 * Get sede by ID
 */
function getSedeById($pdo, $associazione_id) {
    if (!isset($_GET['id'])) {
        sendErrorResponse('Parametro id richiesto', 400);
    }

    $sede_id = $_GET['id'];

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.nome,
            s.indirizzo,
            s.citta,
            s.provincia,
            s.cap,
            s.email,
            s.telefono,
            s.responsabile,
            s.created_at,
            s.updated_at
        FROM sedi s
        WHERE s.id = :sede_id AND s.associazione_id = :associazione_id
    ");

    $stmt->execute([
        ':sede_id' => $sede_id,
        ':associazione_id' => $associazione_id
    ]);

    $sede = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sede) {
        sendErrorResponse('Sede non trovata', 404);
    }

    // Get count of members in this sede
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total_soci
        FROM soci
        WHERE sede_id = :sede_id AND associazione_id = :associazione_id
    ");
    $countStmt->execute([
        ':sede_id' => $sede_id,
        ':associazione_id' => $associazione_id
    ]);
    $total_soci = $countStmt->fetch(PDO::FETCH_ASSOC)['total_soci'];

    $response = [
        'success' => true,
        'data' => [
            'sede' => $sede,
            'statistiche' => [
                'total_soci' => $total_soci
            ]
        ]
    ];

    sendJsonResponse($response);
}

/**
 * List all sedi
 */
function listSedi($pdo, $associazione_id) {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.nome,
            s.indirizzo,
            s.citta,
            s.provincia,
            s.cap,
            s.email,
            s.telefono,
            s.responsabile,
            COUNT(so.id) as total_soci
        FROM sedi s
        LEFT JOIN soci so ON s.id = so.sede_id
        WHERE s.associazione_id = :associazione_id
        GROUP BY s.id
        ORDER BY s.nome ASC
    ");

    $stmt->execute([':associazione_id' => $associazione_id]);
    $sedi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'data' => $sedi,
        'total' => count($sedi)
    ];

    sendJsonResponse($response);
}

/**
 * Search sedi
 */
function searchSedi($pdo, $associazione_id) {
    $where = ["s.associazione_id = :associazione_id"];
    $params = [':associazione_id' => $associazione_id];

    // Build dynamic search filters
    if (isset($_GET['nome']) && $_GET['nome'] !== '') {
        $where[] = "s.nome LIKE :nome";
        $params[':nome'] = '%' . $_GET['nome'] . '%';
    }

    if (isset($_GET['citta']) && $_GET['citta'] !== '') {
        $where[] = "s.citta LIKE :citta";
        $params[':citta'] = '%' . $_GET['citta'] . '%';
    }

    if (isset($_GET['provincia']) && $_GET['provincia'] !== '') {
        $where[] = "s.provincia = :provincia";
        $params[':provincia'] = strtoupper($_GET['provincia']);
    }

    if (isset($_GET['responsabile']) && $_GET['responsabile'] !== '') {
        $where[] = "s.responsabile LIKE :responsabile";
        $params[':responsabile'] = '%' . $_GET['responsabile'] . '%';
    }

    // Generic search
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $where[] = "(s.nome LIKE :q OR s.citta LIKE :q OR s.responsabile LIKE :q)";
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
        FROM sedi s
        WHERE $whereClause
    ");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get results
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.nome,
            s.indirizzo,
            s.citta,
            s.provincia,
            s.cap,
            s.email,
            s.telefono,
            s.responsabile,
            COUNT(so.id) as total_soci
        FROM sedi s
        LEFT JOIN soci so ON s.id = so.sede_id
        WHERE $whereClause
        GROUP BY s.id
        ORDER BY s.nome ASC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $sedi = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'data' => $sedi,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ];

    sendJsonResponse($response);
}
