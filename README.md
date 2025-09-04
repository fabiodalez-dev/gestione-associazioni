# Associazione Soci Manager - Versione PHP

Sistema completo per la gestione di un'associazione sviluppato in PHP con MySQL.

## 🚀 Funzionalità

### Core Features
- ✅ **Gestione Soci**: Anagrafica completa, registrazione, modifica, ricerca avanzata
- ✅ **Eventi**: Creazione eventi, gestione partecipanti, calendario
- ✅ **Quote e Pagamenti**: Tracciamento quote associative, scadenze, stati pagamento
- ✅ **Comunicazioni**: Sistema email integrato, template personalizzabili
- ✅ **Documenti**: Upload, categorizzazione, gestione file

### Advanced Features
- ✅ **Dashboard Avanzata**: Statistiche real-time con grafici Chart.js
- ✅ **Sistema Notifiche**: Promemoria automatici per scadenze e pagamenti
- ✅ **Gestione Verbali**: Creazione, modifica, sistema di approvazione
- ✅ **Tessere Associative**: Generazione, tracking scadenze, rinnovi
- ✅ **Sistema Scadenze**: Gestione deadline, priorità, assegnazioni
- ✅ **Backup Automatico**: Database e file system, scheduling automatico
- ✅ **API RESTful**: Endpoint completi per integrazioni esterne
- ✅ **Sicurezza Avanzata**: Headers CSP, protezioni XSS/CSRF, audit log

## 📋 Requisiti

- **PHP**: 7.4 o superiore
- **MySQL**: 5.7 o superiore  
- **Web Server**: Apache/Nginx con mod_rewrite
- **Estensioni PHP**: PDO, zip, json, curl, mbstring

## 🛠 Installazione

### Metodo 1: Installer Automatico (Raccomandato)

1. **Setup Check**
   ```bash
   # Verifica prerequisiti sistema
   http://your-domain/setup-check.php
   ```

2. **Installer Guidato**
   ```bash
   # Accedi all'installer web
   http://your-domain/install.php
   ```

3. **Segui il Wizard**
   - ✅ Verifica automatica requisiti
   - ✅ Configurazione database automatica
   - ✅ Creazione amministratore
   - ✅ Configurazione applicazione
   - ✅ Setup directory e permessi

### Metodo 2: Installazione Manuale

Se preferisci l'installazione manuale:

1. **Database Setup**
```sql
-- Creare il database
CREATE DATABASE associazione_soci;

-- Importare lo schema
mysql -u username -p associazione_soci < database_schema.sql
```

2. **Configurazione**
```php
// Editare config.php con le tue credenziali
define('DB_HOST', 'localhost');
define('DB_NAME', 'associazione_soci');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

3. **Web Server**
```apache
# Apache VirtualHost example
<VirtualHost *:80>
    DocumentRoot "/path/to/php-project"
    ServerName associazione.local
    
    <Directory "/path/to/php-project">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

4. **Permessi**
```bash
# Impostare permessi per uploads e backup
chmod 755 uploads/
chmod 755 backups/
chmod 644 config.php
```

## 📁 Struttura

```
php-project/
├── api/                    # API RESTful endpoints
│   ├── backup.php         # Sistema backup
│   ├── export.php         # Export dati
│   └── notifications.php  # Notifiche real-time
├── assets/                # Assets statici
│   ├── css/style.css      # Styling personalizzato
│   └── js/main.js         # JavaScript core
├── auth/                  # Sistema autenticazione
├── includes/              # Template parti comuni
├── pages/                 # Pagine applicazione
├── uploads/               # File caricati
├── backups/               # Backup automatici
├── config.php             # Configurazione database
├── database_schema.sql    # Schema database
└── index.php              # Entry point
```

## 🎯 Utilizzo

### Login Iniziale
1. Importare `database_schema.sql`
2. Il sistema creerà automaticamente un admin di default
3. Accedere con le credenziali temporanee (vedere config.php)
4. Cambiare immediatamente password e dati admin

### Gestione Quotidiana
- **Dashboard**: Overview completa di soci, eventi, scadenze
- **Soci**: Gestione anagrafica, quote, tessere
- **Eventi**: Pianificazione, gestione partecipanti
- **Notifiche**: Monitoraggio scadenze automatico
- **Backup**: Sistema automatico ogni notte

### API Usage
```javascript
// Esempio chiamata API notifiche
fetch('api/notifications.php')
    .then(response => response.json())
    .then(data => console.log(data.notifications));

// Esempio backup via API
fetch('api/backup.php?action=complete')
    .then(response => response.json())
    .then(data => console.log('Backup:', data.message));
```

## 🔒 Sicurezza

- **Headers CSP**: Content Security Policy configurata
- **XSS Protection**: Validazione input completa
- **CSRF Protection**: Token per form sensibili
- **File Access**: Directory backup protette
- **SQL Injection**: Prepared statements everywhere
- **Session Security**: Configurazione sicura sessioni PHP

## 📊 Performance

- **Database**: Indici ottimizzati per query frequenti
- **Caching**: Headers cache per asset statici
- **Compression**: Gzip abilitato per contenuti testuali
- **CDN**: Bootstrap e Chart.js da CDN
- **Minification**: CSS e JS ottimizzati

## 🆘 Troubleshooting

### Problemi Comuni

**Errore connessione database**
- Verificare credenziali in config.php
- Controllare che MySQL sia avviato
- Verificare permessi utente database

**File upload non funziona**
- Controllare permessi directory uploads/
- Verificare php.ini: upload_max_filesize, post_max_size
- Controllare spazio disco disponibile

**Backup fallisce**
- Verificare permessi directory backups/
- Controllare estensione PHP zip
- Verificare spazio disco disponibile

### Debug Mode
```php
// Abilitare in config.php per development
define('DEBUG_MODE', true);
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📈 Monitoraggio

- **Audit Log**: Tutte le azioni utente registrate
- **Backup Log**: Storico backup automatici
- **Error Logging**: Log errori PHP configurato
- **Performance**: Query timing in debug mode

## 🔄 Aggiornamenti

Per aggiornamenti futuri:
1. Backup completo del sistema
2. Test in ambiente staging
3. Applicare modifiche database se necessarie
4. Deploy codice aggiornato
5. Verificare funzionamento

## 📞 Supporto

Per supporto tecnico o personalizzazioni, documentare:
- Versione PHP in uso
- Configurazione database
- Log errori specifici
- Steps per riprodurre il problema