<?php
// area-soci/logout.php

require_once '../config.php';

// Qui andrà la logica per il logout specifico della sessione del socio
session_destroy();

header('Location: login.php');
exit;
