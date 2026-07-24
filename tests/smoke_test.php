<?php

declare(strict_types=1);

/**
 * Test end-to-end senza framework, eseguibile con:
 *   php tests/smoke_test.php
 *
 * Richiede un mock S3 in ascolto su 127.0.0.1:8899 (avviato da run_tests.sh).
 * Esercita: validazione integrità, dedup, EXIF/partizione, firma SigV4,
 * streaming cURL, verifica ETag, transizioni di stato nel DB.
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

// --- ambiente di test isolato ---
$work = sys_get_temp_dir() . '/pf_test_' . getmypid();
@mkdir($work, 0775, true);
@mkdir("$work/staging/incoming", 0775, true);
@mkdir("$work/db", 0775, true);

putenv('STAGING_DIR=' . $work . '/staging');
putenv('DB_PATH=' . $work . '/db/test.sqlite');
putenv('LOG_FILE=' . $work . '/db/test.log');
putenv('LOCK_FILE=' . $work . '/db/test.lock');
putenv('S3_BUCKET=test-bucket');
putenv('S3_REGION=eu-south-1');
putenv('AWS_ACCESS_KEY_ID=AKIATEST');
putenv('AWS_SECRET_ACCESS_KEY=secrettest');
putenv('S3_ENDPOINT=http://127.0.0.1:8899');
putenv('S3_PATH_STYLE=true');
putenv('QUIESCENCE_SECONDS=0');   // niente attesa nei test
putenv('DELETE_AFTER_UPLOAD=true');

Env::load($work . '/.env-nonexistent'); // forza il fallback su getenv()

$config = Config::fromEnv($baseDir);
// NOTA: nessuna chiamata a migrate(): lo schema deve crearsi da solo.
$app = new App($config);
$assert(($app->statusReport() === []), 'Auto-migrazione: schema creato al primo avvio (DB vuoto)');

fwrite(STDOUT, "\n== 1. IntegrityValidator ==\n");
$validator = new IntegrityValidator();

// JPEG valido (header FF D8 FF + payload)
$jpeg = "$work/staging/incoming/foto1.jpg";
file_put_contents($jpeg, "\xFF\xD8\xFF\xE0" . str_repeat("CANON-EOS-DATA", 500));
$v = $validator->validate($jpeg);
$assert($v['ok'] === true, 'JPEG valido riconosciuto');
$assert($v['mime'] === 'image/jpeg', 'MIME JPEG corretto');
$assert(strlen((string) $v['sha256']) === 64, 'SHA-256 calcolato');

// file con estensione foto ma contenuto spazzatura -> firma non riconosciuta
$junk = "$work/junk.jpg";
file_put_contents($junk, 'NON-SONO-UNA-FOTO');
$vj = $validator->validate($junk);
$assert($vj['ok'] === false, 'File con firma non valida rifiutato');

// CR3 (ISO-BMFF, brand crx )
$cr3 = "$work/test.cr3";
file_put_contents($cr3, "\x00\x00\x00\x18" . 'ftyp' . 'crx ' . str_repeat("\x00", 100));
$vc = $validator->validate($cr3);
$assert($vc['mime'] === 'image/x-canon-cr3', 'Canon CR3 riconosciuto');

fwrite(STDOUT, "\n== 2. Tick end-to-end (ingest + upload verso mock S3) ==\n");
$result = $app->runTick();
$assert(($result['ingest']['admitted'] ?? 0) === 1, 'Una foto ammessa alla coda');
$assert(($result['upload']['uploaded'] ?? 0) === 1, 'Una foto caricata su S3 (mock)');

$counts = $app->statusReport();
$assert(($counts['UPLOADED_S3'] ?? 0) === 1, 'Stato DB = UPLOADED_S3');
$assert(!is_file($jpeg), 'File staging cancellato dopo conferma upload');

fwrite(STDOUT, "\n== 3. Idempotenza / deduplica ==\n");
// stessa foto (stesso contenuto) di nuovo
file_put_contents("$work/staging/incoming/foto1_ricaricata.jpg", "\xFF\xD8\xFF\xE0" . str_repeat("CANON-EOS-DATA", 500));
$result2 = $app->runTick();
$assert(($result2['ingest']['skipped'] ?? 0) === 1, 'Duplicato riconosciuto e saltato');
$counts2 = $app->statusReport();
$assert(($counts2['UPLOADED_S3'] ?? 0) === 1, 'Nessun duplicato in DB');

fwrite(STDOUT, "\n== 4. Lock anti-sovrapposizione ==\n");
$lock = new \PhotoFacility\Support\Lock($config->lockFile);
$assert($lock->acquire() === true, 'Lock acquisito');
$r3 = $app->runTick();
$assert(($r3['skipped_locked'] ?? false) === true, 'Tick saltato mentre il lock è attivo');
$lock->release();

// cleanup
exec('rm -rf ' . escapeshellarg($work));

fwrite(STDOUT, "\n");
if ($failures === 0) {
    fwrite(STDOUT, "\033[32m== TUTTI I TEST PASSATI ==\033[0m\n");
    exit(0);
}
fwrite(STDOUT, "\033[31m== {$failures} TEST FALLITI ==\033[0m\n");
exit(1);
