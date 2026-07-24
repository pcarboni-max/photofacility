<?php

declare(strict_types=1);

namespace PhotoFacility;

use PhotoFacility\Support\Env;

/**
 * Configurazione centralizzata, popolata da variabili d'ambiente / .env.
 */
final class Config
{
    public function __construct(
        // percorsi
        public readonly string $baseDir,
        public readonly string $incomingDir,
        public readonly string $processingDir,
        public readonly string $failedDir,
        public readonly string $dbPath,
        public readonly string $logFile,
        public readonly string $lockFile,

        // S3
        public readonly string $s3Bucket,
        public readonly string $s3Region,
        public readonly string $s3Prefix,
        public readonly string $awsAccessKey,
        public readonly string $awsSecretKey,
        public readonly ?string $awsSessionToken,
        public readonly ?string $s3Endpoint,
        public readonly bool $s3PathStyle,

        // comportamento
        public readonly int $quiescenceSeconds,
        public readonly int $maxRuntimeSeconds,
        public readonly int $uploadBatchSize,
        public readonly int $maxUploadAttempts,
        public readonly int $retryBaseBackoff,
        public readonly int $retryMaxBackoff,
        public readonly bool $deleteAfterUpload,
        public readonly int $stagingRetentionHours,
        public readonly ?string $cronToken,
        public readonly bool $debug,
    ) {
    }

    public static function fromEnv(string $baseDir): self
    {
        $staging = Env::get('STAGING_DIR', $baseDir . '/staging') ?? $baseDir . '/staging';

        return new self(
            baseDir: $baseDir,
            incomingDir: Env::get('INCOMING_DIR', $staging . '/incoming') ?? $staging . '/incoming',
            processingDir: Env::get('PROCESSING_DIR', $staging . '/processing') ?? $staging . '/processing',
            failedDir: Env::get('FAILED_DIR', $staging . '/failed') ?? $staging . '/failed',
            dbPath: Env::get('DB_PATH', $baseDir . '/db/photofacility.sqlite') ?? $baseDir . '/db/photofacility.sqlite',
            logFile: Env::get('LOG_FILE', $baseDir . '/db/photofacility.log') ?? $baseDir . '/db/photofacility.log',
            lockFile: Env::get('LOCK_FILE', $baseDir . '/db/ingest.lock') ?? $baseDir . '/db/ingest.lock',

            s3Bucket: Env::required('S3_BUCKET'),
            s3Region: Env::get('S3_REGION', 'eu-south-1') ?? 'eu-south-1',
            s3Prefix: Env::get('S3_PREFIX', '') ?? '',
            awsAccessKey: Env::required('AWS_ACCESS_KEY_ID'),
            awsSecretKey: Env::required('AWS_SECRET_ACCESS_KEY'),
            awsSessionToken: Env::get('AWS_SESSION_TOKEN'),
            s3Endpoint: Env::get('S3_ENDPOINT'),
            s3PathStyle: Env::bool('S3_PATH_STYLE', false),

            quiescenceSeconds: Env::int('QUIESCENCE_SECONDS', 20),
            maxRuntimeSeconds: Env::int('MAX_RUNTIME_SECONDS', 50),
            uploadBatchSize: Env::int('UPLOAD_BATCH_SIZE', 20),
            maxUploadAttempts: Env::int('MAX_UPLOAD_ATTEMPTS', 8),
            retryBaseBackoff: Env::int('RETRY_BASE_BACKOFF', 30),
            retryMaxBackoff: Env::int('RETRY_MAX_BACKOFF', 3600),
            deleteAfterUpload: Env::bool('DELETE_AFTER_UPLOAD', true),
            stagingRetentionHours: Env::int('STAGING_RETENTION_HOURS', 48),
            cronToken: Env::get('CRON_TOKEN'),
            debug: Env::bool('DEBUG', false),
        );
    }

    public function ensureDirectories(): void
    {
        foreach ([$this->incomingDir, $this->processingDir, $this->failedDir, dirname($this->dbPath)] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
