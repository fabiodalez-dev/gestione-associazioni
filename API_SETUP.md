# Setup API - Guida all'Installazione

Questa guida spiega come installare e configurare le API protette da API key per il sistema di gestione associazioni.

## Prerequisiti

- PHP 7.4 o superiore
- MySQL 5.7 o superiore
- Apache con mod_rewrite (opzionale, per URL puliti)
- Accesso alla console MySQL o phpMyAdmin

## Installazione

### 1. Eseguire la Migration del Database

La migration crea la tabella `api_keys` necessaria per l'autenticazione.

**Opzione A: Via MySQL CLI**
```bash
mysql -u your_username -p your_database_name < migrations/001_add_api_keys.sql
```

**Opzione B: Via phpMyAdmin**
1. Accedi a phpMyAdmin
2. Seleziona il database
3. Vai alla scheda "SQL"
4. Copia e incolla il contenuto di `migrations/001_add_api_keys.sql`
5. Clicca "Esegui"

**Opzione C: Via PHP Script**
```php
<?php
require_once 'config.php';

$sql = file_get_contents(__DIR__ . '/migrations/001_add_api_keys.sql');

try {
    $pdo->exec($sql);
    echo "Migration eseguita con successo!\n";
} catch (PDOException $e) {
    echo "Errore: " . $e->getMessage() . "\n";
}
?>
```

### 2. Verificare la Creazione della Tabella

Verifica che la tabella `api_keys` sia stata creata correttamente:

```sql
DESCRIBE api_keys;
```

Dovresti vedere i seguenti campi:
- id
- associazione_id
- nome
- api_key
- descrizione
- attiva
- scadenza
- ultimo_utilizzo
- ip_whitelist
- permessi
- created_at
- updated_at

### 3. Generare la Prima API Key

Usa lo script di generazione per creare una API key:

**Via CLI:**
```bash
php api/generate_api_key.php \
  --associazione_id="550e8400-e29b-41d4-a716-446655440000" \
  --nome="Production API" \
  --descrizione="API key per integrazione produzione" \
  --scadenza="2025-12-31"
```

**Via Web (crea uno script temporaneo):**
```php
<?php
require_once 'api/generate_api_key.php';

$result = generateApiKey(
    $pdo,
    '550e8400-e29b-41d4-a716-446655440000', // ID associazione
    'Production API',                        // Nome
    'API key per integrazione produzione',   // Descrizione
    ['soci' => true, 'tessere' => true, 'sedi' => true], // Permessi
    '2025-12-31',                            // Scadenza
    null                                     // IP whitelist (null = tutti gli IP)
);

if ($result['success']) {
    echo "API Key: " . $result['api_key'] . "\n";
    echo "ID: " . $result['id'] . "\n";
    // SALVA QUESTA API KEY IN UN LUOGO SICURO!
} else {
    echo "Errore: " . $result['message'] . "\n";
}
?>
```

**IMPORTANTE**: Salva la API key generata in un luogo sicuro. Non sarà più visibile dopo la generazione!

### 4. Trovare l'ID della Tua Associazione

Se non conosci l'ID della tua associazione:

```sql
SELECT id, nome, email FROM associazioni;
```

### 5. Testare l'API

Testa l'API con curl:

```bash
# Test endpoint soci
curl -X GET "http://your-domain.com/api/v1/soci.php?action=search&q=test" \
  -H "Authorization: Bearer YOUR_API_KEY_HERE"

# Test endpoint tessere
curl -X GET "http://your-domain.com/api/v1/tessere.php?action=search&attive=1" \
  -H "Authorization: Bearer YOUR_API_KEY_HERE"

# Test endpoint sedi
curl -X GET "http://your-domain.com/api/v1/sedi.php?action=list" \
  -H "Authorization: Bearer YOUR_API_KEY_HERE"
```

## Configurazione Avanzata

### Configurare i Permessi

Puoi limitare l'accesso di una API key a specifiche risorse:

```php
$permessi = [
    'soci' => true,    // Accesso agli endpoint dei soci
    'tessere' => false, // Nessun accesso alle tessere
    'sedi' => true      // Accesso agli endpoint delle sedi
];
```

Aggiorna i permessi di una API key esistente:

```sql
UPDATE api_keys
SET permessi = '{"soci": true, "tessere": false, "sedi": true}'
WHERE id = 'your-api-key-id';
```

### Configurare IP Whitelist

Limita l'accesso a specifici indirizzi IP:

```sql
UPDATE api_keys
SET ip_whitelist = '["192.168.1.100", "10.0.0.50"]'
WHERE id = 'your-api-key-id';
```

### Impostare una Data di Scadenza

Imposta o aggiorna la data di scadenza:

```sql
UPDATE api_keys
SET scadenza = '2025-12-31'
WHERE id = 'your-api-key-id';
```

### Disabilitare/Riabilitare una API Key

```sql
-- Disabilita
UPDATE api_keys SET attiva = 0 WHERE id = 'your-api-key-id';

-- Riabilita
UPDATE api_keys SET attiva = 1 WHERE id = 'your-api-key-id';
```

## Gestione API Keys

### Visualizzare Tutte le API Keys

```sql
SELECT
    id,
    nome,
    LEFT(api_key, 10) as api_key_preview,
    attiva,
    scadenza,
    ultimo_utilizzo,
    created_at
FROM api_keys
WHERE associazione_id = 'your-associazione-id'
ORDER BY created_at DESC;
```

### Monitorare l'Utilizzo

```sql
SELECT
    nome,
    ultimo_utilizzo,
    DATEDIFF(NOW(), ultimo_utilizzo) as giorni_inattivita
FROM api_keys
WHERE associazione_id = 'your-associazione-id'
ORDER BY ultimo_utilizzo DESC;
```

### Eliminare una API Key

```sql
DELETE FROM api_keys WHERE id = 'your-api-key-id';
```

## Sicurezza

### Best Practices

1. **HTTPS obbligatorio in produzione**
   - Mai usare API keys su connessioni HTTP non crittografate
   - Configura un certificato SSL/TLS

2. **Rotazione delle chiavi**
   - Rigenera le API keys periodicamente (es. ogni 6-12 mesi)
   - Disabilita le vecchie keys dopo la migrazione

3. **Principio del minimo privilegio**
   - Concedi solo i permessi necessari
   - Usa IP whitelist quando possibile

4. **Monitoraggio**
   - Controlla regolarmente `ultimo_utilizzo`
   - Disabilita keys inattive

5. **Backup**
   - Mantieni un backup sicuro delle API keys attive
   - Documenta quale applicazione usa quale key

6. **Logging**
   - Monitora i log di Apache/PHP per richieste anomale
   - Implementa rate limiting se necessario

### Configurazione HTTPS (Raccomandato)

Modifica `api/auth.php` per forzare HTTPS in produzione:

```php
// Aggiungi all'inizio di api/auth.php
if ($_SERVER['APP_ENV'] === 'production' &&
    (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
    http_response_code(403);
    die(json_encode(['error' => 'HTTPS richiesto']));
}
```

## Troubleshooting

### Errore: "API key non fornita"

Verifica che l'header Authorization sia impostato correttamente:
```bash
curl -v -H "Authorization: Bearer YOUR_KEY" ...
```

### Errore: "API key non valida"

1. Verifica che la key nel database corrisponda
2. Controlla che `attiva = 1`
3. Verifica che la key non sia scaduta

### Errore: "Permesso negato"

Verifica i permessi della API key:
```sql
SELECT permessi FROM api_keys WHERE api_key = 'your-key';
```

### Errore 500 - Internal Server Error

1. Controlla i log PHP: `tail -f /var/log/apache2/error.log`
2. Verifica la connessione al database
3. Assicurati che tutte le tabelle esistano

### CORS Errors (quando si chiama da browser)

Se necessario, modifica le intestazioni CORS in `api/v1/*.php`:

```php
header('Access-Control-Allow-Origin: https://your-frontend-domain.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
```

## Integrazione con il Pannello Admin

Per integrare la gestione delle API keys nel pannello admin, crea una nuova pagina:

```php
// pages/api-keys.php
<?php
require_once __DIR__ . '/../api/generate_api_key.php';

// Verifica autenticazione admin
if (!isUserLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$associazione_id = $_SESSION['associazione_id'];

// Lista API keys
$api_keys = listApiKeys($pdo, $associazione_id);

// Form per creare nuova key
// Form per revocare/eliminare keys
// Tabella con lista keys esistenti
?>
```

## Documentazione API

La documentazione completa delle API è disponibile in:
- `api/v1/README.md` - Documentazione dettagliata di tutti gli endpoint

## Supporto

Per problemi o domande:
1. Controlla i log del server
2. Verifica la configurazione del database
3. Consulta la documentazione API
4. Contatta l'amministratore del sistema
