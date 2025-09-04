<?php
/**
 * Migration: create tessera_templates table if not exists
 */

function runTesseraTemplatesMigration(PDO $pdo) {
    $changes = [];
    try {
        // Check table existence
        $stmt = $pdo->query("SHOW TABLES LIKE 'tessera_templates'");
        if ($stmt->rowCount() === 0) {
            $sql = "
                CREATE TABLE tessera_templates (
                    id CHAR(36) PRIMARY KEY,
                    associazione_id CHAR(36) NOT NULL,
                    tipo_socio_id CHAR(36) NULL,
                    titolo VARCHAR(255) DEFAULT 'Template Tessera',
                    contenuto TEXT NOT NULL,
                    attivo BOOLEAN DEFAULT TRUE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE(associazione_id, tipo_socio_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
            $pdo->exec($sql);
            $changes[] = 'Created table tessera_templates';

            // Add FKs
            $pdo->exec("ALTER TABLE tessera_templates ADD CONSTRAINT fk_tt_assoc FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE");
            $pdo->exec("ALTER TABLE tessera_templates ADD CONSTRAINT fk_tt_tipo FOREIGN KEY (tipo_socio_id) REFERENCES tipi_socio(id) ON DELETE SET NULL");
        }

        return ['success' => true, 'message' => 'Tessera templates migration executed.', 'changes' => $changes];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

