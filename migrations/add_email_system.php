<?php
/**
 * Migration #7: Add email system tables (smtp_settings, email_templates, email_queue, email_log)
 * and email opt-out columns on soci table.
 */

function runEmailSystemMigration(PDO $pdo): array {
    $changes = [];

    // 1. smtp_settings
    if (!tableExists($pdo, 'smtp_settings')) {
        $pdo->exec("
            CREATE TABLE smtp_settings (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                smtp_host VARCHAR(255) NOT NULL DEFAULT '',
                smtp_port INT NOT NULL DEFAULT 587,
                smtp_user VARCHAR(255) NOT NULL DEFAULT '',
                smtp_pass_encrypted TEXT NOT NULL,
                smtp_encryption ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
                from_email VARCHAR(255) NOT NULL DEFAULT '',
                from_name VARCHAR(255) NOT NULL DEFAULT '',
                reply_to_email VARCHAR(255) DEFAULT NULL,
                reply_to_name VARCHAR(255) DEFAULT NULL,
                is_verified BOOLEAN DEFAULT FALSE,
                max_per_hour INT DEFAULT 100,
                auto_benvenuto BOOLEAN DEFAULT TRUE,
                auto_scadenza_tessera BOOLEAN DEFAULT TRUE,
                auto_scadenza_quota BOOLEAN DEFAULT TRUE,
                auto_rinnovo_tessera BOOLEAN DEFAULT FALSE,
                auto_pagamento_quota BOOLEAN DEFAULT FALSE,
                last_test_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_smtp_assoc (associazione_id),
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'Created table smtp_settings';
    }

    // 2. email_templates
    if (!tableExists($pdo, 'email_templates')) {
        $pdo->exec("
            CREATE TABLE email_templates (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                codice VARCHAR(50) NOT NULL,
                nome VARCHAR(255) NOT NULL,
                oggetto VARCHAR(500) NOT NULL DEFAULT '',
                corpo_html TEXT NOT NULL,
                corpo_json TEXT DEFAULT NULL,
                attivo BOOLEAN DEFAULT TRUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_template (associazione_id, codice),
                INDEX idx_et_assoc (associazione_id),
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'Created table email_templates';
    }

    // 3. email_queue
    if (!tableExists($pdo, 'email_queue')) {
        $pdo->exec("
            CREATE TABLE email_queue (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                batch_id CHAR(36) NOT NULL,
                socio_id CHAR(36) DEFAULT NULL,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) DEFAULT '',
                subject VARCHAR(500) NOT NULL,
                body_html TEXT NOT NULL,
                template_codice VARCHAR(50) DEFAULT NULL,
                priority TINYINT DEFAULT 5,
                status ENUM('pending','sending','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
                attempts INT DEFAULT 0,
                max_attempts INT DEFAULT 3,
                last_attempt_at DATETIME DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                sent_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_eq_status (status, created_at),
                INDEX idx_eq_batch (batch_id),
                INDEX idx_eq_assoc (associazione_id),
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
                FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'Created table email_queue';
    }

    // 4. email_log
    if (!tableExists($pdo, 'email_log')) {
        $pdo->exec("
            CREATE TABLE email_log (
                id CHAR(36) PRIMARY KEY,
                associazione_id CHAR(36) NOT NULL,
                batch_id CHAR(36) DEFAULT NULL,
                socio_id CHAR(36) DEFAULT NULL,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255) DEFAULT '',
                subject VARCHAR(500) NOT NULL,
                template_codice VARCHAR(50) DEFAULT NULL,
                status ENUM('sent','failed') NOT NULL,
                error_message TEXT DEFAULT NULL,
                sent_by CHAR(36) DEFAULT NULL,
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_el_assoc (associazione_id),
                INDEX idx_el_sent_at (sent_at),
                INDEX idx_el_batch (batch_id),
                FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
                FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE SET NULL,
                FOREIGN KEY (sent_by) REFERENCES utenti(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $changes[] = 'Created table email_log';
    }

    // 5. ALTER soci — add email opt-out columns
    if (!columnExists($pdo, 'soci', 'email_opt_out')) {
        $pdo->exec("ALTER TABLE soci ADD COLUMN email_opt_out BOOLEAN DEFAULT FALSE AFTER privacy_consenso");
        $changes[] = 'Added soci.email_opt_out column';
    }
    if (!columnExists($pdo, 'soci', 'email_opt_out_token')) {
        $pdo->exec("ALTER TABLE soci ADD COLUMN email_opt_out_token CHAR(64) DEFAULT NULL AFTER email_opt_out");
        $changes[] = 'Added soci.email_opt_out_token column';
    }

    if (empty($changes)) {
        return ['success' => true, 'message' => 'Email system tables already exist — nothing to do.'];
    }

    return ['success' => true, 'message' => 'Email system migration completed.', 'changes' => $changes];
}
