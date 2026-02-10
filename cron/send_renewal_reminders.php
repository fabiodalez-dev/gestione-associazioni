<?php
/**
 * cron/send_renewal_reminders.php — Send renewal reminders with pre-filled renewal links.
 *
 * Finds tessere expiring within `giorni_notifica_scadenza` days for associations
 * with `auto_scadenza_tessera = 1`, generates renewal tokens and queues emails
 * using the `scadenza_tessera` template with {LINK_RINNOVO} placeholder.
 *
 * Anti-duplicate: skips soci who received a `scadenza_tessera` email in the last 7 days.
 *
 * Usage: php cron/send_renewal_reminders.php
 * Cron:  0 9 * * * php /path/to/cron/send_renewal_reminders.php >> /var/log/renewal_reminders.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/email_helpers.php';
require_once __DIR__ . '/../includes/PaymentService.php';

// Ensure schema supports renewal tokens
if (!columnExists($pdo, 'soci', 'rinnovo_token')) {
    echo date('Y-m-d H:i:s') . " Schema not ready: rinnovo_token column missing. Run preiscrizione.php first.\n";
    exit(1);
}

$today = date('Y-m-d');

try {
    // Get all active associations with SMTP configured and auto_scadenza_tessera enabled
    $stmtAssoc = $pdo->query("
        SELECT a.id, a.nome, a.giorni_notifica_scadenza
        FROM associazioni a
        INNER JOIN smtp_settings ss ON ss.associazione_id = a.id
        WHERE a.attiva = 1
          AND ss.auto_scadenza_tessera = 1
    ");
    $associations = $stmtAssoc->fetchAll();

    if (empty($associations)) {
        echo date('Y-m-d H:i:s') . " No associations with auto_scadenza_tessera enabled.\n";
        exit(0);
    }

    $totalQueued = 0;

    foreach ($associations as $assoc) {
        $assocId = $assoc['id'];
        $giorni = (int)($assoc['giorni_notifica_scadenza'] ?: 30);
        $deadlineDate = date('Y-m-d', strtotime("+$giorni days"));

        $service = new EmailService($pdo, $assocId);
        if (!$service->isConfigured()) {
            continue;
        }

        $tpl = $service->getTemplate('scadenza_tessera');
        if ($tpl === null || empty($tpl['attivo'])) {
            continue;
        }

        // Find tessere expiring between today and deadline, for active soci not opted out,
        // not already notified in the last 7 days
        $sql = "SELECT t.id AS tessera_id, t.numero_tessera, t.data_scadenza, t.anno_validita,
                       s.id AS socio_id, s.nome, s.cognome, s.email
                FROM tessere t
                JOIN soci s ON s.id = t.socio_id AND s.associazione_id = t.associazione_id
                WHERE t.associazione_id = ?
                  AND t.stato = 'Attiva'
                  AND t.data_scadenza BETWEEN ? AND ?
                  AND s.stato = 'Attivo'
                  AND s.email IS NOT NULL AND s.email != ''
                  AND (s.email_opt_out = 0 OR s.email_opt_out IS NULL)
                  AND NOT EXISTS (
                      SELECT 1 FROM email_log el
                      WHERE el.socio_id = s.id
                        AND el.template_codice = 'scadenza_tessera'
                        AND el.sent_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$assocId, $today, $deadlineDate]);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            echo date('Y-m-d H:i:s') . " Assoc '{$assoc['nome']}': no expiring tessere to notify.\n";
            continue;
        }

        $batchId = generateUuid();
        $queued = 0;

        // Check if online payment gateway is configured for this association
        $paymentSvc = new PaymentService($pdo, $assocId);
        $hasOnlinePayment = $paymentSvc->isStripeEnabled() || $paymentSvc->isPayPalEnabled();

        foreach ($rows as $row) {
            // Generate renewal token for this socio
            $token = generateRenewalToken($pdo, $assocId, $row['socio_id']);
            $renewalUrl = generateRenewalUrl($assocId, $token);

            // If online payment is enabled, create a "Da Pagare" quota and generate payment link
            $paymentLink = '';
            if ($hasOnlinePayment) {
                $nextYear = (int) date('Y') + 1;
                // Check if an unpaid quota already exists for next year
                $chkQuota = $pdo->prepare("SELECT id FROM quote WHERE socio_id = ? AND associazione_id = ? AND anno = ? AND stato = 'Da Pagare' LIMIT 1");
                $chkQuota->execute([$row['socio_id'], $assocId, $nextYear]);
                $existingQuotaId = $chkQuota->fetchColumn();

                if (!$existingQuotaId) {
                    // Get default importo from tipo_socio or association
                    $stmtImporto = $pdo->prepare("SELECT ts.costo_tessera FROM soci s LEFT JOIN tipi_socio ts ON ts.id = s.tipo_socio_id WHERE s.id = ? AND s.associazione_id = ?");
                    $stmtImporto->execute([$row['socio_id'], $assocId]);
                    $importo = (float) ($stmtImporto->fetchColumn() ?: 0);
                    if ($importo <= 0) {
                        $stmtCostoDefault = $pdo->prepare("SELECT costo_tessera FROM associazioni WHERE id = ?");
                        $stmtCostoDefault->execute([$assocId]);
                        $importo = (float) ($stmtCostoDefault->fetchColumn() ?: 0);
                    }

                    if ($importo > 0) {
                        $existingQuotaId = PaymentService::createQuotaForSocio(
                            $pdo, $assocId, $row['socio_id'], $importo, 'Quota Associativa', 'online'
                        );
                    }
                }

                if ($existingQuotaId) {
                    $paymentToken = $paymentSvc->generatePaymentToken($existingQuotaId, 720); // 30 days
                    $paymentLink = rtrim(getBaseUrl(), '/') . '/pagamento.php?token=' . urlencode($paymentToken);
                }
            }

            // Build placeholders including LINK_RINNOVO and LINK_PAGAMENTO
            $ph = buildPlaceholderValues($pdo, $assocId, $row['socio_id'], [
                'NUMERO_TESSERA' => $row['numero_tessera'],
                'ANNO_VALIDITA' => (string)$row['anno_validita'],
                'DATA_SCADENZA' => date('d/m/Y', strtotime($row['data_scadenza'])),
                'LINK_RINNOVO' => $renewalUrl,
                'LINK_PAGAMENTO' => $paymentLink,
            ]);

            $rendered = $service->renderTemplate('scadenza_tessera', $ph);
            if ($rendered !== null) {
                $service->queueEmail(
                    $row['email'],
                    $row['nome'] . ' ' . $row['cognome'],
                    $rendered['subject'],
                    $rendered['body'],
                    $row['socio_id'],
                    $batchId,
                    'scadenza_tessera',
                    5
                );
                $queued++;
            }
        }

        $totalQueued += $queued;
        echo date('Y-m-d H:i:s') . " Assoc '{$assoc['nome']}': $queued renewal reminders queued.\n";
    }

    echo date('Y-m-d H:i:s') . " Done. Total renewal reminders queued: $totalQueued\n";
} catch (\Exception $e) {
    error_log('send_renewal_reminders.php error: ' . $e->getMessage());
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
