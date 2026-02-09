<?php
// index.php - Main Router v2.0 (SaaS)

// Start output buffering to allow header redirection even after some output
ob_start();

// Includi il file di configurazione principale
require_once 'config.php';

// --- Gestione Autenticazione e Routing ---

// Verifica se l'utente è loggato. Se non lo è, reindirizza alla pagina di login.
// Fa eccezione la pagina di login stessa per evitare un loop di reindirizzamento.
if (!isUserLoggedIn() && ($_GET['page'] ?? 'dashboard') !== 'login') {
    redirect('auth/login.php');
}

// Definisci le pagine consentite e i relativi file
$allowed_pages = [
    'dashboard' => 'pages/dashboard.php',
    'soci' => 'pages/soci.php',
    'eventi' => 'pages/eventi.php',
    'quote' => 'pages/quote.php',
    'tessere' => 'pages/tessere.php',
    'comunicazioni' => 'pages/comunicazioni.php',
    'documenti' => 'pages/documenti.php',
    'verbali' => 'pages/verbali.php',
    'scadenze' => 'pages/scadenze.php',
    'partecipanti' => 'pages/partecipanti.php',
    // Pagine di configurazione
    'configurazioni' => 'pages/configurazioni.php',
    'amministratori' => 'pages/amministratori.php',
    'categorie-socio' => 'pages/categorie-socio.php',
    'tipi-socio' => 'pages/tipi-socio.php',
    'sezioni' => 'pages/sezioni.php',
    'config_campi' => 'pages/config_campi.php',
    'config_tags' => 'pages/config_tags.php',
    'config_gruppi' => 'pages/config_gruppi.php',
    'socio_dettaglio' => 'pages/socio_dettaglio.php',
    'stampa-tessera' => 'pages/stampa-tessera.php',
    'genera-tessera-pdf' => 'pages/genera-tessera-pdf.php',
    'associazioni' => 'pages/associazioni.php',
    // Pagine Email & Notifiche
    'email-impostazioni' => 'pages/email_impostazioni.php',
    'email-templates' => 'pages/email_templates.php',
    'email-coda' => 'pages/email_coda.php',
    'email-log' => 'pages/email_log.php',
    // Pagine API e azioni
    'api-keys' => 'pages/api_keys.php',
    'api-docs' => 'pages/api_docs.php',
    'export' => 'api/export.php',
];

// Determina la pagina da caricare con validazione sicura
$page_key = validateInput($_GET['page'] ?? 'dashboard', 'alphanumeric', 50);

// Se la validazione fallisce, usa dashboard come default
if ($page_key === false) {
    $page_key = 'dashboard';
}

// Verifica se la pagina richiesta è nell'elenco di quelle consentite
if (array_key_exists($page_key, $allowed_pages)) {
    $page_to_include = $allowed_pages[$page_key];
} else {
    // Se la pagina non esiste, mostra la pagina 404
    http_response_code(404);
    $page_to_include = 'pages/404.php';
}

// Per alcune pagine "raw" evitiamo il layout (niente sidebar/head) perché producono output standalone (es. stampa/PDF)
$raw_pages = [
    'genera-tessera-pdf',
    'stampa-tessera',
];

if (in_array($page_key, $raw_pages, true)) {
    include $page_to_include;
    exit;
}

// --- Inizio Layout HTML ---
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeOutput($_SESSION['app_name'] ?? 'Associazione Soci Manager'); ?></title>
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <meta name="theme-color" content="#FF7B11">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
</head>
<body>
    <!-- Mobile Header (only visible on mobile when user is logged in) -->
    <?php if (isUserLoggedIn()): ?>
    <div class="mobile-header d-md-none">
        <button class="sidebar-toggle" id="sidebarToggle">
            <i class="bi bi-list"></i>
            <span>Menu</span>
        </button>
        <div class="flex-grow-1 text-center">
            <span class="fw-semibold"><?php echo htmlspecialchars($_SESSION['associazione_nome'] ?? 'Manager'); ?></span>
        </div>
        <div style="width: 70px;"></div> <!-- Spacer for centering -->
    </div>
    
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay d-md-none" id="sidebarOverlay"></div>
    <?php endif; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- La sidebar viene inclusa solo se l'utente è loggato -->
            <?php if (isUserLoggedIn()): ?>
                <?php include 'includes/sidebar.php'; ?>
            <?php endif; ?>
            
            <!-- Contenuto Principale -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 main-content" id="mainContent">
                <div class="page-container" data-page="<?php echo $page_key; ?>">
                    <?php
                    // Includi il file della pagina richiesta
                    include $page_to_include;
                    ?>
                </div>
            </main>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('./service-worker.js').catch(function() {});
    }
    </script>
</body>
</html>
<?php
// Flush the output buffer
ob_end_flush();
?>
