<?php

declare(strict_types=1);

namespace PhotoFacility\Database;

use PDO;

/**
 * Wrapper PDO su SQLite in modalità WAL.
 *
 * WAL è essenziale: consente a un lettore (es. futura UI di Fase 2) di leggere
 * mentre l'ingestion scrive, senza "database is locked". Impostiamo anche un
 * busy_timeout perché il cron è single-writer ma vogliamo tolleranza.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->pdo->exec('PRAGMA busy_timeout = 5000;');
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(string $schemaFile): void
    {
        if (!is_file($schemaFile)) {
            throw new \RuntimeException("Schema non trovato: {$schemaFile}");
        }
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new \RuntimeException("Impossibile leggere lo schema: {$schemaFile}");
        }
        $this->pdo->exec($sql);
    }
}
