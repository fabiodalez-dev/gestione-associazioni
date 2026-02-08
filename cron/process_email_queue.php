<?php
// cron/process_email_queue.php — Process pending email queue
// Run every 5 minutes via cron.
// Iterates over all associations with pending emails and processes a batch.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/EmailService.php';

$batchSize = 50;

try {
    // Find associations that have pending emails
    $stmt = $pdo->query("SELECT DISTINCT associazione_id FROM email_queue WHERE status = 'pending' ORDER BY created_at ASC");
    $associations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($associations)) {
        echo date('Y-m-d H:i:s') . " No pending emails.\n";
        exit(0);
    }

    $totalSent = 0;
    $totalFailed = 0;

    foreach ($associations as $assocId) {
        $service = new EmailService($pdo, $assocId);

        if (!$service->isConfigured()) {
            echo date('Y-m-d H:i:s') . " Skipping assoc $assocId: SMTP not configured.\n";
            continue;
        }

        $result = $service->processQueue($batchSize);
        $totalSent += $result['sent'];
        $totalFailed += $result['failed'];

        echo date('Y-m-d H:i:s') . " Assoc $assocId: sent={$result['sent']} failed={$result['failed']} remaining={$result['remaining']}\n";
    }

    echo date('Y-m-d H:i:s') . " Done. Total sent=$totalSent failed=$totalFailed\n";
} catch (\Exception $e) {
    error_log('process_email_queue.php error: ' . $e->getMessage());
    echo date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
