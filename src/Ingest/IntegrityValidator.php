<?php

declare(strict_types=1);

namespace PhotoFacility\Ingest;

/**
 * Verifica l'integrità di un file candidato PRIMA di ammetterlo alla coda S3.
 *
 * Gate applicati (in ordine):
 *   1. Il file esiste, è leggibile, dimensione > 0.
 *   2. Magic bytes: l'header binario corrisponde a un formato foto atteso
 *      (JPEG, TIFF/CR2, CR3/ISO-BMFF). Un file che non matcha NON è una foto.
 *   3. Checksum SHA-256 + MD5 calcolati in streaming (nessun caricamento in RAM).
 */
final class IntegrityValidator
{
    /** Estensioni Canon/foto ammesse. */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'cr2', 'cr3', 'tif', 'tiff', 'heic', 'png'];

    /**
     * @return array{ok:bool, mime:?string, sha256:?string, md5_hex:?string, md5_base64:?string, size:int, reason:?string}
     */
    public function validate(string $path): array
    {
        $fail = static fn (string $reason): array => [
            'ok' => false, 'mime' => null, 'sha256' => null, 'md5_hex' => null,
            'md5_base64' => null, 'size' => 0, 'reason' => $reason,
        ];

        if (!is_file($path) || !is_readable($path)) {
            return $fail('file non leggibile');
        }

        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return $fail('dimensione nulla o non determinabile');
        }

        $mime = $this->detectMagic($path);
        if ($mime === null) {
            return $fail('firma binaria non riconosciuta (possibile file corrotto o troncato)');
        }

        [$sha256, $md5Hex, $md5Base64] = $this->hashStreaming($path);
        if ($sha256 === null) {
            return $fail('impossibile calcolare il checksum');
        }

        return [
            'ok' => true,
            'mime' => $mime,
            'sha256' => $sha256,
            'md5_hex' => $md5Hex,
            'md5_base64' => $md5Base64,
            'size' => $size,
            'reason' => null,
        ];
    }

    public static function hasAllowedExtension(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Riconosce il formato dai primi byte. Ritorna il MIME o null.
     */
    private function detectMagic(string $path): ?string
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $head = fread($fh, 32);
        fclose($fh);
        if ($head === false || strlen($head) < 12) {
            return null;
        }

        // JPEG: FF D8 FF
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (str_starts_with($head, "\x89PNG\x0D\x0A\x1A\x0A")) {
            return 'image/png';
        }
        // TIFF / Canon CR2 (little-endian "II" 0x2A00, big-endian "MM" 0x002A)
        if (str_starts_with($head, "II\x2A\x00") || str_starts_with($head, "MM\x00\x2A")) {
            // CR2 aggiunge la firma "CR" all'offset 8
            if (substr($head, 8, 2) === 'CR') {
                return 'image/x-canon-cr2';
            }
            return 'image/tiff';
        }
        // ISO-BMFF (CR3, HEIC): box 'ftyp' agli offset 4-7
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if ($brand === 'crx ') {
                return 'image/x-canon-cr3';
            }
            if (in_array($brand, ['heic', 'heix', 'mif1', 'msf1'], true)) {
                return 'image/heic';
            }
            return 'application/octet-stream-isobmff';
        }

        return null;
    }

    /**
     * Calcola SHA-256 e MD5 in un'unica passata in streaming.
     * @return array{0:?string,1:?string,2:?string} [sha256hex, md5hex, md5base64]
     */
    private function hashStreaming(string $path): array
    {
        $sha = hash_init('sha256');
        $md5 = hash_init('md5');

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [null, null, null];
        }
        while (!feof($fh)) {
            $chunk = fread($fh, 1 << 20); // 1 MB
            if ($chunk === false) {
                fclose($fh);
                return [null, null, null];
            }
            hash_update($sha, $chunk);
            hash_update($md5, $chunk);
        }
        fclose($fh);

        $sha256 = hash_final($sha);
        $md5Hex = hash_final($md5);
        $md5Base64 = base64_encode((string) hex2bin($md5Hex));

        return [$sha256, $md5Hex, $md5Base64];
    }
}
