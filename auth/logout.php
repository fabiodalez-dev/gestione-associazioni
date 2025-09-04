<?php
// auth/logout.php - Gestione Logout v2.0

// Includi la configurazione per avviare la sessione se non già attiva
require_once '../config.php';

// Svuota tutte le variabili di sessione
$_SESSION = [];

// Distrugge la sessione
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Reindirizza alla pagina di login
redirect('login.php');
