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
    public function __construct(private readonly int $maxMegapixels = 80)
    {
    }

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
        // getimagesize legge solo l'header: verifichiamo tipo e dimensioni SENZA
        // decomprimere. Oltre la soglia di megapixel non generiamo (evita OOM).
        $info = @getimagesize($src);
        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            return false;
        }
        $pixels = (int) $info[0] * (int) $info[1];
        if ($pixels > $this->maxMegapixels * 1_000_000) {
            return false;
        }
        // best-effort: alza memory_limit se troppo basso per decodificare l'immagine
        $this->ensureMemory($pixels);

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

    /** Alza memory_limit best-effort se sotto il fabbisogno stimato per decodificare. */
    private function ensureMemory(int $pixels): void
    {
        // GD tiene l'immagine decodificata + copie di lavoro: stima ~5 byte/pixel.
        $needMb = (int) ceil($pixels * 5 / 1048576) + 64;
        $current = $this->memoryLimitMb();
        if ($current > 0 && $current < $needMb) {
            @ini_set('memory_limit', $needMb . 'M');
        }
    }

    private function memoryLimitMb(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return -1; // illimitato
        }
        $unit = strtolower($v[strlen($v) - 1]);
        $num = (int) $v;
        return match ($unit) {
            'g' => $num * 1024,
            'm' => $num,
            'k' => (int) ceil($num / 1024),
            default => (int) ceil($num / 1048576),
        };
    }

    /**
     * Applica la rotazione/ribaltamento indicati dal tag EXIF Orientation.
     * Distrugge sempre la risorsa intermedia dopo una rotazione (niente leak GD).
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

        $rotate = function (\GdImage $im, int $deg): \GdImage {
            $r = imagerotate($im, $deg, 0);
            if ($r instanceof \GdImage) {
                imagedestroy($im);
                return $r;
            }
            return $im;
        };

        switch ($orient) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = $rotate($img, 180);
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $img = $rotate($img, -90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $img = $rotate($img, -90);
                break;
            case 7:
                $img = $rotate($img, 90);
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $img = $rotate($img, 90);
                break;
        }
        return $img;
    }
}
