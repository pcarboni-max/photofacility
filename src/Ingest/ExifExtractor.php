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
    private \DateTimeZone $homeTz;

    public function __construct(string $timezone = 'Europe/Rome')
    {
        try {
            $this->homeTz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $this->homeTz = new \DateTimeZone('UTC');
        }
    }

    /**
     * @param string $receivedAtUtc data di ricezione in UTC ('Y-m-d H:i:s')
     * @return array{taken_at:?string, partition_date:string, camera_model:?string, tags:array<string,string>}
     */
    public function extract(string $path, string $receivedAtUtc): array
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
                            } elseif (is_array($v)) {
                                // es. ISOSpeedRatings può essere un array: prendi il primo scalare
                                $first = reset($v);
                                if (is_scalar($first)) {
                                    $flat[$k] = (string) $first;
                                }
                            }
                        }
                    }
                }

                $rawDate = $flat['DateTimeOriginal'] ?? $flat['DateTimeDigitized'] ?? $flat['DateTime'] ?? null;
                if ($rawDate !== null) {
                    $takenAt = $this->normalizeExifDate($rawDate);
                }

                $make = trim($flat['Make'] ?? '');
                $model = trim($flat['Model'] ?? '');
                if ($model !== '') {
                    // evita "Canon Canon EOS-1D X" quando Model già contiene Make
                    $cameraModel = ($make !== '' && stripos($model, $make) === false)
                        ? $make . ' ' . $model
                        : $model;
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
            : $this->utcToHomeDate($receivedAtUtc);

        return [
            'taken_at' => $takenAt,
            'partition_date' => $partitionDate,
            'camera_model' => $cameraModel,
            'tags' => $tags,
        ];
    }

    /**
     * Converte 'YYYY:MM:DD HH:MM:SS' EXIF in ISO 'YYYY-MM-DD HH:MM:SS', VALIDANDO
     * la data reale: un orologio camera non impostato scrive '0000:00:00', che
     * ha formato valido ma data impossibile → va scartato (fallback su received).
     */
    private function normalizeExifDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!preg_match('/^(\d{4}):(\d{2}):(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            return null;
        }
        [$y, $mo, $d, $h, $mi, $s] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]];

        // validità del calendario
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }
        // plausibilità: niente date pre-2000 (orologio resettato: 0000/1980/2000-01-01)
        if ($y < 2000) {
            return null;
        }
        // plausibilità: niente date nel futuro oltre 48h (deriva d'orologio).
        // exif è orologio da parete: confronto conservativo trattandolo come UTC.
        $value = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $s);
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            if ($dt->getTimestamp() > time() + 48 * 3600) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $value;
    }

    /** Converte un istante UTC nella data ('Y-m-d') del fuso "di casa". */
    private function utcToHomeDate(string $utc): string
    {
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $dt->setTimezone($this->homeTz)->format('Y-m-d');
        } catch (\Throwable) {
            return substr($utc, 0, 10);
        }
    }
}
