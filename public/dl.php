<?php

declare(strict_types=1);

/**
 * Download full-res: reindirizza a un URL S3 presigned a scadenza breve, così
 * i byte non transitano dal server PHP.
 *   dl.php?id=<n>
 */

use PhotoFacility\Web\Gallery;

$g = Gallery::boot(dirname(__DIR__));

$id = (int) ($_GET['id'] ?? 0);
$url = $g->downloadUrl($id);
if ($url === null) {
    http_response_code(404);
    exit;
}

header('Location: ' . $url, true, 302);
