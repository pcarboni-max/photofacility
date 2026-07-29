<?php

declare(strict_types=1);

/**
 * Passthrough delle immagini di cache (thumb/preview) locali, fuori dal webroot.
 *   media.php?id=<n>&size=thumb|preview
 */

use PhotoFacility\Web\Gallery;
use PhotoFacility\Web\Guard;

$g = Gallery::boot(dirname(__DIR__));
Guard::enforce($g->config);

$id = (int) ($_GET['id'] ?? 0);
$size = (($_GET['size'] ?? 'thumb') === 'preview') ? 'preview' : 'thumb';

$path = $g->mediaPath($id, $size);
if ($path === null) {
    http_response_code(404);
    exit;
}

$etag = '"' . md5($path . (string) filemtime($path)) . '"';
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Length: ' . (string) filesize($path));
readfile($path);
