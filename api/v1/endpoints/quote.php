<?php
/**
 * API v1 - Quote Endpoints
 *
 * GET  /quote              - Lista quote dell'associazione
 * GET  /quote/{socio_id}   - Quote di un socio specifico
 */

/**
 * @param array $segments URL path segments after 'quote'
 */
function handleQuote(PDO $pdo, array $apiKey, string $associazioneId, string $method, array $segments): void
{
    if ($method !== 'GET') {
        apiError('Metodo non supportato. Usa GET.', 405, 'method_not_allowed');
    }

    apiRequirePermission($apiKey, 'quote:read');

    // GET /quote/{socio_id} - quote di un socio
    if (isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        // Verify socio belongs to association
        $checkStmt = $pdo->prepare("SELECT id, nome, cognome, numero_socio FROM soci WHERE id = ? AND associazione_id = ?");
        $checkStmt->execute([$segments[1], $associazioneId]);
        $socio = $checkStmt->fetch();

        if (!$socio) {
            apiError('Socio non trovato.', 404, 'not_found');
        }

        $stmt = $pdo->prepare("
            SELECT id, anno, importo, data_scadenza, data_pagamento, stato, tipo, note
            FROM quote
            WHERE socio_id = ? AND associazione_id = ?
            ORDER BY anno DESC
        ");
        $stmt->execute([$segments[1], $associazioneId]);

        apiResponse([
            'success' => true,
            'socio' => $socio,
            'quote' => $stmt->fetchAll(),
        ]);
    }

    // GET /quote - list with filters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $anno = $_GET['anno'] ?? null;
    $stato = $_GET['stato'] ?? null;

    $where = ['q.associazione_id = ?'];
    $params = [$associazioneId];

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

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT q.id, q.anno, q.importo, q.data_scadenza, q.data_pagamento, q.stato, q.tipo,
               s.nome as socio_nome, s.cognome as socio_cognome, s.numero_socio
        FROM quote q
        JOIN soci s ON q.socio_id = s.id
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
