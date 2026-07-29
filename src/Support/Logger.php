<?php

declare(strict_types=1);

namespace PhotoFacility\Support;

/**
 * Logger a file, append-only, con livelli.
 *
 * Volutamente semplice: su hosting condiviso non abbiamo un demone di logging,
 * quindi scriviamo su file di testo ruotabili manualmente (o via logrotate del
 * provider se disponibile).
 */
final class Logger
{
    public const DEBUG = 'DEBUG';
    public const INFO = 'INFO';
    public const WARN = 'WARN';
    public const ERROR = 'ERROR';

    public function __construct(
        private readonly string $file,
        private readonly bool $debug = false,
    ) {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write(self::DEBUG, $message, $context);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write(self::INFO, $message, $context);
    }

    public function warn(string $message, array $context = []): void
    {
        $this->write(self::WARN, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write(self::ERROR, $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $ts = date('Y-m-d H:i:s');
        $ctx = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $line = "[{$ts}] {$level}: {$message}{$ctx}" . PHP_EOL;
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);

        // in modalità CLI mostra anche a video per feedback immediato
        if (PHP_SAPI === 'cli') {
            fwrite($level === self::ERROR ? STDERR : STDOUT, $line);
        }
    }
}
