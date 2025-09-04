<?php
/**
 * Migration: Update users table for multitenant support
 * Adds associazione_id column and updates role enum
 */

function runUserTableMigration($pdo) {
    $migrations = [];
    
    try {
        // Check if migrations table exists, create if not
        $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_name VARCHAR(255) UNIQUE NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Check if this migration has already been run
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM migrations WHERE migration_name = ?");
        $stmt->execute(['update_users_table_multitenant']);
        if ($stmt->fetch()['count'] > 0) {
            return ['success' => true, 'message' => 'Migration already executed'];
        }
        
        echo "Starting users table migration for multitenant support...\n";
        
        // Step 1: Check if associazione_id column exists
        $columnExists = false;
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'associazione_id'");
            $columnExists = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Table might not exist yet
        }
        
        if (!$columnExists) {
            echo "Adding associazione_id column...\n";
            $pdo->exec("ALTER TABLE users ADD COLUMN associazione_id CHAR(36) NULL AFTER role");
            $migrations[] = "Added associazione_id column";
        } else {
            echo "associazione_id column already exists\n";
        }
        
        // Step 2: Add foreign key constraint if it doesn't exist
        try {
            // Check if foreign key exists
            $stmt = $pdo->query("
                SELECT COUNT(*) as count
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'users' 
                AND COLUMN_NAME = 'associazione_id' 
                AND REFERENCED_TABLE_NAME = 'associazioni'
            ");
            
            if ($stmt->fetch()['count'] == 0) {
                echo "Adding foreign key constraint...\n";
                $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_associazione 
                           FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL");
                $migrations[] = "Added foreign key constraint";
            } else {
                echo "Foreign key constraint already exists\n";
            }
        } catch (PDOException $e) {
            echo "Note: Could not add foreign key constraint (associazioni table might not exist yet): " . $e->getMessage() . "\n";
        }
        
        // Step 3: Update role enum to use correct values
        try {
            echo "Updating role enum values...\n";
            
            // First, update existing 'admin' values to 'admin_associazione'
            $stmt = $pdo->prepare("UPDATE users SET role = 'admin_associazione' WHERE role = 'admin'");
            $stmt->execute();
            $updatedRows = $stmt->rowCount();
            if ($updatedRows > 0) {
                echo "Updated $updatedRows existing admin roles to admin_associazione\n";
                $migrations[] = "Updated $updatedRows admin roles";
            }
            
            // Then modify the enum
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin_associazione', 'super_admin') DEFAULT 'admin_associazione'");
            $migrations[] = "Updated role enum values";
            
        } catch (PDOException $e) {
            echo "Note: Could not update role enum (might have existing incompatible data): " . $e->getMessage() . "\n";
        }
        
        // Step 4: Ensure default admin exists as super_admin
        try {
            echo "Checking for super_admin...\n";
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'super_admin'");
            if ($stmt->fetch()['count'] == 0) {
                echo "Creating default super_admin...\n";
                
                // Check if admin@test.com exists and update it
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@test.com' LIMIT 1");
                $stmt->execute();
                $existingAdmin = $stmt->fetch();
                
                if ($existingAdmin) {
                    $stmt = $pdo->prepare("UPDATE users SET role = 'super_admin', associazione_id = NULL WHERE id = ?");
                    $stmt->execute([$existingAdmin['id']]);
                    echo "Updated existing admin@test.com to super_admin\n";
                    $migrations[] = "Updated existing admin to super_admin";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        'admin', 
                        'admin@test.com', 
                        password_hash('admin123', PASSWORD_DEFAULT), 
                        'super_admin', 
                        null
                    ]);
                    echo "Created new super_admin user\n";
                    $migrations[] = "Created default super_admin";
                }
            } else {
                echo "Super admin already exists\n";
            }
        } catch (PDOException $e) {
            echo "Warning: Could not ensure super_admin exists: " . $e->getMessage() . "\n";
        }
        
        // Mark migration as completed
        $stmt = $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)");
        $stmt->execute(['update_users_table_multitenant']);
        
        echo "Migration completed successfully!\n";
        echo "Changes made:\n";
        foreach ($migrations as $change) {
            echo "- $change\n";
        }
        
        return [
            'success' => true, 
            'message' => 'Users table migration completed successfully',
            'changes' => $migrations
        ];
        
    } catch (PDOException $e) {
        return [
            'success' => false, 
            'message' => 'Migration failed: ' . $e->getMessage()
        ];
    }
}