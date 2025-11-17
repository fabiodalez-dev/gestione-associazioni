# 🔌 HOOKS & FILTERS REFERENCE
## Sistema di Plugin per Gestione Associazioni

**Versione:** 1.0.0
**Ultima Modifica:** 2025-11-17
**Total Hooks:** 68 (40 Actions + 28 Filters)

---

## 📖 INDICE

1. [Introduzione](#introduzione)
2. [Come Usare gli Hooks](#come-usare-gli-hooks)
3. [Application Lifecycle](#application-lifecycle)
4. [Authentication & Authorization](#authentication--authorization)
5. [User Management](#user-management)
6. [Soci (Members) Management](#soci-members-management)
7. [Tessere (Cards) Management](#tessere-cards-management)
8. [Quote (Fees) Management](#quote-fees-management)
9. [Events Management](#events-management)
10. [Documents Management](#documents-management)
11. [Communications](#communications)
12. [Database Operations](#database-operations)
13. [UI & Rendering](#ui--rendering)
14. [Security & Logging](#security--logging)
15. [Custom Fields & Settings](#custom-fields--settings)
16. [Email & Notifications](#email--notifications)
17. [Export & Import](#export--import)
18. [Dashboard & Analytics](#dashboard--analytics)

---

## 🎯 INTRODUZIONE

Questo documento elenca tutti gli hooks (actions e filters) disponibili nel sistema.

### Differenza tra Actions e Filters

- **Actions**: Eseguono codice in un punto specifico (non restituiscono valori)
- **Filters**: Modificano un valore e lo restituiscono

### Esempio Base

```php
// Registra un'azione
HookManager::addAction('user_login', function($user_id) {
    error_log("User $user_id logged in");
}, 10);

// Registra un filtro
HookManager::addFilter('socio_display_name', function($name, $socio) {
    return strtoupper($name); // Mostra nome in maiuscolo
}, 10);
```

---

## 🔄 APPLICATION LIFECYCLE

### Actions

#### `app_loaded`
Eseguito quando l'applicazione è completamente caricata.
```php
HookManager::addAction('app_loaded', function() {
    // App initialization code
});
```

#### `before_page_render`
Prima del rendering di una pagina.
- **Parametri**: `$page_name` (string)
```php
HookManager::addAction('before_page_render', function($page_name) {
    // Execute before rendering page
});
```

#### `after_page_render`
Dopo il rendering di una pagina.
- **Parametri**: `$page_name` (string)
```php
HookManager::addAction('after_page_render', function($page_name) {
    // Execute after page rendered
});
```

#### `before_shutdown`
Prima dello shutdown dell'applicazione.
```php
HookManager::addAction('before_shutdown', function() {
    // Cleanup code
});
```

---

## 🔐 AUTHENTICATION & AUTHORIZATION

### Actions

#### `before_login`
Prima del tentativo di login.
- **Parametri**: `$email` (string), `$login_type` (string: 'admin' | 'socio')
```php
HookManager::addAction('before_login', function($email, $type) {
    // Log login attempt
});
```

#### `user_login_success`
Dopo login riuscito.
- **Parametri**: `$user_id` (string), `$user_data` (array), `$login_type` (string)
```php
HookManager::addAction('user_login_success', function($user_id, $data, $type) {
    // Send welcome notification
});
```

#### `user_login_failed`
Dopo login fallito.
- **Parametri**: `$email` (string), `$reason` (string), `$login_type` (string)
```php
HookManager::addAction('user_login_failed', function($email, $reason, $type) {
    // Log failed attempt
});
```

#### `user_logout`
Quando un utente fa logout.
- **Parametri**: `$user_id` (string), `$user_type` (string)
```php
HookManager::addAction('user_logout', function($user_id, $type) {
    // Clear user cache
});
```

#### `password_reset_requested`
Quando viene richiesto reset password.
- **Parametri**: `$email` (string), `$token` (string)
```php
HookManager::addAction('password_reset_requested', function($email, $token) {
    // Send custom email
});
```

#### `password_changed`
Dopo cambio password.
- **Parametri**: `$user_id` (string), `$user_type` (string)
```php
HookManager::addAction('password_changed', function($user_id, $type) {
    // Notify user
});
```

### Filters

#### `login_redirect_url`
Modifica URL di redirect dopo login.
- **Parametri**: `$url` (string), `$user_data` (array)
- **Return**: string (URL)
```php
HookManager::addFilter('login_redirect_url', function($url, $user_data) {
    if ($user_data['role'] === 'super_admin') {
        return '/admin/dashboard';
    }
    return $url;
});
```

#### `can_access_page`
Verifica se utente può accedere a una pagina.
- **Parametri**: `$allowed` (bool), `$page_name` (string), `$user_data` (array)
- **Return**: bool
```php
HookManager::addFilter('can_access_page', function($allowed, $page, $user) {
    // Custom access control logic
    return $allowed;
});
```

---

## 👥 USER MANAGEMENT

### Actions

#### `user_created`
Dopo creazione nuovo utente admin.
- **Parametri**: `$user_id` (string), `$user_data` (array)
```php
HookManager::addAction('user_created', function($user_id, $data) {
    // Send welcome email
});
```

#### `user_updated`
Dopo aggiornamento dati utente.
- **Parametri**: `$user_id` (string), `$old_data` (array), `$new_data` (array)
```php
HookManager::addAction('user_updated', function($user_id, $old, $new) {
    // Log changes
});
```

#### `user_deleted`
Dopo eliminazione utente.
- **Parametri**: `$user_id` (string), `$user_data` (array)
```php
HookManager::addAction('user_deleted', function($user_id, $data) {
    // Cleanup user data
});
```

#### `user_role_changed`
Quando cambia ruolo utente.
- **Parametri**: `$user_id` (string), `$old_role` (string), `$new_role` (string)
```php
HookManager::addAction('user_role_changed', function($user_id, $old_role, $new_role) {
    // Notify admins
});
```

### Filters

#### `user_display_name`
Modifica nome visualizzato utente.
- **Parametri**: `$name` (string), `$user_data` (array)
- **Return**: string
```php
HookManager::addFilter('user_display_name', function($name, $user) {
    return $user['nome'] . ' ' . $user['cognome'] . ' (' . $user['role'] . ')';
});
```

#### `user_capabilities`
Modifica capabilities utente.
- **Parametri**: `$capabilities` (array), `$user_id` (string)
- **Return**: array
```php
HookManager::addFilter('user_capabilities', function($caps, $user_id) {
    $caps[] = 'export_data';
    return $caps;
});
```

---

## 👤 SOCI (MEMBERS) MANAGEMENT

### Actions

#### `socio_created`
Dopo creazione nuovo socio.
- **Parametri**: `$socio_id` (string), `$socio_data` (array)
```php
HookManager::addAction('socio_created', function($socio_id, $data) {
    // Generate membership card
    // Send welcome email
});
```

#### `socio_updated`
Dopo aggiornamento dati socio.
- **Parametri**: `$socio_id` (string), `$old_data` (array), `$new_data` (array)
```php
HookManager::addAction('socio_updated', function($socio_id, $old, $new) {
    // Check if status changed
    if ($old['stato'] !== $new['stato']) {
        // Send notification
    }
});
```

#### `socio_deleted`
Dopo eliminazione socio.
- **Parametri**: `$socio_id` (string), `$socio_data` (array)
```php
HookManager::addAction('socio_deleted', function($socio_id, $data) {
    // Archive member data
    // Cancel memberships
});
```

#### `socio_status_changed`
Quando cambia stato socio.
- **Parametri**: `$socio_id` (string), `$old_status` (string), `$new_status` (string)
```php
HookManager::addAction('socio_status_changed', function($socio_id, $old, $new) {
    if ($new === 'Sospeso') {
        // Suspend card, block access
    }
});
```

#### `socio_imported`
Dopo importazione socio da CSV.
- **Parametri**: `$socio_id` (string), `$import_data` (array)
```php
HookManager::addAction('socio_imported', function($socio_id, $data) {
    // Validate imported data
});
```

### Filters

#### `socio_display_name`
Modifica nome visualizzato socio.
- **Parametri**: `$name` (string), `$socio_data` (array)
- **Return**: string
```php
HookManager::addFilter('socio_display_name', function($name, $socio) {
    return $socio['cognome'] . ' ' . $socio['nome'];
});
```

#### `socio_search_results`
Filtra risultati ricerca soci.
- **Parametri**: `$results` (array), `$search_params` (array)
- **Return**: array
```php
HookManager::addFilter('socio_search_results', function($results, $params) {
    // Custom filtering logic
    return $results;
});
```

#### `socio_list_columns`
Modifica colonne visualizzate in lista soci.
- **Parametri**: `$columns` (array)
- **Return**: array
```php
HookManager::addFilter('socio_list_columns', function($columns) {
    $columns[] = 'custom_field';
    return $columns;
});
```

#### `socio_allowed_statuses`
Modifica stati disponibili per soci.
- **Parametri**: `$statuses` (array)
- **Return**: array
```php
HookManager::addFilter('socio_allowed_statuses', function($statuses) {
    $statuses[] = 'In Attesa';
    return $statuses;
});
```

---

## 🎫 TESSERE (CARDS) MANAGEMENT

### Actions

#### `tessera_created`
Dopo creazione tessera.
- **Parametri**: `$tessera_id` (string), `$tessera_data` (array)
```php
HookManager::addAction('tessera_created', function($tessera_id, $data) {
    // Send card to printer
    // Notify member
});
```

#### `tessera_updated`
Dopo aggiornamento tessera.
- **Parametri**: `$tessera_id` (string), `$old_data` (array), `$new_data` (array)
```php
HookManager::addAction('tessera_updated', function($id, $old, $new) {
    // Log changes
});
```

#### `tessera_renewed`
Quando una tessera viene rinnovata.
- **Parametri**: `$old_tessera_id` (string), `$new_tessera_id` (string), `$socio_id` (string)
```php
HookManager::addAction('tessera_renewed', function($old_id, $new_id, $socio_id) {
    // Transfer benefits
});
```

#### `tessera_expired`
Quando una tessera scade.
- **Parametri**: `$tessera_id` (string), `$socio_id` (string)
```php
HookManager::addAction('tessera_expired', function($tessera_id, $socio_id) {
    // Send renewal reminder
});
```

#### `tessera_pdf_generated`
Dopo generazione PDF tessera.
- **Parametri**: `$tessera_id` (string), `$pdf_path` (string)
```php
HookManager::addAction('tessera_pdf_generated', function($id, $path) {
    // Email PDF to member
});
```

### Filters

#### `tessera_number_format`
Modifica formato numero tessera.
- **Parametri**: `$number` (string), `$year` (int), `$progressive` (int)
- **Return**: string
```php
HookManager::addFilter('tessera_number_format', function($number, $year, $prog) {
    return "CARD-$year-" . str_pad($prog, 6, '0', STR_PAD_LEFT);
});
```

#### `tessera_validity_period`
Modifica periodo validità tessera.
- **Parametri**: `$months` (int), `$tessera_type` (string)
- **Return**: int (months)
```php
HookManager::addFilter('tessera_validity_period', function($months, $type) {
    if ($type === 'VIP') return 24; // 2 years for VIP
    return $months;
});
```

#### `tessera_pdf_html`
Modifica HTML template PDF tessera.
- **Parametri**: `$html` (string), `$tessera_data` (array)
- **Return**: string (HTML)
```php
HookManager::addFilter('tessera_pdf_html', function($html, $data) {
    // Add watermark
    return $html;
});
```

---

## 💰 QUOTE (FEES) MANAGEMENT

### Actions

#### `quota_created`
Dopo creazione quota.
- **Parametri**: `$quota_id` (string), `$quota_data` (array)
```php
HookManager::addAction('quota_created', function($id, $data) {
    // Send payment request
});
```

#### `quota_paid`
Quando una quota viene pagata.
- **Parametri**: `$quota_id` (string), `$payment_data` (array)
```php
HookManager::addAction('quota_paid', function($id, $payment) {
    // Issue receipt
    // Update accounting
});
```

#### `quota_overdue`
Quando una quota è scaduta.
- **Parametri**: `$quota_id` (string), `$socio_id` (string), `$days_overdue` (int)
```php
HookManager::addAction('quota_overdue', function($id, $socio_id, $days) {
    // Send reminder
});
```

### Filters

#### `quota_amount`
Modifica importo quota.
- **Parametri**: `$amount` (float), `$socio_data` (array), `$year` (int)
- **Return**: float
```php
HookManager::addFilter('quota_amount', function($amount, $socio, $year) {
    // Discount for seniors
    if (age($socio['data_nascita']) > 65) {
        return $amount * 0.8;
    }
    return $amount;
});
```

#### `quota_payment_methods`
Modifica metodi di pagamento disponibili.
- **Parametri**: `$methods` (array)
- **Return**: array
```php
HookManager::addFilter('quota_payment_methods', function($methods) {
    $methods[] = 'PayPal';
    return $methods;
});
```

---

## 📅 EVENTS MANAGEMENT

### Actions

#### `event_created`
Dopo creazione evento.
- **Parametri**: `$event_id` (string), `$event_data` (array)
```php
HookManager::addAction('event_created', function($id, $data) {
    // Notify members
});
```

#### `participant_registered`
Quando un socio si registra a un evento.
- **Parametri**: `$event_id` (string), `$socio_id` (string), `$registration_data` (array)
```php
HookManager::addAction('participant_registered', function($event_id, $socio_id, $data) {
    // Send confirmation email
});
```

#### `event_cancelled`
Quando un evento viene cancellato.
- **Parametri**: `$event_id` (string), `$reason` (string)
```php
HookManager::addAction('event_cancelled', function($id, $reason) {
    // Notify all participants
});
```

### Filters

#### `event_capacity`
Modifica capacità massima evento.
- **Parametri**: `$capacity` (int), `$event_data` (array)
- **Return**: int
```php
HookManager::addFilter('event_capacity', function($capacity, $event) {
    // Dynamic capacity based on venue
    return $capacity;
});
```

---

## 📄 DOCUMENTS MANAGEMENT

### Actions

#### `document_uploaded`
Dopo upload documento.
- **Parametri**: `$document_id` (string), `$file_data` (array), `$uploader_id` (string)
```php
HookManager::addAction('document_uploaded', function($id, $file, $uploader) {
    // Scan for viruses
    // Extract metadata
});
```

#### `document_downloaded`
Quando un documento viene scaricato.
- **Parametri**: `$document_id` (string), `$user_id` (string)
```php
HookManager::addAction('document_downloaded', function($doc_id, $user_id) {
    // Log download
});
```

### Filters

#### `allowed_file_types`
Modifica tipi di file permessi per upload.
- **Parametri**: `$types` (array)
- **Return**: array
```php
HookManager::addFilter('allowed_file_types', function($types) {
    $types[] = 'zip';
    return $types;
});
```

#### `max_upload_size`
Modifica dimensione massima upload.
- **Parametri**: `$size_mb` (int), `$user_role` (string)
- **Return**: int
```php
HookManager::addFilter('max_upload_size', function($size, $role) {
    if ($role === 'super_admin') return 100; // 100MB for admins
    return $size;
});
```

---

## 💬 COMMUNICATIONS

### Actions

#### `email_sent`
Dopo invio email.
- **Parametri**: `$to` (string), `$subject` (string), `$success` (bool)
```php
HookManager::addAction('email_sent', function($to, $subject, $success) {
    // Log email delivery
});
```

#### `bulk_email_started`
Prima di invio email massivo.
- **Parametri**: `$recipients_count` (int), `$template` (string)
```php
HookManager::addAction('bulk_email_started', function($count, $template) {
    // Prepare queue
});
```

### Filters

#### `email_content`
Modifica contenuto email prima dell'invio.
- **Parametri**: `$content` (string), `$email_type` (string), `$recipient_data` (array)
- **Return**: string
```php
HookManager::addFilter('email_content', function($content, $type, $recipient) {
    // Personalize content
    return str_replace('{nome}', $recipient['nome'], $content);
});
```

#### `email_subject`
Modifica subject email.
- **Parametri**: `$subject` (string), `$email_type` (string)
- **Return**: string
```php
HookManager::addFilter('email_subject', function($subject, $type) {
    return "[Associazione] $subject";
});
```

---

## 🗄️ DATABASE OPERATIONS

### Actions

#### `before_insert`
Prima di inserimento in database.
- **Parametri**: `$table` (string), `$data` (array)
```php
HookManager::addAction('before_insert', function($table, $data) {
    // Validate data
});
```

#### `after_insert`
Dopo inserimento in database.
- **Parametri**: `$table` (string), `$id` (string), `$data` (array)
```php
HookManager::addAction('after_insert', function($table, $id, $data) {
    // Clear cache
});
```

#### `before_update`
Prima di update in database.
- **Parametri**: `$table` (string), `$id` (string), `$data` (array)
```php
HookManager::addAction('before_update', function($table, $id, $data) {
    // Validate changes
});
```

#### `after_update`
Dopo update in database.
- **Parametri**: `$table` (string), `$id` (string), `$old_data` (array), `$new_data` (array)
```php
HookManager::addAction('after_update', function($table, $id, $old, $new) {
    // Sync with external systems
});
```

#### `before_delete`
Prima di delete da database.
- **Parametri**: `$table` (string), `$id` (string)
```php
HookManager::addAction('before_delete', function($table, $id) {
    // Check dependencies
});
```

#### `after_delete`
Dopo delete da database.
- **Parametri**: `$table` (string), `$id` (string), `$deleted_data` (array)
```php
HookManager::addAction('after_delete', function($table, $id, $data) {
    // Archive deleted record
});
```

---

## 🎨 UI & RENDERING

### Actions

#### `admin_head`
Nell'head delle pagine admin (per aggiungere CSS/JS).
```php
HookManager::addAction('admin_head', function() {
    echo '<link rel="stylesheet" href="/custom.css">';
});
```

#### `admin_footer`
Nel footer delle pagine admin.
```php
HookManager::addAction('admin_footer', function() {
    echo '<script src="/custom.js"></script>';
});
```

#### `dashboard_widgets`
Aggiunge widget alla dashboard.
- **Parametri**: Nessuno (echo diretto)
```php
HookManager::addAction('dashboard_widgets', function() {
    echo '<div class="widget">Custom Widget</div>';
});
```

### Filters

#### `page_title`
Modifica titolo pagina.
- **Parametri**: `$title` (string), `$page_name` (string)
- **Return**: string
```php
HookManager::addFilter('page_title', function($title, $page) {
    return "$title | Associazione";
});
```

#### `sidebar_menu_items`
Modifica voci menu sidebar.
- **Parametri**: `$items` (array)
- **Return**: array
```php
HookManager::addFilter('sidebar_menu_items', function($items) {
    $items[] = ['name' => 'Custom', 'url' => '/custom', 'icon' => 'bi-gear'];
    return $items;
});
```

#### `table_row_actions`
Aggiungi azioni a righe tabella.
- **Parametri**: `$actions` (array), `$row_data` (array), `$table_type` (string)
- **Return**: array
```php
HookManager::addFilter('table_row_actions', function($actions, $row, $type) {
    if ($type === 'soci') {
        $actions[] = '<a href="/export/' . $row['id'] . '">Export</a>';
    }
    return $actions;
});
```

---

## 🔒 SECURITY & LOGGING

### Actions

#### `security_event`
Evento di sicurezza rilevato.
- **Parametri**: `$event_type` (string), `$severity` (string), `$details` (array)
```php
HookManager::addAction('security_event', function($type, $severity, $details) {
    if ($severity === 'critical') {
        // Alert admins immediately
    }
});
```

#### `rate_limit_exceeded`
Rate limit superato.
- **Parametri**: `$identifier` (string), `$limit_type` (string), `$attempts` (int)
```php
HookManager::addAction('rate_limit_exceeded', function($id, $type, $attempts) {
    // Block IP temporarily
});
```

### Filters

#### `security_rules`
Modifica regole di sicurezza.
- **Parametri**: `$rules` (array)
- **Return**: array
```php
HookManager::addFilter('security_rules', function($rules) {
    $rules['min_password_length'] = 16;
    return $rules;
});
```

---

## ⚙️ CUSTOM FIELDS & SETTINGS

### Actions

#### `custom_field_added`
Dopo aggiunta campo personalizzato.
- **Parametri**: `$field_id` (string), `$field_data` (array)
```php
HookManager::addAction('custom_field_added', function($id, $data) {
    // Update form templates
});
```

#### `settings_updated`
Dopo aggiornamento impostazioni.
- **Parametri**: `$setting_key` (string), `$old_value` (mixed), `$new_value` (mixed)
```php
HookManager::addAction('settings_updated', function($key, $old, $new) {
    // Clear cache if needed
});
```

### Filters

#### `custom_field_types`
Modifica tipi di campo personalizzato disponibili.
- **Parametri**: `$types` (array)
- **Return**: array
```php
HookManager::addFilter('custom_field_types', function($types) {
    $types['color_picker'] = 'Color Picker';
    return $types;
});
```

---

## 📧 EMAIL & NOTIFICATIONS

### Filters

#### `notification_channels`
Modifica canali di notifica disponibili.
- **Parametri**: `$channels` (array)
- **Return**: array
```php
HookManager::addFilter('notification_channels', function($channels) {
    $channels[] = 'sms';
    $channels[] = 'push';
    return $channels;
});
```

---

## 📊 EXPORT & IMPORT

### Actions

#### `before_export`
Prima di esportazione dati.
- **Parametri**: `$export_type` (string), `$filters` (array)
```php
HookManager::addAction('before_export', function($type, $filters) {
    // Prepare data
});
```

#### `after_export`
Dopo esportazione dati.
- **Parametri**: `$export_type` (string), `$file_path` (string), `$rows_count` (int)
```php
HookManager::addAction('after_export', function($type, $path, $count) {
    // Email file to admin
});
```

### Filters

#### `export_columns`
Modifica colonne esportate.
- **Parametri**: `$columns` (array), `$export_type` (string)
- **Return**: array
```php
HookManager::addFilter('export_columns', function($columns, $type) {
    if ($type === 'soci') {
        $columns[] = 'custom_field';
    }
    return $columns;
});
```

---

## 📈 DASHBOARD & ANALYTICS

### Filters

#### `dashboard_stats`
Modifica statistiche dashboard.
- **Parametri**: `$stats` (array)
- **Return**: array
```php
HookManager::addFilter('dashboard_stats', function($stats) {
    $stats['custom_metric'] = calculateCustomMetric();
    return $stats;
});
```

#### `chart_data`
Modifica dati grafici.
- **Parametri**: `$data` (array), `$chart_type` (string)
- **Return**: array
```php
HookManager::addFilter('chart_data', function($data, $type) {
    // Transform data
    return $data;
});
```

---

## 🔌 PLUGIN LIFECYCLE

### Actions

#### `plugin_loaded`
Quando un plugin viene caricato.
- **Parametri**: `$plugin_slug` (string), `$plugin_instance` (object)
```php
HookManager::addAction('plugin_loaded', function($slug, $instance) {
    error_log("Plugin $slug loaded");
});
```

#### `plugin_activated`
Quando un plugin viene attivato.
- **Parametri**: `$plugin_slug` (string)
```php
HookManager::addAction('plugin_activated', function($slug) {
    // Plugin activation tasks
});
```

#### `plugin_deactivated`
Quando un plugin viene disattivato.
- **Parametri**: `$plugin_slug` (string)
```php
HookManager::addAction('plugin_deactivated', function($slug) {
    // Cleanup tasks
});
```

---

## 📝 BEST PRACTICES

### 1. Priorità degli Hooks
- Usa priorità bassa (< 10) per esecuzione anticipata
- Usa priorità alta (> 10) per esecuzione posticipata
- Default è 10

### 2. Performance
- Evita operazioni pesanti negli hooks chiamati frequentemente
- Usa caching quando possibile
- Log solo in debug mode

### 3. Error Handling
```php
HookManager::addAction('my_hook', function() {
    try {
        // Your code
    } catch (Exception $e) {
        error_log("Hook error: " . $e->getMessage());
    }
});
```

### 4. Rimozione Hooks
```php
// Rimuovi hook specifico
HookManager::removeAction('hook_name', $callback);

// Rimuovi tutti gli hooks per un nome
HookManager::removeAction('hook_name');
```

---

## 🧪 TESTING HOOKS

### Debug Mode
```php
HookManager::setDebugMode(true);

// Esegui operazioni...

// Ottieni log
$log = HookManager::getExecutionLog();
print_r($log);
```

### Statistiche
```php
$stats = HookManager::getStats();
// ['total_actions' => 40, 'total_filters' => 28, ...]
```

---

## 📞 SUPPORTO

Per aggiungere nuovi hooks o segnalare problemi:
1. Consulta il codice sorgente in `lib/HookManager.php`
2. Vedi esempi in `plugins/` directory
3. Leggi la documentazione del Plugin Manager

---

**Totale Hooks Documentati:** 68
**Ultimo Aggiornamento:** 2025-11-17

