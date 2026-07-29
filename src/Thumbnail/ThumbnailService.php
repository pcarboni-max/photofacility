<?php

declare(strict_types=1);

namespace PhotoFacility\Thumbnail;

use PhotoFacility\Config;
use PhotoFacility\Database\PhotoRepository;
use PhotoFacility\Support\Logger;

/**
 * Orchestrazione della generazione thumbnail/preview e aggiornamento stato DB.
 * Le immagini vivono nella cache locale (fuori dal webroot), servite dal
 * passthrough public/media.php.
 */
final class ThumbnailService
{
    public function __construct(
        private readonly Config $config,
        private readonly PhotoRepository $repo,
        private readonly ThumbnailGenerator $gen,
        private readonly Logger $log,
    ) {
    }

    public function isPreviewable(string $mime): bool
    {
        return $this->gen->isPreviewable($mime);
    }

    /**
     * Genera thumb+preview da un file locale e aggiorna thumb_status.
     * @param array<string,mixed> $row riga photos
     */
    public function processLocal(array $row, string $src): void
    {
        $id = (int) $row['id'];
        $uuid = (string) $row['uuid'];
        $mime = (string) $row['mime_detected'];

        if (!$this->isPreviewable($mime)) {
            $this->repo->setThumb($id, 'SKIPPED', null, null);
            return;
        }

        $thumb = $this->config->cacheDir . '/thumbs/' . $uuid . '.jpg';
        $preview = $this->config->cacheDir . '/previews/' . $uuid . '.jpg';

        $okT = $this->gen->resizeJpeg($src, $thumb, $this->config->thumbWidth);
        $okP = $this->gen->resizeJpeg($src, $preview, $this->config->previewWidth);

        if ($okT && $okP) {
            $this->repo->setThumb($id, 'READY', $thumb, $preview);
            return;
        }

        // Il file è presente ma GD non lo decodifica (JPEG corrotto/non standard):
        // stato TERMINALE 'SKIPPED' (placeholder in UI; EXIF e download restano),
        // così il reconciler non lo riscarica all'infinito.
        @unlink($thumb);
        @unlink($preview);
        $this->repo->setThumb($id, 'SKIPPED', null, null);
        $this->log->warn('Immagine non decodificabile: nessuna anteprima', ['id' => $id, 'file' => $row['original_filename'] ?? '']);
    }
}
