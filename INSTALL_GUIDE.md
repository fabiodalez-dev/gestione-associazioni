# 🚀 Guida Installazione Completa
## Associazione Soci Manager - Versione PHP

Questa guida ti aiuterà a installare Associazione Soci Manager su qualsiasi server web.

## 📋 Prima di Iniziare

### Requisiti Minimi
- **PHP 7.4** o superiore (Raccomandato: PHP 8.0+)
- **MySQL 5.7** o MariaDB 10.2+ 
- **Apache** con mod_rewrite o **Nginx**
- **Almeno 128MB** RAM per PHP
- **100MB** spazio disco disponibile

### Estensioni PHP Richieste
- `pdo` - Database abstraction
- `pdo_mysql` - MySQL driver
- `json` - JSON processing
- `mbstring` - Multibyte strings
- `zip` - Archive support
- `curl` - HTTP client

### Estensioni PHP Consigliate
- `imagick` o `gd` - Image processing
- `openssl` - Security
- `intl` - Internationalization
- `opcache` - Performance

## 🔧 Metodi di Installazione

### ✨ Metodo 1: Installer Automatico (Consigliato)

L'installer guidato è il modo più semplice e sicuro per installare l'applicazione.

#### Passo 1: Upload Files
```bash
# Upload tutti i file del progetto nella directory web
# Esempio con scp:
scp -r php-project/* user@server:/var/www/html/

# O via FTP/SFTP usando FileZilla, WinSCP, etc.
```

#### Passo 2: Verifica Prerequisiti
```bash
# Naviga nel browser:
http://your-domain.com/setup-check.php

# Il sistema verificherà automaticamente:
# ✅ Versione PHP
# ✅ Estensioni richieste  
# ✅ Permessi directory
# ✅ Configurazione server
```

#### Passo 3: Esegui Installer
```bash
# Accedi all'installer:
http://your-domain.com/install.php

# Segui i 7 step guidati:
# 1. Benvenuto
# 2. Verifica Requisiti
# 3. Configurazione Database
# 4. Amministratore Iniziale
# 5. Configurazioni App
# 6. Installazione Automatica
# 7. Completamento
```

#### Passo 4: Accesso
```bash
# Accedi all'applicazione:
http://your-domain.com/

# Usa le credenziali admin create durante l'installazione
```

---

### 🛠 Metodo 2: Installazione Manuale

Per utenti avanzati o server con configurazioni particolari.

#### Passo 1: Preparazione Database

```sql
-- Connettiti a MySQL
mysql -u root -p

-- Crea database
CREATE DATABASE associazione_soci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Crea utente dedicato (opzionale ma consigliato)
CREATE USER 'assoc_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON associazione_soci.* TO 'assoc_user'@'localhost';
FLUSH PRIVILEGES;

-- Importa schema
USE associazione_soci;
SOURCE /path/to/database_schema.sql;
```

#### Passo 2: Configurazione

Crea il file `config.php`:

```php
<?php
/**
 * Configurazione Associazione Soci Manager
 */

// Database
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'associazione_soci');
define('DB_USER', 'assoc_user');
define('DB_PASS', 'strong_password_here');

// Applicazione
define('APP_NAME', 'La Mia Associazione');
define('APP_TIMEZONE', 'Europe/Rome');
define('APP_LOCALE', 'it_IT');
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('ENABLE_BACKUP', true);
define('ENABLE_NOTIFICATIONS', true);

// Sicurezza
define('SESSION_TIMEOUT', 3600);
define('BCRYPT_ROUNDS', 12);

// Percorsi
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('BACKUP_PATH', __DIR__ . '/backups/');

// Connessione Database
try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false
    ]);
} catch (PDOException $e) {
    die('Errore connessione database: ' . $e->getMessage());
}

// Gestione Sessioni
session_start();
date_default_timezone_set(APP_TIMEZONE);

// Utility Functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function formatDate($date, $format = 'd/m/Y') {
    return date($format, strtotime($date));
}

function formatCurrency($amount) {
    return '€ ' . number_format($amount, 2, ',', '.');
}
?>
```

#### Passo 3: Creazione Amministratore

```sql
-- Inserisci utente amministratore iniziale
INSERT INTO amministratori (nome, cognome, email, username, password, ruolo, attivo, created_at) 
VALUES (
    'Admin',
    'Sistema',
    'admin@example.com',
    'admin',
    '$2y$12$example_hash_here', -- Usa password_hash('your_password', PASSWORD_DEFAULT)
    'super_admin',
    1,
    NOW()
);
```

#### Passo 4: Permessi e Directory

```bash
# Crea directory necessarie
mkdir -p uploads/{documents,images}
mkdir -p backups/{database,files}

# Imposta permessi
chmod 755 uploads/ backups/
chmod -R 755 uploads/* backups/*
chmod 644 config.php
chmod 644 database_schema.sql

# Proteggi directory sensibili
echo "Order allow,deny\nDeny from all" > backups/.htaccess
```

---

## 🌐 Configurazione Web Server

### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/html/associazione
    
    <Directory /var/www/html/associazione>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        
        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
    </Directory>
    
    # Logs
    ErrorLog ${APACHE_LOG_DIR}/associazione_error.log
    CustomLog ${APACHE_LOG_DIR}/associazione_access.log combined
</VirtualHost>

# HTTPS (Raccomandato per produzione)
<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/html/associazione
    
    SSLEngine on
    SSLCertificateFile /path/to/certificate.crt
    SSLCertificateKeyFile /path/to/private.key
    
    # Same configuration as HTTP version
    # ...
</VirtualHost>
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html/associazione;
    index index.php index.html;
    
    # Security
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # PHP processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    
    # Deny access to sensitive files
    location ~ ^/(config\.php|database_schema\.sql|\.env)$ {
        deny all;
        return 404;
    }
    
    # Deny access to backups
    location ^~ /backups/ {
        deny all;
        return 404;
    }
    
    # Enable compression
    gzip on;
    gzip_types text/plain text/css application/javascript application/json;
    
    # Logs
    error_log /var/log/nginx/associazione_error.log;
    access_log /var/log/nginx/associazione_access.log;
}
```

---

## 🔒 Post-Installazione e Sicurezza

### 1. Rimuovi File di Installazione

```bash
# IMPORTANTE: Elimina i file di installazione
rm install.php
rm setup-check.php
# Mantieni uninstall.php solo se necessario
```

### 2. Hardening di Sicurezza

```bash
# Cambia password database
ALTER USER 'assoc_user'@'localhost' IDENTIFIED BY 'new_stronger_password';

# Verifica permessi file
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
chmod 600 config.php

# Backup delle configurazioni
cp config.php config.php.backup
```

### 3. Configurazione Backup Automatico

Aggiungi al crontab del server:

```bash
# Modifica crontab
crontab -e

# Aggiungi backup notturno (2:00 AM)
0 2 * * * cd /var/www/html/associazione && php -f api/backup.php?action=complete >/dev/null 2>&1

# Pulizia backup vecchi (settimanale)
0 3 * * 0 find /var/www/html/associazione/backups -name "*.sql" -mtime +30 -delete
0 3 * * 0 find /var/www/html/associazione/backups -name "*.zip" -mtime +30 -delete
```

---

## 🧪 Test e Verifica

### 1. Test Funzionalità Base

```bash
# Accedi come amministratore
http://your-domain.com/

# Testa le pagine principali:
# ✅ Dashboard
# ✅ Gestione Soci
# ✅ Eventi  
# ✅ Quote
# ✅ Comunicazioni
# ✅ Documenti
```

### 2. Test API

```bash
# Test API notifiche
curl -X GET "http://your-domain.com/api/notifications.php" \
     -H "Cookie: PHPSESSID=your_session_id"

# Test backup API
curl -X GET "http://your-domain.com/api/backup.php?action=list" \
     -H "Cookie: PHPSESSID=your_session_id"
```

### 3. Test Sicurezza

```bash
# Verifica file protetti
curl -I http://your-domain.com/config.php          # Dovrebbe dare 403/404
curl -I http://your-domain.com/backups/            # Dovrebbe dare 403/404
curl -I http://your-domain.com/database_schema.sql # Dovrebbe dare 403/404
```

---

## 🆘 Risoluzione Problemi

### Errori Comuni

**1. Database Connection Failed**
```bash
# Verifica credenziali in config.php
# Testa connessione manuale
mysql -h localhost -u assoc_user -p associazione_soci
```

**2. Permission Denied Errors**
```bash
# Verifica proprietario file
chown -R www-data:www-data /var/www/html/associazione

# Verifica permessi
chmod -R 755 uploads/ backups/
```

**3. 500 Internal Server Error**
```bash
# Controlla log errori
tail -f /var/log/apache2/error.log
# o per Nginx:
tail -f /var/log/nginx/error.log

# Verifica sintassi PHP
php -l index.php
```

**4. Session Problems**
```bash
# Verifica configurazione sessioni PHP
php -m | grep session

# Controlla directory sessioni
ls -la /var/lib/php/sessions/
```

### Log e Debug

Per debug temporaneo, aggiungi in `config.php`:

```php
// Solo per debug - RIMUOVI in produzione!
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');
```

---

## 📞 Supporto

### Documentazione
- **README.md** - Overview del progetto
- **Database Schema** - Struttura tabelle in `database_schema.sql`
- **API Docs** - Endpoint disponibili in ogni file `api/`

### File di Supporto
- `setup-check.php` - Verifica stato sistema
- `uninstall.php` - Rimozione completa applicazione

### Community e Sviluppo
- Issues e bug su GitHub repository
- Customizzazioni e miglioramenti
- Contributi benvenuti

---

**🎉 Congratulazioni!**

Se hai seguito questa guida, ora dovresti avere un'installazione funzionante di Associazione Soci Manager pronta per l'uso in produzione.

Per qualsiasi problema, consulta la sezione troubleshooting o i log di sistema.