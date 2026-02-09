<?php
// api/users_search.php - ricerca utenti per superadmin (autocomplete)
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUserLoggedIn(['super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '' || strlen($q) < 2) {
    echo json_encode(['success' => true, 'items' => []]);
    exit;
}

try {
$onlyAdmin = isset($_GET['only_admin_assoc']) && $_GET['only_admin_assoc'] == '1';
$onlyAvailable = isset($_GET['available']) && $_GET['available'] == '1';
$includeExcluded = isset($_GET['include_excluded']) && $_GET['include_excluded'] == '1';

$where = ["(username LIKE ? OR email LIKE ?)"];
$params = [];
$term = "%$q%";
$params[] = $term; $params[] = $term;
if ($onlyAdmin) { $where[] = "role IN ('admin_associazione','super_admin')"; }
if (!$includeExcluded) {
    if ($onlyAvailable) { $where[] = "(associazione_id IS NULL OR associazione_id = '')"; }
}
$sql = "SELECT id, username, email, role, associazione_id FROM users WHERE " . implode(' AND ', $where) . " ORDER BY role DESC, username LIMIT 20";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
    $rows = $stmt->fetchAll();
    if ($includeExcluded) {
        $items = [];
        foreach ($rows as $u) {
            $excluded = false; $reason = null;
            if ($onlyAvailable && !empty($u['associazione_id'])) { $excluded = true; $reason = 'linked'; }
            $u['excluded'] = $excluded;
            $u['reason'] = $reason;
            $items[] = $u;
        }
        echo json_encode(['success' => true, 'items' => $items]);
    } else {
        echo json_encode(['success' => true, 'items' => $rows]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_error']);
}
