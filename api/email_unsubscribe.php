<?php
// api/email_unsubscribe.php — Public unsubscribe endpoint (no auth required)

require_once __DIR__ . '/../config.php';

$socio_id = $_GET['id'] ?? '';
$token = $_GET['token'] ?? '';

if (empty($socio_id) || empty($token)) {
    http_response_code(400);
    echo renderUnsubscribePage('Errore', 'Link non valido.', '');
    exit;
}

try {
    // Validate token
    $stmt = $pdo->prepare('SELECT s.id, s.email, s.email_opt_out, s.email_opt_out_token, a.nome AS associazione_nome FROM soci s JOIN associazioni a ON a.id = s.associazione_id WHERE s.id = ? AND s.email_opt_out_token = ?');
    $stmt->execute([$socio_id, $token]);
    $socio = $stmt->fetch();

    if (!$socio) {
        http_response_code(404);
        echo renderUnsubscribePage('Errore', 'Link non valido o scaduto.', '');
        exit;
    }

    $assocName = htmlspecialchars($socio['associazione_nome']);

    if (!empty($socio['email_opt_out'])) {
        echo renderUnsubscribePage('Già disiscritto', 'Sei già disiscritto dalle comunicazioni email.', $assocName);
        exit;
    }

    // Handle POST confirmation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_unsubscribe'])) {
        $stmtUpdate = $pdo->prepare('UPDATE soci SET email_opt_out = 1 WHERE id = ?');
        $stmtUpdate->execute([$socio_id]);

        echo renderUnsubscribePage('Disiscrizione completata', 'Sei stato disiscritto con successo dalle comunicazioni email di <strong>' . $assocName . '</strong>.<br>Non riceverai più email promozionali o comunicazioni.', $assocName);
        exit;
    }

    // Show confirmation form
    $email = htmlspecialchars($socio['email']);
    $formHtml = '<p>Stai per disiscriverti dalle comunicazioni email di <strong>' . $assocName . '</strong>.</p>'
        . '<p>Email: <strong>' . $email . '</strong></p>'
        . '<form method="POST">'
        . '<input type="hidden" name="confirm_unsubscribe" value="1">'
        . '<button type="submit" style="background-color:#dc3545;color:#fff;border:none;padding:10px 24px;border-radius:4px;font-size:16px;cursor:pointer;">Conferma Disiscrizione</button>'
        . '</form>';

    echo renderUnsubscribePage('Disiscrizione', $formHtml, $assocName);
} catch (\Exception $e) {
    error_log('email_unsubscribe.php error: ' . $e->getMessage());
    http_response_code(500);
    echo renderUnsubscribePage('Errore', 'Si è verificato un errore. Riprova più tardi.', '');
}

/**
 * Render a simple standalone HTML page for unsubscribe flow.
 *
 * @param string $title
 * @param string $bodyHtml
 * @param string $assocName
 * @return string
 */
function renderUnsubscribePage(string $title, string $bodyHtml, string $assocName): string
{
    $titleEsc = htmlspecialchars($title);
    $year = date('Y');
    $footer = $assocName !== '' ? $assocName : 'Gestione Associazioni';

    return '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $titleEsc . '</title>'
        . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f5f5;margin:0;padding:40px 16px;}'
        . '.card{max-width:500px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);overflow:hidden;}'
        . '.header{background:#FF7B11;color:#fff;padding:20px;text-align:center;}'
        . '.body{padding:24px;line-height:1.6;color:#333;}'
        . '.footer{text-align:center;padding:16px;color:#999;font-size:13px;}</style>'
        . '</head><body>'
        . '<div class="card">'
        . '<div class="header"><h2 style="margin:0;">' . $titleEsc . '</h2></div>'
        . '<div class="body">' . $bodyHtml . '</div>'
        . '<div class="footer">&copy; ' . $year . ' ' . htmlspecialchars($footer) . '</div>'
        . '</div>'
        . '</body></html>';
}
