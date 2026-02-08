<?php
// cron/send_reminders.php — Send expiration reminders
// Run daily at 8:00 AM via cron.
// Finds expiring tessere and unpaid quote, queues reminder emails.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/email_helpers.php';

$today = date('Y-m-d');

try {
    // Get all associations with SMTP configured
    $stmtAssoc = $pdo->query("SELECT a.id, a.nome, a.giorni_notifica_scadenza FROM associazioni a INNER JOIN smtp_settings ss ON ss.associazione_id = a.id WHERE a.attiva = 1");
    $associations = $stmtAssoc->fetchAll();

    if (empty($associations)) {
        echo date('Y-m-d H:i:s') . " No associations with SMTP configured.\n";
        exit(0);
    }

    $totalQueued = 0;

    foreach ($associations as $assoc) {
        $assocId = $assoc['id'];
        $giorni = (int)($assoc['giorni_notifica_scadenza'] ?: 30);
        $deadlineDate = date('Y-m-d', strtotime("+$giorni days"));

        $service = new EmailService($pdo, $assocId);
        $smtpCfg = $service->loadSmtpConfig();

        if ($smtpCfg === null || !$service->isConfigured()) {
            continue;
        }

        // --- Tessera expiration reminders ---
        if (!empty($smtpCfg['auto_scadenza_tessera'])) {
            $tpl = $service->getTemplate('scadenza_tessera');
            if ($tpl !== null && !empty($tpl['attivo'])) {
                $totalQueued += queueTesseraReminders($pdo, $service, $assocId, $today, $deadlineDate);
            }
        }

        // --- Quota payment reminders ---
        if (!empty($smtpCfg['auto_scadenza_quota'])) {
            $tpl = $service->getTemplate('scadenza_quota');
            if ($tpl !== null && !empty($tpl['attivo'])) {
                $totalQueued += queueQuotaReminders($pdo, $service, $assocId, $today);
            }
        }

        echo date('Y-m-d H:i:s') . " Assoc {$assoc['nome']}: processed.\n";
    }

    echo date('Y-m-d H:i:s') . " Done. Total reminders queued: $totalQueued\n";
} catch (\Exception $e) {
    error_log('send_reminders.php error: ' . $e->getMessage());
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Queue tessera expiration reminders.
 *
 * @param \PDO $pdo
 * @param EmailService $service
 * @param string $assocId
 * @param string $today
 * @param string $deadlineDate
 * @return int Number of emails queued
 */
function queueTesseraReminders(\PDO $pdo, EmailService $service, string $assocId, string $today, string $deadlineDate): int
{
    // Find tessere expiring between today and deadline, for active soci not opted out,
    // not already notified (no email_log with template scadenza_tessera for this socio in last 30 days)
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
                    AND el.sent_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assocId, $today, $deadlineDate]);
    $rows = $stmt->fetchAll();

    $queued = 0;
    $batchId = generateUuid();

    foreach ($rows as $row) {
        $ph = buildPlaceholderValues($pdo, $assocId, $row['socio_id'], [
            'NUMERO_TESSERA' => $row['numero_tessera'],
            'ANNO_VALIDITA' => $row['anno_validita'],
            'DATA_SCADENZA' => $row['data_scadenza'],
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

    return $queued;
}

/**
 * Queue quota payment reminders for overdue or near-due quotes.
 *
 * @param \PDO $pdo
 * @param EmailService $service
 * @param string $assocId
 * @param string $today
 * @return int Number of emails queued
 */
function queueQuotaReminders(\PDO $pdo, EmailService $service, string $assocId, string $today): int
{
    // Find unpaid quotes that are overdue or due within 30 days, not already notified recently
    $sql = "SELECT q.id AS quota_id, q.importo, q.anno, q.data_scadenza, q.tipo,
                   s.id AS socio_id, s.nome, s.cognome, s.email
            FROM quote q
            JOIN soci s ON s.id = q.socio_id AND s.associazione_id = q.associazione_id
            WHERE q.associazione_id = ?
              AND q.data_pagamento IS NULL
              AND q.data_scadenza <= DATE_ADD(?, INTERVAL 30 DAY)
              AND s.stato = 'Attivo'
              AND s.email IS NOT NULL AND s.email != ''
              AND (s.email_opt_out = 0 OR s.email_opt_out IS NULL)
              AND NOT EXISTS (
                  SELECT 1 FROM email_log el
                  WHERE el.socio_id = s.id
                    AND el.template_codice = 'scadenza_quota'
                    AND el.sent_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$assocId, $today]);
    $rows = $stmt->fetchAll();

    $queued = 0;
    $batchId = generateUuid();

    foreach ($rows as $row) {
        $ph = buildPlaceholderValues($pdo, $assocId, $row['socio_id'], [
            'IMPORTO' => $row['importo'],
            'ANNO' => $row['anno'],
            'DATA_SCADENZA' => $row['data_scadenza'],
        ]);
        $rendered = $service->renderTemplate('scadenza_quota', $ph);
        if ($rendered !== null) {
            $service->queueEmail(
                $row['email'],
                $row['nome'] . ' ' . $row['cognome'],
                $rendered['subject'],
                $rendered['body'],
                $row['socio_id'],
                $batchId,
                'scadenza_quota',
                5
            );
            $queued++;
        }
    }

    return $queued;
}
