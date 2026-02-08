<?php
// api/export.php - v2.0 (SaaS)

require_once '../config.php';

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    http_response_code(403);
    die('Accesso negato.');
}

$associazione_id = $_SESSION['associazione_id'];
$type = $_GET['type'] ?? '';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM per UTF-8

try {
    $query_map = [
        'quote' => "SELECT CONCAT(s.nome, ' ', s.cognome) as socio, q.anno, q.importo, q.tipo, q.data_scadenza, q.data_pagamento, q.stato FROM quote q JOIN soci s ON q.socio_id = s.id WHERE q.associazione_id = ? ORDER BY q.data_scadenza DESC"
    ];

    if ($type === 'soci') {
        // Filtri opzionali
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        $sede_ids = isset($_GET['sede_ids']) ? array_filter((array)$_GET['sede_ids']) : [];
        $sede_id = $_GET['sede_id'] ?? 'all';
        $categoria_ids = isset($_GET['categoria_ids']) ? array_filter((array)$_GET['categoria_ids']) : [];
        $tessera_template = $_GET['tessera_template'] ?? 'all';
        $tessera_scadenza = $_GET['tessera_scadenza'] ?? 'all';
        $has_tessera = $_GET['has_tessera'] ?? 'all';
        $tessera_stato = $_GET['tessera_stato'] ?? 'attive';

        // Config associazione per finestra "in scadenza"
        $cfg = ['giorni_notifica_scadenza' => 30];
        try {
            $stmtCfg = $pdo->prepare("SELECT giorni_notifica_scadenza FROM associazioni WHERE id = ?");
            $stmtCfg->execute([$associazione_id]);
            $cfg = $stmtCfg->fetch() ?: $cfg;
        } catch (PDOException $e) {}

        $sql = "SELECT s.numero_socio, s.nome, s.cognome, s.email, s.telefono, s.data_nascita, s.data_iscrizione, s.stato,
                       se.nome AS sede, cs.nome AS categoria,
                       tt.numero_tessera, tt.template_tessera, tt.data_scadenza, tt.stato AS tessera_stato
                FROM soci s
                LEFT JOIN sedi se ON se.id = s.sede_id
                LEFT JOIN categorie_socio cs ON cs.id = s.categoria_socio_id
                LEFT JOIN tessere tt ON tt.socio_id = s.id AND tt.associazione_id = s.associazione_id
                   AND tt.data_emissione = (
                       SELECT MAX(t2.data_emissione) FROM tessere t2 WHERE t2.socio_id = s.id AND t2.associazione_id = s.associazione_id
                   )
                WHERE 1=1";
        $params = [];
        $assoc_id_filter = $_GET['assoc_id'] ?? null;
        if ($assoc_id_filter && $assoc_id_filter !== 'all') {
            $sql .= " AND s.associazione_id = ?";
            $params[] = $assoc_id_filter;
        } else {
            $sql .= " AND s.associazione_id = ?";
            $params[] = $associazione_id;
        }

        if (!empty($search)) {
            $sql .= " AND (s.nome LIKE ? OR s.cognome LIKE ? OR s.email LIKE ?)";
            $term = "%$search%";
            array_push($params, $term, $term, $term);
        }
        if ($status !== 'all') { $sql .= " AND s.stato = ?"; $params[] = $status; }
        if (!empty($sede_ids)) {
            $place = implode(',', array_fill(0, count($sede_ids), '?'));
            $sql .= " AND s.sede_id IN ($place)";
            foreach ($sede_ids as $sid) { $params[] = $sid; }
        } elseif ($sede_id !== 'all') {
            $sql .= " AND s.sede_id = ?";
            $params[] = $sede_id;
        }
        if (!empty($categoria_ids)) {
            $place = implode(',', array_fill(0, count($categoria_ids), '?'));
            $sql .= " AND s.categoria_socio_id IN ($place)";
            foreach ($categoria_ids as $cid) { $params[] = $cid; }
        }
        if ($tessera_template !== 'all') { $sql .= " AND tt.template_tessera = ?"; $params[] = $tessera_template; }
        if ($tessera_scadenza !== 'all') {
            $sql .= " AND tt.data_scadenza IS NOT NULL";
            if ($tessera_scadenza === 'in_scadenza') {
                $soon_boundary = date('Y-m-d', strtotime('+' . (int)$cfg['giorni_notifica_scadenza'] . ' days'));
                $sql .= " AND tt.data_scadenza BETWEEN CURDATE() AND ?"; $params[] = $soon_boundary;
            } elseif ($tessera_scadenza === 'scadute') {
                $sql .= " AND tt.data_scadenza < CURDATE()";
            }
        }
        if ($has_tessera === 'yes') { $sql .= " AND tt.numero_tessera IS NOT NULL"; }
        elseif ($has_tessera === 'no') { $sql .= " AND tt.numero_tessera IS NULL"; }
        if ($tessera_stato === 'attive') {
            $sql .= " AND tt.numero_tessera IS NOT NULL AND (tt.stato = 'Attiva' AND (tt.data_scadenza IS NULL OR tt.data_scadenza >= CURDATE()))";
        } elseif ($tessera_stato === 'scadute') {
            $sql .= " AND tt.numero_tessera IS NOT NULL AND ((tt.stato = 'Scaduta') OR (tt.data_scadenza IS NOT NULL AND tt.data_scadenza < CURDATE()))";
        }
        $sql .= " ORDER BY s.cognome, s.nome";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } elseif ($type === 'tessere') {
        // Optional filters for tessere
        $q = trim($_GET['q'] ?? '');
        $status = $_GET['status'] ?? 'Attiva';
        $from_year = trim($_GET['from_year'] ?? '');
        $to_year = trim($_GET['to_year'] ?? '');

        $sql = "SELECT t.numero_tessera, CONCAT(s.nome, ' ', s.cognome) AS socio, a.nome AS associazione,
                       t.anno_validita, t.data_emissione, t.data_scadenza, t.stato
                FROM tessere t
                JOIN soci s ON t.socio_id = s.id
                JOIN associazioni a ON a.id = t.associazione_id
                WHERE 1=1";
        $params = [];
        $assoc_id_filter = $_GET['assoc_id'] ?? null;
        if ($assoc_id_filter && $assoc_id_filter !== 'all') {
            $sql .= " AND t.associazione_id = ?";
            $params[] = $assoc_id_filter;
        } else {
            $sql .= " AND t.associazione_id = ?";
            $params[] = $associazione_id;
        }
        if ($q !== '') {
            $sql .= " AND (s.nome LIKE ? OR s.cognome LIKE ? OR CONCAT(s.cognome, ' ', s.nome) LIKE ? OR CONCAT(s.nome, ' ', s.cognome) LIKE ? OR t.numero_tessera LIKE ?)";
            $like = "%$q%";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($status !== 'all') {
            if ($status === 'Scaduta') {
                $sql .= " AND (t.stato = 'Scaduta' OR (t.data_scadenza IS NOT NULL AND t.data_scadenza < CURDATE()))";
            } else {
                $sql .= " AND t.stato = ?";
                $params[] = $status;
            }
        }
        if ($from_year !== '' && preg_match('/^\\d{4}$/', $from_year)) { $sql .= " AND t.anno_validita >= ?"; $params[] = (int)$from_year; }
        if ($to_year !== '' && preg_match('/^\\d{4}$/', $to_year)) { $sql .= " AND t.anno_validita <= ?"; $params[] = (int)$to_year; }
        $sql .= " ORDER BY t.anno_validita DESC, s.cognome ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        if (!array_key_exists($type, $query_map)) {
            die('Tipo di export non valido.');
        }
        $stmt = $pdo->prepare($query_map[$type]);
        $stmt->execute([$associazione_id]);
    }

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, array_keys($row)); // Scrive l'intestazione
        fputcsv($output, $row); // Scrive la prima riga già letta
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

} catch (PDOException $e) {
    error_log('export.php PDOException: ' . $e->getMessage());
    die('Errore database. Riprova più tardi.');
}

fclose($output);
exit;
