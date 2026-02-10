<?php
/**
 * PaymentService — Stripe & PayPal integration for quota payments.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Environment;

class PaymentService
{
    private PDO $pdo;
    private string $associazioneId;
    /** @var array<string,mixed>|null */
    private ?array $config;

    public function __construct(PDO $pdo, string $associazioneId)
    {
        $this->pdo = $pdo;
        $this->associazioneId = $associazioneId;
        $this->config = loadPaymentGatewayConfig($pdo, $associazioneId);
    }

    public function isStripeEnabled(): bool
    {
        return $this->config !== null
            && !empty($this->config['stripe_enabled'])
            && !empty($this->config['stripe_secret_key'])
            && !empty($this->config['stripe_publishable_key']);
    }

    public function isPayPalEnabled(): bool
    {
        return $this->config !== null
            && !empty($this->config['paypal_enabled'])
            && !empty($this->config['paypal_client_id'])
            && !empty($this->config['paypal_client_secret']);
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    /**
     * Create a Stripe Checkout session for a quota.
     * @return string|null Checkout URL to redirect user
     */
    public function createStripeCheckout(string $quotaId, string $returnUrl, string $cancelUrl): ?string
    {
        if (!$this->isStripeEnabled()) {
            return null;
        }

        $quota = $this->loadQuota($quotaId);
        if (!$quota) {
            return null;
        }

        $socio = $this->loadSocio($quota['socio_id']);
        $assoc = $this->loadAssociazione();
        $currency = strtolower($this->config['valuta'] ?? 'eur');

        \Stripe\Stripe::setApiKey($this->config['stripe_secret_key']);

        try {
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => ($assoc['nome'] ?? 'Associazione') . ' - ' . ($quota['tipo'] ?? 'Quota'),
                            'description' => "Anno {$quota['anno']}",
                        ],
                        'unit_amount' => (int) round((float) $quota['importo'] * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'quota_id' => $quotaId,
                    'associazione_id' => $this->associazioneId,
                ],
                'customer_email' => $socio['email'] ?? null,
            ]);

            return $session->url;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('PaymentService::createStripeCheckout: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a PayPal order for a quota.
     * @return string|null Approval URL to redirect user
     */
    public function createPayPalOrder(string $quotaId, string $returnUrl, string $cancelUrl): ?string
    {
        if (!$this->isPayPalEnabled()) {
            return null;
        }

        $quota = $this->loadQuota($quotaId);
        if (!$quota) {
            return null;
        }

        $assoc = $this->loadAssociazione();
        $currency = strtoupper($this->config['valuta'] ?? 'EUR');
        $amount = number_format((float) $quota['importo'], 2, '.', '');

        try {
            $environment = ($this->config['paypal_mode'] ?? 'sandbox') === 'live'
                ? Environment::PRODUCTION
                : Environment::SANDBOX;

            $client = PaypalServerSdkClientBuilder::init()
                ->clientCredentialsAuthCredentials(
                    ClientCredentialsAuthCredentialsBuilder::init(
                        $this->config['paypal_client_id'],
                        $this->config['paypal_client_secret']
                    )
                )
                ->environment($environment)
                ->build();

            $orderBody = OrderRequestBuilder::init(CheckoutPaymentIntent::CAPTURE, [
                PurchaseUnitRequestBuilder::init(
                    AmountWithBreakdownBuilder::init($currency, $amount)->build()
                )
                    ->customId($quotaId)
                    ->description(($assoc['nome'] ?? 'Associazione') . ' - ' . ($quota['tipo'] ?? 'Quota') . " Anno {$quota['anno']}")
                    ->build(),
            ])->build();

            $response = $client->getOrdersController()->createOrder(['body' => $orderBody]);
            $result = $response->getResult();
            $orderId = $result->getId();

            // Find approval link
            $links = $result->getLinks() ?? [];
            foreach ($links as $link) {
                if ($link->getRel() === 'approve') {
                    return $link->getHref();
                }
            }

            // Fallback: construct approval URL
            $baseUrl = ($this->config['paypal_mode'] ?? 'sandbox') === 'live'
                ? 'https://www.paypal.com/checkoutnow?token='
                : 'https://www.sandbox.paypal.com/checkoutnow?token=';
            return $baseUrl . urlencode((string) $orderId);
        } catch (\Exception $e) {
            error_log('PaymentService::createPayPalOrder: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Handle Stripe webhook payload.
     * @return bool True if payment was processed
     */
    public function handleStripeWebhook(string $payload, string $sigHeader): bool
    {
        if (!$this->config || empty($this->config['stripe_webhook_secret'])) {
            return false;
        }

        \Stripe\Stripe::setApiKey($this->config['stripe_secret_key']);

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['stripe_webhook_secret']
            );
        } catch (\Exception $e) {
            error_log('PaymentService Stripe webhook signature: ' . $e->getMessage());
            return false;
        }

        if ($event->type !== 'checkout.session.completed') {
            return true; // Acknowledge other events but don't process
        }

        $session = $event->data->object;
        $quotaId = $session->metadata->quota_id ?? null;
        if (!$quotaId) {
            error_log('PaymentService Stripe webhook: missing quota_id in metadata');
            return false;
        }

        $transactionId = $session->payment_intent ?? $session->id;
        $this->onPaymentCompleted($quotaId, 'stripe', (string) $transactionId);
        return true;
    }

    /**
     * Handle PayPal webhook — capture an approved order.
     * @param array<string,string> $headers
     * @return bool True if payment was processed
     */
    public function handlePayPalWebhook(string $payload, array $headers): bool
    {
        if (!$this->isPayPalEnabled()) {
            return false;
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return false;
        }

        $eventType = $data['event_type'] ?? '';
        if ($eventType !== 'CHECKOUT.ORDER.APPROVED') {
            return true; // Acknowledge other events
        }

        $orderId = $data['resource']['id'] ?? null;
        if (!$orderId) {
            return false;
        }

        // Capture the order
        try {
            $environment = ($this->config['paypal_mode'] ?? 'sandbox') === 'live'
                ? Environment::PRODUCTION
                : Environment::SANDBOX;

            $client = PaypalServerSdkClientBuilder::init()
                ->clientCredentialsAuthCredentials(
                    ClientCredentialsAuthCredentialsBuilder::init(
                        $this->config['paypal_client_id'],
                        $this->config['paypal_client_secret']
                    )
                )
                ->environment($environment)
                ->build();

            $captureResponse = $client->getOrdersController()->captureOrder(['id' => $orderId]);
            $result = $captureResponse->getResult();
            $status = $result->getStatus();

            if ($status !== 'COMPLETED') {
                error_log("PaymentService PayPal capture not completed, status: $status");
                return false;
            }

            // Extract quota_id from custom_id
            $purchaseUnits = $result->getPurchaseUnits() ?? [];
            $quotaId = null;
            $captureId = null;
            foreach ($purchaseUnits as $pu) {
                $quotaId = $quotaId ?: $pu->getCustomId();
                $payments = $pu->getPayments();
                if ($payments) {
                    $captures = $payments->getCaptures() ?? [];
                    foreach ($captures as $capture) {
                        $captureId = $captureId ?: $capture->getId();
                    }
                }
            }

            if (!$quotaId) {
                error_log('PaymentService PayPal webhook: missing custom_id (quota_id)');
                return false;
            }

            $this->onPaymentCompleted($quotaId, 'paypal', $captureId ?: $orderId);
            return true;
        } catch (\Exception $e) {
            error_log('PaymentService PayPal capture error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a unique payment token for a quota (public payment link).
     */
    public function generatePaymentToken(string $quotaId, int $expiryHours = 72): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

        $stmt = $this->pdo->prepare("UPDATE quote SET payment_token = ?, payment_token_expires = ? WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$token, $expires, $quotaId, $this->associazioneId]);

        return $token;
    }

    /**
     * After successful payment: update quota, optionally activate socio + create tessera.
     */
    private function onPaymentCompleted(string $quotaId, string $gateway, string $transactionId): void
    {
        $metodo = $gateway; // stripe or paypal

        // Update quota
        $stmt = $this->pdo->prepare(
            "UPDATE quote SET stato = 'Pagata', data_pagamento = CURDATE(), gateway = ?, gateway_transaction_id = ?, metodo_pagamento = ? WHERE id = ? AND associazione_id = ?"
        );
        $stmt->execute([$gateway, $transactionId, $metodo, $quotaId, $this->associazioneId]);

        // Load quota to get socio_id
        $quota = $this->loadQuota($quotaId);
        if (!$quota) {
            return;
        }

        logSocioActivity(
            $this->pdo,
            $this->associazioneId,
            $quota['socio_id'],
            'Pagamento Online',
            "Pagamento quota €{$quota['importo']} anno {$quota['anno']} via {$gateway}, transazione: {$transactionId}"
        );

        // Auto-activation if enabled and socio is 'In Attesa di Pagamento'
        $autoAttivazione = !empty($this->config['auto_attivazione_pagamento']);
        if ($autoAttivazione) {
            $socioStmt = $this->pdo->prepare("SELECT * FROM soci WHERE id = ? AND associazione_id = ? AND stato = 'In Attesa di Pagamento'");
            $socioStmt->execute([$quota['socio_id'], $this->associazioneId]);
            $socio = $socioStmt->fetch();

            if ($socio) {
                // Activate socio
                $this->pdo->prepare("UPDATE soci SET stato = 'Attivo', data_iscrizione = CURDATE() WHERE id = ? AND associazione_id = ?")
                    ->execute([$socio['id'], $this->associazioneId]);

                logSocioActivity($this->pdo, $this->associazioneId, $socio['id'], 'Attivazione automatica', "Socio attivato dopo pagamento online ({$gateway}).");

                // Create tessera
                $this->createTesseraForSocio($socio['id']);

                // Best-effort welcome email
                $this->sendWelcomeEmail($socio);
            }
        }

        // Best-effort: send payment confirmation email
        $this->sendPaymentConfirmationEmail($quotaId);
    }

    /**
     * Create a quota record for a socio.
     * @return string The new quota ID
     */
    public static function createQuotaForSocio(
        PDO $pdo,
        string $associazioneId,
        string $socioId,
        float $importo,
        string $tipo,
        string $metodo,
        ?string $dataPagamento = null
    ): string {
        $quotaId = generateUuid();
        $anno = (int) date('Y');

        // Calculate scadenza from association setting
        $stmtCfg = $pdo->prepare("SELECT tipo_scadenza_default FROM associazioni WHERE id = ? LIMIT 1");
        $stmtCfg->execute([$associazioneId]);
        $tipoScadenza = $stmtCfg->fetchColumn() ?: 'solare';

        $dataScadenza = ($tipoScadenza === 'annuale')
            ? date('Y-m-d', strtotime('+1 year'))
            : $anno . '-12-31';

        $stato = $dataPagamento !== null ? 'Pagata' : 'Da Pagare';

        $stmt = $pdo->prepare(
            "INSERT INTO quote (id, associazione_id, socio_id, anno, importo, data_scadenza, data_pagamento, stato, tipo, metodo_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $quotaId,
            $associazioneId,
            $socioId,
            $anno,
            $importo,
            $dataScadenza,
            $dataPagamento,
            $stato,
            $tipo,
            $metodo,
        ]);

        return $quotaId;
    }

    /**
     * Create tessera for a socio (reuses existing logic from soci.php).
     */
    private function createTesseraForSocio(string $socioId): void
    {
        $anno = (int) date('Y');

        $chk = $this->pdo->prepare("SELECT COUNT(*) FROM tessere WHERE associazione_id = ? AND socio_id = ? AND anno_validita = ?");
        $chk->execute([$this->associazioneId, $socioId, $anno]);
        if ((int) $chk->fetchColumn() > 0) {
            return; // Tessera already exists
        }

        $stmtCfg = $this->pdo->prepare("SELECT tipo_scadenza_default FROM associazioni WHERE id = ? LIMIT 1");
        $stmtCfg->execute([$this->associazioneId]);
        $tipoScadenza = $stmtCfg->fetchColumn() ?: 'solare';

        $stmtCount = $this->pdo->prepare("SELECT MAX(CAST(numero_tessera AS UNSIGNED)) as max_num FROM tessere WHERE associazione_id = ?");
        $stmtCount->execute([$this->associazioneId]);
        $count = (int) ($stmtCount->fetch()['max_num'] ?? 0) + 1;
        $numero = str_pad((string) $count, 4, '0', STR_PAD_LEFT);

        $dataEmissione = date('Y-m-d');
        $dataScadenza = ($tipoScadenza === 'annuale')
            ? date('Y-m-d', strtotime($dataEmissione . ' +1 year'))
            : $anno . '-12-31';

        $tesseraId = generateUuid();
        $this->pdo->prepare(
            "INSERT INTO tessere (id, associazione_id, socio_id, numero_tessera, anno_validita, data_emissione, data_scadenza, stato, tipo_scadenza) VALUES (?, ?, ?, ?, ?, ?, ?, 'Attiva', ?)"
        )->execute([$tesseraId, $this->associazioneId, $socioId, $numero, $anno, $dataEmissione, $dataScadenza, $tipoScadenza]);

        // QR code
        $qrHelperPath = __DIR__ . '/qrcode_helper.php';
        if (file_exists($qrHelperPath)) {
            require_once $qrHelperPath;
            if (function_exists('buildTesseraVerificationUrl')) {
                $qrUrl = buildTesseraVerificationUrl($tesseraId);
                $this->pdo->prepare("UPDATE tessere SET qr_code_url = ? WHERE id = ?")->execute([$qrUrl, $tesseraId]);
            }
        }

        logSocioActivity($this->pdo, $this->associazioneId, $socioId, 'Generazione Tessera', "Tessera {$numero} generata dopo pagamento online.");
    }

    /**
     * Best-effort welcome email after auto-activation.
     * @param array<string,mixed> $socio
     */
    private function sendWelcomeEmail(array $socio): void
    {
        try {
            require_once __DIR__ . '/EmailService.php';
            require_once __DIR__ . '/email_helpers.php';

            $emailSvc = new EmailService($this->pdo, $this->associazioneId);
            $smtpCfg = $emailSvc->loadSmtpConfig();
            if (!$emailSvc->isConfigured() || !$smtpCfg || empty($smtpCfg['auto_benvenuto'])) {
                return;
            }

            $tpl = $emailSvc->getTemplate('benvenuto');
            if (!$tpl || empty($tpl['attivo'])) {
                return;
            }

            $ph = buildPlaceholderValues($this->pdo, $this->associazioneId, $socio['id']);
            $rendered = $emailSvc->renderTemplate('benvenuto', $ph);
            if ($rendered) {
                $emailSvc->queueEmail(
                    $socio['email'],
                    $socio['nome'] . ' ' . $socio['cognome'],
                    $rendered['subject'],
                    $rendered['body'],
                    $socio['id'],
                    generateUuid(),
                    'benvenuto',
                    3
                );
            }
        } catch (\Throwable $e) {
            error_log('PaymentService::sendWelcomeEmail: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort payment confirmation email.
     */
    private function sendPaymentConfirmationEmail(string $quotaId): void
    {
        try {
            require_once __DIR__ . '/EmailService.php';
            require_once __DIR__ . '/email_helpers.php';

            $quota = $this->loadQuota($quotaId);
            if (!$quota) {
                return;
            }

            $emailSvc = new EmailService($this->pdo, $this->associazioneId);
            $smtpCfg = $emailSvc->loadSmtpConfig();
            if (!$emailSvc->isConfigured() || !$smtpCfg || empty($smtpCfg['auto_pagamento_quota'])) {
                return;
            }

            $tpl = $emailSvc->getTemplate('pagamento_quota');
            if (!$tpl || empty($tpl['attivo'])) {
                return;
            }

            $ph = buildPlaceholderValues($this->pdo, $this->associazioneId, $quota['socio_id'], [
                'IMPORTO' => $quota['importo'],
                'ANNO' => $quota['anno'],
                'DATA_PAGAMENTO' => date('d/m/Y'),
            ]);
            $rendered = $emailSvc->renderTemplate('pagamento_quota', $ph);
            if ($rendered) {
                $socioStmt = $this->pdo->prepare('SELECT nome, cognome, email FROM soci WHERE id = ? AND associazione_id = ?');
                $socioStmt->execute([$quota['socio_id'], $this->associazioneId]);
                $socioRow = $socioStmt->fetch();
                if ($socioRow && !empty($socioRow['email'])) {
                    $emailSvc->queueEmail(
                        $socioRow['email'],
                        $socioRow['nome'] . ' ' . $socioRow['cognome'],
                        $rendered['subject'],
                        $rendered['body'],
                        $quota['socio_id'],
                        generateUuid(),
                        'pagamento_quota',
                        3
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('PaymentService::sendPaymentConfirmationEmail: ' . $e->getMessage());
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function loadQuota(string $quotaId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM quote WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$quotaId, $this->associazioneId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function loadSocio(string $socioId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM soci WHERE id = ? AND associazione_id = ?");
        $stmt->execute([$socioId, $this->associazioneId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    private function loadAssociazione(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM associazioni WHERE id = ?");
        $stmt->execute([$this->associazioneId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
