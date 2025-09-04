<?php
// pages/soci.php - v2.2 (SaaS con Gruppi Dinamici)

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

$is_super_admin = ($_SESSION['user_role'] ?? '') === 'super_admin';
$associazione_id = $_SESSION['associazione_id'] ?? null;
$assoc_filter = $is_super_admin ? ($_GET['assoc_id'] ?? 'all') : ($associazione_id ?? 'all');
$message = $_GET['message'] ?? '';
$messageType = $_GET['messageType'] ?? '';

// Configurazioni associazione utili ai filtri (tipo scadenza, preavviso)
$lists_assoc_id = $is_super_admin ? ($assoc_filter !== 'all' ? $assoc_filter : null) : $associazione_id;

// Configurazione di default
$assoc_cfg = ['tipo_scadenza_default' => 'solare', 'giorni_notifica_scadenza' => 30];
$campi_personalizzati = $tags_disponibili = $tipi_socio = $categorie_socio = $sedi = [];

if ($lists_assoc_id) {
    // Carica configurazione associazione specifica
    $stmt_assoc_cfg = $pdo->prepare("SELECT tipo_scadenza_default, giorni_notifica_scadenza FROM associazioni WHERE id = ? LIMIT 1");
    $stmt_assoc_cfg->execute([$lists_assoc_id]);
    $assoc_cfg = $stmt_assoc_cfg->fetch() ?: $assoc_cfg;

    // Recupero campi personalizzati e tags per l'associazione
    $stmt_campi = $pdo->prepare("SELECT * FROM campi_personalizzati WHERE associazione_id = ? ORDER BY nome_campo");
    $stmt_campi->execute([$lists_assoc_id]);
    $campi_personalizzati = $stmt_campi->fetchAll();

    $stmt_tags = $pdo->prepare("SELECT * FROM tags WHERE associazione_id = ? ORDER BY nome_tag");
    $stmt_tags->execute([$lists_assoc_id]);
    $tags_disponibili = $stmt_tags->fetchAll();

    // Recupero dati aggiuntivi per i form
    $stmt_tipi = $pdo->prepare("SELECT * FROM tipi_socio WHERE associazione_id = ? ORDER BY nome");
    $stmt_tipi->execute([$lists_assoc_id]);
    $tipi_socio = $stmt_tipi->fetchAll();

    $stmt_categorie = $pdo->prepare("SELECT * FROM categorie_socio WHERE associazione_id = ? ORDER BY nome");
    $stmt_categorie->execute([$lists_assoc_id]);
    $categorie_socio = $stmt_categorie->fetchAll();

    $stmt_sedi = $pdo->prepare("SELECT * FROM sedi WHERE associazione_id = ? ORDER BY nome");
    $stmt_sedi->execute([$lists_assoc_id]);
    $sedi = $stmt_sedi->fetchAll();
} elseif ($is_super_admin && $assoc_filter === 'all') {
    // Per super_admin che visualizza "tutte" le associazioni, carica dati da tutte le associazioni
    try {
        $stmt_tipi = $pdo->prepare("SELECT DISTINCT ts.*, a.nome as associazione_nome FROM tipi_socio ts LEFT JOIN associazioni a ON ts.associazione_id = a.id ORDER BY a.nome, ts.nome");
        $stmt_tipi->execute();
        $tipi_socio = $stmt_tipi->fetchAll();

        $stmt_categorie = $pdo->prepare("SELECT DISTINCT cs.*, a.nome as associazione_nome FROM categorie_socio cs LEFT JOIN associazioni a ON cs.associazione_id = a.id ORDER BY a.nome, cs.nome");
        $stmt_categorie->execute();
        $categorie_socio = $stmt_categorie->fetchAll();

        $stmt_sedi = $pdo->prepare("SELECT DISTINCT s.*, a.nome as associazione_nome FROM sedi s LEFT JOIN associazioni a ON s.associazione_id = a.id ORDER BY a.nome, s.nome");
        $stmt_sedi->execute();
        $sedi = $stmt_sedi->fetchAll();
    } catch (Exception $e) {
        error_log('Error loading multi-association data: ' . $e->getMessage());
    }
}

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifica CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        $pdo->beginTransaction();
        try {
        // Eliminazione preset filtri
        if (isset($_POST['delete_filter_preset']) && !empty($_POST['preset_id_delete'])) {
            $presetId = $_POST['preset_id_delete'];
            $stmt_del = $pdo->prepare("DELETE FROM saved_filters WHERE id = ? AND associazione_id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt_del->execute([$presetId, $associazione_id, $_SESSION['user_id'] ?? null]);
            $message = "Preset eliminato";
            $messageType = "success";
            $pdo->commit();
            header('Location: index.php?page=soci');
            exit;
        }

        // Salvataggio preset filtri
        if (isset($_POST['save_filter_preset']) && !empty($_POST['preset_name'])) {
            $presetName = trim($_POST['preset_name']);
            $params = [
                'search' => $_POST['search'] ?? '',
                'status' => $_POST['status'] ?? 'all',
                'sede_id' => $_POST['sede_id'] ?? 'all',
                'categoria_ids' => $_POST['categoria_ids'] ?? [],
                'tessera_template' => $_POST['tessera_template'] ?? 'all',
                'tessera_scadenza' => $_POST['tessera_scadenza'] ?? 'all',
                'has_tessera' => $_POST['has_tessera'] ?? 'all',
                'tessera_stato' => $_POST['tessera_stato'] ?? 'attive',
                'gruppo_id' => $_POST['gruppo_id'] ?? 'all',
            ];
            $json = json_encode($params, JSON_UNESCAPED_UNICODE);
            $preset_id = generateUuid();
            $stmt_p = $pdo->prepare("INSERT INTO saved_filters (id, associazione_id, user_id, scope, name, params_json) VALUES (?, ?, ?, 'soci', ?, ?) ON DUPLICATE KEY UPDATE params_json = VALUES(params_json), updated_at = NOW()");
            $stmt_p->execute([$preset_id, $associazione_id, $_SESSION['user_id'] ?? null, $presetName, $json]);
            $message = "Preset salvato";
            $messageType = "success";
            $pdo->commit();
            header('Location: index.php?page=soci');
            exit;
        }
        if (isset($_POST['delete_id'])) {
            $stmt = $pdo->prepare("DELETE FROM soci WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$_POST['delete_id'], $associazione_id]);
            $message = "Socio eliminato con successo.";
        } else {
            $id = $_POST['id'] ?? null;
            $socio_data = [
                'nome' => cleanInput($_POST['nome'] ?? ''),
                'cognome' => cleanInput($_POST['cognome'] ?? ''),
                'email' => cleanInput($_POST['email'] ?? ''),
                'numero_socio' => cleanInput($_POST['numero_socio'] ?? ''),
                'data_nascita' => $_POST['data_nascita'] ?? null,
                'data_iscrizione' => $_POST['data_iscrizione'] ?? null,
                'stato' => cleanInput($_POST['stato'] ?? ''),
                // normalizza il CF in maiuscolo per confronti univoci
                'codice_fiscale' => strtoupper(cleanInput($_POST['codice_fiscale'] ?? '')),
                'telefono' => cleanInput($_POST['telefono'] ?? ''),
                'indirizzo' => cleanInput($_POST['indirizzo'] ?? ''),
                'citta' => cleanInput($_POST['citta'] ?? ''),
                'provincia' => cleanInput($_POST['provincia'] ?? ''),
                'cap' => cleanInput($_POST['cap'] ?? ''),
                'note' => cleanInput($_POST['note'] ?? ''),
                'privacy_consenso' => isset($_POST['privacy_consenso']) ? 1 : 0,
                'tipo_socio_id' => !empty($_POST['tipo_socio_id']) ? $_POST['tipo_socio_id'] : null,
                'categoria_socio_id' => !empty($_POST['categoria_socio_id']) ? $_POST['categoria_socio_id'] : null,
                'sede_id' => !empty($_POST['sede_id']) ? $_POST['sede_id'] : null
            ];

            // Verifica unicità Codice Fiscale nell'associazione
            if (!empty($socio_data['codice_fiscale'])) {
                $cfSql = "SELECT COUNT(*) FROM soci WHERE associazione_id = ? AND UPPER(codice_fiscale) = ?";
                $cfParams = [$associazione_id, strtoupper($socio_data['codice_fiscale'])];
                if ($id) { $cfSql .= " AND id <> ?"; $cfParams[] = $id; }
                $stmtCf = $pdo->prepare($cfSql);
                $stmtCf->execute($cfParams);
                if ((int)$stmtCf->fetchColumn() > 0) {
                    $pdo->rollBack();
                    $message = "Esiste già un socio con lo stesso Codice Fiscale in questa associazione.";
                    $messageType = "danger";
                    // interrompi gestione POST senza eseguire altro
                    goto SKIP_POST_SAVE;
                }
            }

            if ($id) {
                $sql = "UPDATE soci SET nome=:nome, cognome=:cognome, email=:email, numero_socio=:numero_socio, data_nascita=:data_nascita, data_iscrizione=:data_iscrizione, stato=:stato, codice_fiscale=:codice_fiscale, telefono=:telefono, indirizzo=:indirizzo, citta=:citta, provincia=:provincia, cap=:cap, note=:note, privacy_consenso=:privacy_consenso, tipo_socio_id=:tipo_socio_id, categoria_socio_id=:categoria_socio_id, sede_id=:sede_id WHERE id=:id AND associazione_id=:associazione_id";
                $socio_data['id'] = $id;
                $socio_data['associazione_id'] = $associazione_id;
                $socio_id = $id;
                logSocioActivity($pdo, $associazione_id, $socio_id, 'Modifica Anagrafica', "L'anagrafica del socio è stata aggiornata.");
            } else {
                $socio_id = generateUuid(); // Genera un nuovo UUID per il socio
                
                // Genera numero socio progressivo se non fornito
                if (empty($socio_data['numero_socio'])) {
                    $stmt_count = $pdo->prepare("SELECT MAX(CAST(numero_socio AS UNSIGNED)) as max_num FROM soci WHERE associazione_id = ?");
                    $stmt_count->execute([$associazione_id]);
                    $max_num = $stmt_count->fetch()['max_num'] ?? 0;
                    $socio_data['numero_socio'] = str_pad($max_num + 1, 3, '0', STR_PAD_LEFT);
                }
                
                $sql = "INSERT INTO soci (id, associazione_id, nome, cognome, email, numero_socio, data_nascita, data_iscrizione, stato, codice_fiscale, telefono, indirizzo, citta, provincia, cap, note, privacy_consenso, tipo_socio_id, categoria_socio_id, sede_id) VALUES (:id, :associazione_id, :nome, :cognome, :email, :numero_socio, :data_nascita, :data_iscrizione, :stato, :codice_fiscale, :telefono, :indirizzo, :citta, :provincia, :cap, :note, :privacy_consenso, :tipo_socio_id, :categoria_socio_id, :sede_id)";
                $socio_data['id'] = $socio_id;
                $socio_data['associazione_id'] = $associazione_id;
                logSocioActivity($pdo, $associazione_id, $socio_id, 'Iscrizione', "Nuovo socio iscritto con n. {$socio_data['numero_socio']}");
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($socio_data);

            // Se è una creazione, crea una tessera per l'anno corrente se non presente
            if (!$id) {
                $anno_corrente = date('Y');
                $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM tessere WHERE associazione_id = ? AND socio_id = ? AND anno_validita = ?");
                $stmtChk->execute([$associazione_id, $socio_id, $anno_corrente]);
                if ((int)$stmtChk->fetchColumn() === 0) {
                    // Recupera tipo scadenza predefinito
                    $stmtCfg = $pdo->prepare("SELECT tipo_scadenza_default FROM associazioni WHERE id = ? LIMIT 1");
                    $stmtCfg->execute([$associazione_id]);
                    $tipo_scadenza = $stmtCfg->fetchColumn() ?: 'solare';
                    // Calcola numero tessera progressivo per anno
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) as cnt FROM tessere WHERE associazione_id = ? AND anno_validita = ?");
                    $stmtCount->execute([$associazione_id, $anno_corrente]);
                    $count = (int)($stmtCount->fetch()['cnt'] ?? 0) + 1;
                    $numero_tessera = $anno_corrente . str_pad($count, 4, '0', STR_PAD_LEFT);
                    $data_emissione = date('Y-m-d');
                    $data_scadenza = ($tipo_scadenza === 'annuale') ? date('Y-m-d', strtotime($data_emissione . ' +1 year')) : ($anno_corrente . '-12-31');
                    $stmtInsT = $pdo->prepare("INSERT INTO tessere (id, associazione_id, socio_id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato, tipo_scadenza) VALUES (?, ?, ?, ?, ?, ?, ?, 'Attiva', ?)");
                    $new_tessera_id = generateUuid();
                    $stmtInsT->execute([$new_tessera_id, $associazione_id, $socio_id, $numero_tessera, $anno_corrente, $data_emissione, $data_scadenza, $tipo_scadenza]);

                    // Genera e salva automaticamente il PDF tessera in uploads/documents
                    try {
                        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
                        if (file_exists($autoloadPath)) {
                            require_once $autoloadPath;
                            // Recupera dati per il PDF (socio + associazione + eventuale testo tessera da categoria)
                            $stmtInfo = $pdo->prepare("SELECT s.*, a.nome AS associazione_nome, a.logo_url, cs.testo_tessera, cs.nome AS categoria_nome, ts.nome AS tipo_nome FROM soci s LEFT JOIN associazioni a ON a.id = s.associazione_id LEFT JOIN categorie_socio cs ON cs.id = s.categoria_socio_id LEFT JOIN tipi_socio ts ON ts.id = s.tipo_socio_id WHERE s.id = ? AND s.associazione_id = ?");
                            $stmtInfo->execute([$socio_id, $associazione_id]);
                            $info = $stmtInfo->fetch() ?: [];

                            $nome_completo = htmlspecialchars(trim(($info['cognome'] ?? '') . ' ' . ($info['nome'] ?? '')));
                            $categoria_tipo = htmlspecialchars(trim(($info['categoria_nome'] ?? '') . (!empty($info['tipo_nome']) ? ' - ' . $info['tipo_nome'] : '')));
                            $testo_tessera = !empty($info['testo_tessera']) ? nl2br(htmlspecialchars($info['testo_tessera'])) : '';
                            $associazione_nome = htmlspecialchars($info['associazione_nome'] ?? '');
                            $valid_until = date('d/m/Y', strtotime($data_scadenza));

                            $logo_img = '';
                            if (!empty($info['logo_url'])) {
                                $logoSrc = $info['logo_url'];
                                if (preg_match('~^https?://~i', $logoSrc)) {
                                    $logo_img = '<img src="' . htmlspecialchars($logoSrc) . '" style="height:42px; width:auto;">';
                                } else {
                                    $path = realpath(APP_ROOT . '/' . ltrim($logoSrc, '/'));
                                    if ($path && is_file($path)) {
                                        $mime = mime_content_type($path);
                                        $dataImg = base64_encode(file_get_contents($path));
                                        $logo_img = '<img src="data:' . htmlspecialchars($mime) . ';base64,' . $dataImg . '" style="height:42px; width:auto;">';
                                    }
                                }
                            }

                            $html = "<!DOCTYPE html><html lang='it'><head><meta charset='UTF-8'><style>
                                @page { size: A4; margin: 18mm; }
                                body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #222; font-size: 12pt; }
                                .header { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #003366; padding-bottom:8px; margin-bottom:16px; }
                                .brand { font-weight:bold; color:#003366; font-size:14pt; }
                                .grid { width:100%; border-collapse:collapse; margin-bottom:12px; }
                                .grid td { padding:6px 8px; vertical-align:top; }
                                .label { color:#444; font-weight:bold; width:35%; }
                                .box { border:1px solid #ccc; border-radius:6px; padding:10px; background:#fafafa; }
                            </style></head><body>
                            <div class='header'><div class='brand'>$associazione_nome</div><div>$logo_img</div></div>
                            <h2 style='margin:0 0 10px 0;'>Tessera Associativa $anno_corrente</h2>
                            <table class='grid'>
                                <tr><td class='label'>Nome e Cognome</td><td>$nome_completo</td></tr>
                                <tr><td class='label'>Numero Socio</td><td>" . htmlspecialchars($socio_data['numero_socio']) . "</td></tr>
                                <tr><td class='label'>Categoria / Tipo</td><td>$categoria_tipo</td></tr>
                                <tr><td class='label'>Numero Tessera</td><td>" . htmlspecialchars($numero_tessera) . "</td></tr>
                                <tr><td class='label'>Valida fino al</td><td>$valid_until</td></tr>
                            </table>";
                            if ($testo_tessera) { $html .= "<div class='box'>$testo_tessera</div>"; }
                            $html .= "</body></html>";

                            $options = new Dompdf\Options();
                            $options->set('isRemoteEnabled', true);
                            $options->set('defaultFont', 'DejaVu Sans');
                            $dompdf = new Dompdf\Dompdf($options);
                            $dompdf->loadHtml($html, 'UTF-8');
                            $dompdf->setPaper('A4', 'portrait');
                            $dompdf->render();

                            // Assicura directory
                            $docsDir = APP_ROOT . '/uploads/documents';
                            if (!is_dir($docsDir)) { @mkdir($docsDir, 0755, true); }
                            $safeName = 'tessera_' . preg_replace('/[^A-Za-z0-9_-]/','', ($socio_data['numero_socio'] ?? 'socio')) . '_' . $anno_corrente . '.pdf';
                            $absPath = $docsDir . '/' . $safeName;
                            file_put_contents($absPath, $dompdf->output());

                            // Percorso per link pubblico (relativo)
                            $publicPath = 'uploads/documents/' . $safeName;

                            // Registra in documenti
                            $doc_id = generateUuid();
                            $stmtDoc = $pdo->prepare("INSERT INTO documenti (id, associazione_id, nome_file, percorso_file, descrizione, categoria, caricato_da) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $descr = 'Tessera di ' . ($info['cognome'] ?? '') . ' ' . ($info['nome'] ?? '') . ' - ' . $anno_corrente;
                            $stmtDoc->execute([$doc_id, $associazione_id, $safeName, $publicPath, $descr, 'Tessere', $_SESSION['user_id'] ?? null]);

                            // Log attività
                            $details = json_encode(['tessera_id' => $new_tessera_id, 'numero_tessera' => $numero_tessera, 'documento_id' => $doc_id, 'file' => $publicPath]);
                            logSocioActivity($pdo, $associazione_id, $socio_id, 'Generazione Tessera', 'Generata tessera e PDF salvato.', $details);
                        }
                    } catch (Throwable $e) {
                        // Non bloccare la creazione del socio in caso di errore PDF
                        error_log('Errore generazione PDF tessera: ' . $e->getMessage());
                    }
                }
            }

            // Salva campi personalizzati
            if (!empty($campi_personalizzati)) {
                $stmt_val = $pdo->prepare("INSERT INTO valori_campi_personalizzati (id, socio_id, campo_id, valore) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)");
                foreach ($campi_personalizzati as $campo) {
                    if (isset($_POST['custom_field'][$campo['id']])) {
                        $valore = cleanInput($_POST['custom_field'][$campo['id']]);
                        $stmt_val->execute([generateUuid(), $socio_id, $campo['id'], $valore]);
                    }
                }
            }

            // Salva tags
            $stmt_del_tags = $pdo->prepare("DELETE FROM socio_tags WHERE socio_id = ?");
            $stmt_del_tags->execute([$socio_id]);
            if (!empty($_POST['tags'])) {
                $stmt_ins_tag = $pdo->prepare("INSERT INTO socio_tags (socio_id, tag_id) VALUES (?, ?)");
                foreach ($_POST['tags'] as $tag_id) {
                    $stmt_ins_tag->execute([$socio_id, cleanInput($tag_id)]);
                }
            }
            $message = $id ? "Socio aggiornato." : "Socio creato.";
            $messageType = "success";
            
            // Always redirect after successful save to avoid form resubmission
            header('Location: index.php?page=soci');
            exit;
            

                        $ins = $pdo->prepare("INSERT INTO tessere (id, socio_id, associazione_id, numero_tessera, anno_validita, data_emissione, data_scadenza, tipo_scadenza, stato, template_tessera) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Attiva', ?)");
                        $ins->execute([generateUuid(), $socio_id, $associazione_id, $numero_tessera, $anno_corrente, $data_emissione, $data_scadenza, $tipo_scadenza, $template_tessera]);
                        $message .= " Tessera creata automaticamente con numero $numero_tessera.";
                    }
                } catch (Exception $e) {
                    // Non bloccare il salvataggio del socio se la tessera fallisce
                    error_log('Auto tessera creation failed: ' . $e->getMessage());
                }
        }
        $pdo->commit();
        $message = $id ? "Socio aggiornato." : "Socio creato.";
        $messageType = "success";
        
        // Always redirect after successful save to avoid form resubmission
        header('Location: index.php?page=soci');
        exit;
SKIP_POST_SAVE:
}

// Recupero Dati per la visualizzazione
$editingSocio = null;
$valori_personalizzati = [];
$tags_socio = [];
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM soci WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingSocio = $stmt->fetch();

    if ($editingSocio) {
        $stmt_val = $pdo->prepare("SELECT campo_id, valore FROM valori_campi_personalizzati WHERE socio_id = ?");
        $stmt_val->execute([$editingSocio['id']]);
        $valori_personalizzati = $stmt_val->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt_tags = $pdo->prepare("SELECT tag_id FROM socio_tags WHERE socio_id = ?");
        $stmt_tags->execute([$editingSocio['id']]);
        $tags_socio = $stmt_tags->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Recupero lista soci con filtri e tags e ultima tessera (se presente)
$sql = "SELECT s.*, 
               a.nome AS associazione_nome,
               cs.nome AS categoria_nome,
               ts.nome AS tipo_nome,
               tagagg.tags AS tags,
               tt.numero_tessera, tt.data_scadenza, tt.tipo_scadenza, tt.template_tessera
        FROM soci s 
        LEFT JOIN associazioni a ON a.id = s.associazione_id
        LEFT JOIN categorie_socio cs ON cs.id = s.categoria_socio_id AND cs.associazione_id = s.associazione_id
        LEFT JOIN tipi_socio ts ON ts.id = s.tipo_socio_id AND ts.associazione_id = s.associazione_id
        LEFT JOIN (
            SELECT st.socio_id, GROUP_CONCAT(t.nome_tag, '|', t.colore SEPARATOR ';') AS tags
            FROM socio_tags st
            LEFT JOIN tags t ON st.tag_id = t.id
            GROUP BY st.socio_id
        ) AS tagagg ON tagagg.socio_id = s.id
        LEFT JOIN tessere tt ON tt.socio_id = s.id 
           AND tt.associazione_id = s.associazione_id 
           AND tt.data_emissione = (
               SELECT MAX(t2.data_emissione) FROM tessere t2 
               WHERE t2.socio_id = s.id AND t2.associazione_id = s.associazione_id
           )
        WHERE 1=1";
$params = [];
if (!$is_super_admin) {
    $sql .= " AND s.associazione_id = ?";
    $params[] = $associazione_id;
} elseif ($assoc_filter !== 'all') {
    $sql .= " AND s.associazione_id = ?";
    $params[] = $assoc_filter;
}

if ($lists_assoc_id) {
    $stmt_gruppi = $pdo->prepare("SELECT id, nome_gruppo FROM gruppi_dinamici WHERE associazione_id = ? ORDER BY nome_gruppo");
    $stmt_gruppi->execute([$lists_assoc_id]);
    $gruppi_dinamici = $stmt_gruppi->fetchAll();
} else {
    $gruppi_dinamici = [];
}

// Preset filtri salvati
$presets = [];
try {
    if ($lists_assoc_id) {
        $stmt_presets = $pdo->prepare("SELECT id, name FROM saved_filters WHERE associazione_id = ? AND scope = 'soci' AND (user_id IS NULL OR user_id = ?) ORDER BY name");
        $stmt_presets->execute([$lists_assoc_id, $_SESSION['user_id'] ?? null]);
    } else {
        $stmt_presets = $pdo->prepare("SELECT id, name FROM saved_filters WHERE 1=0");
        $stmt_presets->execute();
    }
    $presets = $stmt_presets->fetchAll();
} catch (PDOException $e) { /* tabelle non presenti o errore, ignora */ }

// Carica preset se richiesto
if (isset($_GET['preset_id'])) {
    try {
        if ($lists_assoc_id) {
            $stmt_preset = $pdo->prepare("SELECT params_json FROM saved_filters WHERE id = ? AND associazione_id = ? LIMIT 1");
            $stmt_preset->execute([$_GET['preset_id'], $lists_assoc_id]);
        } else {
            $stmt_preset = $pdo->prepare("SELECT params_json FROM saved_filters WHERE 1=0");
            $stmt_preset->execute();
        }
        if ($row = $stmt_preset->fetch()) {
            $p = json_decode($row['params_json'], true) ?: [];
            $searchTerm = $p['search'] ?? $searchTerm;
            $statusFilter = $p['status'] ?? $statusFilter;
            $sede_filter = $p['sede_id'] ?? $sede_filter;
            $categoria_filters = $p['categoria_ids'] ?? $categoria_filters;
            $tessera_template_filter = $p['tessera_template'] ?? $tessera_template_filter;
            $tessera_scadenza_filter = $p['tessera_scadenza'] ?? $tessera_scadenza_filter;
            $has_tessera_filter = $p['has_tessera'] ?? $has_tessera_filter;
            $tessera_stato_filter = $p['tessera_stato'] ?? $tessera_stato_filter;
            $gruppo_id_filter = $p['gruppo_id'] ?? $gruppo_id_filter;
        }
    } catch (PDOException $e) {}
}

$searchTerm = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$gruppo_id_filter = $_GET['gruppo_id'] ?? 'all';
// Filtri: sede (dropdown singolo) e categorie (multi-select)
$sede_filter = $_GET['sede_id'] ?? 'all';
$categoria_filters = isset($_GET['categoria_ids']) ? array_filter((array)$_GET['categoria_ids']) : [];
// Ha tessera (all/yes/no)
$has_tessera_filter = $_GET['has_tessera'] ?? 'all';
// Stato tessera: attive (default) / scadute / all
$tessera_stato_filter = $_GET['tessera_stato'] ?? 'attive';
// Nuovi filtri tessera
$tessera_template_filter = $_GET['tessera_template'] ?? 'all';
$tessera_scadenza_filter = $_GET['tessera_scadenza'] ?? 'all';

if ($gruppo_id_filter !== 'all') {
    $stmt_filtro = $pdo->prepare("SELECT filtri_json FROM gruppi_dinamici WHERE id = ? AND associazione_id = ?");
    $stmt_filtro->execute([$gruppo_id_filter, $associazione_id]);
    $filtri = json_decode($stmt_filtro->fetchColumn() ?? '[]', true);
    // Whitelist of allowed column names for security
    $allowed_columns = ['nome', 'cognome', 'email', 'stato', 'tipo_socio_id', 'categoria_socio_id', 'sede_id', 'citta', 'provincia'];
    
    foreach ($filtri as $key => $value) {
        if (!empty($value) && in_array($key, $allowed_columns)) {
            $sql .= " AND s." . $key . " = ?";
            $params[] = $value;
        }
    }
} else {
    if (!empty($searchTerm)) {
        $sql .= " AND (s.nome LIKE ? OR s.cognome LIKE ? OR s.email LIKE ? OR s.codice_fiscale LIKE ? OR s.numero_socio LIKE ?)";
        $searchTermWild = "%$searchTerm%";
        array_push($params, $searchTermWild, $searchTermWild, $searchTermWild, $searchTermWild, $searchTermWild);
    }
    if ($statusFilter !== 'all') {
        $sql .= " AND s.stato = ?";
        $params[] = $statusFilter;
    }
    if ($sede_filter !== 'all') {
        $sql .= " AND s.sede_id = ?";
        $params[] = $sede_filter;
    }
    if (!empty($categoria_filters)) {
        $place = implode(',', array_fill(0, count($categoria_filters), '?'));
        $sql .= " AND s.categoria_socio_id IN ($place)";
        foreach ($categoria_filters as $cid) { $params[] = $cid; }
    }
    // Ha tessera toggle
    if ($has_tessera_filter === 'yes') {
        $sql .= " AND tt.numero_tessera IS NOT NULL";
    } elseif ($has_tessera_filter === 'no') {
        $sql .= " AND tt.numero_tessera IS NULL";
    }
    // Stato tessera: attive / scadute / all
    if ($tessera_stato_filter === 'attive') {
        $sql .= " AND tt.numero_tessera IS NOT NULL AND (tt.stato = 'Attiva' AND (tt.data_scadenza IS NULL OR tt.data_scadenza >= CURDATE()))";
    } elseif ($tessera_stato_filter === 'scadute') {
        $sql .= " AND tt.numero_tessera IS NOT NULL AND ((tt.stato = 'Scaduta') OR (tt.data_scadenza IS NOT NULL AND tt.data_scadenza < CURDATE()))";
    }
    // Filtro per tipo tessera (se disponibile)
    if ($tessera_template_filter !== 'all') {
        $sql .= " AND tt.template_tessera = ?";
        $params[] = $tessera_template_filter;
    }
    // Filtro scadenza tessera (in scadenza / scadute) se annuale o comunque basato su data_scadenza
    if ($tessera_scadenza_filter !== 'all') {
        // Usa giorni di preavviso dell'associazione
        $sql .= " AND tt.data_scadenza IS NOT NULL";
        if ($tessera_scadenza_filter === 'in_scadenza') {
            $soon_days = (int)($assoc_cfg['giorni_notifica_scadenza'] ?? 30);
            $soon_boundary = date('Y-m-d', strtotime("+{$soon_days} days"));
            $sql .= " AND tt.data_scadenza BETWEEN CURDATE() AND ?";
            $params[] = $soon_boundary;
        } elseif ($tessera_scadenza_filter === 'scadute') {
            $sql .= " AND tt.data_scadenza < CURDATE()";
        }
    }
}

$sql .= " ORDER BY s.cognome, s.nome ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$soci = $stmt->fetchAll();

?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h1>Gestione Soci</h1>
            <p class="page-description">Visualizza e gestisci tutti i soci dell'associazione con strumenti avanzati di ricerca e filtro.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary"><?php echo count($soci); ?> <?php echo count($soci) === 1 ? 'socio' : 'soci'; ?></span>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3 align-items-start flex-wrap gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#socioModal"><i class="bi bi-plus-lg"></i> Nuovo Socio</button>
    <div class="ms-auto d-flex flex-column align-items-end gap-1" style="min-width:280px;">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-stretch">
            <input type="hidden" name="page" value="soci">
            <div class="input-group input-group-sm">
                <select class="form-select" id="presetSelect" name="preset_id">
                    <option value="">Seleziona preset</option>
                    <?php foreach ($presets as $pr): ?>
                        <option value="<?php echo htmlspecialchars($pr['id']); ?>" <?php echo (($_GET['preset_id'] ?? '') === $pr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pr['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline-secondary">Carica</button>
                <button type="button" class="btn btn-outline-danger" onclick="deletePreset()">Elimina</button>
            </div>
        </form>
        <div class="input-group input-group-sm" style="max-width: 420px;">
            <span class="input-group-text">Salva preset</span>
            <input type="text" form="savePresetForm" name="preset_name" class="form-control" placeholder="Nome" required>
            <button type="submit" form="savePresetForm" class="btn btn-outline-primary">Salva</button>
        </div>
    </div>
</div>

<div class="card mb-4 mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filtri di Ricerca</h6>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="toggleFilters">
            <i class="bi bi-chevron-up" id="toggleIcon"></i>
        </button>
    </div>
    <div class="card-body" id="filtersContainer">
        <form method="GET" id="filtersForm">
            <input type="hidden" name="page" value="soci">
            
            <!-- Riga 1: Filtri Tessera (Moved to top) -->
            <div class="filter-section mb-3">
                <h6 class="text-muted mb-3"><i class="bi bi-credit-card me-1"></i>Filtri Tessera</h6>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Tipo Tessera</label>
                        <select class="form-select" name="tessera_template">
                            <option value="all">Tutti i tipi</option>
                            <?php
                            if ($lists_assoc_id) {
                                $tpl_stmt = $pdo->prepare("SELECT DISTINCT template_tessera FROM tessere WHERE associazione_id = ? AND template_tessera IS NOT NULL ORDER BY template_tessera");
                                $tpl_stmt->execute([$lists_assoc_id]);
                            } elseif ($is_super_admin && $assoc_filter === 'all') {
                                $tpl_stmt = $pdo->prepare("SELECT DISTINCT template_tessera FROM tessere WHERE template_tessera IS NOT NULL ORDER BY template_tessera");
                                $tpl_stmt->execute();
                            } else {
                                $tpl_stmt = false;
                            }
                            
                            if ($tpl_stmt) {
                                foreach ($tpl_stmt->fetchAll(PDO::FETCH_COLUMN) as $tpl) {
                                    $sel = ($tessera_template_filter === $tpl) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($tpl) . '" ' . $sel . '>' . htmlspecialchars($tpl) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Possesso Tessera</label>
                        <select class="form-select" name="has_tessera">
                            <option value="all" <?php echo $has_tessera_filter==='all'?'selected':''; ?>>Tutti</option>
                            <option value="yes" <?php echo $has_tessera_filter==='yes'?'selected':''; ?>>Con tessera</option>
                            <option value="no" <?php echo $has_tessera_filter==='no'?'selected':''; ?>>Senza tessera</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Stato Tessera</label>
                        <select class="form-select" name="tessera_stato">
                            <option value="attive" <?php echo $tessera_stato_filter==='attive'?'selected':''; ?>>Attive</option>
                            <option value="scadute" <?php echo $tessera_stato_filter==='scadute'?'selected':''; ?>>Scadute</option>
                            <option value="all" <?php echo $tessera_stato_filter==='all'?'selected':''; ?>>Tutte</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">Scadenza Tessera</label>
                        <select class="form-select" name="tessera_scadenza">
                            <option value="all" <?php echo $tessera_scadenza_filter==='all'?'selected':''; ?>>Tutte</option>
                            <option value="in_scadenza" <?php echo $tessera_scadenza_filter==='in_scadenza'?'selected':''; ?>>In scadenza</option>
                            <option value="scadute" <?php echo $tessera_scadenza_filter==='scadute'?'selected':''; ?>>Già scadute</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Riga 2: Controlli Principali -->
            <div class="filter-section mb-3">
                <div class="row g-3 align-items-end">
                    <?php if ($is_super_admin): ?>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-building me-1"></i>Associazione
                        </label>
                        <select class="form-select" name="assoc_id" onchange="this.form.submit()">
                            <option value="all" <?php echo ($assoc_filter==='all')?'selected':''; ?>>Tutte le associazioni</option>
                            <?php 
                            $associazioni = $pdo->query("SELECT id, nome FROM associazioni ORDER BY nome")->fetchAll();
                            foreach ($associazioni as $a): ?>
                                <option value="<?php echo $a['id']; ?>" <?php echo ($assoc_filter===$a['id'])?'selected':''; ?>><?php echo htmlspecialchars($a['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-search me-1"></i>Cerca per nome, cognome, email o codice fiscale
                        </label>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Nome, email o codice fiscale..." 
                               value="<?php echo htmlspecialchars($searchTerm); ?>" 
                               <?php if ($gruppo_id_filter !== 'all') echo 'disabled'; ?>>
                    </div>
                    
                    <div class="col-md-6 col-lg-2">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-person-check me-1"></i>Stato Socio
                        </label>
                        <select class="form-select" name="status" <?php if ($gruppo_id_filter !== 'all') echo 'disabled'; ?>>
                            <option value="all" <?php echo $statusFilter==='all'?'selected':''; ?>>Tutti gli stati</option>
                            <option value="Attivo" <?php echo $statusFilter==='Attivo'?'selected':''; ?>>Attivo</option>
                            <option value="Sospeso" <?php echo $statusFilter==='Sospeso'?'selected':''; ?>>Sospeso</option>
                            <option value="Radiato" <?php echo $statusFilter==='Radiato'?'selected':''; ?>>Radiato</option>
                            <option value="Deceduto" <?php echo $statusFilter==='Deceduto'?'selected':''; ?>>Deceduto</option>
                            <option value="Trasferito" <?php echo $statusFilter==='Trasferito'?'selected':''; ?>>Trasferito</option>
                        </select>
                    </div>
                    
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-geo-alt me-1"></i>Sede
                        </label>
                        <select class="form-select" name="sede_id">
                            <option value="all">Tutte le sedi</option>
                            <?php foreach ($sedi as $sede): 
                                $sel = ($sede_filter === $sede['id']) ? 'selected' : '';
                                $display_name = $sede['nome'];
                                if (isset($sede['associazione_nome'])) {
                                    $display_name .= ' (' . $sede['associazione_nome'] . ')';
                                }
                            ?>
                                <option value="<?php echo $sede['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Riga 3: Categorie Socio -->
            <div class="filter-section mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-semibold mb-0">
                                <i class="bi bi-tags me-1"></i>Categorie Socio
                            </label>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary" onclick="selectAllCategories()">
                                    <i class="bi bi-check-all me-1"></i>Tutte
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="clearAllCategories()">
                                    <i class="bi bi-x-circle me-1"></i>Nessuna
                                </button>
                            </div>
                        </div>
                        <select class="form-select" id="categoriaSelect" name="categoria_ids[]" multiple size="4">
                            <?php foreach ($categorie_socio as $categoria): 
                                $sel = in_array($categoria['id'], $categoria_filters) ? 'selected' : '';
                                $display_name = $categoria['nome'];
                                if (isset($categoria['associazione_nome'])) {
                                    $display_name .= ' (' . $categoria['associazione_nome'] . ')';
                                }
                            ?>
                                <option value="<?php echo $categoria['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Mantieni premuto Ctrl (Cmd su Mac) per selezionare più categorie</div>
                    </div>
                </div>
            </div>

            <!-- Riga 4: Gruppo Rapido e Azioni -->
            <div class="filter-section">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-collection me-1"></i>Gruppo Rapido
                        </label>
                        <select class="form-select" name="gruppo_id" onchange="this.form.submit()">
                            <option value="all">Nessun gruppo applicato</option>
                            <?php foreach($gruppi_dinamici as $g): ?>
                                <option value="<?php echo $g['id']; ?>" <?php echo ($gruppo_id_filter == $g['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($g['nome_gruppo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">I gruppi rapidi applicano filtri predefiniti</div>
                    </div>
                    
                    <div class="col-md-6 col-lg-8">
                        <div class="d-flex justify-content-end gap-2 filter-actions flex-wrap">
                            <button type="submit" class="btn btn-primary d-flex align-items-center gap-2">
                                <i class="bi bi-funnel"></i><span>Applica Filtri</span>
                            </button>
                            <a class="btn btn-outline-secondary d-flex align-items-center gap-2" href="index.php?page=soci">
                                <i class="bi bi-arrow-counterclockwise"></i><span>Reset</span>
                            </a>
                            <?php
                            // Costruisci URL export con gli stessi filtri
                            $export_params = [
                                'type' => 'soci',
                                'search' => $searchTerm,
                                'status' => $statusFilter,
                                'tessera_template' => $tessera_template_filter,
                                'tessera_scadenza' => $tessera_scadenza_filter,
                                'has_tessera' => $has_tessera_filter,
                                'tessera_stato' => $tessera_stato_filter,
                            ];
                            if ($is_super_admin && $assoc_filter !== 'all') { $export_params['assoc_id'] = $assoc_filter; }
                            if ($sede_filter !== 'all') { $export_params['sede_id'] = $sede_filter; }
                            foreach ($categoria_filters as $cid) { $export_params['categoria_ids'][] = $cid; }
                            $qs = http_build_query($export_params);
                            ?>
                            <a class="btn btn-outline-success d-flex align-items-center gap-2" href="api/export.php?<?php echo htmlspecialchars($qs); ?>" target="_blank">
                                <i class="bi bi-download"></i><span>Esporta CSV</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function selectAllCategories(){
  const sel = document.getElementById('categoriaSelect');
  if(!sel) return;
  Array.from(sel.options).forEach(o=>o.selected=true);
}
function clearAllCategories(){
  const sel = document.getElementById('categoriaSelect');
  if(!sel) return;
  Array.from(sel.options).forEach(o=>o.selected=false);
}
function deletePreset(){
  const sel = document.getElementById('presetSelect');
  if(!sel || !sel.value){ alert('Seleziona un preset prima di eliminare.'); return; }
  if(!confirm('Eliminare il preset selezionato?')) return;
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="preset_id_delete" value="${sel.value}">
    <input type="hidden" name="delete_filter_preset" value="1">
  `;
  document.body.appendChild(form);
  form.submit();
}
</script>

<!-- Hidden form for saving preset to avoid nested forms -->
<form id="savePresetForm" method="POST" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="save_filter_preset" value="1">
    <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
    <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
    <input type="hidden" name="sede_id" value="<?php echo htmlspecialchars($sede_filter); ?>">
    <?php foreach ($categoria_filters as $cid): ?>
        <input type="hidden" name="categoria_ids[]" value="<?php echo htmlspecialchars($cid); ?>">
    <?php endforeach; ?>
    <input type="hidden" name="tessera_template" value="<?php echo htmlspecialchars($tessera_template_filter); ?>">
    <input type="hidden" name="tessera_scadenza" value="<?php echo htmlspecialchars($tessera_scadenza_filter); ?>">
    <input type="hidden" name="has_tessera" value="<?php echo htmlspecialchars($has_tessera_filter); ?>">
    <input type="hidden" name="tessera_stato" value="<?php echo htmlspecialchars($tessera_stato_filter); ?>">
    <input type="hidden" name="gruppo_id" value="<?php echo htmlspecialchars($gruppo_id_filter); ?>">
</form>

<?php if (empty($soci)): ?>
<div class="table-empty">
    <i class="bi bi-people"></i>
    <h5>Nessun socio trovato</h5>
    <p class="text-muted">Non ci sono soci che corrispondono ai criteri di ricerca.</p>
    <a href="index.php?page=soci" class="btn btn-outline-primary">Rimuovi filtri</a>
</div>
<?php else: ?>

<!-- Enhanced Responsive Table -->
<div class="responsive-table-wrapper">
    
    <!-- Desktop Table View -->
    <table class="table-desktop" id="sociTable">
        <thead>
            <tr>
                <th>Socio</th>
                <?php if ($is_super_admin): ?><th>Associazione</th><?php endif; ?>
                <th>Contatti</th>
                <th>Stato</th>
                <th>Categoria/Tipo</th>
                <th>Tessera</th>
                <th>Tags</th>
                <th class="text-end">Azioni</th>
            </tr>
        </thead>
        <tbody data-animate="table-rows">
        <?php foreach ($soci as $socio): ?>
            <tr>
                <td>
                    <div>
                        <a href="index.php?page=socio_dettaglio&id=<?php echo $socio['id']; ?>" class="fw-semibold text-decoration-none">
                            <?php echo htmlspecialchars($socio['cognome'] . ' ' . $socio['nome']); ?>
                        </a>
                    </div>
                    <small class="text-muted font-monospace"><?php echo htmlspecialchars($socio['numero_socio']); ?></small>
                </td>
                <?php if ($is_super_admin): ?>
                <td>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($socio['associazione_nome'] ?? ''); ?></span>
                </td>
                <?php endif; ?>
                <td>
                    <div><?php echo htmlspecialchars($socio['email']); ?></div>
                    <?php if (!empty($socio['telefono'])): ?>
                        <small class="text-muted"><?php echo htmlspecialchars($socio['telefono']); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?php echo strtolower($socio['stato']) === 'attivo' ? 'success' : 'warning'; ?>">
                        <?php echo htmlspecialchars($socio['stato']); ?>
                    </span>
                </td>
                <td>
                    <?php if (!empty($socio['categoria_nome'])): ?>
                        <div><span class="badge bg-info"><?php echo htmlspecialchars($socio['categoria_nome']); ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($socio['tipo_nome'])): ?>
                        <div><small class="text-muted"><?php echo htmlspecialchars($socio['tipo_nome']); ?></small></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($socio['numero_tessera'])): ?>
                        <div class="d-flex flex-column gap-1">
                            <div><small class="text-muted">N.</small> <strong><?php echo htmlspecialchars($socio['numero_tessera']); ?></strong></div>
                            <?php if (!empty($socio['template_tessera'])): ?>
                                <span class="badge bg-secondary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($socio['template_tessera']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($socio['data_scadenza'])): 
                                $scad = strtotime($socio['data_scadenza']);
                                $today = strtotime(date('Y-m-d'));
                                $daysNotice = (int)($assoc_cfg['giorni_notifica_scadenza'] ?? 30);
                                $soon = strtotime("+{$daysNotice} days", $today);
                                $badge = '';
                                if ($scad < $today) $badge = '<span class="badge bg-danger">Scaduta</span>';
                                elseif ($scad <= $soon) $badge = '<span class="badge bg-warning text-dark">In scadenza</span>';
                                ?>
                                <div><small class="text-muted">Scade:</small> <?php echo date('d/m/Y', $scad); ?> <?php echo $badge; ?></div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex flex-wrap gap-1">
                        <?php if($socio['tags']): 
                            foreach(explode(';', $socio['tags']) as $tag_str): 
                                if(strpos($tag_str, '|') !== false):
                                    list($nome, $colore) = explode('|', $tag_str); ?>
                                    <span class="badge" style="background-color: <?php echo htmlspecialchars($colore); ?>; color: white; font-size: 0.7rem;">
                                        <?php echo htmlspecialchars($nome); ?>
                                    </span>
                                <?php endif;
                            endforeach; 
                        endif; ?>
                    </div>
                </td>
                <td>
                    <div class="table-actions">
                        <a href="index.php?page=soci&edit=<?php echo $socio['id']; ?>" 
                           class="btn btn-sm btn-outline-primary" 
                           title="Modifica">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="index.php?page=genera-tessera-pdf&socio_id=<?php echo $socio['id']; ?>" 
                           class="btn btn-sm btn-outline-success" 
                           title="Genera Tessera PDF" 
                           target="_blank">
                            <i class="bi bi-credit-card"></i>
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo socio?')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="delete_id" value="<?php echo $socio['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Mobile Card View -->
    <div class="table-mobile">
        <?php foreach ($soci as $socio): ?>
            <div class="table-card">
                <div class="card-header-section">
                    <div class="card-primary-info">
                        <h5 class="card-title">
                            <a href="index.php?page=socio_dettaglio&id=<?php echo $socio['id']; ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($socio['cognome'] . ' ' . $socio['nome']); ?>
                            </a>
                        </h5>
                        <div class="card-subtitle"><?php echo htmlspecialchars($socio['numero_socio']); ?></div>
                    </div>
                    <div class="card-status">
                        <span class="badge bg-<?php echo strtolower($socio['stato']) === 'attivo' ? 'success' : 'warning'; ?>">
                            <?php echo htmlspecialchars($socio['stato']); ?>
                        </span>
                    </div>
                </div>
                
                <div class="card-content">
                    <?php if ($is_super_admin): ?>
                    <div class="card-field">
                        <span class="field-label">Associazione:</span>
                        <span class="field-value">
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($socio['associazione_nome'] ?? ''); ?></span>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="card-field">
                        <span class="field-label">Email:</span>
                        <span class="field-value"><?php echo htmlspecialchars($socio['email']); ?></span>
                    </div>
                    
                    <?php if (!empty($socio['telefono'])): ?>
                    <div class="card-field">
                        <span class="field-label">Telefono:</span>
                        <span class="field-value"><?php echo htmlspecialchars($socio['telefono']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($socio['categoria_nome']) || !empty($socio['tipo_nome'])): ?>
                    <div class="card-field">
                        <span class="field-label">Categoria/Tipo:</span>
                        <div class="field-value">
                            <?php if (!empty($socio['categoria_nome'])): ?>
                                <span class="badge bg-info me-1"><?php echo htmlspecialchars($socio['categoria_nome']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($socio['tipo_nome'])): ?>
                                <small class="text-muted"><?php echo htmlspecialchars($socio['tipo_nome']); ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($socio['numero_tessera'])): ?>
                    <div class="card-field">
                        <span class="field-label">Tessera:</span>
                        <div class="field-value">
                            <div><strong><?php echo htmlspecialchars($socio['numero_tessera']); ?></strong></div>
                            <?php if (!empty($socio['template_tessera'])): ?>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($socio['template_tessera']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($socio['data_scadenza'])): 
                                $scad = strtotime($socio['data_scadenza']);
                                $today = strtotime(date('Y-m-d'));
                                $daysNotice = (int)($assoc_cfg['giorni_notifica_scadenza'] ?? 30);
                                $soon = strtotime("+{$daysNotice} days", $today);
                                $badge = '';
                                if ($scad < $today) $badge = '<span class="badge bg-danger">Scaduta</span>';
                                elseif ($scad <= $soon) $badge = '<span class="badge bg-warning text-dark">In scadenza</span>';
                                ?>
                                <div><small>Scade: <?php echo date('d/m/Y', $scad); ?></small> <?php echo $badge; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($socio['tags']): ?>
                    <div class="card-field">
                        <span class="field-label">Tags:</span>
                        <div class="card-tags">
                            <?php foreach(explode(';', $socio['tags']) as $tag_str): 
                                if(strpos($tag_str, '|') !== false):
                                    list($nome, $colore) = explode('|', $tag_str); ?>
                                    <span class="badge" style="background-color: <?php echo htmlspecialchars($colore); ?>; color: white;">
                                        <?php echo htmlspecialchars($nome); ?>
                                    </span>
                                <?php endif;
                            endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="card-actions">
                    <a href="index.php?page=soci&edit=<?php echo $socio['id']; ?>" 
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-pencil me-1"></i>Modifica
                    </a>
                    <a href="index.php?page=genera-tessera-pdf&socio_id=<?php echo $socio['id']; ?>" 
                       class="btn btn-sm btn-outline-success" 
                       target="_blank">
                        <i class="bi bi-credit-card me-1"></i>PDF
                    </a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo socio?')">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="delete_id" value="<?php echo $socio['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-trash me-1"></i>Elimina
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
</div>
<?php endif; ?>

<!-- Modale Aggiunta/Modifica Socio -->
<div class="modal fade" id="socioModal" tabindex="-1">
<div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
        <h5 class="modal-title"><?php echo $editingSocio ? 'Modifica' : 'Nuovo'; ?> Socio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <form method="POST">
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="id" value="<?php echo $editingSocio['id'] ?? ''; ?>">
            <h6 class="text-muted">Dati Principali</h6><hr class="mt-1">
            <div class="row">
                <div class="col-md-6 mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($editingSocio['nome'] ?? ''); ?>" required></div>
                <div class="col-md-6 mb-3"><label>Cognome</label><input type="text" name="cognome" class="form-control" value="<?php echo htmlspecialchars($editingSocio['cognome'] ?? ''); ?>" required></div>
            </div>
            <div class="row">
                 <div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($editingSocio['email'] ?? ''); ?>" required></div>
                 <div class="col-md-6 mb-3">
                     <label>Numero Socio</label>
                     <?php if ($editingSocio): ?>
                         <input type="text" name="numero_socio" class="form-control" value="<?php echo htmlspecialchars($editingSocio['numero_socio'] ?? ''); ?>" required>
                     <?php else: ?>
                         <input type="text" name="numero_socio" class="form-control" value="" placeholder="Verrà assegnato automaticamente" readonly>
                         <div class="form-text">Il numero socio verrà assegnato automaticamente in base all'ultimo numero registrato</div>
                     <?php endif; ?>
                 </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label>Data di Nascita</label><input type="date" name="data_nascita" class="form-control" value="<?php echo htmlspecialchars($editingSocio['data_nascita'] ?? ''); ?>" required></div>
                <div class="col-md-6 mb-3"><label>Data Iscrizione</label><input type="date" name="data_iscrizione" class="form-control" value="<?php echo htmlspecialchars($editingSocio['data_iscrizione'] ?? date('Y-m-d')); ?>" required></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label>Codice Fiscale</label><input type="text" name="codice_fiscale" class="form-control" value="<?php echo htmlspecialchars($editingSocio['codice_fiscale'] ?? ''); ?>"></div>
                <div class="col-md-6 mb-3"><label>Telefono</label><input type="tel" name="telefono" class="form-control" value="<?php echo htmlspecialchars($editingSocio['telefono'] ?? ''); ?>"></div>
            </div>
            <div class="row">
                <div class="col-md-12 mb-3"><label>Indirizzo</label><input type="text" name="indirizzo" class="form-control" value="<?php echo htmlspecialchars($editingSocio['indirizzo'] ?? ''); ?>"></div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3"><label>Città</label><input type="text" name="citta" class="form-control" value="<?php echo htmlspecialchars($editingSocio['citta'] ?? ''); ?>"></div>
                <div class="col-md-3 mb-3"><label>Provincia</label><input type="text" name="provincia" class="form-control" maxlength="2" value="<?php echo htmlspecialchars($editingSocio['provincia'] ?? ''); ?>"></div>
                <div class="col-md-3 mb-3"><label>CAP</label><input type="text" name="cap" class="form-control" maxlength="5" value="<?php echo htmlspecialchars($editingSocio['cap'] ?? ''); ?>"></div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label>Tipo Socio</label>
                    <select name="tipo_socio_id" class="form-select">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($tipi_socio as $tipo): 
                            $display_name = $tipo['nome'];
                            if (isset($tipo['associazione_nome'])) {
                                $display_name .= ' (' . $tipo['associazione_nome'] . ')';
                            }
                        ?>
                            <option value="<?php echo $tipo['id']; ?>" <?php echo ($editingSocio['tipo_socio_id'] ?? '') == $tipo['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>Categoria Socio</label>
                    <select name="categoria_socio_id" class="form-select">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($categorie_socio as $categoria): 
                            $display_name = $categoria['nome'];
                            if (isset($categoria['associazione_nome'])) {
                                $display_name .= ' (' . $categoria['associazione_nome'] . ')';
                            }
                        ?>
                            <option value="<?php echo $categoria['id']; ?>" <?php echo ($editingSocio['categoria_socio_id'] ?? '') == $categoria['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label>Sede</label>
                    <select name="sede_id" class="form-select">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($sedi as $sede): 
                            $display_name = $sede['nome'];
                            if (isset($sede['associazione_nome'])) {
                                $display_name .= ' (' . $sede['associazione_nome'] . ')';
                            }
                        ?>
                            <option value="<?php echo $sede['id']; ?>" <?php echo ($editingSocio['sede_id'] ?? '') == $sede['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-8 mb-3">
                    <label>Stato</label>
                    <select name="stato" class="form-select">
                        <option value="Attivo" <?php echo ($editingSocio['stato'] ?? 'Attivo') == 'Attivo' ? 'selected' : ''; ?>>Attivo</option>
                        <option value="Sospeso" <?php echo ($editingSocio['stato'] ?? '') == 'Sospeso' ? 'selected' : ''; ?>>Sospeso</option>
                        <option value="Radiato" <?php echo ($editingSocio['stato'] ?? '') == 'Radiato' ? 'selected' : ''; ?>>Radiato</option>
                        <option value="Deceduto" <?php echo ($editingSocio['stato'] ?? '') == 'Deceduto' ? 'selected' : ''; ?>>Deceduto</option>
                        <option value="Trasferito" <?php echo ($editingSocio['stato'] ?? '') == 'Trasferito' ? 'selected' : ''; ?>>Trasferito</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="privacy_consenso" id="privacy_consenso" value="1" <?php echo ($editingSocio['privacy_consenso'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="privacy_consenso">Consenso Privacy</label>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label>Note</label>
                <textarea name="note" class="form-control" rows="3"><?php echo htmlspecialchars($editingSocio['note'] ?? ''); ?></textarea>
            </div>
            
            <?php if (!empty($campi_personalizzati)): ?>
            <h6 class="text-muted mt-4">Dati Personalizzati</h6><hr class="mt-1">
            <div class="row">
            <?php foreach ($campi_personalizzati as $campo):
                ?>
                <div class="col-md-6 mb-3">
                    <label><?php echo htmlspecialchars($campo['nome_campo']); echo $campo['obbligatorio'] ? ' *' : ''; ?></label>
                    <?php if ($campo['descrizione']): ?>
                        <small class="form-text text-muted"><?php echo htmlspecialchars($campo['descrizione']); ?></small>
                    <?php endif; ?>
                    <?php if ($campo['tipo_campo'] === 'textarea'): ?>
                        <textarea name="custom_field[<?php echo $campo['id']; ?>]" class="form-control" rows="3" <?php echo $campo['obbligatorio'] ? 'required' : ''; ?>><?php echo htmlspecialchars($valori_personalizzati[$campo['id']] ?? ''); ?></textarea>
                    <?php elseif ($campo['tipo_campo'] === 'select'): ?>
                        <select name="custom_field[<?php echo $campo['id']; ?>]" class="form-select" <?php echo $campo['obbligatorio'] ? 'required' : ''; ?>>
                            <option value="">-- Seleziona --</option>
                            <?php if ($campo['opzioni']): foreach (explode("\n", $campo['opzioni']) as $option): $option = trim($option); ?>
                                <option value="<?php echo htmlspecialchars($option); ?>" <?php echo ($valori_personalizzati[$campo['id']] ?? '') === $option ? 'selected' : ''; ?>><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                    <?php elseif ($campo['tipo_campo'] === 'checkbox'): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="custom_field[<?php echo $campo['id']; ?>]" value="1" id="custom_<?php echo $campo['id']; ?>" <?php echo ($valori_personalizzati[$campo['id']] ?? '') ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="custom_<?php echo $campo['id']; ?>">Sì</label>
                        </div>
                    <?php else: ?>
                        <input type="<?php echo $campo['tipo_campo']; ?>" name="custom_field[<?php echo $campo['id']; ?>]" class="form-control" value="<?php echo htmlspecialchars($valori_personalizzati[$campo['id']] ?? ''); ?>" <?php echo $campo['obbligatorio'] ? 'required' : ''; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($tags_disponibili)): ?>
            <h6 class="text-muted mt-4">Tag</h6><hr class="mt-1">
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ($tags_disponibili as $tag):
                ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="tags[]" value="<?php echo $tag['id']; ?>" id="tag_<?php echo $tag['id']; ?>" <?php echo in_array($tag['id'], $tags_socio) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="tag_<?php echo $tag['id']; ?>"><span class="badge" style="background-color: <?php echo $tag['colore']; ?>; color: white;"><?php echo htmlspecialchars($tag['nome_tag']); ?></span></label>
                </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
        <div class="modal-footer">
            <?php if (!$editingSocio): ?>
                <div class="form-check me-auto">
                    <input class="form-check-input" type="checkbox" name="genera_tessera" value="1" id="genera_tessera" checked>
                    <label class="form-check-label" for="genera_tessera">
                        <i class="bi bi-credit-card"></i> Genera tessera PDF dopo la creazione
                    </label>
                </div>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary" id="salvaSocioBtn">Salva</button>
        </div>
    </form>
</div></div>
};

?>
</div>

<!-- Script per gestire il submit del form in modo asincrono -->
<script>
// Close modal after form submission if coming from a redirect
document.addEventListener('DOMContentLoaded', function() {
    // Check if there's a success message in the URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('message') && urlParams.get('messageType') === 'success') {
        // If modal is open, close it
        const modalElement = document.getElementById('socioModal');
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    }
});
</script>

<?php if ($editingSocio): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('socioModal')).show());</script>
<?php endif; ?>
