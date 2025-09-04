<?php
// area-soci/index.php - Router Principale Area Riservata

require_once '../config.php';

// Proteggi l'accesso: solo soci loggati
if (!isset($_SESSION['socio_id'])) {
    redirect('login.php');
}

// Recupera i dati del socio loggato
$socio_loggato_id = $_SESSION['socio_id'];
$stmt_socio = $pdo->prepare("SELECT * FROM soci WHERE id = ?");
$stmt_socio->execute([$socio_loggato_id]);
$socio_loggato = $stmt_socio->fetch();

if (!$socio_loggato) {
    // Se il socio non esiste più o c'è un errore, forza il logout
    session_destroy();
    redirect('login.php');
}

// Definisci le pagine consentite nell'area riservata
$allowed_member_pages = [
    'dashboard' => 'pages/dashboard.php',
    'profilo' => 'pages/profilo.php',
    'quote' => 'pages/quote.php',
    'tessere' => 'pages/tessere.php',
    // Aggiungi qui altre pagine future
];

// Determina la pagina da caricare
$page_key = $_GET['page'] ?? 'dashboard';

if (array_key_exists($page_key, $allowed_member_pages)) {
    $page_to_include = $allowed_member_pages[$page_key];
} else {
    // Pagina 404 per l'area soci
    $page_to_include = 'pages/404.php'; // Creeremo una 404 specifica se necessario
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area Riservata - <?php echo htmlspecialchars($socio_loggato['nome'] . ' ' . $socio_loggato['cognome']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet"> <!-- Stile condiviso -->
    
    <style>
        body { background-color: #f8f9fa; }
        .member-area-main { margin-left: 240px; padding: 2rem; }
        @media (max-width: 767.98px) { .member-area-main { margin-left: 0; } }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 member-area-main page-container" data-page="<?php echo $page_key; ?>">
                <?php include $page_to_include; ?>
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
