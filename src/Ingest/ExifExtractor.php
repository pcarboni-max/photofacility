<?php

declare(strict_types=1);

namespace PhotoFacility\Ingest;

/**
 * Estrazione EXIF minima all'ingestion.
 *
 * Scopo Fase 1: derivare la data di scatto (per il partizionamento giornaliero
 * della futura UI di Fase 2) e modello/marca camera. Non è un parser completo:
 * ext-exif copre bene JPEG e in parte TIFF/CR2; CR3 (ISO-BMFF) spesso no.
 * In ogni caso il fallback è la data di ricezione, quindi nessun blocco.
 */
final class ExifExtractor
{
    /**
     * @return array{taken_at:?string, partition_date:string, camera_model:?string, tags:array<string,string>}
     */
    public function extract(string $path, string $receivedAt): array
    {
        $takenAt = null;
        $cameraModel = null;
        $tags = [];

        if (function_exists('exif_read_data')) {
            // exif_read_data può emettere warning su formati non supportati: silenziamo.
            $data = @exif_read_data($path, null, true);
            if (is_array($data)) {
                $flat = [];
                foreach ($data as $section) {
                    if (is_array($section)) {
                        foreach ($section as $k => $v) {
                            if (is_scalar($v)) {
                                $flat[$k] = (string) $v;
                            }
                        }
                    }
                }

                $rawDate = $flat['DateTimeOriginal'] ?? $flat['DateTimeDigitized'] ?? $flat['DateTime'] ?? null;
                if ($rawDate !== null) {
                    $takenAt = $this->normalizeExifDate($rawDate);
                }

                $make = $flat['Make'] ?? null;
                $model = $flat['Model'] ?? null;
                if ($model !== null) {
                    $cameraModel = trim(($make ? $make . ' ' : '') . $model);
                }

                foreach (['Make', 'Model', 'ISOSpeedRatings', 'FNumber', 'ExposureTime', 'FocalLength', 'LensModel', 'Orientation'] as $tag) {
                    if (isset($flat[$tag]) && $flat[$tag] !== '') {
                        $tags[$tag] = $flat[$tag];
                    }
                }
            }
        }

        $partitionDate = $takenAt !== null
            ? substr($takenAt, 0, 10)
            : substr($receivedAt, 0, 10);

        return [
            'taken_at' => $takenAt,
            'partition_date' => $partitionDate,
            'camera_model' => $cameraModel,
            'tags' => $tags,
        ];
    }

    /** Converte 'YYYY:MM:DD HH:MM:SS' EXIF in ISO 'YYYY-MM-DD HH:MM:SS'. */
    private function normalizeExifDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        // qualche camera usa già i trattini
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
