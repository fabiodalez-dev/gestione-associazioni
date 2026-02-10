<?php
/**
 * EmailService — core email sending, queue, templates, rate limiting.
 * Wraps PHPMailer; one instance per associazione.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class EmailService
{
    /** @var PDO */
    private $pdo;

    /** @var string */
    private $associazioneId;

    /** @var array<string,mixed>|null */
    private $smtpConfig = null;

    /** @var bool */
    private $configLoaded = false;

    public function __construct(PDO $pdo, string $associazioneId)
    {
        $this->pdo = $pdo;
        $this->associazioneId = $associazioneId;
    }

    // ── Configuration ────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function loadSmtpConfig(): ?array
    {
        if ($this->configLoaded) {
            return $this->smtpConfig;
        }
        $this->configLoaded = true;

        $stmt = $this->pdo->prepare('SELECT * FROM smtp_settings WHERE associazione_id = ? LIMIT 1');
        $stmt->execute([$this->associazioneId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $this->smtpConfig = $row;
        return $this->smtpConfig;
    }

    public function isConfigured(): bool
    {
        $cfg = $this->loadSmtpConfig();
        return $cfg !== null && $cfg['smtp_host'] !== '' && $cfg['from_email'] !== '';
    }

    // ── Sending ──────────────────────────────────────────────────────

    /**
     * @return array{success:bool, error:?string}
     */
    public function sendSingle(string $to, string $name, string $subject, string $html): array
    {
        $cfg = $this->loadSmtpConfig();
        if ($cfg === null) {
            return ['success' => false, 'error' => 'SMTP non configurato.'];
        }

        if (!$this->checkRateLimit()) {
            return ['success' => false, 'error' => 'Limite invii orario raggiunto.'];
        }

        try {
            $mailer = $this->createMailer($cfg);
            $mailer->addAddress($to, $name);
            $mailer->Subject = $subject;
            $mailer->msgHTML($html);
            $mailer->send();
            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            error_log('EmailService::sendSingle error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success:bool, error:?string}
     */
    public function sendTestEmail(string $toEmail): array
    {
        $cfg = $this->loadSmtpConfig();
        if ($cfg === null) {
            return ['success' => false, 'error' => 'SMTP non configurato.'];
        }

        try {
            $mailer = $this->createMailer($cfg);
            $mailer->addAddress($toEmail);
            $mailer->Subject = 'Test SMTP — ' . ($cfg['from_name'] ?: 'Associazione');
            $mailer->msgHTML('<h2>Test riuscito!</h2><p>La configurazione SMTP funziona correttamente.</p>');
            $mailer->send();

            // Update last_test_at
            $stmt = $this->pdo->prepare('UPDATE smtp_settings SET last_test_at = NOW(), is_verified = 1 WHERE associazione_id = ?');
            $stmt->execute([$this->associazioneId]);

            return ['success' => true, 'error' => null];
        } catch (PHPMailerException $e) {
            error_log('EmailService::sendTestEmail error: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Queue ────────────────────────────────────────────────────────

    /**
     * Queue a single email. Returns the queue entry ID.
     */
    public function queueEmail(
        string $to,
        string $name,
        string $subject,
        string $html,
        ?string $socioId,
        string $batchId,
        ?string $codice = null,
        int $priority = 5
    ): string {
        $id = generateUuid();
        $stmt = $this->pdo->prepare('
            INSERT INTO email_queue
                (id, associazione_id, batch_id, socio_id, to_email, to_name, subject, body_html, template_codice, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $id,
            $this->associazioneId,
            $batchId,
            $socioId,
            $to,
            $name,
            $subject,
            $html,
            $codice,
            $priority,
        ]);
        return $id;
    }

    /**
     * Queue a batch of emails. Returns the batch_id.
     * @param array<int,array{email:string,name:string,socio_id?:string}> $recipients
     */
    public function queueBulk(array $recipients, string $subject, string $html, ?string $codice = null): string
    {
        $batchId = generateUuid();
        $stmt = $this->pdo->prepare('
            INSERT INTO email_queue
                (id, associazione_id, batch_id, socio_id, to_email, to_name, subject, body_html, template_codice, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 5)
        ');
        foreach ($recipients as $r) {
            $stmt->execute([
                generateUuid(),
                $this->associazioneId,
                $batchId,
                $r['socio_id'] ?? null,
                $r['email'],
                $r['name'] ?? '',
                $subject,
                $html,
                $codice,
            ]);
        }
        return $batchId;
    }

    /**
     * Process pending queue items. Returns stats.
     * @return array{sent:int, failed:int, remaining:int}
     */
    public function processQueue(int $batchSize = 20): array
    {
        $sent = 0;
        $failed = 0;

        // Fetch pending items ordered by priority then creation
        $stmt = $this->pdo->prepare('
            SELECT * FROM email_queue
            WHERE associazione_id = ? AND status IN ("pending","failed")
              AND attempts < max_attempts
            ORDER BY priority ASC, created_at ASC
            LIMIT ?
        ');
        $stmt->bindValue(1, $this->associazioneId);
        $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            if (!$this->checkRateLimit()) {
                break;
            }

            // Mark as sending
            $upd = $this->pdo->prepare('UPDATE email_queue SET status = "sending", last_attempt_at = NOW(), attempts = attempts + 1 WHERE id = ?');
            $upd->execute([$item['id']]);

            $result = $this->sendSingle($item['to_email'], $item['to_name'], $item['subject'], $item['body_html']);

            if ($result['success']) {
                $upd2 = $this->pdo->prepare('UPDATE email_queue SET status = "sent", sent_at = NOW(), error_message = NULL WHERE id = ?');
                $upd2->execute([$item['id']]);
                $this->logEmail($item, 'sent', null);
                $sent++;
            } else {
                $newStatus = ((int)$item['attempts'] + 1 >= (int)$item['max_attempts']) ? 'failed' : 'pending';
                $upd3 = $this->pdo->prepare('UPDATE email_queue SET status = ?, error_message = ? WHERE id = ?');
                $upd3->execute([$newStatus, $result['error'], $item['id']]);
                if ($newStatus === 'failed') {
                    $this->logEmail($item, 'failed', $result['error']);
                }
                $failed++;
            }
        }

        // Count remaining
        $stmtR = $this->pdo->prepare('SELECT COUNT(*) FROM email_queue WHERE associazione_id = ? AND status IN ("pending") AND attempts < max_attempts');
        $stmtR->execute([$this->associazioneId]);
        $remaining = (int)$stmtR->fetchColumn();

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }

    /**
     * Cancel all pending items in a batch.
     */
    public function cancelBatch(string $batchId): int
    {
        $stmt = $this->pdo->prepare('UPDATE email_queue SET status = "cancelled" WHERE batch_id = ? AND associazione_id = ? AND status = "pending"');
        $stmt->execute([$batchId, $this->associazioneId]);
        return $stmt->rowCount();
    }

    // ── Templates ────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    public function getTemplate(string $codice): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM email_templates WHERE associazione_id = ? AND codice = ? LIMIT 1');
        $stmt->execute([$this->associazioneId, $codice]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Render a template with placeholder substitution.
     * @param array<string,string> $placeholders
     * @return array{subject:string, body:string}|null
     */
    public function renderTemplate(string $codice, array $placeholders): ?array
    {
        $tpl = $this->getTemplate($codice);
        if (!$tpl || !$tpl['attivo']) {
            return null;
        }
        $subject = renderTemplatePlaceholders($tpl['oggetto'], $placeholders);
        $body = renderTemplatePlaceholders($tpl['corpo_html'], $placeholders);
        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Default template definitions (Italian).
     * @return array<string,array{nome:string,oggetto:string,corpo_html:string}>
     */
    public function getDefaultTemplates(): array
    {
        return [
            'benvenuto' => [
                'nome' => 'Benvenuto nuovo socio',
                'oggetto' => 'Benvenuto in {NOME_ASSOCIAZIONE}, {NOME}!',
                'corpo_html' => '<h2>Benvenuto, {NOME} {COGNOME}!</h2><p>Siamo lieti di accoglierti come nuovo socio di <strong>{NOME_ASSOCIAZIONE}</strong>.</p><p>Il tuo numero socio è: <strong>{NUMERO_SOCIO}</strong></p><p>Data iscrizione: {DATA_ISCRIZIONE}</p><p>Per qualsiasi informazione, contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'scadenza_tessera' => [
                'nome' => 'Promemoria scadenza tessera',
                'oggetto' => 'La tua tessera {NUMERO_TESSERA} sta per scadere',
                'corpo_html' => '<h2>Ciao {NOME},</h2><p>Ti ricordiamo che la tua tessera n. <strong>{NUMERO_TESSERA}</strong> presso {NOME_ASSOCIAZIONE} scadrà il <strong>{DATA_SCADENZA}</strong>.</p><p>Ti invitiamo a rinnovarla al più presto. Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'scadenza_quota' => [
                'nome' => 'Promemoria quota da pagare',
                'oggetto' => 'Promemoria: quota associativa da pagare',
                'corpo_html' => '<h2>Ciao {NOME},</h2><p>Ti ricordiamo che la quota associativa di <strong>€{IMPORTO}</strong> per {NOME_ASSOCIAZIONE} risulta ancora da pagare.</p><p>Scadenza: <strong>{DATA_SCADENZA}</strong></p><p>Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'assemblea' => [
                'nome' => 'Convocazione assemblea',
                'oggetto' => 'Convocazione: {TITOLO_EVENTO}',
                'corpo_html' => '<h2>Convocazione Assemblea</h2><p>Caro/a {NOME} {COGNOME},</p><p>Sei convocato/a all\'assemblea <strong>{TITOLO_EVENTO}</strong>.</p><p>Data: <strong>{DATA_EVENTO}</strong><br>Luogo: <strong>{LUOGO_EVENTO}</strong></p><p>La tua partecipazione è importante. Per informazioni contattaci a {EMAIL_ASSOCIAZIONE}.</p>',
            ],
            'comunicazione' => [
                'nome' => 'Comunicazione generica',
                'oggetto' => '{NOME_ASSOCIAZIONE} — Comunicazione',
                'corpo_html' => '<p>Caro/a {NOME} {COGNOME},</p><p>Questa è una comunicazione da parte di {NOME_ASSOCIAZIONE}.</p>',
            ],
            'rinnovo_tessera' => [
                'nome' => 'Conferma rinnovo tessera',
                'oggetto' => 'Tessera rinnovata — {NOME_ASSOCIAZIONE}',
                'corpo_html' => '<h2>Tessera rinnovata!</h2><p>Ciao {NOME},</p><p>La tua tessera n. <strong>{NUMERO_TESSERA}</strong> è stata rinnovata con successo.</p><p>Nuova scadenza: <strong>{DATA_SCADENZA}</strong></p><p>Grazie per il rinnovo!</p>',
            ],
            'pagamento_quota' => [
                'nome' => 'Conferma pagamento quota',
                'oggetto' => 'Pagamento registrato — {NOME_ASSOCIAZIONE}',
                'corpo_html' => '<h2>Pagamento ricevuto!</h2><p>Ciao {NOME},</p><p>Confermiamo la ricezione del pagamento di <strong>€{IMPORTO}</strong> in data {DATA_PAGAMENTO}.</p><p>Grazie per il tuo contributo a {NOME_ASSOCIAZIONE}!</p>',
            ],
        ];
    }

    /**
     * Seed default templates for this association (skip existing).
     */
    public function seedDefaultTemplates(): void
    {
        $defaults = $this->getDefaultTemplates();
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO email_templates (id, associazione_id, codice, nome, oggetto, corpo_html)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        foreach ($defaults as $codice => $tpl) {
            $stmt->execute([
                generateUuid(),
                $this->associazioneId,
                $codice,
                $tpl['nome'],
                $tpl['oggetto'],
                $tpl['corpo_html'],
            ]);
        }
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * @param array<string,mixed> $cfg
     */
    private function createMailer(array $cfg): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->Port = (int)$cfg['smtp_port'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['smtp_user'];

        // Decrypt password
        $mail->Password = decryptValue($cfg['smtp_pass_encrypted']);

        $enc = $cfg['smtp_encryption'];
        if ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($cfg['from_email'], $cfg['from_name'] ?: '');

        if (!empty($cfg['reply_to_email'])) {
            $mail->addReplyTo($cfg['reply_to_email'], $cfg['reply_to_name'] ?? '');
        }

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);

        return $mail;
    }

    /**
     * @param array<string,mixed> $item Queue row
     */
    private function logEmail(array $item, string $status, ?string $error): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO email_log (id, associazione_id, batch_id, socio_id, to_email, to_name, subject, template_codice, status, error_message, sent_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            generateUuid(),
            $this->associazioneId,
            $item['batch_id'] ?? null,
            $item['socio_id'] ?? null,
            $item['to_email'],
            $item['to_name'] ?? '',
            $item['subject'],
            $item['template_codice'] ?? null,
            $status,
            $error,
            $_SESSION['user_id'] ?? null,
        ]);
    }

    private function checkRateLimit(): bool
    {
        $cfg = $this->loadSmtpConfig();
        if ($cfg === null) {
            return false;
        }
        $maxPerHour = (int)($cfg['max_per_hour'] ?? 100);
        if ($maxPerHour <= 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM email_log
            WHERE associazione_id = ? AND status = "sent" AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ');
        $stmt->execute([$this->associazioneId]);
        $count = (int)$stmt->fetchColumn();

        return $count < $maxPerHour;
    }
}
