#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Applica lo schema del database. Idempotente (CREATE TABLE IF NOT EXISTS).
 * Eseguire una volta al primo deploy:  php bin/migrate.php
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
    $app->migrate($baseDir . '/db/schema.sql');
    fwrite(STDOUT, "Migrazione completata.\n");
    fwrite(STDOUT, 'Stato attuale: ' . json_encode($app->statusReport(), JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE MIGRAZIONE: ' . $e->getMessage() . "\n");
    exit(1);
}
