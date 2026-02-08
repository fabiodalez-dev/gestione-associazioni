<?php
// pages/associazioni.php - Gestione Associazioni (solo Super Admin)

require_once __DIR__ . '/../config.php';

if (!isUserLoggedIn(['super_admin'])) {
    redirect('auth/login.php');
}

$message = '';
$messageType = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza: token CSRF non valido.';
        $messageType = 'danger';
    } else {
            if (isset($_POST['delete_id'])) {
                // Delete association (will cascade)
                $stmt = $pdo->prepare("DELETE FROM associazioni WHERE id = ?");
                $stmt->execute([$_POST['delete_id']]);
                $message = 'Associazione eliminata.';
                $messageType = 'success';
            } else {
                // Rimozione admin esistente dall'associazione
                if (!empty($_POST['remove_admin_user_id']) && !empty($_POST['id'])) {
                    $assocToEdit = $_POST['id'];
                    $userToRemove = $_POST['remove_admin_user_id'];
                    $upd = $pdo->prepare("UPDATE users SET associazione_id = NULL WHERE id = ? AND associazione_id = ?");
                    $upd->execute([$userToRemove, $assocToEdit]);
                    $message = 'Amministratore rimosso dall\'associazione.';
                    $messageType = 'success';
                    // stop further processing to avoid overriding with other actions
                } else {
                $id = $_POST['id'] ?? null;
                $data = [
                    'nome' => cleanInput($_POST['nome'] ?? ''),
                    'email' => cleanInput($_POST['email'] ?? ''),
                    'partita_iva' => cleanInput($_POST['partita_iva'] ?? ''),
                    'codice_fiscale' => cleanInput($_POST['codice_fiscale'] ?? ''),
                    'indirizzo' => cleanInput($_POST['indirizzo'] ?? ''),
                    'citta' => cleanInput($_POST['citta'] ?? ''),
                    'provincia' => cleanInput($_POST['provincia'] ?? ''),
                    'cap' => cleanInput($_POST['cap'] ?? ''),
                    'telefono' => cleanInput($_POST['telefono'] ?? ''),
                    'tipo_scadenza_default' => in_array(($_POST['tipo_scadenza_default'] ?? 'solare'), ['solare','annuale']) ? $_POST['tipo_scadenza_default'] : 'solare',
                    'giorni_notifica_scadenza' => (int)($_POST['giorni_notifica_scadenza'] ?? 30),
                    'costo_tessera' => isset($_POST['costo_tessera']) && $_POST['costo_tessera'] !== '' ? (float)$_POST['costo_tessera'] : null,
                    'attiva' => isset($_POST['attiva']) ? 1 : 0,
                ];

                if ($id) {
                    $sql = "UPDATE associazioni SET nome=?, email=?, partita_iva=?, codice_fiscale=?, indirizzo=?, citta=?, provincia=?, cap=?, telefono=?, tipo_scadenza_default=?, giorni_notifica_scadenza=?, costo_tessera=?, attiva=? WHERE id=?";
                    $params = [
                        $data['nome'],$data['email'],$data['partita_iva'],$data['codice_fiscale'],$data['indirizzo'],$data['citta'],$data['provincia'],$data['cap'],$data['telefono'],$data['tipo_scadenza_default'],$data['giorni_notifica_scadenza'],$data['costo_tessera'],$data['attiva'],$id
                    ];
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $assoc_id = $id;
                    $message = 'Associazione aggiornata con successo.';
                    $messageType = 'success';

                    // Upload logo se presente
                    if (!empty($_FILES['logo_file']['name'] ?? '')) {
                        $errors = validateFileUpload($_FILES['logo_file'], ['jpg','jpeg','png'], 2 * 1024 * 1024);
                        if (empty($errors)) {
                            $safeName = generateSecureFileName($_FILES['logo_file']['name'], 'logo_');
                            $targetPath = UPLOADS_PATH . '/logos/' . $safeName;
                            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                                $relative = 'uploads/logos/' . $safeName;
                                $pdo->prepare("UPDATE associazioni SET logo_url = ? WHERE id = ?")->execute([$relative, $assoc_id]);
                            } else {
                                $message .= ' (Logo non salvato)';
                                $messageType = 'warning';
                            }
                        } else {
                            $message .= ' (Logo non valido: ' . implode(' | ', $errors) . ')';
                            $messageType = 'warning';
                        }
                    }

                    // Link admin esistente, se richiesto
                    if (!empty($_POST['link_admin_user_id'])) {
                        $userId = $_POST['link_admin_user_id'];
                        // Permetti link solo a utenti non super_admin
                        $stmtChk = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                        $stmtChk->execute([$userId]);
                        $role = $stmtChk->fetchColumn();
                        if ($role && $role !== 'super_admin') {
                            $pdo->prepare("UPDATE users SET associazione_id = ?, role = 'admin_associazione' WHERE id = ?")->execute([$assoc_id, $userId]);
                        } else {
                            $message .= ' (Admin non collegato: utente non valido)';
                            $messageType = 'warning';
                        }
                    }
                    // Clonazione su associazione esistente (server-side)
                    $clone_from = $_POST['clone_from_assoc_id'] ?? '';
                    $do_clone = !empty($_POST['clone_enable_edit']) && $clone_from !== '';
                    if ($do_clone) {
                        $tipo_map = [];
                            if (!empty($_POST['clone_tipi'])) {
                                $sel = $pdo->prepare("SELECT * FROM tipi_socio WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $newId = generateUuid();
                                    $ins = $pdo->prepare("INSERT INTO tipi_socio (id, associazione_id, nome, descrizione, costo_tessera) VALUES (?, ?, ?, ?, ?)");
                                    $ins->execute([$newId, $assoc_id, $row['nome'], $row['descrizione'], $row['costo_tessera']]);
                                    $tipo_map[$row['id']] = $newId;
                                }
                            }
                            if (!empty($_POST['clone_categorie'])) {
                                $sel = $pdo->prepare("SELECT * FROM categorie_socio WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO categorie_socio (id, associazione_id, nome, descrizione) VALUES (?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome'], $row['descrizione']]);
                                }
                            }
                            if (!empty($_POST['clone_sedi'])) {
                                $sel = $pdo->prepare("SELECT * FROM sedi WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, provincia, cap, email, telefono, responsabile) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome'], $row['indirizzo'], $row['citta'], $row['provincia'], $row['cap'], $row['email'], $row['telefono'], $row['responsabile']]);
                                }
                            }
                            if (!empty($_POST['clone_campi'])) {
                                $sel = $pdo->prepare("SELECT * FROM campi_personalizzati WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO campi_personalizzati (id, associazione_id, nome_campo, tipo_campo, descrizione, opzioni, obbligatorio, ordine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_campo'], $row['tipo_campo'], $row['descrizione'], $row['opzioni'], $row['obbligatorio'], $row['ordine']]);
                                }
                            }
                            if (!empty($_POST['clone_tags'])) {
                                $sel = $pdo->prepare("SELECT * FROM tags WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO tags (id, associazione_id, nome_tag, colore) VALUES (?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_tag'], $row['colore']]);
                                }
                            }
                            if (!empty($_POST['clone_gruppi'])) {
                                $sel = $pdo->prepare("SELECT * FROM gruppi_dinamici WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO gruppi_dinamici (id, associazione_id, nome_gruppo, descrizione, filtri_json) VALUES (?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_gruppo'], $row['descrizione'], $row['filtri_json']]);
                                }
                            }
                            if (!empty($_POST['clone_templates']) && tableExists($pdo, 'tessera_templates')) {
                                $sel = $pdo->prepare("SELECT * FROM tessera_templates WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $mapped_tipo = $row['tipo_socio_id'] ? ($tipo_map[$row['tipo_socio_id']] ?? null) : null;
                                    $ins = $pdo->prepare("INSERT INTO tessera_templates (id, associazione_id, tipo_socio_id, titolo, contenuto, attivo) VALUES (?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $mapped_tipo, $row['titolo'], $row['contenuto'], $row['attivo']]);
                                }
                            }
                    }

                    $message = 'Associazione aggiornata.';
                    $messageType = 'success';
                } else {
                    $assoc_id = generateUuid();
                    $sql = "INSERT INTO associazioni (id, nome, email, partita_iva, codice_fiscale, indirizzo, citta, provincia, cap, telefono, tipo_scadenza_default, giorni_notifica_scadenza, costo_tessera, attiva) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $params = [
                        $assoc_id,$data['nome'],$data['email'],$data['partita_iva'],$data['codice_fiscale'],$data['indirizzo'],$data['citta'],$data['provincia'],$data['cap'],$data['telefono'],$data['tipo_scadenza_default'],$data['giorni_notifica_scadenza'],$data['costo_tessera'],$data['attiva']
                    ];
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $message = 'Associazione creata con successo.';
                    $messageType = 'success';

                    // Upload logo se presente
                    if (!empty($_FILES['logo_file']['name'] ?? '')) {
                        $errors = validateFileUpload($_FILES['logo_file'], ['jpg','jpeg','png'], 2 * 1024 * 1024);
                        if (empty($errors)) {
                            $safeName = generateSecureFileName($_FILES['logo_file']['name'], 'logo_');
                            $targetPath = UPLOADS_PATH . '/logos/' . $safeName;
                            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $targetPath)) {
                                $relative = 'uploads/logos/' . $safeName;
                                $pdo->prepare("UPDATE associazioni SET logo_url = ? WHERE id = ?")->execute([$relative, $assoc_id]);
                            } else {
                                $message .= ' (Logo non salvato)';
                                $messageType = 'warning';
                            }
                        } else {
                            $message .= ' (Logo non valido: ' . implode(' | ', $errors) . ')';
                            $messageType = 'warning';
                        }
                    }

                    // Collega admin esistente (prioritario rispetto alla creazione)
                    if (!empty($_POST['link_admin_user_id_create'])) {
                        $userId = $_POST['link_admin_user_id_create'];
                        $stmtChk = $pdo->prepare("SELECT role, associazione_id FROM users WHERE id = ?");
                        $stmtChk->execute([$userId]);
                        $row = $stmtChk->fetch();
                        if ($row && $row['role'] !== 'super_admin' && empty($row['associazione_id'])) {
                            $pdo->prepare("UPDATE users SET associazione_id = ?, role = 'admin_associazione' WHERE id = ?")->execute([$assoc_id, $userId]);
                        } else {
                            $message .= ' (Admin esistente non collegato: non valido o già collegato)';
                            $messageType = 'warning';
                        }
                    }

                    // In alternativa: crea un nuovo admin per l'associazione
                    if (!empty($_POST['create_admin']) && empty($_POST['link_admin_user_id_create'])) {
                        $admin_email = cleanInput($_POST['admin_email'] ?? '');
                        $admin_username = cleanInput($_POST['admin_username'] ?? '');
                        $admin_password = $_POST['admin_password'] ?? '';
                        if ($admin_email && $admin_username && $admin_password) {
                            // ensure uniqueness
                            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                            $chk->execute([$admin_username, $admin_email]);
                            if ((int)$chk->fetchColumn() === 0) {
                                $stmtU = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES (?, ?, ?, 'admin_associazione', ?)");
                                $stmtU->execute([$admin_username, $admin_email, password_hash($admin_password, PASSWORD_DEFAULT), $assoc_id]);
                            } else {
                                $message .= ' (Admin non creato: username/email già esistenti)';
                                $messageType = 'warning';
                            }
                        }
                    }

                    // Optional: create initial sede
                    if (!empty($_POST['create_sede'])) {
                        $sede_nome = cleanInput($_POST['sede_nome'] ?? 'Sede Principale');
                        $sede_indirizzo = cleanInput($_POST['sede_indirizzo'] ?? '');
                        $sede_citta = cleanInput($_POST['sede_citta'] ?? '');
                        $sede_resp = cleanInput($_POST['sede_responsabile'] ?? '');
                        $stmtS = $pdo->prepare("INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, responsabile) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmtS->execute([generateUuid(), $assoc_id, $sede_nome, $sede_indirizzo, $sede_citta, $sede_resp]);
                    }

                    // Clonazione rapida da un'altra associazione
                    $clone_from = $_POST['clone_from_assoc_id'] ?? '';
                    $do_clone = !empty($_POST['clone_enable']) && $clone_from !== '';
                    if ($do_clone) {
                        // Mappature per tipi (per template tessera)
                        $tipo_map = [];
                            if (!empty($_POST['clone_tipi'])) {
                                $sel = $pdo->prepare("SELECT * FROM tipi_socio WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $newId = generateUuid();
                                    $ins = $pdo->prepare("INSERT INTO tipi_socio (id, associazione_id, nome, descrizione, costo_tessera) VALUES (?, ?, ?, ?, ?)");
                                    $ins->execute([$newId, $assoc_id, $row['nome'], $row['descrizione'], $row['costo_tessera']]);
                                    $tipo_map[$row['id']] = $newId;
                                }
                            }
                            if (!empty($_POST['clone_categorie'])) {
                                $sel = $pdo->prepare("SELECT * FROM categorie_socio WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO categorie_socio (id, associazione_id, nome, descrizione) VALUES (?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome'], $row['descrizione']]);
                                }
                            }
                            if (!empty($_POST['clone_sedi'])) {
                                $sel = $pdo->prepare("SELECT * FROM sedi WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, provincia, cap, email, telefono, responsabile) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome'], $row['indirizzo'], $row['citta'], $row['provincia'], $row['cap'], $row['email'], $row['telefono'], $row['responsabile']]);
                                }
                            }
                            if (!empty($_POST['clone_campi'])) {
                                $sel = $pdo->prepare("SELECT * FROM campi_personalizzati WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO campi_personalizzati (id, associazione_id, nome_campo, tipo_campo, descrizione, opzioni, obbligatorio, ordine) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_campo'], $row['tipo_campo'], $row['descrizione'], $row['opzioni'], $row['obbligatorio'], $row['ordine']]);
                                }
                            }
                            if (!empty($_POST['clone_tags'])) {
                                $sel = $pdo->prepare("SELECT * FROM tags WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO tags (id, associazione_id, nome_tag, colore) VALUES (?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_tag'], $row['colore']]);
                                }
                            }
                            if (!empty($_POST['clone_gruppi'])) {
                                $sel = $pdo->prepare("SELECT * FROM gruppi_dinamici WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $ins = $pdo->prepare("INSERT INTO gruppi_dinamici (id, associazione_id, nome_gruppo, descrizione, filtri_json) VALUES (?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $row['nome_gruppo'], $row['descrizione'], $row['filtri_json']]);
                                }
                            }
                            if (!empty($_POST['clone_templates']) && tableExists($pdo, 'tessera_templates')) {
                                $sel = $pdo->prepare("SELECT * FROM tessera_templates WHERE associazione_id = ?");
                                $sel->execute([$clone_from]);
                                foreach ($sel->fetchAll() as $row) {
                                    $mapped_tipo = $row['tipo_socio_id'] ? ($tipo_map[$row['tipo_socio_id']] ?? null) : null;
                                    $ins = $pdo->prepare("INSERT INTO tessera_templates (id, associazione_id, tipo_socio_id, titolo, contenuto, attivo) VALUES (?, ?, ?, ?, ?, ?)");
                                    $ins->execute([generateUuid(), $assoc_id, $mapped_tipo, $row['titolo'], $row['contenuto'], $row['attivo']]);
                                }
                            }
                    }
                }
            }
    }
}
}

// Data for list and editing
$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM associazioni WHERE id = ? LIMIT 1");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch();
}

$rows = $pdo->query("SELECT a.*, 
    (SELECT COUNT(*) FROM soci s WHERE s.associazione_id = a.id) AS soci_count,
    (SELECT COUNT(*) FROM sedi se WHERE se.associazione_id = a.id) AS sedi_count
    FROM associazioni a ORDER BY a.nome")->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Associazioni</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assocModal">
        <i class="bi bi-plus-lg"></i> Nuova Associazione
    </button>
    <input type="hidden" id="csrf_token_assoc" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" id="edit_assoc_id" value="<?php echo htmlspecialchars($editing['id'] ?? ''); ?>">
    <input type="hidden" id="editing_flag" value="<?php echo $editing ? '1' : '0'; ?>">
    <script>document.addEventListener('DOMContentLoaded',function(){if(document.getElementById('editing_flag').value==='1'){new bootstrap.Modal(document.getElementById('assocModal')).show();}});</script>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Elenco Associazioni</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Stato</th>
                        <th>Sedi</th>
                        <th>Soci</th>
                        <th>Scadenza</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($r['logo_url'])): ?>
                                    <img src="<?php echo htmlspecialchars($r['logo_url']); ?>" alt="logo" style="height:24px;width:auto;object-fit:contain;">
                                <?php endif; ?>
                                <strong><?php echo htmlspecialchars($r['nome']); ?></strong>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($r['email']); ?></td>
                        <td><?php echo $r['attiva'] ? '<span class="badge bg-success">Attiva</span>' : '<span class="badge bg-secondary">Disattiva</span>'; ?></td>
                        <td><span class="badge bg-info"><?php echo (int)$r['sedi_count']; ?></span></td>
                        <td><span class="badge bg-primary"><?php echo (int)$r['soci_count']; ?></span></td>
                        <td><span class="badge bg-light text-dark"><?php echo htmlspecialchars($r['tipo_scadenza_default']); ?></span></td>
                        <td class="text-end">
                            <a href="index.php?page=associazioni&edit=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa associazione? Tutti i dati collegati saranno rimossi.')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer small text-muted">Totale: <?php echo count($rows); ?> associazioni</div>
    
</div>

<!-- Modal Create/Edit -->
<div class="modal fade" id="assocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo $editing ? 'Modifica Associazione' : 'Nuova Associazione'; ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editing['id'] ?? ''); ?>">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Logo (PNG/JPG, max 2MB)</label>
                    <input type="file" class="form-control" name="logo_file" accept="image/png,image/jpeg">
                    <?php if (!empty($editing['logo_url'])): ?>
                        <div class="form-text">Logo attuale: <a href="<?php echo htmlspecialchars($editing['logo_url']); ?>" target="_blank">visualizza</a></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nome</label>
                    <input type="text" class="form-control" name="nome" value="<?php echo htmlspecialchars($editing['nome'] ?? ''); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($editing['email'] ?? ''); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Partita IVA</label>
                    <input type="text" class="form-control" name="partita_iva" value="<?php echo htmlspecialchars($editing['partita_iva'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Codice Fiscale</label>
                    <input type="text" class="form-control" name="codice_fiscale" value="<?php echo htmlspecialchars($editing['codice_fiscale'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefono</label>
                    <input type="text" class="form-control" name="telefono" value="<?php echo htmlspecialchars($editing['telefono'] ?? ''); ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Indirizzo</label>
                    <input type="text" class="form-control" name="indirizzo" value="<?php echo htmlspecialchars($editing['indirizzo'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Città</label>
                    <input type="text" class="form-control" name="citta" value="<?php echo htmlspecialchars($editing['citta'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Provincia</label>
                    <input type="text" class="form-control" name="provincia" maxlength="2" value="<?php echo htmlspecialchars($editing['provincia'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CAP</label>
                    <input type="text" class="form-control" name="cap" maxlength="5" value="<?php echo htmlspecialchars($editing['cap'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Costo Tessera (EUR)</label>
                    <input type="number" step="0.01" class="form-control" name="costo_tessera" value="<?php echo htmlspecialchars($editing['costo_tessera'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Attiva</label>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="attivaCheck" name="attiva" <?php echo empty($editing) || !empty($editing['attiva']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="attivaCheck">Associazione attiva</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo Scadenza</label>
                    <select class="form-select" name="tipo_scadenza_default">
                        <?php $ts = $editing['tipo_scadenza_default'] ?? 'solare'; ?>
                        <option value="solare" <?php echo $ts==='solare'?'selected':''; ?>>Solare (31/12)</option>
                        <option value="annuale" <?php echo $ts==='annuale'?'selected':''; ?>>Annuale (12 mesi)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Giorni Preavviso Scadenza</label>
                    <input type="number" class="form-control" name="giorni_notifica_scadenza" value="<?php echo htmlspecialchars($editing['giorni_notifica_scadenza'] ?? '30'); ?>">
                </div>
            </div>
            <?php if (!$editing): ?>
            <hr>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="createAdminSwitch" name="create_admin">
                        <label class="form-check-label" for="createAdminSwitch">Crea anche un admin per questa associazione</label>
                    </div>
                </div>
            </div>
            <div id="adminNewFields" class="row g-3" style="display:none;">
                <div class="col-md-4">
                    <label class="form-label">Username Admin</label>
                    <input type="text" class="form-control" name="admin_username">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Admin</label>
                    <input type="email" class="form-control" name="admin_email">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Password Admin</label>
                    <input type="password" class="form-control" name="admin_password">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="linkAdminSwitch" name="link_existing_admin">
                        <label class="form-check-label" for="linkAdminSwitch">Oppure collega un admin esistente</label>
                    </div>
                </div>
            </div>
            <div id="adminLinkFields" class="row g-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">Cerca admin (email o username)</label>
                    <input type="text" class="form-control" id="adminSearchInputCreate" placeholder="Cerca admin...">
                    <input type="hidden" name="link_admin_user_id_create" id="link_admin_user_id_create">
                    <div id="adminSearchResultsCreate" class="border rounded mt-1" style="display:none; max-height:180px; overflow:auto;"></div>
                    <div class="form-text">Verrà collegato all'associazione; non sono ammessi super admin, né admin già collegati ad un'altra associazione.</div>
                </div>
            </div>
            <div class="row g-3 mt-2">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="createSedeSwitch" name="create_sede" checked>
                        <label class="form-check-label" for="createSedeSwitch">Crea una sede iniziale</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nome Sede</label>
                    <input type="text" class="form-control" name="sede_nome" value="Sede Principale">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Responsabile</label>
                    <input type="text" class="form-control" name="sede_responsabile">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Indirizzo Sede</label>
                    <input type="text" class="form-control" name="sede_indirizzo">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Città Sede</label>
                    <input type="text" class="form-control" name="sede_citta">
                </div>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="cloneEnableSwitch" name="clone_enable">
                        <label class="form-check-label" for="cloneEnableSwitch">Clona configurazioni da un'altra associazione</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Associazione sorgente</label>
                    <select class="form-select" name="clone_from_assoc_id">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_tipi" id="cloneTipi"><label class="form-check-label ms-1" for="cloneTipi">Tipi socio</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_categorie" id="cloneCategorie"><label class="form-check-label ms-1" for="cloneCategorie">Categorie socio</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_sedi" id="cloneSedi"><label class="form-check-label ms-1" for="cloneSedi">Sedi</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_campi" id="cloneCampi"><label class="form-check-label ms-1" for="cloneCampi">Campi personalizzati</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_tags" id="cloneTags"><label class="form-check-label ms-1" for="cloneTags">Tag</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_gruppi" id="cloneGruppi"><label class="form-check-label ms-1" for="cloneGruppi">Gruppi dinamici</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_templates" id="cloneTemplates"><label class="form-check-label ms-1" for="cloneTemplates">Template tessera</label></div>
                    </div>
                    <div class="form-text">I template legati ai tipi socio vengono mappati automaticamente se cloni anche i tipi.</div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($editing): ?>
            <hr>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="createAdminSwitchEdit" name="create_admin">
                        <label class="form-check-label" for="createAdminSwitchEdit">Crea un admin per questa associazione</label>
                    </div>
                </div>
            </div>
            <div id="adminNewFieldsEdit" class="row g-3" style="display:none;">
                <div class="col-md-4">
                    <label class="form-label">Username Admin</label>
                    <input type="text" class="form-control" name="admin_username">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email Admin</label>
                    <input type="email" class="form-control" name="admin_email">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Password Admin</label>
                    <input type="password" class="form-control" name="admin_password">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="linkAdminSwitchEdit" name="link_existing_admin_edit">
                        <label class="form-check-label" for="linkAdminSwitchEdit">Oppure collega un admin esistente</label>
                    </div>
                </div>
            </div>
            <div id="adminLinkFieldsEdit" class="row g-3" style="display:none;">
                <div class="col-12">
                    <label class="form-label">Cerca admin (email o username)</label>
                    <input type="text" class="form-control" id="adminSearchInput" placeholder="Cerca admin...">
                    <input type="hidden" name="link_admin_user_id" id="link_admin_user_id">
                    <div id="adminSearchResults" class="border rounded mt-1" style="display:none; max-height:180px; overflow:auto;"></div>
                    <div class="form-text">Solo utenti con ruolo non super_admin e non già collegati. L'utente diventerà admin di questa associazione.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Amministratori correnti</label>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Username</th><th>Email</th><th class="text-end">Azioni</th></tr></thead>
                            <tbody>
                            <?php $admins = $pdo->prepare("SELECT id, username, email FROM users WHERE associazione_id = ? AND role = 'admin_associazione' ORDER BY username"); $admins->execute([$editing['id']]); foreach ($admins->fetchAll() as $u): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Rimuovere questo amministratore dall\'associazione?')">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($editing['id']); ?>">
                                            <input type="hidden" name="remove_admin_user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <hr>
            <div class="row g-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="cloneEnableEditSwitch" name="clone_enable_edit">
                        <label class="form-check-label" for="cloneEnableEditSwitch">Clona configurazioni da un'altra associazione (su questa esistente)</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Associazione sorgente</label>
                    <select class="form-select" name="clone_from_assoc_id">
                        <option value="">-- Seleziona --</option>
                        <?php foreach ($rows as $r): if (($editing['id'] ?? '') === $r['id']) continue; ?>
                            <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap gap-3">
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_tipi" id="cloneTipiE"><label class="form-check-label ms-1" for="cloneTipiE">Tipi socio</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_categorie" id="cloneCategorieE"><label class="form-check-label ms-1" for="cloneCategorieE">Categorie socio</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_sedi" id="cloneSediE"><label class="form-check-label ms-1" for="cloneSediE">Sedi</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_campi" id="cloneCampiE"><label class="form-check-label ms-1" for="cloneCampiE">Campi personalizzati</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_tags" id="cloneTagsE"><label class="form-check-label ms-1" for="cloneTagsE">Tag</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_gruppi" id="cloneGruppiE"><label class="form-check-label ms-1" for="cloneGruppiE">Gruppi dinamici</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="clone_templates" id="cloneTemplatesE"><label class="form-check-label ms-1" for="cloneTemplatesE">Template tessera</label></div>
                    </div>
                    <div class="form-text">La clonazione non include utenti/admin.</div>
                </div>
            </div>
            <script>
                (function(){
                    const createChk = document.getElementById('createAdminSwitchEdit');
                    const linkChk = document.getElementById('linkAdminSwitchEdit');
                    const newBox = document.getElementById('adminNewFieldsEdit');
                    const linkBox = document.getElementById('adminLinkFieldsEdit');
                    function sync() {
                        if (newBox) newBox.style.display = (createChk && createChk.checked) ? '' : 'none';
                        if (linkBox) linkBox.style.display = (linkChk && linkChk.checked) ? '' : 'none';
                    }
                    if (createChk) createChk.addEventListener('change', function(){ if (this.checked && linkChk){ linkChk.checked = false; } sync(); });
                    if (linkChk) linkChk.addEventListener('change', function(){ if (this.checked && createChk){ createChk.checked = false; } sync(); });
                    sync();

                    const box = document.getElementById('adminSearchResults');
                    const inp = document.getElementById('adminSearchInput');
                    const hid = document.getElementById('link_admin_user_id');
                    let timer;
                    function render(items){
                        if (!items || items.length===0){ box.style.display='none'; box.innerHTML=''; return; }
                        const filtered = items.filter(u => u.role === 'admin_associazione' && (u.associazione_id === null || u.associazione_id === ''));
                        if (filtered.length === 0){ box.style.display='none'; box.innerHTML=''; return; }
                        box.innerHTML = filtered.map(u=>`<div class="p-2 list-group-item list-group-item-action" data-id="${u.id}" style="cursor:pointer">${u.username} &lt;${u.email}&gt; — ${u.role}</div>`).join('');
                        box.style.display='block';
                        box.querySelectorAll('[data-id]').forEach(el=>{
                            el.addEventListener('click', ()=>{ hid.value = el.getAttribute('data-id'); inp.value = el.textContent.trim(); box.style.display='none'; });
                        });
                    }
                    if (inp) {
                        inp.addEventListener('input', ()=>{
                            clearTimeout(timer);
                            const q = inp.value.trim();
                            if (q.length < 3){ render([]); return; }
                            timer = setTimeout(()=>{
                                fetch('api/users_search.php?only_admin_assoc=1&available=1&q=' + encodeURIComponent(q))
                                    .then(r=>r.json()).then(d=>{ if (d && d.success) render(d.items); else render([]); })
                                    .catch(()=>render([]));
                            }, 300);
                        });
                    }
                })();
            </script>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="submit" class="btn btn-primary">Salva</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Toggle new admin vs link existing (creation)
document.addEventListener('DOMContentLoaded', function(){
  const createChk = document.getElementById('createAdminSwitch');
  const linkChk = document.getElementById('linkAdminSwitch');
  const newBox = document.getElementById('adminNewFields');
  const linkBox = document.getElementById('adminLinkFields');
  function sync() {
    newBox.style.display = createChk && createChk.checked ? '' : 'none';
    linkBox.style.display = linkChk && linkChk.checked ? '' : 'none';
  }
  if (createChk) createChk.addEventListener('change', function(){ if (this.checked && linkChk){ linkChk.checked = false; } sync(); });
  if (linkChk) linkChk.addEventListener('change', function(){ if (this.checked && createChk){ createChk.checked = false; } sync(); });
  sync();

  // Autocomplete for create modal
  const box = document.getElementById('adminSearchResultsCreate');
  const inp = document.getElementById('adminSearchInputCreate');
  const hid = document.getElementById('link_admin_user_id_create');
  if (inp && box && hid) {
    let timer;
    function render(items){
      if (!items || items.length===0){ box.style.display='none'; box.innerHTML=''; return; }
      // filter out super_admin and those already linked
      const filtered = items.filter(u => u.role !== 'super_admin' && (u.associazione_id === null || u.associazione_id === '' ));
      if (filtered.length === 0){ box.style.display='none'; box.innerHTML=''; return; }
      box.innerHTML = filtered.map(u=>`<div class="p-2 list-group-item list-group-item-action" data-id="${u.id}" style="cursor:pointer">${u.username} &lt;${u.email}&gt; — ${u.role}</div>`).join('');
      box.style.display='block';
      box.querySelectorAll('[data-id]').forEach(el=>{
        el.addEventListener('click', ()=>{ hid.value = el.getAttribute('data-id'); inp.value = el.textContent.trim(); box.style.display='none'; });
      });
    }
    inp.addEventListener('input', ()=>{
      clearTimeout(timer);
      const q = inp.value.trim();
      if (q.length < 3){ render([]); return; }
      timer = setTimeout(()=>{
        fetch('api/users_search.php?only_admin_assoc=1&available=1&q=' + encodeURIComponent(q))
          .then(r=>r.json()).then(d=>{ if (d && d.success) render(d.items); else render([]); })
          .catch(()=>render([]));
      }, 300);
    });
  }
});
</script>
