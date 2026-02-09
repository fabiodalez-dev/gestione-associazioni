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
 * @param array $segments URL path segments after 'soci'
 */
function handleSoci(PDO $pdo, array $apiKey, string $associazioneId, string $method, array $segments): void
{
    // GET /soci/search?q=...
    if ($method === 'GET' && isset($segments[1]) && $segments[1] === 'search') {
        apiRequirePermission($apiKey, 'soci:read');
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            apiError('Parametro q obbligatorio (min 2 caratteri).', 400, 'missing_query');
        }

        $stmt = $pdo->prepare("
            SELECT id, numero_socio, nome, cognome, email, telefono, stato, data_iscrizione
            FROM soci
            WHERE associazione_id = ?
              AND (cognome LIKE ? OR nome LIKE ? OR numero_socio LIKE ? OR email LIKE ? OR codice_fiscale LIKE ?)
            ORDER BY cognome, nome
            LIMIT 50
        ");
        $term = "%$q%";
        $stmt->execute([$associazioneId, $term, $term, $term, $term, $term]);

        apiResponse([
            'success' => true,
            'count' => $stmt->rowCount(),
            'soci' => $stmt->fetchAll(),
        ]);
    }

    // GET /soci/{id}
    if ($method === 'GET' && isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        apiRequirePermission($apiKey, 'soci:read');

        $stmt = $pdo->prepare("
            SELECT s.id, s.numero_socio, s.nome, s.cognome, s.email, s.telefono,
                   s.data_nascita, s.codice_fiscale, s.indirizzo, s.citta, s.provincia, s.cap,
                   s.data_iscrizione, s.stato, s.note,
                   ts.nome as tipo_socio, cs.nome as categoria_socio
            FROM soci s
            LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
            LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
            WHERE s.id = ? AND s.associazione_id = ?
        ");
        $stmt->execute([$segments[1], $associazioneId]);
        $socio = $stmt->fetch();

        if (!$socio) {
            apiError('Socio non trovato.', 404, 'not_found');
        }

        // Fetch tags
        $tagStmt = $pdo->prepare("SELECT t.nome_tag, t.colore FROM socio_tags st JOIN tags t ON st.tag_id = t.id WHERE st.socio_id = ?");
        $tagStmt->execute([$segments[1]]);
        $socio['tags'] = $tagStmt->fetchAll();

        // Fetch tessere attive
        $tesseraStmt = $pdo->prepare("SELECT id, numero_tessera, anno_validita, data_scadenza, stato FROM tessere WHERE socio_id = ? AND associazione_id = ? ORDER BY anno_validita DESC LIMIT 5");
        $tesseraStmt->execute([$segments[1], $associazioneId]);
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

        // Verify socio exists and belongs to this association
        $checkStmt = $pdo->prepare("SELECT id FROM soci WHERE id = ? AND associazione_id = ?");
        $checkStmt->execute([$segments[1], $associazioneId]);
        if (!$checkStmt->fetch()) {
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
        $params[] = $associazioneId;

        try {
            $sql = "UPDATE soci SET " . implode(', ', $updates) . " WHERE id = ? AND associazione_id = ?";
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

        $where = ['associazione_id = ?'];
        $params = [$associazioneId];

        if ($stato !== null && in_array($stato, ['Attivo', 'Sospeso', 'Radiato', 'Deceduto', 'Trasferito'], true)) {
            $where[] = 'stato = ?';
            $params[] = $stato;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM soci WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $params[] = $limit;
        $params[] = $offset;
        $stmt = $pdo->prepare("
            SELECT id, numero_socio, nome, cognome, email, telefono, stato, data_iscrizione
            FROM soci
            WHERE $whereClause
            ORDER BY cognome, nome
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
