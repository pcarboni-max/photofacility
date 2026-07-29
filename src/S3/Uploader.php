<?php

declare(strict_types=1);

namespace PhotoFacility\S3;

use PhotoFacility\Config;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Support\Logger;
use PhotoFacility\Support\Notifier;
use PhotoFacility\Thumbnail\ThumbnailService;

/**
 * Consuma la coda PENDING_S3 e carica i file su S3.
 *
 * Disaccoppiamento: l'ingestion non attende mai S3. Se S3 è irraggiungibile i
 * file restano in staging con stato PENDING_S3 e vengono ritentati al prossimo
 * ciclo, con backoff esponenziale. Dopo maxAttempts finiscono in QUARANTINE.
 *
 * Integrità end-to-end: passiamo Content-MD5 (S3 rifiuta se non combacia) e,
 * quando applicabile, confrontiamo l'ETag (per PutObject single-part == MD5 hex).
 */
final class Uploader
{
    public function __construct(
        private readonly StorageTarget $client,
        private readonly PhotoRepository $repo,
        private readonly Config $config,
        private readonly Logger $log,
        private readonly Notifier $notifier,
        private readonly ThumbnailService $thumbs,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $rows righe photos in PENDING_S3
     * @param float $deadline microtime() oltre cui fermarsi
     * @return array{uploaded:int, failed:int}
     */
    public function processQueue(array $rows, float $deadline): array
    {
        $uploaded = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $remaining = (int) ($deadline - microtime(true));
            if ($remaining < 5) {
                $this->log->info('Budget di tempo esaurito, interrompo la coda upload.');
                break;
            }

            $id = (int) $row['id'];
            $path = (string) $row['staging_path'];

            if (!is_file($path)) {
                $this->repo->updateStatus($id, 'ERROR', 'file di staging mancante');
                $this->repo->logEvent($id, 'error', 'staging file mancante: ' . $path);
                $this->log->error('File di staging mancante', ['id' => $id, 'path' => $path]);
                $failed++;
                continue;
            }

            $this->repo->markUploading($id);

            // Metadati nell'oggetto S3 (M1): rendono il bucket autodescrittivo e
            // il DB ricostruibile. Costo zero (stessa richiesta PutObject).
            $meta = [
                'original-filename' => (string) $row['original_filename'],
                'sha256' => (string) $row['checksum_sha256'],
                'taken-at' => (string) ($row['exif_taken_at'] ?? ''),
                'partition-date' => (string) $row['partition_date'],
            ];

            $result = $this->client->putObject(
                (string) $row['s3_bucket'],
                (string) $row['s3_key'],
                $path,
                (string) $row['checksum_sha256'],
                $this->md5Base64FromHex((string) $row['checksum_md5']),
                (string) $row['mime_detected'],
                $meta,
                $remaining,
            );

            if ($result['ok']) {
                if (!$this->etagMatches($result, (string) $row['checksum_md5'])) {
                    $this->handleFailure($row, "ETag mismatch: atteso {$row['checksum_md5']}, ricevuto {$result['etag']}", true);
                    $failed++;
                    continue;
                }

                $this->repo->markUploaded($id, (string) ($result['etag'] ?? ''), gmdate('Y-m-d H:i:s'));
                $this->repo->logEvent($id, 'uploaded', 's3://' . $row['s3_bucket'] . '/' . $row['s3_key']);
                $this->log->info('Upload S3 OK', ['id' => $id, 'key' => $row['s3_key']]);

                // genera thumbnail/preview MENTRE il file è ancora locale (Fase 2)
                try {
                    $this->thumbs->processLocal($row, $path);
                } catch (\Throwable $e) {
                    $this->log->warn('Thumbnail all\'ingest fallita (riprova il reconciler)', ['id' => $id, 'error' => $e->getMessage()]);
                }

                if ($this->config->deleteAfterUpload) {
                    if (!@unlink($path)) {
                        // upload OK ma cancellazione fallita: non è un errore di dato,
                        // ma va segnalato perché il file resta in processing/.
                        $this->log->warn('unlink post-upload fallito (leak in processing/)', ['id' => $id, 'path' => $path]);
                    }
                }
                $uploaded++;
            } else {
                $this->handleFailure($row, (string) $result['error'], (bool) $result['retriable']);
                $failed++;
            }
        }

        return ['uploaded' => $uploaded, 'failed' => $failed];
    }

    /**
     * L'ETag di S3 è l'MD5 hex SOLO per PutObject single-part senza cifratura
     * lato server con chiave. Con SSE-KMS/SSE-C o multipart l'ETag NON è l'MD5:
     * in quei casi ci fidiamo del Content-MD5 (già verificato e imposto da S3).
     */
    private function etagMatches(array $result, string $expectedMd5Hex): bool
    {
        $etag = $result['etag'] ?? null;
        $sse = $result['sse'] ?? null;

        if ($etag === null) {
            return true; // nessun ETag da confrontare
        }
        if (str_contains($etag, '-')) {
            return true; // multipart: ETag non è l'MD5
        }
        if ($sse !== null && stripos($sse, 'kms') !== false) {
            return true; // SSE-KMS: ETag non è l'MD5
        }
        return hash_equals($expectedMd5Hex, $etag);
    }

    private function handleFailure(array $row, string $error, bool $retriable): void
    {
        $id = (int) $row['id'];
        $attempts = (int) $row['upload_attempts'] + 1;

        // errore non ritentabile (es. 403 credenziali, 400): quarantena subito,
        // senza sprecare gli 8 tentativi.
        if (!$retriable) {
            $this->repo->markRetryOrQuarantine($id, $this->config->maxUploadAttempts, $this->config->maxUploadAttempts, $error, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));
            $this->repo->logEvent($id, 'error', "non ritentabile: {$error}");
            $this->log->error('Upload S3 fallito (non ritentabile) → QUARANTINE', ['id' => $id, 'error' => $error]);
            $this->notifier->alert('quarantine', 'Foto in quarantena', "Foto id {$id} ({$row['original_filename']}): {$error}");
            return;
        }

        $backoffSeconds = min(
            $this->config->retryMaxBackoff,
            $this->config->retryBaseBackoff * (2 ** ($attempts - 1))
        );
        $jitter = $id % 30;
        $nextRetryAt = gmdate('Y-m-d H:i:s', time() + $backoffSeconds + $jitter);

        $this->repo->markRetryOrQuarantine(
            $id,
            $attempts,
            $this->config->maxUploadAttempts,
            $error,
            $nextRetryAt,
            gmdate('Y-m-d H:i:s'),
        );

        if ($attempts >= $this->config->maxUploadAttempts) {
            $this->repo->logEvent($id, 'error', "esauriti i tentativi: {$error}");
            $this->log->error('Upload S3 → QUARANTINE (tentativi esauriti)', ['id' => $id, 'error' => $error]);
            $this->notifier->alert('quarantine', 'Foto in quarantena', "Foto id {$id} ({$row['original_filename']}): {$error}");
        } else {
            $this->repo->logEvent($id, 'retry', "tentativo {$attempts}: {$error}");
            $this->log->warn('Upload S3 fallito, retry programmato', [
                'id' => $id, 'attempt' => $attempts, 'error' => $error, 'next_retry' => $nextRetryAt,
            ]);
        }
    }

    private function md5Base64FromHex(string $hex): string
    {
        $bin = hex2bin($hex);
        return $bin === false ? '' : base64_encode($bin);
    }
}
