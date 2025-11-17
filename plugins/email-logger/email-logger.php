<?php
/**
 * Plugin Name: Email Logger
 * Plugin URI: https://gestione-associazioni.local/plugins/email-logger
 * Description: Logga tutte le email inviate dall'applicazione con tracking dettagliato di delivery, bounces e opens
 * Version: 1.0.0
 * Author: Gestione Associazioni Team
 * Author URI: https://gestione-associazioni.local
 * License: GPL2
 * Requires PHP: 7.4
 */

class Plugin_email_logger {
    /**
     * Database PDO connection
     */
    private $pdo;

    /**
     * Plugin version
     */
    const VERSION = '1.0.0';

    /**
     * Inizializza il plugin
     */
    public function init() {
        global $pdo;
        $this->pdo = $pdo;

        // Registra hooks
        $this->registerHooks();

        // Create database table if needed
        $this->createTables();
    }

    /**
     * Registra hooks e filtri
     */
    private function registerHooks() {
        // Hook quando viene inviata un'email
        HookManager::addAction('email_sent', [$this, 'logEmailSent'], 10);

        // Hook quando inizia invio email massivo
        HookManager::addAction('bulk_email_started', [$this, 'logBulkEmailStart'], 10);

        // Filtro per aggiungere tracking all'email
        HookManager::addFilter('email_content', [$this, 'addEmailTracking'], 10);

        // Aggiungi voce menu per vedere log
        HookManager::addFilter('sidebar_menu_items', [$this, 'addMenuItems'], 10);

        // Hook per widget dashboard
        HookManager::addAction('dashboard_widgets', [$this, 'renderDashboardWidget']);
    }

    /**
     * Crea tabelle database
     */
    private function createTables() {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                to_email VARCHAR(255) NOT NULL,
                to_name VARCHAR(255),
                subject VARCHAR(500),
                body TEXT,
                email_type VARCHAR(50),
                status ENUM('sent', 'failed', 'bounced', 'opened') DEFAULT 'sent',
                sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                opened_at DATETIME NULL,
                tracking_id VARCHAR(32) UNIQUE,
                user_agent TEXT,
                ip_address VARCHAR(45),
                error_message TEXT,
                metadata JSON,
                INDEX idx_to_email (to_email),
                INDEX idx_sent_at (sent_at),
                INDEX idx_status (status),
                INDEX idx_tracking (tracking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("Email Logger Plugin: Errore creazione tabella: " . $e->getMessage());
        }
    }

    /**
     * Log email inviata
     *
     * @param string $to Email destinatario
     * @param string $subject Oggetto
     * @param bool $success Successo invio
     */
    public function logEmailSent($to, $subject, $success) {
        try {
            $tracking_id = bin2hex(random_bytes(16));

            $stmt = $this->pdo->prepare("INSERT INTO email_log
                (to_email, subject, status, tracking_id, metadata)
                VALUES (?, ?, ?, ?, ?)");

            $metadata = json_encode([
                'success' => $success,
                'timestamp' => time(),
                'user_id' => $_SESSION['user_id'] ?? null
            ]);

            $stmt->execute([
                $to,
                $subject,
                $success ? 'sent' : 'failed',
                $tracking_id,
                $metadata
            ]);

            // Store tracking ID in session for adding pixel
            $_SESSION['last_email_tracking_id'] = $tracking_id;

        } catch (PDOException $e) {
            error_log("Email Logger: Errore log email: " . $e->getMessage());
        }
    }

    /**
     * Log inizio invio massivo
     *
     * @param int $count Numero destinatari
     * @param string $template Template usato
     */
    public function logBulkEmailStart($count, $template) {
        error_log("Email Logger: Iniziato invio massivo di $count email usando template '$template'");

        // Qui potresti salvare in una tabella separata per bulk jobs
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS bulk_email_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template VARCHAR(100),
                recipients_count INT,
                started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                status ENUM('running', 'completed', 'failed') DEFAULT 'running'
            ) ENGINE=InnoDB");

            $stmt = $this->pdo->prepare("INSERT INTO bulk_email_jobs (template, recipients_count) VALUES (?, ?)");
            $stmt->execute([$template, $count]);
        } catch (PDOException $e) {
            error_log("Email Logger: Errore log bulk: " . $e->getMessage());
        }
    }

    /**
     * Aggiungi tracking pixel all'email
     *
     * @param string $content Contenuto email
     * @param string $type Tipo email
     * @param array $recipient Dati destinatario
     * @return string Contenuto con tracking
     */
    public function addEmailTracking($content, $type, $recipient) {
        // Ottieni tracking ID dall'ultima email loggata
        $tracking_id = $_SESSION['last_email_tracking_id'] ?? null;

        if (!$tracking_id) {
            return $content;
        }

        // Aggiungi tracking pixel invisibile
        $tracking_pixel = sprintf(
            '<img src="%s/api/email-track.php?id=%s" width="1" height="1" style="display:none;" alt="">',
            $_SERVER['HTTP_HOST'] ?? 'localhost',
            $tracking_id
        );

        // Aggiungi pixel alla fine dell'HTML
        if (strpos($content, '</body>') !== false) {
            $content = str_replace('</body>', $tracking_pixel . '</body>', $content);
        } else {
            $content .= $tracking_pixel;
        }

        return $content;
    }

    /**
     * Aggiungi voci menu
     *
     * @param array $items Menu items
     * @return array Menu items modificato
     */
    public function addMenuItems($items) {
        $items[] = [
            'name' => 'Email Log',
            'url' => '/index.php?page=email-log',
            'icon' => 'bi-envelope-check',
            'section' => 'tools'
        ];

        return $items;
    }

    /**
     * Renderizza widget dashboard
     */
    public function renderDashboardWidget() {
        try {
            // Statistiche ultime 7 giorni
            $stmt = $this->pdo->query("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM email_log
                WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

            $stats = $stmt->fetch();

            $open_rate = $stats['sent'] > 0 ? round(($stats['opened'] / $stats['sent']) * 100, 1) : 0;

            ?>
            <div class="col-md-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                                <i class="bi bi-envelope-check fs-4 text-primary"></i>
                            </div>
                            <div>
                                <h6 class="card-title mb-0">Email Inviate</h6>
                                <small class="text-muted">Ultimi 7 giorni</small>
                            </div>
                        </div>
                        <div class="row text-center">
                            <div class="col-4">
                                <h4 class="mb-0 text-success"><?php echo $stats['sent']; ?></h4>
                                <small class="text-muted">Inviate</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-0 text-info"><?php echo $stats['opened']; ?></h4>
                                <small class="text-muted">Aperte</small>
                            </div>
                            <div class="col-4">
                                <h4 class="mb-0 text-danger"><?php echo $stats['failed']; ?></h4>
                                <small class="text-muted">Fallite</small>
                            </div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted">Open Rate</span>
                            <span class="badge bg-primary"><?php echo $open_rate; ?>%</span>
                        </div>
                        <div class="progress mt-2" style="height: 5px;">
                            <div class="progress-bar" style="width: <?php echo $open_rate; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        } catch (PDOException $e) {
            error_log("Email Logger Widget: " . $e->getMessage());
        }
    }

    /**
     * Attivazione plugin
     */
    public function activate() {
        $this->createTables();
        error_log("Email Logger Plugin: Attivato con successo");
    }

    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        error_log("Email Logger Plugin: Disattivato");
        // Non eliminiamo i log quando il plugin viene disattivato
    }

    /**
     * Ottieni statistiche email
     *
     * @param int $days Giorni da considerare
     * @return array Statistiche
     */
    public function getStats($days = 30) {
        try {
            $stmt = $this->pdo->prepare("SELECT
                DATE(sent_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'opened' THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM email_log
                WHERE sent_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(sent_at)
                ORDER BY date DESC");

            $stmt->execute([$days]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Email Logger: Errore stats: " . $e->getMessage());
            return [];
        }
    }
}

// Il plugin viene automaticamente istanziato dal PluginManager
