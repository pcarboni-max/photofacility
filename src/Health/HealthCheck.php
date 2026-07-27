<?php

declare(strict_types=1);

namespace PhotoFacility\Health;

use PDO;
use PhotoFacility\Config;
use PhotoFacility\S3\S3Client;

/**
 * Diagnostica completa dello stato del sistema.
 *
 * Esegue controlli read-only da ogni punto di vista (ambiente PHP, config,
 * filesystem, disco, database, connettività S3, stato del cron) e restituisce
 * un elenco strutturato di esiti + un verdetto complessivo.
 *
 * NON stampa mai valori segreti (chiavi AWS): solo "presente/assente".
 * NON muta lo stato: il check S3 usa una HeadObject su una chiave sonda.
 */
final class HealthCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** @var list<array{category:string,name:string,status:string,detail:string}> */
    private array $results = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{verdict:string, generated_utc:string, summary:array<string,int>, checks:list<array<string,string>>}
     */
    public function run(): array
    {
        $this->results = [];

        $this->checkPhp();
        $this->checkConfig();
        $this->checkFilesystem();
        $this->checkDisk();
        $pdo = $this->checkDatabase();
        if ($pdo !== null) {
            $this->checkPipeline($pdo);
        }
        $this->checkS3();
        $this->checkCron();

        $summary = [self::OK => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($this->results as $r) {
            $summary[$r['status']]++;
        }
        $verdict = $summary[self::FAIL] > 0 ? 'CRITICAL' : ($summary[self::WARN] > 0 ? 'WARNINGS' : 'SOLID');

        return [
            'verdict' => $verdict,
            'generated_utc' => gmdate('c'),
            'summary' => $summary,
            'checks' => $this->results,
        ];
    }

    private function add(string $category, string $name, string $status, string $detail): void
    {
        $this->results[] = compact('category', 'name', 'status', 'detail');
    }

    // -------------------------------------------------------------------
    // 1. Ambiente PHP
    // -------------------------------------------------------------------
    private function checkPhp(): void
    {
        $cat = 'PHP';
        $this->add($cat, 'Versione PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? self::OK : self::FAIL, PHP_VERSION);

        foreach (['pdo_sqlite', 'curl', 'hash'] as $ext) {
            $this->add($cat, "Estensione {$ext}", extension_loaded($ext) ? self::OK : self::FAIL, extension_loaded($ext) ? 'caricata' : 'MANCANTE');
        }
        $this->add($cat, 'Estensione exif', extension_loaded('exif') ? self::OK : self::WARN, extension_loaded('exif') ? 'caricata' : 'assente (data scatto EXIF non estraibile)');
    }

    // -------------------------------------------------------------------
    // 2. Config
    // -------------------------------------------------------------------
    private function checkConfig(): void
    {
        $cat = 'Config';

        $envFile = $this->config->baseDir . '/.env';
        $this->add($cat, 'File .env', is_file($envFile) ? self::OK : self::WARN, is_file($envFile) ? 'presente' : 'assente (uso variabili d\'ambiente?)');

        // credenziali S3: solo presenza, MAI il valore
        try {
            $this->config->requireS3();
            $this->add($cat, 'Credenziali S3', self::OK, 'presenti (bucket, access key, secret key)');
        } catch (\Throwable $e) {
            $this->add($cat, 'Credenziali S3', self::FAIL, $e->getMessage());
        }

        $this->add($cat, 'Bucket / region', self::OK, ($this->config->s3Bucket ?? '—') . ' @ ' . $this->config->s3Region
            . ($this->config->s3Endpoint ? ' (endpoint: ' . $this->config->s3Endpoint . ')' : ''));
        $this->add($cat, 'Fuso HOME_TZ', self::OK, $this->config->homeTz);
    }

    // -------------------------------------------------------------------
    // 3. Filesystem / permessi
    // -------------------------------------------------------------------
    private function checkFilesystem(): void
    {
        $cat = 'Filesystem';
        foreach ([
            'incoming' => $this->config->incomingDir,
            'processing' => $this->config->processingDir,
            'failed' => $this->config->failedDir,
            'db dir' => dirname($this->config->dbPath),
        ] as $label => $dir) {
            if (!is_dir($dir)) {
                $this->add($cat, "Cartella {$label}", self::FAIL, "inesistente: {$dir}");
            } elseif (!is_writable($dir)) {
                $this->add($cat, "Cartella {$label}", self::FAIL, "non scrivibile: {$dir}");
            } else {
                $this->add($cat, "Cartella {$label}", self::OK, 'scrivibile');
            }
        }
    }

    // -------------------------------------------------------------------
    // 4. Disco
    // -------------------------------------------------------------------
    private function checkDisk(): void
    {
        $free = @disk_free_space($this->config->incomingDir);
        if ($free === false) {
            $this->add('Disco', 'Spazio libero', self::WARN, 'non determinabile');
            return;
        }
        $freeMb = (int) round($free / (1024 * 1024));
        $status = $freeMb < $this->config->diskMinFreeMb ? self::FAIL : ($freeMb < $this->config->diskMinFreeMb * 3 ? self::WARN : self::OK);
        $this->add('Disco', 'Spazio libero', $status, "{$freeMb} MB liberi (soglia freno ingestione: {$this->config->diskMinFreeMb} MB)");
    }

    // -------------------------------------------------------------------
    // 5. Database
    // -------------------------------------------------------------------
    private function checkDatabase(): ?PDO
    {
        $cat = 'Database';
        if (!is_file($this->config->dbPath)) {
            $this->add($cat, 'File DB', self::WARN, 'non ancora creato (verrà generato al primo tick)');
            return null;
        }

        try {
            $pdo = new PDO('sqlite:' . $this->config->dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            $this->add($cat, 'Connessione DB', self::FAIL, 'impossibile aprire il database');
            return null;
        }
        $this->add($cat, 'Connessione DB', self::OK, 'aperta');

        // schema presente?
        $table = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='photos'")->fetchColumn();
        if ($table === false) {
            $this->add($cat, 'Schema', self::FAIL, 'tabella photos assente (migrazione non eseguita)');
            return $pdo;
        }
        $this->add($cat, 'Schema', self::OK, 'tabelle presenti');

        // WAL attivo
        $mode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $this->add($cat, 'Journal mode', strtolower($mode) === 'wal' ? self::OK : self::WARN, $mode);

        // integrità
        try {
            $quick = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
            $this->add($cat, 'Integrità (quick_check)', $quick === 'ok' ? self::OK : self::FAIL, $quick);
        } catch (\Throwable $e) {
            $this->add($cat, 'Integrità (quick_check)', self::FAIL, 'errore durante il check');
        }

        // scrivibilità del file DB
        $this->add($cat, 'DB scrivibile', is_writable($this->config->dbPath) ? self::OK : self::FAIL, is_writable($this->config->dbPath) ? 'sì' : 'NO (upload/stato non aggiornabili)');

        return $pdo;
    }

    // -------------------------------------------------------------------
    // 6. Pipeline (stato operativo)
    // -------------------------------------------------------------------
    private function checkPipeline(PDO $pdo): void
    {
        $cat = 'Pipeline';

        $counts = [];
        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM photos GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }
        $total = array_sum($counts);
        $this->add($cat, 'Foto totali', self::OK, (string) $total . ' (' . ($this->formatCounts($counts) ?: 'nessuna') . ')');

        // backlog
        $backlog = $pdo->query(
            "SELECT COUNT(*) AS n,
                    CAST(strftime('%s','now') AS INTEGER) - CAST(strftime('%s', MIN(received_at)) AS INTEGER) AS age
             FROM photos WHERE status='PENDING_S3'"
        )->fetch();
        $pending = (int) ($backlog['n'] ?? 0);
        $ageMin = $backlog['age'] !== null ? (int) round(((int) $backlog['age']) / 60) : 0;
        if ($pending === 0) {
            $this->add($cat, 'Backlog upload', self::OK, 'coda vuota');
        } else {
            $status = ($pending > $this->config->backlogAlertThreshold || $ageMin > $this->config->backlogAgeAlertMinutes) ? self::WARN : self::OK;
            $this->add($cat, 'Backlog upload', $status, "{$pending} in coda, più vecchia da {$ageMin} min");
        }

        // quarantena / errori
        $quar = (int) ($counts['QUARANTINE'] ?? 0);
        $this->add($cat, 'Quarantena', $quar === 0 ? self::OK : self::WARN, $quar === 0 ? 'nessuna' : "{$quar} foto (upload fallito ripetutamente: reinviale dalla camera per riprovare)");
        $err = (int) ($counts['ERROR'] ?? 0);
        $this->add($cat, 'Errori', $err === 0 ? self::OK : self::WARN, $err === 0 ? 'nessuno' : "{$err} foto in ERROR");

        // record bloccati in UPLOADING_S3 oltre la soglia del reaper
        $cutoff = gmdate('Y-m-d H:i:s', time() - $this->config->reaperStuckMinutes * 60);
        $stuck = (int) $pdo->query("SELECT COUNT(*) FROM photos WHERE status='UPLOADING_S3' AND updated_at < '" . $cutoff . "'")->fetchColumn();
        $this->add($cat, 'Upload bloccati', $stuck === 0 ? self::OK : self::WARN, $stuck === 0 ? 'nessuno' : "{$stuck} oltre soglia reaper (li recupererà il prossimo tick)");
    }

    // -------------------------------------------------------------------
    // 7. Connettività S3 (read-only, least-privilege)
    // -------------------------------------------------------------------
    private function checkS3(): void
    {
        $cat = 'S3';
        try {
            $this->config->requireS3();
        } catch (\Throwable) {
            $this->add($cat, 'Connettività', self::FAIL, 'credenziali S3 non configurate');
            return;
        }

        $client = new S3Client(
            region: $this->config->s3Region,
            accessKey: (string) $this->config->awsAccessKey,
            secretKey: (string) $this->config->awsSecretKey,
            endpoint: $this->config->s3Endpoint,
            usePathStyle: $this->config->s3PathStyle,
            sessionToken: $this->config->awsSessionToken,
        );

        // GET firmata su una chiave sonda inesistente: non muta nulla, non
        // richiede s3:ListBucket, e (a differenza di HEAD) restituisce il corpo
        // XML con il codice d'errore reale. Interpretiamo lo status:
        //   404 → raggiungibile + auth OK (chiave assente) = SANO
        //   200 → raggiungibile + oggetto presente          = SANO
        //   403 → raggiungibile ma permessi/credenziali KO  = FAIL
        //   400 → firma/region errata                        = FAIL
        //   0   → irraggiungibile (rete/DNS/TLS)             = FAIL
        $probeKey = ($this->config->s3Prefix !== '' ? rtrim($this->config->s3Prefix, '/') . '/' : '') . '.healthcheck-probe';
        $head = $client->probe((string) $this->config->s3Bucket, $probeKey, 10);
        $status = (int) $head['status'];
        $err = (string) ($head['error'] ?? '');

        if ($status === 404 || ($status >= 200 && $status < 300)) {
            $this->add($cat, 'Connettività', self::OK, "bucket raggiungibile, autenticazione OK (HTTP {$status})");
        } elseif ($status === 403) {
            $this->add($cat, 'Connettività', self::FAIL, "HTTP 403: credenziali o permessi IAM insufficienti (serve s3:GetObject/HeadObject sul prefisso). {$err}");
        } elseif ($status === 400) {
            $this->add($cat, 'Connettività', self::FAIL, "HTTP 400: richiesta rifiutata. Causa tipica: S3_REGION non corrisponde alla region del bucket, oppure S3_ENDPOINT/S3_PATH_STYLE errati. Dettaglio S3: {$err}");
        } elseif ($status === 301) {
            $this->add($cat, 'Connettività', self::FAIL, "HTTP 301: bucket in un'altra region. Correggi S3_REGION. {$err}");
        } elseif ($status === 0) {
            $this->add($cat, 'Connettività', self::FAIL, 'bucket irraggiungibile (rete/DNS/TLS o hostname errato)');
        } else {
            $this->add($cat, 'Connettività', self::WARN, "risposta inattesa: HTTP {$status}. {$err}");
        }
        // mostra sempre la config S3 in uso, per confronto rapido
        $this->add($cat, 'Config S3 in uso', self::OK, 'region=' . $this->config->s3Region
            . ', path_style=' . ($this->config->s3PathStyle ? 'sì' : 'no')
            . ', endpoint=' . ($this->config->s3Endpoint ?: 'AWS standard'));
    }

    // -------------------------------------------------------------------
    // 8. Cron
    // -------------------------------------------------------------------
    private function checkCron(): void
    {
        $cat = 'Cron';
        $hf = $this->config->healthFile;
        if (!is_file($hf)) {
            $this->add($cat, 'Ultimo ciclo', self::WARN, 'health.json assente: il cron non è ancora mai girato');
            return;
        }
        $data = json_decode((string) @file_get_contents($hf), true);
        $last = is_array($data) ? ($data['last_run_utc'] ?? null) : null;
        if (!is_string($last)) {
            $this->add($cat, 'Ultimo ciclo', self::WARN, 'health.json illeggibile');
            return;
        }
        $ts = strtotime($last);
        $ageSec = $ts !== false ? time() - $ts : PHP_INT_MAX;
        $ageMin = (int) round($ageSec / 60);
        // il cron dovrebbe girare ~ogni minuto; oltre 5 min è sospetto
        $status = $ageSec > 300 ? self::FAIL : ($ageSec > 120 ? self::WARN : self::OK);
        $this->add($cat, 'Ultimo ciclo', $status, "ultimo run {$ageMin} min fa (UTC {$last})");
    }

    /** @param array<string,int> $counts */
    private function formatCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode(', ', $parts);
    }
}
