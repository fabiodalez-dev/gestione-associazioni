<?php
// migrations/update_cf_unique.php

function runCFUniqueMigration(PDO $pdo) {
    $changes = [];
    try {
        // Ensure codice_fiscale column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM soci LIKE 'codice_fiscale'");
        $stmt->execute();
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => "Colonna 'codice_fiscale' non trovata nella tabella soci."];
        }

        // Normalize empty CF to NULL to avoid unique duplicates on ''
        $pdo->exec("UPDATE soci SET codice_fiscale = NULL WHERE codice_fiscale = ''");
        $changes[] = "Normalizzati CF vuoti a NULL";

        // Remove duplicates (keep the oldest by data_iscrizione if exists)
        try {
            $pdo->exec("DELETE s1 FROM soci s1 JOIN soci s2 ON s1.associazione_id = s2.associazione_id AND s1.codice_fiscale = s2.codice_fiscale AND s1.id <> s2.id WHERE COALESCE(s1.data_iscrizione, '9999-12-31') > COALESCE(s2.data_iscrizione, '9999-12-31')");
            $changes[] = "Rimossi eventuali duplicati CF nella stessa associazione (criterio data_iscrizione)";
        } catch (PDOException $e) { /* best effort */ }

        // Check if unique index already exists
        $idxName = 'uniq_assoc_cf';
        $stmt = $pdo->prepare("SHOW INDEX FROM soci WHERE Key_name = ?");
        $stmt->execute([$idxName]);
        if ($stmt->rowCount() === 0) {
            // Add unique index on (associazione_id, codice_fiscale)
            $pdo->exec("ALTER TABLE soci ADD UNIQUE INDEX $idxName (associazione_id, codice_fiscale)");
            $changes[] = "Aggiunto indice unico ($idxName) su (associazione_id, codice_fiscale)";
        }

        return ['success' => true, 'message' => 'Migrazione CF unico completata', 'changes' => $changes];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Errore migrazione CF unico: ' . $e->getMessage(), 'changes' => $changes];
    }
}

