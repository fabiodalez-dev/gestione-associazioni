<?php
/**
 * API Middleware - Authentication, rate limiting, and response helpers
 *
 * Provides:
 * - Bearer token authentication via api_keys table
 * - JSON response helpers
 * - CORS handling
 * - Permission checking
 */

/**
 * Ensure the api_keys table exists (auto-migration)
 */
function ensureApiKeysTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'api_keys')) {
        $pdo->exec("
            CREATE TABLE api_keys (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                api_key VARCHAR(64) UNIQUE NOT NULL,
                nome VARCHAR(255) NOT NULL,
                permessi JSON NOT NULL,
                attiva BOOLEAN DEFAULT TRUE,
                ultimo_utilizzo DATETIME NULL,
                scadenza DATE NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_api_key (api_key),
                INDEX idx_api_assoc (associazione_id),
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/**
 * Generate a secure API key (64 hex chars = 256 bits)
 */
function generateApiKey(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Send a JSON response and exit
 */
function apiResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Send an error JSON response and exit
 */
function apiError(string $message, int $statusCode = 400, ?string $code = null): void
{
    $data = ['success' => false, 'error' => $message];
    if ($code !== null) {
        $data['code'] = $code;
    }
    apiResponse($data, $statusCode);
}

/**
 * Send CORS headers
 */
function apiCorsHeaders(string $allowedOrigin = '*'): void
{
    header("Access-Control-Allow-Origin: $allowedOrigin");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
    header('Access-Control-Max-Age: 3600');
}

/**
 * Handle OPTIONS preflight request
 */
function apiHandlePreflight(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        apiCorsHeaders();
        http_response_code(204);
        exit;
    }
}

/**
 * Extract Bearer token from Authorization header
 */
function extractBearerToken(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if ($auth === null) {
        // Apache CGI/FastCGI workaround
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }
    }

    if ($auth !== null && preg_match('/^Bearer\s+([a-fA-F0-9]{64})$/', $auth, $m)) {
        return $m[1];
    }

    return null;
}

/**
 * Authenticate an API request via Bearer token.
 * Returns the api_key row (with associazione_id) on success, or sends error and exits.
 *
 * @param PDO $pdo
 * @return array{id: string, associazione_id: string, api_key: string, nome: string, permessi: array, attiva: int}
 */
function apiAuthenticate(PDO $pdo): array
{
    $token = extractBearerToken();
    if ($token === null) {
        apiError('Authorization header mancante o non valido. Usa: Authorization: Bearer {api_key}', 401, 'auth_missing');
    }

    $stmt = $pdo->prepare("
        SELECT ak.*, a.nome as associazione_nome, a.attiva as associazione_attiva
        FROM api_keys ak
        JOIN associazioni a ON ak.associazione_id = a.id
        WHERE ak.api_key = ?
    ");
    $stmt->execute([$token]);
    $key = $stmt->fetch();

    if (!$key) {
        apiError('API key non valida.', 401, 'auth_invalid');
    }

    if (!$key['attiva']) {
        apiError('API key disattivata.', 403, 'key_disabled');
    }

    if (!$key['associazione_attiva']) {
        apiError('Associazione non attiva.', 403, 'association_disabled');
    }

    if ($key['scadenza'] !== null && $key['scadenza'] < date('Y-m-d')) {
        apiError('API key scaduta.', 403, 'key_expired');
    }

    // Decode permissions
    $key['permessi'] = json_decode($key['permessi'], true) ?: [];

    // Update last usage (fire-and-forget)
    try {
        $pdo->prepare("UPDATE api_keys SET ultimo_utilizzo = NOW() WHERE id = ?")->execute([$key['id']]);
    } catch (PDOException $e) {
        // ignore
    }

    return $key;
}

/**
 * Check if the API key has a specific permission
 *
 * @param array $apiKey The authenticated api_key row
 * @param string $permission Permission to check (e.g. 'tessere:read', 'soci:write')
 */
function apiHasPermission(array $apiKey, string $permission): bool
{
    $perms = $apiKey['permessi'];

    // Wildcard: full access
    if (in_array('*', $perms, true)) {
        return true;
    }

    // Direct match
    if (in_array($permission, $perms, true)) {
        return true;
    }

    // Category wildcard (e.g. 'tessere:*' matches 'tessere:read')
    $parts = explode(':', $permission, 2);
    if (count($parts) === 2 && in_array($parts[0] . ':*', $perms, true)) {
        return true;
    }

    return false;
}

/**
 * Require a specific permission, or send 403 and exit
 */
function apiRequirePermission(array $apiKey, string $permission): void
{
    if (!apiHasPermission($apiKey, $permission)) {
        apiError("Permesso '$permission' non concesso a questa API key.", 403, 'permission_denied');
    }
}

/**
 * Get request body as parsed JSON
 */
function apiGetJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        apiError('Body JSON non valido.', 400, 'invalid_json');
    }
    return $data;
}

/**
 * Get request method (supports _method override for forms)
 */
function apiGetMethod(): string
{
    $method = strtoupper($_SERVER['REQUEST_METHOD']);
    if ($method === 'POST' && !empty($_POST['_method'])) {
        $method = strtoupper($_POST['_method']);
    }
    return $method;
}
