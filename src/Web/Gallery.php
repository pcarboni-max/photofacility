<?php

declare(strict_types=1);

namespace PhotoFacility\Web;

use PhotoFacility\Config;
use PhotoFacility\Database\Database;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\S3\S3Client;
use PhotoFacility\Support\Env;

/**
 * Logica della UI di visualizzazione (Fase 2). Fornisce i dati alle pagine
 * public/*.php; la presentazione (HTML) sta nelle pagine stesse.
 */
final class Gallery
{
    public function __construct(
        public readonly Config $config,
        private readonly PhotoRepository $repo,
        private readonly S3Client $s3,
    ) {
    }

    /** Bootstrap condiviso dalle pagine web. */
    public static function boot(string $baseDir): self
    {
        require_once $baseDir . '/src/autoload.php';
        Env::load($baseDir . '/.env');
        date_default_timezone_set('UTC');

        $config = Config::fromEnv($baseDir);
        $db = new Database($config->dbPath);
        $repo = new PhotoRepository($db->pdo());
        $s3 = new S3Client(
            region: $config->s3Region,
            accessKey: (string) $config->awsAccessKey,
            secretKey: (string) $config->awsSecretKey,
            endpoint: $config->s3Endpoint,
            usePathStyle: $config->s3PathStyle,
            sessionToken: $config->awsSessionToken,
        );
        return new self($config, $repo, $s3);
    }

    // -------- Home (card per giorno) --------

    /**
     * @return array{page:int, pages:int, days:array<int,array{date:string,count:int,thumbs:array<int,array<string,mixed>>}>}
     */
    public function home(int $page): array
    {
        $per = $this->config->cardsPerPage;
        $total = $this->repo->countDays();
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($page, $pages));

        $days = [];
        foreach ($this->repo->listDays($per, ($page - 1) * $per) as $d) {
            $days[] = [
                'date' => $d['partition_date'],
                'count' => $d['n'],
                'thumbs' => $this->repo->listByDay($d['partition_date'], 8),
            ];
        }
        return ['page' => $page, 'pages' => $pages, 'days' => $days];
    }

    // -------- Pagina giorno --------

    /** @return array<int,array<string,mixed>> */
    public function day(string $date): array
    {
        return $this->repo->listByDay($date);
    }

    /**
     * Elementi pronti per la pagina giorno: thumb/preview/download/exif + un
     * eventuale testo placeholder secondo lo stato thumbnail. EXIF caricati in
     * blocco (una query per l'intera giornata, niente N+1).
     * @return array<int,array<string,mixed>>
     */
    public function dayItems(string $date): array
    {
        $photos = $this->repo->listByDay($date);
        $ids = array_map(static fn ($p) => (int) $p['id'], $photos);
        $exifAll = $this->repo->exifForMany($ids);

        $items = [];
        foreach ($photos as $p) {
            $id = (int) $p['id'];
            $status = (string) ($p['thumb_status'] ?? 'PENDING');
            $ready = $status === 'READY';
            $items[] = [
                'name' => (string) $p['original_filename'],
                'thumb' => $ready ? 'media.php?id=' . $id . '&size=thumb' : null,
                'preview' => $ready ? 'media.php?id=' . $id . '&size=preview' : null,
                'download' => 'dl.php?id=' . $id,
                'placeholder' => $ready ? null : $this->placeholderLabel($status),
                'exif' => $this->exifSummary($p, $exifAll[$id] ?? []),
            ];
        }
        return $items;
    }

    /** Etichetta per le tile senza anteprima, distinguendo lo stato. */
    public function placeholderLabel(string $thumbStatus): string
    {
        return match ($thumbStatus) {
            'SKIPPED' => 'RAW · anteprima n/d',
            'PENDING' => 'anteprima in preparazione…',
            'ERROR' => 'anteprima non disponibile',
            default => 'anteprima n/d',
        };
    }

    // -------- Media (thumb/preview locali) --------

    /** Percorso locale del file (thumb|preview) o null. */
    public function mediaPath(int $id, string $size): ?string
    {
        $row = $this->repo->getById($id);
        if ($row === null) {
            return null;
        }
        $path = $size === 'preview' ? ($row['preview_path'] ?? null) : ($row['thumb_path'] ?? null);
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }
        // difesa: il file deve stare dentro la cache
        $real = realpath($path);
        $base = realpath($this->config->cacheDir);
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return null;
        }
        return $real;
    }

    // -------- Download full-res (presigned S3) --------

    public function downloadUrl(int $id): ?string
    {
        $row = $this->repo->getById($id);
        if ($row === null || ($row['status'] ?? '') !== 'UPLOADED_S3') {
            return null;
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_filename']) ?: 'foto.jpg';
        $disp = 'attachment; filename="' . $name . '"';
        return $this->s3->presignGet((string) $row['s3_bucket'], (string) $row['s3_key'], $this->config->presignTtl, $disp);
    }

    // -------- EXIF principali formattati --------

    /**
     * @param array<string,mixed> $row riga photos
     * @param array<string,string>|null $e EXIF già caricati (null = carica ora)
     * @return array<string,string> etichetta => valore
     */
    public function exifSummary(array $row, ?array $e = null): array
    {
        if ($e === null) {
            $e = $this->repo->exifFor((int) $row['id']);
        }
        $out = [];

        if (!empty($row['exif_taken_at'])) {
            $out['Scatto'] = (string) $row['exif_taken_at'];
        }
        if (!empty($row['camera_model'])) {
            $out['Camera'] = (string) $row['camera_model'];
        }
        if (isset($e['LensModel'])) {
            $out['Obiettivo'] = $e['LensModel'];
        }
        if (isset($e['ISOSpeedRatings'])) {
            $out['ISO'] = $e['ISOSpeedRatings'];
        }
        if (isset($e['FNumber'])) {
            $out['Diaframma'] = 'f/' . $this->rational($e['FNumber']);
        }
        if (isset($e['ExposureTime'])) {
            $out['Tempo'] = $this->exposure($e['ExposureTime']);
        }
        if (isset($e['FocalLength'])) {
            $out['Focale'] = $this->rational($e['FocalLength']) . ' mm';
        }
        return $out;
    }

    private function rational(string $v): string
    {
        if (str_contains($v, '/')) {
            [$n, $d] = array_map('floatval', explode('/', $v, 2));
            if ($d != 0.0) {
                return rtrim(rtrim(number_format($n / $d, 1, '.', ''), '0'), '.');
            }
        }
        return $v;
    }

    private function exposure(string $v): string
    {
        if (str_contains($v, '/')) {
            [$n, $d] = array_map('floatval', explode('/', $v, 2));
            if ($n != 0.0 && $d != 0.0) {
                $sec = $n / $d;
                return $sec >= 1 ? rtrim(rtrim(number_format($sec, 1, '.', ''), '0'), '.') . ' s'
                    : '1/' . (int) round($d / $n) . ' s';
            }
        }
        return $v . ' s';
    }
}
