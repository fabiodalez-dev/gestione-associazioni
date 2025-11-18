<?php
/**
 * CSRF Protection Auto-Patcher
 *
 * Questo script aggiunge automaticamente CSRF protection a tutti i file vulnerabili
 *
 * Run: php scripts/add_csrf_protection.php
 */

$files_to_patch = [
    'pages/eventi.php',
    'pages/comunicazioni.php',
    'pages/amministratori.php',
    'pages/categorie-socio.php',
    'pages/config_campi.php',
    'pages/config_gruppi.php',
    'pages/config_tags.php',
    'pages/tipi-socio.php',
    'pages/partecipanti.php',
    'pages/scadenze.php',
    'pages/socio_dettaglio.php',
    'pages/verbali.php'
];

$base_path = __DIR__ . '/../';
$patched_count = 0;
$errors = [];

echo "=== CSRF Protection Auto-Patcher ===\n\n";

foreach ($files_to_patch as $file) {
    $filepath = $base_path . $file;

    if (!file_exists($filepath)) {
        $errors[] = "File not found: $file";
        continue;
    }

    echo "Processing: $file\n";

    $content = file_get_contents($filepath);
    $original_content = $content;
    $changes_made = false;

    // Step 1: Add CSRF validation to POST handler
    // Look for: if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_pattern = '/if\s*\(\s*\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*===\s*[\'"]POST[\'"]\s*\)\s*\{/';

    if (preg_match($post_pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        $match_pos = $matches[0][1];
        $match_str = $matches[0][0];

        // Check if CSRF validation already exists nearby
        $check_snippet = substr($content, $match_pos, 500);
        if (strpos($check_snippet, 'validateCSRFToken') === false) {
            $csrf_validation = $match_str . "\n    // CSRF Token Validation\n    if (!validateCSRFToken(\$_POST['csrf_token'] ?? '')) {\n        \$message = 'Errore di sicurezza. Riprova.';\n        \$messageType = 'danger';\n    } else {";

            $content = substr_replace($content, $csrf_validation, $match_pos, strlen($match_str));

            // Find the closing bracket of the POST block and add closing else bracket
            // This is tricky, so we'll add a comment marker
            $content = preg_replace(
                '/(\}\s*\/\/\s*End POST handler|\}\s*\/\/\s*Fine gestione POST|\}\s*$)/',
                "    } // Close CSRF validation else\n$1",
                $content,
                1
            );

            $changes_made = true;
            echo "  ✓ Added CSRF validation to POST handler\n";
        }
    }

    // Step 2: Add CSRF tokens to all POST forms
    // Look for: <form method="POST" or <form method='POST'
    $form_count = 0;
    $content = preg_replace_callback(
        '/<form\s+method=["\']POST["\']([^>]*)>/i',
        function($matches) use (&$form_count) {
            $form_count++;
            // Check if CSRF token already exists
            if (strpos($matches[0], 'csrf_token') !== false) {
                return $matches[0]; // Already has CSRF
            }
            // Add CSRF token after form opening tag
            return $matches[0] . "\n        <input type=\"hidden\" name=\"csrf_token\" value=\"<?php echo generateCSRFToken(); ?>\">";
        },
        $content
    );

    if ($form_count > 0) {
        $changes_made = true;
        echo "  ✓ Added CSRF tokens to $form_count form(s)\n";
    }

    // Step 3: Save the modified file
    if ($changes_made) {
        // Create backup
        $backup_file = $filepath . '.backup_' . date('YmdHis');
        copy($filepath, $backup_file);

        file_put_contents($filepath, $content);
        $patched_count++;
        echo "  ✅ File patched successfully (backup: " . basename($backup_file) . ")\n\n";
    } else {
        echo "  ℹ No changes needed (already protected or no POST forms)\n\n";
    }
}

echo "\n=== Summary ===\n";
echo "Files patched: $patched_count\n";
echo "Errors: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\n=== Errors ===\n";
    foreach ($errors as $error) {
        echo "  ❌ $error\n";
    }
}

echo "\n✅ CSRF Protection Auto-Patcher completed!\n";
echo "\nNOTE: Please review the changes and test all forms before committing.\n";
echo "Backups have been created with .backup_* extension.\n\n";
