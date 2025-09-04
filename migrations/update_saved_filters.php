<?php
/**
 * Migration: create saved_filters table for filter presets
 */

function runSavedFiltersMigration(PDO $pdo) {
    $changes = [];
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'saved_filters'");
        if ($stmt->rowCount() === 0) {
            $sql = "CREATE TABLE saved_filters (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                user_id CHAR(36) NULL,
                scope VARCHAR(50) NOT NULL DEFAULT 'soci',
                name VARCHAR(255) NOT NULL,
                params_json LONGTEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_filter (associazione_id, user_id, scope, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($sql);
            $changes[] = 'Created table saved_filters';
            // Add FKs where possible
            try { $pdo->exec("ALTER TABLE saved_filters ADD CONSTRAINT fk_sf_assoc FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE"); } catch (PDOException $e) {}
            try { $pdo->exec("ALTER TABLE saved_filters ADD CONSTRAINT fk_sf_user FOREIGN KEY (user_id) REFERENCES utenti(id) ON DELETE SET NULL"); } catch (PDOException $e) {}
        }
        return ['success' => true, 'message' => 'Saved filters migration executed.', 'changes' => $changes];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

