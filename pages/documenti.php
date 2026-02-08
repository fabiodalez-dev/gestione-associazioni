<?php
// pages/documenti.php
if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

$associazione_id = $_SESSION['associazione_id'];

// Handle form submission for uploading document
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "error";
    } else {
    $nome_file = basename($_FILES["document_file"]["name"]);
    $descrizione = sanitizeInput($_POST['description']);
    $categoria = sanitizeInput($_POST['category']);
    
    // File upload handling
    $target_dir = "../uploads/documents/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = basename($_FILES["document_file"]["name"]);
    $target_file = $target_dir . uniqid() . "_" . $file_name;
    $uploadOk = 1;
    $fileType = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));
    
    // Check if file already exists
    if (file_exists($target_file)) {
        $message = "Errore: file già esistente.";
        $messageType = "error";
        $uploadOk = 0;
    }
    
    // Check file size (max 10MB)
    if ($_FILES["document_file"]["size"] > 10000000) {
        $message = "Errore: file troppo grande (max 10MB).";
        $messageType = "error";
        $uploadOk = 0;
    }
    
    // Allow certain file formats
    $allowed_types = ["pdf", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "jpg", "jpeg", "png"];
    if (!in_array($fileType, $allowed_types)) {
        $message = "Errore: formato file non permesso. Sono permessi solo PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, JPEG, PNG.";
        $messageType = "error";
        $uploadOk = 0;
    }
    
    // Check if $uploadOk is set to 0 by an error
    if ($uploadOk == 0) {
        if (!isset($message)) {
            $message = "Errore durante il caricamento del file.";
            $messageType = "error";
        }
    // if everything is ok, try to upload file
    } else {
        if (move_uploaded_file($_FILES["document_file"]["tmp_name"], $target_file)) {
            try {
                // Save document info to database
                $document_id = generateUuid();
                $stmt = $pdo->prepare("INSERT INTO documenti (id, associazione_id, nome_file, percorso_file, descrizione, categoria, caricato_da) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$document_id, $associazione_id, $nome_file, $target_file, $descrizione, $categoria, null]);
                
                $message = "Documento caricato con successo!";
                $messageType = "success";
            } catch (PDOException $e) {
                error_log('documenti.php upload PDOException: ' . $e->getMessage());
                $message = "Errore durante il salvataggio. Riprova più tardi.";
                $messageType = "error";
                
                // Delete uploaded file if database save fails
                unlink($target_file);
            }
        } else {
            $message = "Errore durante il caricamento del file.";
            $messageType = "error";
        }
    }
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    // Verify CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = "Errore di sicurezza: token CSRF non valido.";
        $messageType = "error";
    } else {
    $delete_id = $_POST['delete_id'];
    
    try {
        // Get file path before deletion
        $stmt = $pdo->prepare("SELECT percorso_file FROM documenti WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$delete_id, $associazione_id]);
        $document = $stmt->fetch();
        
        if ($document) {
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM documenti WHERE id = ? AND associazione_id = ?");
            $stmt->execute([$delete_id, $associazione_id]);
            
            // Delete file from server
            if (file_exists($document['percorso_file'])) {
                unlink($document['percorso_file']);
            }
            
            $message = "Documento eliminato con successo!";
            $messageType = "success";
        } else {
            $message = "Documento non trovato.";
            $messageType = "error";
        }
    } catch (PDOException $e) {
        error_log('documenti.php delete PDOException: ' . $e->getMessage());
        $message = "Errore durante l'eliminazione. Riprova più tardi.";
        $messageType = "error";
    }
    }
}

// Get documents list
try {
    $searchTerm = $_GET['search'] ?? '';
    $categoryFilter = $_GET['category'] ?? 'all';
    
    $sql = "SELECT * FROM documenti WHERE associazione_id = ?";
    $params = [$associazione_id];
    
    if ($searchTerm) {
        $sql .= " AND (nome_file LIKE ? OR descrizione LIKE ?)";
        $params[] = "%$searchTerm%";
        $params[] = "%$searchTerm%";
    }
    
    if ($categoryFilter !== 'all') {
        $sql .= " AND categoria = ?";
        $params[] = $categoryFilter;
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $documents = $stmt->fetchAll();
    
    // Get categories for filter
    $stmt = $pdo->prepare("SELECT DISTINCT categoria FROM documenti WHERE associazione_id = ? ORDER BY categoria");
    $stmt->execute([$associazione_id]);
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('documenti.php fetch PDOException: ' . $e->getMessage());
    die("Errore nel recupero dei documenti. Riprova più tardi.");
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Documenti</h1>
    <p class="text-muted">Gestisci i documenti dell'associazione</p>
</div>

<?php if (isset($message)): ?>
    <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">Carica Nuovo Documento</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <div class="mb-3">
                <label class="form-label">Descrizione</label>
                <textarea class="form-control" name="description" rows="3"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Categoria</label>
                <input type="text" class="form-control" name="category" placeholder="es. Statuti, Verbali, Regolamenti">
            </div>
            <div class="mb-3">
                <label class="form-label">File</label>
                <input type="file" class="form-control" name="document_file" id="document_file" required>
                <div class="form-text">Formati permessi: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, JPEG, PNG (max 10MB)</div>
                <div id="file_name_preview" class="mt-2 text-muted small" style="display: none;">
                    <i class="bi bi-file-earmark"></i> <span id="selected_file_name"></span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-upload"></i> Carica Documento
            </button>
        </form>
    </div>
</div>

<!-- Search and Filter -->
<div class="row mb-3">
    <div class="col-md-6">
        <form method="GET">
            <input type="hidden" name="page" value="documenti">
            <div class="input-group">
                <input type="text" class="form-control" placeholder="Cerca per nome file o descrizione..." name="search" value="<?php echo htmlspecialchars($searchTerm); ?>">
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
    <div class="col-md-6">
        <form method="GET">
            <input type="hidden" name="page" value="documenti">
            <select class="form-select" name="category" onchange="this.form.submit()">
                <option value="all">Tutte le categorie</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- Documents Table -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0">Elenco Documenti</h5>
        <p class="text-muted mb-0"><?php echo count($documents); ?> documenti trovati</p>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Nome File</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th class="text-end">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($doc['nome_file']); ?></strong>
                                    <div class="text-muted small"><?php echo htmlspecialchars($doc['descrizione'] ?? ''); ?></div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($doc['categoria'] ?? 'N/D'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($doc['created_at'])); ?></td>
                            <td class="text-end">
                                <a href="<?php echo htmlspecialchars($doc['percorso_file']); ?>" class="btn btn-sm btn-outline-primary" download>
                                    <i class="bi bi-download"></i> Scarica
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo documento?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $doc['id']; ?>">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('document_file');
    const fileNamePreview = document.getElementById('file_name_preview');
    const selectedFileName = document.getElementById('selected_file_name');

    if (fileInput && fileNamePreview && selectedFileName) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                selectedFileName.textContent = this.files[0].name;
                fileNamePreview.style.display = 'block';
            } else {
                fileNamePreview.style.display = 'none';
            }
        });
    }
});
</script>