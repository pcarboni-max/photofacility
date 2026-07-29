# PhotoFacility — Fase 1

Ingestion automatica di fotografie **Canon EOS 1D X Mk II / Mk III** verso **AWS S3**.

La fotocamera invia le foto via **FTP** (client integrato nel firmware) alla cartella di
ingestion sull'hosting. Un job **cron** le valida e le carica su **S3**, che diventa il
repository definitivo. Il database (**SQLite**) traccia lo stato di ogni file e predispone
le tabelle per la **Fase 2** (visualizzazione per giorno + tagging EXIF massivo).

> **Runtime target:** hosting condiviso (Plesk) con solo cron. Nessun demone
> persistente, nessuna dipendenza runtime esterna: solo PHP 8.1+ con
> `ext-pdo_sqlite`, `ext-curl`, `ext-hash` (e `ext-exif` consigliata).

## Come funziona (un "tick" di cron)

```
Canon EOS ──FTP──► staging/incoming/
                        │
              cron ► bin/ingest.php (un tick)
                        │
   1. lock esclusivo (flock) ─ esce se un tick è già attivo
   2. scansione incoming/ con guardia di QUIESCENZA (mtime stabile)
   3. per ogni file completo:
        • magic bytes  • checksum SHA-256/MD5 (streaming)
        • dedup (SHA-256 UNIQUE)  • EXIF → data/partizione giorno
        • rename atomico → processing/  • record DB = PENDING_S3
   4. svuotamento coda PENDING_S3 → PutObject S3 (Content-MD5, verifica ETag)
        • ok  → UPLOADED_S3, cancella staging
        • ko  → retry con backoff; dopo N tentativi → QUARANTINE
   5. pulizia staging orfano
   (tutto entro MAX_RUNTIME_SECONDS)
```

**Rileviamo e mettiamo in quarantena i file la cui struttura non è completa**, così non
raggiungono S3: la guardia di quiescenza esclude gli upload ancora in corso, i magic bytes
escludono i file non-foto, il **controllo del trailer** (`FF D9` per JPEG, `IEND` per PNG)
intercetta i troncamenti, e `Content-MD5` fa **rifiutare da S3** qualsiasi oggetto i cui byte
non combacino con il checksum calcolato in locale.

## Setup rapido

1. Copia `.env.example` in `.env` e compila bucket, region e credenziali AWS.
2. Configura il cron (ogni minuto):
   ```
   * * * * * /usr/bin/php /percorso/photofacility/bin/ingest.php >> /percorso/photofacility/db/cron.out 2>&1
   ```
3. Configura il client FTP della Canon puntando alla cartella `staging/incoming/`.

Non c'è nient'altro da lanciare. **Senza accesso a riga di comando**, l'unica cosa che gira
è il cron ricorrente: lo schema del DB si crea da solo al primo tick, e **manutenzione e
backup del DB su S3 avvengono automaticamente una volta al giorno** dentro il cron stesso.

Dettagli di deploy su Plesk: **[docs/SETUP.md](docs/SETUP.md)**
Architettura e scelte di design: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**

## Test

```
bash tests/run_tests.sh
```

Avvia un mock S3 locale ed esegue lo smoke test end-to-end (validazione, dedup, firma
SigV4, upload, verifica ETag, lock).

## Struttura

```
bin/ingest.php        UNICO script del cron (loop-within-cron): ingestione +
                      upload S3 + thumbnail + manutenzione/backup giornalieri
public/index.php      UI galleria (Fase 2): home per giorno + pagina giorno
public/media.php      passthrough thumbnail/preview dalla cache locale
public/dl.php         download full-res (redirect a URL S3 presigned)
public/health.php     diagnostica via web (token, read-only)
public/assets/        CSS + lightbox JS (zero dipendenze)
src/Web/Gallery.php   logica della UI (dati, EXIF, presigned)
src/Thumbnail/        generazione thumbnail/preview (GD)
src/Health/           HealthCheck: diagnostica end-to-end del sistema
src/Config.php        configurazione da .env (config DB disaccoppiata da S3)
src/App.php           orchestrazione (loop, reaper, thumbnail, backup, alerting)
src/Ingest/           validazione integrità, EXIF, scansione + freno disco pieno
src/S3/               client S3 SigV4 (put/head/presign/get) + uploader
src/Support/          Env, Logger, Lock, Notifier
src/Database/         schema access (PDO/SQLite WAL)
db/schema.sql         DDL (photos + tabelle Fase 2)
```

## Fase 2 — interfaccia di visualizzazione

Richiede **`ext-gd`**. UI PHP sul server, immagini su S3.

- **Home** (`index.php`): intestazione (nome ambiente + "torna alla home"), **card per giorno** (`AAAA-MM-GG`, strip di thumbnail, CTA), **5 card/pagina** con paginazione, ordinate dal più recente.
- **Pagina giorno** (`index.php?day=…`): griglia di thumbnail → **lightbox** con navigazione sequenziale; per ogni foto nome, EXIF principali e **download full-res**.
- **Thumbnail** (~300px) e **preview** (~1600px) generate solo per JPEG, in cache locale fuori dal webroot, servite da `media.php`. I RAW mostrano un placeholder ma mantengono EXIF e download. Un **reconciler** nel cron garantisce che ogni foto abbia la sua thumbnail (rigenera le mancanti scaricando da S3).
- **Download**: `dl.php` reindirizza a un **URL S3 presigned** a scadenza breve (i byte non passano dal server).
- **Sicurezza**: nessun login applicativo → proteggi l'intera UI con la **basic auth** del webserver ed esponi solo `public/`. Come difesa in profondità opzionale puoi impostare `UI_TOKEN` nel `.env`: se valorizzato, l'accesso richiede `?k=TOKEN` (poi salvato in cookie), così una basic auth disattivata per sbaglio non lascia tutto aperto.
- **Note operative**: il reconciler scarica il full-res da S3 per le anteprime mancanti → **gratis solo se server e bucket sono nella stessa region**. La cache locale (`cache/`) cresce nel tempo ed è **rigenerabile** (non versionata); `bin/ingest.php` ripulisce da sé i file temporanei di download orfani.

## Diagnostica (via web)

`public/health.php` (protetta da `HEALTH_TOKEN`) esegue un **controllo completo**:
versione/estensioni PHP, config e credenziali (senza mai esporre i segreti), permessi
filesystem, spazio disco, database (schema, WAL, integrità, backlog, quarantena, upload
bloccati), **connettività S3** (read-only, least-privilege) e stato del cron. Verdetto:
`SOLID` / `WARNINGS` / `CRITICAL` (la pagina risponde HTTP 503 se `CRITICAL`, per i monitor
esterni). Apri `https://tuosito/health.php` o `?format=json`. Per uno stato rapido senza
pagina, scarica `db/health.json` via FTP.
