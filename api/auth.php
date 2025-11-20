<?php
/**
 * API Authentication Middleware
 *
 * Handles API key authentication for protected endpoints
 *
 * Usage:
 * require_once __DIR__ . '/auth.php';
 * $auth = authenticateApiKey($pdo);
 * if (!$auth['success']) {
 *     http_response_code($auth['http_code']);
 *     echo json_encode(['error' => $auth['message']]);
 *     exit;
 * }
 * $associazione_id = $auth['associazione_id'];
 */

/**
 * Authenticate API request using API key
 *
 * @param PDO $pdo Database connection
 * @return array Authentication result with keys: success, associazione_id, message, http_code
 */
function authenticateApiKey($pdo) {
    // Get API key from Authorization header
    $headers = getallheaders();
    $apiKey = null;

    // Check Authorization header (Bearer token)
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $apiKey = $matches[1];
        }
    }

    // Fallback to X-API-Key header
    if (!$apiKey && isset($headers['X-API-Key'])) {
        $apiKey = $headers['X-API-Key'];
    }

    // Fallback to query parameter (less secure, but useful for testing)
    if (!$apiKey && isset($_GET['api_key'])) {
        $apiKey = $_GET['api_key'];
    }

    if (!$apiKey) {
        return [
            'success' => false,
            'message' => 'API key non fornita. Usa Authorization: Bearer {api_key} o X-API-Key header',
            'http_code' => 401
        ];
    }

    try {
        // Query API key from database
        $stmt = $pdo->prepare("
            SELECT
                ak.id,
                ak.associazione_id,
                ak.nome,
                ak.attiva,
                ak.scadenza,
                ak.ip_whitelist,
                ak.permessi,
                a.attiva as associazione_attiva,
                a.nome as associazione_nome
            FROM api_keys ak
            INNER JOIN associazioni a ON ak.associazione_id = a.id
            WHERE ak.api_key = :api_key
        ");
        $stmt->execute([':api_key' => $apiKey]);
        $keyData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$keyData) {
            return [
                'success' => false,
                'message' => 'API key non valida',
                'http_code' => 401
            ];
        }

        // Check if API key is active
        if (!$keyData['attiva']) {
            return [
                'success' => false,
                'message' => 'API key disabilitata',
                'http_code' => 403
            ];
        }

        // Check if association is active
        if (!$keyData['associazione_attiva']) {
            return [
                'success' => false,
                'message' => 'Associazione non attiva',
                'http_code' => 403
            ];
        }

        // Check expiration
        if ($keyData['scadenza'] && strtotime($keyData['scadenza']) < time()) {
            return [
                'success' => false,
                'message' => 'API key scaduta',
                'http_code' => 403
            ];
        }

        // Check IP whitelist
        if ($keyData['ip_whitelist']) {
            $allowedIps = json_decode($keyData['ip_whitelist'], true);
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

            if (is_array($allowedIps) && !in_array($clientIp, $allowedIps)) {
                return [
                    'success' => false,
                    'message' => 'IP non autorizzato',
                    'http_code' => 403
                ];
            }
        }

        // Update last usage timestamp
        $updateStmt = $pdo->prepare("
            UPDATE api_keys
            SET ultimo_utilizzo = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $keyData['id']]);

        // Return success with association ID and permissions
        return [
            'success' => true,
            'associazione_id' => $keyData['associazione_id'],
            'associazione_nome' => $keyData['associazione_nome'],
            'permessi' => $keyData['permessi'] ? json_decode($keyData['permessi'], true) : null,
            'api_key_nome' => $keyData['nome']
        ];

    } catch (PDOException $e) {
        error_log("API Authentication error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Errore durante l\'autenticazione',
            'http_code' => 500
        ];
    }
}

/**
 * Check if API key has specific permission
 *
 * @param array $auth Authentication result from authenticateApiKey()
 * @param string $permission Permission to check (e.g., 'soci', 'tessere', 'sedi')
 * @return bool
 */
function hasPermission($auth, $permission) {
    // If no permissions defined, allow all
    if (!isset($auth['permessi']) || !$auth['permessi']) {
        return true;
    }

    return isset($auth['permessi'][$permission]) && $auth['permessi'][$permission] === true;
}

/**
 * Send JSON response
 *
 * @param mixed $data Data to encode as JSON
 * @param int $httpCode HTTP status code
 */
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send error response
 *
 * @param string $message Error message
 * @param int $httpCode HTTP status code
 */
function sendErrorResponse($message, $httpCode = 400) {
    sendJsonResponse(['error' => $message], $httpCode);
}
