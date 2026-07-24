<?php

declare(strict_types=1);

/**
 * Entry point WEB per il cron, da usare SOLO se il cron dell'hosting non può
 * eseguire PHP CLI e deve invocare un URL (es. wget/curl):
 *
 *   * * * * * wget -q -O - "https://tuosito/cron.php?token=SEGRETO"
 *
 * Protetto da token segreto (CRON_TOKEN nel .env). Se puoi usare la CLI
 * (bin/ingest.php), preferiscila: niente timeout web, niente endpoint esposto.
 *
 * ATTENZIONE al deploy: questo file deve stare nel document root, ma il resto
 * del progetto (src, db, .env, staging) NO. Vedi docs/SETUP_SHARED_HOSTING.md.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

// il progetto sta un livello sopra il document root
$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';

Env::load($baseDir . '/.env');

header('Content-Type: text/plain; charset=utf-8');

try {
    $config = Config::fromEnv($baseDir);

    // autenticazione: il token deve essere configurato e combaciare
    $provided = $_GET['token'] ?? '';
    if ($config->cronToken === null || $config->cronToken === '') {
        http_response_code(500);
        echo "CRON_TOKEN non configurato: endpoint web disabilitato.\n";
        exit;
    }
    if (!hash_equals($config->cronToken, (string) $provided)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }

    // evita che un client disconnesso interrompa a metà upload
    ignore_user_abort(true);

    $app = new App($config);
    $result = $app->runTick();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'ERRORE: ' . $e->getMessage() . "\n";
}
