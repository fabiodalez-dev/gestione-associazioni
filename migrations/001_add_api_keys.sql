-- Migration: Add API Keys table
-- Purpose: Enable API key authentication for external integrations

CREATE TABLE IF NOT EXISTS api_keys (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    descrizione TEXT,
    attiva BOOLEAN DEFAULT TRUE,
    scadenza DATE NULL,
    ultimo_utilizzo DATETIME NULL,
    ip_whitelist TEXT NULL COMMENT 'JSON array of allowed IPs',
    permessi JSON NULL COMMENT 'JSON object with permissions: {"soci": true, "tessere": true, "sedi": true}',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_keys_associazione (associazione_id),
    INDEX idx_api_key_active (api_key, attiva),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert migration record
INSERT INTO migrations (migration_name) VALUES ('001_add_api_keys');
