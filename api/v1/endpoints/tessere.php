<?php
/**
 * API v1 - Tessere Endpoints
 *
 * GET  /tessere              - Lista tessere dell'associazione
 * GET  /tessere/verify       - Verifica tessera (pubblica, no auth)
 * GET  /tessere/search?q=    - Cerca per numero tessera
 * GET  /tessere/{id}         - Dettaglio tessera
 */

/**
 * Public: verify a tessera by ID (no auth required)
 */
function handleTessereVerify(PDO $pdo): void
{
    $tesseraId = $_GET['tessera_id'] ?? '';
    if (empty($tesseraId) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $tesseraId)) {
        apiError('Parametro tessera_id mancante o non valido (UUID).', 400, 'invalid_tessera_id');
    }

    $stmt = $pdo->prepare("
        SELECT t.id, t.numero_tessera, t.anno_validita, t.data_emissione, t.data_scadenza,
               t.stato, t.tipo_scadenza,
               s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio,
               a.nome as associazione_nome
        FROM tessere t
        JOIN soci s ON t.socio_id = s.id
        JOIN associazioni a ON t.associazione_id = a.id
        WHERE t.id = ?
    ");
    $stmt->execute([$tesseraId]);
    $tessera = $stmt->fetch();

    if (!$tessera) {
        apiError('Tessera non trovata.', 404, 'tessera_not_found');
    }

    $isValid = $tessera['stato'] === 'Attiva' && $tessera['data_scadenza'] >= date('Y-m-d');

    apiResponse([
        'success' => true,
        'valid' => $isValid,
        'tessera' => [
            'numero_tessera' => $tessera['numero_tessera'],
            'anno_validita' => (int)$tessera['anno_validita'],
            'data_emissione' => $tessera['data_emissione'],
            'data_scadenza' => $tessera['data_scadenza'],
            'stato' => $tessera['stato'],
        ],
        'socio' => [
            'nome' => $tessera['socio_nome'],
            'cognome' => $tessera['socio_cognome'],
            'numero_socio' => $tessera['numero_socio'],
        ],
        'associazione' => $tessera['associazione_nome'],
    ]);
}

/**
 * Authenticated tessere endpoints
 *
 * @param array $segments URL path segments after 'tessere'
 */
function handleTessere(PDO $pdo, array $apiKey, string $associazioneId, string $method, array $segments): void
{
    if ($method !== 'GET') {
        apiError('Metodo non supportato. Usa GET.', 405, 'method_not_allowed');
    }

    apiRequirePermission($apiKey, 'tessere:read');

    // GET /tessere/search?q=...
    if (isset($segments[1]) && $segments[1] === 'search') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            apiError('Parametro q obbligatorio (min 1 carattere).', 400, 'missing_query');
        }

        $stmt = $pdo->prepare("
            SELECT t.id, t.numero_tessera, t.anno_validita, t.data_emissione, t.data_scadenza, t.stato,
                   s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio, s.id as socio_id
            FROM tessere t
            JOIN soci s ON t.socio_id = s.id
            WHERE t.associazione_id = ?
              AND (t.numero_tessera LIKE ? OR s.cognome LIKE ? OR s.nome LIKE ? OR s.numero_socio LIKE ?)
            ORDER BY t.anno_validita DESC, t.numero_tessera
            LIMIT 50
        ");
        $term = "%$q%";
        $stmt->execute([$associazioneId, $term, $term, $term, $term]);

        apiResponse([
            'success' => true,
            'count' => $stmt->rowCount(),
            'tessere' => $stmt->fetchAll(),
        ]);
    }

    // GET /tessere/verify?tessera_id=...
    if (isset($segments[1]) && $segments[1] === 'verify') {
        handleTessereVerify($pdo);
    }

    // GET /tessere/{id}
    if (isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        $stmt = $pdo->prepare("
            SELECT t.*, s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio, s.id as socio_id
            FROM tessere t
            JOIN soci s ON t.socio_id = s.id
            WHERE t.id = ? AND t.associazione_id = ?
        ");
        $stmt->execute([$segments[1], $associazioneId]);
        $tessera = $stmt->fetch();

        if (!$tessera) {
            apiError('Tessera non trovata.', 404, 'not_found');
        }

        apiResponse(['success' => true, 'tessera' => $tessera]);
    }

    // GET /tessere - list
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $anno = $_GET['anno'] ?? null;
    $stato = $_GET['stato'] ?? null;

    $where = ['t.associazione_id = ?'];
    $params = [$associazioneId];

    if ($anno !== null && is_numeric($anno)) {
        $where[] = 't.anno_validita = ?';
        $params[] = (int)$anno;
    }
    if ($stato !== null && in_array($stato, ['Attiva', 'Scaduta', 'Sospesa', 'Annullata'], true)) {
        $where[] = 't.stato = ?';
        $params[] = $stato;
    }

    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tessere t WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT t.id, t.numero_tessera, t.anno_validita, t.data_emissione, t.data_scadenza, t.stato,
               s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio
        FROM tessere t
        JOIN soci s ON t.socio_id = s.id
        WHERE $whereClause
        ORDER BY t.anno_validita DESC, t.numero_tessera
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    apiResponse([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'tessere' => $stmt->fetchAll(),
    ]);
}
