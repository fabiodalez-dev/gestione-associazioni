<?php
/**
 * Migration: Add User Approval System
 *
 * Adds approval workflow for new user registrations
 *
 * Run: php migrations/add_user_approval_system.php
 */

require_once __DIR__ . '/../config.php';

echo "=== User Approval System Migration ===\n\n";

try {
    // 1. Add approval fields to users table
    echo "1. Adding approval fields to users table...\n";

    // Check if columns already exist
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();

    if (!$check) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN status ENUM('pending', 'approved', 'rejected', 'suspended') DEFAULT 'pending' AFTER role,
            ADD COLUMN approved_by INT NULL AFTER status,
            ADD COLUMN approved_at TIMESTAMP NULL AFTER approved_by,
            ADD COLUMN rejection_reason TEXT NULL AFTER approved_at,
            ADD COLUMN attivo BOOLEAN DEFAULT TRUE AFTER rejection_reason,
            ADD INDEX idx_users_status (status),
            ADD INDEX idx_users_attivo (attivo),
            ADD FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
        ");
        echo "   ✓ Added: status, approved_by, approved_at, rejection_reason, attivo\n";
    } else {
        echo "   ℹ Columns already exist, skipping...\n";
    }

    // 2. Add profile fields for registration
    echo "\n2. Adding profile fields to users table...\n";

    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'nome'")->fetch();

    if (!$check) {
        $pdo->exec("
            ALTER TABLE users
            ADD COLUMN nome VARCHAR(100) NULL AFTER email,
            ADD COLUMN cognome VARCHAR(100) NULL AFTER nome,
            ADD COLUMN telefono VARCHAR(20) NULL AFTER cognome,
            ADD COLUMN nome_associazione VARCHAR(255) NULL AFTER telefono,
            ADD COLUMN note_registrazione TEXT NULL AFTER nome_associazione
        ");
        echo "   ✓ Added: nome, cognome, telefono, nome_associazione, note_registrazione\n";
    } else {
        echo "   ℹ Profile fields already exist, skipping...\n";
    }

    // 3. Update existing users to 'approved' status
    echo "\n3. Updating existing users to 'approved' status...\n";

    $stmt = $pdo->exec("
        UPDATE users
        SET status = 'approved',
            approved_at = created_at,
            attivo = TRUE
        WHERE status IS NULL OR status = 'pending'
    ");
    echo "   ✓ Updated $stmt existing users\n";

    // 4. Create user_approval_log table for audit trail
    echo "\n4. Creating user_approval_log table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_approval_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action ENUM('registered', 'approved', 'rejected', 'suspended', 'reactivated') NOT NULL,
            performed_by INT NULL,
            reason TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created user_approval_log table\n";

    // 5. Create pending_registrations table for additional data
    echo "\n5. Creating pending_registrations table...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pending_registrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            verification_token VARCHAR(64) UNIQUE,
            ip_address VARCHAR(45),
            user_agent TEXT,
            referrer VARCHAR(255),
            additional_data JSON,
            expires_at TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (verification_token),
            INDEX idx_expires (expires_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "   ✓ Created pending_registrations table\n";

    // 6. Statistics
    echo "\n=== Migration Statistics ===\n";

    $stats = $pdo->query("
        SELECT
            COUNT(*) as total_users,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
        FROM users
    ")->fetch();

    echo "Total users: " . $stats['total_users'] . "\n";
    echo "  - Approved: " . $stats['approved'] . "\n";
    echo "  - Pending: " . $stats['pending'] . "\n";
    echo "  - Rejected: " . $stats['rejected'] . "\n";
    echo "  - Suspended: " . $stats['suspended'] . "\n";

    echo "\n✅ Migration completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Use auth/register.php for new registrations\n";
    echo "2. Admins can approve users at: index.php?page=user-approvals\n";
    echo "3. Email notifications will be sent on approval/rejection\n\n";

} catch (PDOException $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Rolling back changes...\n";
    exit(1);
}
