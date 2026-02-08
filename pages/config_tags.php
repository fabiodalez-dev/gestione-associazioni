<?php
// pages/config_tags.php - v2.0 (SaaS)

if (!isUserLoggedIn(['admin_associazione', 'super_admin'])) {
    redirect('auth/login.php');
}
if (!isset($_SESSION['associazione_id'])) {
    redirect('index.php?page=dashboard');
}
$associazione_id = $_SESSION['associazione_id'];
$message = '';
$messageType = '';

// Gestione Azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        try {
            if (isset($_POST['delete_id'])) {
                $stmt = $pdo->prepare("DELETE FROM tags WHERE id = ? AND associazione_id = ?");
                $stmt->execute([$_POST['delete_id'], $associazione_id]);
                $message = "Tag eliminato con successo.";
                $messageType = "success";
            } else {
                $id = $_POST['id'] ?? null;
                $nome_tag = sanitizeInput($_POST['nome_tag'] ?? '');
                $colore = sanitizeInput($_POST['colore'] ?? '#888888');

                if ($id) {
                    $stmt = $pdo->prepare("UPDATE tags SET nome_tag=?, colore=? WHERE id=? AND associazione_id=?");
                    $stmt->execute([$nome_tag, $colore, $id, $associazione_id]);
                    $message = "Tag aggiornato con successo.";
                    $messageType = "success";
                } else {
                    $new_id = generateUuid();
                    $stmt = $pdo->prepare("INSERT INTO tags (id, associazione_id, nome_tag, colore) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$new_id, $associazione_id, $nome_tag, $colore]);
                    $message = "Tag creato con successo.";
                    $messageType = "success";
                }
            }
        } catch (PDOException $e) {
            error_log('config_tags.php PDOException: ' . $e->getMessage());
            $message = "Errore durante l'operazione. Riprova più tardi.";
            $messageType = "danger";
        }
    }
}

// Recupero Dati
$editingTag = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM tags WHERE id = ? AND associazione_id = ?");
    $stmt->execute([$_GET['edit'], $associazione_id]);
    $editingTag = $stmt->fetch();
}

$stmt_tags = $pdo->prepare("SELECT * FROM tags WHERE associazione_id = ? ORDER BY nome_tag ASC");
$stmt_tags->execute([$associazione_id]);
$tags = $stmt_tags->fetchAll();

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Gestione Tag</h1>
    <p class="text-muted">Crea etichette colorate da assegnare ai soci.</p>
</div>


<?php if ($message): ?><div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tagModal"><i class="bi bi-plus-lg"></i> Nuovo Tag</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Anteprima</th><th>Nome Tag</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($tags as $tag): ?>
                <tr>
                    <td><span class="badge" style="background-color: <?php echo htmlspecialchars($tag['colore']); ?>; color: white;"><?php echo htmlspecialchars($tag['nome_tag']); ?></span></td>
                    <td><strong><?php echo htmlspecialchars($tag['nome_tag']); ?></strong></td>
                    <td class="text-end">
                        <a href="index.php?page=config_tags&edit=<?php echo $tag['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo tag?')"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $tag['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="tagModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingTag ? 'Modifica' : 'Nuovo'; ?> Tag</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingTag['id'] ?? ''; ?>">
            <div class="mb-3"><label>Nome Tag</label><input type="text" name="nome_tag" class="form-control" value="<?php echo htmlspecialchars($editingTag['nome_tag'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Colore</label><input type="color" name="colore" class="form-control form-control-color" value="<?php echo htmlspecialchars($editingTag['colore'] ?? '#888888'); ?>" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingTag): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('tagModal')).show());</script>
<?php endif; ?>