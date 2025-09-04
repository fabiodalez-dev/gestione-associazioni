<?php
/**
 * Associazione Soci Manager - Setup Checker
 * Quick diagnostic tool for server environment
 */

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

class SetupChecker {
    private $checks = [];
    private $overall_status = 'success';
    
    public function run() {
        $this->perform_checks();
        $this->render_results();
    }
    
    private function perform_checks() {
        // PHP Version Check
        $this->check_php_version();
        
        // Required Extensions
        $this->check_extensions();
        
        // File Permissions
        $this->check_permissions();
        
        // Configuration
        $this->check_configuration();
        
        // Database Connection
        $this->check_database();
        
        // Application Status
        $this->check_application();
        
        // Security
        $this->check_security();
        
        // Performance
        $this->check_performance();
    }
    
    private function check_php_version() {
        $version = PHP_VERSION;
        $required = '7.4.0';
        
        if (version_compare($version, $required, '>=')) {
            $this->add_check('PHP Version', "✅ $version", 'success', 
                'PHP version is compatible');
        } else {
            $this->add_check('PHP Version', "❌ $version", 'error',
                "PHP $required or higher required. Current: $version");
            $this->overall_status = 'error';
        }
        
        // Check for recommended version
        if (version_compare($version, '8.0.0', '>=')) {
            $this->add_check('PHP Performance', '🚀 PHP 8.0+', 'success',
                'Using modern PHP version with performance benefits');
        }
    }
    
    private function check_extensions() {
        $required = [
            'pdo' => 'PDO database abstraction',
            'pdo_mysql' => 'MySQL PDO driver',
            'json' => 'JSON processing',
            'mbstring' => 'Multibyte string handling',
            'zip' => 'ZIP archive support',
            'curl' => 'HTTP client functionality'
        ];
        
        $optional = [
            'imagick' => 'Image processing',
            'gd' => 'Image manipulation', 
            'openssl' => 'SSL/TLS support',
            'intl' => 'Internationalization'
        ];
        
        foreach ($required as $ext => $description) {
            if (extension_loaded($ext)) {
                $this->add_check("Extension: $ext", '✅ Installed', 'success', $description);
            } else {
                $this->add_check("Extension: $ext", '❌ Missing', 'error', 
                    "$description - REQUIRED");
                $this->overall_status = 'error';
            }
        }
        
        foreach ($optional as $ext => $description) {
            if (extension_loaded($ext)) {
                $this->add_check("Extension: $ext", '✅ Available', 'success', 
                    "$description - Recommended");
            } else {
                $this->add_check("Extension: $ext", '⚠️ Not installed', 'warning',
                    "$description - Optional but recommended");
                if ($this->overall_status === 'success') {
                    $this->overall_status = 'warning';
                }
            }
        }
    }
    
    private function check_permissions() {
        $directories = [
            '.' => 'Application root',
            'uploads' => 'File uploads',
            'backups' => 'Backup storage',
            'assets' => 'Static assets'
        ];
        
        foreach ($directories as $dir => $description) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
            
            if (file_exists($dir) && is_writable($dir)) {
                $perms = substr(sprintf('%o', fileperms($dir)), -4);
                $this->add_check("Directory: $dir", "✅ Writable ($perms)", 'success',
                    "$description - Permissions OK");
            } else {
                $this->add_check("Directory: $dir", '❌ Not writable', 'error',
                    "$description - Cannot write to directory");
                $this->overall_status = 'error';
            }
        }
        
        // Check specific files
        $files = [
            'config.php' => 'Configuration file',
            'database_schema.sql' => 'Database schema',
            'install.php' => 'Installation script'
        ];
        
        foreach ($files as $file => $description) {
            if (file_exists($file)) {
                $readable = is_readable($file);
                $perms = substr(sprintf('%o', fileperms($file)), -4);
                
                if ($readable) {
                    $this->add_check("File: $file", "✅ Accessible ($perms)", 'success',
                        "$description - File OK");
                } else {
                    $this->add_check("File: $file", '❌ Not readable', 'error',
                        "$description - Cannot read file");
                    $this->overall_status = 'error';
                }
            } else {
                if ($file === 'config.php') {
                    $this->add_check("File: $file", '⚠️ Not found', 'warning',
                        "$description - Run installer to create");
                    if ($this->overall_status === 'success') {
                        $this->overall_status = 'warning';
                    }
                } else {
                    $this->add_check("File: $file", '🔍 Not found', 'info',
                        "$description - Optional file");
                }
            }
        }
    }
    
    private function check_configuration() {
        // Check important PHP settings
        $settings = [
            'file_uploads' => ['On', 'File upload capability'],
            'allow_url_fopen' => ['On', 'URL fopen for remote requests'],
            'register_globals' => ['Off', 'Security - should be disabled'],
            'magic_quotes_gpc' => ['Off', 'Security - should be disabled']
        ];
        
        foreach ($settings as $setting => $info) {
            list($expected, $description) = $info;
            $current = ini_get($setting);
            
            // Convert boolean values
            if ($current === '1') $current = 'On';
            if ($current === '') $current = 'Off';
            
            if (strtolower($current) === strtolower($expected) || 
                ($expected === 'Off' && !$current)) {
                $this->add_check("Setting: $setting", "✅ $current", 'success', $description);
            } else {
                $status = ($setting === 'register_globals' || $setting === 'magic_quotes_gpc') 
                    ? 'warning' : 'info';
                $this->add_check("Setting: $setting", "ℹ️ $current", $status,
                    "$description (Expected: $expected)");
                
                if ($status === 'warning' && $this->overall_status === 'success') {
                    $this->overall_status = 'warning';
                }
            }
        }
        
        // Memory and time limits
        $memory_limit = ini_get('memory_limit');
        $time_limit = ini_get('max_execution_time');
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        
        $this->add_check('Memory Limit', "ℹ️ $memory_limit", 'info',
            'Available memory for PHP scripts');
        $this->add_check('Execution Time', "ℹ️ {$time_limit}s", 'info',
            'Maximum script execution time');
        $this->add_check('Upload Max Size', "ℹ️ $upload_max", 'info',
            'Maximum file upload size');
        $this->add_check('POST Max Size', "ℹ️ $post_max", 'info',
            'Maximum POST data size');
    }
    
    private function check_database() {
        if (!file_exists('config.php')) {
            $this->add_check('Database Config', '⚠️ Not configured', 'warning',
                'Configuration file not found - run installer');
            return;
        }
        
        try {
            // Include config file
            ob_start();
            include 'config.php';
            ob_end_clean();
            
            if (isset($pdo) && $pdo instanceof PDO) {
                // Test connection
                $stmt = $pdo->query('SELECT VERSION() as version');
                $result = $stmt->fetch();
                
                $this->add_check('Database Connection', '✅ Connected', 'success',
                    'MySQL ' . $result['version']);
                
                // Check tables
                $stmt = $pdo->query('SHOW TABLES');
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $table_count = count($tables);
                
                if ($table_count > 0) {
                    $this->add_check('Database Tables', "✅ $table_count tables", 'success',
                        'Database schema is installed');
                        
                    // Check for admin users
                    if (in_array('amministratori', $tables)) {
                        $stmt = $pdo->query('SELECT COUNT(*) as count FROM amministratori');
                        $admin_count = $stmt->fetch()['count'];
                        
                        if ($admin_count > 0) {
                            $this->add_check('Admin Users', "✅ $admin_count user(s)", 'success',
                                'Administrator accounts configured');
                        } else {
                            $this->add_check('Admin Users', '⚠️ No admins', 'warning',
                                'No administrator accounts found');
                            if ($this->overall_status === 'success') {
                                $this->overall_status = 'warning';
                            }
                        }
                    }
                } else {
                    $this->add_check('Database Tables', '⚠️ Empty database', 'warning',
                        'No tables found - run installer');
                    if ($this->overall_status === 'success') {
                        $this->overall_status = 'warning';
                    }
                }
                
            } else {
                $this->add_check('Database Connection', '❌ Failed', 'error',
                    'Could not establish database connection');
                $this->overall_status = 'error';
            }
            
        } catch (Exception $e) {
            $this->add_check('Database Connection', '❌ Error', 'error',
                'Database error: ' . $e->getMessage());
            $this->overall_status = 'error';
        }
    }
    
    private function check_application() {
        // Check if application is installed
        if (file_exists('config.php')) {
            try {
                ob_start();
                include 'config.php';
                ob_end_clean();
                
                if (defined('APP_NAME')) {
                    $app_name = APP_NAME;
                    $this->add_check('Application Status', '✅ Installed', 'success',
                        "Application: $app_name");
                } else {
                    $this->add_check('Application Status', '⚠️ Partial', 'warning',
                        'Configuration incomplete');
                }
            } catch (Exception $e) {
                $this->add_check('Application Status', '❌ Error', 'error',
                    'Configuration error: ' . $e->getMessage());
            }
        } else {
            $this->add_check('Application Status', '⚠️ Not installed', 'warning',
                'Application not configured - run installer');
        }
        
        // Check critical files
        $critical_files = [
            'index.php' => 'Main application file',
            'auth/login.php' => 'Authentication system',
            'api/notifications.php' => 'Notification API',
            'pages/dashboard.php' => 'Dashboard page'
        ];
        
        foreach ($critical_files as $file => $description) {
            if (file_exists($file)) {
                $this->add_check("File: $file", '✅ Present', 'success', $description);
            } else {
                $this->add_check("File: $file", '❌ Missing', 'error', 
                    "$description - Critical file missing");
                $this->overall_status = 'error';
            }
        }
    }
    
    private function check_security() {
        // Check .htaccess
        if (file_exists('.htaccess')) {
            $htaccess_content = file_get_contents('.htaccess');
            if (strpos($htaccess_content, 'X-Frame-Options') !== false) {
                $this->add_check('Security Headers', '✅ Configured', 'success',
                    '.htaccess security headers present');
            } else {
                $this->add_check('Security Headers', '⚠️ Basic', 'warning',
                    '.htaccess exists but lacks security headers');
            }
        } else {
            $this->add_check('Security Headers', '⚠️ Missing', 'warning',
                'No .htaccess file found - consider adding security headers');
        }
        
        // Check backup directory protection
        if (file_exists('backups/.htaccess')) {
            $this->add_check('Backup Security', '✅ Protected', 'success',
                'Backup directory has access restrictions');
        } else {
            $this->add_check('Backup Security', '⚠️ Unprotected', 'warning',
                'Backup directory should be protected from web access');
        }
        
        // Check sensitive files
        $sensitive = ['config.php', 'database_schema.sql'];
        foreach ($sensitive as $file) {
            if (file_exists($file)) {
                // Try to access via HTTP (basic check)
                $url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'] . 
                       dirname($_SERVER['REQUEST_URI']) . '/' . $file;
                
                $this->add_check("File Protection: $file", 'ℹ️ Check manually', 'info',
                    "Verify $file is not accessible via: $url");
            }
        }
    }
    
    private function check_performance() {
        // Check for caching headers
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            $mod_expires = in_array('mod_expires', $modules);
            $mod_deflate = in_array('mod_deflate', $modules);
            
            if ($mod_expires) {
                $this->add_check('Caching Support', '✅ mod_expires', 'success',
                    'Apache expires module available');
            } else {
                $this->add_check('Caching Support', 'ℹ️ No mod_expires', 'info',
                    'Consider enabling mod_expires for better caching');
            }
            
            if ($mod_deflate) {
                $this->add_check('Compression', '✅ mod_deflate', 'success',
                    'Apache compression module available');
            } else {
                $this->add_check('Compression', 'ℹ️ No mod_deflate', 'info',
                    'Consider enabling mod_deflate for compression');
            }
        }
        
        // Check OpCache
        if (function_exists('opcache_get_status')) {
            $opcache_status = opcache_get_status();
            if ($opcache_status && $opcache_status['opcache_enabled']) {
                $this->add_check('OpCache', '🚀 Enabled', 'success',
                    'PHP OpCache is active - improved performance');
            } else {
                $this->add_check('OpCache', 'ℹ️ Disabled', 'info',
                    'Consider enabling OpCache for better performance');
            }
        } else {
            $this->add_check('OpCache', 'ℹ️ Not available', 'info',
                'OpCache not installed - consider enabling for production');
        }
    }
    
    private function add_check($name, $status, $level, $description) {
        $this->checks[] = [
            'name' => $name,
            'status' => $status,
            'level' => $level,
            'description' => $description
        ];
    }
    
    private function render_results() {
        ?>
        <!DOCTYPE html>
        <html lang="it">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Setup Check - Associazione Soci Manager</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
            <style>
                .check-item { 
                    border-left: 4px solid #dee2e6; 
                    margin-bottom: 8px; 
                    padding: 12px;
                    background: white;
                    border-radius: 0 8px 8px 0;
                }
                .check-item.success { border-left-color: #198754; }
                .check-item.warning { border-left-color: #ffc107; }
                .check-item.error { border-left-color: #dc3545; }
                .check-item.info { border-left-color: #0dcaf0; }
                .status-icon { font-size: 1.2em; margin-right: 8px; }
                .overall-status { 
                    font-size: 1.5rem; 
                    padding: 20px; 
                    text-align: center; 
                    border-radius: 12px;
                    margin-bottom: 20px;
                }
                .overall-status.success { background: #d1edff; color: #0f5132; }
                .overall-status.warning { background: #fff3cd; color: #664d03; }
                .overall-status.error { background: #f8d7da; color: #842029; }
                .refresh-button { position: fixed; bottom: 20px; right: 20px; }
            </style>
        </head>
        <body class="bg-light">
            <div class="container-fluid" style="max-width: 1200px;">
                <div class="py-4">
                    <h1 class="text-center mb-4">
                        <i class="bi bi-gear-fill text-primary"></i>
                        Setup Check
                    </h1>
                    
                    <div class="overall-status <?php echo $this->overall_status; ?>">
                        <?php
                        $status_icons = [
                            'success' => 'bi-check-circle-fill',
                            'warning' => 'bi-exclamation-triangle-fill', 
                            'error' => 'bi-x-circle-fill'
                        ];
                        $status_messages = [
                            'success' => 'System is ready for production use',
                            'warning' => 'System is functional but has some issues to address',
                            'error' => 'Critical issues found - system may not work properly'
                        ];
                        ?>
                        <i class="bi <?php echo $status_icons[$this->overall_status]; ?> me-2"></i>
                        <strong>Overall Status: <?php echo ucfirst($this->overall_status); ?></strong>
                        <div class="small mt-2">
                            <?php echo $status_messages[$this->overall_status]; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <?php
                        $categories = [
                            'Environment' => ['php', 'extension', 'setting', 'memory', 'execution', 'upload', 'post'],
                            'File System' => ['directory', 'file'],
                            'Database' => ['database'],
                            'Application' => ['application'],
                            'Security' => ['security', 'protection'],
                            'Performance' => ['caching', 'compression', 'opcache']
                        ];
                        
                        foreach ($categories as $category => $keywords) {
                            $category_checks = array_filter($this->checks, function($check) use ($keywords) {
                                $name_lower = strtolower($check['name']);
                                foreach ($keywords as $keyword) {
                                    if (strpos($name_lower, $keyword) !== false) {
                                        return true;
                                    }
                                }
                                return false;
                            });
                            
                            if (empty($category_checks)) continue;
                            ?>
                            <div class="col-md-6 col-lg-4 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0"><?php echo $category; ?></h5>
                                    </div>
                                    <div class="card-body p-2">
                                        <?php foreach ($category_checks as $check): ?>
                                            <div class="check-item <?php echo $check['level']; ?>">
                                                <div class="d-flex align-items-start">
                                                    <div class="status-icon">
                                                        <?php echo $check['status']; ?>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="fw-bold small">
                                                            <?php echo htmlspecialchars($check['name']); ?>
                                                        </div>
                                                        <div class="text-muted small">
                                                            <?php echo htmlspecialchars($check['description']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="bi bi-info-circle"></i> System Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <table class="table table-sm">
                                                <tr><td><strong>PHP Version</strong></td><td><?php echo PHP_VERSION; ?></td></tr>
                                                <tr><td><strong>Server Software</strong></td><td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></td></tr>
                                                <tr><td><strong>Operating System</strong></td><td><?php echo PHP_OS; ?></td></tr>
                                                <tr><td><strong>Server Time</strong></td><td><?php echo date('Y-m-d H:i:s T'); ?></td></tr>
                                            </table>
                                        </div>
                                        <div class="col-md-6">
                                            <table class="table table-sm">
                                                <tr><td><strong>Document Root</strong></td><td><?php echo $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'; ?></td></tr>
                                                <tr><td><strong>Script Path</strong></td><td><?php echo __FILE__; ?></td></tr>
                                                <tr><td><strong>User Agent</strong></td><td class="small"><?php echo $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'; ?></td></tr>
                                                <tr><td><strong>Check Time</strong></td><td><?php echo date('Y-m-d H:i:s'); ?></td></tr>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <?php if ($this->overall_status === 'error'): ?>
                            <div class="alert alert-danger">
                                <strong>Critical Issues Found!</strong><br>
                                Please resolve the errors above before proceeding.
                            </div>
                        <?php elseif ($this->overall_status === 'warning'): ?>
                            <div class="alert alert-warning">
                                <strong>Some Issues Found</strong><br>
                                The system should work, but consider addressing the warnings.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <strong>All Checks Passed!</strong><br>
                                Your system is ready for Associazione Soci Manager.
                            </div>
                        <?php endif; ?>
                        
                        <div class="btn-group mt-3" role="group">
                            <?php if (file_exists('install.php')): ?>
                                <a href="install.php" class="btn btn-primary">
                                    <i class="bi bi-play-circle"></i> Run Installer
                                </a>
                            <?php endif; ?>
                            
                            <?php if (file_exists('index.php')): ?>
                                <a href="index.php" class="btn btn-success">
                                    <i class="bi bi-house"></i> Go to Application
                                </a>
                            <?php endif; ?>
                            
                            <button onclick="location.reload()" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Refresh Check
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <button onclick="location.reload()" class="btn btn-primary refresh-button">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
        </body>
        </html>
        <?php
    }
}

// Run the setup checker
$checker = new SetupChecker();
$checker->run();
?>