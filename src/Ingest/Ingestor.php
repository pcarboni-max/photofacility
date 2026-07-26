<?php

declare(strict_types=1);

namespace PhotoFacility\Ingest;

use PhotoFacility\Config;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Support\Logger;

/**
 * Scansiona la cartella di ingestion FTP, riconosce i file COMPLETI e li
 * ammette alla coda S3.
 *
 * Su hosting condiviso non abbiamo l'hook post-upload del daemon FTP, quindi
 * usiamo una GUARDIA DI QUIESCENZA: un file è considerato completo solo se il
 * suo mtime è stabile da almeno `quiescenceSeconds`. Mentre la camera carica,
 * l'mtime continua ad aggiornarsi, quindi un upload parziale non viene mai
 * preso in carico.
 */
final class Ingestor
{
    public function __construct(
        private readonly Config $config,
        private readonly PhotoRepository $repo,
        private readonly IntegrityValidator $validator,
        private readonly ExifExtractor $exif,
        private readonly Logger $log,
    ) {
    }

    /**
     * @param callable():bool $withinBudget
     * @return array{admitted:int, skipped:int, failed:int, waiting:int, disk_low:bool}
     */
    public function run(callable $withinBudget): array
    {
        $admitted = 0;
        $skipped = 0;
        $failed = 0;
        $waiting = 0;

        $incoming = $this->config->incomingDir;
        if (!is_dir($incoming)) {
            @mkdir($incoming, 0775, true);
            return ['admitted' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0, 'disk_low' => false];
        }

        // FRENO A DISCO PIENO: se lo spazio libero è sotto soglia, NON ammettiamo
        // nuovi file (ammetterli copierebbe dati su un disco quasi pieno e farebbe
        // fallire anche le scritture SQLite). Lasciamo però drenare la coda S3
        // (gestita da App), che libera spazio man mano che gli upload confermano.
        $freeBytes = @disk_free_space($incoming);
        if ($freeBytes !== false && $freeBytes < ($this->config->diskMinFreeMb * 1024 * 1024)) {
            $freeMb = (int) round($freeBytes / (1024 * 1024));
            $this->log->error('Disco quasi pieno: ingestione sospesa (solo drain S3)', ['free_mb' => $freeMb]);
            return ['admitted' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => 0, 'disk_low' => true];
        }

        $now = time();
        $entries = scandir($incoming) ?: [];

        foreach ($entries as $entry) {
            if (!$withinBudget()) {
                $this->log->info('Budget di tempo esaurito durante la scansione ingestion.');
                break;
            }
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $incoming . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }

            // ignora estensioni temporanee note dei client FTP
            $lower = strtolower($entry);
            if (str_ends_with($lower, '.part') || str_ends_with($lower, '.filepart') || str_ends_with($lower, '.tmp')) {
                $waiting++;
                continue;
            }

            // guardia di quiescenza: mtime stabile da almeno N secondi
            clearstatcache(true, $path);
            $mtime = filemtime($path);
            if ($mtime === false || ($now - $mtime) < $this->config->quiescenceSeconds) {
                $waiting++;
                continue;
            }

            // gate estensione (prima del lavoro pesante)
            if (!IntegrityValidator::hasAllowedExtension($entry)) {
                $this->quarantineFile($path, $entry, 'estensione non ammessa');
                $failed++;
                continue;
            }

            $outcome = $this->admitFile($path, $entry);
            match ($outcome) {
                'admitted' => $admitted++,
                'skipped' => $skipped++,
                default => $failed++,
            };
        }

        return ['admitted' => $admitted, 'skipped' => $skipped, 'failed' => $failed, 'waiting' => $waiting, 'disk_low' => false];
    }

    private function admitFile(string $path, string $filename): string
    {
        // 1. validazione integrità (magic bytes + checksum in streaming)
        $v = $this->validator->validate($path);
        if (!$v['ok']) {
            $this->quarantineFile($path, $filename, (string) $v['reason']);
            $this->repo->logEvent(null, 'error', "{$filename}: {$v['reason']}");
            $this->log->warn('File scartato in validazione', ['file' => $filename, 'reason' => $v['reason']]);
            return 'failed';
        }

        // 2. deduplica / idempotenza
        $existing = $this->repo->findBySha256((string) $v['sha256']);
        if ($existing !== null) {
            $this->repo->logEvent((int) $existing['id'], 'skipped_duplicate', $filename);
            $this->log->info('Duplicato ignorato', ['file' => $filename, 'sha256' => $v['sha256']]);
            @unlink($path); // già noto: liberiamo lo staging
            return 'skipped';
        }

        // 3. EXIF -> data di scatto / partizione giornaliera
        $receivedAt = gmdate('Y-m-d H:i:s');
        $meta = $this->exif->extract($path, $receivedAt);

        // 4. genera identità interna + chiave S3 deterministica
        $uuid = $this->generateUuid();
        $shaPrefix = substr((string) $v['sha256'], 0, 12);
        $safeName = $this->sanitizeName($filename);
        $partition = $meta['partition_date'];
        $s3Key = str_replace('-', '/', $partition) . "/{$shaPrefix}_{$safeName}";
        if ($this->config->s3Prefix !== '') {
            $s3Key = rtrim($this->config->s3Prefix, '/') . '/' . $s3Key;
        }

        // 5. sposta in processing (rename atomico, stesso filesystem)
        $processingPath = $this->config->processingDir . '/' . $uuid . '_' . $safeName;
        if (!@rename($path, $processingPath)) {
            $this->log->error('Rename in processing fallito', ['file' => $filename]);
            return 'failed';
        }

        // 6. registra in DB come PENDING_S3
        $photoId = $this->repo->insertPending([
            'uuid' => $uuid,
            'original_filename' => $filename,
            'camera_model' => $meta['camera_model'],
            'size_bytes' => $v['size'],
            'checksum_sha256' => $v['sha256'],
            'checksum_md5' => $v['md5_hex'],
            'mime_detected' => $v['mime'],
            'staging_path' => $processingPath,
            's3_bucket' => $this->config->s3Bucket,
            's3_key' => $s3Key,
            'exif_taken_at' => $meta['taken_at'],
            'partition_date' => $partition,
            'received_at' => $receivedAt,
        ]);

        if ($meta['tags'] !== []) {
            $this->repo->saveExif($photoId, $meta['tags']);
        }

        $this->repo->logEvent($photoId, 'received', $filename);
        $this->repo->logEvent($photoId, 'validated', "mime={$v['mime']} size={$v['size']}");
        $this->log->info('Foto ammessa alla coda S3', [
            'id' => $photoId, 'file' => $filename, 'key' => $s3Key, 'partition' => $partition,
        ]);

        return 'admitted';
    }

    private function quarantineFile(string $path, string $filename, string $reason): void
    {
        $dest = $this->config->failedDir . '/' . date('Ymd_His') . '_' . $this->sanitizeName($filename);
        if (!@rename($path, $dest)) {
            @unlink($path);
        }
        $this->log->warn('File in quarantena', ['file' => $filename, 'reason' => $reason, 'dest' => $dest]);
    }

    private function sanitizeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'file';
        return substr($name, 0, 120);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
