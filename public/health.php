<?php

declare(strict_types=1);

/**
 * Cruscotto di diagnostica (read-only). Verifica bucket S3, salute del DB,
 * filesystem, cron e solidità complessiva della soluzione.
 *
 *   https://tuosito/health.php                → HTML
 *   https://tuosito/health.php?format=json    → JSON (per monitor esterni)
 *
 * Token OBBLIGATORIO (HEALTH_TOKEN nel .env), via header o querystring:
 *   wget --header="X-Health-Token: SEGRETO" https://tuosito/health.php?format=json
 *
 * SICUREZZA: è l'unica superficie HTTP del progetto. Non muta stato, non stampa
 * segreti (solo presente/assente). Va comunque protetta e possibilmente esposta
 * solo dietro IP whitelist. Vedi docs/SETUP.md.
 */

use PhotoFacility\Config;
use PhotoFacility\Health\HealthCheck;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';
Env::load($baseDir . '/.env');

// --- Autenticazione PRIMA di tutto ---
$expected = Env::get('HEALTH_TOKEN');
$json = (($_GET['format'] ?? '') === 'json');

if ($expected === null || $expected === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Diagnostica disabilitata: HEALTH_TOKEN non configurato.\n";
    exit;
}
$provided = $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ($_GET['token'] ?? '');
if (!is_string($provided) || !hash_equals($expected, $provided)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

@set_time_limit(30);

try {
    $report = (new HealthCheck(Config::fromEnv($baseDir)))->run();
} catch (\Throwable $e) {
    @error_log('[photofacility/health] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Errore interno durante la diagnostica.\n";
    exit;
}

// codice HTTP: 200 se SOLID/WARNINGS, 503 se CRITICAL (utile ai monitor)
http_response_code($report['verdict'] === 'CRITICAL' ? 503 : 200);

if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

// --- HTML ---
$colors = ['ok' => '#1a7f37', 'warn' => '#9a6700', 'fail' => '#cf222e'];
$dots = ['ok' => '●', 'warn' => '▲', 'fail' => '✖'];
$verdictColor = ['SOLID' => '#1a7f37', 'WARNINGS' => '#9a6700', 'CRITICAL' => '#cf222e'][$report['verdict']];

$byCat = [];
foreach ($report['checks'] as $c) {
    $byCat[$c['category']][] = $c;
}

$h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PhotoFacility — Diagnostica</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
         max-width: 900px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
  header { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
  h1 { font-size: 1.4rem; margin: 0; }
  .verdict { font-weight: 700; padding: .35rem .9rem; border-radius: 999px; color: #fff;
             background: <?= $verdictColor ?>; }
  .meta { color: #6e7781; font-size: .85rem; }
  .summary { margin: .5rem 0 1.5rem; font-size: .9rem; }
  h2 { font-size: 1rem; margin: 1.5rem 0 .5rem; border-bottom: 1px solid #d0d7de; padding-bottom: .25rem; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: .4rem .5rem; vertical-align: top; border-bottom: 1px solid #eaeef2; }
  td.status { white-space: nowrap; font-weight: 700; width: 1%; }
  td.name { white-space: nowrap; width: 1%; color: #57606a; }
  .foot { margin-top: 2rem; color: #6e7781; font-size: .8rem; }
  @media (prefers-color-scheme: dark) {
    td { border-color: #30363d; } h2 { border-color: #30363d; } .meta,.foot { color: #8b949e; }
    td.name { color: #8b949e; }
  }
</style>
</head>
<body>
<header>
  <h1>PhotoFacility — Diagnostica</h1>
  <span class="verdict"><?= $h($report['verdict']) ?></span>
</header>
<div class="summary">
  <span style="color:<?= $colors['ok'] ?>">● <?= (int) $report['summary']['ok'] ?> OK</span> &nbsp;
  <span style="color:<?= $colors['warn'] ?>">▲ <?= (int) $report['summary']['warn'] ?> avvisi</span> &nbsp;
  <span style="color:<?= $colors['fail'] ?>">✖ <?= (int) $report['summary']['fail'] ?> critici</span>
</div>
<?php foreach ($byCat as $cat => $items): ?>
<h2><?= $h((string) $cat) ?></h2>
<table>
  <?php foreach ($items as $c): $st = $c['status']; ?>
  <tr>
    <td class="status" style="color:<?= $colors[$st] ?>"><?= $dots[$st] ?></td>
    <td class="name"><?= $h($c['name']) ?></td>
    <td><?= $h($c['detail']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endforeach; ?>
<p class="foot">Generato <?= $h($report['generated_utc']) ?> · read-only · nessun segreto esposto</p>
</body>
</html>
