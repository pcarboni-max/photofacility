# Deploy su hosting condiviso (Plesk)

Guida al deploy della Fase 1 in un ambiente con **solo cron** e Composer via Plesk.

## 1. Layout dei file: cosa esporre e cosa NO

Regola di sicurezza fondamentale: **solo `public/` può stare nel document root**. Tutto il
resto (`src/`, `db/`, `.env`, `staging/`) deve stare **fuori** dalla cartella web, così non
è raggiungibile via HTTP.

Layout consigliato:

```
/var/www/vhosts/tuosito/
├── photofacility/          ← FUORI dal document root
│   ├── src/  bin/  db/  staging/  .env
│   └── ...
└── httpdocs/               ← document root (web)
    └── cron.php            ← SOLO se usi il cron via URL (vedi §4b)
```

Se usi il cron via CLI (consigliato), **non serve esporre nulla sul web**: `public/cron.php`
può restare inutilizzato.

> ⚠️ Se il tuo hosting ti costringe a mettere tutto sotto `httpdocs/`, proteggi le cartelle
> sensibili con un `.htaccess` (`Require all denied`) su `src/`, `db/`, `staging/`, `.env`.
> Ma la soluzione pulita resta tenerle fuori dal web root.

## 2. Configurazione

```bash
cp .env.example .env
# compila: S3_BUCKET, S3_REGION, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY
```

I percorsi di default (`staging/`, `db/`) sono relativi alla root del progetto. Se l'account
FTP della Canon deposita in una cartella diversa, imposta `INCOMING_DIR` con il percorso
assoluto di quella cartella.

## 3. Migrazione DB

Da Plesk → **Scheduled Tasks** (o SSH se disponibile), esegui una volta:

```bash
php /var/www/vhosts/tuosito/photofacility/bin/migrate.php
```

Verifica che `db/photofacility.sqlite` sia stato creato e che la cartella `db/` sia
**scrivibile** dall'utente PHP.

## 4. Cron

### 4a. Via CLI (consigliato)

In Plesk → **Scheduled Tasks** → *Run a PHP script* oppure *Run a command*:

```
* * * * * /usr/bin/php /var/www/vhosts/tuosito/photofacility/bin/ingest.php
```

Ogni minuto è il massimo utile: il `flock` garantisce che i tick non si sovrappongano se
uno sfora. Il budget `MAX_RUNTIME_SECONDS` (default 50s) tiene ogni tick sotto il minuto.

### 4b. Via URL (solo se la CLI non è disponibile)

Imposta `CRON_TOKEN` nel `.env`, esponi `public/cron.php` nel web root e schedula:

```
* * * * * wget -q -O - "https://tuosito/cron.php?token=IL_TUO_TOKEN"
```

Meno robusto (soggetto a `max_execution_time` del PHP web), da usare come ripiego.

## 5. Client FTP sulla Canon

Sulla EOS 1D X Mk II/Mk III → menu di rete → impostazioni server FTP:

| Parametro | Valore |
| :--- | :--- |
| Modalità | **FTP** o **FTPS** (usa FTPS se l'hosting lo offre) |
| Indirizzo | host FTP dell'hosting |
| Modalità passiva | **Attiva** (fondamentale dietro NAT/firewall) |
| Cartella di destinazione | la cartella mappata su `staging/incoming/` |
| Utente/Password | account FTP dedicato (vedi §6) |

Consiglio: crea un **account FTP dedicato** con accesso limitato alla sola cartella di
ingestion, non l'account principale del sito.

## 6. Sicurezza

- **Credenziali AWS**: usa un utente IAM con policy a minimo privilegio — solo
  `s3:PutObject` (e `s3:AbortMultipartUpload` se in futuro userai il multipart) sul solo
  bucket/prefix di destinazione. Niente delete, niente list.
- **`.env`** mai versionato (già in `.gitignore`) e fuori dal web root.
- **Account FTP** dedicato e ristretto alla cartella di ingestion.

## 7. Manutenzione e quota disco

- I RAW pesano ~60 MB: con `DELETE_AFTER_UPLOAD=true` (default) il file locale viene
  cancellato **dopo** la conferma S3, così la quota non si satura.
- I file rifiutati finiscono in `staging/failed/` e vengono ripuliti dopo
  `STAGING_RETENTION_HOURS` (default 48h). Controllali ogni tanto per diagnosticare
  problemi ricorrenti (camera con clock sbagliato, formati inattesi, ecc.).
- Le foto in stato `QUARANTINE` sono quelle che hanno esaurito i tentativi di upload:
  vanno ispezionate a mano (probabile problema di credenziali/permessi S3).

## 8. Verifica del funzionamento

```bash
# stato complessivo
php bin/migrate.php          # ristampa i conteggi per stato (idempotente)

# log applicativo
tail -f db/photofacility.log
```

Test sul campo consigliato: scatta e invia una foto dalla Canon, poi **simula una caduta
Wi-Fi** spegnendo l'access point durante l'invio. Verifica che il file parziale NON venga
caricato su S3 e che, al ritentativo della camera, la foto arrivi correttamente senza
duplicati.
