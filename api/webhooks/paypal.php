<?php
/**
 * PayPal Webhook Endpoint
 * URL: /api/webhooks/paypal.php
 *
 * Receives PayPal events (CHECKOUT.ORDER.APPROVED).
 * Looks up associazione_id from the order's custom_id (quota_id), delegates to PaymentService.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/PaymentService.php';

// PayPal sends JSON in the request body
$payload = file_get_contents('php://input');

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payload']);
    exit;
}

$eventData = json_decode($payload, true);
if (!is_array($eventData)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Collect relevant headers for verification
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_PAYPAL_') === 0) {
        // Convert HTTP_PAYPAL_TRANSMISSION_ID → paypal-transmission-id
        $headerName = strtolower(str_replace(['HTTP_', '_'], ['', '-'], $key));
        $headers[$headerName] = $value;
    }
}

// Extract quota_id from the order's custom_id to look up associazione_id
$quotaId = $eventData['resource']['purchase_units'][0]['custom_id'] ?? null;

if (!$quotaId) {
    // For non-order events, acknowledge and skip
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'no custom_id in purchase_units']);
    exit;
}

// Validate UUID format
if (!preg_match('/^[a-f0-9\-]{36}$/i', $quotaId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid quota_id format']);
    exit;
}

// Look up associazione_id from the quota
try {
    $stmt = $pdo->prepare("SELECT associazione_id FROM quote WHERE id = ? LIMIT 1");
    $stmt->execute([$quotaId]);
    $associazioneId = $stmt->fetchColumn();

    if (!$associazioneId) {
        http_response_code(404);
        echo json_encode(['error' => 'Quota not found']);
        exit;
    }

    $paymentSvc = new PaymentService($pdo, $associazioneId);
    $result = $paymentSvc->handlePayPalWebhook($payload, $headers);

    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Webhook processing failed']);
    }
} catch (\Throwable $e) {
    error_log('PayPal webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
