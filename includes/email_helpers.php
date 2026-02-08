<?php
/**
 * Email helper functions: placeholders, base template wrapping, unsubscribe.
 */

/**
 * List of available placeholders for a given template code.
 * @return array<int,string>
 */
function getEmailPlaceholders(string $codice = ''): array
{
    $common = [
        'NOME', 'COGNOME', 'EMAIL', 'NUMERO_SOCIO', 'DATA_ISCRIZIONE',
        'NOME_ASSOCIAZIONE', 'EMAIL_ASSOCIAZIONE', 'LOGO_ASSOCIAZIONE',
        'ANNO', 'DATA_CORRENTE', 'LINK_DISISCRIZIONE',
    ];

    $extra = [];
    switch ($codice) {
        case 'scadenza_tessera':
        case 'rinnovo_tessera':
            $extra = ['DATA_SCADENZA', 'NUMERO_TESSERA'];
            break;
        case 'scadenza_quota':
            $extra = ['DATA_SCADENZA', 'IMPORTO'];
            break;
        case 'pagamento_quota':
            $extra = ['IMPORTO', 'DATA_PAGAMENTO'];
            break;
        case 'assemblea':
            $extra = ['TITOLO_EVENTO', 'DATA_EVENTO', 'LUOGO_EVENTO'];
            break;
    }

    return array_merge($common, $extra);
}

/**
 * Build the placeholder key→value map for a socio.
 * @param array<string,string> $extra Additional placeholder values
 * @return array<string,string>
 */
function buildPlaceholderValues(PDO $pdo, string $associazioneId, ?string $socioId = null, array $extra = []): array
{
    $vars = [
        'ANNO' => date('Y'),
        'DATA_CORRENTE' => date('d/m/Y'),
    ];

    // Association info
    $stmtA = $pdo->prepare('SELECT nome, email, logo_url FROM associazioni WHERE id = ? LIMIT 1');
    $stmtA->execute([$associazioneId]);
    $assoc = $stmtA->fetch();
    if ($assoc) {
        $vars['NOME_ASSOCIAZIONE'] = htmlspecialchars($assoc['nome'] ?? '', ENT_QUOTES, 'UTF-8');
        $vars['EMAIL_ASSOCIAZIONE'] = htmlspecialchars($assoc['email'] ?? '', ENT_QUOTES, 'UTF-8');
        $vars['LOGO_ASSOCIAZIONE'] = htmlspecialchars($assoc['logo_url'] ?? '', ENT_QUOTES, 'UTF-8');
    }

    // Socio info
    if ($socioId) {
        $stmtS = $pdo->prepare('SELECT nome, cognome, email, numero_socio, data_iscrizione, email_opt_out_token FROM soci WHERE id = ? AND associazione_id = ? LIMIT 1');
        $stmtS->execute([$socioId, $associazioneId]);
        $socio = $stmtS->fetch();
        if ($socio) {
            $vars['NOME'] = htmlspecialchars($socio['nome'] ?? '', ENT_QUOTES, 'UTF-8');
            $vars['COGNOME'] = htmlspecialchars($socio['cognome'] ?? '', ENT_QUOTES, 'UTF-8');
            $vars['EMAIL'] = htmlspecialchars($socio['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $vars['NUMERO_SOCIO'] = htmlspecialchars($socio['numero_socio'] ?? '', ENT_QUOTES, 'UTF-8');
            $vars['DATA_ISCRIZIONE'] = !empty($socio['data_iscrizione']) ? date('d/m/Y', strtotime($socio['data_iscrizione'])) : '';

            $token = ensureUnsubscribeToken($pdo, $socioId);
            $vars['LINK_DISISCRIZIONE'] = generateUnsubscribeUrl($socioId, $token);
        }
    }

    // Merge extra values (already escaped by caller or raw)
    foreach ($extra as $k => $v) {
        $vars[$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    return $vars;
}

/**
 * Wrap email body HTML in the responsive base template.
 */
function wrapInEmailTemplate(string $bodyHtml, string $assocName, string $logoUrl = '', string $unsubUrl = ''): string
{
    $templatePath = __DIR__ . '/../templates/email_base.html';
    if (!file_exists($templatePath)) {
        return $bodyHtml;
    }
    $tpl = file_get_contents($templatePath);
    if ($tpl === false) {
        return $bodyHtml;
    }

    $replacements = [
        '{{BODY}}' => $bodyHtml,
        '{{ASSOCIAZIONE_NOME}}' => htmlspecialchars($assocName, ENT_QUOTES, 'UTF-8'),
        '{{LOGO_URL}}' => htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'),
        '{{UNSUBSCRIBE_URL}}' => htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8'),
        '{{ANNO}}' => date('Y'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $tpl);
}

/**
 * Generate the public unsubscribe URL.
 */
function generateUnsubscribeUrl(string $socioId, string $token): string
{
    $baseUrl = rtrim(getBaseUrl(), '/');
    return $baseUrl . '/api/email_unsubscribe.php?id=' . urlencode($socioId) . '&token=' . urlencode($token);
}

/**
 * Ensure a socio has an unsubscribe token; create one if missing.
 */
function ensureUnsubscribeToken(PDO $pdo, string $socioId): string
{
    $stmt = $pdo->prepare('SELECT email_opt_out_token FROM soci WHERE id = ?');
    $stmt->execute([$socioId]);
    $token = $stmt->fetchColumn();

    if (!empty($token)) {
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    $upd = $pdo->prepare('UPDATE soci SET email_opt_out_token = ? WHERE id = ?');
    $upd->execute([$token, $socioId]);
    return $token;
}

/**
 * Get base URL of the application.
 */
function getBaseUrl(): string
{
    if (php_sapi_name() === 'cli') {
        return $_ENV['APP_URL'] ?? 'http://localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $scheme . '://' . $host . $path;
}
