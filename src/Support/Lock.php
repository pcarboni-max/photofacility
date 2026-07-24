<?php

declare(strict_types=1);

namespace PhotoFacility\Support;

/**
 * Lock esclusivo basato su flock().
 *
 * Impedisce che due esecuzioni del cron si sovrappongano: se il ciclo
 * precedente sta ancora processando (upload S3 lento, molte foto), il nuovo
 * tick del cron deve uscire subito senza fare nulla.
 */
final class Lock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $file)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Prova ad acquisire il lock in modo NON bloccante.
     * @return bool true se acquisito, false se un'altra istanza è attiva.
     */
    public function acquire(): bool
    {
        $handle = fopen($this->file, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Impossibile aprire il lock file: {$this->file}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        // scrivi pid + timestamp per diagnostica
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, getmypid() . ' ' . date('c') . PHP_EOL);
        fflush($handle);

        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
