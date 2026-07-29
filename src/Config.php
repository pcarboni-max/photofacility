<?php

declare(strict_types=1);

namespace PhotoFacility;

use PhotoFacility\Support\Env;

/**
 * Configurazione centralizzata, popolata da variabili d'ambiente / .env.
 *
 * La config S3 è volutamente DISACCOPPIATA da quella di base: costruire la
 * config e migrare il DB non richiede le credenziali AWS (evita il footgun del
 * primo setup). Le credenziali S3 vengono validate solo quando servono
 * davvero, via requireS3().
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
        public readonly string $healthFile,

        // S3 (nullable: validati on-demand con requireS3())
        public readonly ?string $s3Bucket,
        public readonly string $s3Region,
        public readonly string $s3Prefix,
        public readonly ?string $awsAccessKey,
        public readonly ?string $awsSecretKey,
        public readonly ?string $awsSessionToken,
        public readonly ?string $s3Endpoint,
        public readonly bool $s3PathStyle,

        // comportamento ingestion / upload
        public readonly string $homeTz,
        public readonly int $quiescenceSeconds,
        public readonly int $maxRuntimeSeconds,
        public readonly int $loopDurationSeconds,
        public readonly int $uploadBatchSize,
        public readonly int $maxUploadAttempts,
        public readonly int $retryBaseBackoff,
        public readonly int $retryMaxBackoff,
        public readonly bool $deleteAfterUpload,
        public readonly int $stagingRetentionHours,
        public readonly int $reaperStuckMinutes,
        public readonly int $diskMinFreeMb,
        public readonly int $eventsRetentionDays,

        // osservabilità / alerting
        public readonly ?string $alertWebhookUrl,
        public readonly ?string $alertEmail,
        public readonly int $backlogAlertThreshold,
        public readonly int $backlogAgeAlertMinutes,
        public readonly int $stagingAlertMb,

        // pagina di diagnostica (public/health.php)
        public readonly ?string $healthToken,

        // Fase 2 — UI di visualizzazione
        public readonly string $appEnvName,
        public readonly string $cacheDir,
        public readonly int $thumbWidth,
        public readonly int $previewWidth,
        public readonly int $presignTtl,
        public readonly int $cardsPerPage,
        public readonly int $thumbBatchPerTick,

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
            healthFile: Env::get('HEALTH_FILE', $baseDir . '/db/health.json') ?? $baseDir . '/db/health.json',

            s3Bucket: Env::get('S3_BUCKET'),
            s3Region: Env::get('S3_REGION', 'eu-south-1') ?? 'eu-south-1',
            s3Prefix: Env::get('S3_PREFIX', '') ?? '',
            awsAccessKey: Env::get('AWS_ACCESS_KEY_ID'),
            awsSecretKey: Env::get('AWS_SECRET_ACCESS_KEY'),
            awsSessionToken: Env::get('AWS_SESSION_TOKEN'),
            s3Endpoint: Env::get('S3_ENDPOINT'),
            s3PathStyle: Env::bool('S3_PATH_STYLE', false),

            homeTz: self::validTimezone(Env::get('HOME_TZ', 'Europe/Rome') ?? 'Europe/Rome'),
            quiescenceSeconds: Env::int('QUIESCENCE_SECONDS', 30),
            maxRuntimeSeconds: Env::int('MAX_RUNTIME_SECONDS', 55),
            loopDurationSeconds: Env::int('LOOP_DURATION_SECONDS', 55),
            uploadBatchSize: Env::int('UPLOAD_BATCH_SIZE', 20),
            maxUploadAttempts: Env::int('MAX_UPLOAD_ATTEMPTS', 8),
            retryBaseBackoff: Env::int('RETRY_BASE_BACKOFF', 30),
            retryMaxBackoff: Env::int('RETRY_MAX_BACKOFF', 3600),
            deleteAfterUpload: Env::bool('DELETE_AFTER_UPLOAD', true),
            stagingRetentionHours: Env::int('STAGING_RETENTION_HOURS', 48),
            reaperStuckMinutes: Env::int('REAPER_STUCK_MINUTES', 10),
            diskMinFreeMb: Env::int('DISK_MIN_FREE_MB', 1024),
            eventsRetentionDays: Env::int('EVENTS_RETENTION_DAYS', 90),

            alertWebhookUrl: Env::get('ALERT_WEBHOOK_URL'),
            alertEmail: Env::get('ALERT_EMAIL'),
            backlogAlertThreshold: Env::int('BACKLOG_ALERT_THRESHOLD', 200),
            backlogAgeAlertMinutes: Env::int('BACKLOG_AGE_ALERT_MINUTES', 30),
            stagingAlertMb: Env::int('STAGING_ALERT_MB', 5120),

            healthToken: Env::get('HEALTH_TOKEN'),

            appEnvName: Env::get('APP_ENV_NAME', 'PhotoFacility') ?? 'PhotoFacility',
            cacheDir: Env::get('CACHE_DIR', $baseDir . '/cache') ?? $baseDir . '/cache',
            thumbWidth: Env::int('THUMB_WIDTH', 300),
            previewWidth: Env::int('PREVIEW_WIDTH', 1600),
            presignTtl: Env::int('PRESIGN_TTL', 600),
            cardsPerPage: Env::int('CARDS_PER_PAGE', 5),
            thumbBatchPerTick: Env::int('THUMB_BATCH_PER_TICK', 20),

            debug: Env::bool('DEBUG', false),
        );
    }

    /**
     * Valida HOME_TZ: deve essere una zona IANA CON NOME (es. Europe/Rome), che
     * gestisce l'ora legale. Un offset fisso (+01:00) è vietato e un nome non
     * valido fa fallire subito l'avvio con un errore chiaro.
     */
    private static function validTimezone(string $tz): string
    {
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            throw new \RuntimeException(
                "HOME_TZ non valido: '{$tz}'. Usa una zona IANA con nome (es. Europe/Rome), non un offset fisso."
            );
        }
        return $tz;
    }

    /**
     * Valida la presenza delle credenziali S3. Da chiamare prima di usare S3.
     * @throws \RuntimeException se manca una variabile obbligatoria.
     */
    public function requireS3(): void
    {
        $missing = [];
        foreach ([
            'S3_BUCKET' => $this->s3Bucket,
            'AWS_ACCESS_KEY_ID' => $this->awsAccessKey,
            'AWS_SECRET_ACCESS_KEY' => $this->awsSecretKey,
        ] as $name => $value) {
            if ($value === null || $value === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException('Configurazione S3 incompleta: mancano ' . implode(', ', $missing));
        }
    }

    public function ensureDirectories(): void
    {
        foreach ([
            $this->incomingDir, $this->processingDir, $this->failedDir, dirname($this->dbPath),
            $this->cacheDir . '/thumbs', $this->cacheDir . '/previews',
        ] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}
