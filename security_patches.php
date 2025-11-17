<?php
/**
 * SECURITY PATCHES - Correzioni Sicurezza Critiche
 *
 * Questo file contiene funzioni corrette per sostituire quelle vulnerabili in config.php
 *
 * ISTRUZIONI:
 * 1. Copia queste funzioni in config.php sostituendo quelle esistenti
 * 2. Testa accuratamente dopo ogni modifica
 * 3. Esegui security scan con OWASP ZAP o simili
 *
 * CORREZIONI INCLUSE:
 * - UUID generation crittografica sicura
 * - Security headers migliorati con HSTS
 * - Validazione password strength
 * - Rate limiting per login
 * - Session timeout configurabile
 * - Logging sicurezza eventi
 *
 * Created: 2025-11-17
 */

// =============================================================================
// 1. UUID GENERATION SICURA (FIX CRITICO)
// =============================================================================
// SOSTITUISCE: config.php linee 293-305
// VULNERABILITÀ: mt_rand() non è crittograficamente sicuro
// FIX: usa random_bytes() per UUID v4 sicuri

if (!function_exists('generateSecureUuid')) {
    /**
     * Genera un UUID v4 crittograficamente sicuro
     * Conforme a RFC 4122
     *
     * @return string UUID v4 formato standard (es: "550e8400-e29b-41d4-a716-446655440000")
     */
    function generateSecureUuid() {
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

// =============================================================================
// 2. SECURITY HEADERS MIGLIORATI (FIX CRITICO)
// =============================================================================
// SOSTITUISCE: config.php linee 6-23
// AGGIUNTE: HSTS, Permissions-Policy migliorato, CSP con nonce

if (!function_exists('sendSecurityHeaders')) {
    /**
     * Invia header di sicurezza ottimizzati
     * Include HSTS, CSP con nonce, e altri header protettivi
     */
    function sendSecurityHeaders() {
        if (headers_sent()) return;

        // Verifica se HTTPS è attivo
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        // Base security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

        // HSTS (solo su HTTPS)
        if ($is_https) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        // Content Security Policy con nonce
        $nonce = base64_encode(random_bytes(16));
        $_SESSION['csp_nonce'] = $nonce;

        $csp = "default-src 'self'; "
             . "script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
             . "style-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
             . "img-src 'self' data: https:; "
             . "font-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
             . "connect-src 'self'; "
             . "frame-ancestors 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self';";

        if ($is_https) {
            $csp = "upgrade-insecure-requests; " . $csp;
        }

        header("Content-Security-Policy: $csp");
    }
}

// =============================================================================
// 3. VALIDAZIONE PASSWORD STRENGTH (FIX CRITICO)
// =============================================================================
// NUOVA FUNZIONE - da usare in registrazione/cambio password

if (!function_exists('validatePasswordStrength')) {
    /**
     * Valida la robustezza della password
     * Requisiti: min 12 caratteri, maiuscole, minuscole, numeri, simboli
     *
     * @param string $password Password da validare
     * @return array ['valid' => bool, 'errors' => array]
     */
    function validatePasswordStrength($password) {
        $errors = [];

        // Lunghezza minima
        if (strlen($password) < 12) {
            $errors[] = "La password deve contenere almeno 12 caratteri";
        }

        // Lunghezza massima (previene DoS)
        if (strlen($password) > 128) {
            $errors[] = "La password non può superare 128 caratteri";
        }

        // Almeno una maiuscola
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera maiuscola";
        }

        // Almeno una minuscola
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "La password deve contenere almeno una lettera minuscola";
        }

        // Almeno un numero
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = "La password deve contenere almeno un numero";
        }

        // Almeno un carattere speciale
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = "La password deve contenere almeno un carattere speciale (!@#$%^&*)";
        }

        // Check contro password comuni (top 100)
        $common_passwords = [
            'password', '123456', '123456789', '12345678', '12345', '1234567',
            'password1', 'password123', 'qwerty', 'abc123', 'admin', 'admin123',
            'letmein', 'welcome', 'monkey', 'dragon', 'master', 'sunshine',
            'princess', 'football', 'iloveyou', 'shadow', 'baseball', 'superman'
        ];

        if (in_array(strtolower($password), $common_passwords)) {
            $errors[] = "La password è troppo comune. Scegline una più sicura";
        }

        // Check sequenze ovvie
        if (preg_match('/(.)\1{3,}/', $password)) {
            $errors[] = "La password contiene caratteri ripetuti consecutivi (aaaa, 1111)";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

// =============================================================================
// 4. RATE LIMITING PER LOGIN (FIX CRITICO)
// =============================================================================
// NUOVA FUNZIONE - da usare in auth/login.php e area-soci/login.php

if (!function_exists('checkLoginRateLimit')) {
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
            )");
        } catch (PDOException $e) {
            // Tabella già esiste, continua
        }

        // Pulisci vecchi tentativi (oltre la finestra temporale)
        $cutoff_time = date('Y-m-d H:i:s', time() - $window_seconds);
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE attempted_at < ?");
        $stmt->execute([$cutoff_time]);

        // Conta tentativi recenti
        $stmt = $pdo->prepare("SELECT COUNT(*) as attempts,
                                      MAX(attempted_at) as last_attempt
                               FROM login_attempts
                               WHERE identifier = ? AND type = ?
                               AND attempted_at >= ?");
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

if (!function_exists('recordLoginAttempt')) {
    /**
     * Registra un tentativo di login fallito
     *
     * @param PDO $pdo Database connection
     * @param string $identifier Email o username
     * @param string $type Tipo: 'admin' o 'socio'
     */
    function recordLoginAttempt($pdo, $identifier, $type = 'admin') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $stmt = $pdo->prepare("INSERT INTO login_attempts (identifier, type, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$identifier, $type, $ip]);
    }
}

if (!function_exists('clearLoginAttempts')) {
    /**
     * Pulisce i tentativi di login dopo login riuscito
     *
     * @param PDO $pdo Database connection
     * @param string $identifier Email o username
     * @param string $type Tipo: 'admin' o 'socio'
     */
    function clearLoginAttempts($pdo, $identifier, $type = 'admin') {
        $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE identifier = ? AND type = ?");
        $stmt->execute([$identifier, $type]);
    }
}

// =============================================================================
// 5. SESSION TIMEOUT CONFIGURABILE (FIX MEDIO)
// =============================================================================
// SOSTITUISCE/AGGIUNGE a config.php dopo session_start()

if (!function_exists('checkSessionTimeout')) {
    /**
     * Verifica e applica session timeout
     * Redirect a login se sessione scaduta
     *
     * @param int $timeout_seconds Timeout in secondi (default: 3600 = 1 ora)
     * @param string $redirect_url URL redirect se timeout (default: auth/login.php)
     */
    function checkSessionTimeout($timeout_seconds = 3600, $redirect_url = 'auth/login.php') {
        // Verifica ultima attività
        if (isset($_SESSION['last_activity'])) {
            $elapsed = time() - $_SESSION['last_activity'];

            if ($elapsed > $timeout_seconds) {
                // Timeout scaduto
                session_unset();
                session_destroy();

                // Reindirizza con messaggio
                $separator = (strpos($redirect_url, '?') === false) ? '?' : '&';
                header("Location: {$redirect_url}{$separator}timeout=1");
                exit;
            }
        }

        // Aggiorna last activity
        $_SESSION['last_activity'] = time();
    }
}

// =============================================================================
// 6. SECURITY LOGGING (FIX CRITICO)
// =============================================================================
// NUOVE FUNZIONI per logging eventi di sicurezza

if (!function_exists('logSecurityEvent')) {
    /**
     * Log evento di sicurezza
     *
     * @param PDO $pdo Database connection
     * @param string $event_type Tipo evento (login_failed, login_success, password_change, etc.)
     * @param string $description Descrizione evento
     * @param array $context Contesto aggiuntivo (IP, user agent, etc.)
     * @param string $severity Gravità: info, warning, critical
     */
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
            )");
        } catch (PDOException $e) {
            // Tabella già esiste o errore - fallback a error_log
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
            // Fallback a error_log
            error_log("[SECURITY] Failed to log event: " . $e->getMessage());
            error_log("[SECURITY] $severity - $event_type: $description");
        }
    }
}

// =============================================================================
// 7. IMPROVED FILE VALIDATION (FIX MEDIO)
// =============================================================================
// SOSTITUISCE: config.php validateFileUpload()

if (!function_exists('validateFileUploadSecure')) {
    /**
     * Validazione sicura file upload con controlli aggiuntivi
     *
     * @param array $file $_FILES array element
     * @param array $allowed_types Estensioni permesse
     * @param int $max_size Max size in bytes
     * @return array Errors array (vuoto se OK)
     */
    function validateFileUploadSecure($file, $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'], $max_size = 5242880) {
        $errors = [];

        // Check upload errors
        if (!isset($file['error']) || is_array($file['error'])) {
            $errors[] = "Parametri file non validi";
            return $errors;
        }

        // Check PHP upload error codes
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

        // Verify file was actually uploaded
        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = "File non valido (possibile attacco)";
            return $errors;
        }

        // Check file size
        if ($file['size'] > $max_size) {
            $errors[] = "File troppo grande. Massimo " . round($max_size/1024/1024, 1) . "MB";
        }

        // Check file size (zero byte file)
        if ($file['size'] <= 0) {
            $errors[] = "File vuoto";
        }

        // Check extension
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

        // Additional security: check for embedded PHP code in images
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $content = file_get_contents($file['tmp_name']);
            if (preg_match('/<\?php/i', $content)) {
                $errors[] = "File contiene codice PHP pericoloso";
            }
        }

        return $errors;
    }
}

// =============================================================================
// ISTRUZIONI DI INSTALLAZIONE
// =============================================================================
/*

STEP 1: BACKUP
--------------
Prima di applicare le patch, fai backup di:
- config.php
- Database completo

STEP 2: APPLICARE LE PATCH
---------------------------
In config.php, SOSTITUISCI le funzioni vulnerabili con quelle corrette:

1. Sostituisci generateUuid() con generateSecureUuid()
2. Aggiungi sendSecurityHeaders() all'inizio di config.php dopo session_start()
3. Usa validatePasswordStrength() nei form di cambio password
4. Integra checkLoginRateLimit() in auth/login.php e area-soci/login.php
5. Aggiungi checkSessionTimeout() dopo session_start()
6. Usa logSecurityEvent() per tutti gli eventi critici

STEP 3: AGGIORNARE I FILE DI LOGIN
-----------------------------------
In auth/login.php, PRIMA del controllo password:

```php
try {
    checkLoginRateLimit($pdo, $email, 'admin');
} catch (Exception $e) {
    $error = $e->getMessage();
    logSecurityEvent($pdo, 'login_rate_limit', $error, ['email' => $email], 'warning');
    // Mostra errore e exit
}
```

DOPO password_verify() FALLITO:

```php
recordLoginAttempt($pdo, $email, 'admin');
logSecurityEvent($pdo, 'login_failed', 'Login fallito', ['email' => $email], 'warning');
```

DOPO login RIUSCITO:

```php
clearLoginAttempts($pdo, $email, 'admin');
logSecurityEvent($pdo, 'login_success', 'Login riuscito', ['email' => $email], 'info');
```

STEP 4: AGGIUNGERE CSRF TOKEN AI LOGIN FORM
--------------------------------------------
In auth/login.php e area-soci/login.php, AGGIUNGI nel form:

```php
<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
```

E VERIFICA all'inizio del POST handler:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Errore di sicurezza. Riprova.';
        logSecurityEvent($pdo, 'csrf_failed', 'CSRF token invalid', [], 'critical');
        // Exit
    }
    // ... resto del codice
}
```

STEP 5: TESTARE
---------------
1. Testa login con credenziali corrette
2. Testa login con credenziali errate (verifica rate limiting dopo 5 tentativi)
3. Testa session timeout
4. Verifica security_log table per eventi
5. Verifica UUID generati siano diversi e non prevedibili

STEP 6: MONITORAGGIO
--------------------
Query per monitorare security events:

```sql
-- Login failures ultimi 24h
SELECT COUNT(*), ip_address, JSON_EXTRACT(context_json, '$.email') as email
FROM security_log
WHERE event_type = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY ip_address, email
HAVING COUNT(*) > 5
ORDER BY COUNT(*) DESC;

-- Eventi critici ultimi 7 giorni
SELECT *
FROM security_log
WHERE severity = 'critical' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;
```

*/

echo "✅ Security patches loaded successfully!\n";
echo "📖 Read instructions at the end of this file for implementation.\n";
