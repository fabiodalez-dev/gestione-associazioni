<?php
// pages/api_keys.php - Gestione API Keys per associazione

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

require_once __DIR__ . '/../includes/api_middleware.php';
ensureApiKeysTable($pdo);

$associazione_id = $_SESSION['associazione_id'];
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

            if (empty($nome)) {
                $message = "Il nome della chiave API è obbligatorio.";
                $messageType = "danger";
            } else {
                if (!is_array($permessi) || empty($permessi)) {
                    $permessi = ['tessere:read'];
                }

                $newKey = generateApiKey();
                $newId = generateUuid();

                try {
                    $stmt = $pdo->prepare("INSERT INTO api_keys (id, associazione_id, api_key, nome, permessi, scadenza) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$newId, $associazione_id, $newKey, $nome, json_encode($permessi), $scadenza]);
                    $message = "API Key creata con successo. Copiala ora, non sarà più visibile per intero: <code>" . htmlspecialchars($newKey) . "</code>";
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
                    $stmt = $pdo->prepare("UPDATE api_keys SET attiva = NOT attiva WHERE id = ? AND associazione_id = ?");
                    $stmt->execute([$keyId, $associazione_id]);
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
                    $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ? AND associazione_id = ?");
                    $stmt->execute([$keyId, $associazione_id]);
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
$stmt = $pdo->prepare("SELECT * FROM api_keys WHERE associazione_id = ? ORDER BY created_at DESC");
$stmt->execute([$associazione_id]);
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
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createKeyModal"><i class="bi bi-plus-lg me-1"></i>Nuova Chiave</button>
        </div>
    </div>
</div>

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
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($k['nome']); ?></strong></td>
                        <td><code class="small"><?php echo htmlspecialchars($masked); ?></code></td>
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
    const wildcard = document.querySelector('[data-wildcard="1"]');
    const others = document.querySelectorAll('.perm-checkbox:not([data-wildcard="1"])');
    if (wildcard) {
        wildcard.addEventListener('change', function() {
            others.forEach(function(cb) { cb.disabled = wildcard.checked; cb.checked = wildcard.checked; });
        });
    }
});
</script>
