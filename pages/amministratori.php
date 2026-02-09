<?php
// pages/amministratori.php
include 'config.php';

// Handle form submission for adding/editing administrators
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } elseif (isset($_POST['delete_id'])) {
        $deleteId = $_POST['delete_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$deleteId]);
            if ($stmt->rowCount() > 0) {
                $message = "Amministratore eliminato con successo!";
                $messageType = "success";
            } else {
                $message = "Amministratore non trovato.";
                $messageType = "warning";
            }
        } catch (PDOException $e) {
            error_log('amministratori.php delete error: ' . $e->getMessage());
            $message = "Errore durante l'eliminazione. Riprova più tardi.";
            $messageType = "danger";
        }
    } elseif (isset($_POST['add_admin'])) {
        // Add administrator
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        $role = sanitizeInput($_POST['role']);
        $associazione_id = $_POST['associazione_id'] ?? null;
        
        // Validate required fields
        if (empty($username) || empty($email) || empty($password)) {
            $message = "Tutti i campi sono obbligatori!";
            $messageType = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Email non valida!";
            $messageType = "error";
        } elseif (strlen($password) < 8) {
            $message = "La password deve avere almeno 8 caratteri!";
            $messageType = "error";
        } elseif ($role === 'admin_associazione' && empty($associazione_id)) {
            $message = "Gli admin di associazione devono essere collegati a un'associazione!";
            $messageType = "error";
        } else {
            try {
                // Check if username or email already exists
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $email]);
                if ($stmt->fetch()['count'] > 0) {
                    $message = "Username o email già esistenti!";
                    $messageType = "error";
                } else {
                    // Insert new administrator
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    if ($role === 'super_admin') {
                        $associazione_id = null; // Super admin non sono legati a specifiche associazioni
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, associazione_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$username, $email, $password_hash, $role, $associazione_id]);
                    
                    $message = "Amministratore aggiunto con successo!";
                    $messageType = "success";
                }
            } catch (Exception $e) {
                error_log('amministratori.php add error: ' . $e->getMessage());
                $message = "Errore durante l'aggiunta. Riprova più tardi.";
                $messageType = "error";
            }
        }
    } elseif (isset($_POST['edit_admin'])) {
        // Edit administrator
        $id = $_POST['id'] ?? null;
        $username = sanitizeInput($_POST['username']);
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'] ?? '';
        $role = sanitizeInput($_POST['role']);
        $associazione_id = $_POST['associazione_id'] ?? null;

        if (!$id) {
            $message = "ID amministratore mancante"; $messageType = "error";
        } else {
            try {
                // Check unique constraints for username/email (excluding current)
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id <> ?");
                $stmt->execute([$username, $email, $id]);
                if ($stmt->fetchColumn() > 0) {
                    $message = "Username o email già esistenti!"; $messageType = "error";
                } else {
                    if ($role === 'super_admin') { $associazione_id = null; }
                    if (!empty($password) && strlen($password) < 8) {
                        $message = "La password deve avere almeno 8 caratteri!"; $messageType = "error";
                    } else {
                        if (!empty($password)) {
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, password_hash=?, role=?, associazione_id=? WHERE id=?");
                            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $associazione_id, $id]);
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET username=?, email=?, role=?, associazione_id=? WHERE id=?");
                            $stmt->execute([$username, $email, $role, $associazione_id, $id]);
                        }
                        $message = "Amministratore aggiornato con successo!"; $messageType = "success";
                    }
                }
            } catch (Exception $e) {
                error_log('amministratori.php update error: ' . $e->getMessage());
                $message = "Errore durante l'aggiornamento. Riprova più tardi."; $messageType = "error";
            }
        }
    }
}

// Create users table if not exists with association support
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin_associazione', 'super_admin') DEFAULT 'admin_associazione',
        associazione_id CHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL,
        FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL
    )");
    
    // Add associazione_id column if it doesn't exist (for existing installations)
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN associazione_id CHAR(36) NULL AFTER role");
        $pdo->exec("ALTER TABLE users ADD FOREIGN KEY (associazione_id) REFERENCES associazioni(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    // Update role enum to use correct values
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin_associazione', 'super_admin') DEFAULT 'admin_associazione'");
    } catch (PDOException $e) {
        // Might fail if there are existing records with 'admin' role
    }
    
    // Default admin creation removed — the installer (install.php) handles initial admin creation securely.
} catch (PDOException $e) {
    // Table might already exist
}

// Get administrators from database with association info
try {
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, u.role, u.associazione_id, u.created_at, u.last_login,
               a.nome as associazione_nome
        FROM users u 
        LEFT JOIN associazioni a ON u.associazione_id = a.id 
        ORDER BY u.role DESC, u.username
    ");
    $administrators = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('amministratori.php fetch error: ' . $e->getMessage());
    die("Errore nel caricamento degli amministratori. Riprova più tardi.");
}

// Editing user (open modal)
$editingUser = null;
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $editingUser = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('amministratori.php edit fetch error: ' . $e->getMessage());
    }
}

// Get all associations for the form
try {
    $stmt = $pdo->query("SELECT id, nome FROM associazioni WHERE attiva = 1 ORDER BY nome");
    $associazioni = $stmt->fetchAll();
} catch (PDOException $e) {
    $associazioni = [];
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Amministratori</h1>
    <p class="text-muted">Gestisci gli amministratori del sistema</p>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adminModal">
        <i class="bi bi-plus-lg"></i> Aggiungi Amministratore
    </button>
</div>

<!-- Administrators Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Elenco Amministratori</h5>
        <p class="text-muted mb-0"><?php echo count($administrators); ?> amministratori trovati</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                        <th>Associazione</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($administrators as $admin): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $admin['role'] === 'super_admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo $admin['role'] === 'super_admin' ? 'Super Admin' : 'Admin Associazione'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($admin['associazione_nome']): ?>
                                    <span class="badge bg-info"><?php echo htmlspecialchars($admin['associazione_nome']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Tutte le associazioni</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="index.php?page=amministratori&edit=<?php echo htmlspecialchars($admin['id'], ENT_QUOTES); ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i> Modifica
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo amministratore?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo htmlspecialchars($admin['id'], ENT_QUOTES); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Elimina
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Admin Modal -->
<div class="modal fade" id="adminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $editingUser ? 'Modifica amministratore' : 'Aggiungi nuovo amministratore'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <?php if ($editingUser): ?>
                    <input type="hidden" name="edit_admin" value="1">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($editingUser['id']); ?>">
                <?php else: ?>
                    <input type="hidden" name="add_admin" value="1">
                <?php endif; ?>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" value="<?php echo htmlspecialchars($editingUser['username'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($editingUser['email'] ?? ''); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password <?php echo $editingUser ? '<small class="text-muted">(lascia vuoto per non cambiare)</small>' : ''; ?></label>
                        <input type="password" class="form-control" name="password" <?php echo $editingUser ? '' : 'required'; ?>>
                        <div class="form-text">Minimo 8 caratteri</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ruolo</label>
                        <select class="form-select" name="role" id="roleSelect" required>
                            <option value="">-- Seleziona Ruolo --</option>
                            <option value="admin_associazione" <?php echo ($editingUser['role'] ?? '')==='admin_associazione'?'selected':''; ?>>Admin Associazione</option>
                            <option value="super_admin" <?php echo ($editingUser['role'] ?? '')==='super_admin'?'selected':''; ?>>Super Admin</option>
                        </select>
                        <div class="form-text">Super Admin gestiscono tutto il sistema, Admin Associazione solo la loro associazione</div>
                    </div>
                    <div class="mb-3" id="associazioneDiv" style="display: none;">
                        <label class="form-label">Associazione <span class="text-danger">*</span></label>
                        <select class="form-select" name="associazione_id" id="associazioneSelect">
                            <option value="">-- Seleziona Associazione --</option>
                            <?php foreach ($associazioni as $assoc): ?>
                                <option value="<?php echo htmlspecialchars($assoc['id']); ?>" <?php echo (($editingUser['associazione_id'] ?? '')===$assoc['id'])?'selected':''; ?>>
                                    <?php echo htmlspecialchars($assoc['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">L'admin potrà gestire solo questa associazione</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?php echo $editingUser ? 'Salva' : 'Aggiungi'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const associazioneDiv = document.getElementById('associazioneDiv');
    const associazioneSelect = document.getElementById('associazioneSelect');

    if (!roleSelect || !associazioneDiv || !associazioneSelect) return;

    function syncRole(){
        if (roleSelect.value === 'admin_associazione') {
            associazioneDiv.style.display = 'block';
            associazioneSelect.required = true;
        } else {
            associazioneDiv.style.display = 'none';
            associazioneSelect.required = false;
            associazioneSelect.value = '';
        }
    }
    roleSelect.addEventListener('change', syncRole);
    // Prefill on edit
    syncRole();
    <?php if ($editingUser): ?>
    // Mostra subito la modale in modalità modifica
    new bootstrap.Modal(document.getElementById('adminModal')).show();
    <?php endif; ?>
});
</script>
