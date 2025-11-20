<?php
/**
 * API Endpoint: Soci (Members)
 *
 * Endpoints:
 * - GET /api/v1/soci.php?id={id} - Get member personal data
 * - GET /api/v1/soci.php?id={id}&action=tessera - Get active membership card
 * - GET /api/v1/soci.php?id={id}&action=sede - Get member's location/sede
 * - GET /api/v1/soci.php?action=search&{filters} - Search members
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

// Check permission for 'soci' resource
if (!hasPermission($auth, 'soci')) {
    sendErrorResponse('Permesso negato per accedere ai dati dei soci', 403);
}

$associazione_id = $auth['associazione_id'];
$action = $_GET['action'] ?? 'get';

try {
    switch ($action) {
        case 'get':
            getSocioData($pdo, $associazione_id);
            break;

        case 'tessera':
            getSocioTessera($pdo, $associazione_id);
            break;

        case 'sede':
            getSocioSede($pdo, $associazione_id);
            break;

        case 'search':
            searchSoci($pdo, $associazione_id);
            break;

        default:
            sendErrorResponse('Azione non valida', 400);
    }

} catch (Exception $e) {
    error_log("API Error in soci.php: " . $e->getMessage());
    sendErrorResponse('Errore interno del server', 500);
}

/**
 * Get member's personal data
 */
function getSocioData($pdo, $associazione_id) {
    if (!isset($_GET['id'])) {
        sendErrorResponse('Parametro id richiesto', 400);
    }

    $socio_id = $_GET['id'];

    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.numero_socio,
            s.nome,
            s.cognome,
            s.data_nascita,
            s.codice_fiscale,
            s.email,
            s.telefono,
            s.indirizzo,
            s.citta,
            s.provincia,
            s.cap,
            s.data_iscrizione,
            s.stato,
            s.privacy_consenso,
            ts.nome as tipo_socio,
            cs.nome as categoria_socio,
            se.nome as sede_nome,
            s.created_at,
            s.updated_at
        FROM soci s
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE s.id = :socio_id AND s.associazione_id = :associazione_id
    ");

    $stmt->execute([
        ':socio_id' => $socio_id,
        ':associazione_id' => $associazione_id
    ]);

    $socio = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$socio) {
        sendErrorResponse('Socio non trovato', 404);
    }

    // Get custom fields
    $customFieldsStmt = $pdo->prepare("
        SELECT
            cp.nome_campo,
            cp.tipo_campo,
            vcp.valore
        FROM valori_campi_personalizzati vcp
        INNER JOIN campi_personalizzati cp ON vcp.campo_id = cp.id
        WHERE vcp.socio_id = :socio_id
    ");
    $customFieldsStmt->execute([':socio_id' => $socio_id]);
    $customFields = $customFieldsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get tags
    $tagsStmt = $pdo->prepare("
        SELECT t.nome_tag, t.colore
        FROM socio_tags st
        INNER JOIN tags t ON st.tag_id = t.id
        WHERE st.socio_id = :socio_id
    ");
    $tagsStmt->execute([':socio_id' => $socio_id]);
    $tags = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'data' => [
            'anagrafica' => $socio,
            'campi_personalizzati' => $customFields,
            'tags' => $tags
        ]
    ];

    sendJsonResponse($response);
}

/**
 * Get member's active membership card
 */
function getSocioTessera($pdo, $associazione_id) {
    if (!isset($_GET['id'])) {
        sendErrorResponse('Parametro id richiesto', 400);
    }

    $socio_id = $_GET['id'];

    // First, verify the member belongs to this association
    $checkStmt = $pdo->prepare("
        SELECT id FROM soci WHERE id = :socio_id AND associazione_id = :associazione_id
    ");
    $checkStmt->execute([
        ':socio_id' => $socio_id,
        ':associazione_id' => $associazione_id
    ]);

    if (!$checkStmt->fetch()) {
        sendErrorResponse('Socio non trovato', 404);
    }

    // Get active membership card
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
            s.nome as socio_nome,
            s.cognome as socio_cognome,
            s.numero_socio
        FROM tessere t
        INNER JOIN soci s ON t.socio_id = s.id
        WHERE t.socio_id = :socio_id
            AND t.associazione_id = :associazione_id
            AND t.stato = 'Attiva'
        ORDER BY t.data_emissione DESC
        LIMIT 1
    ");

    $stmt->execute([
        ':socio_id' => $socio_id,
        ':associazione_id' => $associazione_id
    ]);

    $tessera = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tessera) {
        sendJsonResponse([
            'success' => true,
            'data' => null,
            'message' => 'Nessuna tessera attiva trovata'
        ]);
    } else {
        // Check if card is actually expired
        $is_expired = strtotime($tessera['data_scadenza']) < time();

        sendJsonResponse([
            'success' => true,
            'data' => [
                'tessera' => $tessera,
                'is_expired' => $is_expired,
                'giorni_scadenza' => $is_expired ? 0 : floor((strtotime($tessera['data_scadenza']) - time()) / 86400)
            ]
        ]);
    }
}

/**
 * Get member's sede (location) information
 */
function getSocioSede($pdo, $associazione_id) {
    if (!isset($_GET['id'])) {
        sendErrorResponse('Parametro id richiesto', 400);
    }

    $socio_id = $_GET['id'];

    $stmt = $pdo->prepare("
        SELECT
            se.id,
            se.nome,
            se.indirizzo,
            se.citta,
            se.provincia,
            se.cap,
            se.email,
            se.telefono,
            se.responsabile,
            s.nome as socio_nome,
            s.cognome as socio_cognome
        FROM soci s
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE s.id = :socio_id AND s.associazione_id = :associazione_id
    ");

    $stmt->execute([
        ':socio_id' => $socio_id,
        ':associazione_id' => $associazione_id
    ]);

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        sendErrorResponse('Socio non trovato', 404);
    }

    $response = [
        'success' => true,
        'data' => [
            'socio' => [
                'nome' => $result['socio_nome'],
                'cognome' => $result['socio_cognome']
            ],
            'sede' => $result['id'] ? [
                'id' => $result['id'],
                'nome' => $result['nome'],
                'indirizzo' => $result['indirizzo'],
                'citta' => $result['citta'],
                'provincia' => $result['provincia'],
                'cap' => $result['cap'],
                'email' => $result['email'],
                'telefono' => $result['telefono'],
                'responsabile' => $result['responsabile']
            ] : null,
            'message' => $result['id'] ? null : 'Nessuna sede associata al socio'
        ]
    ];

    sendJsonResponse($response);
}

/**
 * Search members by various fields
 */
function searchSoci($pdo, $associazione_id) {
    $where = ["s.associazione_id = :associazione_id"];
    $params = [':associazione_id' => $associazione_id];

    // Build dynamic search filters
    if (isset($_GET['nome']) && $_GET['nome'] !== '') {
        $where[] = "s.nome LIKE :nome";
        $params[':nome'] = '%' . $_GET['nome'] . '%';
    }

    if (isset($_GET['cognome']) && $_GET['cognome'] !== '') {
        $where[] = "s.cognome LIKE :cognome";
        $params[':cognome'] = '%' . $_GET['cognome'] . '%';
    }

    if (isset($_GET['email']) && $_GET['email'] !== '') {
        $where[] = "s.email LIKE :email";
        $params[':email'] = '%' . $_GET['email'] . '%';
    }

    if (isset($_GET['codice_fiscale']) && $_GET['codice_fiscale'] !== '') {
        $where[] = "s.codice_fiscale = :codice_fiscale";
        $params[':codice_fiscale'] = strtoupper($_GET['codice_fiscale']);
    }

    if (isset($_GET['numero_socio']) && $_GET['numero_socio'] !== '') {
        $where[] = "s.numero_socio = :numero_socio";
        $params[':numero_socio'] = $_GET['numero_socio'];
    }

    if (isset($_GET['telefono']) && $_GET['telefono'] !== '') {
        $where[] = "s.telefono LIKE :telefono";
        $params[':telefono'] = '%' . $_GET['telefono'] . '%';
    }

    if (isset($_GET['stato']) && $_GET['stato'] !== '') {
        $where[] = "s.stato = :stato";
        $params[':stato'] = $_GET['stato'];
    }

    if (isset($_GET['citta']) && $_GET['citta'] !== '') {
        $where[] = "s.citta LIKE :citta";
        $params[':citta'] = '%' . $_GET['citta'] . '%';
    }

    if (isset($_GET['provincia']) && $_GET['provincia'] !== '') {
        $where[] = "s.provincia = :provincia";
        $params[':provincia'] = strtoupper($_GET['provincia']);
    }

    if (isset($_GET['sede_id']) && $_GET['sede_id'] !== '') {
        $where[] = "s.sede_id = :sede_id";
        $params[':sede_id'] = $_GET['sede_id'];
    }

    if (isset($_GET['tipo_socio_id']) && $_GET['tipo_socio_id'] !== '') {
        $where[] = "s.tipo_socio_id = :tipo_socio_id";
        $params[':tipo_socio_id'] = $_GET['tipo_socio_id'];
    }

    if (isset($_GET['categoria_socio_id']) && $_GET['categoria_socio_id'] !== '') {
        $where[] = "s.categoria_socio_id = :categoria_socio_id";
        $params[':categoria_socio_id'] = $_GET['categoria_socio_id'];
    }

    // Generic search (searches across multiple fields)
    if (isset($_GET['q']) && $_GET['q'] !== '') {
        $where[] = "(s.nome LIKE :q OR s.cognome LIKE :q OR s.email LIKE :q OR s.numero_socio LIKE :q OR s.codice_fiscale LIKE :q)";
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
        FROM soci s
        WHERE $whereClause
    ");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get results
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.numero_socio,
            s.nome,
            s.cognome,
            s.data_nascita,
            s.codice_fiscale,
            s.email,
            s.telefono,
            s.indirizzo,
            s.citta,
            s.provincia,
            s.cap,
            s.data_iscrizione,
            s.stato,
            ts.nome as tipo_socio,
            cs.nome as categoria_socio,
            se.nome as sede_nome,
            se.id as sede_id
        FROM soci s
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
        LEFT JOIN sedi se ON s.sede_id = se.id
        WHERE $whereClause
        ORDER BY s.cognome ASC, s.nome ASC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $soci = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        'success' => true,
        'data' => $soci,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ];

    sendJsonResponse($response);
}
