<?php

declare(strict_types=1);

namespace PhotoFacility;

use PhotoFacility\Database\Database;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Ingest\ExifExtractor;
use PhotoFacility\Ingest\Ingestor;
use PhotoFacility\Ingest\IntegrityValidator;
use PhotoFacility\S3\S3Client;
use PhotoFacility\S3\StorageTarget;
use PhotoFacility\S3\Uploader;
use PhotoFacility\Support\Lock;
use PhotoFacility\Support\Logger;
use PhotoFacility\Support\Notifier;

/**
 * Composition root + orchestrazione dell'ingestion.
 *
 * Due modalità di esecuzione:
 *   - runTick(): un singolo passaggio (usato dai test e dall'endpoint web).
 *   - runLoop(): "loop-within-cron" — acquisisce il lock UNA volta e cicla per
 *     ~55 s facendo micro-batch, così un'invocazione al minuto del cron tiene
 *     quasi sempre un processo vivo, abbattendo la latenza a pochi secondi.
 *
 * Fuso orari: al bootstrap fissiamo UTC per coerenza tra log e timestamp DB;
 * il raggruppamento per giorno (partition_date) usa esplicitamente il fuso "di
 * casa" (config->timezone) dentro ExifExtractor.
 */
final class App
{
    private Config $config;
    private Logger $log;
    private Database $db;
    private PhotoRepository $repo;
    private Notifier $notifier;

    public function __construct(Config $config)
    {
        // Bootstrap fuso: tutti i timestamp macchina in UTC (coerenti col DB).
        date_default_timezone_set('UTC');

        $this->config = $config;
        $this->config->ensureDirectories();
        $this->log = new Logger($config->logFile, $config->debug);
        $this->db = new Database($config->dbPath);
        $this->repo = new PhotoRepository($this->db->pdo());
        $this->notifier = new Notifier(
            $config->alertWebhookUrl,
            $config->alertEmail,
            $this->log,
            dirname($config->healthFile),
        );

        $this->ensureSchema();
    }

    /** Applica lo schema esplicitamente (usato da bin/migrate.php). Idempotente. */
    public function migrate(string $schemaFile): void
    {
        $this->db->migrate($schemaFile);
        $this->log->info('Schema DB applicato.');
    }

    private function ensureSchema(): void
    {
        $exists = $this->db->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='photos'")
            ->fetchColumn();

        if ($exists === false) {
            $this->db->migrate($this->config->baseDir . '/db/schema.sql');
            $this->log->info('Schema DB creato automaticamente al primo avvio.');
        }
    }

    // ---------------------------------------------------------------------
    // Esecuzione
    // ---------------------------------------------------------------------

    /**
     * Loop-within-cron: un'invocazione = un ciclo di ~loopDurationSeconds.
     * @return array<string,mixed>
     */
    public function runLoop(): array
    {
        $start = microtime(true);
        $deadline = $start + $this->config->loopDurationSeconds;

        $lock = new Lock($this->config->lockFile);
        if (!$lock->acquire()) {
            $this->log->debug('Loop saltato: un altro ciclo è in esecuzione.');
            return ['skipped_locked' => true];
        }

        try {
            $this->config->requireS3();
            [$ingestor, $uploader, $client] = $this->buildWorkers();
            $this->reapStuckUploads($client);

            $totals = ['admitted' => 0, 'skipped' => 0, 'failed' => 0, 'uploaded' => 0, 'iterations' => 0, 'disk_low' => false];

            while (microtime(true) < $deadline) {
                $pass = $this->runPass($ingestor, $uploader, $deadline);
                $totals['admitted'] += $pass['ingest']['admitted'];
                $totals['skipped'] += $pass['ingest']['skipped'];
                $totals['failed'] += $pass['ingest']['failed'] + $pass['upload']['failed'];
                $totals['uploaded'] += $pass['upload']['uploaded'];
                $totals['iterations']++;
                $totals['disk_low'] = $totals['disk_low'] || $pass['ingest']['disk_low'];

                if (microtime(true) >= $deadline) {
                    break;
                }
                // sleep adattivo: reattivo se c'è lavoro, riposo se a vuoto.
                $work = $pass['ingest']['admitted'] + $pass['upload']['uploaded'] + $pass['upload']['failed'];
                usleep($work > 0 ? 200_000 : 3_000_000);
            }

            $this->cleanupStaging();
            $this->postCycle($totals);

            $totals['elapsed'] = round(microtime(true) - $start, 2);
            $this->log->info('Loop completato', $totals);
            return $totals;
        } finally {
            $lock->release();
        }
    }

    /**
     * Un singolo passaggio con lock (usato da test / endpoint web fallback).
     * @return array{skipped_locked?:bool, ingest?:array, upload?:array, elapsed?:float}
     */
    public function runTick(): array
    {
        $start = microtime(true);
        $deadline = $start + $this->config->maxRuntimeSeconds;

        $lock = new Lock($this->config->lockFile);
        if (!$lock->acquire()) {
            $this->log->debug('Tick saltato: un altro ciclo è in esecuzione.');
            return ['skipped_locked' => true];
        }

        try {
            $this->config->requireS3();
            [$ingestor, $uploader, $client] = $this->buildWorkers();
            $this->reapStuckUploads($client);

            $pass = $this->runPass($ingestor, $uploader, $deadline);
            $this->cleanupStaging();
            $this->postCycle([
                'admitted' => $pass['ingest']['admitted'],
                'uploaded' => $pass['upload']['uploaded'],
                'disk_low' => $pass['ingest']['disk_low'],
            ]);

            $elapsed = round(microtime(true) - $start, 2);
            $this->log->info('Tick completato', ['ingest' => $pass['ingest'], 'upload' => $pass['upload'], 'elapsed_s' => $elapsed]);
            return ['ingest' => $pass['ingest'], 'upload' => $pass['upload'], 'elapsed' => $elapsed];
        } finally {
            $lock->release();
        }
    }

    /** @return array{ingest:array, upload:array} */
    private function runPass(Ingestor $ingestor, Uploader $uploader, float $deadline): array
    {
        $withinBudget = static fn (): bool => microtime(true) < $deadline;
        $ingest = $ingestor->run($withinBudget);
        $rows = $this->repo->fetchUploadable($this->config->uploadBatchSize, gmdate('Y-m-d H:i:s'));
        $upload = $uploader->processQueue($rows, $deadline);
        return ['ingest' => $ingest, 'upload' => $upload];
    }

    /** @return array{0:Ingestor,1:Uploader,2:S3Client} */
    private function buildWorkers(): array
    {
        $client = new S3Client(
            region: $this->config->s3Region,
            accessKey: (string) $this->config->awsAccessKey,
            secretKey: (string) $this->config->awsSecretKey,
            endpoint: $this->config->s3Endpoint,
            usePathStyle: $this->config->s3PathStyle,
            sessionToken: $this->config->awsSessionToken,
        );
        $ingestor = new Ingestor(
            $this->config,
            $this->repo,
            new IntegrityValidator(),
            new ExifExtractor($this->config->timezone),
            $this->log,
        );
        $uploader = new Uploader($client, $this->repo, $this->config, $this->log, $this->notifier);
        return [$ingestor, $uploader, $client];
    }

    /**
     * Recupera i record bloccati in UPLOADING_S3 (crash a metà upload). Se
     * l'oggetto è già su S3 con la dimensione attesa lo consideriamo caricato,
     * altrimenti lo rimettiamo in coda (il re-PUT è idempotente).
     */
    private function reapStuckUploads(StorageTarget $client): void
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->config->reaperStuckMinutes * 60);
        $stuck = $this->repo->fetchStuckUploading($cutoff);
        if ($stuck === []) {
            return;
        }
        $this->log->warn('Reaper: record bloccati in UPLOADING_S3', ['count' => count($stuck)]);

        foreach ($stuck as $row) {
            $id = (int) $row['id'];
            $head = $client->headObject((string) $row['s3_bucket'], (string) $row['s3_key']);

            if ($head['exists'] && ($head['size'] === null || $head['size'] === (int) $row['size_bytes'])) {
                $this->repo->markUploaded($id, '', gmdate('Y-m-d H:i:s'));
                $this->repo->logEvent($id, 'uploaded', 'recuperato dal reaper (HeadObject conferma)');
                if ($this->config->deleteAfterUpload && is_file((string) $row['staging_path'])) {
                    @unlink((string) $row['staging_path']);
                }
            } else {
                $this->repo->resetToPending($id, 'reaper: riportato in coda dopo crash upload');
                $this->repo->logEvent($id, 'retry', 'reaper: UPLOADING_S3 orfano → PENDING_S3');
            }
        }
    }

    // ---------------------------------------------------------------------
    // Osservabilità
    // ---------------------------------------------------------------------

    /** Scrive health.json e valuta gli alert. */
    private function postCycle(array $totals): void
    {
        $counts = $this->repo->statusCounts();
        $backlog = $this->repo->backlogStats();
        $stagingMb = $this->stagingSizeMb();
        $freeMb = $this->diskFreeMb();

        $health = [
            'last_run_utc' => gmdate('c'),
            'ok' => true,
            'status_counts' => $counts,
            'backlog' => $backlog,
            'staging_mb' => $stagingMb,
            'disk_free_mb' => $freeMb,
            'last_cycle' => $totals,
        ];
        @file_put_contents(
            $this->config->healthFile,
            json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        // --- Alert ---
        if (($totals['disk_low'] ?? false) === true || ($freeMb !== null && $freeMb < $this->config->diskMinFreeMb)) {
            $this->notifier->alert('disk_low', 'Disco quasi pieno', "Spazio libero: {$freeMb} MB. Ingestione sospesa finché la coda S3 non libera spazio.");
        }
        if ($backlog['count'] > $this->config->backlogAlertThreshold) {
            $this->notifier->alert('backlog_size', 'Backlog upload elevato', "PENDING_S3: {$backlog['count']} foto in coda.");
        }
        $ageMin = $backlog['oldest_age_seconds'] !== null ? (int) round($backlog['oldest_age_seconds'] / 60) : 0;
        if ($ageMin > $this->config->backlogAgeAlertMinutes) {
            $this->notifier->alert('backlog_age', 'Backlog upload invecchiato', "La foto più vecchia in coda attende da {$ageMin} minuti (S3 irraggiungibile?).");
        }
        if ($stagingMb !== null && $stagingMb > $this->config->stagingAlertMb) {
            $this->notifier->alert('staging_size', 'Staging locale grande', "Staging: {$stagingMb} MB.");
        }
    }

    private function stagingSizeMb(): ?int
    {
        $bytes = 0;
        foreach ([$this->config->incomingDir, $this->config->processingDir, $this->config->failedDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $e) {
                $p = $dir . '/' . $e;
                if (is_file($p)) {
                    $bytes += (int) filesize($p);
                }
            }
        }
        return (int) round($bytes / (1024 * 1024));
    }

    private function diskFreeMb(): ?int
    {
        $free = @disk_free_space($this->config->incomingDir);
        return $free === false ? null : (int) round($free / (1024 * 1024));
    }

    public function statusReport(): array
    {
        return $this->repo->statusCounts();
    }

    public function health(): array
    {
        return [
            'status_counts' => $this->repo->statusCounts(),
            'backlog' => $this->repo->backlogStats(),
            'staging_mb' => $this->stagingSizeMb(),
            'disk_free_mb' => $this->diskFreeMb(),
        ];
    }

    // ---------------------------------------------------------------------
    // Operazioni (bin/requeue.php, bin/maintain.php)
    // ---------------------------------------------------------------------

    /** @param list<string> $statuses */
    public function requeue(array $statuses): int
    {
        $n = $this->repo->requeueTerminal($statuses);
        $this->log->info('Requeue dalla dead-letter', ['statuses' => $statuses, 'affected' => $n]);
        return $n;
    }

    /** @return array<string,mixed> */
    public function maintain(): array
    {
        $pruned = $this->repo->pruneEvents($this->config->eventsRetentionDays);
        $this->db->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $this->db->pdo()->exec('VACUUM');
        $result = ['pruned_events' => $pruned, 'vacuumed' => true];
        $this->log->info('Manutenzione DB', $result);
        return $result;
    }

    /**
     * Backup del DB SQLite su S3. I byte delle foto sono già su S3, ma i
     * METADATI e i TAG (Fase 2) vivono solo nel DB: qui li mettiamo al sicuro.
     * @return string la chiave S3 del backup.
     */
    public function backupDbToS3(): string
    {
        $this->config->requireS3();

        // consolida il WAL nel file principale, poi copia atomica per lo snapshot.
        $this->db->pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $tmp = $this->config->baseDir . '/db/backup_' . gmdate('Ymd_His') . '.sqlite';
        if (!@copy($this->config->dbPath, $tmp)) {
            throw new \RuntimeException('Copia dello snapshot DB fallita.');
        }

        try {
            $sha256 = hash_file('sha256', $tmp) ?: '';
            $md5Hex = hash_file('md5', $tmp) ?: '';
            $md5B64 = base64_encode((string) hex2bin($md5Hex));

            $key = ($this->config->s3Prefix !== '' ? rtrim($this->config->s3Prefix, '/') . '/' : '')
                . 'backups/db/' . gmdate('Y/m/d/His') . '.sqlite';

            $client = new S3Client(
                region: $this->config->s3Region,
                accessKey: (string) $this->config->awsAccessKey,
                secretKey: (string) $this->config->awsSecretKey,
                endpoint: $this->config->s3Endpoint,
                usePathStyle: $this->config->s3PathStyle,
                sessionToken: $this->config->awsSessionToken,
            );
            $res = $client->putObject((string) $this->config->s3Bucket, $key, $tmp, $sha256, $md5B64, 'application/x-sqlite3', [], 120);
            if (!$res['ok']) {
                throw new \RuntimeException('Upload backup DB fallito: ' . $res['error']);
            }
            $this->log->info('Backup DB su S3 OK', ['key' => $key]);
            return $key;
        } finally {
            @unlink($tmp);
        }
    }

    // ---------------------------------------------------------------------
    // Pulizia staging
    // ---------------------------------------------------------------------

    /**
     * Rimuove: file scaduti in incoming/ e failed/ (oltre la retention), e gli
     * ORFANI in processing/ (file non più referenziati da un record attivo —
     * es. resti di QUARANTINE con DELETE_AFTER_UPLOAD=false, o leak di unlink).
     */
    private function cleanupStaging(): void
    {
        $ttl = $this->config->stagingRetentionHours * 3600;
        $now = time();

        foreach ([$this->config->incomingDir, $this->config->failedDir] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (!is_file($path)) {
                    continue;
                }
                $mtime = filemtime($path);
                if ($mtime !== false && ($now - $mtime) > $ttl) {
                    @unlink($path);
                    $this->log->debug('Rimosso file staging scaduto', ['path' => $path]);
                }
            }
        }

        // orfani in processing/: non referenziati da un record attivo e scaduti.
        $active = array_flip($this->repo->activeStagingPaths());
        $procDir = $this->config->processingDir;
        if (is_dir($procDir)) {
            foreach (scandir($procDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $procDir . '/' . $entry;
                if (!is_file($path) || isset($active[$path])) {
                    continue;
                }
                $mtime = filemtime($path);
                if ($mtime !== false && ($now - $mtime) > $ttl) {
                    @unlink($path);
                    $this->log->debug('Rimosso orfano in processing/', ['path' => $path]);
                }
            }
        }
    }
}
