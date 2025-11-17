<?php
// pages/genera-tessera-pdf.php - Generazione PDF tessera socio (Dompdf)

// Config già caricato da index.php, non serve require_once

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isUserLoggedIn() || !isset($_SESSION['associazione_id'])) {
    redirect('auth/login.php');
}

// Assicura colonne costo tessera
ensureTesseraCostColumns($pdo);

$associazione_id = $_SESSION['associazione_id'];
$socio_id = $_GET['socio_id'] ?? null;

if (!$socio_id) {
    redirect('index.php?page=soci');
}

// Recupera dati socio e associazione
try {
    // Dati socio con categoria e tipo
    $stmt = $pdo->prepare("
        SELECT s.*, 
               cs.nome as categoria_nome, cs.testo_tessera,
               ts.nome as tipo_nome, ts.costo_tessera as tipo_costo_tessera,
               a.nome as associazione_nome, a.logo_url, a.costo_tessera as assoc_costo_tessera,
               a.partita_iva as associazione_partita_iva, a.codice_fiscale as associazione_codice_fiscale,
               a.indirizzo as associazione_indirizzo, a.citta as associazione_citta, a.provincia as associazione_provincia, a.cap as associazione_cap,
               a.email as associazione_email, a.telefono as associazione_telefono
        FROM soci s 
        LEFT JOIN categorie_socio cs ON s.categoria_socio_id = cs.id
        LEFT JOIN tipi_socio ts ON s.tipo_socio_id = ts.id
        LEFT JOIN associazioni a ON s.associazione_id = a.id
        WHERE s.id = ? AND s.associazione_id = ?
    ");
    $stmt->execute([$socio_id, $associazione_id]);
    $socio = $stmt->fetch();
    
    if (!$socio) {
        redirect('index.php?page=soci');
    }
    
    // Verifica se esiste già una tessera per l'anno corrente
    $anno_corrente = date('Y');
    $stmt_tessera = $pdo->prepare("
        SELECT numero_tessera, data_scadenza, tipo_scadenza, template_tessera
        FROM tessere 
        WHERE socio_id = ? AND anno_validita = ?
        ORDER BY data_emissione DESC 
        LIMIT 1
    ");
    $stmt_tessera->execute([$socio_id, $anno_corrente]);
    $tessera = $stmt_tessera->fetch();
    
    // Se non esiste, crea una nuova tessera
    if (!$tessera) {
        // Genera numero tessera
        $stmt_count = $pdo->prepare("SELECT COUNT(*) as count FROM tessere WHERE associazione_id = ? AND anno_validita = ?");
        $stmt_count->execute([$associazione_id, $anno_corrente]);
        $count = $stmt_count->fetch()['count'] + 1;
        $numero_tessera = $anno_corrente . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        // Tipo scadenza e data scadenza in base alla configurazione dell'associazione
        $cfg_stmt = $pdo->prepare("SELECT tipo_scadenza_default, giorni_notifica_scadenza FROM associazioni WHERE id = ?");
        $cfg_stmt->execute([$associazione_id]);
        $cfg = $cfg_stmt->fetch() ?: ['tipo_scadenza_default' => 'solare'];
        $tipo_scadenza = $cfg['tipo_scadenza_default'] ?? 'solare';
        if ($tipo_scadenza === 'annuale') {
            $data_scadenza = date('Y-m-d', strtotime('+1 year'));
        } else {
            $data_scadenza = date('Y-12-31');
        }
        
        // Imposta un'etichetta di tipo tessera se disponibile (usa nome tipo socio o 'Default')
        $template_tessera_value = !empty($socio['tipo_nome']) ? $socio['tipo_nome'] : 'Default';
        
        // Inserisci nuova tessera
        $tessera_id = generateUuid();
        $stmt_insert = $pdo->prepare("
            INSERT INTO tessere (id, socio_id, associazione_id, numero_tessera, anno_validita, data_emissione, data_scadenza, tipo_scadenza, stato, template_tessera) 
            VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, 'Attiva', ?)
        ");
        $stmt_insert->execute([$tessera_id, $socio_id, $associazione_id, $numero_tessera, $anno_corrente, $data_scadenza, $tipo_scadenza, $template_tessera_value]);
        
        $tessera = ['numero_tessera' => $numero_tessera, 'data_scadenza' => $data_scadenza, 'tipo_scadenza' => $tipo_scadenza, 'template_tessera' => $template_tessera_value];
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    die("Errore nel recupero dati: " . $e->getMessage());
}

// Carica l'autoloader di Composer per Dompdf
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    die('Libreria Dompdf non installata. Esegui composer install.');
}
require_once $autoloadPath;

// Prepara HTML del PDF (A4)
$associazione_nome = htmlspecialchars($socio['associazione_nome'] ?? '');
$nome_completo = htmlspecialchars(trim(($socio['nome'] ?? '') . ' ' . ($socio['cognome'] ?? '')));
$numero_socio = htmlspecialchars($socio['numero_socio'] ?? '');
$categoria_tipo = htmlspecialchars(trim(($socio['categoria_nome'] ?? '') . (!empty($socio['tipo_nome']) ? ' - ' . $socio['tipo_nome'] : '')));
$numero_tessera = htmlspecialchars($tessera['numero_tessera'] ?? '');
$testo_tessera = !empty($socio['testo_tessera']) ? nl2br(htmlspecialchars($socio['testo_tessera'])) : '';
$data_oggi = date('d/m/Y');
$costo_applicato = null;
if (!empty($socio['tipo_costo_tessera'])) { $costo_applicato = (float)$socio['tipo_costo_tessera']; }
elseif (!empty($socio['assoc_costo_tessera'])) { $costo_applicato = (float)$socio['assoc_costo_tessera']; }
$costo_str = $costo_applicato !== null ? '€ ' . number_format($costo_applicato, 2, ',', '.') : '';

// Recupera un template specifico per tipo socio o quello di default (se la tabella esiste)
$tpl_row = null;
if (function_exists('tableExists') && tableExists($pdo, 'tessera_templates')) {
    $tpl_sql = "SELECT contenuto FROM tessera_templates WHERE associazione_id = ? AND (tipo_socio_id = ? OR (tipo_socio_id IS NULL)) ORDER BY tipo_socio_id IS NULL ASC LIMIT 1";
    $tpl_stmt = $pdo->prepare($tpl_sql);
    $tpl_stmt->execute([$associazione_id, $socio['tipo_socio_id'] ?? null]);
    $tpl_row = $tpl_stmt->fetch();
}

// Costruisci placeholder per il template
$placeholders = [
    'ASSOCIAZIONE_NOME' => $socio['associazione_nome'] ?? '',
    'ASSOCIAZIONE_CODICE_FISCALE' => $socio['associazione_codice_fiscale'] ?? '',
    'ASSOCIAZIONE_PARTITA_IVA' => $socio['associazione_partita_iva'] ?? '',
    'ASSOCIAZIONE_INDIRIZZO' => $socio['associazione_indirizzo'] ?? '',
    'ASSOCIAZIONE_CITTA' => $socio['associazione_citta'] ?? '',
    'ASSOCIAZIONE_PROVINCIA' => $socio['associazione_provincia'] ?? '',
    'ASSOCIAZIONE_CAP' => $socio['associazione_cap'] ?? '',
    'ASSOCIAZIONE_EMAIL' => $socio['associazione_email'] ?? '',
    'ASSOCIAZIONE_TELEFONO' => $socio['associazione_telefono'] ?? '',
    'NOME' => $socio['nome'] ?? '',
    'COGNOME' => $socio['cognome'] ?? '',
    'NOME_COMPLETO' => trim(($socio['nome'] ?? '') . ' ' . ($socio['cognome'] ?? '')),
    'NUMERO_SOCIO' => $socio['numero_socio'] ?? '',
    'CODICE_FISCALE' => $socio['codice_fiscale'] ?? '',
    'INDIRIZZO' => $socio['indirizzo'] ?? '',
    'CITTA' => $socio['citta'] ?? '',
    'PROVINCIA' => $socio['provincia'] ?? '',
    'CAP' => $socio['cap'] ?? '',
    'TIPO_SOCIO' => $socio['tipo_nome'] ?? '',
    'CATEGORIA_SOCIO' => $socio['categoria_nome'] ?? '',
    'NUMERO_TESSERA' => $tessera['numero_tessera'] ?? '',
    'ANNO_VALIDITA' => $anno_corrente,
    'DATA_EMISSIONE' => date('d/m/Y'),
    'DATA_SCADENZA' => isset($tessera['data_scadenza']) ? date('d/m/Y', strtotime($tessera['data_scadenza'])) : date('d/m/Y', strtotime($anno_corrente . '-12-31')),
];

$rendered_testo = '';
if ($tpl_row && !empty($tpl_row['contenuto'])) {
    $rendered_testo = nl2br(htmlspecialchars(renderTemplatePlaceholders($tpl_row['contenuto'], $placeholders)));
} elseif (!empty($socio['testo_tessera'])) {
    $rendered_testo = nl2br(htmlspecialchars(renderTemplatePlaceholders($socio['testo_tessera'], $placeholders)));
}

// Gestione logo: se locale, converti in data URI (da mostrare nell'header)
$logo_img = '';
if (!empty($socio['logo_url'])) {
    $logoSrc = $socio['logo_url'];
    if (preg_match('~^https?://~i', $logoSrc)) {
        $logo_img = '<img src="' . htmlspecialchars($logoSrc) . '" style="height:48px; width:auto;">';
    } else {
        $path = realpath(APP_ROOT . '/' . ltrim($logoSrc, '/'));
        if ($path && is_file($path)) {
            $mime = mime_content_type($path);
            $data = base64_encode(file_get_contents($path));
            $logo_img = '<img src="data:' . htmlspecialchars($mime) . ';base64,' . $data . '" style="height:48px; width:auto;">';
        }
    }
}

$body_text = $rendered_testo ? '<div class="box">' . $rendered_testo . '</div>' : '';
$valid_until = isset($tessera['data_scadenza']) ? date('d/m/Y', strtotime($tessera['data_scadenza'])) : date('d/m/Y', strtotime($anno_corrente . '-12-31'));

$html = <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page { size: A4; margin: 18mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #222; font-size: 12pt; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #003366; padding-bottom: 8px; margin-bottom: 18px; }
        .brand-wrap { display: flex; align-items: center; gap: 12px; }
        .brand-wrap img { display: block; height: 48px; width: auto; }
        .brand { font-weight: bold; color: #003366; font-size: 16pt; }
        .doc-title { font-size: 14pt; margin-top: 2px; }
        .meta { font-size: 10pt; color: #666; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .grid td { padding: 6px 8px; vertical-align: top; }
        .label { color: #444; font-weight: bold; width: 35%; }
        .box { border: 1px solid #ccc; border-radius: 6px; padding: 10px; background: #fafafa; }
        .signatures { margin-top: 26px; display: table; width: 100%; }
        .sig { display: table-cell; width: 50%; padding-right: 12px; }
        .line { height: 1px; background: #555; margin-top: 36px; }
        .caption { text-align: center; font-size: 10pt; color: #555; margin-top: 6px; }
        .note { font-size: 10pt; color: #666; margin-top: 14px; }
    </style>
    <title>Tessera $nome_completo</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    </head>
<body>
    <div class="header">
        <div class="brand-wrap">
            $logo_img
            <div>
                <div class="brand">$associazione_nome</div>
                <div class="doc-title">Tessera Associativa - $anno_corrente</div>
            </div>
        </div>
        <div class="meta">Data: $data_oggi</div>
    </div>

    <table class="grid">
        <tr>
            <td class="label">Nome e Cognome</td>
            <td>$nome_completo</td>
        </tr>
        <tr>
            <td class="label">Numero Socio</td>
            <td>$numero_socio</td>
        </tr>
        <tr>
            <td class="label">Categoria / Tipo</td>
            <td>$categoria_tipo</td>
        </tr>
        <tr>
            <td class="label">Numero Tessera</td>
            <td>$numero_tessera</td>
        </tr>
        <tr>
            <td class="label">Costo Tessera</td>
            <td>$costo_str</td>
        </tr>
    </table>

    $body_text

    <div class="signatures">
        <div class="sig">
            <div class="line"></div>
            <div class="caption">Firma del socio</div>
        </div>
        <div class="sig">
            <div class="line"></div>
            <div class="caption">Firma del Presidente/Segretario</div>
        </div>
    </div>

    <div class="note">Valida fino al $valid_until. Il presente documento, se firmato, vale come tessera associativa.</div>
</body>
</html>
HTML;

// Configura Dompdf
$options = new Options();
// SECURITY FIX: Disable remote loading to prevent SSRF attacks
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Pulisce eventuali buffer e invia il PDF inline
if (function_exists('ob_get_length') && ob_get_length()) {
    @ob_end_clean();
}
$filename = 'tessera_' . ($socio['numero_socio'] ?? 'socio') . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit;
