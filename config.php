<?php
/**
 * Associazione Soci Manager - Configuration File v2.0 (SaaS)
 */

// Security Headers - send immediately before any output (skip per API: gestisce i suoi headers)
if (!defined('INSTALLER_ACTIVE') && !defined('API_REQUEST') && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // Content Security Policy (avoid forcing HTTPS in local HTTP to prevent ERR_CONNECTION_CLOSED)
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    // GrapesJS pages need 'unsafe-eval' for the editor engine
    $grapesjs_pages = ['email-templates', 'comunicazioni'];
    $current_page_key = $_GET['page'] ?? '';
    $needs_eval = in_array($current_page_key, $grapesjs_pages, true);
    $script_extra = $needs_eval ? " 'unsafe-eval'" : '';
    $csp = "default-src 'self'; "
         . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdn.jsdelivr.net http://cdnjs.cloudflare.com; "
         . "style-src-elem 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdn.jsdelivr.net http://cdnjs.cloudflare.com; "
         . "script-src 'self' 'unsafe-inline'" . $script_extra . " https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdn.jsdelivr.net http://cdnjs.cloudflare.com; "
         . "img-src 'self' data:; "
         . "connect-src 'self'; "
         . "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdn.jsdelivr.net http://cdnjs.cloudflare.com; "
         . "worker-src 'self';";
    if ($is_https) { $csp = "upgrade-insecure-requests; " . $csp; }
    header("Content-Security-Policy: $csp");
}

// Avvia la sessione in modo sicuro (skip per richieste API stateless)
if (session_status() === PHP_SESSION_NONE && !defined('API_REQUEST')) {
    // Configurazioni sicurezza sessione
    ini_set('session.cookie_httponly', '1');
    // Imposta cookie secure solo se HTTPS attivo
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    ini_set('session.cookie_secure', $is_https ? '1' : '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Strict');
    
    session_start();
    
    // Regenera session ID periodicamente per prevenire session fixation
    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif ($_SESSION['last_regeneration'] < (time() - 3600)) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}


// --- Caricamento Configurazione Ambiente ---
// Carica file .env se esiste (per sviluppo locale)
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos($line, '#') === 0) continue; // Skip comments
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// --- Impostazioni Connessione Database ---
// Usa variabili d'ambiente per produzione, con fallback per sviluppo locale
if (!defined('DB_HOST')) define('DB_HOST', $_ENV['DB_HOST'] ?? '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', $_ENV['DB_NAME'] ?? 'associazione_soci_saas');
if (!defined('DB_USER')) define('DB_USER', $_ENV['DB_USER'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $_ENV['DB_PASS'] ?? ''); // No default password for security
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// --- Costanti di Sistema ---
if (!defined('APP_ROOT')) define('APP_ROOT', __DIR__);
if (!defined('UPLOADS_PATH')) define('UPLOADS_PATH', APP_ROOT . '/uploads');

// --- Verifica installazione tramite lock file ---
$_installerBypass = ['install.php', 'verifica-tessera.php', 'checkin.php', 'scanner-tessera.php'];
if (!file_exists(__DIR__ . '/.installed')) {
    if (!defined('INSTALLER_ACTIVE') && !defined('API_REQUEST') && !in_array(basename($_SERVER['PHP_SELF']), $_installerBypass, true)) {
        header('Location: install.php');
        exit;
    }
}

// --- Connessione Database (PDO) ---
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    if (!defined('INSTALLER_ACTIVE') && !defined('API_REQUEST') && !in_array(basename($_SERVER['PHP_SELF']), $_installerBypass, true)) {
        header('Location: install.php');
        exit;
    } else {
        error_log('config.php DB connection error: ' . $e->getMessage());
        if (defined('API_REQUEST')) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Errore di connessione al database.']);
            exit;
        }
        die("Errore di connessione al database. Verifica la configurazione.");
    }
}

// --- Funzioni di Utilità Globale ---

/**
 * Sanitizza un input per prevenire XSS.
 * @param string $input
 * @return string
 */
// Legacy: sanitizeInput encoded values on save; keep for backward compatibility (do not use for new saves)
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim((string)$input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * Clean raw input for storage: trims and strips ASCII control chars; NO HTML encoding.
 * Always escape at render with escapeOutput()/htmlspecialchars.
 */
if (!function_exists('cleanInput')) {
    function cleanInput($input) {
        $s = trim((string)$input);
        // Remove non-printable control chars except CR, LF and TAB
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return $s;
    }
}

/**
 * Sicurezza avanzata - XSS Prevention
 */
if (!function_exists('escapeOutput')) {
    function escapeOutput($data) {
        if (is_array($data)) {
            return array_map('escapeOutput', $data);
        }
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

/**
 * CSRF Token Generation and Validation
 */
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Sicurezza File Upload
 */
if (!function_exists('validateFileUpload')) {
    function validateFileUpload($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'], $max_size = 5242880) {
    $errors = [];
    
    if (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = "File non valido";
        return $errors;
    }
    
    // Check file size (default 5MB)
    if ($file['size'] > $max_size) {
        $errors[] = "File troppo grande. Massimo " . round($max_size/1024/1024, 1) . "MB";
    }
    
    // Check file extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_types)) {
        $errors[] = "Tipo file non permesso. Permessi: " . implode(', ', $allowed_types);
    }
    
    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowed_mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 
        'png' => 'image/png',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    
    if (isset($allowed_mimes[$ext]) && $mime !== $allowed_mimes[$ext]) {
        $errors[] = "Tipo MIME non valido per l'estensione del file";
    }
    
    return $errors;
    }
}

/**
 * Genera nome file sicuro
 */
if (!function_exists('generateSecureFileName')) {
    function generateSecureFileName($original_name, $prefix = '') {
    $ext = pathinfo($original_name, PATHINFO_EXTENSION);
    return $prefix . uniqid() . '_' . time() . '.' . $ext;
    }
}

/**
 * Prevenzione Directory Traversal
 */
if (!function_exists('sanitizeFilePath')) {
    function sanitizeFilePath($path) {
    // Remove any attempt at directory traversal
    $path = str_replace(['../', '..\\', '../', '..\\'], '', $path);
    // Remove null bytes
    $path = str_replace(chr(0), '', $path);
    // Only allow alphanumeric, dash, underscore, and dot
    $path = preg_replace('/[^a-zA-Z0-9._-]/', '_', $path);
    return $path;
    }
}

/**
 * Validazione parametri GET/POST
 */
if (!function_exists('validateInput')) {
    function validateInput($input, $type = 'string', $max_length = 255) {
    switch($type) {
        case 'email':
            return filter_var($input, FILTER_VALIDATE_EMAIL) ? trim($input) : false;
        case 'int':
            return filter_var($input, FILTER_VALIDATE_INT);
        case 'uuid':
            return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $input) ? $input : false;
        case 'alpha':
            return preg_match('/^[a-zA-Z\s]+$/', $input) ? trim($input) : false;
        case 'alphanumeric':
            return preg_match('/^[a-zA-Z0-9\s_-]+$/', $input) ? trim($input) : false;
        default:
            $cleaned = trim($input);
            return strlen($cleaned) <= $max_length ? $cleaned : false;
    }
    }
}

/**
 * Verifica se un utente è loggato e ha un ruolo specifico.
 * @param array $allowed_roles Array di ruoli permessi (es. ['super_admin', 'admin_associazione'])
 * @return bool
 */
if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn(array $allowed_roles = []) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        return false;
    }
    if (empty($allowed_roles)) {
        return true; // Se non sono specificati ruoli, basta essere loggati
    }
    return in_array($_SESSION['user_role'], $allowed_roles);
    }
}

/**
 * Reindirizza a una pagina e termina lo script.
 * @param string $url
 */
if (!function_exists('redirect')) {
    function redirect($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit;
    } else {
        // Fallback: redirect using JavaScript if headers already sent
        echo "<script>window.location.href = '" . htmlspecialchars($url, ENT_QUOTES) . "';</script>";
        exit;
    }
    }
}

/**
 * Genera un UUID v4 compatibile con MySQL CHAR(36).
 * @return string
 */
if (!function_exists('generateUuid')) {
    function generateUuid() {
    // Funzione PHP per generare UUID v4
    // Source: https://www.php.net/manual/en/function.uniqid.php#94959
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
        mt_rand( 0, 0xffff ),
        mt_rand( 0, 0x0fff ) | 0x4000,
        mt_rand( 0, 0x3fff ) | 0x8000,
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
    );
    }
}

/**
 * Registra un'attività per un socio nello storico.
 */
if (!function_exists('logSocioActivity')) {
    function logSocioActivity($pdo, $associazione_id, $socio_id, $tipo_attivita, $descrizione, $dettagli_json = null) {
    try {
        $sql = "INSERT INTO storico_attivita_socio (id, associazione_id, socio_id, utente_id, tipo_attivita, descrizione, dettagli_json) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $user_id = $_SESSION['user_id'] ?? null;
        $stmt->execute([generateUuid(), $associazione_id, $socio_id, $user_id, $tipo_attivita, $descrizione, $dettagli_json]);
    } catch (PDOException $e) {
        error_log("Impossibile registrare l'attività del socio: " . $e->getMessage());
    }
    }
}

// --- Encryption Key ---
if (!defined('APP_ENCRYPTION_KEY')) {
    define('APP_ENCRYPTION_KEY', $_ENV['APP_ENCRYPTION_KEY'] ?? '');
}

/**
 * Encrypt a value using AES-256-CBC.
 * Returns base64-encoded "iv:ciphertext" string.
 */
if (!function_exists('encryptValue')) {
    function encryptValue(string $plaintext): string {
        $key = hex2bin(APP_ENCRYPTION_KEY);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be a 64-char hex string.');
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return base64_encode($iv . $cipher);
    }
}

/**
 * Decrypt a value previously encrypted with encryptValue().
 */
if (!function_exists('decryptValue')) {
    function decryptValue(string $encoded): string {
        $key = hex2bin(APP_ENCRYPTION_KEY);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY must be a 64-char hex string.');
        }
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 17) {
            throw new RuntimeException('Invalid encrypted data.');
        }
        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed.');
        }
        return $plain;
    }
}

// Creazione directory di base se non esistono
if (!is_dir(UPLOADS_PATH)) {
    mkdir(UPLOADS_PATH, 0755, true);
}

/**
 * Verifica se una tabella esiste nello schema corrente
 */
if (!function_exists('tableExists')) {
    function tableExists(PDO $pdo, $tableName) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

/**
 * Verifica se una colonna esiste in una tabella
 */
if (!function_exists('columnExists')) {
    function columnExists(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

/**
 * Garantisce che le colonne costo_tessera esistano su associazioni e tipi_socio (auto-migrate soft)
 */
if (!function_exists('ensureTesseraCostColumns')) {
    function ensureTesseraCostColumns(PDO $pdo) {
        try {
            if (!columnExists($pdo, 'associazioni', 'costo_tessera')) {
                $pdo->exec("ALTER TABLE associazioni ADD COLUMN costo_tessera DECIMAL(10,2) NULL AFTER telefono");
            }
        } catch (PDOException $e) {
            // ignore
        }
        try {
            if (!columnExists($pdo, 'tipi_socio', 'costo_tessera')) {
                $pdo->exec("ALTER TABLE tipi_socio ADD COLUMN costo_tessera DECIMAL(10,2) NULL AFTER descrizione");
            }
        } catch (PDOException $e) {
            // ignore
        }
        try {
            if (!columnExists($pdo, 'tessere', 'evento_creazione_id')) {
                $pdo->exec("ALTER TABLE tessere ADD COLUMN evento_creazione_id CHAR(36) NULL AFTER template_tessera");
                $pdo->exec("ALTER TABLE tessere ADD INDEX idx_tessere_evento_creazione (evento_creazione_id)");
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
}

/**
 * Rende un template sostituendo i placeholder {KEY}
 */
if (!function_exists('renderTemplatePlaceholders')) {
    function renderTemplatePlaceholders($template, array $vars) {
        return preg_replace_callback('/\{([A-Z0-9_]+)\}/', function ($m) use ($vars) {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string)$vars[$key] : $m[0];
        }, (string)$template);
    }
}

/**
 * Mostra una notifica animata con JavaScript
 */
if (!function_exists('showAnimatedNotification')) {
    function showAnimatedNotification($message, $type = 'success', $autoHide = true) {
        echo "<script>
";
        echo "document.addEventListener('DOMContentLoaded', function() {
";
        echo "    if (window.animationManager && typeof window.animationManager.showNotification === 'function') {
";
        echo "        const notification = window.animationManager.showNotification(" . json_encode($message) . ", " . json_encode($type) . ");
";
        if ($autoHide && $type !== 'danger' && $type !== 'error') {
            echo "        // Auto-hide success notifications after 5 seconds
";
            echo "        setTimeout(() => {
";
            echo "            if (notification && notification.parentNode) {
";
            echo "                gsap.to(notification, {
";
            echo "                    height: 0,
";
            echo "                    opacity: 0,
";
            echo "                    marginTop: 0,
";
            echo "                    marginBottom: 0,
";
            echo "                    paddingTop: 0,
";
            echo "                    paddingBottom: 0,
";
            echo "                    duration: 0.5,
";
            echo "                    ease: \"power2.in\",
";
            echo "                    onComplete: () => {
";
            echo "                        if (notification.parentNode) {
";
            echo "                            notification.parentNode.removeChild(notification);
";
            echo "                        }
";
            echo "                    }
";
            echo "                });
";
            echo "            }
";
            echo "        }, 5000);
";
        }
        echo "    }
";
        echo "});
";
        echo "</script>
";
    }
}

/**
 * Formatta una data in formato italiano
 * @param string|null $date Data in formato YYYY-MM-DD o timestamp
 * @param string $format Formato di output (default: d/m/Y)
 * @return string Data formattata o stringa vuota
 */
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd/m/Y') {
        if (empty($date)) {
            return '';
        }

        try {
            $dateTime = new DateTime($date);
            return $dateTime->format($format);
        } catch (Exception $e) {
            return '';
        }
    }
}

/**
 * Assicura la directory dei loghi
 */
if (!is_dir(UPLOADS_PATH . '/logos')) {
    mkdir(UPLOADS_PATH . '/logos', 0755, true);
}
