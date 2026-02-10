<?php
// api/email_queue_process.php — AJAX endpoint to process email queue
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailService.php';

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    echo json_encode(['success' => false, 'message' => 'Non autorizzato.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metodo non valido.']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF non valido.']);
    exit;
}

$emailService = new EmailService($pdo, $_SESSION['associazione_id']);
$result = $emailService->processQueue(50);

echo json_encode([
    'success' => true,
    'sent' => $result['sent'],
    'failed' => $result['failed'],
    'remaining' => $result['remaining'],
]);
