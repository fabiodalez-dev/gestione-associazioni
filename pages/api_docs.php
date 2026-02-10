<?php
// pages/api_docs.php - Documentazione interattiva API REST

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

$baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$apiBase = $baseUrl . '/api/v1';
?>

<div class="pt-3 pb-2 mb-3 border-bottom">
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="h2"><i class="bi bi-book me-2"></i>Documentazione API</h1>
        <a href="index.php?page=api-keys" class="btn btn-outline-primary"><i class="bi bi-key me-1"></i>Gestisci Chiavi API</a>
    </div>
</div>

<div class="row">
<div class="col-lg-3 mb-3">
    <div class="list-group list-group-flush sticky-top" style="top:1rem;">
        <a href="#auth" class="list-group-item list-group-item-action">Autenticazione</a>
        <a href="#errors" class="list-group-item list-group-item-action">Errori</a>
        <a href="#tessere-verify" class="list-group-item list-group-item-action">Verifica Tessera</a>
        <a href="#tessere" class="list-group-item list-group-item-action">Tessere</a>
        <a href="#soci" class="list-group-item list-group-item-action">Soci</a>
        <a href="#eventi" class="list-group-item list-group-item-action">Eventi</a>
        <a href="#checkin" class="list-group-item list-group-item-action">Check-in Evento</a>
        <a href="#quote" class="list-group-item list-group-item-action">Quote</a>
        <a href="#client-examples" class="list-group-item list-group-item-action">Esempi Client</a>
        <a href="#qr-mobile" class="list-group-item list-group-item-action">QR + Mobile</a>
    </div>
</div>

<div class="col-lg-9">

<!-- Base URL -->
<div class="alert alert-info">
    <strong>Base URL:</strong> <code><?php echo htmlspecialchars($apiBase); ?></code>
</div>

<!-- Autenticazione -->
<div class="card mb-4" id="auth">
    <div class="card-header"><h5 class="mb-0">Autenticazione</h5></div>
    <div class="card-body">
        <p>Tutte le richieste (tranne la verifica tessera) richiedono un header <code>Authorization</code> con la chiave API:</p>
<pre class="bg-dark text-light p-3 rounded"><code>Authorization: Bearer {API_KEY}</code></pre>
        <p>Le chiavi API si creano dalla pagina <a href="index.php?page=api-keys">Gestisci Chiavi API</a>. Ogni chiave ha permessi specifici e appartiene a un'associazione.</p>
        <h6>Permessi disponibili</h6>
        <table class="table table-sm">
            <thead><tr><th>Permesso</th><th>Descrizione</th></tr></thead>
            <tbody>
                <tr><td><code>tessere:read</code></td><td>Leggere e cercare tessere</td></tr>
                <tr><td><code>soci:read</code></td><td>Leggere e cercare soci</td></tr>
                <tr><td><code>soci:write</code></td><td>Modificare dati soci</td></tr>
                <tr><td><code>eventi:read</code></td><td>Leggere eventi e partecipanti</td></tr>
                <tr><td><code>eventi:write</code></td><td>Check-in partecipanti</td></tr>
                <tr><td><code>quote:read</code></td><td>Leggere quote associative</td></tr>
                <tr><td><code>*</code></td><td>Accesso completo</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Errori -->
<div class="card mb-4" id="errors">
    <div class="card-header"><h5 class="mb-0">Formato Errori</h5></div>
    <div class="card-body">
        <p>Tutti gli errori ritornano un JSON con la struttura:</p>
<pre class="bg-dark text-light p-3 rounded"><code>{
  "success": false,
  "error": "Descrizione errore",
  "code": "error_code"
}</code></pre>
        <table class="table table-sm">
            <thead><tr><th>HTTP Status</th><th>Codice</th><th>Significato</th></tr></thead>
            <tbody>
                <tr><td>401</td><td><code>auth_missing</code></td><td>Header Authorization mancante</td></tr>
                <tr><td>401</td><td><code>auth_invalid</code></td><td>API key non valida</td></tr>
                <tr><td>403</td><td><code>permission_denied</code></td><td>Permesso non concesso</td></tr>
                <tr><td>403</td><td><code>key_expired</code></td><td>API key scaduta</td></tr>
                <tr><td>404</td><td><code>not_found</code></td><td>Risorsa non trovata</td></tr>
                <tr><td>405</td><td><code>method_not_allowed</code></td><td>Metodo HTTP non supportato</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Verifica Tessera (pubblica) -->
<div class="card mb-4" id="tessere-verify">
    <div class="card-header">
        <h5 class="mb-0"><span class="badge bg-success me-2">GET</span> Verifica Tessera <span class="badge bg-warning text-dark ms-2">Pubblica</span></h5>
    </div>
    <div class="card-body">
        <p>Verifica la validità di una tessera tramite il suo UUID (dal QR code). <strong>Non richiede autenticazione.</strong></p>
        <p><code>GET <?php echo htmlspecialchars($apiBase); ?>/tessere/verify?tessera_id={UUID}</code></p>
        <h6>Risposta</h6>
<pre class="bg-dark text-light p-3 rounded"><code>{
  "success": true,
  "valid": true,
  "tessera": {
    "numero_tessera": "0001",
    "anno_validita": 2026,
    "data_emissione": "2026-01-15",
    "data_scadenza": "2026-12-31",
    "stato": "Attiva"
  },
  "socio": {
    "nome": "Mario",
    "cognome": "Rossi",
    "numero_socio": "001"
  },
  "associazione": "ASD Sport Club"
}</code></pre>
        <h6>cURL</h6>
<pre class="bg-dark text-light p-3 rounded"><code>curl "<?php echo htmlspecialchars($apiBase); ?>/tessere/verify?tessera_id=UUID_DELLA_TESSERA"</code></pre>
    </div>
</div>

<!-- Tessere -->
<div class="card mb-4" id="tessere">
    <div class="card-header"><h5 class="mb-0"><span class="badge bg-success me-2">GET</span> Tessere</h5></div>
    <div class="card-body">
        <p>Richiede: <code>tessere:read</code></p>
        <h6>Lista tessere</h6>
        <p><code>GET /tessere?page=1&amp;limit=20&amp;anno=2026&amp;stato=Attiva</code></p>
        <h6>Cerca tessera</h6>
        <p><code>GET /tessere/search?q=0001</code></p>
        <p>Cerca per numero tessera, cognome, nome o numero socio.</p>
        <h6>Dettaglio tessera</h6>
        <p><code>GET /tessere/{id}</code></p>
    </div>
</div>

<!-- Soci -->
<div class="card mb-4" id="soci">
    <div class="card-header"><h5 class="mb-0"><span class="badge bg-success me-2">GET</span><span class="badge bg-primary me-2">PUT</span> Soci</h5></div>
    <div class="card-body">
        <h6>Lista soci</h6>
        <p><code>GET /soci?page=1&amp;limit=20&amp;stato=Attivo</code></p>
        <p>Richiede: <code>soci:read</code></p>
        <h6>Cerca socio</h6>
        <p><code>GET /soci/search?q=rossi</code></p>
        <p>Cerca per cognome, nome, numero socio, email o codice fiscale.</p>
        <h6>Dettaglio socio</h6>
        <p><code>GET /soci/{id}</code></p>
        <p>Include tags e tessere recenti.</p>
        <h6>Modifica socio</h6>
        <p><code>PUT /soci/{id}</code> — Richiede: <code>soci:write</code></p>
        <p>Campi modificabili: <code>nome, cognome, email, telefono, indirizzo, citta, provincia, cap, stato, note</code></p>
<pre class="bg-dark text-light p-3 rounded"><code>curl -X PUT "<?php echo htmlspecialchars($apiBase); ?>/soci/{id}" \
  -H "Authorization: Bearer API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"telefono": "+39 333 1234567", "stato": "Attivo"}'</code></pre>
    </div>
</div>

<!-- Eventi -->
<div class="card mb-4" id="eventi">
    <div class="card-header"><h5 class="mb-0"><span class="badge bg-success me-2">GET</span> Eventi</h5></div>
    <div class="card-body">
        <p>Richiede: <code>eventi:read</code></p>
        <h6>Lista eventi</h6>
        <p><code>GET /eventi?page=1&amp;limit=20&amp;futuri=1</code></p>
        <p>Usa <code>futuri=1</code> per mostrare solo gli eventi futuri.</p>
        <h6>Dettaglio evento</h6>
        <p><code>GET /eventi/{id}</code></p>
        <p>Include la lista dei partecipanti con stato check-in.</p>
    </div>
</div>

<!-- Check-in -->
<div class="card mb-4" id="checkin">
    <div class="card-header"><h5 class="mb-0"><span class="badge bg-warning text-dark me-2">POST</span> Check-in Evento</h5></div>
    <div class="card-body">
        <p>Richiede: <code>eventi:write</code></p>
        <p><code>POST /eventi/{evento_id}/checkin</code></p>
        <p>Il socio può essere identificato in 4 modi (in ordine di priorità):</p>
        <ol>
            <li><code>socio_id</code> — UUID del socio</li>
            <li><code>tessera_id</code> — UUID della tessera (dal QR code)</li>
            <li><code>numero_tessera</code> — Numero tessera</li>
            <li><code>numero_socio</code> — Numero socio</li>
        </ol>
<pre class="bg-dark text-light p-3 rounded"><code># Check-in tramite scansione QR (tessera_id dal QR code)
curl -X POST "<?php echo htmlspecialchars($apiBase); ?>/eventi/{evento_id}/checkin" \
  -H "Authorization: Bearer API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"tessera_id": "UUID_DALLA_SCANSIONE_QR"}'

# Check-in tramite numero tessera
curl -X POST "<?php echo htmlspecialchars($apiBase); ?>/eventi/{evento_id}/checkin" \
  -H "Authorization: Bearer API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"numero_tessera": "0001"}'</code></pre>
        <h6>Risposta</h6>
<pre class="bg-dark text-light p-3 rounded"><code>{
  "success": true,
  "already_checked_in": false,
  "message": "Check-in effettuato con successo.",
  "socio": {
    "nome": "Mario",
    "cognome": "Rossi",
    "numero_socio": "001"
  },
  "evento": "Assemblea Annuale 2026"
}</code></pre>
    </div>
</div>

<!-- Quote -->
<div class="card mb-4" id="quote">
    <div class="card-header"><h5 class="mb-0"><span class="badge bg-success me-2">GET</span> Quote</h5></div>
    <div class="card-body">
        <p>Richiede: <code>quote:read</code></p>
        <h6>Lista quote</h6>
        <p><code>GET /quote?page=1&amp;limit=20&amp;anno=2026&amp;stato=Pagata</code></p>
        <h6>Quote di un socio</h6>
        <p><code>GET /quote/{socio_id}</code></p>
    </div>
</div>

<!-- Esempi Client -->
<div class="card mb-4" id="client-examples">
    <div class="card-header"><h5 class="mb-0">Esempi Client</h5></div>
    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-curl">cURL</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-js">JavaScript</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-python">Python</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-php">PHP</button></li>
        </ul>
        <div class="tab-content mt-3">
            <div class="tab-pane fade show active" id="tab-curl">
<pre class="bg-dark text-light p-3 rounded"><code># Lista soci
curl -H "Authorization: Bearer YOUR_API_KEY" \
  "<?php echo htmlspecialchars($apiBase); ?>/soci"

# Cerca socio per cognome
curl -H "Authorization: Bearer YOUR_API_KEY" \
  "<?php echo htmlspecialchars($apiBase); ?>/soci/search?q=rossi"

# Verifica tessera (no auth)
curl "<?php echo htmlspecialchars($apiBase); ?>/tessere/verify?tessera_id=UUID"</code></pre>
            </div>
            <div class="tab-pane fade" id="tab-js">
<pre class="bg-dark text-light p-3 rounded"><code>const API_KEY = 'YOUR_API_KEY';
const BASE = '<?php echo htmlspecialchars($apiBase); ?>';

// Funzione helper
async function apiCall(endpoint, options = {}) {
  const res = await fetch(BASE + endpoint, {
    ...options,
    headers: {
      'Authorization': 'Bearer ' + API_KEY,
      'Content-Type': 'application/json',
      ...options.headers,
    },
  });
  return res.json();
}

// Lista soci
const soci = await apiCall('/soci?page=1&amp;limit=10');
console.log(soci);

// Check-in tramite QR
const checkin = await apiCall('/eventi/EVENT_ID/checkin', {
  method: 'POST',
  body: JSON.stringify({ tessera_id: 'UUID_DA_QR' }),
});

// Verifica tessera (no auth necessaria)
const verifica = await fetch(
  BASE + '/tessere/verify?tessera_id=UUID'
).then(r =&gt; r.json());
console.log(verifica.valid ? 'Tessera valida' : 'Tessera non valida');</code></pre>
            </div>
            <div class="tab-pane fade" id="tab-python">
<pre class="bg-dark text-light p-3 rounded"><code>import requests

API_KEY = 'YOUR_API_KEY'
BASE = '<?php echo htmlspecialchars($apiBase); ?>'
HEADERS = {'Authorization': f'Bearer {API_KEY}'}

# Lista soci
r = requests.get(f'{BASE}/soci', headers=HEADERS)
print(r.json())

# Cerca socio
r = requests.get(f'{BASE}/soci/search', headers=HEADERS,
                 params={'q': 'rossi'})
print(r.json())

# Check-in evento
r = requests.post(f'{BASE}/eventi/EVENT_ID/checkin',
                  headers=HEADERS,
                  json={'tessera_id': 'UUID_DA_QR'})
print(r.json())

# Verifica tessera (no auth)
r = requests.get(f'{BASE}/tessere/verify',
                 params={'tessera_id': 'UUID'})
print('Valida' if r.json()['valid'] else 'Non valida')</code></pre>
            </div>
            <div class="tab-pane fade" id="tab-php">
<pre class="bg-dark text-light p-3 rounded"><code>$apiKey = 'YOUR_API_KEY';
$base = '<?php echo htmlspecialchars($apiBase); ?>';

function apiCall($endpoint, $method = 'GET', $body = null) {
    global $apiKey, $base;
    $ch = curl_init($base . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER =&gt; true,
        CURLOPT_HTTPHEADER =&gt; [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Lista soci
$soci = apiCall('/soci');
print_r($soci);

// Check-in
$result = apiCall('/eventi/EVENT_ID/checkin', 'POST', [
    'tessera_id' =&gt; 'UUID_DA_QR',
]);
print_r($result);</code></pre>
            </div>
        </div>
    </div>
</div>

<!-- QR + Mobile -->
<div class="card mb-4" id="qr-mobile">
    <div class="card-header"><h5 class="mb-0">Integrazione QR Code + Mobile</h5></div>
    <div class="card-body">
        <h6>Come funziona il QR</h6>
        <p>Ogni tessera ha un QR code che contiene un URL di verifica con l'UUID della tessera. Quando scansionato:</p>
        <ol>
            <li>Il QR punta a <code><?php echo htmlspecialchars($baseUrl); ?>/verifica-tessera.php?t={UUID}</code></li>
            <li>La pagina web mostra i dati della tessera e la sua validità</li>
            <li>Oppure, un'app mobile può estrarre l'UUID e usare l'API</li>
        </ol>

        <h6>Flusso per App Mobile / Scanner</h6>
<pre class="bg-dark text-light p-3 rounded"><code>// 1. Scansiona QR code - ottieni URL
const qrUrl = 'https://example.com/verifica-tessera.php?t=abc-123-def';

// 2. Estrai il tessera_id dall'URL
const url = new URL(qrUrl);
const tesseraId = url.searchParams.get('t');

// 3. Opzione A: Verifica tessera (no auth)
const verifica = await fetch(
  `${BASE}/tessere/verify?tessera_id=${tesseraId}`
).then(r =&gt; r.json());

if (verifica.valid) {
  console.log(`Tessera valida: ${verifica.socio.nome} ${verifica.socio.cognome}`);
}

// 4. Opzione B: Check-in all'evento
const checkin = await apiCall(`/eventi/${eventoId}/checkin`, {
  method: 'POST',
  body: JSON.stringify({ tessera_id: tesseraId }),
});</code></pre>

        <h6>Esempio: App Check-in con HTML5 QR Scanner</h6>
<pre class="bg-dark text-light p-3 rounded"><code>&lt;!-- Includi una libreria QR scanner (es. html5-qrcode) --&gt;
&lt;script src="https://unpkg.com/html5-qrcode"&gt;&lt;/script&gt;
&lt;div id="reader"&gt;&lt;/div&gt;
&lt;script&gt;
const scanner = new Html5Qrcode("reader");
scanner.start(
  { facingMode: "environment" },
  { fps: 10, qrbox: 250 },
  (decodedText) =&gt; {
    // decodedText = URL di verifica tessera
    const url = new URL(decodedText);
    const tesseraId = url.searchParams.get('t');
    if (tesseraId) {
      // Check-in automatico
      fetch('<?php echo htmlspecialchars($apiBase); ?>/eventi/EVENT_ID/checkin', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer YOUR_API_KEY',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ tessera_id: tesseraId }),
      })
      .then(r =&gt; r.json())
      .then(data =&gt; {
        if (data.success) {
          alert('Check-in OK: ' + data.socio.nome + ' ' + data.socio.cognome);
        }
      });
    }
  }
);
&lt;/script&gt;</code></pre>
    </div>
</div>

</div><!-- col-lg-9 -->
</div><!-- row -->
