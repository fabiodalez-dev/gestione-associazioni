# Associazione Soci Manager - Versione PHP/MySQL

Applicazione completa per la gestione dei soci di un'associazione, convertita da React a PHP con database MySQL.

## 🚀 Funzionalità Principali

### ✅ **Dashboard**
- Statistiche complete sui soci
- Grafici di crescita e attività
- Panoramica quote e scadenze
- Attività recenti e prossimi eventi

### ✅ **Gestione Soci**
- CRUD completo per i soci
- Ricerca e filtri avanzati
- Export CSV
- Campi completi: anagrafica, contatti, indirizzi, privacy

### ✅ **Gestione Eventi**
- Creazione e modifica eventi
- Calendario integrato
- Export e gestione partecipazioni

### ✅ **Quote e Pagamenti**
- Gestione quote associative
- Tracking pagamenti
- Stati automatici (Pagata, Da Pagare, Scaduta)
- Report e statistiche

### ✅ **Comunicazioni**
- Invio comunicazioni massive
- Filtro per categorie e sezioni
- Gestione consensi privacy

### ✅ **Documenti**
- Upload e gestione documenti
- Categorizzazione
- Download sicuro
- Formati supportati: PDF, DOC, XLS, PPT, immagini

### ✅ **Configurazioni**
- Gestione tipologie soci
- Sezioni e categorie
- Impostazioni associazione
- Gestione amministratori

## 🛠️ Tecnologie Utilizzate

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Frontend**: Bootstrap 5, HTML5, CSS3, JavaScript
- **Icone**: Bootstrap Icons
- **Architettura**: MVC Pattern, PDO per database

## 📋 Requisiti di Sistema

- PHP 7.4 o superiore
- MySQL 5.7 o superiore
- Apache/Nginx
- Estensioni PHP: PDO, PDO_MySQL, GD (per immagini)
- 50MB spazio disco
- 128MB RAM

## 🔧 Installazione

### 1. Clona il Repository
```bash
git clone [repository-url]
cd associazione-soci-manager
```

### 2. Configura il Database
```sql
CREATE DATABASE associazione_soci;
```

### 3. Importa lo Schema
```bash
mysql -u username -p associazione_soci < database_schema.sql
```

### 4. Configura l'Applicazione
Modifica `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'associazione_soci');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
```

### 5. Imposta i Permessi
```bash
chmod 755 uploads/
chmod 755 uploads/documents/
chmod 755 uploads/profile_photos/
```

### 6. Accedi all'Applicazione
Visita: `http://localhost/associazione-soci-manager`

## 🔐 Credenziali di Accesso

**Amministratore di Sistema:**
- Username: `admin`
- Password: `admin123`

> ⚠️ **Importante**: Cambia le credenziali di default dopo il primo accesso!

## 📁 Struttura del Progetto

```
├── api/                  # API endpoints
│   └── export.php       # Funzioni di export
├── assets/              # Asset statici
│   ├── css/
│   │   └── style.css    # Stili personalizzati
│   └── js/
│       └── main.js      # JavaScript principale
├── auth/                # Sistema di autenticazione
│   ├── login.php        # Pagina di login
│   └── logout.php       # Logout
├── includes/            # Include files
│   └── sidebar.php      # Sidebar navigazione
├── pages/               # Pagine dell'applicazione
│   ├── dashboard.php
│   ├── soci.php
│   ├── eventi.php
│   ├── quote.php
│   ├── comunicazioni.php
│   ├── documenti.php
│   ├── configurazioni.php
│   ├── amministratori.php
│   ├── categorie-socio.php
│   ├── tipi-socio.php
│   ├── sezioni.php
│   └── 404.php
├── uploads/             # File caricati
│   ├── documents/       # Documenti
│   └── profile_photos/  # Foto profilo
├── config.php           # Configurazione principale
├── index.php            # Entry point
├── database_schema.sql  # Schema database
└── README_PHP.md        # Documentazione
```

## 🔒 Sicurezza

- ✅ Autenticazione sicura con password hash
- ✅ Protezione SQL Injection (PDO Prepared Statements)
- ✅ Sanitizzazione input utente
- ✅ Validazione file upload
- ✅ Controllo accessi e sessioni
- ✅ CSRF Protection sui form critici

## 📊 Database Schema

### Tabelle Principali:
- `soci` - Anagrafica soci
- `eventi` - Eventi associazione
- `quote` - Quote e pagamenti
- `categorie_socio` - Categorie soci
- `tipi_socio` - Tipologie soci
- `sezioni` - Sezioni associazione
- `documenti` - Documenti caricati
- `users` - Amministratori sistema

## 🎨 Personalizzazione

### Modificare il Tema
Modifica le variabili CSS in `assets/css/style.css`:
```css
:root {
    --primary-color: #2470dc;
    --secondary-color: #6c757d;
    /* ... altre variabili */
}
```

### Aggiungere Nuove Pagine
1. Crea il file in `pages/nuova-pagina.php`
2. Aggiungi il routing in `index.php`
3. Aggiungi link in `includes/sidebar.php`

## 🚀 Deployment in Produzione

### 1. Configurazione Server
- PHP 7.4+ con OPcache abilitato
- MySQL con InnoDB engine
- HTTPS obbligatorio
- Backup automatici database

### 2. Ottimizzazioni
```php
// In config.php per produzione
ini_set('display_errors', 0);
error_reporting(0);
```

### 3. Backup
```bash
# Database backup
mysqldump -u username -p associazione_soci > backup_$(date +%Y%m%d).sql

# Files backup
tar -czf files_backup_$(date +%Y%m%d).tar.gz uploads/
```

## 🤝 Contributi

1. Fork del progetto
2. Crea feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit delle modifiche (`git commit -m 'Add AmazingFeature'`)
4. Push al branch (`git push origin feature/AmazingFeature`)
5. Apri una Pull Request

## 📝 TODO Future

- [ ] Sistema di notifiche email
- [ ] App mobile companion
- [ ] Integrazione pagamenti online
- [ ] Reporting avanzato con grafici
- [ ] API REST per integrazioni
- [ ] Multi-lingua (i18n)
- [ ] Audit log completo
- [ ] Dashboard amministratore avanzata

## 📞 Supporto

Per supporto e segnalazioni:
- 🐛 Issues: [GitHub Issues]
- 📧 Email: admin@associazione.it
- 📚 Wiki: [GitHub Wiki]

## 📄 Licenza

Questo progetto è rilasciato sotto licenza MIT. Vedi `LICENSE` per maggiori dettagli.

---

**Versione**: 1.0.0  
**Ultimo aggiornamento**: Agosto 2025  
**Stato**: ✅ Completamente funzionale e pronto per produzione