#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ripesca dalla dead-letter: riporta in coda (PENDING_S3) le foto in QUARANTINE
 * e/o ERROR, azzerando i tentativi. Da usare DOPO aver risolto la causa (es.
 * credenziali/permessi S3 corretti).
 *
 * Uso (via Plesk → Scheduled Tasks "Run Now", nessun SSH necessario):
 *   php bin/requeue.php            # requeue QUARANTINE + ERROR
 *   php bin/requeue.php quarantine # solo QUARANTINE
 *   php bin/requeue.php error      # solo ERROR
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

$arg = strtolower($argv[1] ?? 'all');
$statuses = match ($arg) {
    'quarantine' => ['QUARANTINE'],
    'error' => ['ERROR'],
    default => ['QUARANTINE', 'ERROR'],
};

try {
    $app = new App(Config::fromEnv($baseDir));
    $n = $app->requeue($statuses);
    fwrite(STDOUT, "Requeue completato: {$n} foto riportate in coda ({$arg}).\n");
    fwrite(STDOUT, 'Stato: ' . json_encode($app->statusReport(), JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}
