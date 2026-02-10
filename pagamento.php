<?php
/**
 * pagamento.php — Public payment page for online quota payments.
 * URL: /pagamento.php?token=<PAYMENT_TOKEN>[&result=success|cancel]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/PaymentService.php';
require_once __DIR__ . '/includes/email_helpers.php';

// --- Validate token ---
$token = $_GET['token'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
    http_response_code(400);
    die('Link non valido.');
}

// Ensure payment columns exist
try {
    if (!columnExists($pdo, 'quote', 'payment_token')) {
        http_response_code(500);
        die('Configurazione non completata.');
    }
} catch (PDOException $e) {
    http_response_code(500);
    die('Errore di sistema.');
}

// Load quota by token
$stmt = $pdo->prepare("SELECT q.*, s.nome AS socio_nome, s.cognome AS socio_cognome, s.email AS socio_email, a.nome AS assoc_nome, a.logo_url AS assoc_logo FROM quote q JOIN soci s ON s.id = q.socio_id JOIN associazioni a ON a.id = q.associazione_id WHERE q.payment_token = ? LIMIT 1");
$stmt->execute([$token]);
$quota = $stmt->fetch();

if (!$quota) {
    http_response_code(404);
    die('Link di pagamento non trovato o non valido.');
}

// Check token expiry
if (!empty($quota['payment_token_expires']) && strtotime($quota['payment_token_expires']) < time()) {
    $tokenExpired = true;
} else {
    $tokenExpired = false;
}

// Check if already paid
$alreadyPaid = !empty($quota['data_pagamento']);

// Load payment gateway config
$pgConfig = loadPaymentGatewayConfig($pdo, $quota['associazione_id']);
$paymentSvc = new PaymentService($pdo, $quota['associazione_id']);
$stripeEnabled = $paymentSvc->isStripeEnabled();
$paypalEnabled = $paymentSvc->isPayPalEnabled();

// Handle return from gateway
$result = $_GET['result'] ?? '';

// Handle PayPal capture on return (PayPal redirects back with token param)
$paypalOrderId = $_GET['paypal_order_id'] ?? '';

$pageError = '';
$pageSuccess = '';

if ($result === 'success') {
    $pageSuccess = 'Pagamento completato con successo! Riceverai una conferma via email.';
} elseif ($result === 'cancel') {
    $pageError = 'Pagamento annullato. Puoi riprovare quando vuoi.';
}

// Handle payment initiation via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenExpired && !$alreadyPaid) {
    $gateway = $_POST['gateway'] ?? '';
    $baseUrl = rtrim(getBaseUrl(), '/');
    $returnUrl = $baseUrl . '/pagamento.php?token=' . urlencode($token) . '&result=success';
    $cancelUrl = $baseUrl . '/pagamento.php?token=' . urlencode($token) . '&result=cancel';

    if ($gateway === 'stripe' && $stripeEnabled) {
        $checkoutUrl = $paymentSvc->createStripeCheckout($quota['id'], $returnUrl, $cancelUrl);
        if ($checkoutUrl) {
            header('Location: ' . $checkoutUrl);
            exit;
        }
        $pageError = 'Errore nella creazione del pagamento Stripe. Riprova.';
    } elseif ($gateway === 'paypal' && $paypalEnabled) {
        $approvalUrl = $paymentSvc->createPayPalOrder($quota['id'], $returnUrl, $cancelUrl);
        if ($approvalUrl) {
            header('Location: ' . $approvalUrl);
            exit;
        }
        $pageError = 'Errore nella creazione del pagamento PayPal. Riprova.';
    }
}

$logoUrl = !empty($quota['assoc_logo']) ? $quota['assoc_logo'] : '';
$assocNome = htmlspecialchars($quota['assoc_nome'] ?? 'Associazione', ENT_QUOTES, 'UTF-8');
$socioNome = htmlspecialchars($quota['socio_nome'] . ' ' . $quota['socio_cognome'], ENT_QUOTES, 'UTF-8');
$importoFmt = number_format((float) $quota['importo'], 2, ',', '.');
$currency = strtoupper($pgConfig['valuta'] ?? 'EUR');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento - <?php echo $assocNome; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css">
    <style>
        :root { --brand: #FF7B11; --brand-dark: #e06a00; }
        body { background: #f5f6fa; min-height: 100vh; }
        .brand-card { max-width: 540px; margin: 2rem auto; border: none; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
        .brand-header { background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%); color: #fff; border-radius: 16px 16px 0 0; padding: 2rem; text-align: center; }
        .brand-header img { max-height: 64px; margin-bottom: 0.75rem; border-radius: 8px; background: #fff; padding: 4px; }
        .brand-header h1 { font-size: 1.5rem; margin: 0; font-weight: 600; }
        .brand-header p { margin: 0.5rem 0 0; opacity: 0.9; font-size: 0.95rem; }
        .btn-brand { background: var(--brand); border-color: var(--brand); color: #fff; font-size: 1.1rem; padding: 0.75rem 1.5rem; }
        .btn-brand:hover, .btn-brand:focus { background: var(--brand-dark); border-color: var(--brand-dark); color: #fff; }
        .btn-stripe { background: #635bff; border-color: #635bff; color: #fff; }
        .btn-stripe:hover { background: #4b45c6; border-color: #4b45c6; color: #fff; }
        .btn-paypal { background: #0070ba; border-color: #0070ba; color: #fff; }
        .btn-paypal:hover { background: #005ea6; border-color: #005ea6; color: #fff; }
        .payment-detail { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .payment-detail:last-child { border-bottom: none; }
        .payment-amount { font-size: 2rem; font-weight: 700; color: var(--brand); text-align: center; margin: 1.5rem 0; }
        .brand-footer { text-align: center; padding: 1rem; color: #999; font-size: 0.85rem; }
    </style>
</head>
<body>

<div class="container py-4">
    <div class="card brand-card">
        <div class="brand-header">
            <?php if ($logoUrl): ?>
                <img src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo">
            <?php endif; ?>
            <h1><?php echo $assocNome; ?></h1>
            <p>Pagamento Quota</p>
        </div>

        <div class="card-body p-4">
            <?php if ($pageSuccess): ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                    <h4 class="mt-3"><?php echo htmlspecialchars($pageSuccess); ?></h4>
                </div>
            <?php elseif ($tokenExpired): ?>
                <div class="text-center py-4">
                    <i class="bi bi-clock-history text-warning" style="font-size:3rem;"></i>
                    <h4 class="mt-3">Link scaduto</h4>
                    <p class="text-muted">Questo link di pagamento non è più valido. Contatta l'associazione per un nuovo link.</p>
                </div>
            <?php elseif ($alreadyPaid): ?>
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                    <h4 class="mt-3">Pagamento già effettuato</h4>
                    <p class="text-muted">Questa quota risulta già pagata.</p>
                </div>
            <?php else: ?>
                <?php if ($pageError): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($pageError); ?></div>
                <?php endif; ?>

                <div class="payment-details mb-4">
                    <div class="payment-detail"><span class="text-muted">Socio</span><strong><?php echo $socioNome; ?></strong></div>
                    <div class="payment-detail"><span class="text-muted">Tipo</span><span><?php echo htmlspecialchars($quota['tipo']); ?></span></div>
                    <div class="payment-detail"><span class="text-muted">Anno</span><span><?php echo (int) $quota['anno']; ?></span></div>
                </div>

                <div class="payment-amount"><?php echo $currency; ?> <?php echo $importoFmt; ?></div>

                <div class="d-grid gap-3">
                    <?php if ($stripeEnabled): ?>
                    <form method="POST">
                        <input type="hidden" name="gateway" value="stripe">
                        <button type="submit" class="btn btn-stripe w-100 btn-lg"><i class="bi bi-credit-card me-2"></i>Paga con Carta (Stripe)</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($paypalEnabled): ?>
                    <form method="POST">
                        <input type="hidden" name="gateway" value="paypal">
                        <button type="submit" class="btn btn-paypal w-100 btn-lg"><i class="bi bi-paypal me-2"></i>Paga con PayPal</button>
                    </form>
                    <?php endif; ?>

                    <?php if (!$stripeEnabled && !$paypalEnabled): ?>
                    <div class="alert alert-warning text-center">
                        <i class="bi bi-exclamation-triangle me-1"></i>Nessun metodo di pagamento online configurato. Contatta l'associazione.
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="brand-footer">
            &copy; <?php echo date('Y'); ?> <?php echo $assocNome; ?>
        </div>
    </div>
</div>

</body>
</html>
