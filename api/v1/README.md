# API v1 - Gestione Associazioni

API RESTful protetta da API key per la gestione di soci, tessere e sedi.

## Autenticazione

Tutte le richieste API devono includere un'API key valida. L'API key può essere fornita in tre modi (in ordine di preferenza):

### 1. Authorization Header (Raccomandato)
```bash
Authorization: Bearer YOUR_API_KEY_HERE
```

### 2. X-API-Key Header
```bash
X-API-Key: YOUR_API_KEY_HERE
```

### 3. Query Parameter (Solo per test, meno sicuro)
```bash
?api_key=YOUR_API_KEY_HERE
```

## Formato Risposta

Tutte le risposte sono in formato JSON con charset UTF-8.

### Successo
```json
{
  "success": true,
  "data": { ... }
}
```

### Errore
```json
{
  "error": "Messaggio di errore"
}
```

## Rate Limiting

Al momento non ci sono limiti di rate, ma si consiglia di non superare 100 richieste al minuto.

---

## Endpoints

### 1. Soci (Members)

#### GET - Dati Anagrafici Socio
Recupera i dati anagrafici completi di un iscritto, inclusi campi personalizzati e tags.

**Endpoint:** `GET /api/v1/soci.php?id={socio_id}`

**Parametri:**
- `id` (required): ID del socio

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci.php?id=550e8400-e29b-41d4-a716-446655440001" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": {
    "anagrafica": {
      "id": "550e8400-e29b-41d4-a716-446655440001",
      "numero_socio": "001",
      "nome": "Mario",
      "cognome": "Rossi",
      "data_nascita": "1980-01-15",
      "codice_fiscale": "RSSMRA80A15H501U",
      "email": "mario.rossi@example.com",
      "telefono": "+39 123 456789",
      "indirizzo": "Via Roma 1",
      "citta": "Milano",
      "provincia": "MI",
      "cap": "20100",
      "data_iscrizione": "2020-01-01",
      "stato": "Attivo",
      "privacy_consenso": true,
      "tipo_socio": "Ordinario",
      "categoria_socio": "Senior",
      "sede_nome": "Milano Centro"
    },
    "campi_personalizzati": [
      {
        "nome_campo": "Professione",
        "tipo_campo": "text",
        "valore": "Ingegnere"
      }
    ],
    "tags": [
      {
        "nome_tag": "Volontario",
        "colore": "#007bff"
      }
    ]
  }
}
```

---

#### GET - Tessera Attiva Socio
Verifica e recupera la tessera attiva di un socio.

**Endpoint:** `GET /api/v1/soci.php?id={socio_id}&action=tessera`

**Parametri:**
- `id` (required): ID del socio
- `action=tessera` (required)

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci.php?id=550e8400-e29b-41d4-a716-446655440001&action=tessera" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta (tessera trovata):**
```json
{
  "success": true,
  "data": {
    "tessera": {
      "id": "tessera-id-123",
      "numero_tessera": "2024-001",
      "anno_validita": 2024,
      "data_emissione": "2024-01-01",
      "data_scadenza": "2024-12-31",
      "tipo_scadenza": "solare",
      "stato": "Attiva",
      "qr_code_url": "/path/to/qr-code.png",
      "socio_nome": "Mario",
      "socio_cognome": "Rossi",
      "numero_socio": "001"
    },
    "is_expired": false,
    "giorni_scadenza": 245
  }
}
```

**Risposta (nessuna tessera attiva):**
```json
{
  "success": true,
  "data": null,
  "message": "Nessuna tessera attiva trovata"
}
```

---

#### GET - Sede del Socio
Recupera le informazioni sulla sede associata al socio.

**Endpoint:** `GET /api/v1/soci.php?id={socio_id}&action=sede`

**Parametri:**
- `id` (required): ID del socio
- `action=sede` (required)

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci.php?id=550e8400-e29b-41d4-a716-446655440001&action=sede" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": {
    "socio": {
      "nome": "Mario",
      "cognome": "Rossi"
    },
    "sede": {
      "id": "sede-id-123",
      "nome": "Milano Centro",
      "indirizzo": "Piazza Duomo 1",
      "citta": "Milano",
      "provincia": "MI",
      "cap": "20100",
      "email": "milano@example.com",
      "telefono": "+39 02 1234567",
      "responsabile": "Giuseppe Verdi"
    }
  }
}
```

---

### 1.1 Ricerca Specifica Soci (Nuovi Endpoint Diretti)

Per semplificare l'integrazione, sono disponibili endpoint dedicati per cercare soci usando **un singolo campo specifico**. Questi endpoint restituiscono SEMPRE i dati completi dell'iscritto (anagrafica + tessera attiva + campi personalizzati + tags).

#### GET - Cerca per Nome
**Endpoint:** `GET /api/v1/soci-by-nome.php?nome={nome}`

Cerca soci il cui nome contiene il valore specificato (LIKE).

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-nome.php?nome=Mario" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": [
    {
      "anagrafica": { ... },
      "tessera_attiva": { ... },
      "campi_personalizzati": [ ... ],
      "tags": [ ... ]
    }
  ],
  "total": 1,
  "search_field": "nome",
  "search_value": "Mario"
}
```

---

#### GET - Cerca per Cognome
**Endpoint:** `GET /api/v1/soci-by-cognome.php?cognome={cognome}`

Cerca soci il cui cognome contiene il valore specificato (LIKE).

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-cognome.php?cognome=Rossi" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Cerca per Codice Fiscale
**Endpoint:** `GET /api/v1/soci-by-codice-fiscale.php?cf={codice_fiscale}`

Cerca socio per codice fiscale (exact match). Restituisce UN SOLO risultato.

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-codice-fiscale.php?cf=RSSMRA80A15H501U" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": {
    "anagrafica": { ... },
    "tessera_attiva": { ... },
    "campi_personalizzati": [ ... ],
    "tags": [ ... ]
  },
  "search_field": "codice_fiscale",
  "search_value": "RSSMRA80A15H501U"
}
```

---

#### GET - Cerca per Numero Socio
**Endpoint:** `GET /api/v1/soci-by-numero-socio.php?numero={numero_socio}`

Cerca socio per numero socio (exact match). Restituisce UN SOLO risultato.

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-numero-socio.php?numero=001" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Cerca per Email
**Endpoint:** `GET /api/v1/soci-by-email.php?email={email}`

Cerca socio per email (exact match). Restituisce UN SOLO risultato.

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-email.php?email=mario.rossi@example.com" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Cerca per Telefono
**Endpoint:** `GET /api/v1/soci-by-telefono.php?telefono={telefono}`

Cerca soci il cui telefono contiene il valore specificato (LIKE).

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-telefono.php?telefono=123456" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Cerca per Numero Tessera
**Endpoint:** `GET /api/v1/soci-by-numero-tessera.php?numero={numero_tessera}`

Cerca socio tramite numero tessera (exact match). Restituisce UN SOLO risultato. **Ideale per scansione QR code.**

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-numero-tessera.php?numero=2024-001" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Caso d'uso:** Scansione QR code su tessera associativa per verificare validità e dati socio.

---

#### GET - Lista Soci per Sede
**Endpoint:** `GET /api/v1/soci-by-sede.php?sede_id={sede_id}`

Lista tutti i soci di una specifica sede.

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/soci-by-sede.php?sede_id=sede-id-123" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": [
    {
      "anagrafica": { ... },
      "tessera_attiva": { ... },
      "campi_personalizzati": [ ... ],
      "tags": [ ... ]
    }
  ],
  "total": 15,
  "search_field": "sede_id",
  "search_value": "sede-id-123",
  "sede_info": {
    "id": "sede-id-123",
    "nome": "Milano Centro"
  }
}
```

---

#### GET - Ricerca Soci (Multi-campo con Paginazione)
Cerca soci per qualsiasi campo con filtri multipli e paginazione.

**Endpoint:** `GET /api/v1/soci.php?action=search&{filters}`

**Parametri Query:**
- `action=search` (required)
- `nome`: Ricerca per nome (LIKE)
- `cognome`: Ricerca per cognome (LIKE)
- `email`: Ricerca per email (LIKE)
- `codice_fiscale`: Ricerca per codice fiscale (exact match)
- `numero_socio`: Ricerca per numero socio (exact match)
- `telefono`: Ricerca per telefono (LIKE)
- `stato`: Filtra per stato (Attivo, Sospeso, Radiato, Deceduto, Trasferito)
- `citta`: Ricerca per città (LIKE)
- `provincia`: Filtra per provincia (exact match)
- `sede_id`: Filtra per sede
- `tipo_socio_id`: Filtra per tipo socio
- `categoria_socio_id`: Filtra per categoria socio
- `q`: Ricerca generica su più campi (nome, cognome, email, numero_socio, codice_fiscale)
- `page`: Numero pagina (default: 1)
- `limit`: Risultati per pagina (default: 20, max: 100)

**Esempio:**
```bash
# Ricerca per nome
curl -X GET "https://your-domain.com/api/v1/soci.php?action=search&nome=Mario" \
  -H "Authorization: Bearer YOUR_API_KEY"

# Ricerca generica
curl -X GET "https://your-domain.com/api/v1/soci.php?action=search&q=rossi&page=1&limit=10" \
  -H "Authorization: Bearer YOUR_API_KEY"

# Filtri multipli
curl -X GET "https://your-domain.com/api/v1/soci.php?action=search&citta=Milano&stato=Attivo" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": [
    {
      "id": "socio-id-123",
      "numero_socio": "001",
      "nome": "Mario",
      "cognome": "Rossi",
      "data_nascita": "1980-01-15",
      "codice_fiscale": "RSSMRA80A15H501U",
      "email": "mario.rossi@example.com",
      "telefono": "+39 123 456789",
      "indirizzo": "Via Roma 1",
      "citta": "Milano",
      "provincia": "MI",
      "cap": "20100",
      "data_iscrizione": "2020-01-01",
      "stato": "Attivo",
      "tipo_socio": "Ordinario",
      "categoria_socio": "Senior",
      "sede_nome": "Milano Centro",
      "sede_id": "sede-id-123"
    }
  ],
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 1,
    "total_pages": 1
  }
}
```

---

### 2. Tessere (Membership Cards)

#### GET - Dettagli Tessera per ID
**Endpoint:** `GET /api/v1/tessere.php?id={tessera_id}`

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/tessere.php?id=tessera-id-123" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Dettagli Tessera per Numero
**Endpoint:** `GET /api/v1/tessere.php?numero_tessera={numero}`

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/tessere.php?numero_tessera=2024-001" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

#### GET - Ricerca Tessere
**Endpoint:** `GET /api/v1/tessere.php?action=search&{filters}`

**Parametri:**
- `action=search` (required)
- `socio_id`: Filtra per socio
- `numero_tessera`: Ricerca per numero tessera (LIKE)
- `anno_validita`: Filtra per anno
- `stato`: Filtra per stato (Attiva, Scaduta, Sospesa, Annullata)
- `tipo_scadenza`: Filtra per tipo scadenza (solare, annuale)
- `scadute`: Solo tessere scadute (scadute=1)
- `attive`: Solo tessere attive e non scadute (attive=1)
- `socio_nome`: Ricerca per nome socio (LIKE)
- `socio_cognome`: Ricerca per cognome socio (LIKE)
- `q`: Ricerca generica
- `page`, `limit`: Paginazione

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/tessere.php?action=search&anno_validita=2024&attive=1" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

### 3. Sedi (Locations/Branches)

#### GET - Dettagli Sede
**Endpoint:** `GET /api/v1/sedi.php?id={sede_id}`

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/sedi.php?id=sede-id-123" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": {
    "sede": {
      "id": "sede-id-123",
      "nome": "Milano Centro",
      "indirizzo": "Piazza Duomo 1",
      "citta": "Milano",
      "provincia": "MI",
      "cap": "20100",
      "email": "milano@example.com",
      "telefono": "+39 02 1234567",
      "responsabile": "Giuseppe Verdi",
      "created_at": "2020-01-01 00:00:00",
      "updated_at": "2024-01-01 10:00:00"
    },
    "statistiche": {
      "total_soci": 150
    }
  }
}
```

---

#### GET - Lista Tutte le Sedi
**Endpoint:** `GET /api/v1/sedi.php?action=list`

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/sedi.php?action=list" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

**Risposta:**
```json
{
  "success": true,
  "data": [
    {
      "id": "sede-id-123",
      "nome": "Milano Centro",
      "indirizzo": "Piazza Duomo 1",
      "citta": "Milano",
      "provincia": "MI",
      "cap": "20100",
      "email": "milano@example.com",
      "telefono": "+39 02 1234567",
      "responsabile": "Giuseppe Verdi",
      "total_soci": 150
    }
  ],
  "total": 1
}
```

---

#### GET - Ricerca Sedi
**Endpoint:** `GET /api/v1/sedi.php?action=search&{filters}`

**Parametri:**
- `action=search` (required)
- `nome`: Ricerca per nome (LIKE)
- `citta`: Ricerca per città (LIKE)
- `provincia`: Filtra per provincia (exact)
- `responsabile`: Ricerca per responsabile (LIKE)
- `q`: Ricerca generica
- `page`, `limit`: Paginazione

**Esempio:**
```bash
curl -X GET "https://your-domain.com/api/v1/sedi.php?action=search&citta=Milano" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

---

## Codici di Stato HTTP

- `200 OK`: Richiesta riuscita
- `400 Bad Request`: Parametri mancanti o non validi
- `401 Unauthorized`: API key mancante o non valida
- `403 Forbidden`: API key scaduta, disabilitata, o permessi insufficienti
- `404 Not Found`: Risorsa non trovata
- `405 Method Not Allowed`: Metodo HTTP non consentito
- `500 Internal Server Error`: Errore interno del server

---

## Gestione Permessi

Le API keys possono avere permessi granulari per controllare l'accesso a specifiche risorse:

```json
{
  "soci": true,
  "tessere": true,
  "sedi": false
}
```

Se i permessi non sono definiti, l'accesso a tutte le risorse è consentito.

---

## Sicurezza

### IP Whitelist
Le API keys possono essere limitate a specifici indirizzi IP:

```json
["192.168.1.100", "10.0.0.50"]
```

### Scadenza
Le API keys possono avere una data di scadenza configurabile.

### HTTPS
Si raccomanda fortemente l'uso di HTTPS in produzione per proteggere le API keys in transito.

---

## Note Implementative

- Tutte le date sono in formato `YYYY-MM-DD`
- Tutti i timestamp sono in formato `YYYY-MM-DD HH:MM:SS`
- Il charset è UTF-8
- Le query LIKE sono case-insensitive (dipende dalla configurazione MySQL)
- I campi vuoti/null non vengono inclusi nelle risposte (a meno che non siano rilevanti)

---

## Supporto

Per assistenza o per richiedere nuove funzionalità, contattare l'amministratore del sistema.
