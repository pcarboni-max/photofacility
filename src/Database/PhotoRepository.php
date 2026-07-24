<?php

declare(strict_types=1);

namespace PhotoFacility\Database;

use PDO;

/**
 * Accesso ai dati per il ciclo di vita delle foto.
 *
 * Il DB è la SORGENTE DI VERITÀ dello stato: il filesystem di staging è
 * transiente. Al riavvio (o al prossimo cron) tutto ciò che è PENDING_S3
 * viene ripreso da qui, non dalla scansione del disco.
 */
final class PhotoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findBySha256(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM photos WHERE checksum_sha256 = :h LIMIT 1');
        $stmt->execute([':h' => $sha256]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Inserisce una foto già validata pronta per l'upload S3.
     * @return int id della riga.
     */
    public function insertPending(array $data): int
    {
        $sql = 'INSERT INTO photos
            (uuid, original_filename, camera_model, status, size_bytes,
             checksum_sha256, checksum_md5, mime_detected, staging_path,
             s3_bucket, s3_key, exif_taken_at, partition_date, received_at)
            VALUES
            (:uuid, :original_filename, :camera_model, :status, :size_bytes,
             :checksum_sha256, :checksum_md5, :mime_detected, :staging_path,
             :s3_bucket, :s3_key, :exif_taken_at, :partition_date, :received_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':uuid' => $data['uuid'],
            ':original_filename' => $data['original_filename'],
            ':camera_model' => $data['camera_model'] ?? null,
            ':status' => 'PENDING_S3',
            ':size_bytes' => $data['size_bytes'],
            ':checksum_sha256' => $data['checksum_sha256'],
            ':checksum_md5' => $data['checksum_md5'],
            ':mime_detected' => $data['mime_detected'],
            ':staging_path' => $data['staging_path'],
            ':s3_bucket' => $data['s3_bucket'],
            ':s3_key' => $data['s3_key'],
            ':exif_taken_at' => $data['exif_taken_at'] ?? null,
            ':partition_date' => $data['partition_date'],
            ':received_at' => $data['received_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Recupera un lotto di foto pronte per l'upload (o per il retry).
     * @return array<int,array<string,mixed>>
     */
    public function fetchUploadable(int $limit, string $now): array
    {
        $sql = "SELECT * FROM photos
                WHERE status = 'PENDING_S3'
                  AND (next_retry_at IS NULL OR next_retry_at <= :now)
                ORDER BY id ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function markUploading(int $id): void
    {
        $this->updateStatus($id, 'UPLOADING_S3', null);
    }

    public function markUploaded(int $id, string $etag, string $uploadedAt): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE photos
             SET status = 'UPLOADED_S3', s3_etag = :etag, uploaded_at = :ua,
                 status_detail = NULL, updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([':etag' => $etag, ':ua' => $uploadedAt, ':id' => $id]);
    }

    /**
     * Registra un fallimento di upload e programma il retry con backoff.
     * Oltre maxAttempts la foto va in QUARANTINE (dead-letter).
     */
    public function markRetryOrQuarantine(int $id, int $attempts, int $maxAttempts, string $error, string $nextRetryAt, string $now): void
    {
        if ($attempts >= $maxAttempts) {
            $stmt = $this->pdo->prepare(
                "UPDATE photos
                 SET status = 'QUARANTINE', upload_attempts = :att,
                     status_detail = :err, last_error_at = :now, updated_at = datetime('now')
                 WHERE id = :id"
            );
            $stmt->execute([':att' => $attempts, ':err' => $error, ':now' => $now, ':id' => $id]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE photos
             SET status = 'PENDING_S3', upload_attempts = :att, next_retry_at = :nra,
                 status_detail = :err, last_error_at = :now, updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([
            ':att' => $attempts,
            ':nra' => $nextRetryAt,
            ':err' => $error,
            ':now' => $now,
            ':id' => $id,
        ]);
    }

    public function updateStatus(int $id, string $status, ?string $detail): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE photos SET status = :s, status_detail = :d, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':s' => $status, ':d' => $detail, ':id' => $id]);
    }

    public function logEvent(?int $photoId, string $event, ?string $detail = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ingest_events (photo_id, event, detail) VALUES (:p, :e, :d)'
        );
        $stmt->execute([':p' => $photoId, ':e' => $event, ':d' => $detail]);
    }

    /** @param array<string,string> $exif tag => value */
    public function saveExif(int $photoId, array $exif, string $source = 'extracted'): void
    {
        $sql = 'INSERT INTO photo_exif (photo_id, tag, value, source)
                VALUES (:p, :t, :v, :s)
                ON CONFLICT(photo_id, tag) DO UPDATE SET value = excluded.value,
                    source = excluded.source, updated_at = datetime(\'now\')';
        $stmt = $this->pdo->prepare($sql);
        foreach ($exif as $tag => $value) {
            $stmt->execute([':p' => $photoId, ':t' => $tag, ':v' => (string) $value, ':s' => $source]);
        }
    }

    /** @return array<string,int> conteggio per stato */
    public function statusCounts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS n FROM photos GROUP BY status')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }
}
