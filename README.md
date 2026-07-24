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

Nessun file incompleto o corrotto raggiunge mai S3: la guardia di quiescenza esclude gli
upload parziali, i magic bytes escludono i file non-foto, e `Content-MD5` fa **rifiutare da
S3** qualsiasi oggetto i cui byte non combacino.

## Setup rapido

1. Copia `.env.example` in `.env` e compila bucket, region e credenziali AWS.
2. Configura il cron (ogni minuto):
   ```
   * * * * * /usr/bin/php /percorso/photofacility/bin/ingest.php >> /percorso/photofacility/db/cron.out 2>&1
   ```
3. Configura il client FTP della Canon puntando alla cartella `staging/incoming/`.

> **Lo schema del DB si crea da solo** al primo avvio (auto-migrazione idempotente):
> non serve lanciare `bin/migrate.php` a mano. Utile su hosting **senza SSH**, dove non
> hai una shell. `bin/migrate.php` resta disponibile come comando esplicito/di stato per
> chi può eseguirlo (es. Plesk → Scheduled Tasks).

Dettagli di deploy su Plesk: **[docs/SETUP_SHARED_HOSTING.md](docs/SETUP_SHARED_HOSTING.md)**
Architettura e scelte di design: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**

## Test

```
bash tests/run_tests.sh
```

Avvia un mock S3 locale ed esegue lo smoke test end-to-end (validazione, dedup, firma
SigV4, upload, verifica ETag, lock).

## Struttura

```
bin/ingest.php        entry point cron (CLI)
bin/migrate.php       applica lo schema DB
public/cron.php       entry point web alternativo (cron via URL, protetto da token)
src/Config.php        configurazione da .env
src/App.php           orchestrazione di un tick
src/Ingest/           validazione integrità, EXIF, scansione ingestion
src/S3/               client S3 SigV4 + uploader con retry
src/Database/         schema access (PDO/SQLite)
db/schema.sql         DDL (photos + tabelle Fase 2)
```
