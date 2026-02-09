<?php
/**
 * API v1 - Soci Endpoints
 *
 * GET  /soci              - Lista soci dell'associazione
 * GET  /soci/search?q=    - Cerca socio per nome/cognome/numero
 * GET  /soci/{id}         - Dettaglio socio
 * PUT  /soci/{id}         - Modifica dati socio
 */

/**
 * @param ?string $associazioneId  null = global key
 * @param ?string $filterAssocId   optional filter from ?associazione_id=
 * @param array $segments URL path segments after 'soci'
 */
function handleSoci(PDO $pdo, array $apiKey, ?string $associazioneId, string $method, array $segments, ?string $filterAssocId = null): void
{
    $isGlobal = ($associazioneId === null);

    // GET /soci/search?q=...
    if ($method === 'GET' && isset($segments[1]) && $segments[1] === 'search') {
        apiRequirePermission($apiKey, 'soci:read');
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            apiError('Parametro q obbligatorio (min 2 caratteri).', 400, 'missing_query');
        }

        $assocFilter = apiAssociationFilter('s.associazione_id', $associazioneId, $filterAssocId);
        $where = $assocFilter['where'];
        $params = $assocFilter['params'];
        $where .= ' AND (s.cognome LIKE ? OR s.nome LIKE ? OR s.numero_socio LIKE ? OR s.email LIKE ? OR s.codice_fiscale LIKE ?)';
        $term = "%$q%";
        $params = array_merge($params, [$term, $term, $term, $term, $term]);

        $selectExtra = $isGlobal ? ', a.nome as associazione_nome, s.associazione_id' : '';
        $joinExtra = $isGlobal ? 'JOIN associazioni a ON s.associazione_id = a.id' : '';

        $stmt = $pdo->prepare("
            SELECT s.id, s.numero_socio, s.nome, s.cognome, s.email, s.telefono, s.stato, s.data_iscrizione
                   $selectExtra
            FROM soci s
            $joinExtra
            WHERE $where
            ORDER BY s.cognome, s.nome
            LIMIT 50
        ");
        $stmt->execute($params);

        apiResponse([
            'success' => true,
            'count' => $stmt->rowCount(),
            'soci' => $stmt->fetchAll(),
        ]);
    }

    // GET /soci/{id}
    if ($method === 'GET' && isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        apiRequirePermission($apiKey, 'soci:read');

        $where = 's.id = ?';
        $params = [$segments[1]];

        if ($associazioneId !== null) {
            $where .= ' AND s.associazione_id = ?';
            $params[] = $associazioneId;
        }

        $selectExtra = $isGlobal ? ', a.nome as associazione_nome, s.associazione_id' : '';
        $joinExtra = $isGlobal ? 'JOIN associazioni a ON s.associazione_id = a.id' : '';

        $stmt = $pdo->prepare("
            SELECT s.id, s.numero_socio, s.nome, s.cognome, s.email, s.telefono,
                   s.data_nascita, s.codice_fiscale, s.indirizzo, s.citta, s.provincia, s.cap,
                   s.data_iscrizione, s.stato, s.note,
                   ts.nome as tipo_socio, cs.nome as categoria_socio
                   $selectExtra
            FROM soci s
            LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
            LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
            $joinExtra
            WHERE $where
        ");
        $stmt->execute($params);
        $socio = $stmt->fetch();

        if (!$socio) {
            apiError('Socio non trovato.', 404, 'not_found');
        }

        // Fetch tags
        $tagStmt = $pdo->prepare("SELECT t.nome_tag, t.colore FROM socio_tags st JOIN tags t ON st.tag_id = t.id WHERE st.socio_id = ?");
        $tagStmt->execute([$segments[1]]);
        $socio['tags'] = $tagStmt->fetchAll();

        // Fetch tessere attive
        $tesseraWhere = 'socio_id = ?';
        $tesseraParams = [$segments[1]];
        if ($associazioneId !== null) {
            $tesseraWhere .= ' AND associazione_id = ?';
            $tesseraParams[] = $associazioneId;
        }
        $tesseraStmt = $pdo->prepare("SELECT id, numero_tessera, anno_validita, data_scadenza, stato FROM tessere WHERE $tesseraWhere ORDER BY anno_validita DESC LIMIT 5");
        $tesseraStmt->execute($tesseraParams);
        $socio['tessere'] = $tesseraStmt->fetchAll();

        apiResponse(['success' => true, 'socio' => $socio]);
    }

    // PUT /soci/{id}
    if ($method === 'PUT' && isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        apiRequirePermission($apiKey, 'soci:write');

        $body = apiGetJsonBody();
        if (empty($body)) {
            apiError('Body JSON vuoto.', 400, 'empty_body');
        }

        // Verify socio exists (and belongs to association if scoped)
        $checkWhere = 'id = ?';
        $checkParams = [$segments[1]];
        if ($associazioneId !== null) {
            $checkWhere .= ' AND associazione_id = ?';
            $checkParams[] = $associazioneId;
        }
        $checkStmt = $pdo->prepare("SELECT id, associazione_id FROM soci WHERE $checkWhere");
        $checkStmt->execute($checkParams);
        $socioRow = $checkStmt->fetch();
        if (!$socioRow) {
            apiError('Socio non trovato.', 404, 'not_found');
        }

        // Allowed fields for update
        $allowedFields = ['nome', 'cognome', 'email', 'telefono', 'indirizzo', 'citta', 'provincia', 'cap', 'stato', 'note'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $value = is_string($body[$field]) ? cleanInput($body[$field]) : $body[$field];
                // Validate stato enum
                if ($field === 'stato' && !in_array($value, ['Attivo', 'Sospeso', 'Radiato', 'Deceduto', 'Trasferito'], true)) {
                    apiError("Stato non valido. Valori: Attivo, Sospeso, Radiato, Deceduto, Trasferito.", 400, 'invalid_stato');
                }
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            apiError('Nessun campo valido da aggiornare.', 400, 'no_valid_fields');
        }

        $params[] = $segments[1];
        $updateWhere = 'id = ?';
        if ($associazioneId !== null) {
            $updateWhere .= ' AND associazione_id = ?';
            $params[] = $associazioneId;
        }

        try {
            $sql = "UPDATE soci SET " . implode(', ', $updates) . " WHERE $updateWhere";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            apiResponse(['success' => true, 'message' => 'Socio aggiornato.']);
        } catch (PDOException $e) {
            error_log('API soci PUT error: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                apiError('Email già presente per un altro socio.', 409, 'duplicate_email');
            }
            apiError('Errore durante l\'aggiornamento.', 500, 'db_error');
        }
    }

    // GET /soci - list
    if ($method === 'GET') {
        apiRequirePermission($apiKey, 'soci:read');

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $stato = $_GET['stato'] ?? null;

        $assocFilter = apiAssociationFilter('s.associazione_id', $associazioneId, $filterAssocId);
        $where = [$assocFilter['where']];
        $params = $assocFilter['params'];

        if ($stato !== null && in_array($stato, ['Attivo', 'Sospeso', 'Radiato', 'Deceduto', 'Trasferito'], true)) {
            $where[] = 's.stato = ?';
            $params[] = $stato;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM soci s WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $selectExtra = $isGlobal ? ', a.nome as associazione_nome, s.associazione_id' : '';
        $joinExtra = $isGlobal ? 'JOIN associazioni a ON s.associazione_id = a.id' : '';

        $params[] = $limit;
        $params[] = $offset;
        $stmt = $pdo->prepare("
            SELECT s.id, s.numero_socio, s.nome, s.cognome, s.email, s.telefono, s.stato, s.data_iscrizione
                   $selectExtra
            FROM soci s
            $joinExtra
            WHERE $whereClause
            ORDER BY s.cognome, s.nome
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);

        apiResponse([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'soci' => $stmt->fetchAll(),
        ]);
    }

    apiError('Metodo non supportato.', 405, 'method_not_allowed');
}
