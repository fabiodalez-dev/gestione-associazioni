<?php
// pages/sezioni.php - reindirizza la gestione Sedi alle Impostazioni dell'associazione
require_once __DIR__ . '/../config.php';

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

// Se l'utente ha un'associazione in sessione, porta alla sezione Sedi delle impostazioni
if (!empty($_SESSION['associazione_id'])) {
    redirect('index.php?page=configurazioni&section=sedi');
}

// Super admin senza associazione: porta alla selezione associazione in configurazioni
redirect('index.php?page=configurazioni');
