# PHP Vanilla Framework — Portable Technical Blueprint

Questo documento descrive l'architettura tecnica riutilizzabile estratta da questo progetto. Tutto ciò che è descritto qui è indipendente dalla logica applicativa specifica e può essere applicato a qualsiasi nuovo progetto PHP.

---

## Indice

1. [Stack Tecnologico](#1-stack-tecnologico)
2. [Struttura Directory](#2-struttura-directory)
3. [Routing](#3-routing)
4. [Database](#4-database)
5. [Sistema di Migrazioni](#5-sistema-di-migrazioni)
6. [Configurazione e Variabili d'Ambiente](#6-configurazione-e-variabili-dambiente)
7. [Email e SMTP](#7-email-e-smtp)
8. [Autenticazione via Token Email](#8-autenticazione-via-token-email)
9. [Flash Messages e PRG Pattern](#9-flash-messages-e-prg-pattern)
10. [Internazionalizzazione (i18n)](#10-internazionalizzazione-i18n)
11. [Frontend (No Build System)](#11-frontend-no-build-system)
12. [Docker e Containerizzazione Locale](#12-docker-e-containerizzazione-locale)
13. [Makefile — Automazione](#13-makefile--automazione)
14. [Deployment su Hosting Tradizionale (FTP)](#14-deployment-su-hosting-tradizionale-ftp)
15. [Sicurezza](#15-sicurezza)
16. [Checklist Nuovo Progetto](#16-checklist-nuovo-progetto)

---

## 1. Stack Tecnologico

| Componente | Tecnologia |
|---|---|
| Linguaggio | PHP 8.3 |
| Web Server | Apache 2.4 (mod_php, no PHP-FPM) |
| Database primario | SQLite 3 (file-based, zero configurazione) |
| Database alternativo | MySQL / MariaDB |
| Container | Docker + Docker Compose |
| Frontend | PHP server-side rendering, CSS vanilla, JS vanilla |
| Email | SMTP nativo PHP (`mail()` o socket), dev mode su file |
| Deploy | FTP via `lftp` + `git archive` |
| Dipendenze esterne | **Nessuna** (no Composer, no npm, no framework) |

**Filosofia:** zero dipendenze esterne. Tutto gira con il PHP standard installato su qualsiasi hosting condiviso o container Docker. Non serve Composer, non serve npm.

---

## 2. Struttura Directory

```
project-root/
├── public/                    # Web root (DocumentRoot Apache)
│   ├── index.php              # Entry point unico — router principale
│   ├── style.css              # Tutti gli stili in un solo file
│   ├── logo.png
│   ├── favicon.ico
│   └── .htaccess              # Rewrite rules + security headers
│
├── src/                       # Logica applicativa
│   ├── config.php             # Costanti di configurazione (da env vars)
│   ├── db.php                 # Database abstraction layer (PDO singleton)
│   ├── mail.php               # Funzioni email/SMTP
│   ├── lang.php               # Sistema i18n
│   ├── utils.php              # Helper: flash, normalizzazione, utility varie
│   ├── pages/                 # Un file PHP per pagina
│   │   ├── home.php
│   │   ├── about.php
│   │   ├── contact.php
│   │   └── ...                # Aggiungere una pagina = creare un file qui
│   └── migrations/            # File PHP numerati per le migrazioni DB
│       ├── 20240101000000_create_migrations_table.php
│       ├── 20240101000001_create_initial_schema.php
│       └── ...
│
├── data/                      # Dati persistenti (volume Docker)
│   ├── app.db                 # SQLite database
│   └── emails/                # Email in formato file (dev mode)
│
├── migrate.php                # Runner migrazioni (CLI o browser)
├── index.php                  # Redirect a public/ (per hosting che non permette DocumentRoot)
├── Dockerfile
├── docker-compose.yml
├── docker-entrypoint.sh
├── Makefile
├── .env.example
├── env.php.example            # Alternativa a .env per hosting condiviso
└── .gitattributes             # Esclude file dev dal git archive (deploy FTP)
```

**Regola chiave:** `public/` è l'unica directory esposta via web. Tutto il resto è fuori dalla web root.

---

## 3. Routing

Il routing è gestito interamente da `public/index.php` tramite query parameter. Nessun framework, nessuna libreria di routing.

### Meccanismo

```php
// public/index.php
session_start();

$page = $_GET['page'] ?? 'home';

// Whitelist esplicita delle pagine valide
$allowed_pages = ['home', 'about', 'contact', 'create', 'confirm', 'api'];

if (!in_array($page, $allowed_pages)) {
    $page = 'home';
}

// API: risposta JSON diretta, senza layout HTML
if ($page === 'api') {
    require __DIR__ . '/../src/pages/api.php';
    exit;
}

// Pagine normali: output buffering + wrap nel layout
ob_start();
require __DIR__ . '/../src/pages/' . $page . '.php';
$content = ob_get_clean();

// Rendering del layout con header, nav, footer
require __DIR__ . '/../src/layout.php';
```

### Root entrypoint e `ROOT_MODE`

Il progetto supporta due modalità di bootstrap:

- `public/index.php` come entrypoint diretto, quando il web server punta già a `public/` come `DocumentRoot`
- `index.php` nella root del progetto, come fallback per hosting condivisi che non permettono di cambiare la `DocumentRoot`

In questo secondo caso, `index.php` definisce la costante `ROOT_MODE` prima di delegare a `public/index.php`:

```php
// index.php
define('ROOT_MODE', true);
require_once __DIR__ . '/public/index.php';
```

Dentro `public/index.php` la funzione helper `asset()` controlla questa costante:

```php
function asset($path) {
    return (defined('ROOT_MODE') && ROOT_MODE ? 'public/' : '') . $path;
}
```

Effetto pratico:

- se l'app parte da `/public/index.php`, `ROOT_MODE` non è definita e gli asset restano `style.css`, `logo.png`, `favicon.ico`
- se l'app parte da `/index.php` nella root, `ROOT_MODE` vale `true` e gli asset diventano `public/style.css`, `public/logo.png`, `public/favicon.ico`

Questo evita di duplicare template o configurazioni tra ambienti diversi: cambia solo il prefisso degli asset statici, mentre router, pagine e logica applicativa restano identici.

### URL Structure

```
/?page=home              # Homepage
/?page=about             # Pagina about
/?page=contact           # Form contatto
/?page=detail&id=42      # Dettaglio elemento
/?page=api&action=foo    # JSON API endpoint
/?lang=it                # Cambio lingua (redirect + cookie)
```

### Aggiungere una pagina

1. Creare `src/pages/nuova-pagina.php`
2. Aggiungere `'nuova-pagina'` all'array `$allowed_pages` in `index.php`
3. Aggiungere il link nel menu

### Apache .htaccess

```apache
# public/.htaccess
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "SAMEORIGIN"
Header set X-XSS-Protection "1; mode=block"

Options -Indexes

<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
```

### API JSON

Le chiamate AJAX usano `?page=api&action={nome_action}`. Il file `src/pages/api.php` fa lo switch sulle action e risponde sempre con `Content-Type: application/json`.

```php
// src/pages/api.php
header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'lista_elementi':
        echo json_encode($db->query('SELECT ...')->fetchAll());
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
}
exit;
```

---

## 4. Database

### Dual-database Support (SQLite + MySQL)

Il sistema supporta sia SQLite che MySQL dallo stesso codice, configurabile via variabile d'ambiente `DB_TYPE`.

**SQLite** è il default: nessun servizio da avviare, il database è un singolo file in `data/`. Perfetto per sviluppo locale e progetti con traffico medio-basso.

**MySQL** si attiva settando `DB_TYPE=mysql` e le relative variabili di connessione.

### Database Abstraction Layer (`src/db.php`)

```php
class Database {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            if (DB_TYPE === 'sqlite') {
                self::$instance = new PDO('sqlite:' . DB_PATH);
            } else {
                $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD);
            }
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
        return self::$instance;
    }
}
```

**Utilizzo in ogni pagina:**
```php
$db = Database::get();
$items = $db->prepare('SELECT * FROM items WHERE active = 1 ORDER BY name');
$items->execute();
$rows = $items->fetchAll();
```

**Prepared statements sempre** — mai concatenazione di stringhe per le query.

### Soft Delete

Tutte le tabelle principali hanno una colonna `deleted_at DATETIME DEFAULT NULL`. Le query escludono sempre i record eliminati con `WHERE deleted_at IS NULL`. Per eliminare un record: `UPDATE items SET deleted_at = datetime('now') WHERE id = ?`.

---

## 5. Sistema di Migrazioni

Nessuna libreria esterna. Il sistema di migrazione è un semplice runner PHP che legge file numerati da `src/migrations/`.

### Come funziona

**`migrate.php`** (root del progetto) — eseguibile via CLI o browser:

```php
// Struttura base di migrate.php
require 'src/config.php';
require 'src/db.php';
require 'src/migrations.php'; // lista ordinata delle migrazioni

$db = Database::get();

// Assicura che la tabella migrations esista
$db->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY, migration TEXT UNIQUE)');

foreach ($migrations as $migration) {
    $name = basename($migration, '.php');
    $exists = $db->prepare('SELECT id FROM migrations WHERE migration = ?');
    $exists->execute([$name]);
    if ($exists->fetch()) continue; // già eseguita

    require $migration;
    runMigration($db);  // funzione definita nel file di migrazione

    $db->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
    echo "✓ $name\n";
}
```

**`src/migrations.php`** — registro ordinato:

```php
$migrations = [
    __DIR__ . '/migrations/20240101000000_create_migrations_table.php',
    __DIR__ . '/migrations/20240101000001_create_initial_schema.php',
    // ...
];
```

**File di migrazione** — ogni file definisce una funzione `runMigration($db)`:

```php
// src/migrations/20240101000001_create_initial_schema.php
function runMigration(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT (datetime('now'))
        )
    ");
}
```

### Convenzioni

- **Naming:** `YYYYMMDDHHMMSS_descrizione_azione.php`
- **Idempotenza:** Usare sempre `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`
- **SQLite vs MySQL:** Le migrazioni devono essere compatibili con entrambi, oppure usare `if (DB_TYPE === 'sqlite') { ... } else { ... }`
- **Esecuzione automatica:** Il `docker-entrypoint.sh` esegue `php -f migrate.php` ad ogni avvio container
- **Su hosting legacy:** Si può visitare `https://dominio.com/migrate.php` dal browser

---

## 6. Configurazione e Variabili d'Ambiente

### Due modi per configurare

**Modo 1 — `.env` + Docker Compose** (ambiente containerizzato):
```
# .env (NON committare nel repo, usare .env.example come template)
PORT=8080
BASE_URL=http://localhost:8080
APP_ENV=                     # vuoto = produzione, qualsiasi valore = non-prod
DB_TYPE=sqlite
DB_PATH=/var/www/html/data/app.db
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
MAIL_FROM=noreply@tuodominio.com
MAIL_FROM_NAME=NomeApp
ADMIN_EMAIL=admin@tuodominio.com
TOKEN_EXPIRY_HOURS=72
DEFAULT_LANG=it
```

**Modo 2 — `env.php`** (hosting condiviso senza Docker):
```php
<?php
// env.php (NON committare nel repo, usare env.php.example come template)
// Bloccato da .htaccess: deny from all per accesso diretto
putenv('DB_TYPE=sqlite');
putenv('DB_PATH=/home/user/data/app.db');
putenv('SMTP_HOST=smtp.tuoprovider.com');
// ...
```

### Caricamento in `src/config.php`

```php
// src/config.php
// Carica env.php se esiste (hosting legacy)
if (file_exists(__DIR__ . '/../env.php')) {
    require __DIR__ . '/../env.php';
}

// Definisce costanti con fallback ai default
define('APP_ENV',    getenv('APP_ENV')    ?: '');
define('BASE_URL',   getenv('BASE_URL')   ?: 'http://localhost:8080');
define('DB_TYPE',    getenv('DB_TYPE')    ?: 'sqlite');
define('DB_PATH',    getenv('DB_PATH')    ?: __DIR__ . '/../data/app.db');
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'app');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASSWORD',getenv('DB_PASSWORD')?: '');
define('SMTP_HOST',  getenv('SMTP_HOST')  ?: 'smtp.example.com');
define('SMTP_PORT',  getenv('SMTP_PORT')  ?: '587');
define('SMTP_USER',  getenv('SMTP_USER')  ?: '');
define('SMTP_PASS',  getenv('SMTP_PASS')  ?: '');
define('MAIL_FROM',  getenv('MAIL_FROM')  ?: 'noreply@example.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'App');
define('ADMIN_EMAIL',getenv('ADMIN_EMAIL')?: '');
define('TOKEN_EXPIRY_HOURS', (int)(getenv('TOKEN_EXPIRY_HOURS') ?: 72));
define('DEFAULT_LANG',getenv('DEFAULT_LANG')?: 'it');
```

### .gitattributes — escludere file dal deploy FTP

```gitattributes
# .gitattributes
Dockerfile          export-ignore
docker-compose.yml  export-ignore
docker-entrypoint.sh export-ignore
.env.example        export-ignore
env.php.example     export-ignore
Makefile            export-ignore
*.lftp              export-ignore
.gitignore          export-ignore
README.md           export-ignore
```

---

## 7. Email e SMTP

### Architettura

`src/mail.php` contiene tutte le funzioni di invio email. **Nessuna libreria esterna** — usa socket PHP diretto o `mail()`.

### Dev Mode (email su file)

Quando `SMTP_HOST` è il valore di default (`smtp.example.com`), le email non vengono inviate ma salvate su file in `data/emails/`:

```php
// src/mail.php
function sendEmail(string $to, string $subject, string $htmlBody): bool {
    if (SMTP_HOST === 'smtp.example.com') {
        // Dev mode: salva su file
        $filename = date('YmdHis') . '_' . md5($to . $subject) . '.html';
        file_put_contents(
            __DIR__ . '/../data/emails/' . $filename,
            "To: $to\nSubject: $subject\n\n$htmlBody"
        );
        return true;
    }
    // Produzione: invia via SMTP
    return sendViaSMTP($to, $subject, $htmlBody);
}
```

### Template Email HTML

Le email usano un template HTML inline con design responsive. Il prefisso del subject cambia in base all'ambiente:

```php
$prefix = APP_ENV ? '[' . strtoupper(APP_ENV) . '] ' : '';
$subject = $prefix . 'NomeApp - ' . $actualSubject;
```

### Struttura tipo per funzioni email

```php
// Per ogni tipo di email, una funzione dedicata
function sendConfirmationEmail(string $to, string $confirmUrl): bool {
    $subject = 'Conferma la tua email';
    $html = buildEmailTemplate(
        title: 'Conferma email',
        body: 'Clicca il bottone per confermare.',
        buttonUrl: $confirmUrl,
        buttonText: 'Conferma',
        buttonColor: '#4361ee'
    );
    return sendEmail($to, $subject, $html);
}
```

---

## 8. Autenticazione via Token Email

Nessuna password. Tutto l'accesso è via link email con token sicuri.

### Tabella `confirmations`

```sql
CREATE TABLE confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token TEXT UNIQUE NOT NULL,
    type TEXT NOT NULL,          -- es: 'verify_email', 'delete_account', 'login'
    target_id INTEGER,           -- id della risorsa collegata
    email TEXT NOT NULL,
    role TEXT,                   -- ruolo dell'utente nel contesto
    confirmed INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT (datetime('now')),
    expires_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX idx_confirmations_token ON confirmations(token);
```

### Flusso completo

```
1. Utente richiede azione (es. registrazione)
2. App genera token: bin2hex(random_bytes(32))
3. App inserisce token in confirmations con expires_at = now + TOKEN_EXPIRY_HOURS
4. App invia email con link: BASE_URL + "?page=confirm&token=" + $token
5. Utente clicca link
6. App verifica token (esiste? non scaduto? non già usato?)
7. App marca token come confirmed=1
8. App esegue l'azione associata al tipo di token
9. Redirect con flash message di successo
```

### Funzioni in `src/db.php`

```php
function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function createConfirmation(PDO $db, string $type, int $targetId, string $email, string $role = ''): string {
    $token = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY_HOURS * 3600);
    $stmt = $db->prepare('INSERT INTO confirmations (token, type, target_id, email, role, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$token, $type, $targetId, $email, $role, $expiresAt]);
    return $token;
}

function verifyConfirmation(PDO $db, string $token): ?array {
    $stmt = $db->prepare('SELECT * FROM confirmations WHERE token = ? AND confirmed = 0 AND expires_at > datetime("now")');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    $db->prepare('UPDATE confirmations SET confirmed = 1 WHERE id = ?')->execute([$row['id']]);
    return $row;
}
```

### Access Token per sessioni persistenti (cookie)

Per entità che necessitano accesso ripetuto senza email ogni volta (es. un admin di club):

```php
// Genera e salva token
$token = generateToken();
$db->prepare('INSERT INTO access_tokens (entity_id, token) VALUES (?, ?)')->execute([$entityId, $token]);

// Setta cookie (valido 1 anno)
setcookie('app_access_' . $entityId, $token, time() + 365 * 24 * 3600, '/', '', true, true);

// Verifica accesso
function hasAccess(PDO $db, int $entityId): bool {
    $cookie = $_COOKIE['app_access_' . $entityId] ?? '';
    if (!$cookie) return false;
    $stmt = $db->prepare('SELECT id FROM access_tokens WHERE entity_id = ? AND token = ?');
    $stmt->execute([$entityId, $cookie]);
    return (bool)$stmt->fetch();
}
```

---

## 9. Flash Messages e PRG Pattern

### Post-Redirect-Get (PRG)

Tutti i form POST seguono il pattern PRG per evitare il re-submit al refresh:

```
POST /submit  →  esecuzione azione  →  setFlash()  →  header('Location: ?page=X')  →  GET ?page=X  →  getFlash()
```

### Implementazione in `src/utils.php`

```php
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}
```

**Tipi di flash:** `success`, `error`, `warning`, `info`

### Rendering nel layout

```php
<?php $flash = getFlash(); ?>
<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?>">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>
```

---

## 10. Internazionalizzazione (i18n)

### Meccanismo

```php
// src/lang.php
function __( string $key): string {
    global $translations;
    return $translations[$key] ?? $key;  // fallback alla chiave se non trovata
}

// Caricamento lingua
$lang = $_COOKIE['lang'] ?? DEFAULT_LANG;
if (!in_array($lang, SUPPORTED_LANGS)) $lang = DEFAULT_LANG;

$translations = require __DIR__ . '/translations/' . $lang . '.php';
```

### Struttura file traduzioni

```php
// src/translations/it.php
return [
    'nav.home'      => 'Home',
    'nav.about'     => 'Chi siamo',
    'form.submit'   => 'Invia',
    'msg.saved'     => 'Salvato con successo',
    'msg.error'     => 'Si è verificato un errore',
    // ...
];

// src/translations/en.php
return [
    'nav.home'      => 'Home',
    'nav.about'     => 'About',
    'form.submit'   => 'Submit',
    'msg.saved'     => 'Saved successfully',
    'msg.error'     => 'An error occurred',
    // ...
];
```

### Cambio lingua

```php
// In public/index.php, prima del routing
if (isset($_GET['lang']) && in_array($_GET['lang'], SUPPORTED_LANGS)) {
    setcookie('lang', $_GET['lang'], time() + 365 * 24 * 3600, '/');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '?page=home'));
    exit;
}
```

### Uso nel template

```php
<a href="?page=about"><?= __('nav.about') ?></a>
```

---

## 11. Frontend (No Build System)

### Filosofia

Un solo file CSS (`public/style.css`), JavaScript vanilla inline nei template, zero dipendenze npm o build step.

### CSS Architecture

```css
/* public/style.css */

/* 1. CSS Custom Properties (Design Tokens) */
:root {
    --bg-primary:   #0f0f1a;
    --bg-secondary: #1a1a2e;
    --bg-card:      #16213e;
    --accent:       #4361ee;
    --text-primary: #e8e8e8;
    --text-muted:   #9999bb;
    --border:       #2a2a4a;
    --success:      #10b981;
    --warning:      #f59e0b;
    --error:        #ef4444;
    --radius:       8px;
}

/* 2. Reset minimo */
/* 3. Layout base (header, nav, main, footer) */
/* 4. Componenti (card, button, form, table, badge, alert) */
/* 5. Utility classes */
/* 6. Media queries */
```

### Componenti JS vanilla ricorrenti

```javascript
// Apertura/chiusura modal
function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
// Chiudi cliccando fuori
window.onclick = e => {
    if (e.target.classList.contains('modal')) closeModal(e.target.id);
};

// Toggle menu mobile
document.querySelector('.hamburger').addEventListener('click', () => {
    document.querySelector('.nav-menu').classList.toggle('open');
});
```

---

## 12. Docker e Containerizzazione Locale

### Dockerfile

```dockerfile
FROM php:8.3-apache

# Dipendenze sistema
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Estensioni PHP
RUN docker-php-ext-install pdo pdo_sqlite

# Abilita mod_rewrite
RUN a2enmod rewrite headers

# Configura Apache: DocumentRoot → public/, abilita .htaccess
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY . .

RUN chmod +x docker-entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost/ || exit 1

ENTRYPOINT ["./docker-entrypoint.sh"]
```

### docker-compose.yml

```yaml
services:
  app:
    build: .
    ports:
      - "${PORT:-8080}:80"
    volumes:
      - ./data:/var/www/html/data      # DB SQLite + email dev
      - ./src:/var/www/html/src        # Hot reload codice PHP
      - ./public:/var/www/html/public  # Hot reload frontend
    environment:
      BASE_URL: ${BASE_URL:-http://localhost:8080}
      APP_ENV: ${APP_ENV:-}
      DB_TYPE: ${DB_TYPE:-sqlite}
      DB_PATH: ${DB_PATH:-/var/www/html/data/app.db}
      DB_HOST: ${DB_HOST:-localhost}
      DB_PORT: ${DB_PORT:-3306}
      DB_NAME: ${DB_NAME:-app}
      DB_USER: ${DB_USER:-root}
      DB_PASSWORD: ${DB_PASSWORD:-}
      SMTP_HOST: ${SMTP_HOST:-smtp.example.com}
      SMTP_PORT: ${SMTP_PORT:-587}
      SMTP_USER: ${SMTP_USER:-}
      SMTP_PASS: ${SMTP_PASS:-}
      MAIL_FROM: ${MAIL_FROM:-noreply@example.com}
      MAIL_FROM_NAME: ${MAIL_FROM_NAME:-App}
      ADMIN_EMAIL: ${ADMIN_EMAIL:-}
      TOKEN_EXPIRY_HOURS: ${TOKEN_EXPIRY_HOURS:-72}
      DEFAULT_LANG: ${DEFAULT_LANG:-it}
    restart: unless-stopped
```

**Nota sui volumi:** `src/` e `public/` sono montati come volumi — modificare un file PHP sul host si riflette immediatamente nel container, senza rebuild. Solo le modifiche al Dockerfile o alle dipendenze sistema richiedono `--build`.

### docker-entrypoint.sh

```bash
#!/bin/bash
set -e

# Crea directory per le email di sviluppo
mkdir -p /var/www/html/data/emails
chown -R www-data:www-data /var/www/html/data
chmod 755 /var/www/html/data

# Esegui migrazioni DB automaticamente ad ogni avvio
php -f /var/www/html/migrate.php

# Avvia Apache
exec apache2-foreground
```

### Aggiungere MySQL in sviluppo

Aggiungere al `docker-compose.yml` il servizio MySQL quando necessario:

```yaml
services:
  app:
    # ... configurazione esistente ...
    depends_on:
      - db
    environment:
      DB_TYPE: mysql
      DB_HOST: db
      DB_NAME: app
      DB_USER: app
      DB_PASSWORD: secret

  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: app
      MYSQL_USER: app
      MYSQL_PASSWORD: secret
      MYSQL_ROOT_PASSWORD: rootsecret
    volumes:
      - db_data:/var/lib/mysql

volumes:
  db_data:
```

---

## 13. Makefile — Automazione

```makefile
.PHONY: start stop migrate push ftp-deploy

# Avvia l'ambiente di sviluppo
start:
	docker compose up -d --build
	@echo "App disponibile su http://localhost:$${PORT:-8080}"

# Ferma i container
stop:
	docker compose down

# Esegui le migrazioni pendenti
migrate:
	docker compose exec app php -f migrate.php

# Push rapido al repository
push:
	git config credential.helper 'cache --timeout=3600'
	git add .
	git commit -m "update"
	git push origin main

# Deploy via FTP (richiede: make ftp-deploy file=prod.lftp)
ftp-deploy:
	@if [ -z "$(file)" ]; then echo "Uso: make ftp-deploy file=prod.lftp"; exit 1; fi
	git push origin main
	rm -rf .deploy && mkdir .deploy
	git archive HEAD | tar -x -C .deploy/
	lftp -f $(file)
	rm -rf .deploy

# Shell nel container
shell:
	docker compose exec app bash

# Log del container
logs:
	docker compose logs -f app
```

---

## 14. Deployment su Hosting Tradizionale (FTP)

Questo framework è compatibile con hosting condiviso che offrono solo FTP + PHP + Apache/MySQL. Non servono Docker, SSH o accesso root.

### Script LFTP

```lftp
# prod.lftp (non committare: contiene credenziali)
open -u ftpuser,ftppassword ftp.tuodominio.com
mirror -R --only-newer --exclude-glob .git --exclude-glob .deploy .deploy/ /public_html/
bye
```

### Processo deploy FTP

```
make ftp-deploy file=prod.lftp
```

Internamente:
1. `git push origin main` — sincronizza il repo
2. `git archive HEAD | tar -x -C .deploy/` — esporta solo i file del repo (rispetta `.gitattributes export-ignore`)
3. `lftp -f prod.lftp` — sincronizza FTP solo i file più recenti
4. `rm -rf .deploy` — pulizia

### .gitattributes per export-ignore

I file di sviluppo vengono esclusi automaticamente dall'archivio:

```gitattributes
Dockerfile            export-ignore
docker-compose.yml    export-ignore
docker-entrypoint.sh  export-ignore
.env.example          export-ignore
env.php.example       export-ignore
Makefile              export-ignore
*.lftp                export-ignore
.gitignore            export-ignore
*.md                  export-ignore
```

### Configurazione su hosting condiviso

1. Caricare tutti i file via FTP (esclusi quelli in `export-ignore`)
2. Copiare `env.php.example` → `env.php` e compilare con le credenziali reali
3. Visitare `https://tuodominio.com/migrate.php` per eseguire le migrazioni
4. Assicurarsi che `data/` sia scrivibile: `chmod 755 data/`

---

## 15. Sicurezza

### Pratiche adottate

| Pratica | Implementazione |
|---|---|
| SQL Injection | Prepared statements PDO ovunque, mai concatenazione |
| XSS | `htmlspecialchars()` su tutti gli output utente |
| CSRF | Token nei form (nascosti, verificati lato server) |
| Path Traversal | Whitelist delle pagine in `index.php` |
| Secure Tokens | `bin2hex(random_bytes(32))` — 256 bit di entropia |
| Cookie sicuri | `HttpOnly`, `Secure`, `SameSite=Strict` |
| Directory listing | `Options -Indexes` in `.htaccess` |
| File nascosti | `.htaccess` blocca accesso a file `.dotfile` |
| Security headers | `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection` |
| env.php | Protetto da accesso diretto browser via `.htaccess` |
| Rate limiting | Tabella `reminder_logs` con IP + timestamp per limitare abusi |

### Rate Limiting

```php
function checkRateLimit(PDO $db, string $ip, int $maxPerMinute = 5, int $cooldownMinutes = 15): bool {
    // Controlla richieste nell'ultimo minuto
    $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND created_at > datetime("now", "-1 minute")');
    $stmt->execute([$ip]);
    if ($stmt->fetchColumn() >= $maxPerMinute) return false;

    // Controlla se è in cooldown
    $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND created_at > datetime("now", ? || " minutes")');
    $stmt->execute([$ip, '-' . $cooldownMinutes]);
    if ($stmt->fetchColumn() >= $maxPerMinute) return false;

    // Logga la richiesta
    $db->prepare('INSERT INTO rate_limits (ip, created_at) VALUES (?, datetime("now"))')->execute([$ip]);
    return true;
}
```

---

## 16. Checklist Nuovo Progetto

Per creare un nuovo progetto basato su questo framework:

### Setup iniziale

- [ ] Copiare la struttura directory (senza `src/pages/` specifici e `src/migrations/` specifiche)
- [ ] Copiare `src/config.php`, `src/db.php`, `src/mail.php`, `src/lang.php`, `src/utils.php`
- [ ] Copiare `public/index.php`, `public/.htaccess`
- [ ] Copiare `Dockerfile`, `docker-compose.yml`, `docker-entrypoint.sh`
- [ ] Copiare `Makefile`, `.env.example`, `env.php.example`, `.gitattributes`
- [ ] Copiare `migrate.php`, `src/migrations.php`

### Personalizzare

- [ ] Rinominare il progetto in `docker-compose.yml` (nome servizio) e `Makefile`
- [ ] Aggiornare `BASE_URL` in `.env`
- [ ] Modificare le costanti in `src/config.php` rimuovendo quelle non necessarie
- [ ] Creare `src/migrations/20240101000000_create_migrations_table.php` (invariato)
- [ ] Creare `src/migrations/20240101000001_create_initial_schema.php` con lo schema del nuovo progetto
- [ ] Aggiornare `src/migrations.php` con i nuovi file
- [ ] Creare `src/pages/home.php` con la home del nuovo progetto
- [ ] Aggiornare la whitelist pagine in `public/index.php`
- [ ] Scrivere `public/style.css` con il design del nuovo progetto
- [ ] Creare i file di traduzione in `src/translations/`
- [ ] Creare `data/` e assicurarsi sia in `.gitignore` (eccetto `.gitkeep`)

### Avvio

```bash
cp .env.example .env
# Editare .env con la configurazione desiderata
make start
# Visita http://localhost:8080
```

### Verifica

- [ ] Home page risponde
- [ ] `data/app.db` creato automaticamente
- [ ] Migrazioni eseguite (log in terminal del container)
- [ ] Invio email salva file in `data/emails/` (dev mode)
- [ ] `.htaccess` funziona (rewrite rules attive)
