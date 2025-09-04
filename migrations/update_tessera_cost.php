<?php
/**
 * Migration: add costo_tessera to associazioni and tipi_socio
 */

function runTesseraCostMigration(PDO $pdo) {
    $changes = [];
    try {
        // associazioni.costo_tessera
        $col = $pdo->query("SHOW COLUMNS FROM associazioni LIKE 'costo_tessera'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE associazioni ADD COLUMN costo_tessera DECIMAL(10,2) NULL AFTER telefono");
            $changes[] = 'Added associazioni.costo_tessera';
        }
        // tipi_socio.costo_tessera
        $col = $pdo->query("SHOW COLUMNS FROM tipi_socio LIKE 'costo_tessera'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE tipi_socio ADD COLUMN costo_tessera DECIMAL(10,2) NULL AFTER descrizione");
            $changes[] = 'Added tipi_socio.costo_tessera';
        }
        return ['success' => true, 'message' => 'Tessera cost migration executed.', 'changes' => $changes];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

