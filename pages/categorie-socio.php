<?php
// pages/categorie-socio.php - v2.0 (SaaS)

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
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "danger";
    } else {
        $id = $_POST['id'] ?? null;
        $nome = cleanInput($_POST['nome'] ?? '');
        $descrizione = cleanInput($_POST['descrizione'] ?? '');
        $testo_tessera = cleanInput($_POST['testo_tessera'] ?? '');

        if (isset($_POST['delete_id'])) {
            $stmt = $pdo->prepare("DELETE FROM categorie_socio WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$_POST['delete_id'], $associazione_id]);
            // PRG via JS to avoid headers already sent
            $redir = 'index.php?page=categorie-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
            echo '<script>window.location.href='.json_encode($redir).';</script>';
            exit;
        } elseif ($id) {
            $stmt = $pdo->prepare("UPDATE categorie_socio SET nome=?, descrizione=?, testo_tessera=? WHERE id=? AND associazione_id=?");
            $stmt->execute([$nome, $descrizione, $testo_tessera, $id, $associazione_id]);
            $redir = 'index.php?page=categorie-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
            echo '<script>window.location.href='.json_encode($redir).';</script>';
            exit;
        } else {
            $new_id = generateUuid();
            $stmt = $pdo->prepare("INSERT INTO categorie_socio (id, associazione_id, nome, descrizione, testo_tessera) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$new_id, $associazione_id, $nome, $descrizione, $testo_tessera]);
            $redir = 'index.php?page=categorie-socio' . (($_SESSION['user_role'] === 'super_admin' && $associazione_id) ? ('&assoc_id=' . urlencode($associazione_id)) : '');
            echo '<script>window.location.href='.json_encode($redir).';</script>';
            exit;
        }
    }
}

// Recupero Dati (solo se abbiamo un associazione_id)
$editingCategory = null;
$categorie_socio = [];

if ($associazione_id) {
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM categorie_socio WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$_GET['edit'], $associazione_id]);
        $editingCategory = $stmt->fetch();
    }

    $stmt_categorie = $pdo->prepare("SELECT * FROM categorie_socio WHERE associazione_id = ? ORDER BY nome ASC");
    $stmt_categorie->execute([$associazione_id]);
    $categorie_socio = $stmt_categorie->fetchAll();
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Categorie di Socio</h1>
    <p class="text-muted">Gestisci le diverse categorie di socio per la tua associazione</p>
</div>

<div class="d-flex justify-content-between mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal"><i class="bi bi-plus-lg"></i> Nuova Categoria</button>
</div>

<div class="card">
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Nome</th><th>Descrizione</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
            <?php foreach ($categorie_socio as $cat): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($cat['nome']); ?></strong></td>
                    <td><?php 
                        $desc = $cat['descrizione'] ?? '';
                        for ($i=0; $i<3; $i++) { $desc = html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                        echo htmlspecialchars($desc, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
                    ?></td>
                    <td class="text-end">
                        <a href="index.php?page=categorie-socio&edit=<?php echo $cat['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa categoria?')"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><input type="hidden" name="delete_id" value="<?php echo $cat['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
<div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><?php echo $editingCategory ? 'Modifica' : 'Nuova'; ?> Categoria</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo $editingCategory['id'] ?? ''; ?>">
            <div class="mb-3"><label>Nome</label><input type="text" name="nome" class="form-control" value="<?php echo escapeOutput($editingCategory['nome'] ?? ''); ?>" required></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control" rows="3"><?php 
                $ed = $editingCategory['descrizione'] ?? '';
                for ($i=0; $i<3; $i++) { $ed = html_entity_decode($ed, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                echo htmlspecialchars($ed, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            ?></textarea></div>
            <div class="mb-3"><label>Testo per Tessera</label><textarea name="testo_tessera" class="form-control" rows="2" placeholder="Testo che apparirà sulla tessera (es. 'Diritti e doveri del socio...')"><?php 
                $tt = $editingCategory['testo_tessera'] ?? '';
                for ($i=0; $i<3; $i++) { $tt = html_entity_decode($tt, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                echo htmlspecialchars($tt, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            ?></textarea></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salva</button>
        </div>
    </form>
</div></div>
</div>

<?php if ($editingCategory): ?>
<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('categoryModal')).show());</script>
<?php endif; ?>
