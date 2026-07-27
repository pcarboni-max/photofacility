#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Diagnostica da riga di comando (stesso motore di public/health.php, senza
 * superficie HTTP). Da lanciare via Plesk → Scheduled Tasks "Run Now".
 *
 *   php bin/doctor.php           # tabella leggibile
 *   php bin/doctor.php --json    # output JSON
 *
 * Exit code: 0 se SOLID/WARNINGS, 2 se CRITICAL (usabile in automazioni).
 */

use PhotoFacility\Config;
use PhotoFacility\Health\HealthCheck;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

$json = in_array('--json', $argv, true);

try {
    $report = (new HealthCheck(Config::fromEnv($baseDir)))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE diagnostica: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($json) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit($report['verdict'] === 'CRITICAL' ? 2 : 0);
}

$icon = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
fwrite(STDOUT, "PhotoFacility — Diagnostica: {$report['verdict']}\n");
fwrite(STDOUT, "  OK={$report['summary']['ok']}  WARN={$report['summary']['warn']}  FAIL={$report['summary']['fail']}\n");
fwrite(STDOUT, str_repeat('-', 70) . "\n");

$cat = '';
foreach ($report['checks'] as $c) {
    if ($c['category'] !== $cat) {
        $cat = $c['category'];
        fwrite(STDOUT, "\n{$cat}\n");
    }
    fwrite(STDOUT, sprintf("  %s %-24s %s\n", $icon[$c['status']], $c['name'], $c['detail']));
}
fwrite(STDOUT, "\n");

exit($report['verdict'] === 'CRITICAL' ? 2 : 0);
