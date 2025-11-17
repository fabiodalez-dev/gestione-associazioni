<?php
/**
 * Plugin Name: Custom Fields Extended
 * Plugin URI: https://gestione-associazioni.local/plugins/custom-fields-extended
 * Description: Aggiunge nuovi tipi di campi personalizzati: Color Picker, File Upload, Rating, Multi-Select, Map Location
 * Version: 1.0.0
 * Author: Gestione Associazioni Team
 * Author URI: https://gestione-associazioni.local
 * License: GPL2
 * Requires PHP: 7.4
 */

class Plugin_custom_fields_extended {
    /**
     * Nuovi tipi di campo disponibili
     */
    private $new_field_types = [
        'color_picker' => 'Color Picker',
        'file_upload' => 'File Upload',
        'rating' => 'Rating (1-5 stelle)',
        'multi_select' => 'Multi Select',
        'map_location' => 'Map Location (Lat/Lng)',
        'currency' => 'Valuta',
        'phone_intl' => 'Telefono Internazionale',
        'image_upload' => 'Image Upload',
        'wysiwyg' => 'Editor WYSIWYG',
        'code_editor' => 'Code Editor'
    ];

    /**
     * Inizializza il plugin
     */
    public function init() {
        $this->registerHooks();
    }

    /**
     * Registra hooks e filtri
     */
    private function registerHooks() {
        // Aggiungi nuovi tipi di campo
        HookManager::addFilter('custom_field_types', [$this, 'addFieldTypes'], 10);

        // Rendering dei nuovi tipi di campo
        HookManager::addFilter('render_custom_field', [$this, 'renderField'], 10);

        // Validazione dei nuovi tipi
        HookManager::addFilter('validate_custom_field', [$this, 'validateField'], 10);

        // Aggiungi CSS/JS necessari
        HookManager::addAction('admin_head', [$this, 'enqueueAssets']);

        // Hook per form socio
        HookManager::addAction('socio_form_fields', [$this, 'renderCustomFields'], 10);
    }

    /**
     * Aggiungi nuovi tipi di campo
     *
     * @param array $types Tipi esistenti
     * @return array Tipi con aggiunte
     */
    public function addFieldTypes($types) {
        return array_merge($types, $this->new_field_types);
    }

    /**
     * Renderizza campo personalizzato
     *
     * @param string $html HTML default
     * @param array $field Dati campo
     * @param mixed $value Valore corrente
     * @return string HTML campo
     */
    public function renderField($html, $field, $value = null) {
        $field_name = htmlspecialchars($field['nome_campo']);
        $field_id = 'field_' . $field['id'];
        $required = $field['obbligatorio'] ? 'required' : '';

        switch ($field['tipo_campo']) {
            case 'color_picker':
                return sprintf(
                    '<div class="mb-3">
                        <label for="%s" class="form-label">%s %s</label>
                        <input type="color" class="form-control form-control-color" id="%s" name="custom_fields[%s]" value="%s" %s>
                        <small class="text-muted">%s</small>
                    </div>',
                    $field_id,
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $field_id,
                    $field['id'],
                    htmlspecialchars($value ?? '#000000'),
                    $required,
                    htmlspecialchars($field['descrizione'] ?? '')
                );

            case 'rating':
                $stars = '';
                for ($i = 1; $i <= 5; $i++) {
                    $checked = ($value == $i) ? 'checked' : '';
                    $stars .= sprintf(
                        '<input type="radio" class="btn-check" name="custom_fields[%s]" id="%s_star_%d" value="%d" %s %s>
                         <label class="btn btn-outline-warning" for="%s_star_%d">★</label>',
                        $field['id'],
                        $field_id,
                        $i,
                        $i,
                        $checked,
                        ($i == 1 && $required) ? 'required' : '',
                        $field_id,
                        $i
                    );
                }

                return sprintf(
                    '<div class="mb-3">
                        <label class="form-label">%s %s</label>
                        <div class="btn-group" role="group">%s</div>
                        <small class="text-muted d-block">%s</small>
                    </div>',
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $stars,
                    htmlspecialchars($field['descrizione'] ?? '')
                );

            case 'multi_select':
                $options = json_decode($field['opzioni'] ?? '[]', true);
                if (!is_array($options)) $options = explode(',', $field['opzioni'] ?? '');

                $selected_values = is_array($value) ? $value : json_decode($value ?? '[]', true);
                if (!is_array($selected_values)) $selected_values = [];

                $options_html = '';
                foreach ($options as $option) {
                    $option = trim($option);
                    $checked = in_array($option, $selected_values) ? 'checked' : '';
                    $options_html .= sprintf(
                        '<div class="form-check">
                            <input class="form-check-input" type="checkbox" name="custom_fields[%s][]" value="%s" id="%s_%s" %s>
                            <label class="form-check-label" for="%s_%s">%s</label>
                        </div>',
                        $field['id'],
                        htmlspecialchars($option),
                        $field_id,
                        md5($option),
                        $checked,
                        $field_id,
                        md5($option),
                        htmlspecialchars($option)
                    );
                }

                return sprintf(
                    '<div class="mb-3">
                        <label class="form-label">%s %s</label>
                        %s
                        <small class="text-muted">%s</small>
                    </div>',
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $options_html,
                    htmlspecialchars($field['descrizione'] ?? '')
                );

            case 'map_location':
                $coords = json_decode($value ?? '{"lat": 0, "lng": 0}', true);

                return sprintf(
                    '<div class="mb-3">
                        <label class="form-label">%s %s</label>
                        <div class="row">
                            <div class="col-6">
                                <input type="number" step="any" class="form-control" name="custom_fields[%s][lat]" placeholder="Latitudine" value="%s" %s>
                            </div>
                            <div class="col-6">
                                <input type="number" step="any" class="form-control" name="custom_fields[%s][lng]" placeholder="Longitudine" value="%s" %s>
                            </div>
                        </div>
                        <small class="text-muted">%s</small>
                    </div>',
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $field['id'],
                    htmlspecialchars($coords['lat'] ?? ''),
                    $required,
                    $field['id'],
                    htmlspecialchars($coords['lng'] ?? ''),
                    $required,
                    htmlspecialchars($field['descrizione'] ?? 'Inserisci coordinate GPS')
                );

            case 'currency':
                return sprintf(
                    '<div class="mb-3">
                        <label for="%s" class="form-label">%s %s</label>
                        <div class="input-group">
                            <span class="input-group-text">€</span>
                            <input type="number" step="0.01" min="0" class="form-control" id="%s" name="custom_fields[%s]" value="%s" %s>
                        </div>
                        <small class="text-muted">%s</small>
                    </div>',
                    $field_id,
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $field_id,
                    $field['id'],
                    htmlspecialchars($value ?? ''),
                    $required,
                    htmlspecialchars($field['descrizione'] ?? '')
                );

            case 'phone_intl':
                return sprintf(
                    '<div class="mb-3">
                        <label for="%s" class="form-label">%s %s</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control" id="%s" name="custom_fields[%s]" value="%s" placeholder="+39 123 456 7890" pattern="[\+]?[0-9\s\-\(\)]+" %s>
                        </div>
                        <small class="text-muted">%s</small>
                    </div>',
                    $field_id,
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $field_id,
                    $field['id'],
                    htmlspecialchars($value ?? ''),
                    $required,
                    htmlspecialchars($field['descrizione'] ?? 'Formato: +39 123 456 7890')
                );

            case 'wysiwyg':
                // Rich text editor con Quill o TinyMCE
                return sprintf(
                    '<div class="mb-3">
                        <label for="%s" class="form-label">%s %s</label>
                        <div id="%s_editor" style="min-height: 200px; border: 1px solid #ddd; border-radius: 4px;">%s</div>
                        <textarea id="%s" name="custom_fields[%s]" style="display:none;" %s>%s</textarea>
                        <small class="text-muted">%s</small>
                        <script>
                        // Simplified rich text (in production use Quill.js or TinyMCE)
                        document.getElementById("%s_editor").contentEditable = true;
                        document.getElementById("%s_editor").addEventListener("input", function() {
                            document.getElementById("%s").value = this.innerHTML;
                        });
                        </script>
                    </div>',
                    $field_id,
                    $field_name,
                    $field['obbligatorio'] ? '<span class="text-danger">*</span>' : '',
                    $field_id,
                    htmlspecialchars($value ?? ''),
                    $field_id,
                    $field['id'],
                    $required,
                    htmlspecialchars($value ?? ''),
                    htmlspecialchars($field['descrizione'] ?? ''),
                    $field_id,
                    $field_id,
                    $field_id
                );
        }

        // Nessun match, return HTML default
        return $html;
    }

    /**
     * Valida campo personalizzato
     *
     * @param bool $valid Validazione default
     * @param array $field Dati campo
     * @param mixed $value Valore da validare
     * @return bool|string True se valido, stringa errore altrimenti
     */
    public function validateField($valid, $field, $value) {
        // Se già non valido, return
        if ($valid !== true) {
            return $valid;
        }

        switch ($field['tipo_campo']) {
            case 'color_picker':
                if (!preg_match('/^#[0-9A-F]{6}$/i', $value)) {
                    return "Colore non valido. Usa formato esadecimale (#RRGGBB)";
                }
                break;

            case 'rating':
                if ($value < 1 || $value > 5) {
                    return "Rating deve essere tra 1 e 5";
                }
                break;

            case 'map_location':
                $coords = is_array($value) ? $value : json_decode($value, true);
                if (!isset($coords['lat']) || !isset($coords['lng'])) {
                    return "Coordinate GPS incomplete";
                }
                if ($coords['lat'] < -90 || $coords['lat'] > 90) {
                    return "Latitudine deve essere tra -90 e 90";
                }
                if ($coords['lng'] < -180 || $coords['lng'] > 180) {
                    return "Longitudine deve essere tra -180 e 180";
                }
                break;

            case 'currency':
                if ($value < 0) {
                    return "Il valore non può essere negativo";
                }
                break;

            case 'phone_intl':
                if (!preg_match('/^[\+]?[0-9\s\-\(\)]+$/', $value)) {
                    return "Numero di telefono non valido";
                }
                break;
        }

        return true;
    }

    /**
     * Aggiungi CSS/JS necessari
     */
    public function enqueueAssets() {
        ?>
        <style>
            /* Stili per rating stars */
            .btn-check:checked + .btn-outline-warning {
                background-color: #ffc107;
                border-color: #ffc107;
                color: #000;
            }

            /* Stili per color picker */
            .form-control-color {
                height: 45px;
                width: 100%;
            }

            /* Stili per WYSIWYG editor */
            [contentEditable="true"] {
                padding: 10px;
                background: white;
            }
        </style>
        <script>
            console.log('Custom Fields Extended Plugin: Assets loaded');
        </script>
        <?php
    }

    /**
     * Renderizza campi personalizzati nel form socio
     *
     * @param array $socio_data Dati socio
     */
    public function renderCustomFields($socio_data) {
        // Questo hook sarebbe chiamato dal form soci
        // Per ora è un placeholder
        echo '<!-- Custom Fields Extended Plugin Ready -->';
    }

    /**
     * Attivazione plugin
     */
    public function activate() {
        error_log("Custom Fields Extended Plugin: Attivato - Nuovi tipi: " . implode(', ', array_keys($this->new_field_types)));
    }

    /**
     * Disattivazione plugin
     */
    public function deactivate() {
        error_log("Custom Fields Extended Plugin: Disattivato");
        // I campi esistenti continuano a funzionare ma non saranno creabili nuovi
    }

    /**
     * Ottieni lista tipi di campo aggiunti
     *
     * @return array Field types
     */
    public function getFieldTypes() {
        return $this->new_field_types;
    }
}
