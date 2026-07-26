-- ============================================================
-- PhotoFacility — Schema di stato (Fase 1)
-- SQLite (modalità WAL). Vedi docs per note di porting MariaDB.
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ------------------------------------------------------------
-- photos: ciclo di vita del trasferimento fotocamera -> S3
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photos (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid              TEXT    NOT NULL UNIQUE,             -- id interno (nome file in staging)
    original_filename TEXT    NOT NULL,                    -- nome dato dalla camera
    camera_model      TEXT,                                -- da EXIF se disponibile

    -- stato del ciclo di vita
    status            TEXT    NOT NULL DEFAULT 'RECEIVED'
                      CHECK (status IN ('RECEIVED','VALIDATING','PENDING_S3',
                                        'UPLOADING_S3','UPLOADED_S3','ERROR','QUARANTINE')),
    status_detail     TEXT,                                -- diagnostica / messaggio errore

    -- integrità
    size_bytes        INTEGER,
    checksum_sha256   TEXT    NOT NULL UNIQUE,             -- deduplica / idempotenza
    checksum_md5      TEXT,                                -- per Content-MD5 verso S3
    mime_detected     TEXT,                                -- da magic bytes

    -- storage
    staging_path      TEXT,                                -- percorso locale finché non su S3
    s3_bucket         TEXT,
    s3_key            TEXT,                                -- YYYY/MM/DD/{hash}_{name}
    s3_etag           TEXT,

    -- resilienza upload
    upload_attempts   INTEGER NOT NULL DEFAULT 0,
    next_retry_at     TEXT,                                -- ISO8601; backoff esponenziale
    last_error_at     TEXT,

    -- date (chiave per la Fase 2)
    exif_taken_at     TEXT,                                -- DateTimeOriginal (nullable)
    received_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    uploaded_at       TEXT,
    partition_date    TEXT,                                -- 'YYYY-MM-DD' per pagina/giorno

    -- NOTA FUSO ORARI: received_at/uploaded_at/created_at/updated_at sono UTC
    -- (datetime('now') di SQLite è UTC). exif_taken_at e partition_date sono nel
    -- fuso "di casa" (TIMEZONE, default Europe/Rome), coerenti con la UI Fase 2.
    created_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),

    -- una stessa chiave S3 non può essere usata da due righe: l'INSERT fallisce
    -- invece di causare una sovrascrittura silenziosa su S3.
    UNIQUE (s3_bucket, s3_key)
);

CREATE INDEX IF NOT EXISTS idx_photos_status         ON photos(status);
CREATE INDEX IF NOT EXISTS idx_photos_retry          ON photos(status, next_retry_at);
CREATE INDEX IF NOT EXISTS idx_photos_partition_date ON photos(partition_date);
CREATE INDEX IF NOT EXISTS idx_photos_exif_taken     ON photos(exif_taken_at);

-- Indice parziale per la galleria di Fase 2 (conteggio per giorno + pagina del
-- giorno ordinata per orario di scatto), limitato alle foto effettivamente su S3.
CREATE INDEX IF NOT EXISTS idx_photos_gallery
    ON photos(partition_date, exif_taken_at)
    WHERE status = 'UPLOADED_S3';

-- updated_at aggiornato automaticamente a ogni UPDATE (il reaper dipende dalla
-- sua freschezza, a prescindere dal chiamante).
CREATE TRIGGER IF NOT EXISTS trg_photos_updated_at
AFTER UPDATE ON photos
FOR EACH ROW
BEGIN
    UPDATE photos SET updated_at = datetime('now') WHERE id = OLD.id;
END;

-- ------------------------------------------------------------
-- photo_exif (predisposizione Fase 2): metadati EXIF chiave-valore
-- Modello flessibile: nuovi tag senza migrazioni di schema.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_exif (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    photo_id   INTEGER NOT NULL REFERENCES photos(id) ON DELETE CASCADE,
    tag        TEXT    NOT NULL,       -- es. 'Make','Model','ISO','FNumber','LensModel'
    value      TEXT,
    source     TEXT    NOT NULL DEFAULT 'extracted'
               CHECK (source IN ('extracted','manual','bulk')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (photo_id, tag)
);
CREATE INDEX IF NOT EXISTS idx_exif_photo ON photo_exif(photo_id);
CREATE INDEX IF NOT EXISTS idx_exif_tag   ON photo_exif(tag, value);

-- ------------------------------------------------------------
-- bulk_operations (predisposizione Fase 2): audit tagging massivo
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bulk_operations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    operation     TEXT    NOT NULL,
    payload_json  TEXT,
    affected_rows INTEGER,
    performed_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ------------------------------------------------------------
-- ingest_events: log applicativo per osservabilità
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingest_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    photo_id   INTEGER REFERENCES photos(id) ON DELETE SET NULL,
    event      TEXT NOT NULL,          -- 'received','validated','uploaded','error','retry','skipped_duplicate'
    detail     TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_events_photo ON ingest_events(photo_id);
CREATE INDEX IF NOT EXISTS idx_events_created ON ingest_events(created_at);
