<?php
/**
 * Associazione Soci Manager - Uninstaller
 * Complete removal tool for the application
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security check - require confirmation
$confirmation_code = 'REMOVE_ALL_DATA_' . date('Ymd');

class AssociazioneUninstaller {
    private $pdo;
    private $config_loaded = false;
    
    public function __construct() {
        // Try to load existing config
        if (file_exists('config.php')) {
            try {
                include 'config.php';
                $this->pdo = $pdo ?? null;
                $this->config_loaded = true;
            } catch (Exception $e) {
                // Config exists but has errors
            }
        }
    }
    
    public function run() {
        if ($_POST && isset($_POST['action'])) {
            $this->process_action($_POST['action']);
        } else {
            $this->show_uninstall_form();
        }
    }
    
    private function show_uninstall_form() {
        global $confirmation_code;
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Disinstallazione - Associazione Soci Manager</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
            <style>
                .uninstall-container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .danger-zone { border: 2px solid #dc3545; background-color: #f8d7da; }
            </style>
        </head>
        <body class="bg-light">
            <div class="uninstall-container">
                <div class="card danger-zone">
                    <div class="card-header bg-danger text-white">
                        <h3 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            Disinstallazione Associazione Soci Manager
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger">
                            <h5><i class="bi bi-exclamation-triangle-fill"></i> ATTENZIONE!</h5>
                            <p>Questa operazione è <strong>IRREVERSIBILE</strong> e comporterà:</p>
                            <ul>
                                <li>🗑️ <strong>Eliminazione completa del database</strong></li>
                                <li>📁 <strong>Rimozione di tutti i file caricati</strong></li>
                                <li>💾 <strong>Cancellazione dei backup</strong></li>
                                <li>⚙️ <strong>Rimozione delle configurazioni</strong></li>
                                <li>👥 <strong>Perdita di tutti i dati dei soci</strong></li>
                            </ul>
                        </div>
                        
                        <?php if ($this->config_loaded): ?>
                            <div class="alert alert-warning">
                                <h6><i class="bi bi-database"></i> Database Rilevato</h6>
                                <p>Trovato database configurato. La disinstallazione rimuoverà:</p>
                                <ul class="list-unstyled">
                                    <li>• Database: <code><?php echo DB_NAME ?? 'N/A'; ?></code></li>
                                    <li>• Host: <code><?php echo DB_HOST ?? 'N/A'; ?></code></li>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <h5>Opzioni di Disinstallazione</h5>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="backup_before" name="backup_before" checked>
                                    <label class="form-check-label" for="backup_before">
                                        <strong>Crea backup completo prima della rimozione</strong>
                                        <div class="form-text">Raccomandato: crea un backup di sicurezza prima di procedere</div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remove_database" name="remove_database" checked>
                                    <label class="form-check-label" for="remove_database">
                                        <strong>Elimina database</strong>
                                        <div class="form-text">Rimuove completamente il database MySQL</div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remove_files" name="remove_files" checked>
                                    <label class="form-check-label" for="remove_files">
                                        <strong>Elimina tutti i file</strong>
                                        <div class="form-text">Rimuove uploads, backups, configurazioni</div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remove_app" name="remove_app">
                                    <label class="form-check-label" for="remove_app">
                                        <strong>Elimina files dell'applicazione</strong>
                                        <div class="form-text text-danger">ATTENZIONE: Elimina tutti i file PHP dell'app</div>
                                    </label>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-3">
                                <label for="confirmation_code" class="form-label">
                                    <strong>Codice di Conferma</strong>
                                </label>
                                <input type="text" class="form-control" id="confirmation_code" name="confirmation_code" required>
                                <div class="form-text">
                                    Digita: <code><?php echo $confirmation_code; ?></code>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="final_confirm" name="final_confirm" required>
                                    <label class="form-check-label" for="final_confirm">
                                        <strong>Confermo di voler procedere con la disinstallazione completa</strong>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="action" value="uninstall" class="btn btn-danger btn-lg">
                                    <i class="bi bi-trash"></i> Procedi con Disinstallazione
                                </button>
                                <a href="index.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> Annulla e Torna All'App
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <small class="text-muted">
                        Associazione Soci Manager - Strumento di Disinstallazione
                    </small>
                </div>
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    }
    
    private function process_action($action) {
        global $confirmation_code;
        
        if ($action !== 'uninstall') {
            $this->show_error('Azione non valida');
            return;
        }
        
        // Verify confirmation code
        if ($_POST['confirmation_code'] !== $confirmation_code) {
            $this->show_error('Codice di conferma non corretto');
            return;
        }
        
        // Verify final confirmation
        if (!isset($_POST['final_confirm'])) {
            $this->show_error('Devi confermare la procedura di disinstallazione');
            return;
        }
        
        $this->execute_uninstall();
    }
    
    private function execute_uninstall() {
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Disinstallazione in corso...</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container" style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h3 class="mb-0">
                            <i class="bi bi-trash"></i>
                            Disinstallazione in Corso
                        </h3>
                    </div>
                    <div class="card-body">
                        <div id="uninstall-progress">
        <?php
        
        flush();
        
        try {
            // Step 1: Create backup if requested
            if (isset($_POST['backup_before']) && $this->config_loaded) {
                $this->log_step('Creazione backup di sicurezza...', 'info');
                $this->create_final_backup();
            }
            
            // Step 2: Remove database if requested
            if (isset($_POST['remove_database']) && $this->config_loaded) {
                $this->log_step('Rimozione database...', 'warning');
                $this->remove_database();
            }
            
            // Step 3: Remove files if requested
            if (isset($_POST['remove_files'])) {
                $this->log_step('Rimozione files dati...', 'warning');
                $this->remove_data_files();
            }
            
            // Step 4: Remove app files if requested
            if (isset($_POST['remove_app'])) {
                $this->log_step('Rimozione files applicazione...', 'danger');
                $this->remove_app_files();
            } else {
                // Just remove config if not removing all files
                $this->log_step('Rimozione configurazione...', 'warning');
                $this->remove_config();
            }
            
            $this->log_step('✅ Disinstallazione completata con successo!', 'success');
            
        } catch (Exception $e) {
            $this->log_step('❌ Errore durante disinstallazione: ' . $e->getMessage(), 'danger');
        }
        
        ?>
                        </div>
                        
                        <div class="mt-4 text-center">
                            <?php if (isset($_POST['remove_app'])): ?>
                                <div class="alert alert-success">
                                    <h5>🎉 Disinstallazione Completata</h5>
                                    <p>Associazione Soci Manager è stato rimosso completamente dal server.</p>
                                    <p class="mb-0"><strong>Questa pagina si eliminerà automaticamente in 10 secondi.</strong></p>
                                </div>
                                
                                <script>
                                setTimeout(function() {
                                    document.body.innerHTML = '<div class="container mt-5 text-center"><h2>Disinstallazione Completata</h2><p>Tutti i file sono stati rimossi.</p></div>';
                                }, 10000);
                                </script>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <h5>Disinstallazione Parziale Completata</h5>
                                    <p>I dati sono stati rimossi ma i file dell'applicazione sono ancora presenti.</p>
                                    <p>Per reinstallare, esegui: <code>install.php</code></p>
                                </div>
                                
                                <a href="install.php" class="btn btn-primary">
                                    <i class="bi bi-arrow-clockwise"></i> Reinstalla Applicazione
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function log_step($message, $type = 'info') {
        $icon_map = [
            'info' => 'bi-info-circle',
            'warning' => 'bi-exclamation-triangle',
            'danger' => 'bi-exclamation-triangle-fill',
            'success' => 'bi-check-circle-fill'
        ];
        
        $icon = $icon_map[$type] ?? 'bi-info-circle';
        
        echo "<div class='alert alert-$type'>";
        echo "<i class='bi $icon me-2'></i>$message";
        echo "</div>";
        echo str_pad('', 4096) . "\n";
        flush();
        sleep(1);
    }
    
    private function create_final_backup() {
        if (!$this->pdo) {
            throw new Exception('Database non disponibile per backup');
        }
        
        $timestamp = date('Y-m-d_H-i-s');
        $backup_dir = 'backups/uninstall/';
        
        if (!file_exists($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        // Database backup
        $filename = "final_backup_database_{$timestamp}.sql";
        $filepath = $backup_dir . $filename;
        
        $stmt = $this->pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $output = "-- Final Database Backup before uninstall\n";
        $output .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->query("SHOW CREATE TABLE `$table`");
            $tableInfo = $stmt->fetch();
            
            $output .= "-- Table structure for `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $tableInfo['Create Table'] . ";\n\n";
            
            $stmt = $this->pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($rows)) {
                $output .= "-- Data for table `$table`\n";
                $columns = array_keys($rows[0]);
                $columnsList = '`' . implode('`, `', $columns) . '`';
                
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    $output .= "INSERT INTO `$table` ($columnsList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $output .= "\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        if (!file_put_contents($filepath, $output)) {
            throw new Exception("Impossibile creare backup database");
        }
        
        // Files backup
        if (file_exists('uploads') && is_dir('uploads')) {
            $zip_filename = $backup_dir . "final_backup_files_{$timestamp}.zip";
            $zip = new ZipArchive();
            
            if ($zip->open($zip_filename, ZipArchive::CREATE) === TRUE) {
                $this->add_directory_to_zip($zip, 'uploads/', 'uploads/');
                $zip->close();
            }
        }
    }
    
    private function add_directory_to_zip($zip, $dir, $zipDir = '') {
        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file != '.' && $file != '..') {
                    $filePath = $dir . $file;
                    $zipPath = $zipDir . $file;
                    
                    if (is_dir($filePath)) {
                        $zip->addEmptyDir($zipPath);
                        $this->add_directory_to_zip($zip, $filePath . '/', $zipPath . '/');
                    } else {
                        $zip->addFile($filePath, $zipPath);
                    }
                }
            }
        }
    }
    
    private function remove_database() {
        if (!$this->pdo) {
            throw new Exception('Database non disponibile');
        }
        
        $db_name = DB_NAME;
        
        // Get all tables first
        $stmt = $this->pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Drop all tables to handle foreign key constraints
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        foreach ($tables as $table) {
            $this->pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Drop the entire database
        $this->pdo->exec("DROP DATABASE IF EXISTS `$db_name`");
    }
    
    private function remove_data_files() {
        $directories = ['uploads', 'backups'];
        
        foreach ($directories as $dir) {
            if (file_exists($dir)) {
                $this->remove_directory_recursive($dir);
            }
        }
    }
    
    private function remove_config() {
        $config_files = ['config.php'];
        
        foreach ($config_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
    
    private function remove_app_files() {
        // Remove config first
        $this->remove_config();
        
        // List of files and directories to remove
        $items_to_remove = [
            // Files
            'index.php',
            'install.php',
            'database_schema.sql',
            '.htaccess',
            
            // Directories
            'api',
            'auth', 
            'assets',
            'includes',
            'pages',
            'uploads',
            'backups'
        ];
        
        foreach ($items_to_remove as $item) {
            if (file_exists($item)) {
                if (is_dir($item)) {
                    $this->remove_directory_recursive($item);
                } else {
                    unlink($item);
                }
            }
        }
        
        // Remove this uninstall script last
        if (file_exists('uninstall.php')) {
            // Create a self-deleting script
            $self_delete = '<?php unlink(__FILE__); ?>';
            file_put_contents('cleanup.php', $self_delete);
            header('Location: cleanup.php');
            exit;
        }
    }
    
    private function remove_directory_recursive($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $this->remove_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
    
    private function show_error($message) {
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Errore Disinstallazione</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container" style="max-width: 500px; margin: 100px auto;">
                <div class="alert alert-danger">
                    <h4>Errore</h4>
                    <p><?php echo htmlspecialchars($message); ?></p>
                    <a href="uninstall.php" class="btn btn-outline-danger">Torna Indietro</a>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
}

// Run uninstaller
$uninstaller = new AssociazioneUninstaller();
$uninstaller->run();
?>