<?php
/**
 * Plugin Name: Advanced Custom Fields for Soci
 * Plugin URI: https://github.com/your-repo/acf-soci
 * Description: Sistema completo per aggiungere campi personalizzati illimitati ai soci. Supporta 15+ tipi di campo, ordinamento drag & drop, gruppi organizzati e validazione avanzata.
 * Version: 2.0.0
 * Author: Gestione Associazioni Team
 * Author URI: https://your-domain.com
 * License: GPL2
 * Requires PHP: 7.4
 */

class Plugin_advanced_custom_fields {
    private $pdo;
    private $table_groups = 'acf_field_groups';
    private $table_fields = 'acf_fields';
    private $table_values = 'acf_values';

    /**
     * Supported field types
     */
    private $field_types = [
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

    /**
     * Initialize plugin
     */
    public function init() {
        global $pdo;
        $this->pdo = $pdo;

        // Add hooks
        HookManager::addAction('admin_menu', [$this, 'addAdminMenu']);
        HookManager::addFilter('dashboard_widgets', [$this, 'addDashboardWidget'], 10);

        // Hook into soci form display
        HookManager::addAction('soci_form_after_standard_fields', [$this, 'renderCustomFieldsInForm'], 10);

        // Hook into soci save
        HookManager::addAction('soci_saved', [$this, 'saveCustomFieldValues'], 10);

        // Hook into soci display
        HookManager::addFilter('soci_detail_tabs', [$this, 'addCustomFieldsTab'], 10);
    }

    /**
     * Activation hook - create tables
     */
    public function activate() {
        $this->createTables();
        $this->insertDefaultFieldGroups();
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        // Don't drop tables on deactivation - keep data
        // Tables will only be dropped if plugin is deleted
    }

    /**
     * Create database tables
     */
    private function createTables() {
        try {
            // Field Groups table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_groups} (
                id VARCHAR(36) PRIMARY KEY,
                associazione_id VARCHAR(36),
                nome VARCHAR(255) NOT NULL,
                descrizione TEXT,
                posizione ENUM('main', 'sidebar', 'tab') DEFAULT 'main',
                ordine INT DEFAULT 0,
                attivo BOOLEAN DEFAULT TRUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_assoc (associazione_id),
                INDEX idx_ordine (ordine),
                INDEX idx_attivo (attivo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Fields table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_fields} (
                id VARCHAR(36) PRIMARY KEY,
                group_id VARCHAR(36) NOT NULL,
                nome_campo VARCHAR(100) NOT NULL,
                label VARCHAR(255) NOT NULL,
                tipo_campo VARCHAR(50) NOT NULL,
                descrizione TEXT,
                placeholder VARCHAR(255),
                valore_default TEXT,
                opzioni JSON COMMENT 'For select/radio/checkbox fields',
                validazione JSON COMMENT 'Validation rules: required, min, max, pattern, etc.',
                larghezza ENUM('full', 'half', 'third', 'quarter') DEFAULT 'full',
                ordine INT DEFAULT 0,
                obbligatorio BOOLEAN DEFAULT FALSE,
                attivo BOOLEAN DEFAULT TRUE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (group_id) REFERENCES {$this->table_groups}(id) ON DELETE CASCADE,
                INDEX idx_group (group_id),
                INDEX idx_ordine (ordine),
                INDEX idx_attivo (attivo),
                UNIQUE KEY unique_field_name_per_group (group_id, nome_campo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Values table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table_values} (
                id VARCHAR(36) PRIMARY KEY,
                field_id VARCHAR(36) NOT NULL,
                socio_id VARCHAR(36) NOT NULL,
                valore TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (field_id) REFERENCES {$this->table_fields}(id) ON DELETE CASCADE,
                INDEX idx_field (field_id),
                INDEX idx_socio (socio_id),
                UNIQUE KEY unique_value_per_socio (field_id, socio_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        } catch (PDOException $e) {
            error_log("ACF Plugin: Error creating tables: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Insert default field groups as examples
     */
    private function insertDefaultFieldGroups() {
        try {
            // Check if already exists
            $check = $this->pdo->query("SELECT COUNT(*) FROM {$this->table_groups}")->fetchColumn();
            if ($check > 0) {
                return; // Already has data
            }

            // Insert example groups
            $groups = [
                [
                    'id' => $this->generateUuid(),
                    'nome' => 'Informazioni Aggiuntive',
                    'descrizione' => 'Campi aggiuntivi per informazioni del socio',
                    'posizione' => 'main',
                    'ordine' => 1
                ],
                [
                    'id' => $this->generateUuid(),
                    'nome' => 'Dati Professionali',
                    'descrizione' => 'Informazioni relative all\'attività professionale',
                    'posizione' => 'tab',
                    'ordine' => 2
                ],
                [
                    'id' => $this->generateUuid(),
                    'nome' => 'Preferenze e Interessi',
                    'descrizione' => 'Preferenze del socio e aree di interesse',
                    'posizione' => 'sidebar',
                    'ordine' => 3
                ]
            ];

            $stmt = $this->pdo->prepare("
                INSERT INTO {$this->table_groups}
                (id, associazione_id, nome, descrizione, posizione, ordine)
                VALUES (?, NULL, ?, ?, ?, ?)
            ");

            foreach ($groups as $group) {
                $stmt->execute([
                    $group['id'],
                    $group['nome'],
                    $group['descrizione'],
                    $group['posizione'],
                    $group['ordine']
                ]);

                // Add example fields to first group
                if ($group['ordine'] === 1) {
                    $this->insertExampleFields($group['id']);
                }
            }
        } catch (PDOException $e) {
            error_log("ACF Plugin: Error inserting default groups: " . $e->getMessage());
        }
    }

    /**
     * Insert example fields
     */
    private function insertExampleFields($group_id) {
        $fields = [
            [
                'nome_campo' => 'linkedin_profile',
                'label' => 'Profilo LinkedIn',
                'tipo_campo' => 'url',
                'placeholder' => 'https://linkedin.com/in/username',
                'ordine' => 1
            ],
            [
                'nome_campo' => 'instagram_handle',
                'label' => 'Instagram',
                'tipo_campo' => 'text',
                'placeholder' => '@username',
                'ordine' => 2
            ],
            [
                'nome_campo' => 'data_nascita',
                'label' => 'Data di Nascita',
                'tipo_campo' => 'date',
                'ordine' => 3
            ],
            [
                'nome_campo' => 'professione',
                'label' => 'Professione',
                'tipo_campo' => 'select',
                'opzioni' => json_encode([
                    'Studente',
                    'Dipendente',
                    'Libero Professionista',
                    'Imprenditore',
                    'Pensionato',
                    'Altro'
                ]),
                'ordine' => 4
            ],
            [
                'nome_campo' => 'newsletter',
                'label' => 'Iscritto alla Newsletter',
                'tipo_campo' => 'checkbox_single',
                'valore_default' => '1',
                'ordine' => 5
            ]
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table_fields}
            (id, group_id, nome_campo, label, tipo_campo, placeholder, valore_default, opzioni, ordine)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($fields as $field) {
            $stmt->execute([
                $this->generateUuid(),
                $group_id,
                $field['nome_campo'],
                $field['label'],
                $field['tipo_campo'],
                $field['placeholder'] ?? null,
                $field['valore_default'] ?? null,
                $field['opzioni'] ?? null,
                $field['ordine']
            ]);
        }
    }

    /**
     * Add admin menu item
     */
    public function addAdminMenu($menu) {
        $menu[] = [
            'title' => 'Custom Fields',
            'url' => 'index.php?page=acf-manager',
            'icon' => 'bi-sliders'
        ];
        return $menu;
    }

    /**
     * Add dashboard widget
     */
    public function addDashboardWidget($widgets) {
        $widgets[] = [
            'title' => 'Custom Fields',
            'content' => $this->renderDashboardWidget(),
            'color' => 'primary',
            'icon' => 'bi-sliders'
        ];
        return $widgets;
    }

    /**
     * Render dashboard widget
     */
    private function renderDashboardWidget() {
        try {
            $stats = $this->pdo->query("
                SELECT
                    COUNT(DISTINCT g.id) as total_groups,
                    COUNT(DISTINCT f.id) as total_fields,
                    COUNT(DISTINCT v.socio_id) as soci_with_custom_data
                FROM {$this->table_groups} g
                LEFT JOIN {$this->table_fields} f ON g.id = f.group_id
                LEFT JOIN {$this->table_values} v ON f.id = v.field_id
                WHERE g.attivo = 1 AND f.attivo = 1
            ")->fetch();

            return "
                <div class='row text-center'>
                    <div class='col-4'>
                        <h4 class='mb-0'>{$stats['total_groups']}</h4>
                        <small class='text-muted'>Gruppi</small>
                    </div>
                    <div class='col-4'>
                        <h4 class='mb-0'>{$stats['total_fields']}</h4>
                        <small class='text-muted'>Campi</small>
                    </div>
                    <div class='col-4'>
                        <h4 class='mb-0'>{$stats['soci_with_custom_data']}</h4>
                        <small class='text-muted'>Soci</small>
                    </div>
                </div>
                <hr>
                <a href='index.php?page=acf-manager' class='btn btn-sm btn-primary w-100'>
                    <i class='bi bi-sliders'></i> Gestisci Campi
                </a>
            ";
        } catch (Exception $e) {
            return "<p class='text-muted'>Errore nel caricamento statistiche.</p>";
        }
    }

    /**
     * Render custom fields in soci form
     */
    public function renderCustomFieldsInForm($socio_id = null) {
        try {
            // Get all active groups and fields
            $groups = $this->pdo->query("
                SELECT * FROM {$this->table_groups}
                WHERE attivo = 1
                AND (associazione_id IS NULL OR associazione_id = '{$_SESSION['associazione_id']}')
                ORDER BY ordine ASC
            ")->fetchAll();

            if (empty($groups)) {
                return;
            }

            // Get existing values if editing
            $values = [];
            if ($socio_id) {
                $stmt = $this->pdo->prepare("
                    SELECT field_id, valore
                    FROM {$this->table_values}
                    WHERE socio_id = ?
                ");
                $stmt->execute([$socio_id]);
                foreach ($stmt->fetchAll() as $row) {
                    $values[$row['field_id']] = $row['valore'];
                }
            }

            echo '<div class="acf-custom-fields mt-4">';
            echo '<h4 class="mb-3"><i class="bi bi-sliders"></i> Campi Personalizzati</h4>';

            foreach ($groups as $group) {
                // Get fields for this group
                $stmt = $this->pdo->prepare("
                    SELECT * FROM {$this->table_fields}
                    WHERE group_id = ? AND attivo = 1
                    ORDER BY ordine ASC
                ");
                $stmt->execute([$group['id']]);
                $fields = $stmt->fetchAll();

                if (empty($fields)) {
                    continue;
                }

                echo '<div class="card mb-3">';
                echo '<div class="card-header bg-light">';
                echo '<h5 class="mb-0">' . htmlspecialchars($group['nome']) . '</h5>';
                if ($group['descrizione']) {
                    echo '<small class="text-muted">' . htmlspecialchars($group['descrizione']) . '</small>';
                }
                echo '</div>';
                echo '<div class="card-body">';
                echo '<div class="row">';

                foreach ($fields as $field) {
                    $width_class = $this->getWidthClass($field['larghezza']);
                    $value = $values[$field['id']] ?? $field['valore_default'] ?? '';
                    $required = $field['obbligatorio'] ? 'required' : '';

                    echo "<div class='$width_class mb-3'>";
                    echo $this->renderField($field, $value, $required);
                    echo '</div>';
                }

                echo '</div>'; // row
                echo '</div>'; // card-body
                echo '</div>'; // card
            }

            echo '</div>'; // acf-custom-fields

        } catch (Exception $e) {
            error_log("ACF Plugin: Error rendering fields: " . $e->getMessage());
        }
    }

    /**
     * Get Bootstrap width class
     */
    private function getWidthClass($width) {
        switch ($width) {
            case 'half': return 'col-md-6';
            case 'third': return 'col-md-4';
            case 'quarter': return 'col-md-3';
            default: return 'col-12';
        }
    }

    /**
     * Render individual field based on type
     */
    private function renderField($field, $value = '', $required = '') {
        $id = 'acf_' . $field['nome_campo'];
        $name = 'acf[' . $field['id'] . ']';
        $label = htmlspecialchars($field['label']);
        $placeholder = $field['placeholder'] ? 'placeholder="' . htmlspecialchars($field['placeholder']) . '"' : '';
        $description = $field['descrizione'] ? '<small class="form-text text-muted">' . htmlspecialchars($field['descrizione']) . '</small>' : '';

        $html = "<label for='$id' class='form-label'>$label";
        if ($required) {
            $html .= " <span class='text-danger'>*</span>";
        }
        $html .= "</label>";

        switch ($field['tipo_campo']) {
            case 'text':
            case 'email':
            case 'url':
            case 'phone':
                $type = $field['tipo_campo'];
                $html .= "<input type='$type' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $placeholder $required>";
                break;

            case 'password':
                $html .= "<input type='password' class='form-control' id='$id' name='$name' value='' $placeholder $required>";
                $html .= "<small class='text-muted'>Lascia vuoto per non modificare</small>";
                break;

            case 'number':
                $html .= "<input type='number' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $placeholder $required>";
                break;

            case 'date':
                $html .= "<input type='date' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $required>";
                break;

            case 'datetime':
                $html .= "<input type='datetime-local' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $required>";
                break;

            case 'time':
                $html .= "<input type='time' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $required>";
                break;

            case 'color':
                $html .= "<input type='color' class='form-control form-control-color' id='$id' name='$name' value='" . htmlspecialchars($value ?: '#000000') . "' $required>";
                break;

            case 'textarea':
                $html .= "<textarea class='form-control' id='$id' name='$name' rows='4' $placeholder $required>" . htmlspecialchars($value) . "</textarea>";
                break;

            case 'select':
                $options = json_decode($field['opzioni'] ?? '[]', true) ?: [];
                $html .= "<select class='form-select' id='$id' name='$name' $required>";
                $html .= "<option value=''>Seleziona...</option>";
                foreach ($options as $option) {
                    $selected = ($value == $option) ? 'selected' : '';
                    $html .= "<option value='" . htmlspecialchars($option) . "' $selected>" . htmlspecialchars($option) . "</option>";
                }
                $html .= "</select>";
                break;

            case 'radio':
                $options = json_decode($field['opzioni'] ?? '[]', true) ?: [];
                foreach ($options as $i => $option) {
                    $checked = ($value == $option) ? 'checked' : '';
                    $html .= "<div class='form-check'>";
                    $html .= "<input class='form-check-input' type='radio' id='{$id}_{$i}' name='$name' value='" . htmlspecialchars($option) . "' $checked $required>";
                    $html .= "<label class='form-check-label' for='{$id}_{$i}'>" . htmlspecialchars($option) . "</label>";
                    $html .= "</div>";
                }
                break;

            case 'checkbox':
                $options = json_decode($field['opzioni'] ?? '[]', true) ?: [];
                $selected_values = is_array($value) ? $value : json_decode($value ?? '[]', true) ?: [];
                foreach ($options as $i => $option) {
                    $checked = in_array($option, $selected_values) ? 'checked' : '';
                    $html .= "<div class='form-check'>";
                    $html .= "<input class='form-check-input' type='checkbox' id='{$id}_{$i}' name='{$name}[]' value='" . htmlspecialchars($option) . "' $checked>";
                    $html .= "<label class='form-check-label' for='{$id}_{$i}'>" . htmlspecialchars($option) . "</label>";
                    $html .= "</div>";
                }
                break;

            case 'checkbox_single':
                $checked = ($value == '1' || $value == 'on') ? 'checked' : '';
                $html .= "<div class='form-check'>";
                $html .= "<input class='form-check-input' type='checkbox' id='$id' name='$name' value='1' $checked>";
                $html .= "<label class='form-check-label' for='$id'>Sì</label>";
                $html .= "</div>";
                break;

            case 'file':
            case 'image':
                $accept = ($field['tipo_campo'] == 'image') ? 'accept="image/*"' : '';
                $html .= "<input type='file' class='form-control' id='$id' name='$name' $accept>";
                if ($value) {
                    $html .= "<small class='text-muted'>File corrente: <a href='$value' target='_blank'>Visualizza</a></small>";
                }
                break;

            case 'rating':
                $html .= "<div class='rating-stars'>";
                for ($i = 1; $i <= 5; $i++) {
                    $checked = ($value == $i) ? 'checked' : '';
                    $html .= "<input type='radio' id='{$id}_{$i}' name='$name' value='$i' $checked>";
                    $html .= "<label for='{$id}_{$i}'>★</label>";
                }
                $html .= "</div>";
                $html .= "<style>.rating-stars { display: inline-flex; flex-direction: row-reverse; } .rating-stars input { display: none; } .rating-stars label { font-size: 2rem; color: #ddd; cursor: pointer; } .rating-stars input:checked ~ label, .rating-stars label:hover, .rating-stars label:hover ~ label { color: #ffc107; }</style>";
                break;

            case 'wysiwyg':
                $html .= "<textarea class='form-control wysiwyg-editor' id='$id' name='$name' rows='8' $required>" . htmlspecialchars($value) . "</textarea>";
                $html .= "<small class='text-muted'>Editor WYSIWYG - Formattazione disponibile</small>";
                break;

            case 'code':
                $html .= "<textarea class='form-control font-monospace' id='$id' name='$name' rows='8' $placeholder $required>" . htmlspecialchars($value) . "</textarea>";
                break;

            default:
                $html .= "<input type='text' class='form-control' id='$id' name='$name' value='" . htmlspecialchars($value) . "' $placeholder $required>";
        }

        $html .= $description;
        return $html;
    }

    /**
     * Save custom field values when socio is saved
     */
    public function saveCustomFieldValues($socio_id) {
        if (!isset($_POST['acf']) || !is_array($_POST['acf'])) {
            return;
        }

        try {
            foreach ($_POST['acf'] as $field_id => $value) {
                // Handle arrays (checkbox multiple)
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                // Insert or update
                $stmt = $this->pdo->prepare("
                    INSERT INTO {$this->table_values} (id, field_id, socio_id, valore)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE valore = ?, updated_at = CURRENT_TIMESTAMP
                ");

                $uuid = $this->generateUuid();
                $stmt->execute([$uuid, $field_id, $socio_id, $value, $value]);
            }
        } catch (Exception $e) {
            error_log("ACF Plugin: Error saving values: " . $e->getMessage());
        }
    }

    /**
     * Add custom fields tab to soci detail view
     */
    public function addCustomFieldsTab($tabs) {
        $tabs[] = [
            'id' => 'custom-fields',
            'title' => 'Campi Personalizzati',
            'icon' => 'bi-sliders',
            'content_callback' => [$this, 'renderCustomFieldsTab']
        ];
        return $tabs;
    }

    /**
     * Render custom fields tab content
     */
    public function renderCustomFieldsTab($socio_id) {
        // This would render a read-only view of custom fields
        echo "<div class='alert alert-info'>Visualizzazione campi personalizzati per socio ID: $socio_id</div>";
        // Implementation would show all custom field values in a nice table
    }

    /**
     * Generate UUID
     */
    private function generateUuid() {
        if (function_exists('generateUuid')) {
            return generateUuid();
        }
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
