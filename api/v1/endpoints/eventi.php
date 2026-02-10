<?php
/**
 * API v1 - Eventi Endpoints
 *
 * GET  /eventi              - Lista eventi dell'associazione
 * GET  /eventi/{id}         - Dettaglio evento con partecipanti
 * POST /eventi/{id}/checkin - Check-in di un socio a un evento
 */

/**
 * @param ?string $associazioneId  null = global key
 * @param ?string $filterAssocId   optional filter from ?associazione_id=
 * @param array $segments URL path segments after 'eventi'
 */
function handleEventi(PDO $pdo, array $apiKey, ?string $associazioneId, string $method, array $segments, ?string $filterAssocId = null): void
{
    $isGlobal = ($associazioneId === null);

    // POST /eventi/{id}/checkin
    if ($method === 'POST' && isset($segments[1], $segments[2]) && $segments[2] === 'checkin') {
        apiRequirePermission($apiKey, 'eventi:write');

        $eventoId = $segments[1];
        if (!preg_match('/^[a-f0-9-]{36}$/i', $eventoId)) {
            apiError('ID evento non valido.', 400, 'invalid_id');
        }

        // Verify event exists (scoped or global)
        $evWhere = 'id = ?';
        $evParams = [$eventoId];
        if ($associazioneId !== null) {
            $evWhere .= ' AND associazione_id = ?';
            $evParams[] = $associazioneId;
        }
        $evStmt = $pdo->prepare("SELECT id, titolo, associazione_id FROM eventi WHERE $evWhere");
        $evStmt->execute($evParams);
        $evento = $evStmt->fetch();
        if (!$evento) {
            apiError('Evento non trovato.', 404, 'not_found');
        }

        // For checkin, use the event's own associazione_id for lookups
        $eventAssocId = $evento['associazione_id'];

        $body = apiGetJsonBody();

        // Accept socio_id directly, or search by numero_tessera / numero_socio
        $socioId = $body['socio_id'] ?? null;
        $numeroTessera = $body['numero_tessera'] ?? null;
        $numeroSocio = $body['numero_socio'] ?? null;
        $tesseraId = $body['tessera_id'] ?? null;

        if ($socioId === null && $tesseraId !== null) {
            $tStmt = $pdo->prepare("SELECT socio_id FROM tessere WHERE id = ? AND associazione_id = ?");
            $tStmt->execute([$tesseraId, $eventAssocId]);
            $row = $tStmt->fetch();
            if ($row) {
                $socioId = $row['socio_id'];
            }
        }

        if ($socioId === null && $numeroTessera !== null) {
            $tStmt = $pdo->prepare("SELECT socio_id FROM tessere WHERE numero_tessera = ? AND associazione_id = ? ORDER BY anno_validita DESC LIMIT 1");
            $tStmt->execute([$numeroTessera, $eventAssocId]);
            $row = $tStmt->fetch();
            if ($row) {
                $socioId = $row['socio_id'];
            }
        }

        if ($socioId === null && $numeroSocio !== null) {
            $sStmt = $pdo->prepare("SELECT id FROM soci WHERE numero_socio = ? AND associazione_id = ?");
            $sStmt->execute([$numeroSocio, $eventAssocId]);
            $row = $sStmt->fetch();
            if ($row) {
                $socioId = $row['id'];
            }
        }

        if ($socioId === null) {
            apiError('Socio non identificato. Fornisci socio_id, tessera_id, numero_tessera o numero_socio.', 400, 'missing_socio');
        }

        // Verify socio exists and belongs to event's association
        $socioStmt = $pdo->prepare("SELECT id, nome, cognome, numero_socio, stato FROM soci WHERE id = ? AND associazione_id = ?");
        $socioStmt->execute([$socioId, $eventAssocId]);
        $socio = $socioStmt->fetch();

        if (!$socio) {
            apiError('Socio non trovato nell\'associazione.', 404, 'socio_not_found');
        }

        if ($socio['stato'] !== 'Attivo') {
            apiError('Il socio non è attivo (stato: ' . $socio['stato'] . ').', 400, 'socio_not_active');
        }

        // Check if already checked in
        $existStmt = $pdo->prepare("SELECT id, stato_partecipazione FROM eventi_partecipanti WHERE evento_id = ? AND socio_id = ?");
        $existStmt->execute([$eventoId, $socioId]);
        $existing = $existStmt->fetch();

        if ($existing && $existing['stato_partecipazione'] === 'Confermato') {
            apiResponse([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'Socio già registrato per questo evento.',
                'socio' => [
                    'nome' => $socio['nome'],
                    'cognome' => $socio['cognome'],
                    'numero_socio' => $socio['numero_socio'],
                ],
            ]);
        }

        try {
            if ($existing) {
                $updStmt = $pdo->prepare("UPDATE eventi_partecipanti SET stato_partecipazione = 'Confermato', data_conferma = NOW() WHERE id = ?");
                $updStmt->execute([$existing['id']]);
            } else {
                $insStmt = $pdo->prepare("INSERT INTO eventi_partecipanti (id, associazione_id, evento_id, socio_id, stato_partecipazione, data_conferma) VALUES (?, ?, ?, ?, 'Confermato', NOW())");
                $insStmt->execute([generateUuid(), $eventAssocId, $eventoId, $socioId]);
            }

            apiResponse([
                'success' => true,
                'already_checked_in' => false,
                'message' => 'Check-in effettuato con successo.',
                'socio' => [
                    'nome' => $socio['nome'],
                    'cognome' => $socio['cognome'],
                    'numero_socio' => $socio['numero_socio'],
                ],
                'evento' => $evento['titolo'],
            ]);
        } catch (PDOException $e) {
            error_log('API eventi checkin error: ' . $e->getMessage());
            apiError('Errore durante il check-in.', 500, 'db_error');
        }
    }

    if ($method !== 'GET') {
        apiError('Metodo non supportato.', 405, 'method_not_allowed');
    }

    apiRequirePermission($apiKey, 'eventi:read');

    // GET /eventi/{id}
    if (isset($segments[1]) && preg_match('/^[a-f0-9-]{36}$/i', $segments[1])) {
        $where = 'e.id = ?';
        $params = [$segments[1]];
        if ($associazioneId !== null) {
            $where .= ' AND e.associazione_id = ?';
            $params[] = $associazioneId;
        }

        $selectExtra = $isGlobal ? ', a.nome as associazione_nome, e.associazione_id' : '';
        $joinExtra = $isGlobal ? 'JOIN associazioni a ON e.associazione_id = a.id' : '';

        $stmt = $pdo->prepare("SELECT e.* $selectExtra FROM eventi e $joinExtra WHERE $where");
        $stmt->execute($params);
        $evento = $stmt->fetch();

        if (!$evento) {
            apiError('Evento non trovato.', 404, 'not_found');
        }

        // Fetch participants
        $eventAssocId = $evento['associazione_id'];
        $partStmt = $pdo->prepare("
            SELECT ep.stato_partecipazione, ep.data_conferma,
                   s.id as socio_id, s.nome, s.cognome, s.numero_socio
            FROM eventi_partecipanti ep
            JOIN soci s ON ep.socio_id = s.id
            WHERE ep.evento_id = ? AND ep.associazione_id = ?
            ORDER BY s.cognome, s.nome
        ");
        $partStmt->execute([$segments[1], $eventAssocId]);
        $evento['partecipanti'] = $partStmt->fetchAll();
        $evento['totale_partecipanti'] = count($evento['partecipanti']);

        apiResponse(['success' => true, 'evento' => $evento]);
    }

    // GET /eventi - list
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $futuri = isset($_GET['futuri']) && $_GET['futuri'] === '1';

    $assocFilter = apiAssociationFilter('e.associazione_id', $associazioneId, $filterAssocId);
    $where = [$assocFilter['where']];
    $params = $assocFilter['params'];

    if ($futuri) {
        $where[] = 'e.data_evento >= NOW()';
    }

    if (isset($_GET['oggi']) && $_GET['oggi'] === '1') {
        $where[] = 'DATE(e.data_evento) = CURDATE()';
    }

    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM eventi e WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $selectExtra = $isGlobal ? ', a.nome as associazione_nome, e.associazione_id' : '';
    $joinExtra = $isGlobal ? 'JOIN associazioni a ON e.associazione_id = a.id' : '';

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $pdo->prepare("
        SELECT e.id, e.titolo, e.descrizione, e.data_evento, e.luogo
               $selectExtra
        FROM eventi e
        $joinExtra
        WHERE $whereClause
        ORDER BY e.data_evento DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);

    apiResponse([
        'success' => true,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'eventi' => $stmt->fetchAll(),
    ]);
}
