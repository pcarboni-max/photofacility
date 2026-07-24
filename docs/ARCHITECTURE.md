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
   CR3/HEIC box `ftyp`). Un file troncato o non-foto viene messo in quarantena.
4. **Checksum in streaming** — SHA-256 + MD5 calcolati leggendo il file a blocchi di 1 MB
   (nessun RAW in RAM).
5. **Deduplica** — vincolo `UNIQUE` su `checksum_sha256`: una foto già nota (riconnessione
   della camera, reinvio) non genera duplicati su S3.
6. **Rename atomico** — `incoming/ → processing/` sullo stesso filesystem: elimina la race
   tra scrittura FTP e lettura dell'uploader.
7. **Content-MD5 verso S3** — S3 **rifiuta** l'oggetto se il digest non combacia. A upload
   completato si verifica anche l'**ETag** (per PutObject single-part = MD5 hex).

## Tabella Edge Cases & Resilience

| Scenario di errore | Rischio | Soluzione in questa architettura |
| :--- | :--- | :--- |
| Wi-Fi cade durante l'invio FTP | File incompleto su disco | Guardia di quiescenza: mtime instabile ⇒ file mai preso in carico. |
| Camera reinvia la stessa foto | Duplicati su S3 | SHA-256 `UNIQUE` + chiave S3 deterministica ⇒ skip idempotente. |
| Upload parziale con mtime "fermo" | JPEG/RAW troncato su S3 | Magic bytes + `Content-MD5` (S3 rifiuta il digest errato). |
| S3 irraggiungibile | Backlog / blocco | Coda `PENDING_S3` + retry con backoff esponenziale + jitter; dopo N → `QUARANTINE`. |
| Tick di cron sovrapposti | Doppia elaborazione / race | `flock` non bloccante: il secondo tick esce subito. |
| Tick supera il time limit dell'host | Kill a metà, stato incoerente | Budget `MAX_RUNTIME_SECONDS`: si ferma pulito, il resto al tick dopo. |
| Crash a metà upload | File appeso | Stato in DB; il tick successivo riprende i `PENDING_S3`. |
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

## Client S3 senza SDK

`src/S3/S3Client.php` implementa **AWS Signature V4** per `PutObject`:

- Firma con payload signed (SHA-256 già calcolato in validazione).
- Upload in **streaming** dal file (`CURLOPT_INFILE`), nessun caricamento in memoria.
- `Content-MD5` per l'integrità lato S3; verifica ETag lato client.
- Supporta virtual-hosted-style, path-style ed endpoint S3-compatibili (MinIO, Wasabi).

Motivazione: zero dipendenze da caricare su hosting condiviso, footprint minimo, nessun
`vendor/` da mantenere. Se in futuro servisse il **multipart upload** (file > 5 GB o
ripresa a blocchi), si può estendere questa classe o passare all'SDK ufficiale via Composer.

## Cosa NON è incluso (limiti noti della Fase 1)

- **Multipart upload / ripresa**: PutObject single-part. Adeguato fino a file di alcune
  centinaia di MB; oltre, o su reti molto instabili verso AWS, valutare il multipart.
- **UI**: è l'oggetto della Fase 2 (visualizzazione per giorno + tagging EXIF massivo). Lo
  schema DB è già predisposto.
- **Notifiche/alerting**: al momento solo log su file. Un hook di alert (email/Slack) sui
  passaggi in `QUARANTINE` è un'aggiunta naturale.
