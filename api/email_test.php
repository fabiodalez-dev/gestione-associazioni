<?php
// api/email_test.php — AJAX endpoint to send a test SMTP email
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

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Indirizzo email non valido.']);
    exit;
}

$emailService = new EmailService($pdo, $_SESSION['associazione_id']);
$result = $emailService->sendTestEmail($email);

echo json_encode([
    'success' => $result['success'],
    'message' => $result['success'] ? 'Email di test inviata con successo!' : ('Errore: ' . ($result['error'] ?? 'sconosciuto')),
]);
