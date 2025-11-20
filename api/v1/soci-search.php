<?php
/**
 * API Endpoint: Ricerca Specifica Soci
 *
 * Endpoints per ricerca diretta con singolo campo:
 * - GET /api/v1/soci-by-nome.php?nome={nome} - Cerca per nome
 * - GET /api/v1/soci-by-cognome.php?cognome={cognome} - Cerca per cognome
 * - GET /api/v1/soci-by-codice-fiscale.php?cf={codice_fiscale} - Cerca per CF (exact match)
 * - GET /api/v1/soci-by-numero-socio.php?numero={numero} - Cerca per numero socio (exact match)
 * - GET /api/v1/soci-by-email.php?email={email} - Cerca per email (exact match)
 * - GET /api/v1/soci-by-telefono.php?telefono={telefono} - Cerca per telefono
 * - GET /api/v1/soci-by-numero-tessera.php?numero={numero_tessera} - Cerca per numero tessera (exact match)
 * - GET /api/v1/soci-by-sede.php?sede_id={sede_id} - Lista soci di una sede
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../auth.php';

// Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// Enable CORS
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

// Check permission
if (!hasPermission($auth, 'soci')) {
    sendErrorResponse('Permesso negato per accedere ai dati dei soci', 403);
}

$associazione_id = $auth['associazione_id'];

// Determine which endpoint was called based on script name
$script_name = basename($_SERVER['SCRIPT_NAME']);

try {
    switch ($script_name) {
        case 'soci-by-nome.php':
            searchByNome($pdo, $associazione_id);
            break;
        case 'soci-by-cognome.php':
            searchByCognome($pdo, $associazione_id);
            break;
        case 'soci-by-codice-fiscale.php':
            searchByCodiceFiscale($pdo, $associazione_id);
            break;
        case 'soci-by-numero-socio.php':
            searchByNumeroSocio($pdo, $associazione_id);
            break;
        case 'soci-by-email.php':
            searchByEmail($pdo, $associazione_id);
            break;
        case 'soci-by-telefono.php':
            searchByTelefono($pdo, $associazione_id);
            break;
        case 'soci-by-numero-tessera.php':
            searchByNumeroTessera($pdo, $associazione_id);
            break;
        case 'soci-by-sede.php':
            searchBySede($pdo, $associazione_id);
            break;
        default:
            sendErrorResponse('Endpoint non valido', 404);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    sendErrorResponse('Errore interno del server', 500);
}

/**
 * Helper function to get full socio data with related info
 */
function getSocioFullData($pdo, $socio_id, $associazione_id) {
    // Get main socio data
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
            se.id as sede_id,
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
        return null;
    }

    // Get active membership card
    $tesseraStmt = $pdo->prepare("
        SELECT
            id,
            numero_tessera,
            anno_validita,
            data_emissione,
            data_scadenza,
            tipo_scadenza,
            stato,
            qr_code_url
        FROM tessere
        WHERE socio_id = :socio_id
            AND associazione_id = :associazione_id
            AND stato = 'Attiva'
        ORDER BY data_emissione DESC
        LIMIT 1
    ");
    $tesseraStmt->execute([
        ':socio_id' => $socio_id,
        ':associazione_id' => $associazione_id
    ]);
    $tessera = $tesseraStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate tessera expiration
    $tessera_info = null;
    if ($tessera) {
        $is_expired = strtotime($tessera['data_scadenza']) < time();
        $tessera_info = [
            'tessera' => $tessera,
            'is_expired' => $is_expired,
            'giorni_scadenza' => $is_expired ? 0 : floor((strtotime($tessera['data_scadenza']) - time()) / 86400)
        ];
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

    return [
        'anagrafica' => $socio,
        'tessera_attiva' => $tessera_info,
        'campi_personalizzati' => $customFields,
        'tags' => $tags
    ];
}

/**
 * Search by nome (first name)
 */
function searchByNome($pdo, $associazione_id) {
    if (!isset($_GET['nome']) || trim($_GET['nome']) === '') {
        sendErrorResponse('Parametro "nome" richiesto', 400);
    }

    $nome = trim($_GET['nome']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND nome LIKE :nome
        ORDER BY cognome ASC, nome ASC
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':nome' => '%' . $nome . '%'
    ]);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = getSocioFullData($pdo, $row['id'], $associazione_id);
        if ($data) {
            $results[] = $data;
        }
    }

    sendJsonResponse([
        'success' => true,
        'data' => $results,
        'total' => count($results),
        'search_field' => 'nome',
        'search_value' => $nome
    ]);
}

/**
 * Search by cognome (last name)
 */
function searchByCognome($pdo, $associazione_id) {
    if (!isset($_GET['cognome']) || trim($_GET['cognome']) === '') {
        sendErrorResponse('Parametro "cognome" richiesto', 400);
    }

    $cognome = trim($_GET['cognome']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND cognome LIKE :cognome
        ORDER BY cognome ASC, nome ASC
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':cognome' => '%' . $cognome . '%'
    ]);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = getSocioFullData($pdo, $row['id'], $associazione_id);
        if ($data) {
            $results[] = $data;
        }
    }

    sendJsonResponse([
        'success' => true,
        'data' => $results,
        'total' => count($results),
        'search_field' => 'cognome',
        'search_value' => $cognome
    ]);
}

/**
 * Search by codice fiscale (exact match)
 */
function searchByCodiceFiscale($pdo, $associazione_id) {
    if (!isset($_GET['cf']) || trim($_GET['cf']) === '') {
        sendErrorResponse('Parametro "cf" (codice fiscale) richiesto', 400);
    }

    $cf = strtoupper(trim($_GET['cf']));

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND codice_fiscale = :cf
        LIMIT 1
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':cf' => $cf
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse([
            'success' => true,
            'data' => null,
            'message' => 'Socio non trovato con questo codice fiscale'
        ]);
    }

    $data = getSocioFullData($pdo, $row['id'], $associazione_id);

    sendJsonResponse([
        'success' => true,
        'data' => $data,
        'search_field' => 'codice_fiscale',
        'search_value' => $cf
    ]);
}

/**
 * Search by numero socio (exact match)
 */
function searchByNumeroSocio($pdo, $associazione_id) {
    if (!isset($_GET['numero']) || trim($_GET['numero']) === '') {
        sendErrorResponse('Parametro "numero" (numero socio) richiesto', 400);
    }

    $numero = trim($_GET['numero']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND numero_socio = :numero
        LIMIT 1
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':numero' => $numero
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse([
            'success' => true,
            'data' => null,
            'message' => 'Socio non trovato con questo numero'
        ]);
    }

    $data = getSocioFullData($pdo, $row['id'], $associazione_id);

    sendJsonResponse([
        'success' => true,
        'data' => $data,
        'search_field' => 'numero_socio',
        'search_value' => $numero
    ]);
}

/**
 * Search by email (exact match)
 */
function searchByEmail($pdo, $associazione_id) {
    if (!isset($_GET['email']) || trim($_GET['email']) === '') {
        sendErrorResponse('Parametro "email" richiesto', 400);
    }

    $email = trim($_GET['email']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND email = :email
        LIMIT 1
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':email' => $email
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse([
            'success' => true,
            'data' => null,
            'message' => 'Socio non trovato con questa email'
        ]);
    }

    $data = getSocioFullData($pdo, $row['id'], $associazione_id);

    sendJsonResponse([
        'success' => true,
        'data' => $data,
        'search_field' => 'email',
        'search_value' => $email
    ]);
}

/**
 * Search by telefono
 */
function searchByTelefono($pdo, $associazione_id) {
    if (!isset($_GET['telefono']) || trim($_GET['telefono']) === '') {
        sendErrorResponse('Parametro "telefono" richiesto', 400);
    }

    $telefono = trim($_GET['telefono']);

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND telefono LIKE :telefono
        ORDER BY cognome ASC, nome ASC
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':telefono' => '%' . $telefono . '%'
    ]);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = getSocioFullData($pdo, $row['id'], $associazione_id);
        if ($data) {
            $results[] = $data;
        }
    }

    sendJsonResponse([
        'success' => true,
        'data' => $results,
        'total' => count($results),
        'search_field' => 'telefono',
        'search_value' => $telefono
    ]);
}

/**
 * Search by numero tessera (exact match)
 */
function searchByNumeroTessera($pdo, $associazione_id) {
    if (!isset($_GET['numero']) || trim($_GET['numero']) === '') {
        sendErrorResponse('Parametro "numero" (numero tessera) richiesto', 400);
    }

    $numero_tessera = trim($_GET['numero']);

    $stmt = $pdo->prepare("
        SELECT t.socio_id
        FROM tessere t
        WHERE t.associazione_id = :associazione_id
            AND t.numero_tessera = :numero_tessera
        LIMIT 1
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':numero_tessera' => $numero_tessera
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        sendJsonResponse([
            'success' => true,
            'data' => null,
            'message' => 'Nessun socio trovato con questo numero tessera'
        ]);
    }

    $data = getSocioFullData($pdo, $row['socio_id'], $associazione_id);

    sendJsonResponse([
        'success' => true,
        'data' => $data,
        'search_field' => 'numero_tessera',
        'search_value' => $numero_tessera
    ]);
}

/**
 * Search by sede (list all members of a location)
 */
function searchBySede($pdo, $associazione_id) {
    if (!isset($_GET['sede_id']) || trim($_GET['sede_id']) === '') {
        sendErrorResponse('Parametro "sede_id" richiesto', 400);
    }

    $sede_id = trim($_GET['sede_id']);

    // Verify sede exists and belongs to association
    $checkStmt = $pdo->prepare("
        SELECT id, nome FROM sedi WHERE id = :sede_id AND associazione_id = :associazione_id
    ");
    $checkStmt->execute([
        ':sede_id' => $sede_id,
        ':associazione_id' => $associazione_id
    ]);
    $sede = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sede) {
        sendErrorResponse('Sede non trovata', 404);
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM soci
        WHERE associazione_id = :associazione_id
            AND sede_id = :sede_id
        ORDER BY cognome ASC, nome ASC
    ");

    $stmt->execute([
        ':associazione_id' => $associazione_id,
        ':sede_id' => $sede_id
    ]);

    $results = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = getSocioFullData($pdo, $row['id'], $associazione_id);
        if ($data) {
            $results[] = $data;
        }
    }

    sendJsonResponse([
        'success' => true,
        'data' => $results,
        'total' => count($results),
        'search_field' => 'sede_id',
        'search_value' => $sede_id,
        'sede_info' => $sede
    ]);
}
