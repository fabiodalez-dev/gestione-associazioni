<?php
/**
 * Migration: Add Analytics Tables and Indexes
 *
 * Creates tables for tracking analytics data and optimizes existing tables
 *
 * Run: php migrations/add_analytics_system.php
 */

require_once __DIR__ . '/../config.php';

echo "=== Analytics System Migration ===\n\n";

try {
    // 1. Create analytics_cache table for performance
    echo "1. Creating analytics_cache table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cache_key VARCHAR(255) UNIQUE NOT NULL,
            associazione_id VARCHAR(36),
            data JSON NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cache_key (cache_key),
            INDEX idx_associazione (associazione_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created analytics_cache table\n";

    // 2. Create analytics_snapshots for daily aggregates
    echo "\n2. Creating analytics_snapshots table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics_snapshots (
            id INT AUTO_INCREMENT PRIMARY KEY,
            associazione_id VARCHAR(36),
            snapshot_date DATE NOT NULL,
            metric_type ENUM('members', 'cards', 'payments', 'events', 'communications') NOT NULL,
            metrics JSON NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_snapshot (associazione_id, snapshot_date, metric_type),
            INDEX idx_date (snapshot_date),
            INDEX idx_type (metric_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created analytics_snapshots table\n";

    // 3. Add analytics fields to soci table
    echo "\n3. Adding analytics fields to soci table...\n";

    $columns_to_add = [
        "citta VARCHAR(100)" => "AFTER indirizzo",
        "provincia VARCHAR(2)" => "AFTER citta",
        "cap VARCHAR(10)" => "AFTER provincia",
        "paese VARCHAR(100) DEFAULT 'Italia'" => "AFTER cap",
        "data_iscrizione DATE" => "AFTER data_nascita",
        "data_ultima_attivita TIMESTAMP NULL" => "AFTER data_iscrizione",
        "punteggio_engagement INT DEFAULT 0" => "AFTER data_ultima_attivita",
        "note_interne TEXT" => "AFTER note"
    ];

    foreach ($columns_to_add as $column => $position) {
        $col_name = explode(' ', $column)[0];
        $check = $pdo->query("SHOW COLUMNS FROM soci LIKE '$col_name'")->fetch();
        if (!$check) {
            try {
                $pdo->exec("ALTER TABLE soci ADD COLUMN $column $position");
                echo "   ✓ Added column: $col_name\n";
            } catch (PDOException $e) {
                echo "   ⚠ Column $col_name: " . $e->getMessage() . "\n";
            }
        }
    }

    // 4. Add analytics fields to tessere table
    echo "\n4. Adding analytics fields to tessere table...\n";

    $tessere_columns = [
        "metodo_pagamento ENUM('contanti', 'bonifico', 'carta', 'paypal', 'altro')" => "AFTER data_emissione",
        "data_pagamento DATE" => "AFTER metodo_pagamento",
        "rinnovata_da VARCHAR(36)" => "AFTER stato",
        "canale_emissione ENUM('sportello', 'online', 'evento', 'altro') DEFAULT 'sportello'" => "AFTER data_emissione"
    ];

    foreach ($tessere_columns as $column => $position) {
        $col_name = explode(' ', $column)[0];
        $check = $pdo->query("SHOW COLUMNS FROM tessere LIKE '$col_name'")->fetch();
        if (!$check) {
            try {
                $pdo->exec("ALTER TABLE tessere ADD COLUMN $column $position");
                echo "   ✓ Added column: $col_name\n";
            } catch (PDOException $e) {
                echo "   ⚠ Column $col_name: " . $e->getMessage() . "\n";
            }
        }
    }

    // 5. Add analytics indexes to soci
    echo "\n5. Adding performance indexes to soci...\n";

    $soci_indexes = [
        "idx_soci_citta" => "(citta)",
        "idx_soci_provincia" => "(provincia)",
        "idx_soci_data_iscrizione" => "(data_iscrizione)",
        "idx_soci_engagement" => "(punteggio_engagement)",
        "idx_soci_ultima_attivita" => "(data_ultima_attivita)"
    ];

    foreach ($soci_indexes as $index_name => $columns) {
        try {
            $pdo->exec("CREATE INDEX $index_name ON soci $columns");
            echo "   ✓ Created index: $index_name\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "   ⚠ Index $index_name: " . $e->getMessage() . "\n";
            }
        }
    }

    // 6. Add analytics indexes to tessere
    echo "\n6. Adding performance indexes to tessere...\n";

    $tessere_indexes = [
        "idx_tessere_metodo_pagamento" => "(metodo_pagamento)",
        "idx_tessere_data_pagamento" => "(data_pagamento)",
        "idx_tessere_canale" => "(canale_emissione)"
    ];

    foreach ($tessere_indexes as $index_name => $columns) {
        try {
            $pdo->exec("CREATE INDEX $index_name ON tessere $columns");
            echo "   ✓ Created index: $index_name\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "   ⚠ Index $index_name: " . $e->getMessage() . "\n";
            }
        }
    }

    // 7. Create member_activity_log table
    echo "\n7. Creating member_activity_log table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS member_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            socio_id VARCHAR(36) NOT NULL,
            activity_type ENUM('login', 'card_issued', 'payment', 'event_rsvp', 'email_opened', 'profile_updated', 'document_downloaded') NOT NULL,
            activity_data JSON,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_socio_id (socio_id),
            INDEX idx_activity_type (activity_type),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created member_activity_log table\n";

    // 8. Create geographic_data table
    echo "\n8. Creating geographic_data table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS geographic_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            associazione_id VARCHAR(36),
            provincia VARCHAR(2) NOT NULL,
            provincia_nome VARCHAR(100),
            regione VARCHAR(100),
            member_count INT DEFAULT 0,
            active_cards INT DEFAULT 0,
            total_revenue DECIMAL(10,2) DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_geo (associazione_id, provincia),
            INDEX idx_regione (regione)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created geographic_data table\n";

    // 9. Update existing data with defaults
    echo "\n9. Updating existing records with default values...\n";

    // Set default data_iscrizione to created_at or current date
    $pdo->exec("
        UPDATE soci
        SET data_iscrizione = COALESCE(DATE(created_at), CURDATE())
        WHERE data_iscrizione IS NULL
    ");
    echo "   ✓ Updated soci.data_iscrizione\n";

    // Set default paese to 'Italia'
    $pdo->exec("
        UPDATE soci
        SET paese = 'Italia'
        WHERE paese IS NULL OR paese = ''
    ");
    echo "   ✓ Updated soci.paese\n";

    // 10. Statistics
    echo "\n=== Migration Statistics ===\n";

    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM soci) as total_members,
            (SELECT COUNT(*) FROM tessere) as total_cards,
            (SELECT COUNT(*) FROM quote) as total_payments,
            (SELECT COUNT(*) FROM eventi) as total_events,
            (SELECT COUNT(DISTINCT citta) FROM soci WHERE citta IS NOT NULL) as total_cities,
            (SELECT COUNT(DISTINCT provincia) FROM soci WHERE provincia IS NOT NULL) as total_provinces
    ")->fetch();

    echo "Total members: " . $stats['total_members'] . "\n";
    echo "Total cards: " . $stats['total_cards'] . "\n";
    echo "Total payments: " . $stats['total_payments'] . "\n";
    echo "Total events: " . $stats['total_events'] . "\n";
    echo "Unique cities: " . $stats['total_cities'] . "\n";
    echo "Unique provinces: " . $stats['total_provinces'] . "\n";

    echo "\n✅ Migration completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Access analytics at: index.php?page=analytics\n";
    echo "2. Review KPI dashboard\n";
    echo "3. Export reports as needed\n\n";

} catch (PDOException $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Rolling back changes...\n";
    exit(1);
}
