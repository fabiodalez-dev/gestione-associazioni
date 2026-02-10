<?php
// pages/configurazioni.php - v2.0 (SaaS)

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}
$associazione_id = $_SESSION['associazione_id'];

// Assicura colonne costo tessera
ensureTesseraCostColumns($pdo);

// Assicura colonna privacy_policy_html
try {
    if (!columnExists($pdo, 'associazioni', 'privacy_policy_html')) {
        $pdo->exec("ALTER TABLE associazioni ADD COLUMN privacy_policy_html LONGTEXT DEFAULT NULL AFTER template_email_scadenza");
    }
} catch (PDOException $e) {
    error_log('configurazioni.php ensurePrivacyPolicyColumn: ' . $e->getMessage());
}

// Assicura colonna campi_obbligatori_config su associazioni
try {
    if (!columnExists($pdo, 'associazioni', 'campi_obbligatori_config')) {
        $pdo->exec("ALTER TABLE associazioni ADD COLUMN campi_obbligatori_config JSON DEFAULT NULL AFTER privacy_policy_html");
    }
} catch (PDOException $e) {
    error_log('configurazioni.php ensureCampiObbligatoriConfig: ' . $e->getMessage());
}

// Assicura colonna obbligatorio_preiscrizione su campi_personalizzati
try {
    if (!columnExists($pdo, 'campi_personalizzati', 'obbligatorio_preiscrizione')) {
        $pdo->exec("ALTER TABLE campi_personalizzati ADD COLUMN obbligatorio_preiscrizione BOOLEAN DEFAULT FALSE AFTER obbligatorio");
    }
} catch (PDOException $e) {
    error_log('configurazioni.php ensureObbligatorioPreiscrizione: ' . $e->getMessage());
}

// Assicura tabella payment_gateway_settings e colonne quote per pagamenti
try {
    if (!tableExists($pdo, 'payment_gateway_settings')) {
        $pdo->exec("CREATE TABLE payment_gateway_settings (
            id CHAR(36) PRIMARY KEY,
            associazione_id CHAR(36) NOT NULL,
            stripe_enabled BOOLEAN DEFAULT FALSE,
            stripe_publishable_key VARCHAR(255) DEFAULT '',
            stripe_secret_key_encrypted TEXT DEFAULT '',
            stripe_webhook_secret_encrypted TEXT DEFAULT '',
            paypal_enabled BOOLEAN DEFAULT FALSE,
            paypal_client_id VARCHAR(255) DEFAULT '',
            paypal_client_secret_encrypted TEXT DEFAULT '',
            paypal_mode ENUM('sandbox','live') DEFAULT 'sandbox',
            paypal_webhook_id VARCHAR(255) DEFAULT '',
            auto_attivazione_pagamento BOOLEAN DEFAULT TRUE,
            valuta VARCHAR(3) DEFAULT 'EUR',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pg_assoc (associazione_id),
            FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!columnExists($pdo, 'quote', 'metodo_pagamento')) {
        $pdo->exec("ALTER TABLE quote ADD COLUMN metodo_pagamento VARCHAR(50) DEFAULT NULL AFTER data_pagamento");
    }
    if (!columnExists($pdo, 'quote', 'gateway')) {
        $pdo->exec("ALTER TABLE quote ADD COLUMN gateway VARCHAR(20) DEFAULT NULL AFTER metodo_pagamento");
    }
    if (!columnExists($pdo, 'quote', 'gateway_transaction_id')) {
        $pdo->exec("ALTER TABLE quote ADD COLUMN gateway_transaction_id VARCHAR(255) DEFAULT NULL AFTER gateway");
    }
    if (!columnExists($pdo, 'quote', 'payment_token')) {
        $pdo->exec("ALTER TABLE quote ADD COLUMN payment_token CHAR(64) DEFAULT NULL AFTER gateway_transaction_id");
        $pdo->exec("ALTER TABLE quote ADD COLUMN payment_token_expires DATETIME DEFAULT NULL AFTER payment_token");
        $pdo->exec("ALTER TABLE quote ADD INDEX idx_quote_payment_token (payment_token)");
    }
} catch (PDOException $e) {
    error_log('configurazioni.php ensurePaymentSchema: ' . $e->getMessage());
}

$message = '';
$messageType = '';

// Verifica se la tabella per i template tessera è presente (per evitare errori pre-migrazione)
$has_tessera_templates = tableExists($pdo, 'tessera_templates');

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        try {
            // CRUD Sedi (sezione impostazioni)
            if (isset($_POST['sedi_action']) && $associazione_id) {
                if ($_POST['sedi_action'] === 'delete' && !empty($_POST['delete_id'])) {
                    $stmt = $pdo->prepare("DELETE FROM sedi WHERE id = ? AND associazione_id = ?");
                    $stmt->execute([$_POST['delete_id'], $associazione_id]);
                    $message = "Sede eliminata con successo."; $messageType = "success";
                } elseif ($_POST['sedi_action'] === 'save') {
                    $sid = $_POST['id'] ?? null;
                    $nome = cleanInput($_POST['nome'] ?? '');
                    $indirizzo = cleanInput($_POST['indirizzo'] ?? '');
                    $citta = cleanInput($_POST['citta'] ?? '');
                    $provincia = cleanInput($_POST['provincia'] ?? '');
                    $cap = cleanInput($_POST['cap'] ?? '');
                    $responsabile = cleanInput($_POST['responsabile'] ?? '');
                    if ($sid) {
                        $stmt = $pdo->prepare("UPDATE sedi SET nome=?, indirizzo=?, citta=?, provincia=?, cap=?, responsabile=? WHERE id=? AND associazione_id=?");
                        $stmt->execute([$nome, $indirizzo, $citta, $provincia, $cap, $responsabile, $sid, $associazione_id]);
                        $message = "Sede aggiornata con successo."; $messageType = "success";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, provincia, cap, responsabile) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([generateUuid(), $associazione_id, $nome, $indirizzo, $citta, $provincia, $cap, $responsabile]);
                        $message = "Sede creata con successo."; $messageType = "success";
                    }
                }
            }
            // Upload Logo Associazione
            if (isset($_POST['upload_logo']) && isset($_FILES['logo_file'])) {
            $errors = validateFileUpload($_FILES['logo_file'], ['jpg', 'jpeg', 'png'], 2 * 1024 * 1024);
            if (empty($errors)) {
                $safeName = generateSecureFileName($_FILES['logo_file']['name'], 'logo_');
                $targetPath = UPLOADS_PATH . '/logos/' . $safeName;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                    $relative = 'uploads/logos/' . $safeName;
                    $stmt = $pdo->prepare("UPDATE associazioni SET logo_url = ? WHERE id = ?");
                    $stmt->execute([$relative, $associazione_id]);
                    $message = "Logo aggiornato con successo.";
                    $messageType = "success";
                    $associazione['logo_url'] = $relative;
                } else {
                    $message = "Errore nel salvataggio del file.";
                    $messageType = "danger";
                }
            } else {
                $message = implode(' | ', $errors);
                $messageType = "danger";
            }
        }

        if (isset($_POST['update_info'])) {
            $sql = "UPDATE associazioni SET nome=?, partita_iva=?, codice_fiscale=?, indirizzo=?, citta=?, provincia=?, cap=?, email=?, telefono=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                cleanInput($_POST['nome'] ?? ''),
                cleanInput($_POST['partita_iva'] ?? ''),
                cleanInput($_POST['codice_fiscale'] ?? ''),
                cleanInput($_POST['indirizzo'] ?? ''),
                cleanInput($_POST['citta'] ?? ''),
                cleanInput($_POST['provincia'] ?? ''),
                cleanInput($_POST['cap'] ?? ''),
                cleanInput($_POST['email'] ?? ''),
                cleanInput($_POST['telefono'] ?? ''),
                $associazione_id
            ]);
            $message = "Dati anagrafici aggiornati con successo.";
        } elseif (isset($_POST['update_tesseramento'])) {
            $sql = "UPDATE associazioni SET tipo_scadenza_default=?, giorni_notifica_scadenza=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                sanitizeInput($_POST['tipo_scadenza_default']),
                filter_var($_POST['giorni_notifica_scadenza'], FILTER_VALIDATE_INT),
                $associazione_id
            ]);
            $message = "Impostazioni di tesseramento aggiornate.";
        } elseif (isset($_POST['update_template'])) {
            $sql = "UPDATE associazioni SET template_email_scadenza=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([cleanInput($_POST['template_email_scadenza'] ?? ''), $associazione_id]);
            $message = "Template email aggiornato.";
        } elseif (isset($_POST['update_privacy_policy'])) {
            $sql = "UPDATE associazioni SET privacy_policy_html = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$_POST['privacy_policy_html'] ?? '', $associazione_id]);
            $message = "Privacy policy aggiornata con successo.";
            $messageType = "success";
        } elseif (isset($_POST['update_required_fields'])) {
            // Campi configurabili built-in
            $configurable_backend = ['telefono','codice_fiscale','data_nascita','indirizzo','citta','provincia','cap','tipo_socio_id','categoria_socio_id','sede_id','privacy_consenso','note'];
            $configurable_preiscrizione = ['telefono','codice_fiscale','data_nascita','indirizzo','citta','provincia','cap','tipo_socio_id','categoria_socio_id','privacy_consenso'];

            $cfg = ['backend' => [], 'preiscrizione' => []];
            foreach ($configurable_backend as $f) {
                $cfg['backend'][$f] = isset($_POST['required_backend_' . $f]);
            }
            foreach ($configurable_preiscrizione as $f) {
                // privacy_consenso è sempre obbligatorio in preiscrizione (GDPR)
                if ($f === 'privacy_consenso') {
                    $cfg['preiscrizione'][$f] = true;
                } else {
                    $cfg['preiscrizione'][$f] = isset($_POST['required_preiscrizione_' . $f]);
                }
            }

            $stmt = $pdo->prepare("UPDATE associazioni SET campi_obbligatori_config = ? WHERE id = ?");
            $stmt->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $associazione_id]);

            // Aggiorna campi personalizzati
            $stmtCampiList = $pdo->prepare("SELECT id FROM campi_personalizzati WHERE associazione_id = ?");
            $stmtCampiList->execute([$associazione_id]);
            $campiIds = $stmtCampiList->fetchAll(PDO::FETCH_COLUMN);
            $stmtUpdCampo = $pdo->prepare("UPDATE campi_personalizzati SET obbligatorio = ?, obbligatorio_preiscrizione = ? WHERE id = ? AND associazione_id = ?");
            foreach ($campiIds as $cid) {
                $obbBackend = isset($_POST['required_custom_backend_' . $cid]) ? 1 : 0;
                $obbPreisc = isset($_POST['required_custom_preiscrizione_' . $cid]) ? 1 : 0;
                $stmtUpdCampo->execute([$obbBackend, $obbPreisc, $cid, $associazione_id]);
            }

            $message = "Configurazione campi obbligatori aggiornata.";
            $messageType = "success";
        } elseif (isset($_POST['save_tessera_template'])) {
            if (!$has_tessera_templates) {
                $message = "Funzionalità non installata. Esegui la migrazione DB per abilitare i template della tessera.";
                $messageType = "warning";
            } else {
                $tipo_id = $_POST['tipo_id'] !== '' ? $_POST['tipo_id'] : null;
                $contenuto = $_POST['contenuto'] ?? '';
                // Upsert separato per tipo specifico o default
                if ($tipo_id !== null) {
                    $check = $pdo->prepare("SELECT id FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id = ? LIMIT 1");
                    $check->execute([$associazione_id, $tipo_id]);
                } else {
                    $check = $pdo->prepare("SELECT id FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id IS NULL LIMIT 1");
                    $check->execute([$associazione_id]);
                }
                $existing = $check->fetch();
                if ($existing) {
                    $upd = $pdo->prepare("UPDATE tessera_templates SET contenuto = ?, updated_at = NOW() WHERE id = ?");
                    $upd->execute([$contenuto, $existing['id']]);
                    $message = "Template tessera aggiornato.";
                } else {
                    $ins = $pdo->prepare("INSERT INTO tessera_templates (id, associazione_id, tipo_socio_id, titolo, contenuto, attivo) VALUES (?, ?, ?, 'Template Tessera', ?, 1)");
                    $ins->execute([generateUuid(), $associazione_id, $tipo_id, $contenuto]);
                    $message = "Template tessera creato.";
                }
                $messageType = "success";
            }
        } elseif (isset($_POST['update_payment_gateway'])) {
            $stripeEnabled = isset($_POST['pg_stripe_enabled']) ? 1 : 0;
            $stripePubKey = cleanInput($_POST['pg_stripe_publishable_key'] ?? '');
            $stripeSecretRaw = $_POST['pg_stripe_secret_key'] ?? '';
            $stripeWebhookRaw = $_POST['pg_stripe_webhook_secret'] ?? '';
            $paypalEnabled = isset($_POST['pg_paypal_enabled']) ? 1 : 0;
            $paypalClientId = cleanInput($_POST['pg_paypal_client_id'] ?? '');
            $paypalSecretRaw = $_POST['pg_paypal_client_secret'] ?? '';
            $paypalMode = in_array($_POST['pg_paypal_mode'] ?? '', ['sandbox', 'live'], true) ? $_POST['pg_paypal_mode'] : 'sandbox';
            $paypalWebhookId = cleanInput($_POST['pg_paypal_webhook_id'] ?? '');
            $autoAttivazione = isset($_POST['pg_auto_attivazione']) ? 1 : 0;
            $valuta = cleanInput($_POST['pg_valuta'] ?? 'EUR');

            // Load existing to preserve encrypted values if fields left empty
            $existingPg = $pdo->prepare("SELECT * FROM payment_gateway_settings WHERE associazione_id = ? LIMIT 1");
            $existingPg->execute([$associazione_id]);
            $existingRow = $existingPg->fetch();

            $stripeSecretEnc = ($existingRow['stripe_secret_key_encrypted'] ?? '');
            if ($stripeSecretRaw !== '') {
                $stripeSecretEnc = encryptValue($stripeSecretRaw);
            }
            $stripeWebhookEnc = ($existingRow['stripe_webhook_secret_encrypted'] ?? '');
            if ($stripeWebhookRaw !== '') {
                $stripeWebhookEnc = encryptValue($stripeWebhookRaw);
            }
            $paypalSecretEnc = ($existingRow['paypal_client_secret_encrypted'] ?? '');
            if ($paypalSecretRaw !== '') {
                $paypalSecretEnc = encryptValue($paypalSecretRaw);
            }

            if ($existingRow) {
                $stmt = $pdo->prepare("UPDATE payment_gateway_settings SET stripe_enabled=?, stripe_publishable_key=?, stripe_secret_key_encrypted=?, stripe_webhook_secret_encrypted=?, paypal_enabled=?, paypal_client_id=?, paypal_client_secret_encrypted=?, paypal_mode=?, paypal_webhook_id=?, auto_attivazione_pagamento=?, valuta=? WHERE associazione_id=?");
                $stmt->execute([$stripeEnabled, $stripePubKey, $stripeSecretEnc, $stripeWebhookEnc, $paypalEnabled, $paypalClientId, $paypalSecretEnc, $paypalMode, $paypalWebhookId, $autoAttivazione, $valuta, $associazione_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO payment_gateway_settings (id, associazione_id, stripe_enabled, stripe_publishable_key, stripe_secret_key_encrypted, stripe_webhook_secret_encrypted, paypal_enabled, paypal_client_id, paypal_client_secret_encrypted, paypal_mode, paypal_webhook_id, auto_attivazione_pagamento, valuta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([generateUuid(), $associazione_id, $stripeEnabled, $stripePubKey, $stripeSecretEnc, $stripeWebhookEnc, $paypalEnabled, $paypalClientId, $paypalSecretEnc, $paypalMode, $paypalWebhookId, $autoAttivazione, $valuta]);
            }
            $message = "Impostazioni pagamenti online aggiornate.";
            $messageType = "success";
        }
        } catch (PDOException $e) {
            error_log('configurazioni.php PDOException: ' . $e->getMessage());
            $message = "Errore durante l'aggiornamento. Riprova più tardi.";
            $messageType = "danger";
        }
    }
}

// Recupero dati associazione
$stmt = $pdo->prepare("SELECT * FROM associazioni WHERE id = ?");
$stmt->execute([$associazione_id]);
$associazione = $stmt->fetch();

// Tipi socio per selezione template tessera
$tipi_stmt = $pdo->prepare("SELECT id, nome FROM tipi_socio WHERE associazione_id = ? ORDER BY nome");
$tipi_stmt->execute([$associazione_id]);
$tipi_socio = $tipi_stmt->fetchAll();

// Tipo selezionato (GET)
$selected_tipo_id = $_GET['tipo_id'] ?? '';

// Template corrente per il tipo selezionato o default (solo se tabella presente)
$template_corrente = '';
if ($has_tessera_templates) {
    if ($selected_tipo_id !== '') {
        $tpl_stmt = $pdo->prepare("SELECT contenuto FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id = ? LIMIT 1");
        $tpl_stmt->execute([$associazione_id, $selected_tipo_id]);
    } else {
        $tpl_stmt = $pdo->prepare("SELECT contenuto FROM tessera_templates WHERE associazione_id = ? AND tipo_socio_id IS NULL LIMIT 1");
        $tpl_stmt->execute([$associazione_id]);
    }
    $template_corrente = ($row = $tpl_stmt->fetch()) ? $row['contenuto'] : '';
}

// Carica configurazione campi obbligatori
$required_config = loadRequiredFieldsConfig($pdo, $associazione_id);

// Carica configurazione payment gateway
$pgConfig = null;
try {
    $pgStmt = $pdo->prepare("SELECT * FROM payment_gateway_settings WHERE associazione_id = ? LIMIT 1");
    $pgStmt->execute([$associazione_id]);
    $pgConfig = $pgStmt->fetch() ?: null;
} catch (PDOException $e) {
    // table may not exist yet
}

// Carica campi personalizzati per la card campi obbligatori
$stmt_custom_fields = $pdo->prepare("SELECT id, nome_campo, obbligatorio, obbligatorio_preiscrizione FROM campi_personalizzati WHERE associazione_id = ? ORDER BY ordine, nome_campo");
$stmt_custom_fields->execute([$associazione_id]);
$custom_fields_config = $stmt_custom_fields->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Impostazioni Associazione</h1>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>


<div class="row">
    <div class="col-lg-8">
        <!-- Card Sedi -->
        <div class="card mb-4" id="sedi">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Sedi</h5>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#sedeModal">Nuova Sede</button>
            </div>
            <div class="card-body">
                <?php 
                $sedi = [];
                $stmt_sedi = $pdo->prepare("SELECT * FROM sedi WHERE associazione_id = ? ORDER BY nome");
                $stmt_sedi->execute([$associazione_id]);
                $sedi = $stmt_sedi->fetchAll();
                ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Nome</th><th>Indirizzo</th><th>Città</th><th>Provincia</th><th>CAP</th><th>Responsabile</th><th class="text-end">Azioni</th></tr></thead>
                        <tbody>
                        <?php foreach ($sedi as $s): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($s['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['indirizzo'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['citta'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['provincia'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['cap'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($s['responsabile'] ?? ''); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sedeModal" data-id="<?php echo $s['id']; ?>" data-nome="<?php echo htmlspecialchars($s['nome']); ?>" data-indirizzo="<?php echo htmlspecialchars($s['indirizzo']); ?>" data-citta="<?php echo htmlspecialchars($s['citta']); ?>" data-provincia="<?php echo htmlspecialchars($s['provincia']); ?>" data-cap="<?php echo htmlspecialchars($s['cap']); ?>" data-responsabile="<?php echo htmlspecialchars($s['responsabile']); ?>"><i class="bi bi-pencil"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa sede?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="sedi_action" value="delete">
                                        <input type="hidden" name="delete_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Card Dati Anagrafici -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Dati Anagrafici</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3"><label>Nome Associazione</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($associazione['nome'] ?? ''); ?>"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Partita IVA</label><input type="text" name="partita_iva" class="form-control" value="<?php echo htmlspecialchars($associazione['partita_iva'] ?? ''); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Codice Fiscale</label><input type="text" name="codice_fiscale" class="form-control" value="<?php echo htmlspecialchars($associazione['codice_fiscale'] ?? ''); ?>"></div>
                    </div>
                    <div class="mb-3"><label>Indirizzo</label><input type="text" name="indirizzo" class="form-control" value="<?php echo htmlspecialchars($associazione['indirizzo'] ?? ''); ?>"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label>Città</label><input type="text" name="citta" class="form-control" value="<?php echo htmlspecialchars($associazione['citta'] ?? ''); ?>"></div>
                        <div class="col-md-6 col-lg-3 mb-3"><label>Provincia</label><input type="text" name="provincia" class="form-control" value="<?php echo htmlspecialchars($associazione['provincia'] ?? ''); ?>"></div>
                        <div class="col-md-6 col-lg-3 mb-3"><label>CAP</label><input type="text" name="cap" class="form-control" value="<?php echo htmlspecialchars($associazione['cap'] ?? ''); ?>"></div>
                    </div>
                     <div class="row">
                        <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($associazione['email'] ?? ''); ?>"></div>
                        <div class="col-md-6 mb-3"><label>Telefono</label><input type="tel" name="telefono" class="form-control" value="<?php echo htmlspecialchars($associazione['telefono'] ?? ''); ?>"></div>
                    </div>
                    <button type="submit" name="update_info" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Salva Dati Anagrafici</button>
                </form>
            </div>
        </div>

        <!-- Card Impostazioni Tesseramento -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Impostazioni Tesseramento e Quote</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label>Tipo Scadenza Predefinita</label>
                        <select name="tipo_scadenza_default" class="form-select">
                            <option value="solare" <?php echo ($associazione['tipo_scadenza_default'] == 'solare') ? 'selected' : ''; ?>>Anno Solare (31 Dicembre)</option>
                            <option value="annuale" <?php echo ($associazione['tipo_scadenza_default'] == 'annuale') ? 'selected' : ''; ?>>Annuale (365 giorni da iscrizione)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label>Giorni Preavviso Scadenza</label>
                        <input type="number" name="giorni_notifica_scadenza" class="form-control" value="<?php echo htmlspecialchars($associazione['giorni_notifica_scadenza'] ?? '30'); ?>">
                        <div class="form-text">Quanti giorni prima della scadenza inviare la notifica.</div>
                    </div>
                    <button type="submit" name="update_tesseramento" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Impostazioni Tesseramento</button>
                </form>
            </div>
        </div>

         <!-- Card Template Email -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Template Email Scadenza</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="form-text mb-2">Placeholder disponibili: {NOME_SOCIO}, {COGNOME_SOCIO}, {DATA_SCADENZA}, {IMPORTO_QUOTA}</div>
                    <textarea name="template_email_scadenza" class="form-control" rows="10"><?php echo htmlspecialchars($associazione['template_email_scadenza'] ?? 'Ciao {NOME_SOCIO}, ti ricordiamo che la tua quota scade il {DATA_SCADENZA}.'); ?></textarea>
                    <button type="submit" name="update_template" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Template</button>
                </form>
            </div>
        </div>

        <!-- Card Privacy Policy -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Privacy Policy</h5></div>
            <div class="card-body">
                <p class="form-text mb-2">Scrivi l'informativa sulla privacy per la tua associazione. Verr&agrave; mostrata ai potenziali soci nel form di pre-iscrizione.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <textarea id="privacy_editor" name="privacy_policy_html"><?php echo htmlspecialchars($associazione['privacy_policy_html'] ?? ''); ?></textarea>
                    <button type="submit" name="update_privacy_policy" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Privacy Policy</button>
                </form>
            </div>
        </div>

        <!-- Card Template Tessera per Tipo Socio -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Template Tessera (per Tipo Socio)</h5></div>
            <div class="card-body">
                <?php if (!$has_tessera_templates): ?>
                    <div class="alert alert-warning">
                        Funzionalità non installata. Esegui la migrazione del database per abilitare i template tessera.
                        <a href="migrate.php?confirm=yes" target="_blank" class="alert-link">Esegui migrazione</a>
                    </div>
                <?php else: ?>
                <form method="GET" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="page" value="configurazioni">
                    <div class="col-md-6">
                        <label class="form-label">Tipo Socio</label>
                        <select name="tipo_id" class="form-select">
                            <option value="">Default per tutti</option>
                            <?php foreach ($tipi_socio as $tipo): ?>
                                <option value="<?php echo htmlspecialchars($tipo['id']); ?>" <?php echo ($selected_tipo_id === $tipo['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($tipo['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-folder2-open me-1"></i>Carica</button>
                    </div>
                </form>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="save_tessera_template" value="1">
                    <input type="hidden" name="tipo_id" value="<?php echo htmlspecialchars($selected_tipo_id); ?>">
                    <div class="form-text mb-2">Placeholder disponibili: {ASSOCIAZIONE_NOME}, {ASSOCIAZIONE_CODICE_FISCALE}, {ASSOCIAZIONE_INDIRIZZO}, {NOME}, {COGNOME}, {NOME_COMPLETO}, {NUMERO_SOCIO}, {TIPO_SOCIO}, {CATEGORIA_SOCIO}, {NUMERO_TESSERA}, {ANNO_VALIDITA}, {DATA_EMISSIONE}, {DATA_SCADENZA}</div>
                    <textarea name="contenuto" class="form-control" rows="10"><?php echo htmlspecialchars($template_corrente ?: "Il/La sottoscritto/a {NOME_COMPLETO}, tessera n. {NUMERO_TESSERA}, è iscritto/a all'associazione {ASSOCIAZIONE_NOME} per l'anno {ANNO_VALIDITA}."); ?></textarea>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Template Tessera</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Campi Obbligatori -->
        <div class="card mb-4" id="campi-obbligatori">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-ui-checks me-2"></i>Campi Obbligatori</h5></div>
            <div class="card-body">
                <p class="form-text mb-3">Configura quali campi sono obbligatori nel pannello di gestione soci (Backend) e nel form pubblico di pre-iscrizione. Nome, Cognome ed Email sono sempre obbligatori.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="update_required_fields" value="1">

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th class="text-center" style="width:120px">Backend</th>
                                    <th class="text-center" style="width:120px">Pre-iscrizione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-light">
                                    <td>Nome</td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                </tr>
                                <tr class="table-light">
                                    <td>Cognome</td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                </tr>
                                <tr class="table-light">
                                    <td>Email</td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                    <td class="text-center"><span class="badge bg-secondary">Sempre</span></td>
                                </tr>
                                <?php
                                $configurable_fields = [
                                    'data_nascita' => 'Data di Nascita',
                                    'telefono' => 'Telefono',
                                    'codice_fiscale' => 'Codice Fiscale',
                                    'indirizzo' => 'Indirizzo',
                                    'citta' => 'Città',
                                    'provincia' => 'Provincia',
                                    'cap' => 'CAP',
                                    'tipo_socio_id' => 'Tipo Socio',
                                    'categoria_socio_id' => 'Categoria Socio',
                                    'sede_id' => 'Sede',
                                    'privacy_consenso' => 'Consenso Privacy',
                                    'note' => 'Note',
                                ];
                                $backend_only = ['sede_id', 'note'];
                                foreach ($configurable_fields as $field => $label): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($label); ?></td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input" name="required_backend_<?php echo $field; ?>" value="1" <?php echo !empty($required_config['backend'][$field]) ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($field === 'privacy_consenso'): ?>
                                            <span class="badge bg-info text-dark">GDPR</span>
                                            <input type="hidden" name="required_preiscrizione_privacy_consenso" value="1">
                                        <?php elseif (in_array($field, $backend_only, true)): ?>
                                            <span class="text-muted">N/A</span>
                                        <?php else: ?>
                                            <input type="checkbox" class="form-check-input" name="required_preiscrizione_<?php echo $field; ?>" value="1" <?php echo !empty($required_config['preiscrizione'][$field]) ? 'checked' : ''; ?>>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($custom_fields_config)): ?>
                    <h6 class="text-muted mt-4 mb-2">Campi personalizzati</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Campo</th>
                                    <th class="text-center" style="width:120px">Backend</th>
                                    <th class="text-center" style="width:120px">Pre-iscrizione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($custom_fields_config as $cf): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cf['nome_campo']); ?></td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input" name="required_custom_backend_<?php echo htmlspecialchars($cf['id']); ?>" value="1" <?php echo !empty($cf['obbligatorio']) ? 'checked' : ''; ?>>
                                    </td>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input" name="required_custom_preiscrizione_<?php echo htmlspecialchars($cf['id']); ?>" value="1" <?php echo !empty($cf['obbligatorio_preiscrizione']) ? 'checked' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-floppy me-1"></i>Salva Configurazione Campi</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Logo Associazione</h5></div>
            <div class="card-body">
                <?php if (!empty($associazione['logo_url'])): ?>
                    <div class="mb-2"><img src="<?php echo htmlspecialchars($associazione['logo_url']); ?>" alt="Logo" style="max-width: 100%; height: auto; border:1px solid #ddd; padding:6px; border-radius:6px;"></div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <div class="mb-3">
                        <label class="form-label">Carica nuovo logo (PNG/JPG, max 2MB)</label>
                        <input type="file" name="logo_file" class="form-control" accept="image/png,image/jpeg">
                    </div>
                    <button type="submit" name="upload_logo" class="btn btn-outline-primary">Carica Logo</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">Altre Configurazioni</h5></div>
            <div class="list-group list-group-flush">
                <a href="index.php?page=sezioni" class="list-group-item list-group-item-action">Gestione Sedi</a>
                <a href="index.php?page=tipi-socio" class="list-group-item list-group-item-action">Gestione Tipi Socio</a>
                <a href="index.php?page=categorie-socio" class="list-group-item list-group-item-action">Gestione Categorie Socio</a>
                <a href="index.php?page=amministratori" class="list-group-item list-group-item-action">Gestione Utenti e Permessi</a>
            </div>
        </div>

        <!-- Card Pagamenti Online -->
        <div class="card mb-4" id="pagamenti-online">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>Pagamenti Online</h5></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="update_payment_gateway" value="1">

                    <h6 class="text-muted mb-2"><i class="bi bi-stripe me-1"></i>Stripe</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="pgStripeEnabled" name="pg_stripe_enabled" value="1" <?php echo !empty($pgConfig['stripe_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pgStripeEnabled">Abilita Stripe</label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Publishable Key</label>
                        <input type="text" name="pg_stripe_publishable_key" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pgConfig['stripe_publishable_key'] ?? ''); ?>" placeholder="pk_...">
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Secret Key</label>
                        <input type="password" name="pg_stripe_secret_key" class="form-control form-control-sm" value="" placeholder="<?php echo !empty($pgConfig['stripe_secret_key_encrypted']) ? '••••••••' : 'sk_...'; ?>">
                        <div class="form-text">Lascia vuoto per mantenere il valore attuale.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Webhook Secret</label>
                        <input type="password" name="pg_stripe_webhook_secret" class="form-control form-control-sm" value="" placeholder="<?php echo !empty($pgConfig['stripe_webhook_secret_encrypted']) ? '••••••••' : 'whsec_...'; ?>">
                    </div>

                    <hr>
                    <h6 class="text-muted mb-2"><i class="bi bi-paypal me-1"></i>PayPal</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="pgPaypalEnabled" name="pg_paypal_enabled" value="1" <?php echo !empty($pgConfig['paypal_enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pgPaypalEnabled">Abilita PayPal</label>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Client ID</label>
                        <input type="text" name="pg_paypal_client_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pgConfig['paypal_client_id'] ?? ''); ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Client Secret</label>
                        <input type="password" name="pg_paypal_client_secret" class="form-control form-control-sm" value="" placeholder="<?php echo !empty($pgConfig['paypal_client_secret_encrypted']) ? '••••••••' : ''; ?>">
                        <div class="form-text">Lascia vuoto per mantenere il valore attuale.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Modalità</label>
                        <select name="pg_paypal_mode" class="form-select form-select-sm">
                            <option value="sandbox" <?php echo ($pgConfig['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : ''; ?>>Sandbox (test)</option>
                            <option value="live" <?php echo ($pgConfig['paypal_mode'] ?? '') === 'live' ? 'selected' : ''; ?>>Live (produzione)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Webhook ID</label>
                        <input type="text" name="pg_paypal_webhook_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pgConfig['paypal_webhook_id'] ?? ''); ?>">
                    </div>

                    <hr>
                    <h6 class="text-muted mb-2"><i class="bi bi-gear me-1"></i>Impostazioni</h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="pgAutoAttivazione" name="pg_auto_attivazione" value="1" <?php echo ($pgConfig === null || !empty($pgConfig['auto_attivazione_pagamento'])) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="pgAutoAttivazione">Attivazione automatica socio dopo pagamento online</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Valuta</label>
                        <input type="text" name="pg_valuta" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pgConfig['valuta'] ?? 'EUR'); ?>" maxlength="3" style="max-width:80px;">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-floppy me-1"></i>Salva Impostazioni Pagamenti</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- TinyMCE per Privacy Policy -->
<script src="assets/vendor/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#privacy_editor',
    language: 'it',
    height: 400,
    plugins: 'lists link table code',
    toolbar: 'undo redo | bold italic underline | bullist numlist | link table | code',
    menubar: false,
    branding: false,
    promotion: false,
    content_css: false,
    skin: 'oxide',
    license_key: 'gpl'
});
</script>
