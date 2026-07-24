#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Entry point CLI dell'ingestion. Da eseguire via cron:
 *
 *   * * * * * /usr/bin/php /path/photofacility/bin/ingest.php >> /path/cron.out 2>&1
 *
 * Esegue un singolo "tick": ammette i file completi e svuota la coda S3.
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
    $result = $app->runTick();

    if (($result['skipped_locked'] ?? false) === true) {
        fwrite(STDOUT, "Tick saltato: lock attivo.\n");
        exit(0);
    }

    fwrite(STDOUT, 'Tick OK: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE FATALE: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
