<?php

declare(strict_types=1);

/**
 * Test end-to-end senza framework, eseguibile con:
 *   php tests/smoke_test.php
 *
 * Richiede un mock S3 in ascolto su 127.0.0.1:8899 (avviato da run_tests.sh).
 * Esercita: validazione integrità (incl. trailer FF D9), dedup, EXIF/partizione,
 * firma SigV4, streaming cURL, verifica ETag, reaper UPLOADING_S3, lock.
 */

use PhotoFacility\App;
use PhotoFacility\Config;
use PhotoFacility\Ingest\IntegrityValidator;
use PhotoFacility\Support\Env;

$baseDir = dirname(__DIR__);
require $baseDir . '/src/autoload.php';

$failures = 0;
$assert = function (bool $cond, string $label) use (&$failures): void {
    if ($cond) {
        fwrite(STDOUT, "  \033[32mPASS\033[0m {$label}\n");
    } else {
        fwrite(STDOUT, "  \033[31mFAIL\033[0m {$label}\n");
        $failures++;
    }
};

// JPEG "valido": header FF D8 FF + payload + trailer EOI FF D9
$makeJpeg = static fn (string $payload): string => "\xFF\xD8\xFF\xE0" . $payload . "\xFF\xD9";

// --- ambiente di test isolato ---
$work = sys_get_temp_dir() . '/pf_test_' . getmypid();
@mkdir($work, 0775, true);
@mkdir("$work/staging/incoming", 0775, true);
@mkdir("$work/staging/processing", 0775, true);
@mkdir("$work/db", 0775, true);

putenv('STAGING_DIR=' . $work . '/staging');
putenv('DB_PATH=' . $work . '/db/test.sqlite');
putenv('LOG_FILE=' . $work . '/db/test.log');
putenv('LOCK_FILE=' . $work . '/db/test.lock');
putenv('HEALTH_FILE=' . $work . '/db/health.json');
putenv('S3_BUCKET=test-bucket');
putenv('S3_REGION=eu-south-1');
putenv('AWS_ACCESS_KEY_ID=AKIATEST');
putenv('AWS_SECRET_ACCESS_KEY=secrettest');
putenv('S3_ENDPOINT=http://127.0.0.1:8899');
putenv('S3_PATH_STYLE=true');
putenv('QUIESCENCE_SECONDS=0');
putenv('DELETE_AFTER_UPLOAD=true');

Env::load($work . '/.env-nonexistent'); // forza il fallback su getenv()

$config = Config::fromEnv($baseDir);
$app = new App($config); // auto-migrazione nel costruttore
$assert(($app->statusReport() === []), 'Auto-migrazione: schema creato al primo avvio');

fwrite(STDOUT, "\n== 1. IntegrityValidator ==\n");
$validator = new IntegrityValidator();

$jpeg = "$work/staging/incoming/foto1.jpg";
file_put_contents($jpeg, $makeJpeg(str_repeat('CANON-EOS-DATA', 500)));
$v = $validator->validate($jpeg);
$assert($v['ok'] === true, 'JPEG completo (con FF D9) riconosciuto');
$assert($v['mime'] === 'image/jpeg', 'MIME JPEG corretto');
$assert(strlen((string) $v['sha256']) === 64, 'SHA-256 calcolato');

// JPEG troncato: header valido ma SENZA trailer FF D9
$trunc = "$work/troncato.jpg";
file_put_contents($trunc, "\xFF\xD8\xFF\xE0" . str_repeat('X', 2000));
$vt = $validator->validate($trunc);
$assert($vt['ok'] === false, 'JPEG troncato (senza FF D9) RIFIUTATO');

$junk = "$work/junk.jpg";
file_put_contents($junk, 'NON-SONO-UNA-FOTO');
$assert($validator->validate($junk)['ok'] === false, 'File con firma non valida rifiutato');

$cr3 = "$work/test.cr3";
file_put_contents($cr3, "\x00\x00\x00\x18" . 'ftyp' . 'crx ' . str_repeat("\x00", 100));
$assert($validator->validate($cr3)['mime'] === 'image/x-canon-cr3', 'Canon CR3 riconosciuto');

fwrite(STDOUT, "\n== 2. Tick end-to-end (ingest + upload verso mock S3) ==\n");
$result = $app->runTick();
$assert(($result['ingest']['admitted'] ?? 0) === 1, 'Una foto ammessa alla coda');
$assert(($result['upload']['uploaded'] ?? 0) === 1, 'Una foto caricata su S3 (mock)');
$counts = $app->statusReport();
$assert(($counts['UPLOADED_S3'] ?? 0) === 1, 'Stato DB = UPLOADED_S3');
$assert(!is_file($jpeg), 'File staging cancellato dopo conferma upload');
$assert(is_file("$work/db/health.json"), 'health.json scritto');

// M1: metadati x-amz-meta-* firmati e ricevuti dal mock
$hdrFile = getenv('MOCK_S3_HEADERS');
$meta = $hdrFile && is_file($hdrFile) ? json_decode((string) file_get_contents($hdrFile), true) : [];
$assert(($meta['x-amz-meta-sha256'] ?? '') === $v['sha256'], 'M1: header x-amz-meta-sha256 corretto');
$assert(($meta['x-amz-meta-original-filename'] ?? '') === 'foto1.jpg', 'M1: header x-amz-meta-original-filename presente');

fwrite(STDOUT, "\n== 3. Idempotenza / deduplica ==\n");
file_put_contents("$work/staging/incoming/foto1_bis.jpg", $makeJpeg(str_repeat('CANON-EOS-DATA', 500)));
$r2 = $app->runTick();
$assert(($r2['ingest']['skipped'] ?? 0) === 1, 'Duplicato riconosciuto e saltato');
$assert(($app->statusReport()['UPLOADED_S3'] ?? 0) === 1, 'Nessun duplicato in DB');

fwrite(STDOUT, "\n== 4. Reaper UPLOADING_S3 (recupero dopo crash) ==\n");
// inseriamo a mano un record bloccato in UPLOADING_S3 con updated_at vecchio
$pdo = new PDO('sqlite:' . $work . '/db/test.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stuckFile = "$work/staging/processing/stuck.jpg";
file_put_contents($stuckFile, $makeJpeg(str_repeat('STUCK', 300)));
$sha = hash_file('sha256', $stuckFile);
$md5 = hash_file('md5', $stuckFile);
$pdo->prepare(
    "INSERT INTO photos (uuid, original_filename, status, size_bytes, checksum_sha256, checksum_md5,
        mime_detected, staging_path, s3_bucket, s3_key, partition_date, received_at, updated_at)
     VALUES ('stuck-uuid','stuck.jpg','UPLOADING_S3',:sz,:sha,:md5,'image/jpeg',:path,
        'test-bucket','2020/01/01/stuck.jpg','2020-01-01','2020-01-01 00:00:00','2020-01-01 00:00:00')"
)->execute([':sz' => filesize($stuckFile), ':sha' => $sha, ':md5' => $md5, ':path' => $stuckFile]);

$app->runTick(); // il reaper gira a inizio tick, poi il drain carica
$row = $pdo->query("SELECT status FROM photos WHERE uuid='stuck-uuid'")->fetch(PDO::FETCH_ASSOC);
$assert(($row['status'] ?? '') === 'UPLOADED_S3', 'Record orfano recuperato dal reaper e caricato');

fwrite(STDOUT, "\n== 5. Requeue dalla dead-letter ==\n");
$pdo->exec("UPDATE photos SET status='QUARANTINE' WHERE uuid='stuck-uuid'");
$n = $app->requeue(['QUARANTINE']);
$assert($n === 1, 'Requeue riporta 1 foto in coda');
$assert(($pdo->query("SELECT status FROM photos WHERE uuid='stuck-uuid'")->fetchColumn()) === 'PENDING_S3', 'Stato tornato PENDING_S3');

fwrite(STDOUT, "\n== 5b. Recupero da reinvio (R11) ==\n");
// foto1 è UPLOADED_S3: la forziamo in QUARANTINE, poi la camera "reinvia" gli
// stessi byte → deve essere RECUPERATA (non scartata come duplicato).
$pdo->exec("UPDATE photos SET status='QUARANTINE' WHERE original_filename='foto1.jpg'");
file_put_contents("$work/staging/incoming/foto1_reinvio.jpg", $makeJpeg(str_repeat('CANON-EOS-DATA', 500)));
$rr = $app->runTick();
$assert(($rr['ingest']['admitted'] ?? 0) === 1, 'Reinvio da QUARANTINE = recuperato (admitted), non skipped');
$rowF1 = $pdo->query("SELECT status, COUNT(*) OVER () AS n FROM photos WHERE original_filename='foto1.jpg'")->fetch(PDO::FETCH_ASSOC);
$assert(($rowF1['status'] ?? '') === 'UPLOADED_S3', 'Foto recuperata e ricaricata su S3');
$dupCount = (int) $pdo->query("SELECT COUNT(*) FROM photos WHERE checksum_sha256='" . $v['sha256'] . "'")->fetchColumn();
$assert($dupCount === 1, 'Nessuna riga duplicata dopo il recupero');

fwrite(STDOUT, "\n== 6. Lock anti-sovrapposizione ==\n");
$lock = new \PhotoFacility\Support\Lock($config->lockFile);
$assert($lock->acquire() === true, 'Lock acquisito');
$assert(($app->runTick()['skipped_locked'] ?? false) === true, 'Tick saltato mentre il lock è attivo');
$lock->release();

fwrite(STDOUT, "\n== 6b. Diagnostica (HealthCheck) ==\n");
$report = (new \PhotoFacility\Health\HealthCheck($config))->run();
$assert($report['verdict'] !== 'CRITICAL', 'Verdetto diagnostica non CRITICAL (' . $report['verdict'] . ')');
$byName = [];
foreach ($report['checks'] as $c) {
    $byName[$c['category'] . '/' . $c['name']] = $c['status'];
}
$assert(($byName['S3/Connettività'] ?? '') === 'ok', 'Check S3 connettività = ok (mock raggiungibile)');
$assert(($byName['Database/Integrità (quick_check)'] ?? '') === 'ok', 'Check integrità DB = ok');
$assert(($byName['Database/Schema'] ?? '') === 'ok', 'Check schema DB = ok');

fwrite(STDOUT, "\n== 7. Validazione HOME_TZ (R5) ==\n");
putenv('HOME_TZ=Foo/Bar'); // zona inesistente
$threw = false;
try {
    Config::fromEnv($baseDir);
} catch (\RuntimeException) {
    $threw = true;
}
$assert($threw, 'HOME_TZ non valido fa fallire l\'avvio con errore chiaro');
putenv('HOME_TZ'); // ripristina (torna al default)

exec('rm -rf ' . escapeshellarg($work));

fwrite(STDOUT, "\n");
if ($failures === 0) {
    fwrite(STDOUT, "\033[32m== TUTTI I TEST PASSATI ==\033[0m\n");
    exit(0);
}
fwrite(STDOUT, "\033[31m== {$failures} TEST FALLITI ==\033[0m\n");
exit(1);
