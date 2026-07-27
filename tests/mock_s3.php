<?php

declare(strict_types=1);

/**
 * Mock S3 minimale per i test end-to-end.
 * Accetta PUT, verifica il Content-MD5 come farebbe S3, e risponde con
 * ETag = md5 hex del corpo ricevuto. Serve solo ai test locali.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// HEAD: simula "oggetto non trovato" (chiave sonda / reaper). Raggiungibile +
// auth OK ma chiave assente = 404, come farebbe S3 con credenziali valide.
if ($method === 'HEAD') {
    http_response_code(404);
    return;
}

if ($method !== 'PUT') {
    http_response_code(405);
    echo 'Method Not Allowed';
    return;
}

$body = file_get_contents('php://input');
if ($body === false) {
    $body = '';
}

$md5Hex = md5($body);
$md5Base64 = base64_encode((string) hex2bin($md5Hex));

// verifica integrità come S3
$provided = $_SERVER['HTTP_CONTENT_MD5'] ?? '';
if ($provided !== '' && $provided !== $md5Base64) {
    http_response_code(400);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0"?><Error><Code>BadDigest</Code><Message>Content-MD5 mismatch</Message></Error>';
    return;
}

// salva per ispezione (facoltativo)
$dumpDir = getenv('MOCK_S3_DUMP');
if ($dumpDir && is_dir($dumpDir)) {
    file_put_contents($dumpDir . '/' . $md5Hex . '.bin', $body);
}

// cattura gli header x-amz-meta-* dell'ultima richiesta (per i test M1)
$headersFile = getenv('MOCK_S3_HEADERS');
if ($headersFile) {
    $meta = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_X_AMZ_META_')) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $meta[$name] = $v;
        }
    }
    file_put_contents($headersFile, json_encode($meta));
}

http_response_code(200);
header('ETag: "' . $md5Hex . '"');
echo '';
