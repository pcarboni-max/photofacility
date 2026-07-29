<?php

declare(strict_types=1);

namespace PhotoFacility\Support;

/**
 * Loader .env minimale + accesso tipizzato alle variabili d'ambiente.
 *
 * Su hosting condiviso le credenziali NON vanno mai committate: vivono in un
 * file .env fuori dal document root (o comunque non versionato) e vengono
 * lette qui una sola volta all'avvio.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_file($path)) {
            return; // in produzione le variabili possono arrivare anche da getenv()
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // rimuovi un eventuale commento inline (solo se il valore NON è tra apici):
            //   "KEY=val # nota"  -> "val"
            //   "KEY=   # nota"   -> ""   (valore vuoto + solo commento)
            // Un '#' NON preceduto da spazio e non iniziale viene preservato
            // (es. un frammento di URL "https://x#y" resta intatto).
            $isQuoted = strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")));
            if (!$isQuoted) {
                if (str_starts_with($value, '#')) {
                    $value = ''; // l'intero valore è un commento
                } elseif (preg_match('/\s#/', $value, $mm, PREG_OFFSET_CAPTURE)) {
                    $value = rtrim(substr($value, 0, $mm[0][1]));
                }
            }

            // rimuovi eventuali apici
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (!self::$loaded) {
            // fallback: nessun load() esplicito, usa solo l'ambiente reale
        }
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }
        $env = getenv($key);
        if ($env !== false) {
            return $env;
        }
        return $default;
    }

    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Variabile d'ambiente obbligatoria mancante: {$key}");
        }
        return $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
