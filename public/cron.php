<?php

declare(strict_types=1);

/**
 * Entry point WEB per il cron, da usare SOLO se il cron dell'hosting non può
 * eseguire PHP CLI e deve invocare un URL:
 *
 *   * * * * * wget -q -O - --header="X-Cron-Token: SEGRETO" "https://tuosito/cron.php"
 *
 * Protetto da token segreto (CRON_TOKEN nel .env), passabile via header
 * (consigliato) o querystring. Se puoi usare la CLI (bin/ingest.php),
 * preferiscila: niente timeout del web server, niente endpoint esposto.
 *
 * Esegue un singolo passaggio (runTick), non il loop: il web server ha limiti
 * di durata. Per la latenza minima usa comunque la CLI.
 *
 * ATTENZIONE al deploy: questo file deve stare nel document root, ma il resto
 * del progetto (src, db, .env, staging) NO. Vedi docs/SETUP.md.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

header('Content-Type: text/plain; charset=utf-8');

// --- Autenticazione PRIMA di qualsiasi altra cosa ---
$expected = Env::get('CRON_TOKEN');
if ($expected === null || $expected === '') {
    http_response_code(500);
    echo "Endpoint disabilitato.\n"; // niente dettagli
    exit;
}
$provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
if (!is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// il web server può avere un time limit: evitiamo di morire a metà upload.
@set_time_limit(0);
ignore_user_abort(true);

try {
    $app = new App(Config::fromEnv($baseDir));
    $result = $app->runTick();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    // log dettagliato lato server, risposta generica al client (no info-disclosure)
    @error_log('[photofacility/cron] ' . $e->getMessage());
    http_response_code(500);
    echo "Errore interno.\n";
}
