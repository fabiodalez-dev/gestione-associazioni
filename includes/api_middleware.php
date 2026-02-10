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
 * Also migrates existing tables: makes associazione_id nullable for global keys.
 */
function ensureApiKeysTable(PDO $pdo): void
{
    if (!tableExists($pdo, 'api_keys')) {
        $pdo->exec("
            CREATE TABLE api_keys (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NULL,
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
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } else {
        // Migrate existing table: make associazione_id nullable
        try {
            $stmt = $pdo->prepare("
                SELECT IS_NULLABLE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys' AND COLUMN_NAME = 'associazione_id'
            ");
            $stmt->execute();
            $nullable = $stmt->fetchColumn();
            if ($nullable === 'NO') {
                $pdo->exec("ALTER TABLE api_keys MODIFY COLUMN associazione_id CHAR(36) NULL");
                // Update FK to SET NULL (drop old, add new)
                try {
                    $fkStmt = $pdo->prepare("
                        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_keys'
                          AND COLUMN_NAME = 'associazione_id' AND REFERENCED_TABLE_NAME = 'associazioni'
                    ");
                    $fkStmt->execute();
                    $fkName = $fkStmt->fetchColumn();
                    if ($fkName !== false && is_string($fkName)) {
                        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '', $fkName);
                        $pdo->exec("ALTER TABLE api_keys DROP FOREIGN KEY `{$safeName}`");
                        $pdo->exec("ALTER TABLE api_keys ADD CONSTRAINT `{$safeName}` FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL");
                    }
                } catch (PDOException $e) {
                    error_log('api_keys FK migration: ' . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            error_log('api_keys nullable migration: ' . $e->getMessage());
        }
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
 * Returns the api_key row on success, or sends error and exits.
 * For global keys (associazione_id IS NULL), associazione_id will be null.
 *
 * @param PDO $pdo
 * @return array{id: string, associazione_id: ?string, api_key: string, nome: string, permessi: array<string>, attiva: int, is_globale: bool}
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
        LEFT JOIN associazioni a ON ak.associazione_id = a.id
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

    // For scoped keys, check association is active
    if ($key['associazione_id'] !== null && !$key['associazione_attiva']) {
        apiError('Associazione non attiva.', 403, 'association_disabled');
    }

    if ($key['scadenza'] !== null && $key['scadenza'] < date('Y-m-d')) {
        apiError('API key scaduta.', 403, 'key_expired');
    }

    // Decode permissions
    $key['permessi'] = json_decode($key['permessi'], true) ?: [];

    // Mark global status
    $key['is_globale'] = ($key['associazione_id'] === null);

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

/**
 * Build WHERE clause + params for association filtering.
 *
 * For scoped keys: always filters by the key's associazione_id.
 * For global keys: optionally filters by ?associazione_id= query param.
 *
 * @param string $column  SQL column name (e.g. 's.associazione_id' or 'associazione_id')
 * @param ?string $associazioneId  From the API key (null = global)
 * @param ?string $filterAssocId   Optional filter from query string
 * @return array{where: string, params: array<string>, is_filtered: bool}
 */
function apiAssociationFilter(string $column, ?string $associazioneId, ?string $filterAssocId = null): array
{
    if ($associazioneId !== null) {
        // Scoped key: mandatory filter
        return [
            'where' => "$column = ?",
            'params' => [$associazioneId],
            'is_filtered' => true,
        ];
    }

    // Global key
    if ($filterAssocId !== null && preg_match('/^[a-f0-9-]{36}$/i', $filterAssocId)) {
        return [
            'where' => "$column = ?",
            'params' => [$filterAssocId],
            'is_filtered' => true,
        ];
    }

    // No filter: return all
    return [
        'where' => '1=1',
        'params' => [],
        'is_filtered' => false,
    ];
}
