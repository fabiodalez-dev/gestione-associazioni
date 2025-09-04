<?php
/**
 * Associazione Soci Manager - SaaS Installer v2.0
 * Multi-tenant installation wizard
 */

// Definisce la costante per evitare reindirizzamenti dal config.php
if (!defined('INSTALLER_ACTIVE')) {
    define('INSTALLER_ACTIVE', true);
}

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Aumenta il limite di memoria per l'installazione
ini_set('memory_limit', '1024M'); // 1GB per sicurezza
set_time_limit(300); // 5 minuti per l'installazione

// Verifica che i limiti siano stati applicati
if (ini_get('memory_limit') === '128M') {
    // Se ini_set non funziona, proviamo tramite htaccess
    echo "<!-- Memory limit not changed, using fallback -->";
}

// Prevent running if already installed
if (file_exists('config.php') && !isset($_GET['force'])) {
    $config_content = file_get_contents('config.php');
    if (strpos($config_content, 'INSTALLER_TEMP') === false) {
        // Verifica se il database è effettivamente installato
        try {
            // Controlla solo la configurazione del database senza connettersi
            if (strpos($config_content, 'DB_NAME') !== false) {
                // Prova una connessione test
                $db_config = [];
                preg_match("/define\('DB_HOST',\s*'([^']+)'\)/", $config_content, $host_match);
                preg_match("/define\('DB_NAME',\s*'([^']+)'\)/", $config_content, $name_match);
                preg_match("/define\('DB_USER',\s*'([^']+)'\)/", $config_content, $user_match);
                preg_match("/define\('DB_PASS',\s*'([^']+)'\)/", $config_content, $pass_match);
                
                if (isset($host_match[1], $name_match[1], $user_match[1], $pass_match[1])) {
                    try {
                        $test_pdo = new PDO("mysql:host={$host_match[1]};dbname={$name_match[1]}", $user_match[1], $pass_match[1]);
                        $stmt = $test_pdo->query("SHOW TABLES LIKE 'associazioni'");
                        if ($stmt && $stmt->rowCount() > 0) {
                            // Database effettivamente installato
                            die('
                            <html>
                            <head><title>Installazione Completata</title></head>
                            <body style="font-family: Arial; text-align: center; margin-top: 100px;">
                                <h2>🎉 Installazione già completata!</h2>
                                <p>L\'applicazione SaaS è già stata installata.</p>
                                <p><a href="index.php">Vai all\'applicazione</a></p>
                                <hr>
                                <small><a href="install.php?force=1">Forza reinstallazione</a> (attenzione: rimuoverà tutti i dati)</small>
                            </body>
                            </html>
                            ');
                        }
                    } catch (PDOException $e) {
                        // Database non accessibile o non configurato, continua con l'installazione
                    }
                }
            }
        } catch (Exception $e) {
            // Errore nella verifica, continua con l'installazione
        }
    }
}

if (!defined('INSTALLER_ACTIVE')) {
    define('INSTALLER_ACTIVE', true);
}

// UUID Generator Function (needed for installer)
if (!function_exists('generateUuid')) {
    function generateUuid() {
    return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
        mt_rand( 0, 0xffff ),
        mt_rand( 0, 0x0fff ) | 0x4000,
        mt_rand( 0, 0x3fff ) | 0x8000,
        mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
    );
    }
}

class SaaSInstaller {
    private $steps = [
        'welcome' => 'Benvenuto',
        'requirements' => 'Verifica Requisiti',
        'database' => 'Configurazione Database', 
        'association' => 'Prima Associazione',
        'admin' => 'Super Amministratore',
        'install' => 'Installazione',
        'complete' => 'Completamento'
    ];
    
    private $current_step;
    private $errors = [];
    
    public function __construct() {
        $this->current_step = $_GET['step'] ?? 'welcome';
        
        if (!array_key_exists($this->current_step, $this->steps)) {
            $this->current_step = 'welcome';
        }
    }
    
    public function run() {
        $method = 'step_' . $this->current_step;
        if (method_exists($this, $method)) {
            $this->$method();
        } else {
            $this->step_welcome();
        }
    }
    
    private function render_header() {
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>SaaS Installer - Associazione Soci Manager</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
            
            <!-- GSAP per animazioni installer -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
            <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
            <style>
                .installer-container { max-width: 900px; margin: 0 auto; padding: 20px; }
                .step-indicator { margin-bottom: 30px; }
                .step-indicator .step { display: inline-block; padding: 8px 16px; margin: 0 5px; border-radius: 20px; font-size: 0.85rem; }
                .step.active { background-color: #0d6efd; color: white; }
                .step.completed { background-color: #198754; color: white; }
                .step.pending { background-color: #e9ecef; color: #6c757d; }
                .requirement-check { display: flex; align-items: center; padding: 12px; margin: 8px 0; border-radius: 8px; }
                .requirement-check.success { background-color: #d1edff; border-left: 4px solid #198754; }
                .requirement-check.error { background-color: #f8d7da; border-left: 4px solid #dc3545; }
                .requirement-check.warning { background-color: #fff3cd; border-left: 4px solid #ffc107; }
                .saas-badge { background: linear-gradient(45deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; }
                
                /* GSAP Animation Support */
                .card { will-change: transform, opacity; }
                .step { will-change: transform, opacity; transition: all 0.3s ease; }
                .step:hover { transform: translateY(-2px); }
                .requirement-check { will-change: transform, opacity; }
                .btn { will-change: transform; transition: transform 0.2s ease; }
                .progress-bar { will-change: width; }
                
                /* Loading animation */
                .installer-loading {
                    position: relative;
                    overflow: hidden;
                }
                .installer-loading::after {
                    content: "";
                    position: absolute;
                    top: 0;
                    left: -100%;
                    width: 100%;
                    height: 100%;
                    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
                    animation: shimmer 2s infinite;
                }
                
                @keyframes shimmer {
                    0% { left: -100%; }
                    100% { left: 100%; }
                }
            </style>
        </head>
        <body class="bg-light">
            <div class="installer-container">
                <div class="card shadow-lg">
                    <div class="card-header bg-gradient text-white" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="mb-0">
                                <i class="bi bi-cloud-check"></i>
                                Associazione Soci Manager
                            </h3>
                            <span class="saas-badge">SaaS Multi-Tenant</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php $this->render_step_indicator(); ?>
        <?php
    }
    
    private function render_footer() {
        ?>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <small class="text-muted">Associazione Soci Manager v2.0 - Piattaforma SaaS Multi-Tenant</small>
                </div>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
            <script>
                // GSAP Animations for Installer
                document.addEventListener('DOMContentLoaded', function() {
                    gsap.registerPlugin(ScrollTrigger);
                    
                    // Animate installer container
                    gsap.from('.card', {
                        y: 50,
                        opacity: 0,
                        duration: 0.8,
                        ease: "power3.out"
                    });
                    
                    // Animate step indicator
                    gsap.from('.step', {
                        scale: 0,
                        opacity: 0,
                        duration: 0.5,
                        stagger: 0.1,
                        ease: "back.out(1.7)",
                        delay: 0.3
                    });
                    
                    // Animate requirement checks
                    gsap.from('.requirement-check', {
                        x: -30,
                        opacity: 0,
                        duration: 0.6,
                        stagger: 0.1,
                        ease: "power2.out",
                        delay: 0.6
                    });
                    
                    // Animate buttons
                    const buttons = document.querySelectorAll('.btn');
                    buttons.forEach(button => {
                        button.addEventListener('mouseenter', () => {
                            gsap.to(button, {
                                scale: 1.05,
                                duration: 0.2,
                                ease: "power2.out"
                            });
                        });
                        
                        button.addEventListener('mouseleave', () => {
                            gsap.to(button, {
                                scale: 1,
                                duration: 0.2,
                                ease: "power2.out"
                            });
                        });
                    });
                    
                    // Progress animation for installation steps
                    if (document.querySelector('.progress-bar')) {
                        gsap.from('.progress-bar', {
                            width: 0,
                            duration: 1.5,
                            ease: "power2.out",
                            delay: 0.8
                        });
                    }
                    
                    console.log('🎬 Installer loaded with GSAP animations');
                });
            </script>
        </body>
        </html>
        <?php
    }
    
    private function render_step_indicator() {
        echo '<div class="step-indicator text-center">';
        $step_keys = array_keys($this->steps);
        $current_index = array_search($this->current_step, $step_keys);
        
        foreach ($this->steps as $key => $title) {
            $index = array_search($key, $step_keys);
            $class = 'step ';
            
            if ($index < $current_index) {
                $class .= 'completed';
            } elseif ($index == $current_index) {
                $class .= 'active';
            } else {
                $class .= 'pending';
            }
            
            echo "<span class='$class'>$title</span>";
        }
        echo '</div>';
    }
    
    private function step_welcome() {
        $this->render_header();
        ?>
        <div class="text-center">
            <i class="bi bi-cloud-check text-primary" style="font-size: 5rem;"></i>
            <h2 class="mt-3">Benvenuto nel SaaS Installer</h2>
            <p class="lead">Installa la piattaforma multi-tenant per gestire più associazioni</p>
            
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-building text-primary fs-1"></i>
                            <h5 class="mt-2">Multi-Tenant</h5>
                            <p class="small text-muted">Gestisci più associazioni isolate tra loro</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-people text-success fs-1"></i>
                            <h5 class="mt-2">Soci e CRM</h5>
                            <p class="small text-muted">Anagrafica completa con campi personalizzati</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-check text-warning fs-1"></i>
                            <h5 class="mt-2">Sicurezza</h5>
                            <p class="small text-muted">Isolamento dati e controllo accessi</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <h5><i class="bi bi-info-circle"></i> Questo installer configurerà:</h5>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled text-start">
                            <li>✓ Database MySQL multi-tenant</li>
                            <li>✓ Schema con UUID e isolamento dati</li>
                            <li>✓ Sistema di autenticazione sicuro</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled text-start">
                            <li>✓ Prima associazione e super admin</li>
                            <li>✓ Configurazioni di sicurezza</li>
                            <li>✓ Directory e permessi</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <strong>⚠️ Requisiti:</strong>
                <ul class="list-unstyled mt-2 mb-0">
                    <li>• PHP 8.0+ con estensioni PDO, MySQL, JSON, mbstring</li>
                    <li>• MySQL 5.7+ o MariaDB 10.2+</li>
                    <li>• Permessi di scrittura sulla directory web</li>
                </ul>
            </div>
            
            <div class="mt-4">
                <a href="install.php?step=requirements" class="btn btn-primary btn-lg">
                    <i class="bi bi-rocket-takeoff"></i> Inizia Installazione SaaS
                </a>
            </div>
        </div>
        <?php
        $this->render_footer();
    }
    
    private function step_requirements() {
        $this->render_header();
        
        $requirements = $this->check_requirements();
        $all_ok = true;
        
        ?>
        <h4><i class="bi bi-list-check"></i> Verifica Requisiti Sistema</h4>
        <p>Controllo dei requisiti per l'installazione SaaS multi-tenant:</p>
        
        <?php foreach ($requirements as $req): ?>
            <div class="requirement-check <?php echo $req['status']; ?>">
                <div class="me-3">
                    <?php if ($req['status'] == 'success'): ?>
                        <i class="bi bi-check-circle text-success fs-5"></i>
                    <?php elseif ($req['status'] == 'error'): ?>
                        <i class="bi bi-x-circle text-danger fs-5"></i>
                        <?php $all_ok = false; ?>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle text-warning fs-5"></i>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <strong><?php echo $req['name']; ?></strong>
                    <div class="small text-muted"><?php echo $req['description']; ?></div>
                </div>
                <div>
                    <span class="badge bg-<?php echo $req['status'] == 'success' ? 'success' : ($req['status'] == 'error' ? 'danger' : 'warning'); ?>">
                        <?php echo $req['value']; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
        
        <div class="mt-4 d-flex justify-content-between">
            <a href="install.php?step=welcome" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Indietro
            </a>
            
            <?php if ($all_ok): ?>
                <a href="install.php?step=database" class="btn btn-primary">
                    Continua <i class="bi bi-arrow-right"></i>
                </a>
            <?php else: ?>
                <button class="btn btn-danger" disabled>
                    Risolvi i problemi per continuare
                </button>
            <?php endif; ?>
        </div>
        <?php
        $this->render_footer();
    }
    
    private function step_database() {
        if ($_POST) {
            $this->process_database_config();
            // If process was successful, it would have redirected
            // If we reach here, there were errors, so continue to show the form
        }
        
        $this->render_header();
        ?>
        <h4><i class="bi bi-database"></i> Configurazione Database SaaS</h4>
        <p>Configura il database MySQL per la piattaforma multi-tenant:</p>
        
        <?php if (!empty($this->errors)): ?>
            <div class="alert alert-danger">
                <h6><i class="bi bi-exclamation-triangle"></i> Errori di connessione:</h6>
                <ul class="mb-0">
                    <?php foreach ($this->errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label for="db_host" class="form-label">Host Database</label>
                        <input type="text" class="form-control" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? '127.0.0.1', ENT_QUOTES); ?>" required>
                        <div class="form-text">Indirizzo del server MySQL</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="db_port" class="form-label">Porta</label>
                        <input type="number" class="form-control" id="db_port" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306', ENT_QUOTES); ?>">
                        <div class="form-text">Porta MySQL (default: 3306)</div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="db_name" class="form-label">Nome Database</label>
                <input type="text" class="form-control" id="db_name" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'associazione_soci_saas', ENT_QUOTES); ?>" required>
                <div class="form-text">Il database SaaS verrà creato automaticamente se non esiste</div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="db_user" class="form-label">Username Database</label>
                        <input type="text" class="form-control" id="db_user" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? 'root', ENT_QUOTES); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="db_pass" class="form-label">Password Database</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? 'Fa310reds?', ENT_QUOTES); ?>">
                    </div>
                </div>
            </div>
            
            <div class="alert alert-info">
                <h6><i class="bi bi-info-circle"></i> Permessi richiesti per il database SaaS:</h6>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="mb-0">
                            <li>CREATE, DROP (creazione database e tabelle)</li>
                            <li>SELECT, INSERT, UPDATE, DELETE (operazioni dati)</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="mb-0">
                            <li>ALTER, INDEX (modifiche schema)</li>
                            <li>TRIGGER (per timestamp automatici)</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 d-flex justify-content-between">
                <a href="install.php?step=requirements" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Indietro
                </a>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-database-check"></i> Testa e Continua
                </button>
            </div>
        </form>
        <?php
        $this->render_footer();
    }
    
    private function step_association() {
        if ($_POST) {
            $this->process_association_config();
            // If process was successful, it would have redirected
            // If we reach here, there were errors, so continue to show the form
        }
        
        $this->render_header();
        ?>
        <h4><i class="bi bi-building"></i> Prima Associazione</h4>
        <p>Configura la prima associazione che verrà creata nella piattaforma SaaS:</p>
        
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_nome" class="form-label">Nome Associazione *</label>
                        <input type="text" class="form-control" id="assoc_nome" name="assoc_nome" value="<?php echo htmlspecialchars($_POST['assoc_nome'] ?? '', ENT_QUOTES); ?>" required>
                        <div class="form-text">Nome della tua associazione</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_email" class="form-label">Email Associazione *</label>
                        <input type="email" class="form-control" id="assoc_email" name="assoc_email" value="<?php echo htmlspecialchars($_POST['assoc_email'] ?? '', ENT_QUOTES); ?>" required>
                        <div class="form-text">Email principale dell'associazione</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_cf" class="form-label">Codice Fiscale</label>
                        <input type="text" class="form-control" id="assoc_cf" name="assoc_cf" value="<?php echo $_POST['assoc_cf'] ?? ''; ?>" maxlength="16">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_piva" class="form-label">Partita IVA</label>
                        <input type="text" class="form-control" id="assoc_piva" name="assoc_piva" value="<?php echo $_POST['assoc_piva'] ?? ''; ?>" maxlength="11">
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="assoc_indirizzo" class="form-label">Indirizzo</label>
                <input type="text" class="form-control" id="assoc_indirizzo" name="assoc_indirizzo" value="<?php echo $_POST['assoc_indirizzo'] ?? ''; ?>">
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_citta" class="form-label">Città</label>
                        <input type="text" class="form-control" id="assoc_citta" name="assoc_citta" value="<?php echo $_POST['assoc_citta'] ?? ''; ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="assoc_provincia" class="form-label">Provincia</label>
                        <input type="text" class="form-control" id="assoc_provincia" name="assoc_provincia" value="<?php echo $_POST['assoc_provincia'] ?? ''; ?>" maxlength="2">
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mb-3">
                        <label for="assoc_cap" class="form-label">CAP</label>
                        <input type="text" class="form-control" id="assoc_cap" name="assoc_cap" value="<?php echo $_POST['assoc_cap'] ?? ''; ?>" maxlength="5">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="assoc_telefono" class="form-label">Telefono</label>
                        <input type="tel" class="form-control" id="assoc_telefono" name="assoc_telefono" value="<?php echo $_POST['assoc_telefono'] ?? ''; ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="tipo_scadenza" class="form-label">Tipo Scadenza Default</label>
                        <select class="form-select" id="tipo_scadenza" name="tipo_scadenza">
                            <option value="solare" <?php echo ($_POST['tipo_scadenza'] ?? 'solare') == 'solare' ? 'selected' : ''; ?>>Solare (31 Dicembre)</option>
                            <option value="annuale" <?php echo ($_POST['tipo_scadenza'] ?? '') == 'annuale' ? 'selected' : ''; ?>>Annuale (data iscrizione)</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-success">
                <h6><i class="bi bi-check-circle"></i> Configurazione Multi-Tenant</h6>
                <p class="mb-0">Questa associazione avrà il suo spazio isolato nel sistema SaaS. Potrai aggiungere altre associazioni in futuro tramite il pannello di amministrazione.</p>
            </div>
            
            <div class="mt-4 d-flex justify-content-between">
                <a href="install.php?step=database" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Indietro
                </a>
                
                <button type="submit" class="btn btn-primary">
                    Continua <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        <?php
        $this->render_footer();
    }
    
    private function step_admin() {
        if ($_POST) {
            $this->process_admin_config();
            // If process was successful, it would have redirected
            // If we reach here, there were errors, so continue to show the form
        }
        
        $this->render_header();
        ?>
        <h4><i class="bi bi-person-gear"></i> Super Amministratore SaaS</h4>
        <p>Crea l'account super amministratore che gestirà la piattaforma SaaS:</p>
        
        <?php if (!empty($this->errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($this->errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="admin_nome" class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="admin_nome" name="admin_nome" value="<?php echo htmlspecialchars($_POST['admin_nome'] ?? '', ENT_QUOTES); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="admin_cognome" class="form-label">Cognome *</label>
                        <input type="text" class="form-control" id="admin_cognome" name="admin_cognome" value="<?php echo htmlspecialchars($_POST['admin_cognome'] ?? '', ENT_QUOTES); ?>" required>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="admin_email" class="form-label">Email *</label>
                <input type="email" class="form-control" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES); ?>" required>
                <div class="form-text">Sarà usata per il login al sistema SaaS</div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="admin_password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="admin_password" name="admin_password" required>
                        <div class="form-text">Min 8 caratteri, una maiuscola, una minuscola, un numero</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="admin_password_confirm" class="form-label">Conferma Password *</label>
                        <input type="password" class="form-control" id="admin_password_confirm" name="admin_password_confirm" required>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="bi bi-shield-exclamation"></i> Ruolo Super Admin</h6>
                <p class="mb-0">Il super amministratore può:</p>
                <ul class="mb-0 mt-2">
                    <li>Gestire tutte le associazioni nella piattaforma SaaS</li>
                    <li>Creare nuove associazioni e amministratori</li>
                    <li>Accedere alle configurazioni globali del sistema</li>
                </ul>
            </div>
            
            <div class="mt-4 d-flex justify-content-between">
                <a href="install.php?step=association" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Indietro
                </a>
                
                <button type="submit" class="btn btn-primary">
                    Continua <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </form>
        <?php
        $this->render_footer();
    }
    
    private function step_install() {
        $this->render_header();
        $this->execute_installation();
        $this->render_footer();
    }
    
    private function step_complete() {
        $this->render_header();
        $data = $_SESSION['install_data'] ?? [];
        ?>
        <div class="text-center">
            <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem;"></i>
            <h2 class="mt-3 text-success">Piattaforma SaaS Installata!</h2>
            <p class="lead">Associazione Soci Manager è pronto per gestire più associazioni</p>
            
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-person-circle"></i> Super Amministratore</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($data['admin_email'] ?? ''); ?></p>
                            <p class="mb-0"><strong>Ruolo:</strong> Super Admin SaaS</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-building"></i> Prima Associazione</h6>
                        </div>
                        <div class="card-body">
                            <p><strong>Nome:</strong> <?php echo htmlspecialchars($data['assoc_nome'] ?? ''); ?></p>
                            <p class="mb-0"><strong>Email:</strong> <?php echo htmlspecialchars($data['assoc_email'] ?? ''); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-success mt-4">
                <h5><i class="bi bi-check-circle"></i> Sistema SaaS Configurato:</h5>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled text-start">
                            <li>✓ Database multi-tenant creato</li>
                            <li>✓ Schema con UUID implementato</li>
                            <li>✓ Isolamento dati configurato</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-unstyled text-start">
                            <li>✓ Prima associazione creata</li>
                            <li>✓ Super amministratore configurato</li>
                            <li>✓ Directory e sicurezza impostati</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="alert alert-warning">
                <h6><i class="bi bi-shield-exclamation"></i> Sicurezza - Azione Richiesta:</h6>
                <p class="mb-0">Per sicurezza, <strong>elimina il file install.php</strong> dal server:</p>
                <code class="d-block mt-2">rm <?php echo __FILE__; ?></code>
            </div>
            
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-database-check text-success fs-1"></i>
                            <h6 class="mt-2">Database</h6>
                            <small class="text-muted"><?php echo htmlspecialchars($data['db_name'] ?? ''); ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-building-check text-primary fs-1"></i>
                            <h6 class="mt-2">Multi-Tenant</h6>
                            <small class="text-muted">1 associazione configurata</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="bi bi-shield-check text-warning fs-1"></i>
                            <h6 class="mt-2">Sicurezza</h6>
                            <small class="text-muted">Isolamento dati attivo</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <a href="index.php" class="btn btn-success btn-lg">
                    <i class="bi bi-rocket-takeoff"></i> Accedi alla Piattaforma SaaS
                </a>
            </div>
            
            <div class="mt-3">
                <small class="text-muted">
                    🎉 Piattaforma SaaS installata con successo - Puoi ora gestire più associazioni in modo isolato
                </small>
            </div>
        </div>
        <?php
        $this->render_footer();
    }
    
    // Implementation methods...
    
    private function check_requirements() {
        $requirements = [];
        
        // PHP Version - required 8.0+
        $php_version = PHP_VERSION;
        $requirements[] = [
            'name' => 'Versione PHP',
            'description' => 'Richiede PHP 8.0+ per il SaaS multi-tenant',
            'status' => version_compare($php_version, '8.0.0', '>=') ? 'success' : 'error',
            'value' => $php_version
        ];
        
        // Critical extensions for SaaS
        $extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'zip'];
        foreach ($extensions as $ext) {
            $requirements[] = [
                'name' => "Estensione $ext",
                'description' => "Estensione $ext richiesta per SaaS",
                'status' => extension_loaded($ext) ? 'success' : 'error',
                'value' => extension_loaded($ext) ? 'Installata' : 'Mancante'
            ];
        }
        
        // Directory permissions for SaaS
        $directories = ['.', 'uploads', 'uploads/documents', 'backups', 'backups/database', 'backups/files'];
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
            $writable = is_writable($dir);
            $requirements[] = [
                'name' => "Directory $dir",
                'description' => "Permessi scrittura per $dir",
                'status' => $writable ? 'success' : 'error',
                'value' => $writable ? 'Scrivibile' : 'Non scrivibile'
            ];
        }
        
        // Memory limit for SaaS operations
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = $this->return_bytes($memory_limit);
        $requirements[] = [
            'name' => 'Memory Limit',
            'description' => 'Raccomandati 256MB+ per SaaS multi-tenant',
            'status' => $memory_bytes >= 268435456 ? 'success' : 'warning',
            'value' => $memory_limit
        ];
        
        // UUID support check
        $uuid_test = function_exists('openssl_random_pseudo_bytes');
        $requirements[] = [
            'name' => 'Supporto UUID',
            'description' => 'Necessario per sistema multi-tenant',
            'status' => $uuid_test ? 'success' : 'error',
            'value' => $uuid_test ? 'Disponibile' : 'Mancante'
        ];
        
        return $requirements;
    }
    
    private function return_bytes($val) {
        if (empty($val)) return 0;
        $val = trim($val);
        $last = strtolower(substr($val, -1));
        $val = (int) $val;
        switch($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }
    
    private function process_database_config() {
        $this->errors = [];
        
        $db_host = trim($_POST['db_host']);
        $db_port = (int)($_POST['db_port'] ?? 3306);
        $db_name = trim($_POST['db_name']);
        $db_user = trim($_POST['db_user']);
        $db_pass = $_POST['db_pass'] ?? '';
        
        // Debug: controlla memoria
        $current_memory = memory_get_usage(true);
        error_log("Memory usage at start of database step: " . round($current_memory/1024/1024, 2) . "MB");
        
        // Validate inputs
        if (empty($db_host) || empty($db_name) || empty($db_user)) {
            $this->errors[] = "Tutti i campi obbligatori devono essere compilati";
            // Don't call step_database() directly to avoid recursion
            return;
        }
        
        try {
            // Test connection without database first
            error_log("Tentativo connessione database: $db_host:$db_port con utente $db_user");
            $dsn = "mysql:host=$db_host;port=$db_port;charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            error_log("Connessione database riuscita");
            
            // Create SaaS database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db_name`");
            
            // Test UUID generation
            $test_uuid = generateUuid();
            if (strlen($test_uuid) !== 36) {
                throw new Exception("Errore nella generazione UUID per sistema multi-tenant");
            }
            
            // Store database config
            $_SESSION['install_data']['db_host'] = $db_host;
            $_SESSION['install_data']['db_port'] = $db_port;
            $_SESSION['install_data']['db_name'] = $db_name;
            $_SESSION['install_data']['db_user'] = $db_user;
            $_SESSION['install_data']['db_pass'] = $db_pass;
            
            // Write .env file
            $this->writeEnvFile($db_host, $db_port, $db_name, $db_user, $db_pass);
            
            header('Location: install.php?step=association');
            exit;
            
        } catch (PDOException $e) {
            error_log("Errore PDO: " . $e->getMessage());
            $this->errors[] = "Errore connessione database: " . $e->getMessage();
            // Don't call step_database() directly to avoid recursion
            return;
        } catch (Exception $e) {
            error_log("Errore generico: " . $e->getMessage());
            $this->errors[] = $e->getMessage();
            // Don't call step_database() directly to avoid recursion
            return;
        }
    }
    
    private function process_association_config() {
        $assoc_nome = trim($_POST['assoc_nome']);
        $assoc_email = trim($_POST['assoc_email']);
        
        if (empty($assoc_nome) || empty($assoc_email)) {
            $this->errors[] = "Nome e email associazione sono obbligatori";
            // Don't call step_association() directly to avoid recursion
            return;
        }
        
        // Store association data
        $_SESSION['install_data']['assoc_nome'] = $assoc_nome;
        $_SESSION['install_data']['assoc_email'] = $assoc_email;
        $_SESSION['install_data']['assoc_cf'] = trim($_POST['assoc_cf'] ?? '');
        $_SESSION['install_data']['assoc_piva'] = trim($_POST['assoc_piva'] ?? '');
        $_SESSION['install_data']['assoc_indirizzo'] = trim($_POST['assoc_indirizzo'] ?? '');
        $_SESSION['install_data']['assoc_citta'] = trim($_POST['assoc_citta'] ?? '');
        $_SESSION['install_data']['assoc_provincia'] = strtoupper(trim($_POST['assoc_provincia'] ?? ''));
        $_SESSION['install_data']['assoc_cap'] = trim($_POST['assoc_cap'] ?? '');
        $_SESSION['install_data']['assoc_telefono'] = trim($_POST['assoc_telefono'] ?? '');
        $_SESSION['install_data']['tipo_scadenza'] = $_POST['tipo_scadenza'] ?? 'solare';
        
        header('Location: install.php?step=admin');
        exit;
    }
    
    private function process_admin_config() {
        $this->errors = [];
        
        $password = $_POST['admin_password'];
        $password_confirm = $_POST['admin_password_confirm'];
        
        // Password validation
        if ($password !== $password_confirm) {
            $this->errors[] = "Le password non corrispondono";
        }
        
        if (strlen($password) < 8) {
            $this->errors[] = "La password deve essere di almeno 8 caratteri";
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $this->errors[] = "La password deve contenere almeno una lettera maiuscola";
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $this->errors[] = "La password deve contenere almeno una lettera minuscola";
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $this->errors[] = "La password deve contenere almeno un numero";
        }
        
        $admin_email = trim($_POST['admin_email']);
        $admin_nome = trim($_POST['admin_nome']);
        $admin_cognome = trim($_POST['admin_cognome']);
        
        if (empty($admin_nome) || empty($admin_cognome) || empty($admin_email)) {
            $this->errors[] = "Tutti i campi obbligatori devono essere compilati";
        }
        
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Email non valida";
        }
        
        if (!empty($this->errors)) {
            // Don't call step_admin() directly to avoid recursion
            return;
        }
        
        // Store admin config
        $_SESSION['install_data']['admin_nome'] = $admin_nome;
        $_SESSION['install_data']['admin_cognome'] = $admin_cognome;
        $_SESSION['install_data']['admin_email'] = $admin_email;
        $_SESSION['install_data']['admin_password'] = password_hash($password, PASSWORD_DEFAULT);
        
        header('Location: install.php?step=install');
        exit;
    }
    
    private function writeEnvFile($db_host, $db_port, $db_name, $db_user, $db_pass) {
        $env_content = "# Database Configuration - Generated by installer\n";
        $env_content .= "DB_HOST=" . $db_host . "\n";
        $env_content .= "DB_NAME=" . $db_name . "\n";
        $env_content .= "DB_USER=" . $db_user . "\n";
        $env_content .= "DB_PASS=" . $db_pass . "\n";
        $env_content .= "\n# Environment\n";
        $env_content .= "APP_ENV=production\n";
        $env_content .= "\n# Security\n";
        $env_content .= "SESSION_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        
        $env_file = __DIR__ . '/.env';
        if (file_put_contents($env_file, $env_content) === false) {
            $this->log_step('⚠️ Attenzione: impossibile scrivere file .env');
        } else {
            $this->log_step('✅ File .env creato con successo');
        }
    }
    
    private function execute_installation() {
        echo '<h4><i class="bi bi-gear-fill"></i> Installazione SaaS Multi-Tenant</h4>';
        echo '<div id="install-progress">';
        
        $data = $_SESSION['install_data'] ?? null;
        
        // If session data is lost, redirect back to installer start
        if (!$data || empty($data['db_host'])) {
            $this->log_step('❌ Dati di installazione mancanti');
            echo '<div class="alert alert-danger">Errore: Dati di installazione mancanti. <a href="install.php">Riavvia l\'installazione</a></div>';
            return;
        }
        
        try {
            // Connect to database
            $this->log_step('🔗 Connessione al database SaaS...');
            error_log("Database config: host={$data['db_host']}, port={$data['db_port']}, name={$data['db_name']}, user={$data['db_user']}");
            $dsn = "mysql:host={$data['db_host']};port={$data['db_port']};dbname={$data['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $data['db_user'], $data['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            // Create SaaS database schema
            $this->log_step('🏗️ Creazione schema database multi-tenant...');
            $this->create_database_tables($pdo);
            
            // Create first association
            $this->log_step('🏢 Creazione prima associazione...');
            $association_id = $this->create_association($pdo, $data);
            
            // Create super admin user
            $this->log_step('👤 Creazione super amministratore...');
            $this->create_admin_user($pdo, $data, $association_id);
            
            // Create config file for SaaS
            $this->log_step('⚙️ Generazione configurazione SaaS...');
            $this->create_saas_config_file($data);
            
            // Setup directories and security
            $this->log_step('📁 Configurazione directory e permessi...');
            $this->setup_directories();
            $this->setup_security();
            
            // Insert default SaaS data
            $this->log_step('📊 Inserimento dati iniziali multi-tenant...');
            $this->insert_saas_default_data($pdo, $data, $association_id);
            
            $this->log_step('🎉 Piattaforma SaaS installata con successo!', 'success');
            
            echo '</div>';
            echo '<div class="mt-4 text-center">';
            echo '<a href="install.php?step=complete" class="btn btn-success btn-lg"><i class="bi bi-check-circle"></i> Completa Installazione</a>';
            echo '</div>';
            
        } catch (Exception $e) {
            $this->log_step('❌ Errore installazione: ' . $e->getMessage(), 'error');
            echo '</div>';
            echo '<div class="mt-4 text-center">';
            echo '<a href="install.php?step=database" class="btn btn-danger">🔄 Ricomincia Installazione</a>';
            echo '</div>';
        }
    }
    
    private function log_step($message, $type = 'info') {
        $class = $type === 'success' ? 'success' : ($type === 'error' ? 'danger' : 'info');
        echo "<div class='alert alert-$class mb-2'>$message</div>";
        echo str_pad('', 4096) . "\n";
        flush();
        if ($type !== 'error') sleep(1);
    }
    
    private function create_database_tables($pdo) {
        $schema_file = 'database_schema.sql';
        if (!file_exists($schema_file)) {
            throw new Exception("File schema database non trovato: $schema_file");
        }
        
        // Leggi il file riga per riga per risparmiare memoria
        $handle = fopen($schema_file, 'r');
        if (!$handle) {
            throw new Exception("Impossibile aprire il file schema: $schema_file");
        }
        
        $statement = '';
        $line_number = 0;
        
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $line = trim($line);
            
            // Salta righe vuote e commenti
            if (empty($line) || preg_match('/^\s*(--|#)/', $line)) {
                continue;
            }
            
            // Salta comandi di database
            if (preg_match('/^(CREATE DATABASE|USE |DELIMITER)/i', $line)) {
                continue;
            }
            
            $statement .= $line . ' ';
            
            // Esegui quando troviamo un semicolon di fine statement
            if (substr(rtrim($line), -1) === ';') {
                $statement = trim($statement);
                if (!empty($statement)) {
                    try {
                        $pdo->exec($statement);
                        $this->log_step("✅ Tabella creata (riga $line_number)");
                    } catch (PDOException $e) {
                        fclose($handle);
                        throw new Exception("Errore riga $line_number: " . $e->getMessage() . " - SQL: " . substr($statement, 0, 100));
                    }
                }
                $statement = '';
            }
        }
        
        fclose($handle);
        
        // Esegui statement finale se presente
        if (!empty(trim($statement))) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                throw new Exception("Errore statement finale: " . $e->getMessage());
            }
        }
    }
    
    private function create_association($pdo, $data) {
        $association_id = generateUuid();
        
        $stmt = $pdo->prepare("
            INSERT INTO associazioni (
                id, nome, partita_iva, codice_fiscale, indirizzo, citta, provincia, cap, 
                email, telefono, attiva, tipo_scadenza_default, giorni_notifica_scadenza
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 30)
        ");
        
        $stmt->execute([
            $association_id,
            $data['assoc_nome'],
            $data['assoc_piva'] ?: null,
            $data['assoc_cf'] ?: null,
            $data['assoc_indirizzo'] ?: null,
            $data['assoc_citta'] ?: null,
            $data['assoc_provincia'] ?: null,
            $data['assoc_cap'] ?: null,
            $data['assoc_email'],
            $data['assoc_telefono'] ?: null,
            $data['tipo_scadenza']
        ]);
        
        return $association_id;
    }
    
    private function create_admin_user($pdo, $data, $association_id) {
        $admin_id = generateUuid();
        
        $stmt = $pdo->prepare("
            INSERT INTO utenti (
                id, associazione_id, nome, cognome, email, password_hash, ruolo, attivo
            ) VALUES (?, ?, ?, ?, ?, ?, 'super_admin', 1)
        ");
        
        $stmt->execute([
            $admin_id,
            $association_id,
            $data['admin_nome'],
            $data['admin_cognome'],
            $data['admin_email'],
            $data['admin_password']
        ]);
    }
    
    private function create_saas_config_file($data) {
        // Use the existing config.php as template and update it
        $existing_config = file_get_contents('config.php');
        
        // Update database configuration
        $config_updates = [
            "'localhost'" => "'" . $data['db_host'] . "'",
            "'associazione_soci_saas'" => "'" . $data['db_name'] . "'",
            "'root'" => "'" . $data['db_user'] . "'",
            "''" => "'" . $data['db_pass'] . "'"
        ];
        
        // Apply minimal updates to maintain compatibility
        $updated_config = str_replace("INSTALLER_TEMP", "// SaaS Installation completed on " . date('Y-m-d H:i:s'), $existing_config);
        
        // Update database constants
        $updated_config = preg_replace("/define\('DB_HOST',\s*'[^']*'\);/", "define('DB_HOST', '{$data['db_host']}');", $updated_config);
        $updated_config = preg_replace("/define\('DB_NAME',\s*'[^']*'\);/", "define('DB_NAME', '{$data['db_name']}');", $updated_config);
        $updated_config = preg_replace("/define\('DB_USER',\s*'[^']*'\);/", "define('DB_USER', '{$data['db_user']}');", $updated_config);
        $updated_config = preg_replace("/define\('DB_PASS',\s*'[^']*'\);/", "define('DB_PASS', '{$data['db_pass']}');", $updated_config);
        
        if (!file_put_contents('config.php', $updated_config)) {
            throw new Exception("Impossibile aggiornare il file config.php");
        }
    }
    
    private function setup_directories() {
        $directories = [
            'uploads' => 0755,
            'uploads/documents' => 0755,
            'uploads/images' => 0755,
            'uploads/logos' => 0755,
            'backups' => 0755,
            'backups/database' => 0755,
            'backups/files' => 0755
        ];
        
        foreach ($directories as $dir => $permissions) {
            if (!file_exists($dir)) {
                mkdir($dir, $permissions, true);
            }
            chmod($dir, $permissions);
        }
    }
    
    private function setup_security() {
        // Create .htaccess for uploads security
        $uploads_htaccess = 'uploads/.htaccess';
        if (!file_exists($uploads_htaccess)) {
            $content = "# Security for uploads directory\n";
            $content .= "Options -Indexes\n";
            $content .= "<Files ~ \"\\.(php|phtml|php3|php4|php5|pl|py|jsp|asp|sh|cgi|exe)$\">\n";
            $content .= "    Order allow,deny\n";
            $content .= "    Deny from all\n";
            $content .= "</Files>\n";
            file_put_contents($uploads_htaccess, $content);
        }
        
        // Create .htaccess for backups directory
        $backup_htaccess = 'backups/.htaccess';
        if (!file_exists($backup_htaccess)) {
            $content = "# Deny all access to backup files\n";
            $content .= "Order allow,deny\n";
            $content .= "Deny from all\n";
            file_put_contents($backup_htaccess, $content);
        }
    }
    
    private function insert_saas_default_data($pdo, $data, $association_id) {
        // Create default categories for the association
        $categories = ['Socio Ordinario', 'Socio Sostenitore', 'Socio Onorario'];
        foreach ($categories as $cat) {
            $stmt = $pdo->prepare("INSERT INTO categorie_socio (id, associazione_id, nome) VALUES (?, ?, ?)");
            $stmt->execute([generateUuid(), $association_id, $cat]);
        }
        
        // Create default types for the association
        $types = ['Socio Effettivo', 'Socio Aggregato', 'Socio Junior'];
        foreach ($types as $type) {
            $stmt = $pdo->prepare("INSERT INTO tipi_socio (id, associazione_id, nome) VALUES (?, ?, ?)");
            $stmt->execute([generateUuid(), $association_id, $type]);
        }
        
        // Create main sede for the association
        $stmt = $pdo->prepare("
            INSERT INTO sedi (id, associazione_id, nome, indirizzo, citta, provincia, cap, email, telefono) 
            VALUES (?, ?, 'Sede Principale', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            generateUuid(),
            $association_id,
            $data['assoc_indirizzo'] ?: null,
            $data['assoc_citta'] ?: null,
            $data['assoc_provincia'] ?: null,
            $data['assoc_cap'] ?: null,
            $data['assoc_email'],
            $data['assoc_telefono'] ?: null
        ]);

        // Insert default tessera template (if table exists)
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'tessera_templates'");
            if ($check && $check->rowCount() > 0) {
                $tpl_id = generateUuid();
                $default_tpl = "Il/La sottoscritto/a {NOME_COMPLETO}, tessera n. {NUMERO_TESSERA}, è iscritto/a all'associazione {ASSOCIAZIONE_NOME} per l'anno {ANNO_VALIDITA}.";
                $ins = $pdo->prepare("INSERT INTO tessera_templates (id, associazione_id, tipo_socio_id, titolo, contenuto, attivo) VALUES (?, ?, NULL, 'Template Tessera', ?, 1)");
                $ins->execute([$tpl_id, $association_id, $default_tpl]);
            }
        } catch (PDOException $e) {
            // ignore if table missing or insert fails
        }
    }
}

// Run SaaS installer
$installer = new SaaSInstaller();
$installer->run();
?>
