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

## 3. Migrazione DB (automatica — nessun SSH necessario)

**Non devi lanciare nulla a mano.** Lo schema del database si crea da solo, in modo
idempotente, al primo avvio dell'applicazione (primo tick del cron o prima chiamata
all'endpoint web). Assicurati solo che la cartella `db/` sia **scrivibile** dall'utente PHP.

Se preferisci crearlo esplicitamente *prima* di attivare il cron, hai tre modi — tutti
senza SSH:

- **Via browser** (il più rapido): imposta `CRON_TOKEN` nel `.env`, esponi `public/cron.php`
  nel web root e visita una volta `https://tuosito/cron.php?token=IL_TUO_TOKEN`. La prima
  chiamata crea lo schema e restituisce lo stato in JSON.
- **Via Plesk → Scheduled Tasks**: aggiungi un task una tantum *Run a PHP script* che punta a
  `bin/migrate.php` (stampa anche i conteggi per stato).
- **Via SSH** (se un giorno lo attivi): `php bin/migrate.php`.

Verifica poi che `db/photofacility.sqlite` sia stato creato.

## 4. Cron

### 4a. Via CLI (consigliato)

In Plesk → **Scheduled Tasks** → *Run a PHP script* oppure *Run a command*:

```
* * * * * /usr/bin/php /var/www/vhosts/tuosito/photofacility/bin/ingest.php
```

Ogni minuto è il massimo utile: il `flock` garantisce che i tick non si sovrappongano se
uno sfora. Il budget `MAX_RUNTIME_SECONDS` (default 50s) tiene ogni tick sotto il minuto.

`bin/ingest.php` usa il **loop-within-cron**: acquisisce il lock una volta e cicla per
~`LOOP_DURATION_SECONDS` (default 55s), così c'è quasi sempre un processo vivo e la latenza
scende da ~1 min a pochi secondi. Il `flock` fa uscire subito il tick del minuto successivo
se il precedente è ancora attivo.

### 4b. Via URL (solo se la CLI non è disponibile)

Imposta `CRON_TOKEN` nel `.env`, esponi `public/cron.php` nel web root e schedula (token
**via header**, non in querystring, per non lasciarlo nei log):

```
* * * * * wget -q -O - --header="X-Cron-Token: IL_TUO_TOKEN" "https://tuosito/cron.php"
```

Meno robusto (soggetto a `max_execution_time` del PHP web) e fa un solo passaggio, non il
loop: da usare come ripiego.

### 4c. Altri task schedulati (Plesk → Scheduled Tasks)

| Task | Frequenza consigliata | Comando |
| :--- | :--- | :--- |
| Backup DB su S3 | giornaliera | `php bin/backup.php` |
| Manutenzione DB | mensile | `php bin/maintain.php` |
| Requeue quarantena | "Run Now" all'occorrenza | `php bin/requeue.php` |

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

**Rischio #1 — layout di deploy.** Il pericolo maggiore è *dove* installi i file: solo
`public/` va nel document root; `.env`, `db/`, `staging/`, `src/` **fuori**. Verifica pratica
subito dopo il deploy: prova a scaricare da browser `…/.env` e `…/db/photofacility.sqlite` →
devono dare **403/404**. In subordine, proteggi quelle cartelle con `.htaccess`
(`Require all denied`).

- **Bucket S3 privato**: attiva **S3 Block Public Access**. Le foto personali non devono mai
  essere pubbliche; la lettura in Fase 2 avverrà solo via URL presigned.
- **Credenziali AWS**: utente IAM a minimo privilegio — `s3:PutObject` (+ `s3:GetObject`/
  `s3:HeadObject` per la riconciliazione del reaper e per la Fase 2) sul solo bucket/prefix.
  Niente `Delete`, niente `ListBucket`: una chiave rubata non potrebbe esfiltrare le foto già
  caricate. Ruota le chiavi periodicamente (Lightsail non ha instance role IAM).
- **`.env`** mai versionato (già in `.gitignore`), fuori dal web root, permessi `600`.
- **Account FTP** dedicato e ristretto (chroot) alla sola cartella di ingestion; **FTPS** se
  disponibile.

## 6bis. Alerting (fortemente consigliato)

Su una pipeline non presidiata, un guasto silenzioso è il rischio peggiore. Imposta almeno
`ALERT_WEBHOOK_URL` nel `.env` (incoming webhook di Slack/Discord/Telegram-bot o un endpoint
tuo): riceverai una notifica quando una foto va in **quarantena**, quando il **backlog** cresce
o invecchia, o quando il **disco** è quasi pieno. Se lo lasci vuoto, gli alert restano solo nel
log e dovrai controllarli a mano.

## 7. Manutenzione e quota disco

- I RAW pesano ~60 MB: con `DELETE_AFTER_UPLOAD=true` (default) il file locale viene
  cancellato **dopo** la conferma S3, così la quota non si satura.
- I file rifiutati finiscono in `staging/failed/` e vengono ripuliti dopo
  `STAGING_RETENTION_HOURS` (default 48h). Controllali ogni tanto per diagnosticare
  problemi ricorrenti (camera con clock sbagliato, formati inattesi, ecc.).
- Le foto in stato `QUARANTINE` sono quelle che hanno esaurito i tentativi di upload:
  vanno ispezionate a mano (probabile problema di credenziali/permessi S3).

## 8. Verifica del funzionamento

Senza SSH, lo stato si legge senza `tail`:

- **`db/health.json`** — scritto a ogni ciclo: ultimo run (UTC), conteggi per stato, backlog,
  MB in staging, spazio disco libero. Scaricalo via **FTP** o esponilo dietro token.
- **`db/photofacility.log`** — log applicativo, scaricabile via FTP.
- **`bin/migrate.php`** (via Plesk "Run Now") ristampa i conteggi per stato.

Test sul campo consigliato: scatta e invia una foto dalla Canon, poi **simula una caduta
Wi-Fi** spegnendo l'access point durante l'invio. Verifica che il file parziale NON venga
caricato su S3 (finisce in `staging/failed/` o resta in attesa) e che, al ritentativo della
camera, la foto arrivi correttamente senza duplicati.
