<?php
/**
 * Plugin Manager Page - Gestione Plugin
 *
 * Interfaccia per installare, attivare, disattivare e gestire i plugin
 */

// Verifica autenticazione (già fatto in config.php tramite index.php)
if (!isUserLoggedIn()) {
    redirect('auth/login.php');
}

// Solo admin o super_admin possono gestire i plugin
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'super_admin'])) {
    redirect('index.php?page=dashboard');
}

$message = '';
$message_type = 'success';

// Gestione azioni plugin
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Token Validation
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Errore di sicurezza. Riprova.';
        $message_type = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $plugin_slug = $_POST['plugin_slug'] ?? '';

        if (empty($plugin_slug)) {
            $message = 'Plugin non specificato.';
            $message_type = 'danger';
        } else {
            try {
                switch ($action) {
                    case 'activate':
                        if (PluginManager::activate($plugin_slug)) {
                            $message = 'Plugin attivato con successo!';
                            $message_type = 'success';

                            // Log security event
                            logSecurityEvent($pdo, 'plugin_activated', "Plugin '$plugin_slug' activated", [
                                'plugin' => $plugin_slug,
                                'user_id' => $_SESSION['user_id']
                            ], 'info');
                        } else {
                            $message = 'Errore nell\'attivazione del plugin.';
                            $message_type = 'danger';
                        }
                        break;

                    case 'deactivate':
                        if (PluginManager::deactivate($plugin_slug)) {
                            $message = 'Plugin disattivato con successo!';
                            $message_type = 'warning';

                            // Log security event
                            logSecurityEvent($pdo, 'plugin_deactivated', "Plugin '$plugin_slug' deactivated", [
                                'plugin' => $plugin_slug,
                                'user_id' => $_SESSION['user_id']
                            ], 'info');
                        } else {
                            $message = 'Errore nella disattivazione del plugin.';
                            $message_type = 'danger';
                        }
                        break;

                    default:
                        $message = 'Azione non valida.';
                        $message_type = 'danger';
                }
            } catch (Exception $e) {
                $message = 'Errore: ' . htmlspecialchars($e->getMessage());
                $message_type = 'danger';
                error_log("Plugin Manager Error: " . $e->getMessage());
            }
        }
    }
}

// Recupera tutti i plugin
$all_plugins = PluginManager::getAll();
$plugin_stats = PluginManager::getStats();
$hook_stats = HookManager::getStats();

?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-puzzle-fill text-primary"></i> Gestione Plugin
                    </h1>
                    <p class="text-muted mb-0">Estendi le funzionalità dell'applicazione con i plugin</p>
                </div>
                <div>
                    <a href="<?php echo PluginManager::getPluginsDir(); ?>" class="btn btn-outline-secondary btn-sm" title="Apri directory plugin">
                        <i class="bi bi-folder2-open"></i> Directory Plugin
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Plugin Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Plugin Totali</p>
                            <h3 class="mb-0"><?php echo $plugin_stats['total']; ?></h3>
                        </div>
                        <div class="text-primary">
                            <i class="bi bi-puzzle fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Plugin Attivi</p>
                            <h3 class="mb-0 text-success"><?php echo $plugin_stats['active']; ?></h3>
                        </div>
                        <div class="text-success">
                            <i class="bi bi-check-circle-fill fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Plugin Inattivi</p>
                            <h3 class="mb-0 text-warning"><?php echo $plugin_stats['inactive']; ?></h3>
                        </div>
                        <div class="text-warning">
                            <i class="bi bi-dash-circle-fill fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted mb-1 small">Hooks Registrati</p>
                            <h3 class="mb-0 text-info"><?php echo $hook_stats['total_hooks']; ?></h3>
                        </div>
                        <div class="text-info">
                            <i class="bi bi-link-45deg fs-1"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Plugins List -->
    <div class="row">
        <div class="col-md-12">
            <?php if (empty($all_plugins)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h4 class="mt-3">Nessun plugin disponibile</h4>
                        <p class="text-muted">Aggiungi plugin nella directory: <code><?php echo PluginManager::getPluginsDir(); ?></code></p>
                        <a href="https://github.com/your-repo/plugins" class="btn btn-primary mt-3" target="_blank">
                            <i class="bi bi-download"></i> Scarica Plugin
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                    <?php foreach ($all_plugins as $slug => $plugin):
                        $info = $plugin['info'];
                        $is_active = $plugin['active'];
                    ?>
                        <div class="col">
                            <div class="card h-100 <?php echo $is_active ? 'border-success' : ''; ?>">
                                <div class="card-header d-flex justify-content-between align-items-center <?php echo $is_active ? 'bg-success bg-opacity-10' : 'bg-light'; ?>">
                                    <h5 class="mb-0">
                                        <?php echo htmlspecialchars($info['name']); ?>
                                        <?php if ($is_active): ?>
                                            <span class="badge bg-success ms-2">Attivo</span>
                                        <?php endif; ?>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        <?php echo htmlspecialchars($info['description'] ?: 'Nessuna descrizione disponibile.'); ?>
                                    </p>

                                    <div class="small mb-3">
                                        <div class="row mb-1">
                                            <div class="col-4 text-muted">Versione:</div>
                                            <div class="col-8"><strong><?php echo htmlspecialchars($info['version']); ?></strong></div>
                                        </div>
                                        <div class="row mb-1">
                                            <div class="col-4 text-muted">Autore:</div>
                                            <div class="col-8">
                                                <?php if (!empty($info['author_uri'])): ?>
                                                    <a href="<?php echo htmlspecialchars($info['author_uri']); ?>" target="_blank">
                                                        <?php echo htmlspecialchars($info['author']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($info['author']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($info['license'])): ?>
                                            <div class="row mb-1">
                                                <div class="col-4 text-muted">Licenza:</div>
                                                <div class="col-8"><?php echo htmlspecialchars($info['license']); ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($info['requires_php'])): ?>
                                            <div class="row">
                                                <div class="col-4 text-muted">PHP:</div>
                                                <div class="col-8"><?php echo htmlspecialchars($info['requires_php']); ?>+</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($info['uri'])): ?>
                                        <a href="<?php echo htmlspecialchars($info['uri']); ?>" class="btn btn-sm btn-outline-secondary w-100 mb-2" target="_blank">
                                            <i class="bi bi-info-circle"></i> Maggiori informazioni
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-white">
                                    <form method="POST" class="d-inline w-100">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="plugin_slug" value="<?php echo htmlspecialchars($slug); ?>">

                                        <?php if ($is_active): ?>
                                            <button type="submit" name="action" value="deactivate"
                                                    class="btn btn-warning w-100"
                                                    onclick="return confirm('Sei sicuro di voler disattivare questo plugin?');">
                                                <i class="bi bi-pause-circle"></i> Disattiva
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="activate"
                                                    class="btn btn-success w-100">
                                                <i class="bi bi-play-circle"></i> Attiva
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hooks Information -->
    <div class="row mt-5">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header bg-info bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="bi bi-link-45deg"></i> Sistema Hooks
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted">Statistiche Hooks</h6>
                            <ul class="list-unstyled">
                                <li><strong>Hooks Totali:</strong> <?php echo $hook_stats['total_hooks']; ?></li>
                                <li><strong>Callbacks Totali:</strong> <?php echo $hook_stats['total_callbacks']; ?></li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Documentazione</h6>
                            <p class="small mb-2">I plugin possono estendere l'applicazione usando il sistema di hooks.</p>
                            <a href="?page=hooks-reference" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-book"></i> Vedi Documentazione Hooks
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($hook_stats['hooks']) && HookManager::isDebugMode()): ?>
                        <hr>
                        <details>
                            <summary class="text-muted" style="cursor: pointer;">
                                <strong>Debug: Hooks Registrati (<?php echo count($hook_stats['hooks']); ?>)</strong>
                            </summary>
                            <div class="mt-3">
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th>Hook Name</th>
                                                <th>Callbacks</th>
                                                <th>Priorities</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($hook_stats['hooks'] as $hook_name => $callback_count): ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars($hook_name); ?></code></td>
                                                    <td><?php echo $callback_count; ?></td>
                                                    <td class="small text-muted">10 (default)</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Plugin Development Guide -->
    <div class="row mt-4 mb-5">
        <div class="col-md-12">
            <div class="card border-secondary">
                <div class="card-header bg-secondary bg-opacity-10">
                    <h5 class="mb-0">
                        <i class="bi bi-code-slash"></i> Sviluppa il tuo Plugin
                    </h5>
                </div>
                <div class="card-body">
                    <p>Vuoi creare un plugin personalizzato? Segui questa struttura:</p>

                    <ol class="mb-3">
                        <li>Crea una directory in <code><?php echo PluginManager::getPluginsDir(); ?>/nome-plugin/</code></li>
                        <li>Crea il file principale <code>nome-plugin.php</code> con l'header del plugin</li>
                        <li>Usa hooks e filtri per estendere le funzionalità</li>
                        <li>Torna qui per attivare il plugin</li>
                    </ol>

                    <pre class="bg-dark text-light p-3 rounded"><code>&lt;?php
/**
 * Plugin Name: Il Mio Plugin
 * Description: Descrizione del plugin
 * Version: 1.0.0
 * Author: Il Tuo Nome
 * License: GPL2
 */

class Plugin_nome_plugin {
    public function init() {
        // Aggiungi hooks e filtri qui
        HookManager::addAction('user_created', [$this, 'onUserCreated']);
    }

    public function activate() {
        // Eseguito all'attivazione
    }

    public function deactivate() {
        // Eseguito alla disattivazione
    }

    public function onUserCreated($user_id) {
        // La tua logica
    }
}
</code></pre>

                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i>
                        <strong>Documentazione completa:</strong> Consulta <code>HOOKS_REFERENCE.md</code> per l'elenco completo di hooks disponibili.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    transition: all 0.3s ease;
}
.card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}
.border-success {
    border-width: 2px !important;
}
pre {
    font-size: 0.875rem;
    overflow-x: auto;
}
</style>
