#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Manutenzione periodica del DB: pruning dei vecchi ingest_events, checkpoint
 * WAL e VACUUM. Da schedulare mensilmente (Plesk → Scheduled Tasks) o lanciare
 * con "Run Now". Nessun SSH necessario.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

try {
    $app = new App(Config::fromEnv($baseDir));
    $result = $app->maintain();
    fwrite(STDOUT, 'Manutenzione OK: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
