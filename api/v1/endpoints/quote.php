<?php
/**
 * API v1 - Quote Endpoints
 *
 * GET  /quote              - Lista quote dell'associazione
 * GET  /quote/{socio_id}   - Quote di un socio specifico
 */

/**
 * @param ?string $associazioneId  null = global key
 * @param ?string $filterAssocId   optional filter from ?associazione_id=
 * @param array $segments URL path segments after 'quote'
 */
function handleQuote(PDO $pdo, array $apiKey, ?string $associazioneId, string $method, array $segments, ?string $filterAssocId = null): void
{
    if ($method !== 'GET') {
        apiError('Metodo non supportato. Usa GET.', 405, 'method_not_allowed');
    }

    apiRequirePermission($apiKey, 'quote:read');

    $isGlobal = ($associazioneId === null);

    // GET /quote/{socio_id} - quote di un socio
    if (isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        // Verify socio exists (and belongs to association if scoped)
        $checkWhere = 'id = ?';
        $checkParams = [$segments[1]];
        if ($associazioneId !== null) {
            $checkWhere .= ' AND associazione_id = ?';
            $checkParams[] = $associazioneId;
        }
        $checkStmt = $pdo->prepare("SELECT id, nome, cognome, numero_socio, associazione_id FROM soci WHERE $checkWhere");
        $checkStmt->execute($checkParams);
        $socio = $checkStmt->fetch();

        if (!$socio) {
            apiError('Socio non trovato.', 404, 'not_found');
        }

        $quoteWhere = 'socio_id = ? AND associazione_id = ?';
        $quoteParams = [$segments[1], $socio['associazione_id']];

        $stmt = $pdo->prepare("
            SELECT id, anno, importo, data_scadenza, data_pagamento, stato, tipo, note
            FROM quote
            WHERE $quoteWhere
            ORDER BY anno DESC
        ");
        $stmt->execute($quoteParams);

        $response = [
            'success' => true,
            'socio' => [
                'id' => $socio['id'],
                'nome' => $socio['nome'],
                'cognome' => $socio['cognome'],
                'numero_socio' => $socio['numero_socio'],
            ],
            'quote' => $stmt->fetchAll(),
        ];

        if ($isGlobal) {
            $response['socio']['associazione_id'] = $socio['associazione_id'];
        }

        apiResponse($response);
    }

    // GET /quote - list with filters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $anno = $_GET['anno'] ?? null;
    $stato = $_GET['stato'] ?? null;

    $assocFilter = apiAssociationFilter('q.associazione_id', $associazioneId, $filterAssocId);
    $where = [$assocFilter['where']];
    $params = $assocFilter['params'];

    if ($anno !== null && is_numeric($anno)) {
        $where[] = 'q.anno = ?';
        $params[] = (int)$anno;
    }
    if ($stato !== null && in_array($stato, ['Pagata', 'Da Pagare', 'Scaduta', 'In Scadenza'], true)) {
        $where[] = 'q.stato = ?';
        $params[] = $stato;
    }

    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM quote q WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $selectExtra = $isGlobal ? ', a.nome as associazione_nome, q.associazione_id' : '';
    $joinExtra = $isGlobal ? 'JOIN associazioni a ON q.associazione_id = a.id' : '';

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT q.id, q.anno, q.importo, q.data_scadenza, q.data_pagamento, q.stato, q.tipo,
               s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio
               $selectExtra
        FROM quote q
        JOIN soci s ON q.socio_id = s.id
        $joinExtra
        WHERE $whereClause
        ORDER BY q.anno DESC, s.cognome, s.nome
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    apiResponse([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'quote' => $stmt->fetchAll(),
    ]);
}
