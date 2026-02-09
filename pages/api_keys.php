<?php
// pages/api_keys.php - Gestione API Keys per associazione

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

require_once __DIR__ . '/../includes/api_middleware.php';
ensureApiKeysTable($pdo);

$isSuperAdmin = ($_SESSION['user_role'] ?? '') === 'super_admin';
$associazione_id = $_SESSION['associazione_id'] ?? null;

// Non-super_admin must have an association selected
if (!$isSuperAdmin && !$associazione_id) {
    redirect('auth/login.php');
}

$message = '';
$messageType = '';

// --- GESTIONE AZIONI POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $nome = cleanInput($_POST['nome'] ?? '');
            $scadenza = !empty($_POST['scadenza']) ? $_POST['scadenza'] : null;
            $permessi = $_POST['permessi'] ?? [];
            $isGlobale = $isSuperAdmin && !empty($_POST['globale']);

            if (empty($nome)) {
                $message = "Il nome della chiave API è obbligatorio.";
                $messageType = "danger";
            } elseif (!$isGlobale && !$associazione_id) {
                $message = "Seleziona un'associazione per creare una chiave non globale.";
                $messageType = "danger";
            } else {
                if (!is_array($permessi) || empty($permessi)) {
                    $permessi = ['tessere:read'];
                }

                $newKey = generateApiKey();
                $newId = generateUuid();
                $targetAssocId = $isGlobale ? null : $associazione_id;

                try {
                    $stmt = $pdo->prepare("INSERT INTO api_keys (id, associazione_id, api_key, nome, permessi, scadenza) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$newId, $targetAssocId, $newKey, $nome, json_encode($permessi), $scadenza]);
                    $label = $isGlobale ? 'Chiave API globale creata' : 'API Key creata';
                    $message = "$label con successo. Copiala ora, non sarà più visibile per intero: <code>" . htmlspecialchars($newKey) . "</code>";
                    $messageType = "success";
                } catch (PDOException $e) {
                    error_log('api_keys create error: ' . $e->getMessage());
                    $message = "Errore nella creazione della chiave API.";
                    $messageType = "danger";
                }
            }
        }

        if ($action === 'toggle') {
            $keyId = $_POST['key_id'] ?? '';
            if (!empty($keyId)) {
                try {
                    // Super admin can toggle any key; regular admin only their own association's keys
                    if ($isSuperAdmin) {
                        $stmt = $pdo->prepare("UPDATE api_keys SET attiva = NOT attiva WHERE id = ?");
                        $stmt->execute([$keyId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE api_keys SET attiva = NOT attiva WHERE id = ? AND associazione_id = ?");
                        $stmt->execute([$keyId, $associazione_id]);
                    }
                    $message = "Stato chiave API aggiornato.";
                    $messageType = "success";
                } catch (PDOException $e) {
                    error_log('api_keys toggle error: ' . $e->getMessage());
                    $message = "Errore nell'aggiornamento.";
                    $messageType = "danger";
                }
            }
        }

        if ($action === 'delete') {
            $keyId = $_POST['key_id'] ?? '';
            if (!empty($keyId)) {
                try {
                    if ($isSuperAdmin) {
                        $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ?");
                        $stmt->execute([$keyId]);
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ? AND associazione_id = ?");
                        $stmt->execute([$keyId, $associazione_id]);
                    }
                    $message = "Chiave API eliminata.";
                    $messageType = "success";
                } catch (PDOException $e) {
                    error_log('api_keys delete error: ' . $e->getMessage());
                    $message = "Errore nell'eliminazione.";
                    $messageType = "danger";
                }
            }
        }
    }
}

// --- RECUPERO DATI ---
$associazione_nome = '';
if ($associazione_id) {
    $stmt_assoc = $pdo->prepare("SELECT nome FROM associazioni WHERE id = ?");
    $stmt_assoc->execute([$associazione_id]);
    $associazione_nome = $stmt_assoc->fetchColumn() ?: 'Sconosciuta';
}

// Determine which keys to show
if ($isSuperAdmin && !$associazione_id) {
    // Super admin without association: show ALL keys from ALL associations + global
    $stmt = $pdo->prepare("
        SELECT ak.*, a.nome as associazione_nome
        FROM api_keys ak
        LEFT JOIN associazioni a ON ak.associazione_id = a.id
        ORDER BY ak.associazione_id IS NOT NULL, a.nome, ak.created_at DESC
    ");
    $stmt->execute();
} elseif ($isSuperAdmin && $associazione_id) {
    // Super admin with association: show keys for this association + global keys
    $stmt = $pdo->prepare("
        SELECT ak.*, a.nome as associazione_nome
        FROM api_keys ak
        LEFT JOIN associazioni a ON ak.associazione_id = a.id
        WHERE ak.associazione_id = ? OR ak.associazione_id IS NULL
        ORDER BY ak.associazione_id IS NOT NULL, ak.created_at DESC
    ");
    $stmt->execute([$associazione_id]);
} else {
    // Regular admin: only their association's keys
    $stmt = $pdo->prepare("SELECT ak.*, NULL as associazione_nome FROM api_keys ak WHERE ak.associazione_id = ? ORDER BY ak.created_at DESC");
    $stmt->execute([$associazione_id]);
}
$keys = $stmt->fetchAll();

$availablePermissions = [
    'tessere:read' => 'Tessere - Lettura',
    'tessere:write' => 'Tessere - Scrittura',
    'soci:read' => 'Soci - Lettura',
    'soci:write' => 'Soci - Scrittura',
    'eventi:read' => 'Eventi - Lettura',
    'eventi:write' => 'Eventi - Scrittura (check-in)',
    'quote:read' => 'Quote - Lettura',
    '*' => 'Accesso completo (tutti i permessi)',
];

?>

<div class="pt-3 pb-2 mb-3 border-bottom">
    <div class="d-flex justify-content-between align-items-center">
        <h1 class="h2"><i class="bi bi-key me-2"></i>Chiavi API</h1>
        <div>
            <a href="index.php?page=api-docs" class="btn btn-outline-primary me-2"><i class="bi bi-book me-1"></i>Documentazione API</a>
            <?php if ($associazione_id || $isSuperAdmin): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createKeyModal"><i class="bi bi-plus-lg me-1"></i>Nuova Chiave</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isSuperAdmin && !$associazione_id): ?>
<div class="alert alert-warning d-flex align-items-center mb-3">
    <i class="bi bi-globe me-2"></i>
    <div>Stai visualizzando le chiavi API di <strong>tutte le associazioni</strong>. Seleziona un'associazione dalla barra laterale per filtrare.</div>
</div>
<?php elseif ($associazione_id): ?>
<div class="alert alert-info d-flex align-items-center mb-3">
    <i class="bi bi-info-circle me-2"></i>
    <div>Le chiavi API sono collegate all'associazione <strong><?php echo htmlspecialchars($associazione_nome); ?></strong>.<?php if ($isSuperAdmin): ?> Le chiavi <span class="badge bg-warning text-dark">Globale</span> accedono a tutte le associazioni.<?php endif; ?></div>
</div>
<?php endif; ?>

<?php if (!empty($message)): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show">
    <?php echo $messageType === 'success' ? $message : htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (empty($keys)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-key display-1"></i>
            <p class="mt-3">Nessuna chiave API creata.</p>
            <p class="small">Le chiavi API permettono a client esterni (app mobile, scanner QR, sistemi di check-in) di accedere ai dati dell'associazione via REST API.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Chiave</th>
                        <th>Ambito</th>
                        <th>Permessi</th>
                        <th>Stato</th>
                        <th>Ultimo utilizzo</th>
                        <th>Scadenza</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keys as $k):
                        $perms = json_decode($k['permessi'], true) ?: [];
                        $masked = substr($k['api_key'], 0, 8) . '...' . substr($k['api_key'], -4);
                        $isKeyGlobal = ($k['associazione_id'] === null);
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($k['nome']); ?></strong></td>
                        <td><code class="small"><?php echo htmlspecialchars($masked); ?></code></td>
                        <td>
                            <?php if ($isKeyGlobal): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-globe me-1"></i>Globale</span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><?php echo htmlspecialchars($k['associazione_nome'] ?? $associazione_nome); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php foreach ($perms as $p): ?>
                            <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($p); ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($k['attiva']): ?>
                            <span class="badge bg-success">Attiva</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Disattivata</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php echo $k['ultimo_utilizzo'] ? date('d/m/Y H:i', strtotime($k['ultimo_utilizzo'])) : 'Mai'; ?>
                        </td>
                        <td class="small">
                            <?php
                            if ($k['scadenza']) {
                                $isExpired = $k['scadenza'] < date('Y-m-d');
                                echo '<span class="' . ($isExpired ? 'text-danger' : '') . '">' . date('d/m/Y', strtotime($k['scadenza'])) . '</span>';
                            } else {
                                echo '<span class="text-muted">Nessuna</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="key_id" value="<?php echo htmlspecialchars($k['id']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="<?php echo $k['attiva'] ? 'Disattiva' : 'Attiva'; ?>">
                                    <i class="bi bi-<?php echo $k['attiva'] ? 'pause-circle' : 'play-circle'; ?>"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa chiave API? L\'azione è irreversibile.')">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="key_id" value="<?php echo htmlspecialchars($k['id']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Creazione -->
<div class="modal fade" id="createKeyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Nuova Chiave API</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($isSuperAdmin): ?>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="globale" value="1" id="globaleSwitch">
                            <label class="form-check-label fw-bold" for="globaleSwitch">
                                <i class="bi bi-globe me-1"></i>Chiave Globale (tutte le associazioni)
                            </label>
                        </div>
                        <div class="alert alert-warning mt-2 mb-0 d-none" id="globaleWarning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>Attenzione:</strong> questa chiave avrà accesso ai dati di <strong>TUTTE</strong> le associazioni.
                        </div>
                    </div>
                    <hr>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Nome <span class="text-danger">*</span></label>
                        <input type="text" name="nome" class="form-control" placeholder="Es. App Check-in Eventi" required>
                        <div class="form-text">Un nome descrittivo per identificare l'uso di questa chiave.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Permessi <span class="text-danger">*</span></label>
                        <?php foreach ($availablePermissions as $perm => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input perm-checkbox" type="checkbox" name="permessi[]" value="<?php echo htmlspecialchars($perm); ?>" id="perm_<?php echo htmlspecialchars(str_replace([':', '*'], ['_', 'all'], $perm)); ?>"
                                <?php echo $perm === 'tessere:read' ? 'checked' : ''; ?>
                                <?php echo $perm === '*' ? 'data-wildcard="1"' : ''; ?>>
                            <label class="form-check-label" for="perm_<?php echo htmlspecialchars(str_replace([':', '*'], ['_', 'all'], $perm)); ?>">
                                <?php echo htmlspecialchars($label); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Data Scadenza (opzionale)</label>
                        <input type="date" name="scadenza" class="form-control">
                        <div class="form-text">Lascia vuoto per una chiave senza scadenza.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Crea Chiave</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Wildcard checkbox toggles all others
    var wildcard = document.querySelector('[data-wildcard="1"]');
    var others = document.querySelectorAll('.perm-checkbox:not([data-wildcard="1"])');
    if (wildcard) {
        wildcard.addEventListener('change', function() {
            others.forEach(function(cb) { cb.disabled = wildcard.checked; cb.checked = wildcard.checked; });
        });
    }

    // Global key switch - show/hide warning
    var globaleSwitch = document.getElementById('globaleSwitch');
    var globaleWarning = document.getElementById('globaleWarning');
    if (globaleSwitch && globaleWarning) {
        globaleSwitch.addEventListener('change', function() {
            if (globaleSwitch.checked) {
                globaleWarning.classList.remove('d-none');
            } else {
                globaleWarning.classList.add('d-none');
            }
        });
    }
});
</script>
