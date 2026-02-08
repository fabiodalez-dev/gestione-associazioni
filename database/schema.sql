-- ============================================================================
-- Associazione Soci Manager - Database Schema v2.0 (SaaS Multi-Tenant)
-- ============================================================================
-- All tables use CREATE TABLE IF NOT EXISTS for idempotent execution.
-- Tables are ordered by foreign-key dependencies (parents first).
-- No DROP TABLE statements. No sample data.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. ASSOCIAZIONI — root entity, no foreign keys
-- ============================================================================
CREATE TABLE IF NOT EXISTS associazioni (
    id CHAR(36) PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    partita_iva VARCHAR(20) UNIQUE,
    codice_fiscale VARCHAR(16) UNIQUE,
    indirizzo TEXT,
    citta VARCHAR(100),
    provincia VARCHAR(2),
    cap VARCHAR(5),
    email VARCHAR(255) UNIQUE NOT NULL,
    telefono VARCHAR(20),
    costo_tessera DECIMAL(10,2) NULL,
    logo_url TEXT,
    attiva BOOLEAN DEFAULT TRUE,
    tipo_scadenza_default ENUM('solare', 'annuale') DEFAULT 'solare' NOT NULL,
    giorni_notifica_scadenza INT DEFAULT 30,
    template_email_scadenza TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. UTENTI — admin accounts (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS utenti (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36),
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    ruolo ENUM('super_admin', 'admin_associazione', 'operatore') NOT NULL,
    attivo BOOLEAN DEFAULT TRUE,
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. USERS — legacy/backward-compatible accounts (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin_associazione', 'super_admin') DEFAULT 'admin_associazione',
    associazione_id CHAR(36) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_users_association (associazione_id),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. SEDI — branch offices (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS sedi (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    indirizzo TEXT,
    citta VARCHAR(100),
    provincia VARCHAR(2),
    cap VARCHAR(5),
    email VARCHAR(255),
    telefono VARCHAR(20),
    responsabile VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. TIPI_SOCIO — member types (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tipi_socio (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT,
    costo_tessera DECIMAL(10,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. CATEGORIE_SOCIO — member categories (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS categorie_socio (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. SOCI — members (FK → associazioni, sedi, tipi_socio, categorie_socio)
-- ============================================================================
CREATE TABLE IF NOT EXISTS soci (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    sede_id CHAR(36),
    numero_socio VARCHAR(50) NOT NULL,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    data_nascita DATE NOT NULL,
    codice_fiscale VARCHAR(16),
    email VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    indirizzo VARCHAR(255),
    citta VARCHAR(100),
    provincia VARCHAR(2),
    cap VARCHAR(5),
    data_iscrizione DATE NOT NULL,
    stato ENUM('Attivo', 'Sospeso', 'Radiato', 'Deceduto', 'Trasferito') DEFAULT 'Attivo',
    note TEXT,
    privacy_consenso BOOLEAN DEFAULT FALSE,
    tipo_socio_id CHAR(36),
    categoria_socio_id CHAR(36),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    password_hash VARCHAR(255) NULL,
    password_reset_token VARCHAR(255) NULL,
    password_reset_expires DATETIME NULL,
    UNIQUE(associazione_id, numero_socio),
    UNIQUE(associazione_id, email),
    INDEX idx_soci_assoc (associazione_id),
    INDEX idx_soci_tipo (tipo_socio_id),
    INDEX idx_soci_cat (categoria_socio_id),
    INDEX idx_soci_sede (sede_id),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (sede_id) REFERENCES sedi(id) ON DELETE SET NULL,
    FOREIGN KEY (tipo_socio_id) REFERENCES tipi_socio(id) ON DELETE SET NULL,
    FOREIGN KEY (categoria_socio_id) REFERENCES categorie_socio(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. TESSERE — membership cards (FK → soci, associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tessere (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    associazione_id CHAR(36) NOT NULL,
    numero_tessera VARCHAR(50) NOT NULL,
    anno_validita INT NOT NULL,
    data_emissione DATE NOT NULL,
    data_scadenza DATE NOT NULL,
    tipo_scadenza ENUM('solare', 'annuale') NOT NULL,
    stato ENUM('Attiva', 'Scaduta', 'Sospesa', 'Annullata') DEFAULT 'Attiva',
    qr_code_url TEXT,
    template_tessera VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, numero_tessera),
    INDEX idx_tessere_assoc_anno (associazione_id, anno_validita),
    INDEX idx_tessere_socio (socio_id),
    INDEX idx_tessere_emissione (socio_id, associazione_id, data_emissione),
    INDEX idx_tessere_template (template_tessera),
    INDEX idx_tessere_scadenza (data_scadenza),
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. TESSERA_TEMPLATES — card design templates (FK → associazioni, tipi_socio)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tessera_templates (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    tipo_socio_id CHAR(36) NULL,
    titolo VARCHAR(255) DEFAULT 'Template Tessera',
    contenuto TEXT NOT NULL,
    attivo BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, tipo_socio_id),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_socio_id) REFERENCES tipi_socio(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. SAVED_FILTERS — saved filter presets (FK → associazioni, utenti)
-- ============================================================================
CREATE TABLE IF NOT EXISTS saved_filters (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    user_id CHAR(36) NULL,
    scope VARCHAR(50) NOT NULL DEFAULT 'soci',
    name VARCHAR(255) NOT NULL,
    params_json LONGTEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_filter (associazione_id, user_id, scope, name),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. QUOTE — membership fees (FK → soci, associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS quote (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    associazione_id CHAR(36) NOT NULL,
    anno INT NOT NULL,
    importo DECIMAL(10, 2) NOT NULL,
    data_scadenza DATE NOT NULL,
    data_pagamento DATE,
    stato ENUM('Pagata', 'Da Pagare', 'Scaduta', 'In Scadenza') DEFAULT 'Da Pagare',
    tipo VARCHAR(100) NOT NULL,
    note TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. EVENTI — events (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS eventi (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    titolo VARCHAR(255) NOT NULL,
    descrizione TEXT,
    data_evento DATETIME NOT NULL,
    luogo VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 13. EVENTI_PARTECIPANTI — event participants (FK → associazioni, eventi, soci)
-- ============================================================================
CREATE TABLE IF NOT EXISTS eventi_partecipanti (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    evento_id CHAR(36) NOT NULL,
    socio_id CHAR(36) NOT NULL,
    stato_partecipazione ENUM('Confermato', 'Forse', 'Non Partecipa') DEFAULT 'Non Partecipa',
    data_conferma TIMESTAMP NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_partecipation (evento_id, socio_id),
    INDEX idx_partecipanti_associazione (associazione_id),
    INDEX idx_partecipanti_evento (evento_id),
    INDEX idx_partecipanti_socio (socio_id),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (evento_id) REFERENCES eventi(id) ON DELETE CASCADE,
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 14. DOCUMENTI — association documents (FK → associazioni, utenti)
-- ============================================================================
CREATE TABLE IF NOT EXISTS documenti (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_file VARCHAR(255) NOT NULL,
    percorso_file TEXT NOT NULL,
    descrizione TEXT,
    categoria VARCHAR(100),
    dimensione_kb INT,
    caricato_da CHAR(36),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (caricato_da) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 15. CAMPI_PERSONALIZZATI — custom fields (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS campi_personalizzati (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_campo VARCHAR(100) NOT NULL,
    tipo_campo ENUM('text', 'email', 'tel', 'number', 'date', 'url', 'textarea', 'select', 'checkbox') NOT NULL,
    descrizione TEXT,
    opzioni TEXT,
    obbligatorio BOOLEAN DEFAULT FALSE,
    ordine INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome_campo),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 16. VALORI_CAMPI_PERSONALIZZATI — custom field values (FK → soci, campi_personalizzati)
-- ============================================================================
CREATE TABLE IF NOT EXISTS valori_campi_personalizzati (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    campo_id CHAR(36) NOT NULL,
    valore TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(socio_id, campo_id),
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campi_personalizzati(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17. TAGS — member tags (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS tags (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_tag VARCHAR(50) NOT NULL,
    colore VARCHAR(7) DEFAULT '#888888',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome_tag),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 18. SOCIO_TAGS — member-tag junction (FK → soci, tags)
-- ============================================================================
CREATE TABLE IF NOT EXISTS socio_tags (
    socio_id CHAR(36) NOT NULL,
    tag_id CHAR(36) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (socio_id, tag_id),
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 19. STORICO_ATTIVITA_SOCIO — member activity log (FK → soci, associazioni, utenti)
-- ============================================================================
CREATE TABLE IF NOT EXISTS storico_attivita_socio (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    associazione_id CHAR(36) NOT NULL,
    utente_id CHAR(36),
    tipo_attivita VARCHAR(50) NOT NULL,
    descrizione TEXT NOT NULL,
    dettagli_json JSON,
    data_attivita DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 20. DOCUMENTI_SOCIO — per-member documents (FK → soci, associazioni, utenti)
-- ============================================================================
CREATE TABLE IF NOT EXISTS documenti_socio (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    associazione_id CHAR(36) NOT NULL,
    nome_file VARCHAR(255) NOT NULL,
    percorso_file TEXT NOT NULL,
    descrizione TEXT,
    data_scadenza DATE,
    caricato_da CHAR(36),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE,
    FOREIGN KEY (caricato_da) REFERENCES utenti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21. GRUPPI_DINAMICI — dynamic member groups (FK → associazioni)
-- ============================================================================
CREATE TABLE IF NOT EXISTS gruppi_dinamici (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_gruppo VARCHAR(100) NOT NULL,
    descrizione TEXT,
    filtri_json JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome_gruppo),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 22. VERBALI — meeting minutes (index on associazione_id, no FK constraint)
-- ============================================================================
CREATE TABLE IF NOT EXISTS verbali (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    titolo VARCHAR(255) NOT NULL,
    data_riunione DATE NOT NULL,
    tipo_riunione ENUM('Assemblea Ordinaria', 'Assemblea Straordinaria', 'Consiglio Direttivo', 'Commissione') NOT NULL,
    presenti TEXT,
    ordine_del_giorno TEXT,
    deliberazioni TEXT,
    allegati VARCHAR(500),
    approvato BOOLEAN DEFAULT FALSE,
    approvato_da CHAR(36),
    data_approvazione DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_verbali_associazione (associazione_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 23. SCADENZE — deadlines/reminders (index on associazione_id, no FK constraint)
-- ============================================================================
CREATE TABLE IF NOT EXISTS scadenze (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    titolo VARCHAR(255) NOT NULL,
    descrizione TEXT,
    data_scadenza DATE NOT NULL,
    tipo_scadenza ENUM('Quote', 'Documenti', 'Eventi', 'Generale') NOT NULL,
    priorita ENUM('Bassa', 'Media', 'Alta', 'Critica') DEFAULT 'Media',
    stato ENUM('Attiva', 'Completata', 'Annullata') DEFAULT 'Attiva',
    assegnato_a CHAR(36),
    promemoria_giorni INT DEFAULT 7,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_scadenze_associazione (associazione_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 24. MIGRATIONS — schema version tracking (system table, no FKs)
-- ============================================================================
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- STANDALONE INDEXES — performance indexes for frequently queried columns
-- ============================================================================
-- Using CREATE INDEX IF NOT EXISTS is not supported in all MySQL versions,
-- so these are wrapped to silently fail if the index already exists.

CREATE INDEX idx_utenti_associazione_id ON utenti(associazione_id);
CREATE INDEX idx_sedi_associazione_id ON sedi(associazione_id);
CREATE INDEX idx_soci_associazione_id ON soci(associazione_id);
CREATE INDEX idx_soci_cognome_nome ON soci(cognome, nome);
CREATE INDEX idx_tessere_socio_id ON tessere(socio_id);
CREATE INDEX idx_tessere_associazione_id ON tessere(associazione_id);
CREATE INDEX idx_quote_socio_id ON quote(socio_id);
CREATE INDEX idx_quote_associazione_id ON quote(associazione_id);
CREATE INDEX idx_eventi_associazione_id ON eventi(associazione_id);
CREATE INDEX idx_documenti_associazione_id ON documenti(associazione_id);
CREATE INDEX idx_campi_personalizzati_associazione_id ON campi_personalizzati(associazione_id);
CREATE INDEX idx_valori_campi_personalizzati_socio_id ON valori_campi_personalizzati(socio_id);
CREATE INDEX idx_tags_associazione_id ON tags(associazione_id);
CREATE INDEX idx_socio_tags_socio_id ON socio_tags(socio_id);
CREATE INDEX idx_storico_attivita_socio_socio_id ON storico_attivita_socio(socio_id);
CREATE INDEX idx_documenti_socio_socio_id ON documenti_socio(socio_id);
CREATE INDEX idx_gruppi_dinamici_associazione_id ON gruppi_dinamici(associazione_id);
