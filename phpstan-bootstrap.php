<?php
/**
 * PHPStan Bootstrap File
 * Dichiara variabili globali per l'analisi statica
 * NOTA: Questo file viene usato solo da PHPStan, non in runtime
 */

declare(strict_types=1);

// Stub per variabile globale $pdo definita in config.php
// Usiamo una funzione per evitare side effects durante l'analisi
function _phpstan_bootstrap_stub(): void
{
    /** @var PDO $pdo */
    global $pdo;
}

// Non chiamiamo mai questa funzione - serve solo a PHPStan per i type hints
