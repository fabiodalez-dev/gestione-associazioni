<?php
/**
 * API v1 Router
 *
 * Entry point for all REST API requests.
 * Authenticates via Bearer token (api_keys table),
 * routes to the correct endpoint handler.
 *
 * URL format: /api/v1/{endpoint}
 * Examples:
 *   GET  /api/v1/tessere/verify?tessera_id=UUID
 *   GET  /api/v1/soci
 *   POST /api/v1/eventi/{id}/checkin
 *   GET  /api/v1/associazioni  (global keys only)
 */

// Prevent session start (API is stateless)
define('API_REQUEST', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/api_middleware.php';

// Ensure api_keys table exists
ensureApiKeysTable($pdo);

// CORS
apiCorsHeaders();
apiHandlePreflight();

// Determine endpoint path from PATH_INFO or query string
$pathInfo = $_SERVER['PATH_INFO'] ?? $_SERVER['ORIG_PATH_INFO'] ?? '';
if (empty($pathInfo) && isset($_GET['endpoint'])) {
    $pathInfo = '/' . ltrim($_GET['endpoint'], '/');
}
$pathInfo = trim($pathInfo, '/');
$segments = $pathInfo !== '' ? explode('/', $pathInfo) : [];

$method = apiGetMethod();

// Public endpoint: tessera verification (no auth required)
if (count($segments) >= 2 && $segments[0] === 'tessere' && $segments[1] === 'verify') {
    require __DIR__ . '/endpoints/tessere.php';
    handleTessereVerify($pdo);
    exit;
}

// All other endpoints require authentication
$apiKey = apiAuthenticate($pdo);
$associazioneId = $apiKey['associazione_id']; // string|null

// Optional association filter from query string (for global keys)
$filterAssocId = $_GET['associazione_id'] ?? null;

// Route to endpoint handler
if (empty($segments)) {
    $response = [
        'success' => true,
        'message' => 'Associazione Soci Manager API v1',
        'endpoints' => [
            'GET /tessere' => 'Lista tessere',
            'GET /tessere/verify?tessera_id={id}' => 'Verifica tessera (pubblica)',
            'GET /tessere/search?q={query}' => 'Cerca tessera per numero',
            'GET /soci' => 'Lista soci',
            'GET /soci/{id}' => 'Dettaglio socio',
            'GET /soci/search?q={query}' => 'Cerca socio',
            'GET /eventi' => 'Lista eventi',
            'GET /eventi/{id}' => 'Dettaglio evento',
            'POST /eventi/{id}/checkin' => 'Check-in partecipante',
            'GET /quote' => 'Lista quote',
            'GET /quote/{socio_id}' => 'Quote di un socio',
        ],
    ];

    if ($apiKey['is_globale']) {
        $response['associazione'] = 'Tutte (chiave globale)';
        $response['endpoints']['GET /associazioni'] = 'Lista associazioni (solo chiavi globali)';
    } else {
        $response['associazione'] = $apiKey['associazione_nome'] ?? '';
    }

    apiResponse($response);
}

$resource = $segments[0];

switch ($resource) {
    case 'associazioni':
        if (!$apiKey['is_globale']) {
            apiError('Endpoint disponibile solo per chiavi globali.', 403, 'global_only');
        }
        if ($method !== 'GET') {
            apiError('Metodo non supportato. Usa GET.', 405, 'method_not_allowed');
        }
        $stmt = $pdo->query("SELECT id, nome, email, citta, provincia, attiva FROM associazioni WHERE attiva = 1 ORDER BY nome");
        $associazioni = $stmt->fetchAll();
        apiResponse([
            'success' => true,
            'count' => count($associazioni),
            'associazioni' => $associazioni,
        ]);
        break;

    case 'tessere':
        require __DIR__ . '/endpoints/tessere.php';
        handleTessere($pdo, $apiKey, $associazioneId, $method, $segments, $filterAssocId);
        break;

    case 'soci':
        require __DIR__ . '/endpoints/soci.php';
        handleSoci($pdo, $apiKey, $associazioneId, $method, $segments, $filterAssocId);
        break;

    case 'eventi':
        require __DIR__ . '/endpoints/eventi.php';
        handleEventi($pdo, $apiKey, $associazioneId, $method, $segments, $filterAssocId);
        break;

    case 'quote':
        require __DIR__ . '/endpoints/quote.php';
        handleQuote($pdo, $apiKey, $associazioneId, $method, $segments, $filterAssocId);
        break;

    default:
        apiError("Endpoint '$resource' non trovato.", 404, 'not_found');
}
