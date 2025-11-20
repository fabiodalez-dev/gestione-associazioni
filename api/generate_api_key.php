<?php
/**
 * Utility Script: Generate API Key
 *
 * This script generates a new API key for an association.
 * Can be run from command line or integrated into admin panel.
 *
 * Usage (CLI):
 * php generate_api_key.php --associazione_id="550e8400-e29b-41d4-a716-446655440000" --nome="My API Key"
 *
 * Usage (Web - requires admin authentication):
 * Include this file in your admin panel and call generateApiKey()
 */

require_once __DIR__ . '/../config.php';

/**
 * Generate a secure API key
 *
 * @return string 64-character API key
 */
function createSecureApiKey() {
    return bin2hex(random_bytes(32));
}

/**
 * Generate UUID v4
 *
 * @return string UUID
 */
function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate and insert a new API key
 *
 * @param PDO $pdo Database connection
 * @param string $associazione_id Association ID
 * @param string $nome Name/description of the API key
 * @param string|null $descrizione Optional description
 * @param array|null $permessi Permissions array (e.g., ['soci' => true, 'tessere' => true])
 * @param string|null $scadenza Optional expiration date (Y-m-d)
 * @param array|null $ip_whitelist Optional array of allowed IPs
 * @return array Result with 'success', 'api_key', and 'message'
 */
function generateApiKey($pdo, $associazione_id, $nome, $descrizione = null, $permessi = null, $scadenza = null, $ip_whitelist = null) {
    try {
        // Verify association exists
        $stmt = $pdo->prepare("SELECT id FROM associazioni WHERE id = :id");
        $stmt->execute([':id' => $associazione_id]);
        if (!$stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'Associazione non trovata'
            ];
        }

        // Generate unique API key
        $apiKey = createSecureApiKey();
        $id = generateUuid();

        // Prepare permissions and IP whitelist as JSON
        $permessiJson = $permessi ? json_encode($permessi) : null;
        $ipWhitelistJson = $ip_whitelist ? json_encode($ip_whitelist) : null;

        // Insert API key
        $insertStmt = $pdo->prepare("
            INSERT INTO api_keys (
                id,
                associazione_id,
                nome,
                api_key,
                descrizione,
                permessi,
                scadenza,
                ip_whitelist,
                attiva
            ) VALUES (
                :id,
                :associazione_id,
                :nome,
                :api_key,
                :descrizione,
                :permessi,
                :scadenza,
                :ip_whitelist,
                1
            )
        ");

        $insertStmt->execute([
            ':id' => $id,
            ':associazione_id' => $associazione_id,
            ':nome' => $nome,
            ':api_key' => $apiKey,
            ':descrizione' => $descrizione,
            ':permessi' => $permessiJson,
            ':scadenza' => $scadenza,
            ':ip_whitelist' => $ipWhitelistJson
        ]);

        return [
            'success' => true,
            'api_key' => $apiKey,
            'id' => $id,
            'message' => 'API key generata con successo'
        ];

    } catch (PDOException $e) {
        error_log("Error generating API key: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Errore durante la generazione della API key: ' . $e->getMessage()
        ];
    }
}

/**
 * List all API keys for an association
 *
 * @param PDO $pdo Database connection
 * @param string $associazione_id Association ID
 * @return array List of API keys (without the actual key for security)
 */
function listApiKeys($pdo, $associazione_id) {
    $stmt = $pdo->prepare("
        SELECT
            id,
            nome,
            descrizione,
            attiva,
            scadenza,
            ultimo_utilizzo,
            permessi,
            ip_whitelist,
            created_at
        FROM api_keys
        WHERE associazione_id = :associazione_id
        ORDER BY created_at DESC
    ");

    $stmt->execute([':associazione_id' => $associazione_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Revoke (disable) an API key
 *
 * @param PDO $pdo Database connection
 * @param string $api_key_id API key ID
 * @param string $associazione_id Association ID (for security)
 * @return bool Success
 */
function revokeApiKey($pdo, $api_key_id, $associazione_id) {
    $stmt = $pdo->prepare("
        UPDATE api_keys
        SET attiva = 0
        WHERE id = :id AND associazione_id = :associazione_id
    ");

    return $stmt->execute([
        ':id' => $api_key_id,
        ':associazione_id' => $associazione_id
    ]);
}

/**
 * Delete an API key permanently
 *
 * @param PDO $pdo Database connection
 * @param string $api_key_id API key ID
 * @param string $associazione_id Association ID (for security)
 * @return bool Success
 */
function deleteApiKey($pdo, $api_key_id, $associazione_id) {
    $stmt = $pdo->prepare("
        DELETE FROM api_keys
        WHERE id = :id AND associazione_id = :associazione_id
    ");

    return $stmt->execute([
        ':id' => $api_key_id,
        ':associazione_id' => $associazione_id
    ]);
}

// CLI usage
if (php_sapi_name() === 'cli') {
    $options = getopt('', [
        'associazione_id:',
        'nome:',
        'descrizione::',
        'scadenza::',
        'help'
    ]);

    if (isset($options['help']) || !isset($options['associazione_id']) || !isset($options['nome'])) {
        echo "Usage: php generate_api_key.php --associazione_id=ID --nome=NAME [OPTIONS]\n\n";
        echo "Required arguments:\n";
        echo "  --associazione_id    Association UUID\n";
        echo "  --nome               Name/label for the API key\n\n";
        echo "Optional arguments:\n";
        echo "  --descrizione        Description of the API key\n";
        echo "  --scadenza           Expiration date (YYYY-MM-DD)\n";
        echo "  --help               Show this help message\n\n";
        echo "Example:\n";
        echo "  php generate_api_key.php --associazione_id=\"550e8400-e29b-41d4-a716-446655440000\" --nome=\"Production API\" --scadenza=\"2025-12-31\"\n\n";
        exit(1);
    }

    $result = generateApiKey(
        $pdo,
        $options['associazione_id'],
        $options['nome'],
        $options['descrizione'] ?? null,
        null, // permissions (null = all permissions)
        $options['scadenza'] ?? null
    );

    if ($result['success']) {
        echo "✓ API Key generata con successo!\n\n";
        echo "ID: " . $result['id'] . "\n";
        echo "API Key: " . $result['api_key'] . "\n\n";
        echo "IMPORTANTE: Salva questa API key in un luogo sicuro. Non sarà più visibile!\n";
    } else {
        echo "✗ Errore: " . $result['message'] . "\n";
        exit(1);
    }
}
