<?php
/**
 * Plugin Manager - Gestione Plugin
 *
 * Carica, attiva, disattiva e gestisce i plugin dell'applicazione.
 * I plugin possono estendere le funzionalità attraverso hooks e filtri.
 *
 * @package GestioneAssociazioni
 * @version 1.0.0
 */

require_once __DIR__ . '/HookManager.php';

class PluginManager {
    /**
     * Directory dei plugin
     */
    private static $plugins_dir = null;

    /**
     * Plugin caricati
     * Formato: ['plugin_slug' => ['info' => array, 'instance' => object, 'active' => bool]]
     */
    private static $plugins = [];

    /**
     * Plugin attivi (salvati in database)
     */
    private static $active_plugins = [];

    /**
     * Database PDO connection
     */
    private static $pdo = null;

    /**
     * Inizializza il Plugin Manager
     *
     * @param PDO $pdo Database connection
     * @param string $plugins_dir Directory dei plugin (default: APP_ROOT/plugins)
     */
    public static function init($pdo, $plugins_dir = null) {
        self::$pdo = $pdo;
        self::$plugins_dir = $plugins_dir ?? (defined('APP_ROOT') ? APP_ROOT . '/plugins' : __DIR__ . '/../plugins');

        // Crea directory plugin se non esiste
        if (!is_dir(self::$plugins_dir)) {
            mkdir(self::$plugins_dir, 0755, true);
        }

        // Crea tabella plugin se non esiste
        self::createPluginTable();

        // Carica lista plugin attivi dal database
        self::loadActivePlugins();

        // Scansiona e carica i plugin
        self::discoverPlugins();
    }

    /**
     * Crea tabella per gestione plugin
     */
    private static function createPluginTable() {
        try {
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS plugins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                version VARCHAR(50),
                author VARCHAR(255),
                description TEXT,
                active BOOLEAN DEFAULT FALSE,
                settings JSON,
                installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (active),
                INDEX idx_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {
            error_log("PluginManager: Errore creazione tabella plugins: " . $e->getMessage());
        }
    }

    /**
     * Carica lista plugin attivi dal database
     */
    private static function loadActivePlugins() {
        try {
            $stmt = self::$pdo->query("SELECT slug FROM plugins WHERE active = 1");
            self::$active_plugins = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("PluginManager: Errore caricamento plugin attivi: " . $e->getMessage());
            self::$active_plugins = [];
        }
    }

    /**
     * Scopre e registra tutti i plugin disponibili
     */
    private static function discoverPlugins() {
        if (!is_dir(self::$plugins_dir)) {
            return;
        }

        // Scansiona directory plugin
        $plugin_folders = array_diff(scandir(self::$plugins_dir), ['.', '..']);

        foreach ($plugin_folders as $folder) {
            $plugin_path = self::$plugins_dir . '/' . $folder;

            // Verifica che sia una directory
            if (!is_dir($plugin_path)) {
                continue;
            }

            // Cerca file plugin principale (plugin-name.php o main.php)
            $main_file = null;
            if (file_exists($plugin_path . '/' . $folder . '.php')) {
                $main_file = $plugin_path . '/' . $folder . '.php';
            } elseif (file_exists($plugin_path . '/main.php')) {
                $main_file = $plugin_path . '/main.php';
            } elseif (file_exists($plugin_path . '/plugin.php')) {
                $main_file = $plugin_path . '/plugin.php';
            }

            if (!$main_file) {
                continue;
            }

            // Leggi header del plugin
            $plugin_info = self::parsePluginHeader($main_file);
            $plugin_info['slug'] = $folder;
            $plugin_info['path'] = $main_file;

            // Registra plugin
            self::$plugins[$folder] = [
                'info' => $plugin_info,
                'active' => in_array($folder, self::$active_plugins),
                'instance' => null
            ];

            // Carica plugin se attivo
            if (in_array($folder, self::$active_plugins)) {
                self::loadPlugin($folder);
            }
        }
    }

    /**
     * Parse header del file plugin
     * Header format simile a WordPress:
     * /*
     *  * Plugin Name: Nome Plugin
     *  * Plugin URI: https://...
     *  * Description: Descrizione
     *  * Version: 1.0.0
     *  * Author: Nome Autore
     *  * Author URI: https://...
     *  * License: GPL2
     *  * /
     *
     * @param string $file_path Path al file plugin
     * @return array Plugin info
     */
    private static function parsePluginHeader($file_path) {
        $default = [
            'name' => 'Unknown Plugin',
            'uri' => '',
            'description' => '',
            'version' => '1.0.0',
            'author' => 'Unknown',
            'author_uri' => '',
            'license' => '',
            'requires_php' => '7.4'
        ];

        if (!file_exists($file_path)) {
            return $default;
        }

        $file_data = file_get_contents($file_path);

        // Parse headers
        $headers = [
            'name' => 'Plugin Name',
            'uri' => 'Plugin URI',
            'description' => 'Description',
            'version' => 'Version',
            'author' => 'Author',
            'author_uri' => 'Author URI',
            'license' => 'License',
            'requires_php' => 'Requires PHP'
        ];

        $plugin_data = $default;

        foreach ($headers as $key => $header) {
            if (preg_match('/' . preg_quote($header, '/') . ':\s*(.+)/i', $file_data, $match)) {
                $plugin_data[$key] = trim($match[1]);
            }
        }

        return $plugin_data;
    }

    /**
     * Carica un plugin
     *
     * @param string $slug Plugin slug
     * @return bool Success
     */
    private static function loadPlugin($slug) {
        if (!isset(self::$plugins[$slug])) {
            return false;
        }

        $plugin = self::$plugins[$slug];

        if ($plugin['instance'] !== null) {
            // Già caricato
            return true;
        }

        try {
            // Include file plugin
            require_once $plugin['info']['path'];

            // Cerca classe Plugin_{slug} o semplicemente {slug}
            $class_name = 'Plugin_' . str_replace('-', '_', $slug);
            if (!class_exists($class_name)) {
                $class_name = str_replace('-', '_', $slug);
            }

            // Crea istanza se classe esiste
            if (class_exists($class_name)) {
                self::$plugins[$slug]['instance'] = new $class_name();

                // Chiama metodo init se esiste
                if (method_exists(self::$plugins[$slug]['instance'], 'init')) {
                    self::$plugins[$slug]['instance']->init();
                }

                // Chiama hook plugin_loaded
                HookManager::doAction('plugin_loaded', $slug, self::$plugins[$slug]['instance']);

                return true;
            }

            return true; // File caricato anche senza classe
        } catch (Exception $e) {
            error_log("PluginManager: Errore caricamento plugin '$slug': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Attiva un plugin
     *
     * @param string $slug Plugin slug
     * @return bool Success
     */
    public static function activate($slug) {
        if (!isset(self::$plugins[$slug])) {
            return false;
        }

        // Verifica se già attivo
        if (self::$plugins[$slug]['active']) {
            return true;
        }

        // Carica plugin
        if (!self::loadPlugin($slug)) {
            return false;
        }

        // Chiama hook attivazione se esiste
        if (isset(self::$plugins[$slug]['instance']) && method_exists(self::$plugins[$slug]['instance'], 'activate')) {
            self::$plugins[$slug]['instance']->activate();
        }

        // Salva in database
        try {
            $info = self::$plugins[$slug]['info'];
            $stmt = self::$pdo->prepare("INSERT INTO plugins (slug, name, version, author, description, active)
                                         VALUES (?, ?, ?, ?, ?, 1)
                                         ON DUPLICATE KEY UPDATE active = 1");
            $stmt->execute([
                $slug,
                $info['name'],
                $info['version'],
                $info['author'],
                $info['description']
            ]);

            self::$plugins[$slug]['active'] = true;
            self::$active_plugins[] = $slug;

            HookManager::doAction('plugin_activated', $slug);

            return true;
        } catch (PDOException $e) {
            error_log("PluginManager: Errore attivazione plugin '$slug': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Disattiva un plugin
     *
     * @param string $slug Plugin slug
     * @return bool Success
     */
    public static function deactivate($slug) {
        if (!isset(self::$plugins[$slug])) {
            return false;
        }

        // Chiama hook disattivazione se esiste
        if (isset(self::$plugins[$slug]['instance']) && method_exists(self::$plugins[$slug]['instance'], 'deactivate')) {
            self::$plugins[$slug]['instance']->deactivate();
        }

        // Aggiorna database
        try {
            $stmt = self::$pdo->prepare("UPDATE plugins SET active = 0 WHERE slug = ?");
            $stmt->execute([$slug]);

            self::$plugins[$slug]['active'] = false;
            self::$active_plugins = array_diff(self::$active_plugins, [$slug]);

            HookManager::doAction('plugin_deactivated', $slug);

            return true;
        } catch (PDOException $e) {
            error_log("PluginManager: Errore disattivazione plugin '$slug': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene lista di tutti i plugin
     *
     * @return array Plugins
     */
    public static function getAll() {
        return self::$plugins;
    }

    /**
     * Ottiene plugin attivi
     *
     * @return array Active plugins
     */
    public static function getActive() {
        return array_filter(self::$plugins, function($plugin) {
            return $plugin['active'];
        });
    }

    /**
     * Verifica se un plugin è attivo
     *
     * @param string $slug Plugin slug
     * @return bool
     */
    public static function isActive($slug) {
        return isset(self::$plugins[$slug]) && self::$plugins[$slug]['active'];
    }

    /**
     * Ottiene informazioni su un plugin
     *
     * @param string $slug Plugin slug
     * @return array|null Plugin info
     */
    public static function getInfo($slug) {
        return isset(self::$plugins[$slug]) ? self::$plugins[$slug]['info'] : null;
    }

    /**
     * Ottiene istanza di un plugin
     *
     * @param string $slug Plugin slug
     * @return object|null Plugin instance
     */
    public static function getInstance($slug) {
        return isset(self::$plugins[$slug]) ? self::$plugins[$slug]['instance'] : null;
    }

    /**
     * Ottiene directory plugin
     *
     * @return string Plugins directory path
     */
    public static function getPluginsDir() {
        return self::$plugins_dir;
    }

    /**
     * Ottiene statistiche plugin
     *
     * @return array Stats
     */
    public static function getStats() {
        return [
            'total' => count(self::$plugins),
            'active' => count(self::getActive()),
            'inactive' => count(self::$plugins) - count(self::getActive())
        ];
    }
}
