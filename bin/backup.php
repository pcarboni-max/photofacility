#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backup del database SQLite (metadati + tag) su S3. Da schedulare giornalmente
 * (Plesk → Scheduled Tasks). I byte delle foto sono già su S3; questo protegge
 * il lavoro di catalogazione della Fase 2, che vive solo nel DB.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

try {
    $app = new App(Config::fromEnv($baseDir));
    $key = $app->backupDbToS3();
    fwrite(STDOUT, "Backup DB completato: s3://{$key}\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE BACKUP: ' . $e->getMessage() . "\n");
    exit(1);
}
