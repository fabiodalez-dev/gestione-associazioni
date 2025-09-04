-- Database schema for Associazione Soci Manager - v2.0 (SaaS - MySQL Compatible)

-- Drop tables if they exist (for clean re-creation during development)
-- Order matters due to foreign key constraints
DROP TABLE IF EXISTS gruppi_dinamici;
DROP TABLE IF EXISTS documenti_socio;
DROP TABLE IF EXISTS storico_attivita_socio;
DROP TABLE IF EXISTS socio_tags;
DROP TABLE IF EXISTS tags;
DROP TABLE IF EXISTS valori_campi_personalizzati;
DROP TABLE IF EXISTS campi_personalizzati;
DROP TABLE IF EXISTS documenti;
DROP TABLE IF EXISTS eventi;
DROP TABLE IF EXISTS quote;
DROP TABLE IF EXISTS tessere;
DROP TABLE IF EXISTS soci;
DROP TABLE IF EXISTS categorie_socio;
DROP TABLE IF EXISTS tipi_socio;
DROP TABLE IF EXISTS sedi;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS utenti;
DROP TABLE IF EXISTS associazioni;

-- Definizione dei tipi ENUM (MySQL)
-- MySQL non ha tipi ENUM globali, vanno definiti direttamente nelle tabelle

-- Tabella per le Associazioni (cuore del SaaS)
CREATE TABLE associazioni (
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
);

-- Tabella Users per l'accesso al sistema (compatibile con il codice esistente)
CREATE TABLE users (
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
);

-- Tabella Utenti legacy (mantenuta per compatibilità se necessaria)
CREATE TABLE utenti (
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
);

-- Tabella per le Sedi, collegate a un'associazione
CREATE TABLE sedi (
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
);

-- Tabelle di configurazione per associazione
CREATE TABLE tipi_socio (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT,
    costo_tessera DECIMAL(10,2) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
);

CREATE TABLE categorie_socio (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
);

-- Tabella Soci, il cuore della gestione
CREATE TABLE soci (
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
    -- Campi per l'accesso all'area riservata
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
);

-- Tabella Tessere, migliorata per le nuove esigenze
CREATE TABLE tessere (
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
);

-- Filtri salvati (preset) per viste come 'soci'
CREATE TABLE saved_filters (
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
);

-- Template Tessera per Associazione e Tipo Socio
CREATE TABLE tessera_templates (
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
);

-- Tabella Quote, collegata a soci e associazioni
CREATE TABLE quote (
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
);

-- Altre tabelle funzionali, tutte con `associazione_id`
CREATE TABLE eventi (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    titolo VARCHAR(255) NOT NULL,
    descrizione TEXT,
    data_evento DATETIME NOT NULL,
    luogo VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
);

CREATE TABLE documenti (
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
);

-- Tabelle per le nuove funzionalità CRM (MySQL Compatible)
CREATE TABLE campi_personalizzati (
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
);

CREATE TABLE valori_campi_personalizzati (
    id CHAR(36) PRIMARY KEY,
    socio_id CHAR(36) NOT NULL,
    campo_id CHAR(36) NOT NULL,
    valore TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(socio_id, campo_id),
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (campo_id) REFERENCES campi_personalizzati(id) ON DELETE CASCADE
);

CREATE TABLE tags (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_tag VARCHAR(50) NOT NULL,
    colore VARCHAR(7) DEFAULT '#888888',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome_tag),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
);

CREATE TABLE socio_tags (
    socio_id CHAR(36) NOT NULL,
    tag_id CHAR(36) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (socio_id, tag_id),
    FOREIGN KEY (socio_id) REFERENCES soci(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
);

CREATE TABLE storico_attivita_socio (
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
);

CREATE TABLE documenti_socio (
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
);

-- Tabella per i Gruppi Dinamici (filtri salvati per i soci)
CREATE TABLE gruppi_dinamici (
    id CHAR(36) PRIMARY KEY,
    associazione_id CHAR(36) NOT NULL,
    nome_gruppo VARCHAR(100) NOT NULL,
    descrizione TEXT,
    filtri_json JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE(associazione_id, nome_gruppo),
    FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
);

-- Indici per migliorare le performance delle query
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

-- Note: Trigger MySQL rimossi per compatibilità con installer PHP
-- I timestamp updated_at sono gestiti via "ON UPDATE CURRENT_TIMESTAMP" nelle definizioni delle tabelle

-- Inserimento dati di esempio
-- Super Admin di default (password: admin123)
INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES 
('admin', 'admin@test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin', NULL);

-- Associazione di esempio
INSERT INTO associazioni (id, nome, email, partita_iva, tipo_scadenza_default) VALUES 
('550e8400-e29b-41d4-a716-446655440000', 'Associazione di Test', 'info@test.com', '12345678901', 'solare');

-- Admin associazione di esempio (password: test123)
INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES 
('admin_test', 'admin@associazione-test.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin_associazione', '550e8400-e29b-41d4-a716-446655440000');

-- Tabella migrations per tracciare gli aggiornamenti dello schema
CREATE TABLE migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) UNIQUE NOT NULL,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Marca lo schema come inizializzato
INSERT INTO migrations (migration_name) VALUES ('initial_schema_v2_multitenant');
