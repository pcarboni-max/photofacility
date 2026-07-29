<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 minimale, senza Composer.
 *
 * Su hosting condiviso non possiamo lanciare `composer install` (niente SSH/CLI),
 * quindi mappiamo a mano il namespace PhotoFacility\ sulla cartella src/.
 * Se in futuro userai Composer, questo file resta innocuo.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'PhotoFacility\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
