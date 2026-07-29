# Architettura — Fase 1

## Contesto e vincolo determinante

Il brief originale ipotizzava un **demone FTP ad eventi (ReactPHP)** con hook post-upload.
Il vincolo di runtime reale è però un **hosting condiviso (Plesk) con solo cron**: niente
processi persistenti, niente controllo sul daemon FTP, niente hook. Questo ha portato alla
**Strada B in variante CRON-driven**:

- Il daemon FTP è quello del provider (non lo gestiamo): la Canon usa un account FTP e
  deposita in una cartella di ingestion.
- La logica di valore è in PHP, invocata dal **cron**, non da un event-loop.
- Nessuna dipendenza runtime: client S3 **SigV4 fatto a mano** (solo `ext-curl`).

## Il ciclo di vita del file

```
RECEIVED ──► (validazione) ──► PENDING_S3 ──► UPLOADING_S3 ──► UPLOADED_S3
                  │                                │
                  ▼                                ▼ (fallimento)
              ERROR / QUARANTINE            PENDING_S3 (retry backoff)
                                                   │
                                                   ▼ (esauriti i tentativi)
                                              QUARANTINE
```

Il **DB è la sorgente di verità** dello stato; il filesystem di staging è transiente. Un
crash o un tick interrotto non perde nulla: il tick successivo riprende tutti i `PENDING_S3`.

## Meccanismi di salvaguardia e integrità

Un file raggiunge S3 solo dopo aver superato, in ordine, tutti questi gate:

1. **Quiescenza** — l'mtime del file è stabile da ≥ `QUIESCENCE_SECONDS`. Rimpiazza l'hook
   post-upload che non abbiamo: durante il trasferimento FTP l'mtime cambia di continuo,
   quindi un upload parziale non viene mai preso in carico.
2. **Estensione** ammessa (jpg/jpeg/cr2/cr3/tif/heic/png).
3. **Magic bytes** — l'header binario conferma il formato (JPEG `FF D8 FF`, CR2 `II 2A … CR`,
   CR3/HEIC box `ftyp`). Un file non-foto viene messo in quarantena.
4. **Trailer di completezza** — per i formati con marcatore di fine noto (JPEG `FF D9`, PNG
   `IEND`) si verifica che il file termini correttamente: è questo che intercetta un upload
   FTP **troncato** che la sola quiescenza mtime non coglie.
5. **Checksum in streaming** — SHA-256 + MD5 calcolati leggendo il file a blocchi di 1 MB
   (nessun RAW in RAM).
6. **Deduplica** — vincolo `UNIQUE` su `checksum_sha256`: una foto già nota (riconnessione
   della camera, reinvio) non genera duplicati su S3.
7. **Rename atomico** — `incoming/ → processing/` sullo stesso filesystem: elimina la race
   tra scrittura FTP e lettura dell'uploader.
8. **Content-MD5 verso S3** — S3 **rifiuta** l'oggetto se il digest non combacia. A upload
   completato si verifica anche l'**ETag** — con l'accortezza che con **SSE-KMS** o multipart
   l'ETag non è l'MD5, quindi in quei casi ci si affida al solo Content-MD5.
9. **`UNIQUE(s3_bucket, s3_key)`** — due righe non possono puntare alla stessa chiave S3:
   l'INSERT fallisce invece di causare una sovrascrittura silenziosa.

## Tabella Edge Cases & Resilience

| Scenario di errore | Rischio | Soluzione in questa architettura |
| :--- | :--- | :--- |
| Wi-Fi cade durante l'invio FTP | File incompleto su disco | Guardia di quiescenza: mtime instabile ⇒ file mai preso in carico. |
| Camera reinvia la stessa foto | Duplicati su S3 | SHA-256 `UNIQUE` + chiave S3 deterministica ⇒ skip idempotente. |
| Upload parziale con mtime "fermo" | JPEG/RAW troncato su S3 | Magic bytes + `Content-MD5` (S3 rifiuta il digest errato). |
| S3 irraggiungibile | Backlog / blocco | Coda `PENDING_S3` + retry con backoff esponenziale + jitter; dopo N → `QUARANTINE`. |
| Tick di cron sovrapposti | Doppia elaborazione / race | `flock` non bloccante: il secondo tick esce subito. Il **loop-within-cron** tiene un solo processo vivo che si rinnova ogni minuto. |
| Tick supera il time limit dell'host | Kill a metà, stato incoerente | Budget di tempo (`LOOP_DURATION_SECONDS`/`MAX_RUNTIME_SECONDS`), controllato anche tra un upload e l'altro; `CURLOPT_TIMEOUT` allineato al tempo residuo. |
| Crash a metà upload (record in `UPLOADING_S3`) | Foto persa in silenzio (la coda non la riprende) | **Reaper** a inizio ciclo: i record `UPLOADING_S3` più vecchi di `REAPER_STUCK_MINUTES` vengono riconciliati via `HeadObject` (già su S3 → `UPLOADED`; altrimenti → `PENDING_S3`). |
| Disco quasi pieno (backlog per S3 down) | Falliscono upload FTP e scritture SQLite | **Freno**: sotto `DISK_MIN_FREE_MB` si sospende l'ammissione di nuovi file e si continua solo a drenare la coda S3 (che libera spazio). Alert. |
| Errore S3 non ritentabile (403/400) | 8 retry sprecati | Distinzione retriabile/non-retriabile: un 4xx va subito in `QUARANTINE`. |
| Guasto silenzioso non presidiato | Nessuno se ne accorge | **Alerting** (webhook/email) su quarantena, backlog grande/invecchiato, disco pieno; `health.json` a ogni ciclo. |
| Quota disco satura (RAW da 60 MB) | Scritture FTP falliscono | `DELETE_AFTER_UPLOAD` cancella dopo conferma S3; cleanup di `failed/` a scadenza. |
| Nome file ripetuto dalla camera | Sovrascrittura | UUID interno per lo staging + chiave S3 con hash+data; il nome originale è solo metadato. |
| Clock camera errato | Foto nel giorno sbagliato (Fase 2) | Partizione da EXIF `DateTimeOriginal` con fallback alla data di ricezione; entrambe in DB. |
| File non-foto nella cartella | Upload spazzatura | Gate estensione + magic bytes ⇒ quarantena. |

## Schema database

Vedi `db/schema.sql`. In sintesi:

- **`photos`** — ciclo di vita completo (stato, integrità, storage S3, retry, date).
- **`photo_exif`** — metadati EXIF chiave-valore (Fase 2: tagging massivo senza migrazioni).
- **`bulk_operations`** — audit delle operazioni massive (Fase 2).
- **`ingest_events`** — log applicativo per osservabilità.

Indici pronti per la Fase 2: `partition_date` (pagina per giorno) ed `exif_taken_at`.

### Porting MariaDB

SQLite basta per il volume della Fase 1 (max ~15 foto/min). Se la Fase 2 introduce UI
concorrente + più writer, migra a MariaDB: `TEXT`→`VARCHAR`/`DATETIME`, `datetime('now')`→
`CURRENT_TIMESTAMP`, `AUTOINCREMENT`→`AUTO_INCREMENT`, `ENGINE=InnoDB`. Il codice accede al
DB solo via PDO, quindi il cambio di driver è localizzato in `src/Database/`.

## Storage: la porta `StorageTarget` e il client S3

`Uploader` e `App` dipendono dall'interfaccia **`StorageTarget`** (`putObject`, `headObject`),
non dal client concreto. L'unica implementazione oggi è `src/S3/S3Client.php`, che implementa
**AWS Signature V4**:

- Firma con payload signed (SHA-256 già calcolato in validazione).
- Upload in **streaming** dal file (`CURLOPT_INFILE`), nessun caricamento in memoria.
- `Content-MD5` per l'integrità lato S3; verifica ETag lato client (consapevole di SSE-KMS).
- **Metadati `x-amz-meta-*` firmati** (nome originale, sha256, data scatto, partizione): il
  bucket diventa **autodescrittivo** e il DB ricostruibile.
- Supporta virtual-hosted-style, path-style ed endpoint S3-compatibili (MinIO, Wasabi).

La porta rende la scelta **reversibile**: un adapter basato su AWS SDK (per presigned URL e
`ListObjects` in Fase 2, o multipart per file grandi) potrà affiancare il client a mano senza
toccare `Uploader`/`App`. Motivazione del client a mano in Fase 1: footprint minimo, nessuna
dipendenza runtime, e copre esattamente ciò che serve (`PutObject`+`HeadObject`).

## Nessun endpoint web

L'ingestion gira **solo** via cron CLI (`bin/ingest.php`). Non esiste alcun entry-point HTTP:
questo elimina l'unica superficie d'attacco web (token nei log, timeout del web server,
info-disclosure) senza perdere funzionalità, dato che il cron CLI di Plesk è operativo.

## Cosa NON è incluso (limiti noti della Fase 1)

- **Multipart upload / ripresa**: PutObject single-part. Adeguato al carico JPEG; per RAW
  molto grandi su reti instabili si aggiungerebbe un adapter dietro `StorageTarget`.
- **AWS SDK**: non adottato in Fase 1 (il client a mano copre `PutObject`+`HeadObject`).
  Previsto al confine della Fase 2, quando servono presigned URL e `ListObjects`.
- **UI**: è l'oggetto della Fase 2 (galleria per giorno + tagging EXIF massivo). Lo schema DB
  è già predisposto (incl. `bulk_operation_items` per l'undo del tagging).
