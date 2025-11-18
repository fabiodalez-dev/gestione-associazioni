# Landing Page & User Registration System

Sistema completo di landing page e registrazione utenti con approvazione admin.

## 🎨 Design

**Color Palette: Black / Silver / White**
- Primary: `#000000` (Black)
- Secondary: `#C0C0C0` (Silver)
- Background: `#FFFFFF` (White)
- Modern minimalist design con effetti glassmorphism e animazioni smooth

## 📦 Setup NPM (No CDN!)

Tutti gli asset sono gestiti localmente tramite npm:

```bash
# Installa dipendenze
npm install

# Copia asset da node_modules a assets/
npm run copy:assets

# Build (se usi SCSS)
npm run build

# Watch mode per sviluppo
npm run watch
```

### Asset Locali Installati

```
assets/
├── css/
│   ├── bootstrap.min.css          (Bootstrap 5.3.2)
│   ├── bootstrap-icons.min.css    (Bootstrap Icons 1.11.1)
│   └── landing.css                (Custom theme)
├── js/
│   ├── bootstrap.bundle.min.js    (Bootstrap JS + Popper)
│   └── Sortable.min.js            (SortableJS 1.15.0)
└── fonts/
    ├── bootstrap-icons.woff
    └── bootstrap-icons.woff2
```

**Total size: ~850KB** (tutto locale, zero CDN!)

## 🏠 Landing Page

**File:** `landing.php`

### Features

- ✅ Hero section con animazioni gradient
- ✅ 6 feature cards:
  - Gestione Soci
  - Tessere & Quote
  - Sicurezza OWASP
  - Sistema Plugin
  - Comunicazioni
  - Analytics
- ✅ Responsive design (mobile-first)
- ✅ Loading overlay animato
- ✅ Smooth scroll navigation
- ✅ Link a registrazione e login

### Accesso

```
https://your-domain.com/landing.php
```

## 📝 Sistema Registrazione

### 1. Migration Database

**Prima di tutto**, esegui la migration per aggiungere il sistema di approvazione:

```bash
php migrations/add_user_approval_system.php
```

Questo aggiunge:
- Colonna `status` a users (pending|approved|rejected|suspended)
- Colonne `approved_by`, `approved_at`, `rejection_reason`
- Colonne profilo: `nome`, `cognome`, `telefono`, `nome_associazione`, `note_registrazione`
- Tabella `user_approval_log` per audit trail
- Tabella `pending_registrations` per tracking registrazioni

### 2. Form Registrazione

**File:** `auth/register.php`

#### Campi Form

| Campo | Tipo | Obbligatorio | Validazione |
|-------|------|--------------|-------------|
| Nome | text | ✅ | - |
| Cognome | text | ✅ | - |
| Email | email | ✅ | Unique, valid email |
| Telefono | tel | ❌ | - |
| Nome Associazione | text | ✅ | - |
| Password | password | ✅ | 12+ chars, uppercase, lowercase, number |
| Conferma Password | password | ✅ | Must match |
| Note | textarea | ❌ | Max 1000 chars |

#### Validazione Password

```
✅ Minimo 12 caratteri
✅ Almeno una lettera maiuscola
✅ Almeno una lettera minuscola
✅ Almeno un numero
```

#### Workflow

1. User compila form registrazione
2. Validazione lato client (HTML5) e server (PHP)
3. Password hashata con `password_hash()` (BCRYPT)
4. User creato con:
   - `status = 'pending'`
   - `attivo = FALSE`
   - Username auto-generato: `email_prefix_XXXXXX`
5. Log creato in `user_approval_log` (action: 'registered')
6. Token generato in `pending_registrations`
7. Security event logged
8. Messaggio: "Registrazione in attesa di approvazione"

### 3. Admin Approval Interface

**File:** `pages/user-approvals.php`
**URL:** `index.php?page=user-approvals`
**Accesso:** Solo admin e super_admin

#### Features

##### Statistics Dashboard
- 📊 4 cards: Pending / Approved / Rejected / Suspended
- 📈 Real-time counts from database

##### Pending Users Table
- 👤 User details: nome, cognome, email, telefono
- 🏢 Nome associazione
- 📅 Data registrazione + giorni in attesa (color-coded)
- 🌍 IP address tracking
- ⚡ Quick actions: Approve / Reject / View Details

##### Actions

**Approve:**
- Click "Approva" button
- Conferma con alert
- Updates:
  - `status = 'approved'`
  - `attivo = TRUE`
  - `approved_by = current_admin_id`
  - `approved_at = NOW()`
- Log in `user_approval_log`
- Security event logged
- TODO: Email inviata all'utente

**Reject:**
- Click "Rifiuta" button
- Modal con campo "Motivazione"
- Updates:
  - `status = 'rejected'`
  - `attivo = FALSE`
  - `approved_by = current_admin_id`
  - `approved_at = NOW()`
  - `rejection_reason = input_value`
- Log in `user_approval_log`
- TODO: Email inviata all'utente

**View Details:**
- Modal con tutte le informazioni:
  - Dati personali completi
  - Data registrazione precisa
  - IP address
  - Note registrazione

##### Recently Processed
- Ultimi 20 utenti approvati/rifiutati (30 giorni)
- Mostra: chi ha approvato/rifiutato + quando + motivazione

### 4. Sidebar Integration

**File:** `includes/sidebar.php`

Aggiunta voce menu "Approvazioni":
- 🔔 Badge con count pending users
- ⚠️ Colore warning se ci sono pending
- 👁️ Visibile solo ad admin/super_admin

```php
<?php
$pending_count = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
if ($pending_count > 0): ?>
    <span class="badge bg-warning"><?php echo $pending_count; ?></span>
<?php endif; ?>
```

## 🔒 Sicurezza

### Implementata

✅ **CSRF Protection**
- Token su tutti i form
- Validazione server-side

✅ **Password Security**
- Hash con BCRYPT
- Validazione complessità
- Min 12 caratteri

✅ **Input Validation**
- Server-side validation
- Email uniqueness check
- SQL injection protection (prepared statements)
- XSS protection (htmlspecialchars)

✅ **Rate Limiting**
- Eredita sistema esistente da `checkLoginRateLimit()`

✅ **Security Logging**
- Tutti gli eventi tracciati in `security_log`
- Audit trail completo in `user_approval_log`

✅ **IP Tracking**
- IP salvato in `pending_registrations`
- Visibile agli admin per review

### TODO

⚠️ **Email Notifications**
```php
// TODO: Implement in auth/register.php
// - Send confirmation email to user
// - Send notification to admins

// TODO: Implement in pages/user-approvals.php
// - Send approval email
// - Send rejection email with reason
```

⚠️ **Email Verification**
```php
// TODO: Add email verification step
// - Send verification link with token
// - User clicks link to verify email
// - Then admin approves
```

## 📊 Database Schema

### users (modifiche)

```sql
status ENUM('pending', 'approved', 'rejected', 'suspended') DEFAULT 'pending'
approved_by INT NULL
approved_at TIMESTAMP NULL
rejection_reason TEXT NULL
attivo BOOLEAN DEFAULT TRUE
nome VARCHAR(100) NULL
cognome VARCHAR(100) NULL
telefono VARCHAR(20) NULL
nome_associazione VARCHAR(255) NULL
note_registrazione TEXT NULL

INDEX idx_users_status (status)
INDEX idx_users_attivo (attivo)
FOREIGN KEY (approved_by) REFERENCES users(id)
```

### user_approval_log (nuova)

```sql
id INT AUTO_INCREMENT PRIMARY KEY
user_id INT NOT NULL
action ENUM('registered', 'approved', 'rejected', 'suspended', 'reactivated')
performed_by INT NULL
reason TEXT NULL
ip_address VARCHAR(45) NULL
user_agent TEXT NULL
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
```

### pending_registrations (nuova)

```sql
id INT AUTO_INCREMENT PRIMARY KEY
user_id INT NOT NULL UNIQUE
verification_token VARCHAR(64) UNIQUE
ip_address VARCHAR(45)
user_agent TEXT
referrer VARCHAR(255)
additional_data JSON
expires_at TIMESTAMP
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
```

## 🎯 User Journey

### Scenario: Nuovo Utente

1. **Visita landing page**
   - `landing.php`
   - Legge features
   - Click "Registrati"

2. **Compila form registrazione**
   - `auth/register.php`
   - Inserisce dati
   - Crea password sicura
   - Submit form

3. **Validazione e creazione**
   - ✅ Validazione dati
   - ✅ Email unique check
   - ✅ Password hashing
   - ✅ User created (pending)
   - ✅ Security logged

4. **Messaggio conferma**
   - "Registrazione completata!"
   - "In attesa di approvazione"
   - Link al login (disabilitato)

5. **Email (TODO)**
   - Conferma registrazione
   - Informazioni su tempi approvazione

### Scenario: Admin Approva

1. **Login admin**
   - Vede badge "Approvazioni (1)"

2. **Accede a user-approvals**
   - Vede pending user in tabella
   - Vede giorni in attesa (es: "2 giorni")

3. **Review informazioni**
   - Nome, email, associazione
   - IP address
   - Note registrazione

4. **Approva user**
   - Click "Approva"
   - Conferma
   - ✅ Status → approved
   - ✅ Account attivato

5. **Email (TODO)**
   - User riceve email approvazione
   - Può fare login

### Scenario: Admin Rifiuta

1. **Review user**
   - Informazioni incomplete
   - Associazione sospetta

2. **Rifiuta con motivazione**
   - Click "Rifiuta"
   - Modal: "Associazione non riconosciuta"
   - Submit

3. **User rejected**
   - ✅ Status → rejected
   - ✅ Reason salvata
   - ✅ Account disattivato

4. **Email (TODO)**
   - User riceve email rifiuto
   - Include motivazione

## 🚀 Deployment

### 1. Upload Files

```bash
# Upload tutti i file
rsync -avz --exclude 'node_modules' ./ user@server:/var/www/html/

# O solo i file necessari
scp -r assets/ auth/register.php landing.php migrations/ pages/user-approvals.php user@server:/var/www/html/
```

### 2. NPM Setup on Server

```bash
ssh user@server
cd /var/www/html
npm install
npm run copy:assets
```

### 3. Run Migration

```bash
php migrations/add_user_approval_system.php
```

### 4. Verify

```bash
# Check database
mysql -u user -p database_name -e "SHOW COLUMNS FROM users LIKE 'status'"
mysql -u user -p database_name -e "SELECT * FROM user_approval_log LIMIT 1"

# Check files
ls -lh assets/css/bootstrap.min.css
ls -lh assets/js/bootstrap.bundle.min.js
```

### 5. Test

1. Visit `https://your-domain.com/landing.php`
2. Click "Registrati"
3. Complete form
4. Login as admin
5. Check "Approvazioni"
6. Approve test user
7. Login with test user

## 📈 Monitoring

### Pending Users Query

```sql
SELECT
    CONCAT(nome, ' ', cognome) as name,
    email,
    nome_associazione,
    DATEDIFF(NOW(), created_at) as days_waiting,
    ip_address
FROM users u
LEFT JOIN pending_registrations pr ON u.id = pr.user_id
WHERE status = 'pending'
ORDER BY created_at ASC;
```

### Approval Stats

```sql
SELECT
    DATE(approved_at) as date,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
FROM users
WHERE approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY DATE(approved_at)
ORDER BY date DESC;
```

### Slow Approvals (>7 days)

```sql
SELECT
    CONCAT(nome, ' ', cognome) as name,
    email,
    DATEDIFF(NOW(), created_at) as days_waiting
FROM users
WHERE status = 'pending'
  AND DATEDIFF(NOW(), created_at) > 7
ORDER BY created_at ASC;
```

## 🎨 Customization

### Colors

Edit `assets/css/landing.css`:

```css
:root {
    --color-black: #000000;    /* Change to your brand color */
    --color-silver: #C0C0C0;   /* Change to your secondary */
    --color-white: #FFFFFF;    /* Change to your background */
}
```

### Features

Edit `landing.php` section "Features":

```php
<div class="feature-card">
    <div class="feature-icon">
        <i class="bi bi-YOUR-ICON"></i>
    </div>
    <h3 class="feature-title">Your Feature</h3>
    <p class="feature-description">Your description</p>
</div>
```

### Registration Fields

Edit `auth/register.php` to add/remove fields:

```php
// Add new field
<div class="mb-3">
    <label for="city" class="form-label">Città</label>
    <input type="text" class="form-control" id="city" name="city">
</div>
```

Then update database schema to store new fields.

## 📝 Best Practices

### Admin Workflow

1. ✅ Review daily pending registrations
2. ✅ Check IP for suspicious activity
3. ✅ Verify association exists
4. ✅ Provide clear rejection reasons
5. ✅ Respond within 2-3 days

### User Experience

1. ✅ Set expectations: "Approval in 24-48h"
2. ✅ Send confirmation email on registration
3. ✅ Send approval/rejection emails
4. ✅ Provide contact info for questions
5. ✅ Keep rejection reasons professional

### Security

1. ✅ Monitor `security_log` for anomalies
2. ✅ Review rejection patterns
3. ✅ Check for mass registration attempts
4. ✅ Implement rate limiting on registration
5. ✅ Add CAPTCHA if needed

## 🐛 Troubleshooting

### Assets non caricano

```bash
# Verifica file esistono
ls -lh assets/css/bootstrap.min.css
ls -lh assets/js/bootstrap.bundle.min.js

# Verifica permessi
chmod -R 755 assets/

# Rigenera assets
npm run copy:assets
```

### Migration fallisce

```bash
# Check se colonne esistono già
mysql -u user -p -e "SHOW COLUMNS FROM users LIKE 'status'"

# Drop e ricrea (ATTENZIONE: perde dati!)
mysql -u user -p -e "ALTER TABLE users DROP COLUMN status"
php migrations/add_user_approval_system.php
```

### Badge non appare in sidebar

```php
// Check if query runs
$pending = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
var_dump($pending); // Should show number
```

### Email non inviate

```php
// TODO: Implement email sending
// Check SMTP configuration
// Test with mail() or PHPMailer
```

## 📄 License

GPL-2.0 - Same as main project

## 👨‍💻 Support

For issues:
1. Check `logs/security.log`
2. Check `user_approval_log` table
3. Review PHP error logs
4. Open GitHub issue

---

**Created by:** Gestione Associazioni Team
**Version:** 2.0.0
**Date:** November 2024
