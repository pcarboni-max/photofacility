<?php

declare(strict_types=1);

namespace PhotoFacility;

use PhotoFacility\Database\Database;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Ingest\ExifExtractor;
use PhotoFacility\Ingest\Ingestor;
use PhotoFacility\Ingest\IntegrityValidator;
use PhotoFacility\S3\S3Client;
use PhotoFacility\S3\Uploader;
use PhotoFacility\Support\Lock;
use PhotoFacility\Support\Logger;

/**
 * Composition root + orchestrazione di un singolo ciclo di ingestion.
 *
 * Un "tick" (invocato dal cron) esegue in sequenza:
 *   1. acquisizione lock (esce subito se un altro tick è attivo)
 *   2. scansione ingestion -> ammissione alla coda
 *   3. svuotamento della coda S3 (con retry/backoff)
 *   4. pulizia dello staging orfano
 * Tutto entro un budget di tempo, per non sforare i limiti dell'hosting.
 */
final class App
{
    private Config $config;
    private Logger $log;
    private Database $db;
    private PhotoRepository $repo;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->config->ensureDirectories();
        $this->log = new Logger($config->logFile, $config->debug);
        $this->db = new Database($config->dbPath);
        $this->repo = new PhotoRepository($this->db->pdo());

        // Auto-migrazione: su hosting senza SSH non c'è una shell per lanciare
        // il migrate a mano. Al primo avvio (o se il DB è stato azzerato) lo
        // schema viene creato in modo idempotente. Costo per tick: una query.
        $this->ensureSchema();
    }

    /** Applica lo schema esplicitamente (usato da bin/migrate.php). Idempotente. */
    public function migrate(string $schemaFile): void
    {
        $this->db->migrate($schemaFile);
        $this->log->info('Schema DB applicato.');
    }

    /** Crea lo schema se la tabella principale non esiste ancora. */
    private function ensureSchema(): void
    {
        $exists = $this->db->pdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='photos'")
            ->fetchColumn();

        if ($exists === false) {
            $schemaFile = $this->config->baseDir . '/db/schema.sql';
            $this->db->migrate($schemaFile);
            $this->log->info('Schema DB creato automaticamente al primo avvio.');
        }
    }

    /**
     * @return array{skipped_locked?:bool, ingest?:array, upload?:array, elapsed?:float}
     */
    public function runTick(): array
    {
        $start = microtime(true);
        $deadline = $start + $this->config->maxRuntimeSeconds;
        $withinBudget = static fn (): bool => microtime(true) < $deadline;

        $lock = new Lock($this->config->lockFile);
        if (!$lock->acquire()) {
            $this->log->debug('Tick saltato: un altro ciclo è in esecuzione.');
            return ['skipped_locked' => true];
        }

        try {
            // 1. ingestion
            $ingestor = new Ingestor(
                $this->config,
                $this->repo,
                new IntegrityValidator(),
                new ExifExtractor(),
                $this->log,
            );
            $ingestResult = $ingestor->run($withinBudget);

            // 2. coda S3
            $client = new S3Client(
                region: $this->config->s3Region,
                accessKey: $this->config->awsAccessKey,
                secretKey: $this->config->awsSecretKey,
                endpoint: $this->config->s3Endpoint,
                usePathStyle: $this->config->s3PathStyle,
                sessionToken: $this->config->awsSessionToken,
            );
            $uploader = new Uploader($client, $this->repo, $this->config, $this->log);

            $rows = $this->repo->fetchUploadable($this->config->uploadBatchSize, gmdate('Y-m-d H:i:s'));
            $uploadResult = $uploader->processQueue($rows, $withinBudget);

            // 3. pulizia staging orfano
            $this->cleanupStaging();

            $elapsed = round(microtime(true) - $start, 2);
            $this->log->info('Tick completato', [
                'ingest' => $ingestResult, 'upload' => $uploadResult, 'elapsed_s' => $elapsed,
            ]);

            return ['ingest' => $ingestResult, 'upload' => $uploadResult, 'elapsed' => $elapsed];
        } finally {
            $lock->release();
        }
    }

    public function statusReport(): array
    {
        return $this->repo->statusCounts();
    }

    /**
     * Rimuove i file .part orfani in incoming e i file in failed/ più vecchi
     * della retention, per non saturare la quota disco dell'hosting.
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
    }
}
