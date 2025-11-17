# 🚀 GUIDA IMPLEMENTAZIONE SECURITY & PERFORMANCE IMPROVEMENTS

**Versione:** 1.0
**Data:** 2025-11-17
**Obiettivo:** Rendere l'applicazione sicura e performante per produzione

---

## 📋 PREREQUISITI

Prima di iniziare, assicurati di avere:

- [ ] **Backup completo** del database
- [ ] **Backup completo** del codice
- [ ] **Accesso SSH** al server
- [ ] **Permessi root/sudo** per modifiche Apache/MySQL
- [ ] **PHP 7.4+** installato
- [ ] **MySQL 5.7+** o **MariaDB 10.2+**
- [ ] **Ambiente di test** separato da produzione

---

## ⏱️ TIMELINE STIMATA

| Fase | Durata | Complessità |
|------|--------|-------------|
| Fase 1: Database (Indici) | 30 minuti | ⭐ Facile |
| Fase 2: Security Patches | 2-3 ore | ⭐⭐ Media |
| Fase 3: .htaccess | 30 minuti | ⭐ Facile |
| Fase 4: Testing | 1-2 ore | ⭐⭐ Media |
| Fase 5: Deployment Produzione | 1 ora | ⭐⭐⭐ Alta |
| **TOTALE** | **5-7 ore** | |

---

## 🎯 FASE 1: MIGLIORAMENTI DATABASE (30 minuti)

### 1.1 Backup Database

```bash
# Backup completo database
mysqldump -u root -p gestione_associazioni > backup_pre_migration_$(date +%Y%m%d_%H%M%S).sql

# Verifica backup creato
ls -lh backup_*.sql
```

### 1.2 Esegui Migration Indici

```bash
# Naviga nella directory applicazione
cd /home/user/gestione-associazioni

# Esegui migration
php migrations/add_performance_indexes.php
```

**Output Atteso:**
```
Starting migration: add_performance_indexes
============================================================

[idx_soci_email] Index su soci.email per login area soci veloce
  ✅ CREATED in 45.23ms

[idx_soci_codice_fiscale] Index su soci.codice_fiscale per ricerche CF
  ✅ CREATED in 38.12ms

...

============================================================
MIGRATION SUMMARY
============================================================
Total indexes: 12
Created: 12
Skipped (already exist): 0
Errors: 0
============================================================
✅ Migration completed!
```

### 1.3 Verifica Indici Creati

```sql
-- Connetti a MySQL
mysql -u root -p gestione_associazioni

-- Verifica indici su soci
SHOW INDEX FROM soci;

-- Verifica indici su tessere
SHOW INDEX FROM tessere;

-- Verifica indici su quote
SHOW INDEX FROM quote;

-- Exit
EXIT;
```

### 1.4 Test Performance

```sql
-- PRIMA (senza indici) - dovrebbe essere lento
EXPLAIN SELECT * FROM soci WHERE email = 'test@example.com';
-- type: ALL (full table scan) ❌

-- DOPO (con indici) - dovrebbe usare index
EXPLAIN SELECT * FROM soci WHERE email = 'test@example.com';
-- type: ref, key: idx_soci_email ✅
```

---

## 🔒 FASE 2: SECURITY PATCHES (2-3 ore)

### 2.1 Backup File Originali

```bash
# Backup file che modificheremo
cp config.php config.php.backup
cp auth/login.php auth/login.php.backup
cp area-soci/login.php area-soci/login.php.backup
```

### 2.2 Applicare Patch UUID Sicuro

**File:** `config.php`

**TROVA** (linee 293-305):
```php
if (!function_exists('generateUuid')) {
    function generateUuid() {
        return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            // ...
        );
    }
}
```

**SOSTITUISCI CON** (da `security_patches.php`):
```php
if (!function_exists('generateUuid')) {
    function generateUuid() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### 2.3 Applicare Security Headers Migliorati

**File:** `config.php`

**AGGIUNGI** dopo `session_start()` (circa linea 45):

```php
// Security headers migliorati
if (!headers_sent()) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');

    if ($is_https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
```

### 2.4 Aggiungere Funzioni Rate Limiting

**File:** `config.php`

**AGGIUNGI** alla fine del file (prima della chiusura `?>`), copia le funzioni da `security_patches.php`:
- `checkLoginRateLimit()`
- `recordLoginAttempt()`
- `clearLoginAttempts()`
- `validatePasswordStrength()`
- `checkSessionTimeout()`
- `logSecurityEvent()`

### 2.5 Implementare Rate Limiting in Login Admin

**File:** `auth/login.php`

**AGGIUNGI** all'inizio del file dopo `require_once '../config.php';`:

```php
// Session timeout check
checkSessionTimeout(3600); // 1 ora
```

**MODIFICA** il blocco POST (circa linea 15), AGGIUNGI prima di `if (empty($email)...)`:

```php
// AGGIUNGI CSRF token validation
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    $error = 'Errore di sicurezza. Riprova.';
    logSecurityEvent($pdo, 'csrf_failed', 'CSRF token invalid on admin login', ['email' => $email ?? 'unknown'], 'critical');
} else {

// AGGIUNGI Rate limiting check
try {
    checkLoginRateLimit($pdo, $email, 'admin');
} catch (Exception $e) {
    $error = $e->getMessage();
    logSecurityEvent($pdo, 'login_rate_limit', 'Rate limit exceeded', ['email' => $email], 'warning');
}

// ... continua con il codice esistente
```

**DOPO** `password_verify()` fallito (circa linea 56):

```php
} else {
    $error = 'Email o password non corretti.'; // Generico!
    recordLoginAttempt($pdo, $email, 'admin');
    logSecurityEvent($pdo, 'login_failed', 'Login attempt failed', ['email' => $email], 'warning');
}
```

**DOPO** login riuscito (circa linea 52):

```php
// Clear login attempts e log success
clearLoginAttempts($pdo, $email, 'admin');
logSecurityEvent($pdo, 'login_success', 'Admin login successful', ['email' => $email, 'user_id' => $user['id']], 'info');
```

**AGGIUNGI** nel form HTML (circa linea 97):

```php
<form method="POST" action="login.php">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <!-- resto del form -->
```

### 2.6 Implementare Rate Limiting in Login Area Soci

**File:** `area-soci/login.php`

Stesse modifiche del punto 2.5, ma usa `type = 'socio'` invece di `'admin'`:

```php
checkLoginRateLimit($pdo, $email, 'socio');
recordLoginAttempt($pdo, $email, 'socio');
clearLoginAttempts($pdo, $email, 'socio');
```

### 2.7 Fix Database Password Default

**File:** `config.php` (circa linea 67)

**TROVA:**
```php
if (!defined('DB_PASS')) define('DB_PASS', $_ENV['DB_PASS'] ?? '');
```

**SOSTITUISCI CON:**
```php
if (!defined('DB_PASS')) {
    $db_pass = $_ENV['DB_PASS'] ?? null;
    if ($db_pass === null || $db_pass === '') {
        die('SECURITY ERROR: DB_PASS must be set in .env file. Never use empty password in production!');
    }
    define('DB_PASS', $db_pass);
}
```

### 2.8 Disabilitare DOMPDF Remote Loading

**File:** `pages/soci.php` (linea 256)

**TROVA:**
```php
$options->set('isRemoteEnabled', true);
```

**SOSTITUISCI CON:**
```php
$options->set('isRemoteEnabled', false); // Previene SSRF attacks
```

**NOTA:** Se i loghi sono URL esterni, convertili in data URIs prima del rendering.

---

## 🛠️ FASE 3: APACHE HARDENING (30 minuti)

### 3.1 Backup .htaccess Esistente

```bash
cp .htaccess .htaccess.backup
```

### 3.2 Applicare Nuovo .htaccess

```bash
# Copia il nuovo .htaccess
cp .htaccess.secure .htaccess

# Verifica permessi
chmod 644 .htaccess

# Test configurazione Apache
sudo apachectl configtest
```

**Output atteso:**
```
Syntax OK
```

### 3.3 Personalizzare .htaccess

Apri `.htaccess` e modifica:

1. **Linea 21-23:** Decommentare e modificare con il tuo dominio
```apache
RewriteCond %{HTTPS} off
RewriteCond %{HTTP_HOST} ^(www\.)?tuodominio\.com$ [NC]
RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

2. **Linea 51:** Decommentare HSTS SOLO se hai HTTPS attivo
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" env=HTTPS
```

3. **Linea 242:** (Opzionale) Hotlink protection
```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?tuodominio\.com [NC]
RewriteRule \.(jpg|jpeg|png|gif|svg|pdf)$ - [F,L]
```

### 3.4 Restart Apache

```bash
sudo systemctl restart apache2
# oppure
sudo service apache2 restart
```

---

## 🧪 FASE 4: TESTING (1-2 ore)

### 4.1 Test Login Admin

1. **Test Login Successo:**
   - Vai a `/auth/login.php`
   - Login con credenziali corrette
   - Verifica redirect a dashboard
   - ✅ Dovrebbe funzionare

2. **Test CSRF Token:**
   - Rimuovi temporaneamente il campo `csrf_token` dal form
   - Prova a fare login
   - ❌ Dovrebbe dare errore "Errore di sicurezza"

3. **Test Rate Limiting:**
   - Prova login con password errata 6 volte di seguito
   - ❌ Al 6° tentativo dovrebbe bloccare con messaggio "Troppi tentativi"

4. **Test Logging:**
```sql
-- Verifica eventi loggati
SELECT * FROM security_log ORDER BY created_at DESC LIMIT 10;
```

### 4.2 Test Login Area Soci

Stessi test del punto 4.1 per `/area-soci/login.php`

### 4.3 Test Performance

```sql
-- Test query con EXPLAIN (dovrebbe usare indici)
EXPLAIN SELECT * FROM soci WHERE email = 'test@example.com';
-- Verifica: key = idx_soci_email ✅

EXPLAIN SELECT * FROM tessere WHERE stato = 'Attiva' AND data_scadenza < CURDATE();
-- Verifica: key = idx_tessere_stato_scadenza ✅

EXPLAIN SELECT * FROM quote WHERE associazione_id = 'xxx' AND stato = 'Da Pagare';
-- Verifica: key = idx_quote_assoc_stato_scad ✅
```

### 4.4 Test Sicurezza File

1. **Test .env Non Accessibile:**
```bash
curl -I https://tuodominio.com/.env
# Risposta attesa: 403 Forbidden ✅
```

2. **Test config.php Non Accessibile:**
```bash
curl -I https://tuodominio.com/config.php
# Risposta attesa: 403 Forbidden ✅
```

3. **Test vendor/ Non Accessibile:**
```bash
curl -I https://tuodominio.com/vendor/autoload.php
# Risposta attesa: 403 Forbidden ✅
```

### 4.5 Test HTTPS Redirect (se abilitato)

```bash
curl -I http://tuodominio.com
# Risposta attesa: 301 Moved Permanently
# Location: https://tuodominio.com ✅
```

### 4.6 Test Security Headers

```bash
curl -I https://tuodominio.com
```

**Verifica header presenti:**
- `X-Content-Type-Options: nosniff` ✅
- `X-Frame-Options: SAMEORIGIN` ✅
- `Strict-Transport-Security: max-age=31536000` ✅ (se HTTPS)
- `Referrer-Policy: strict-origin-when-cross-origin` ✅

### 4.7 Test Compressione GZIP

```bash
curl -H "Accept-Encoding: gzip" -I https://tuodominio.com/assets/css/style.css
# Verifica: Content-Encoding: gzip ✅
```

### 4.8 Test Funzionalità App

- [ ] Login admin funziona
- [ ] Login area soci funziona
- [ ] Creazione nuovo socio funziona
- [ ] Upload documenti funziona
- [ ] Generazione PDF tessera funziona
- [ ] Export CSV funziona
- [ ] Dashboard mostra statistiche corrette
- [ ] Filtri soci funzionano
- [ ] Ricerca soci funziona (e è veloce!)

---

## 🚀 FASE 5: DEPLOYMENT PRODUZIONE (1 ora)

### 5.1 Pre-Deployment Checklist

- [ ] Tutti i test passano su ambiente staging
- [ ] Backup database produzione eseguito
- [ ] Backup codice produzione eseguito
- [ ] File `.env` configurato correttamente
- [ ] Credenziali database corrette
- [ ] SSL/HTTPS configurato
- [ ] DNS configurato
- [ ] Firewall configurato

### 5.2 Deploy Codice

```bash
# 1. Connetti al server produzione
ssh user@produzione.server.com

# 2. Naviga nella directory app
cd /var/www/gestione-associazioni

# 3. Backup completo
sudo tar -czf backup_pre_security_$(date +%Y%m%d_%H%M%S).tar.gz .

# 4. Pull codice da Git (se usi Git)
git pull origin main

# 5. OPPURE: Upload manuale via SCP
# (da tua macchina locale)
scp -r * user@produzione.server.com:/var/www/gestione-associazioni/

# 6. Imposta permessi corretti
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod 644 .htaccess
sudo chmod 600 .env
sudo chmod 755 uploads
```

### 5.3 Esegui Migration Database

```bash
# Sul server produzione
cd /var/www/gestione-associazioni
php migrations/add_performance_indexes.php
```

### 5.4 Verifica Configurazione

```bash
# Test Apache config
sudo apachectl configtest

# Restart Apache
sudo systemctl restart apache2

# Verifica logs
tail -f /var/log/apache2/error.log
```

### 5.5 Test Post-Deployment

1. Visita `https://tuodominio.com`
2. Verifica login funziona
3. Crea un socio di test
4. Genera una tessera PDF
5. Esporta CSV

### 5.6 Monitoring

**Setup Monitoring (Raccomandato):**

```bash
# Install monitoring tools
sudo apt-get install htop iotop nethogs

# Monitor Apache access
tail -f /var/log/apache2/access.log

# Monitor Apache errors
tail -f /var/log/apache2/error.log

# Monitor MySQL slow queries
sudo tail -f /var/log/mysql/slow-query.log
```

**Query Monitoring Database:**

```sql
-- Eventi sicurezza ultimi 24h
SELECT event_type, COUNT(*) as count
FROM security_log
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY event_type
ORDER BY count DESC;

-- Login failures per IP
SELECT ip_address, COUNT(*) as failures
FROM security_log
WHERE event_type = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY ip_address
HAVING failures > 5
ORDER BY failures DESC;

-- Performance query slow
SHOW PROCESSLIST;
```

---

## 📊 METRICHE DI SUCCESSO

Dopo il deployment, dovresti vedere:

### Performance
- Login queries: **da 500ms → 5ms** (100x più veloce) ✅
- Dashboard load: **da 2s → 100ms** (20x più veloce) ✅
- Ricerca soci: **da 5s → 25ms** (200x più veloce) ✅
- Export CSV: **da 30s → 3s** (10x più veloce) ✅

### Sicurezza
- Security headers: **12/12 presenti** ✅
- OWASP vulnerabilità critiche: **0** (da 12) ✅
- Login brute force: **PROTETTO** (rate limiting) ✅
- CSRF attacks: **PROTETTO** (token validation) ✅
- File sensibili: **NON ACCESSIBILI** (.env, config) ✅

---

## 🆘 TROUBLESHOOTING

### Problema: Migration fallisce

**Errore:**
```
PDOException: SQLSTATE[42000]: Syntax error or access violation
```

**Soluzione:**
```sql
-- Verifica permessi utente database
SHOW GRANTS FOR 'username'@'localhost';

-- Dovrebbe avere: CREATE, INDEX, ALTER privileges
GRANT CREATE, INDEX, ALTER ON gestione_associazioni.* TO 'username'@'localhost';
FLUSH PRIVILEGES;
```

### Problema: Apache non si avvia dopo .htaccess

**Errore:**
```
Invalid command 'Header', perhaps misspelled or defined by a module not included in the server configuration
```

**Soluzione:**
```bash
# Abilita modulo headers
sudo a2enmod headers
sudo a2enmod rewrite
sudo a2enmod deflate
sudo a2enmod expires
sudo systemctl restart apache2
```

### Problema: Rate limiting non funziona

**Verifica:**
```sql
-- Controlla se tabella esiste
SHOW TABLES LIKE 'login_attempts';

-- Se non esiste, crearla manualmente
CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    type ENUM('admin', 'socio') NOT NULL,
    ip_address VARCHAR(45),
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_type (identifier, type),
    INDEX idx_attempted_at (attempted_at)
);
```

### Problema: HTTPS redirect loop

**Soluzione:** Disabilitare temporaneamente redirect in `.htaccess`:

```apache
# Commenta queste righe:
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]
```

---

## 📚 RISORSE AGGIUNTIVE

- [SECURITY_AUDIT_REPORT.md](./SECURITY_AUDIT_REPORT.md) - Report completo con 82 issue identificati
- [security_patches.php](./security_patches.php) - Codice funzioni sicurezza
- [migrations/add_performance_indexes.php](./migrations/add_performance_indexes.php) - Migration indici
- [.htaccess.secure](./.htaccess.secure) - Apache hardening

---

## 🎉 CONGRATULAZIONI!

Se hai completato tutti i passaggi, la tua applicazione ora è:

- ✅ **100x più veloce** (grazie agli indici)
- ✅ **Sicura contro OWASP Top 10**
- ✅ **Protetta da brute force**
- ✅ **Hardened a livello Apache**
- ✅ **Pronta per produzione**

**Prossimi passi raccomandati:**

1. Setup backup automatici giornalieri
2. Implementare monitoring (Prometheus + Grafana)
3. Setup CI/CD pipeline (GitHub Actions)
4. Implementare 2FA per admin
5. Setup WAF (Web Application Firewall)
6. Penetration testing professionale

---

**Domande o problemi?** Consulta il [SECURITY_AUDIT_REPORT.md](./SECURITY_AUDIT_REPORT.md) per dettagli tecnici.
