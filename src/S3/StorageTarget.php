<?php

declare(strict_types=1);

namespace PhotoFacility\S3;

/**
 * Confine di storage: astrae il target su cui vengono caricate le foto.
 *
 * Oggi l'unica implementazione è S3Client (SigV4 a mano). Questa porta rende la
 * scelta reversibile: in futuro un adapter basato su AWS SDK potrà affiancare il
 * client a mano senza toccare Uploader/App (che dipendono solo da questa
 * interfaccia). Le operazioni di sola Fase 2 (presigned GET, listObjects) sono
 * documentate qui come estensione prevista, NON implementate ora.
 */
interface StorageTarget
{
    /**
     * Carica un file (PutObject single-part) con verifica di integrità.
     *
     * @param array<string,string> $meta metadati utente (diventano x-amz-meta-*, firmati)
     * @return array{ok:bool, status:int, etag:?string, sse:?string, error:?string, retriable:bool}
     */
    public function putObject(
        string $bucket,
        string $key,
        string $filePath,
        string $sha256Hex,
        string $md5Base64,
        string $contentType,
        array $meta = [],
        int $timeout = 120
    ): array;

    /**
     * Verifica esistenza/dimensione di un oggetto (riconciliazione / reaper).
     * @return array{ok:bool, exists:bool, status:int, size:?int, error:?string}
     */
    public function headObject(string $bucket, string $key, int $timeout = 15): array;

    /**
     * URL presigned (SigV4 query-string) per un GET a scadenza breve — download
     * full-res direttamente da S3, senza far transitare i byte dal server PHP.
     */
    public function presignGet(string $bucket, string $key, int $expires = 600, ?string $contentDisposition = null): string;

    /** Scarica un oggetto su file locale in streaming (reconciler thumbnail). */
    public function getToFile(string $bucket, string $key, string $dstPath, int $timeout = 120): bool;
}
