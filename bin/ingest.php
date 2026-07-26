#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Entry point CLI dell'ingestion. Da eseguire via cron (Plesk Scheduled Tasks):
 *
 *   * * * * * /usr/bin/php /path/photofacility/bin/ingest.php >> /path/db/cron.out 2>&1
 *
 * Esegue il "loop-within-cron": acquisisce il lock una volta e cicla per
 * ~LOOP_DURATION_SECONDS facendo micro-batch (ammissione file completi + drain
 * coda S3), poi esce prima del tick successivo. Il flock evita accavallamenti.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';

Env::load($baseDir . '/.env');

try {
    $config = Config::fromEnv($baseDir);
    $app = new App($config);
    $result = $app->runLoop();

    if (($result['skipped_locked'] ?? false) === true) {
        fwrite(STDOUT, "Loop saltato: lock attivo.\n");
        exit(0);
    }

    fwrite(STDOUT, 'Loop OK: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE FATALE: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
