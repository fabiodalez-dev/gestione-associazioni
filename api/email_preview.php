<?php
// api/email_preview.php — AJAX endpoint to preview a template or fetch log detail
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato.']);
    exit;
}

$associazione_id = $_SESSION['associazione_id'];

// View log detail
if (!empty($_GET['log_id'])) {
    $logId = cleanInput($_GET['log_id']);
    $stmt = $pdo->prepare('SELECT el.*, eq.body_html FROM email_log el LEFT JOIN email_queue eq ON eq.batch_id = el.batch_id AND eq.to_email = el.to_email AND eq.associazione_id = el.associazione_id WHERE el.id = ? AND el.associazione_id = ? LIMIT 1');
    $stmt->execute([$logId, $associazione_id]);
    $log = $stmt->fetch();

    if (!$log) {
        echo json_encode(['success' => false, 'message' => 'Email non trovata.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'to_email' => $log['to_email'],
        'to_name' => $log['to_name'] ?? '',
        'subject' => $log['subject'],
        'template_codice' => $log['template_codice'],
        'status' => $log['status'],
        'error_message' => $log['error_message'],
        'sent_at' => $log['sent_at'],
        'body_html' => $log['body_html'] ?? '',
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Parametri mancanti.']);
