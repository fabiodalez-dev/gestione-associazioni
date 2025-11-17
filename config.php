<?php
/**
 * Associazione Soci Manager - Configuration File v2.0 (SaaS)
 */

// Security Headers - send immediately before any output
if (!defined('INSTALLER_ACTIVE') && !headers_sent()) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    // HSTS - CRITICAL for HTTPS security
    if ($is_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Content Security Policy with nonce for inline scripts
    $nonce = base64_encode(random_bytes(16));
    $_SESSION['csp_nonce'] = $nonce;

    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "style-src 'self' 'nonce-$nonce' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "img-src 'self' data: https:; "
         . "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
         . "connect-src 'self'; "
         . "frame-ancestors 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self';";
    if ($is_https) { $csp = "upgrade-insecure-requests; " . $csp; }
    header("Content-Security-Policy: $csp");
}

// Avvia la sessione in modo sicuro
if (session_status() === PHP_SESSION_NONE) {
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

    // Session timeout check (default 1 hour)
    if (!defined('INSTALLER_ACTIVE') && isset($_SESSION['user_id'])) {
        $timeout = 3600; // 1 hour
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            session_unset();
            session_destroy();
            if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
                header('Location: auth/login.php?timeout=1');
                exit;
            }
        }
        $_SESSION['last_activity'] = time();
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
// SECURITY FIX: No default password - fail if not set
if (!defined('DB_PASS')) {
    $db_pass = $_ENV['DB_PASS'] ?? null;
    if ($db_pass === null || $db_pass === '') {
        // Allow empty password only in development (localhost)
        $is_localhost = in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);
        if (!$is_localhost && !defined('INSTALLER_ACTIVE')) {
            die('SECURITY ERROR: DB_PASS must be set in .env file. Never use empty password in production!');
        }
        $db_pass = ''; // Allow empty only for localhost
    }
    define('DB_PASS', $db_pass);
}
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// --- Costanti di Sistema ---
if (!defined('APP_ROOT')) define('APP_ROOT', __DIR__);
if (!defined('UPLOADS_PATH')) define('UPLOADS_PATH', APP_ROOT . '/uploads');

// --- Connessione Database (PDO) ---
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Verifica se l'applicazione è installata controllando l'esistenza delle tabelle principali
    $stmt = $pdo->query("SHOW TABLES LIKE 'associazioni'");
    if ($stmt->rowCount() === 0) {
        // Applicazione non installata, reindirizza all'installer
        if (!defined('INSTALLER_ACTIVE') && basename($_SERVER['PHP_SELF']) !== 'install.php') {
            header('Location: install.php');
            exit;
        }
    }
} catch (PDOException $e) {
    // Errore di connessione al database - probabilmente non installato
    if (!defined('INSTALLER_ACTIVE') && basename($_SERVER['PHP_SELF']) !== 'install.php') {
        // Reindirizza all'installer se non siamo già nell'installer
        header('Location: install.php');
        exit;
    } else {
        // Mostra errore solo se siamo nell'installer
        die("Errore di connessione al database: " . $e->getMessage());
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
 * Genera un UUID v4 crittograficamente sicuro compatibile con MySQL CHAR(36).
 * SECURITY FIX: Usa random_bytes() invece di mt_rand() per sicurezza
 * @return string UUID v4 formato standard (es: "550e8400-e29b-41d4-a716-446655440000")
 */
if (!function_exists('generateUuid')) {
    function generateUuid() {
        // Genera 16 byte random crittografici
        $data = random_bytes(16);

        // Set version (0100) per UUID v4
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Set variant (10xx) per RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Formatta come UUID
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
 * Assicura la directory dei loghi
 */
if (!is_dir(UPLOADS_PATH . '/logos')) {
    mkdir(UPLOADS_PATH . '/logos', 0755, true);
}

// ============================================================================
// PLUGIN SYSTEM - Hook Manager & Plugin Manager
// ============================================================================

// Load Hook Manager
require_once APP_ROOT . '/lib/HookManager.php';

// Load Plugin Manager
require_once APP_ROOT . '/lib/PluginManager.php';

// Initialize Plugin System
if (isset($pdo) && !defined('INSTALLER_ACTIVE')) {
    try {
        PluginManager::init($pdo);
        // Hook: Application fully loaded
        HookManager::doAction('app_loaded');
    } catch (Exception $e) {
        error_log("Error initializing Plugin Manager: " . $e->getMessage());
    }
}

// ============================================================================
// SECURITY FUNCTIONS - Rate Limiting, Logging, Validation
// ============================================================================

/**
 * Verifica rate limiting per login attempts
 * Blocca dopo 5 tentativi falliti per 15 minuti
 *
 * @param PDO $pdo Database connection
 * @param string $identifier Email o IP address
 * @param string $type Tipo: 'admin' o 'socio'
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
 * @throws Exception se rate limit superato
 */
if (!function_exists('checkLoginRateLimit')) {
    function checkLoginRateLimit($pdo, $identifier, $type = 'admin') {
        $max_attempts = 5;
        $window_seconds = 900; // 15 minuti

        // Crea tabella se non esiste
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                type ENUM('admin', 'socio') NOT NULL,
                ip_address VARCHAR(45),
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_identifier_type (identifier, type),
                INDEX idx_attempted_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            // Tabella già esiste, continua
        }

        // Pulisci vecchi tentativi (oltre la finestra temporale)
        $cutoff_time = date('Y-m-d H:i:s', time() - $window_seconds);
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ?");
        $stmt->execute([$cutoff_time]);

        // Conta tentativi recenti
        $stmt = $pdo->prepare("SELECT COUNT(*) as attempts, MAX(attempted_at) as last_attempt
                               FROM login_attempts
                               WHERE identifier = ? AND type = ? AND attempted_at >= ?");
        $stmt->execute([$identifier, $type, $cutoff_time]);
        $result = $stmt->fetch();

        $attempts = (int)$result['attempts'];
        $remaining = max(0, $max_attempts - $attempts);

        // Calcola retry_after se bloccato
        $retry_after = 0;
        if ($attempts >= $max_attempts && $result['last_attempt']) {
            $last_attempt_time = strtotime($result['last_attempt']);
            $retry_after = max(0, $window_seconds - (time() - $last_attempt_time));
        }

        // Blocca se superato il limite
        if ($attempts >= $max_attempts) {
            $minutes = ceil($retry_after / 60);
            throw new Exception("Troppi tentativi di login falliti. Riprova tra $minutes minuti.");
        }

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'retry_after' => 0
        ];
    }
}

/**
 * Registra un tentativo di login fallito
 */
if (!function_exists('recordLoginAttempt')) {
    function recordLoginAttempt($pdo, $identifier, $type = 'admin') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        try {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$identifier, $type, $ip]);
        } catch (PDOException $e) {
            error_log("Failed to record login attempt: " . $e->getMessage());
        }
    }
}

/**
 * Pulisce i tentativi di login dopo login riuscito
 */
if (!function_exists('clearLoginAttempts')) {
    function clearLoginAttempts($pdo, $identifier, $type = 'admin') {
        try {
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?");
            $stmt->execute([$identifier, $type]);
        } catch (PDOException $e) {
            error_log("Failed to clear login attempts: " . $e->getMessage());
        }
    }
}

/**
 * Valida la robustezza della password
 * Requisiti: min 12 caratteri, maiuscole, minuscole, numeri, simboli
 */
if (!function_exists('validatePasswordStrength')) {
    function validatePasswordStrength($password) {
        $errors = [];

        if (strlen($password) < 12) {
            $errors[] = "La password deve contenere almeno 12 caratteri";
        }
        if (strlen($password) > 128) {
            $errors[] = "La password non può superare 128 caratteri";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera maiuscola";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera minuscola";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "La password deve contenere almeno un numero";
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "La password deve contenere almeno un carattere speciale (!@#$%^&*)";
        }

        // Check contro password comuni
        $common_passwords = [
            'password', '123456', '123456789', '12345678', '12345', '1234567',
            'password1', 'password123', 'qwerty', 'abc123', 'admin', 'admin123'
        ];
        if (in_array(strtolower($password), $common_passwords)) {
            $errors[] = "La password è troppo comune. Scegline una più sicura";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

/**
 * Log evento di sicurezza
 */
if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent($pdo, $event_type, $description, $context = [], $severity = 'info') {
        // Crea tabella se non esiste
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS security_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
                description TEXT NOT NULL,
                user_id CHAR(36) NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                context_json JSON,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_severity (severity),
                INDEX idx_created_at (created_at),
                INDEX idx_ip (ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("[SECURITY] $severity - $event_type: $description");
            return;
        }

        // Prepara context
        $full_context = array_merge([
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ], $context);

        $user_id = $_SESSION['user_id'] ?? null;

        // Insert log
        try {
            $stmt = $pdo->prepare("INSERT INTO security_log
                                   (event_type, severity, description, user_id, ip_address, user_agent, context_json)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $event_type,
                $severity,
                $description,
                $user_id,
                $full_context['ip'],
                $full_context['user_agent'],
                json_encode($full_context)
            ]);
        } catch (PDOException $e) {
            error_log("[SECURITY] Failed to log event: " . $e->getMessage());
        }
    }
}

/**
 * Validazione sicura file upload con controlli aggiuntivi
 */
if (!function_exists('validateFileUploadSecure')) {
    function validateFileUploadSecure($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 5242880) {
        $errors = [];

        if (!isset($file['error']) || is_array($file['error'])) {
            $errors[] = "Parametri file non validi";
            return $errors;
        }

        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors[] = "File troppo grande";
                return $errors;
            case UPLOAD_ERR_NO_FILE:
                $errors[] = "Nessun file caricato";
                return $errors;
            default:
                $errors[] = "Errore sconosciuto durante l'upload";
                return $errors;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = "File non valido (possibile attacco)";
            return $errors;
        }

        if ($file['size'] > $max_size) {
            $errors[] = "File troppo grande. Massimo " . round($max_size/1024/1024, 1) . "MB";
        }

        if ($file['size'] <= 0) {
            $errors[] = "File vuoto";
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_types, true)) {
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

        // Check for embedded PHP code in images
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $content = file_get_contents($file['tmp_name']);
            if (preg_match('/<\?php/i', $content)) {
                $errors[] = "File contiene codice PHP pericoloso";
            }
        }

        return $errors;
    }
}
