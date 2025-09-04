<?php
/**
 * Database Migration Runner
 * Run this script to update the database schema for existing installations
 */

// Include configuration
require_once 'config.php';

echo "=== Associazione Soci Manager - Database Migration ===\n\n";

// Check if we're running from command line or web
$isCommandLine = php_sapi_name() === 'cli';

if (!$isCommandLine) {
    // Web interface
    echo "<pre>";
    echo "<h2>Database Migration Tool</h2>";
    echo "<p>This tool will update your database schema to support the latest features.</p>";
    
    // Simple authentication check
    if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
        echo "<p><strong>WARNING:</strong> This will modify your database structure.</p>";
        echo "<p>Make sure you have a backup of your database before proceeding.</p>";
        echo "<p><a href='migrate.php?confirm=yes' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>Confirm and Run Migration</a></p>";
        echo "</pre>";
        exit;
    }
}

try {
    // Test database connection
    echo "Testing database connection...\n";
    $stmt = $pdo->query("SELECT VERSION() as version");
    $version = $stmt->fetch()['version'];
    echo "✅ Connected to MySQL $version\n\n";
    
    // Run migrations
    echo "Available migrations:\n";
    
    // Migration 1: Update users table for multitenant support
    if (file_exists('migrations/update_users_table.php')) {
        require_once 'migrations/update_users_table.php';
        
        echo "\n1. Running users table migration...\n";
        echo str_repeat('-', 50) . "\n";
        
        $result = runUserTableMigration($pdo);
        
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (isset($result['changes'])) {
                foreach ($result['changes'] as $change) {
                    echo "   - $change\n";
                }
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    // Migration 2: Tessera templates per associazione e tipo socio
    if (file_exists('migrations/update_tessera_templates.php')) {
        require_once 'migrations/update_tessera_templates.php';
        
        echo "\n2. Running tessera templates migration...\n";
        echo str_repeat('-', 50) . "\n";
        
        $result = runTesseraTemplatesMigration($pdo);
        
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (isset($result['changes'])) {
                foreach ($result['changes'] as $change) {
                    echo "   - $change\n";
                }
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    // Migration 3: Saved filters presets
    if (file_exists('migrations/update_saved_filters.php')) {
        require_once 'migrations/update_saved_filters.php';
        
        echo "\n3. Running saved filters migration...\n";
        echo str_repeat('-', 50) . "\n";
        
        $result = runSavedFiltersMigration($pdo);
        
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (isset($result['changes'])) {
                foreach ($result['changes'] as $change) {
                    echo "   - $change\n";
                }
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    // Migration 4: Tessera cost fields
    if (file_exists('migrations/update_tessera_cost.php')) {
        require_once 'migrations/update_tessera_cost.php';
        echo "\n4. Running tessera cost migration...\n";
        echo str_repeat('-', 50) . "\n";
        $result = runTesseraCostMigration($pdo);
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (!empty($result['changes'])) {
                foreach ($result['changes'] as $c) echo "   - $c\n";
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    // Migration 5: Schema optimizations (indexes + text normalization)
    if (file_exists('migrations/update_schema_optimizations.php')) {
        require_once 'migrations/update_schema_optimizations.php';
        echo "\n5. Running schema optimizations...\n";
        echo str_repeat('-', 50) . "\n";
        $result = runSchemaOptimizations($pdo);
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (!empty($result['changes'])) {
                foreach ($result['changes'] as $c) echo "   - $c\n";
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }

    // Migration 6: Unique CF per associazione
    if (file_exists('migrations/update_cf_unique.php')) {
        require_once 'migrations/update_cf_unique.php';
        echo "\n6. Running CF unique index migration...\n";
        echo str_repeat('-', 50) . "\n";
        $result = runCFUniqueMigration($pdo);
        if ($result['success']) {
            echo "✅ " . $result['message'] . "\n";
            if (!empty($result['changes'])) {
                foreach ($result['changes'] as $c) echo "   - $c\n";
            }
        } else {
            echo "❌ " . $result['message'] . "\n";
        }
        echo str_repeat('-', 50) . "\n";
    }
    // Check current schema status
    echo "\nCurrent schema status:\n";
    
    // Check users table structure
    try {
        $stmt = $pdo->query("DESCRIBE users");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "Users table columns: " . implode(', ', $columns) . "\n";
        
        if (in_array('associazione_id', $columns)) {
            echo "✅ associazione_id column exists\n";
        } else {
            echo "❌ associazione_id column missing\n";
        }
        
        // Check role enum values
        $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
        $roleColumn = $stmt->fetch();
        if ($roleColumn) {
            echo "Role enum values: " . $roleColumn['Type'] . "\n";
        }
        
    } catch (PDOException $e) {
        echo "Could not check users table: " . $e->getMessage() . "\n";
    }
    
    // Check associations table
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM associazioni");
        $count = $stmt->fetch()['count'];
        echo "Associazioni table: $count records\n";
    } catch (PDOException $e) {
        echo "Associazioni table: Not found or error\n";
    }
    
    // Check admin users
    try {
        $stmt = $pdo->query("SELECT username, email, role, associazione_id FROM users ORDER BY role DESC");
        $users = $stmt->fetchAll();
        
        echo "\nCurrent admin users:\n";
        foreach ($users as $user) {
            $assocInfo = $user['associazione_id'] ? " (Assoc: {$user['associazione_id']})" : " (All associations)";
            echo "- {$user['username']} ({$user['email']}) - {$user['role']}$assocInfo\n";
        }
        
    } catch (PDOException $e) {
        echo "Could not list admin users: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== Migration completed! ===\n";
    
    if (!$isCommandLine) {
        echo "<p><strong>Migration completed successfully!</strong></p>";
        echo "<p><a href='index.php'>Return to Application</a></p>";
        echo "</pre>";
    }
    
} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "\n";
    echo "Please check your database configuration in config.php\n";
    
    if (!$isCommandLine) {
        echo "</pre>";
    }
    exit(1);
}
