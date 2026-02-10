<?php
/**
 * QR Code helper functions for tessera verification.
 * Requires chillerlan/php-qrcode (loaded via Composer autoload).
 */

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;

/**
 * Generate a raw SVG string for a QR code (ideal for Dompdf embedding).
 */
function generateQrSvg(string $data, int $scale = 4): string {
    $options = new QROptions([
        'outputInterface' => QROutputInterface::MARKUP_SVG,
        'eccLevel'        => EccLevel::M,
        'scale'           => $scale,
        'outputBase64'    => false,
        'addQuietzone'    => true,
        'svgUseFillAttributes' => true,
    ]);

    return (new QRCode($options))->render($data);
}

/**
 * Generate a PNG data URI for a QR code (ideal for HTML <img> tags).
 */
function generateQrDataUri(string $data, int $scale = 5): string {
    $options = new QROptions([
        'outputInterface' => QROutputInterface::GDIMAGE_PNG,
        'eccLevel'        => EccLevel::M,
        'scale'           => $scale,
        'outputBase64'    => true,
        'addQuietzone'    => true,
    ]);

    return (new QRCode($options))->render($data);
}

/**
 * Build the full verification URL for a tessera.
 */
function buildTesseraVerificationUrl(string $tesseraId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    // If called from pages/ subfolder, go up one level
    if (basename($scriptDir) === 'pages') {
        $scriptDir = dirname($scriptDir);
    }
    $basePath = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $basePath . '/verifica-tessera.php?t=' . urlencode($tesseraId);
}
