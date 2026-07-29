<?php

declare(strict_types=1);

namespace PhotoFacility\Thumbnail;

/**
 * Generazione di thumbnail e preview (GD).
 *
 * Fase 2: solo JPEG. I formati non-preview (RAW, ecc.) non producono immagine
 * e vengono segnati SKIPPED a monte. Applica l'orientamento EXIF così le foto
 * verticali non risultano ruotate.
 */
final class ThumbnailGenerator
{
    /** Solo JPEG genera preview, per ora. Senza GD, nessuna anteprima (SKIPPED). */
    public function isPreviewable(string $mime): bool
    {
        return $mime === 'image/jpeg' && extension_loaded('gd') && function_exists('imagecreatefromjpeg');
    }

    /**
     * Ridimensiona un JPEG mantenendo le proporzioni: lato lungo <= $maxSide.
     * @return bool true se generato.
     */
    public function resizeJpeg(string $src, string $dst, int $maxSide, int $quality = 82): bool
    {
        $img = @imagecreatefromjpeg($src);
        if ($img === false) {
            return false;
        }
        $img = $this->applyExifOrientation($img, $src);

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            return false;
        }

        // non ingrandire: se già più piccola, mantieni la dimensione originale
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dstImg = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dstImg, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $dir = dirname($dst);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ok = imagejpeg($dstImg, $dst, $quality);

        imagedestroy($img);
        imagedestroy($dstImg);
        return $ok;
    }

    /**
     * Applica la rotazione/ribaltamento indicati dal tag EXIF Orientation.
     * @param \GdImage $img
     * @return \GdImage
     */
    private function applyExifOrientation(\GdImage $img, string $src): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($src);
        $orient = is_array($exif) ? (int) ($exif['Orientation'] ?? 0) : 0;
        if ($orient <= 1) {
            return $img;
        }

        switch ($orient) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = imagerotate($img, 180, 0);
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $img = imagerotate($img, -90, 0);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $img = imagerotate($img, -90, 0);
                break;
            case 7:
                $img = imagerotate($img, 90, 0);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $img = imagerotate($img, 90, 0);
                break;
        }
        return $img;
    }
}
