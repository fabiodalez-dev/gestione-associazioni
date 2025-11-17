<?php
/**
 * Advanced Custom Fields Manager
 * Interfaccia per gestire gruppi di campi personalizzati e campi
 */

if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

// Solo admin può gestire campi personalizzati
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    redirect('index.php?page=dashboard');
}

// Database tables
$table_groups = 'acf_field_groups';
$table_fields = 'acf_fields';
$table_values = 'acf_values';

$message = '';
$message_type = 'success';
$action = $_GET['action'] ?? 'list';
$group_id = $_GET['group_id'] ?? null;
$field_id = $_GET['field_id'] ?? null;

// Field types definition
$field_types = [
    'text' => 'Testo',
    'textarea' => 'Testo Lungo',
    'number' => 'Numero',
    'email' => 'Email',
    'phone' => 'Telefono',
    'url' => 'URL',
    'date' => 'Data',
    'datetime' => 'Data e Ora',
    'time' => 'Ora',
    'select' => 'Selezione (Dropdown)',
    'radio' => 'Radio Button',
    'checkbox' => 'Checkbox Multipla',
    'checkbox_single' => 'Checkbox Singola',
    'file' => 'File Upload',
    'image' => 'Immagine',
    'color' => 'Colore',
    'rating' => 'Valutazione (1-5 stelle)',
    'wysiwyg' => 'Editor WYSIWYG',
    'code' => 'Codice',
    'password' => 'Password'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
        $message_type = 'danger';
    } else {
        try {
            $post_action = $_POST['post_action'] ?? '';

            switch ($post_action) {
                case 'create_group':
                    $id = generateUuid();
                    $stmt = $pdo->prepare("
                        INSERT INTO $table_groups (id, associazione_id, nome, descrizione, posizione, ordine)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $id,
                        $_SESSION['associazione_id'],
                        $_POST['nome'],
                        $_POST['descrizione'],
                        $_POST['posizione'],
                        $_POST['ordine'] ?? 0
                    ]);
                    $message = 'Gruppo creato con successo!';
                    break;

                case 'update_group':
                    $stmt = $pdo->prepare("
                        UPDATE $table_groups
                        SET nome = ?, descrizione = ?, posizione = ?, ordine = ?, attivo = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['nome'],
                        $_POST['descrizione'],
                        $_POST['posizione'],
                        $_POST['ordine'] ?? 0,
                        isset($_POST['attivo']) ? 1 : 0,
                        $_POST['group_id']
                    ]);
                    $message = 'Gruppo aggiornato con successo!';
                    break;

                case 'delete_group':
                    $stmt = $pdo->prepare("DELETE FROM $table_groups WHERE id = ?");
                    $stmt->execute([$_POST['group_id']]);
                    $message = 'Gruppo eliminato con successo!';
                    $message_type = 'warning';
                    break;

                case 'create_field':
                    $id = generateUuid();
                    $opzioni = null;
                    if (in_array($_POST['tipo_campo'], ['select', 'radio', 'checkbox'])) {
                        // Convert newline-separated options to JSON array
                        $opts = array_filter(array_map('trim', explode("\n", $_POST['opzioni'] ?? '')));
                        $opzioni = json_encode($opts);
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO $table_fields
                        (id, group_id, nome_campo, label, tipo_campo, descrizione, placeholder,
                         valore_default, opzioni, larghezza, ordine, obbligatorio)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $id,
                        $_POST['group_id'],
                        $_POST['nome_campo'],
                        $_POST['label'],
                        $_POST['tipo_campo'],
                        $_POST['descrizione'],
                        $_POST['placeholder'],
                        $_POST['valore_default'],
                        $opzioni,
                        $_POST['larghezza'],
                        $_POST['ordine'] ?? 0,
                        isset($_POST['obbligatorio']) ? 1 : 0
                    ]);
                    $message = 'Campo creato con successo!';
                    break;

                case 'update_field':
                    $opzioni = null;
                    if (in_array($_POST['tipo_campo'], ['select', 'radio', 'checkbox'])) {
                        $opts = array_filter(array_map('trim', explode("\n", $_POST['opzioni'] ?? '')));
                        $opzioni = json_encode($opts);
                    }

                    $stmt = $pdo->prepare("
                        UPDATE $table_fields
                        SET nome_campo = ?, label = ?, tipo_campo = ?, descrizione = ?,
                            placeholder = ?, valore_default = ?, opzioni = ?, larghezza = ?,
                            ordine = ?, obbligatorio = ?, attivo = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['nome_campo'],
                        $_POST['label'],
                        $_POST['tipo_campo'],
                        $_POST['descrizione'],
                        $_POST['placeholder'],
                        $_POST['valore_default'],
                        $opzioni,
                        $_POST['larghezza'],
                        $_POST['ordine'] ?? 0,
                        isset($_POST['obbligatorio']) ? 1 : 0,
                        isset($_POST['attivo']) ? 1 : 0,
                        $_POST['field_id']
                    ]);
                    $message = 'Campo aggiornato con successo!';
                    break;

                case 'delete_field':
                    $stmt = $pdo->prepare("DELETE FROM $table_fields WHERE id = ?");
                    $stmt->execute([$_POST['field_id']]);
                    $message = 'Campo eliminato con successo!';
                    $message_type = 'warning';
                    break;

                case 'reorder_fields':
                    // Update field order based on drag & drop
                    $order_data = json_decode($_POST['order_data'], true);
                    if ($order_data) {
                        $stmt = $pdo->prepare("UPDATE $table_fields SET ordine = ? WHERE id = ?");
                        foreach ($order_data as $index => $field_id) {
                            $stmt->execute([$index, $field_id]);
                        }
                        $message = 'Ordine aggiornato con successo!';
                    }
                    break;
            }
        } catch (PDOException $e) {
            $message = 'Errore: ' . $e->getMessage();
            $message_type = 'danger';
            error_log("ACF Manager Error: " . $e->getMessage());
        }
    }
}

// Get all groups
$groups = $pdo->query("
    SELECT g.*,
           COUNT(DISTINCT f.id) as field_count,
           COUNT(DISTINCT v.socio_id) as usage_count
    FROM $table_groups g
    LEFT JOIN $table_fields f ON g.id = f.group_id AND f.attivo = 1
    LEFT JOIN $table_values v ON f.id = v.field_id
    WHERE g.associazione_id IS NULL OR g.associazione_id = '{$_SESSION['associazione_id']}'
    GROUP BY g.id
    ORDER BY g.ordine ASC
")->fetchAll();

// For edit views, get specific data
$current_group = null;
$current_field = null;

if ($action === 'edit_group' && $group_id) {
    $stmt = $pdo->prepare("SELECT * FROM $table_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $current_group = $stmt->fetch();
}

if ($action === 'edit_field' && $field_id) {
    $stmt = $pdo->prepare("SELECT * FROM $table_fields WHERE id = ?");
    $stmt->execute([$field_id]);
    $current_field = $stmt->fetch();
}

// Get fields for a group (for manage_fields view)
$group_fields = [];
if ($action === 'manage_fields' && $group_id) {
    $stmt = $pdo->prepare("
        SELECT f.*,
               COUNT(v.id) as value_count
        FROM $table_fields f
        LEFT JOIN $table_values v ON f.id = v.field_id
        WHERE f.group_id = ?
        GROUP BY f.id
        ORDER BY f.ordine ASC
    ");
    $stmt->execute([$group_id]);
    $group_fields = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM $table_groups WHERE id = ?");
    $stmt->execute([$group_id]);
    $current_group = $stmt->fetch();
}

?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-sliders text-primary"></i>
                        <?php
                        switch ($action) {
                            case 'create_group': echo 'Nuovo Gruppo di Campi'; break;
                            case 'edit_group': echo 'Modifica Gruppo'; break;
                            case 'create_field': echo 'Nuovo Campo'; break;
                            case 'edit_field': echo 'Modifica Campo'; break;
                            case 'manage_fields': echo 'Gestione Campi - ' . htmlspecialchars($current_group['nome'] ?? ''); break;
                            default: echo 'Gestione Campi Personalizzati';
                        }
                        ?>
                    </h1>
                    <p class="text-muted mb-0">Crea e organizza campi personalizzati per i soci</p>
                </div>
                <div>
                    <?php if ($action === 'list'): ?>
                        <a href="?page=acf-manager&action=create_group" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Nuovo Gruppo
                        </a>
                    <?php else: ?>
                        <a href="?page=acf-manager" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla Lista
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Groups List -->
        <div class="row">
            <div class="col-md-12">
                <?php if (empty($groups)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h4 class="mt-3">Nessun gruppo di campi</h4>
                            <p class="text-muted">Inizia creando il tuo primo gruppo di campi personalizzati.</p>
                            <a href="?page=acf-manager&action=create_group" class="btn btn-primary mt-3">
                                <i class="bi bi-plus-circle"></i> Crea Primo Gruppo
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                        <?php foreach ($groups as $group): ?>
                            <div class="col">
                                <div class="card h-100 <?php echo $group['attivo'] ? '' : 'border-secondary opacity-75'; ?>">
                                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <?php echo htmlspecialchars($group['nome']); ?>
                                            <?php if (!$group['attivo']): ?>
                                                <span class="badge bg-secondary">Disattivo</span>
                                            <?php endif; ?>
                                        </h5>
                                        <span class="badge bg-primary rounded-pill"><?php echo $group['field_count']; ?> campi</span>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted small mb-3">
                                            <?php echo htmlspecialchars($group['descrizione'] ?: 'Nessuna descrizione'); ?>
                                        </p>

                                        <div class="row g-2 mb-3">
                                            <div class="col-6">
                                                <div class="small">
                                                    <i class="bi bi-pin-angle text-muted"></i>
                                                    <strong>Posizione:</strong><br>
                                                    <?php
                                                    $pos_labels = ['main' => 'Principale', 'sidebar' => 'Sidebar', 'tab' => 'Tab'];
                                                    echo $pos_labels[$group['posizione']] ?? $group['posizione'];
                                                    ?>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="small">
                                                    <i class="bi bi-arrow-down-up text-muted"></i>
                                                    <strong>Ordine:</strong><br>
                                                    <?php echo $group['ordine']; ?>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="small">
                                                    <i class="bi bi-people text-muted"></i>
                                                    <strong>Utilizzo:</strong><br>
                                                    <?php echo $group['usage_count']; ?> soci
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white">
                                        <div class="btn-group w-100" role="group">
                                            <a href="?page=acf-manager&action=manage_fields&group_id=<?php echo $group['id']; ?>"
                                               class="btn btn-sm btn-primary">
                                                <i class="bi bi-list-ul"></i> Campi
                                            </a>
                                            <a href="?page=acf-manager&action=edit_group&group_id=<?php echo $group['id']; ?>"
                                               class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo gruppo e tutti i suoi campi?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="post_action" value="delete_group">
                                                <input type="hidden" name="group_id" value="<?php echo $group['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif (in_array($action, ['create_group', 'edit_group'])): ?>
        <!-- Group Form -->
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="post_action" value="<?php echo $action === 'create_group' ? 'create_group' : 'update_group'; ?>">
                            <?php if ($current_group): ?>
                                <input type="hidden" name="group_id" value="<?php echo $current_group['id']; ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="nome" class="form-label">Nome Gruppo <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="nome" name="nome"
                                       value="<?php echo htmlspecialchars($current_group['nome'] ?? ''); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="descrizione" class="form-label">Descrizione</label>
                                <textarea class="form-control" id="descrizione" name="descrizione" rows="3"><?php echo htmlspecialchars($current_group['descrizione'] ?? ''); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="posizione" class="form-label">Posizione</label>
                                    <select class="form-select" id="posizione" name="posizione">
                                        <option value="main" <?php echo ($current_group['posizione'] ?? 'main') === 'main' ? 'selected' : ''; ?>>Principale</option>
                                        <option value="sidebar" <?php echo ($current_group['posizione'] ?? '') === 'sidebar' ? 'selected' : ''; ?>>Sidebar</option>
                                        <option value="tab" <?php echo ($current_group['posizione'] ?? '') === 'tab' ? 'selected' : ''; ?>>Tab</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="ordine" class="form-label">Ordine</label>
                                    <input type="number" class="form-control" id="ordine" name="ordine"
                                           value="<?php echo $current_group['ordine'] ?? 0; ?>">
                                </div>
                            </div>

                            <?php if ($current_group): ?>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="attivo" name="attivo"
                                               <?php echo ($current_group['attivo'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="attivo">
                                            Gruppo attivo
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Salva Gruppo
                                </button>
                                <a href="?page=acf-manager" class="btn btn-outline-secondary">Annulla</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($action === 'manage_fields' && $current_group): ?>
        <!-- Manage Fields for Group -->
        <div class="row">
            <div class="col-md-12">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0"><?php echo htmlspecialchars($current_group['nome']); ?></h5>
                            <small><?php echo htmlspecialchars($current_group['descrizione']); ?></small>
                        </div>
                        <a href="?page=acf-manager&action=create_field&group_id=<?php echo $group_id; ?>" class="btn btn-light btn-sm">
                            <i class="bi bi-plus-circle"></i> Nuovo Campo
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($group_fields)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-inbox display-4 text-muted"></i>
                                <h5 class="mt-3">Nessun campo in questo gruppo</h5>
                                <a href="?page=acf-manager&action=create_field&group_id=<?php echo $group_id; ?>" class="btn btn-primary mt-3">
                                    <i class="bi bi-plus-circle"></i> Aggiungi Primo Campo
                                </a>
                            </div>
                        <?php else: ?>
                            <div id="sortable-fields" class="list-group">
                                <?php foreach ($group_fields as $field): ?>
                                    <div class="list-group-item list-group-item-action" data-field-id="<?php echo $field['id']; ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center mb-2">
                                                    <i class="bi bi-grip-vertical text-muted me-2" style="cursor: move;"></i>
                                                    <h6 class="mb-0">
                                                        <?php echo htmlspecialchars($field['label']); ?>
                                                        <?php if ($field['obbligatorio']): ?>
                                                            <span class="badge bg-danger">Obbligatorio</span>
                                                        <?php endif; ?>
                                                        <?php if (!$field['attivo']): ?>
                                                            <span class="badge bg-secondary">Disattivo</span>
                                                        <?php endif; ?>
                                                    </h6>
                                                </div>
                                                <div class="small text-muted">
                                                    <span class="me-3"><strong>Nome:</strong> <code><?php echo $field['nome_campo']; ?></code></span>
                                                    <span class="me-3"><strong>Tipo:</strong> <?php echo $field_types[$field['tipo_campo']] ?? $field['tipo_campo']; ?></span>
                                                    <span class="me-3"><strong>Larghezza:</strong> <?php echo $field['larghezza']; ?></span>
                                                    <span class="me-3"><strong>Ordine:</strong> <?php echo $field['ordine']; ?></span>
                                                    <span><strong>Valori salvati:</strong> <?php echo $field['value_count']; ?></span>
                                                </div>
                                                <?php if ($field['descrizione']): ?>
                                                    <div class="small text-muted mt-1">
                                                        <?php echo htmlspecialchars($field['descrizione']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="btn-group ms-3">
                                                <a href="?page=acf-manager&action=edit_field&field_id=<?php echo $field['id']; ?>&group_id=<?php echo $group_id; ?>"
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo campo?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                    <input type="hidden" name="post_action" value="delete_field">
                                                    <input type="hidden" name="field_id" value="<?php echo $field['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="alert alert-info mt-3">
                                <i class="bi bi-info-circle"></i> <strong>Suggerimento:</strong> Trascina i campi per riordinarli. L'ordine verrà salvato automaticamente.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- SortableJS for drag & drop -->
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.getElementById('sortable-fields');
            if (el) {
                Sortable.create(el, {
                    animation: 150,
                    handle: '.bi-grip-vertical',
                    onEnd: function(evt) {
                        // Get new order
                        var items = el.querySelectorAll('[data-field-id]');
                        var orderData = [];
                        items.forEach(function(item) {
                            orderData.push(item.getAttribute('data-field-id'));
                        });

                        // Send AJAX request to save order
                        var formData = new FormData();
                        formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');
                        formData.append('post_action', 'reorder_fields');
                        formData.append('order_data', JSON.stringify(orderData));

                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        }).then(function(response) {
                            if (response.ok) {
                                // Show success message
                                var alert = document.createElement('div');
                                alert.className = 'alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3';
                                alert.style.zIndex = '9999';
                                alert.innerHTML = '<i class="bi bi-check-circle"></i> Ordine aggiornato! <button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
                                document.body.appendChild(alert);
                                setTimeout(function() { alert.remove(); }, 3000);
                            }
                        });
                    }
                });
            }
        });
        </script>

    <?php elseif (in_array($action, ['create_field', 'edit_field'])): ?>
        <!-- Field Form -->
        <div class="row">
            <div class="col-md-10 mx-auto">
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="post_action" value="<?php echo $action === 'create_field' ? 'create_field' : 'update_field'; ?>">
                            <input type="hidden" name="group_id" value="<?php echo $group_id ?? $current_field['group_id'] ?? ''; ?>">
                            <?php if ($current_field): ?>
                                <input type="hidden" name="field_id" value="<?php echo $current_field['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="nome_campo" class="form-label">Nome Campo (slug) <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="nome_campo" name="nome_campo"
                                           value="<?php echo htmlspecialchars($current_field['nome_campo'] ?? ''); ?>"
                                           pattern="[a-z0-9_]+" title="Solo lettere minuscole, numeri e underscore" required>
                                    <small class="text-muted">Es: email_secondaria, numero_tessera_sanitaria</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="label" class="form-label">Etichetta <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="label" name="label"
                                           value="<?php echo htmlspecialchars($current_field['label'] ?? ''); ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="tipo_campo" class="form-label">Tipo Campo <span class="text-danger">*</span></label>
                                    <select class="form-select" id="tipo_campo" name="tipo_campo" required>
                                        <?php foreach ($field_types as $type => $label): ?>
                                            <option value="<?php echo $type; ?>" <?php echo ($current_field['tipo_campo'] ?? '') === $type ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="larghezza" class="form-label">Larghezza</label>
                                    <select class="form-select" id="larghezza" name="larghezza">
                                        <option value="full" <?php echo ($current_field['larghezza'] ?? 'full') === 'full' ? 'selected' : ''; ?>>Intera (100%)</option>
                                        <option value="half" <?php echo ($current_field['larghezza'] ?? '') === 'half' ? 'selected' : ''; ?>>Metà (50%)</option>
                                        <option value="third" <?php echo ($current_field['larghezza'] ?? '') === 'third' ? 'selected' : ''; ?>>Un terzo (33%)</option>
                                        <option value="quarter" <?php echo ($current_field['larghezza'] ?? '') === 'quarter' ? 'selected' : ''; ?>>Un quarto (25%)</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="descrizione" class="form-label">Descrizione</label>
                                <textarea class="form-control" id="descrizione" name="descrizione" rows="2"><?php echo htmlspecialchars($current_field['descrizione'] ?? ''); ?></textarea>
                                <small class="text-muted">Testo di aiuto mostrato sotto il campo</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="placeholder" class="form-label">Placeholder</label>
                                    <input type="text" class="form-control" id="placeholder" name="placeholder"
                                           value="<?php echo htmlspecialchars($current_field['placeholder'] ?? ''); ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="valore_default" class="form-label">Valore Predefinito</label>
                                    <input type="text" class="form-control" id="valore_default" name="valore_default"
                                           value="<?php echo htmlspecialchars($current_field['valore_default'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="mb-3" id="options-container" style="display: none;">
                                <label for="opzioni" class="form-label">Opzioni (per select/radio/checkbox)</label>
                                <textarea class="form-control" id="opzioni" name="opzioni" rows="5"><?php
                                    if (isset($current_field['opzioni'])) {
                                        $opts = json_decode($current_field['opzioni'], true) ?: [];
                                        echo htmlspecialchars(implode("\n", $opts));
                                    }
                                ?></textarea>
                                <small class="text-muted">Una opzione per riga</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="ordine" class="form-label">Ordine</label>
                                    <input type="number" class="form-control" id="ordine" name="ordine"
                                           value="<?php echo $current_field['ordine'] ?? 0; ?>">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <div class="form-check mt-4">
                                        <input class="form-check-input" type="checkbox" id="obbligatorio" name="obbligatorio"
                                               <?php echo ($current_field['obbligatorio'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="obbligatorio">
                                            Campo obbligatorio
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <?php if ($current_field): ?>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="attivo" name="attivo"
                                               <?php echo ($current_field['attivo'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="attivo">
                                            Campo attivo
                                        </label>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Salva Campo
                                </button>
                                <a href="?page=acf-manager&action=manage_fields&group_id=<?php echo $group_id ?? $current_field['group_id'] ?? ''; ?>" class="btn btn-outline-secondary">Annulla</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Show/hide options field based on field type
        document.addEventListener('DOMContentLoaded', function() {
            var tipoCampo = document.getElementById('tipo_campo');
            var optionsContainer = document.getElementById('options-container');

            function toggleOptions() {
                var needsOptions = ['select', 'radio', 'checkbox'].includes(tipoCampo.value);
                optionsContainer.style.display = needsOptions ? 'block' : 'none';
            }

            tipoCampo.addEventListener('change', toggleOptions);
            toggleOptions(); // Initial check
        });
        </script>
    <?php endif; ?>
</div>
