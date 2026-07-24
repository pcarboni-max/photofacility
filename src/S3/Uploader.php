<?php

declare(strict_types=1);

namespace PhotoFacility\S3;

use PhotoFacility\Config;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Support\Logger;

/**
 * Consuma la coda PENDING_S3 e carica i file su S3.
 *
 * Disaccoppiamento: l'ingestion non attende mai S3. Se S3 è irraggiungibile i
 * file restano in staging con stato PENDING_S3 e vengono ritentati al prossimo
 * cron, con backoff esponenziale. Dopo maxAttempts finiscono in QUARANTINE.
 *
 * Integrità end-to-end: passiamo Content-MD5 (S3 rifiuta se non combacia) e
 * confrontiamo l'ETag restituito (per PutObject single-part == MD5 hex).
 */
final class Uploader
{
    public function __construct(
        private readonly S3Client $client,
        private readonly PhotoRepository $repo,
        private readonly Config $config,
        private readonly Logger $log,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $rows righe photos in PENDING_S3
     * @param callable():bool $withinBudget ritorna false quando scade il tempo
     * @return array{uploaded:int, failed:int}
     */
    public function processQueue(array $rows, callable $withinBudget): array
    {
        $uploaded = 0;
        $failed = 0;

        foreach ($rows as $row) {
            if (!$withinBudget()) {
                $this->log->info('Budget di tempo esaurito, interrompo la coda upload.');
                break;
            }

            $id = (int) $row['id'];
            $path = (string) $row['staging_path'];

            if (!is_file($path)) {
                // file sparito dal disco ma record ancora pending: incoerenza
                $this->repo->updateStatus($id, 'ERROR', 'file di staging mancante');
                $this->repo->logEvent($id, 'error', 'staging file mancante: ' . $path);
                $this->log->error('File di staging mancante', ['id' => $id, 'path' => $path]);
                $failed++;
                continue;
            }

            $this->repo->markUploading($id);

            $result = $this->client->putObject(
                (string) $row['s3_bucket'],
                (string) $row['s3_key'],
                $path,
                (string) $row['checksum_sha256'],
                $this->md5Base64FromHex((string) $row['checksum_md5']),
                (string) $row['mime_detected'],
            );

            if ($result['ok']) {
                // verifica ETag (single-part = md5 hex dell'oggetto)
                $expected = (string) $row['checksum_md5'];
                if ($result['etag'] !== null && $result['etag'] !== $expected && !str_contains($result['etag'], '-')) {
                    // ETag presente, non multipart, ma diverso -> integrità sospetta
                    $this->handleFailure($row, "ETag mismatch: atteso {$expected}, ricevuto {$result['etag']}");
                    $failed++;
                    continue;
                }

                $this->repo->markUploaded($id, (string) ($result['etag'] ?? ''), gmdate('Y-m-d H:i:s'));
                $this->repo->logEvent($id, 'uploaded', 's3://' . $row['s3_bucket'] . '/' . $row['s3_key']);
                $this->log->info('Upload S3 OK', ['id' => $id, 'key' => $row['s3_key']]);

                // retention: cancella lo staging solo DOPO conferma
                if ($this->config->deleteAfterUpload) {
                    @unlink($path);
                }
                $uploaded++;
            } else {
                $this->handleFailure($row, (string) $result['error']);
                $failed++;
            }
        }

        return ['uploaded' => $uploaded, 'failed' => $failed];
    }

    private function handleFailure(array $row, string $error): void
    {
        $id = (int) $row['id'];
        $attempts = (int) $row['upload_attempts'] + 1;

        $backoffSeconds = min(
            $this->config->retryMaxBackoff,
            $this->config->retryBaseBackoff * (2 ** ($attempts - 1))
        );
        // jitter deterministico basato sull'id per evticare thundering herd
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

        $event = $attempts >= $this->config->maxUploadAttempts ? 'error' : 'retry';
        $this->repo->logEvent($id, $event, "tentativo {$attempts}: {$error}");
        $this->log->warn('Upload S3 fallito', [
            'id' => $id, 'attempt' => $attempts, 'error' => $error, 'next_retry' => $nextRetryAt,
        ]);
    }

    private function md5Base64FromHex(string $hex): string
    {
        $bin = hex2bin($hex);
        return $bin === false ? '' : base64_encode($bin);
    }
}
