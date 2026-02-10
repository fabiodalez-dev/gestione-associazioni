<?php
/**
 * Stripe Webhook Endpoint
 * URL: /api/webhooks/stripe.php
 *
 * Receives Stripe events (checkout.session.completed).
 * Verifies signature, extracts associazione_id from metadata, delegates to PaymentService.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/PaymentService.php';

// Stripe sends JSON in the request body
$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (empty($payload) || empty($sigHeader)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload or signature']);
    exit;
}

// Decode payload to extract associazione_id from metadata
$eventData = json_decode($payload, true);
if (!is_array($eventData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extract associazione_id from the event's session metadata
$associazioneId = $eventData['data']['object']['metadata']['associazione_id'] ?? null;

if (!$associazioneId) {
    // For non-checkout events we might not have metadata — acknowledge and skip
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'no associazione_id in metadata']);
    exit;
}

// Validate UUID format
if (!preg_match('/^[a-f0-9\-]{36}$/i', $associazioneId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid associazione_id format']);
    exit;
}

try {
    $paymentSvc = new PaymentService($pdo, $associazioneId);
    $result = $paymentSvc->handleStripeWebhook($payload, $sigHeader);

    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Webhook processing failed']);
    }
} catch (\Throwable $e) {
    error_log('Stripe webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
