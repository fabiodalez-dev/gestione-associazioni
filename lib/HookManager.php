<?php
/**
 * Hook Manager - Sistema di Hooks per Plugin
 *
 * Permette ai plugin di agganciare funzioni a punti specifici dell'applicazione.
 * Ispirato ai sistemi di hooks di WordPress e Laravel.
 *
 * @package GestioneAssociazioni
 * @version 1.0.0
 */

class HookManager {
    /**
     * Array di hooks registrati
     * Formato: ['hook_name' => [['callback' => callable, 'priority' => int], ...]]
     */
    private static $hooks = [];

    /**
     * Array di filtri registrati (simile a hooks ma con valore di ritorno)
     * Formato: ['filter_name' => [['callback' => callable, 'priority' => int], ...]]
     */
    private static $filters = [];

    /**
     * Log delle azioni eseguite (per debugging)
     */
    private static $execution_log = [];

    /**
     * Flag per abilitare logging
     */
    private static $debug_mode = false;

    /**
     * Registra un hook (action)
     *
     * @param string $hook_name Nome del hook
     * @param callable $callback Funzione da eseguire
     * @param int $priority Priorità di esecuzione (default 10, più basso = prima)
     * @return bool Success
     */
    public static function addAction($hook_name, $callback, $priority = 10) {
        if (!is_callable($callback)) {
            error_log("HookManager: callback non valida per hook '$hook_name'");
            return false;
        }

        if (!isset(self::$hooks[$hook_name])) {
            self::$hooks[$hook_name] = [];
        }

        self::$hooks[$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Ordina per priorità
        usort(self::$hooks[$hook_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        if (self::$debug_mode) {
            error_log("HookManager: Registrato hook '$hook_name' con priorità $priority");
        }

        return true;
    }

    /**
     * Esegue tutti i callback registrati per un hook
     *
     * @param string $hook_name Nome del hook da eseguire
     * @param mixed ...$args Argomenti da passare ai callback
     * @return void
     */
    public static function doAction($hook_name, ...$args) {
        $start_time = microtime(true);

        if (!isset(self::$hooks[$hook_name]) || empty(self::$hooks[$hook_name])) {
            if (self::$debug_mode) {
                error_log("HookManager: Nessun callback per hook '$hook_name'");
            }
            return;
        }

        $executed = 0;
        foreach (self::$hooks[$hook_name] as $hook) {
            try {
                call_user_func_array($hook['callback'], $args);
                $executed++;
            } catch (Exception $e) {
                error_log("HookManager: Errore eseguendo hook '$hook_name': " . $e->getMessage());
            }
        }

        $elapsed = microtime(true) - $start_time;

        if (self::$debug_mode) {
            self::$execution_log[] = [
                'hook' => $hook_name,
                'type' => 'action',
                'callbacks_executed' => $executed,
                'time_ms' => round($elapsed * 1000, 2)
            ];
            error_log("HookManager: Eseguiti $executed callback per hook '$hook_name' in " . round($elapsed * 1000, 2) . "ms");
        }
    }

    /**
     * Registra un filtro
     * I filtri modificano un valore e lo restituiscono
     *
     * @param string $filter_name Nome del filtro
     * @param callable $callback Funzione da eseguire (deve accettare $value come primo parametro e restituirlo modificato)
     * @param int $priority Priorità di esecuzione
     * @return bool Success
     */
    public static function addFilter($filter_name, $callback, $priority = 10) {
        if (!is_callable($callback)) {
            error_log("HookManager: callback non valida per filtro '$filter_name'");
            return false;
        }

        if (!isset(self::$filters[$filter_name])) {
            self::$filters[$filter_name] = [];
        }

        self::$filters[$filter_name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Ordina per priorità
        usort(self::$filters[$filter_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        if (self::$debug_mode) {
            error_log("HookManager: Registrato filtro '$filter_name' con priorità $priority");
        }

        return true;
    }

    /**
     * Applica tutti i filtri registrati a un valore
     *
     * @param string $filter_name Nome del filtro
     * @param mixed $value Valore da filtrare
     * @param mixed ...$args Argomenti aggiuntivi da passare ai filtri
     * @return mixed Valore filtrato
     */
    public static function applyFilters($filter_name, $value, ...$args) {
        $start_time = microtime(true);

        if (!isset(self::$filters[$filter_name]) || empty(self::$filters[$filter_name])) {
            if (self::$debug_mode) {
                error_log("HookManager: Nessun filtro per '$filter_name'");
            }
            return $value;
        }

        $executed = 0;
        foreach (self::$filters[$filter_name] as $filter) {
            try {
                $value = call_user_func_array($filter['callback'], array_merge([$value], $args));
                $executed++;
            } catch (Exception $e) {
                error_log("HookManager: Errore applicando filtro '$filter_name': " . $e->getMessage());
            }
        }

        $elapsed = microtime(true) - $start_time;

        if (self::$debug_mode) {
            self::$execution_log[] = [
                'hook' => $filter_name,
                'type' => 'filter',
                'callbacks_executed' => $executed,
                'time_ms' => round($elapsed * 1000, 2)
            ];
            error_log("HookManager: Applicati $executed filtri per '$filter_name' in " . round($elapsed * 1000, 2) . "ms");
        }

        return $value;
    }

    /**
     * Rimuove un hook specifico
     *
     * @param string $hook_name Nome del hook
     * @param callable $callback Callback da rimuovere (null per rimuovere tutti)
     * @return bool Success
     */
    public static function removeAction($hook_name, $callback = null) {
        if (!isset(self::$hooks[$hook_name])) {
            return false;
        }

        if ($callback === null) {
            // Rimuovi tutti i callback per questo hook
            unset(self::$hooks[$hook_name]);
            return true;
        }

        // Rimuovi solo il callback specifico
        foreach (self::$hooks[$hook_name] as $key => $hook) {
            if ($hook['callback'] === $callback) {
                unset(self::$hooks[$hook_name][$key]);
                // Re-index array
                self::$hooks[$hook_name] = array_values(self::$hooks[$hook_name]);
                return true;
            }
        }

        return false;
    }

    /**
     * Rimuove un filtro specifico
     *
     * @param string $filter_name Nome del filtro
     * @param callable $callback Callback da rimuovere (null per rimuovere tutti)
     * @return bool Success
     */
    public static function removeFilter($filter_name, $callback = null) {
        if (!isset(self::$filters[$filter_name])) {
            return false;
        }

        if ($callback === null) {
            unset(self::$filters[$filter_name]);
            return true;
        }

        foreach (self::$filters[$filter_name] as $key => $filter) {
            if ($filter['callback'] === $callback) {
                unset(self::$filters[$filter_name][$key]);
                self::$filters[$filter_name] = array_values(self::$filters[$filter_name]);
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se un hook ha callback registrati
     *
     * @param string $hook_name Nome del hook
     * @return bool True se ha callback
     */
    public static function hasAction($hook_name) {
        return isset(self::$hooks[$hook_name]) && !empty(self::$hooks[$hook_name]);
    }

    /**
     * Verifica se un filtro ha callback registrati
     *
     * @param string $filter_name Nome del filtro
     * @return bool True se ha callback
     */
    public static function hasFilter($filter_name) {
        return isset(self::$filters[$filter_name]) && !empty(self::$filters[$filter_name]);
    }

    /**
     * Ottiene tutti gli hooks registrati
     *
     * @return array Lista hooks
     */
    public static function getRegisteredActions() {
        return array_keys(self::$hooks);
    }

    /**
     * Ottiene tutti i filtri registrati
     *
     * @return array Lista filtri
     */
    public static function getRegisteredFilters() {
        return array_keys(self::$filters);
    }

    /**
     * Abilita/disabilita debug mode
     *
     * @param bool $enabled
     */
    public static function setDebugMode($enabled) {
        self::$debug_mode = $enabled;
    }

    /**
     * Ottiene il log di esecuzione
     *
     * @return array Execution log
     */
    public static function getExecutionLog() {
        return self::$execution_log;
    }

    /**
     * Pulisce il log di esecuzione
     */
    public static function clearExecutionLog() {
        self::$execution_log = [];
    }

    /**
     * Ottiene statistiche sugli hooks
     *
     * @return array Stats
     */
    public static function getStats() {
        $total_actions = count(self::$hooks);
        $total_filters = count(self::$filters);
        $total_action_callbacks = 0;
        $total_filter_callbacks = 0;

        foreach (self::$hooks as $callbacks) {
            $total_action_callbacks += count($callbacks);
        }

        foreach (self::$filters as $callbacks) {
            $total_filter_callbacks += count($callbacks);
        }

        return [
            'total_actions' => $total_actions,
            'total_filters' => $total_filters,
            'total_action_callbacks' => $total_action_callbacks,
            'total_filter_callbacks' => $total_filter_callbacks,
            'total_hooks' => $total_actions + $total_filters,
            'total_callbacks' => $total_action_callbacks + $total_filter_callbacks
        ];
    }

    /**
     * Reset completo (per testing)
     */
    public static function reset() {
        self::$hooks = [];
        self::$filters = [];
        self::$execution_log = [];
    }
}
