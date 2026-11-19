# PHP Server Monitor — 2026 Edition (`v4.0.0`)

A modernized, security-hardened fork of **PHP Server Monitor** updated for **PHP 8.2 – 8.4**, featuring a dark-first 2026 UI, public status dashboard with per-monitor visibility controls, customizable site title, modern webhook/chat Notification Targets (**Discord**, **Microsoft Teams**, **Home Assistant**, **Generic Webhook**) plus branded HTML email, and zero external CDN runtime dependencies.

> **Upstream Credit & Attribution**
>
> This project is a fork of [**PHP Server Monitor**](https://github.com/phpservermon/phpservermon) (`v3.5.2`), originally created by [Pepijn Over](https://github.com/dopeh) and developed with the PHP Server Monitor team ([Samuel Denis-D'Ortun](https://github.com/sadortun), [Tim Zandbergen](https://github.com/TimZ99), [Jérôme Cabanis](https://github.com/Abawell), and [all upstream contributors](docs/credits.rst)).
> Licensed under the **GNU General Public License v3.0 or later** ([`LICENSE`](LICENSE)).

---

## Table of Contents

- [What's New in v4.0.0](#whats-new-in-v400)
- [Requirements](#requirements)
- [Deploying to a Web Server](#deploying-to-a-web-server)
  - [1. Download or Clone](#1-download-or-clone)
  - [2. Create the MySQL / MariaDB Database](#2-create-the-mysql--mariadb-database)
  - [3. Configure `config.php` & Run the Installer](#3-configure-configphp--run-the-installer)
  - [4. Web Server Configuration (Apache & Nginx)](#4-web-server-configuration-apache--nginx)
  - [5. Set Up the Monitoring Cron Job](#5-set-up-the-monitoring-cron-job)
  - [6. Post-Install Configuration](#6-post-install-configuration)
- [Upgrading from PHP Server Monitor v3.x](#upgrading-from-php-server-monitor-v3x)
- [Credits & License](#credits--license)

---

## What's New in v4.0.0

### PHP 8.2 – 8.4 & Dependency Modernization
- Updated dependencies in `composer.json` for modern PHP (`>=8.2`, tested on PHP `8.4`): **Symfony 6.4**, **Twig 3.14**, and **PHPMailer 6.9+**.
- Standardized database schema on `InnoDB` / `utf8mb4` (via `Installer`).
- Replaced deprecated `strftime()` formatting with `IntlDateFormatter` (`psm_strftime()`).
- Cross-platform ICMP `ping` support (Linux, macOS, and Windows).

### 2026 UI Redesign (100% Self-Hosted Assets)
- Dark-first UI (with light mode toggle) built on self-hosted **Bootstrap 5.3**, **Bootstrap Icons**, **Chart.js 4**, **Tom Select**, and **Inter** font assets — no third-party CDN requests.
- Responsive sidebar layout, KPI status summary cards, interactive latency/uptime history charts, and **24-hour uptime strips** per monitor.
- Vanilla ES6 frontend ([`app.js`](src/templates/default/static/js/app.js)) — **jQuery removed**.
- Crisp matching header mark and generated favicon/PWA icon set (`favicon.svg`, `favicon.png`, `favicon.ico`, `phpservermon.png`).

### Public Status Panel & Site Branding
- **Configurable Site Title**: Set your status page title under **Config → General → Site title** (`psm_site_title()`).
- **Public Status Panel**: Logged-out visitors automatically see a clean public status view with a **Login** button; logged-in admins can preview the public view at `public.php`.
- **Per-Monitor Public Visibility**: Every monitor has a **Public** toggle (`yes` / `no`, **defaulting to Hidden / `no`** so private/internal monitors are never exposed unintentionally).

### Modern Notification Targets & HTML Email
- Legacy SMS gateways, Pushover, Telegram, and Jabber have been replaced with **Notification Targets** (`{prefix}targets` / `{prefix}servers_targets`):
  - **Discord** (Rich Embeds via Webhook URL)
  - **Microsoft Teams** (Adaptive Cards / Incoming Webhooks)
  - **Home Assistant** (REST Service / Event webhook or Persistent Notification with Bearer token)
  - **Generic Webhook** (Custom JSON payload / HMAC-signed webhook endpoints)
  - **Branded HTML Email** (SMTP / PHP `mail()` via PHPMailer with responsive light/dark layout)
- Built-in **Test** action for Targets and **Attach to all monitors** helper.

### Security Hardening
- **`install.php` Auto-Lock**: Once installed and schema is up to date, `install.php` responds with `HTTP 403 Forbidden`.
- **Web Path Lockdown (`.htaccess`)**: Disables directory listings and blocks HTTP access to `config.php`, `composer.*`, `vendor/`, `cron/`, `logs/`, `docs/`, `dev/`, `.git/`, and non-static `src/` files.
- **Session & Remember-Me Cookies**: `HttpOnly`, `SameSite=Lax`, automatic `Secure` flag over HTTPS, `session.use_strict_mode=1`, per-install HMAC cookie secret, and constant-time `hash_equals()` verification.
- **Authentication Protection**: Per-IP failed-login rate limiting (Cloudflare `CF-Connecting-IP` aware) and constant response flow on forgot-password requests to prevent user enumeration.
- **CSRF & XSS/Injection Defenses**: CSRF validation enforced on all state-changing `POST` and `GET` actions (delete, toggle public visibility, apply targets, inline server checks), PDO parameterized queries, safe regex error suppression, private-IP / SSRF guard on outbound target tests, and HTTP security headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`).

---

## Requirements

- **PHP**: `8.2`, `8.3`, or `8.4`
- **PHP Extensions**: `pdo_mysql`, `curl`, `json`, `xml`, `mbstring`, `openssl` (`intl` recommended for localized date formatting)
- **Database**: MySQL `5.7+` / `8.0+` or MariaDB `10.3+`
- **Web Server**: Apache `2.4+` (`mod_rewrite` + `AllowOverride All`) or Nginx `1.18+` with PHP-FPM
- **CLI access / Cron**: Recommended to schedule automated checks (typically every 1–5 minutes)

---

## Deploying to a Web Server

### 1. Download or Clone

#### Option A — Pre-Packaged Release Archive (Recommended for Shared Hosting / cPanel / FTP)
This archive already includes `vendor/` dependencies so **Composer is not required** on the web server.

1. Download `phpservermon-v4.0.0.tar.gz` (or `.zip`) from the [**Releases**](https://github.com/houseofbugs/phpservermon/releases/latest) page.
2. Upload and extract it into your web root (for example `/var/www/status` or `~/your-domain.example`):
   ```bash
   tar -xzf phpservermon-v4.0.0.tar.gz -C /var/www/status
   ```

#### Option B — Clone from Git + Composer (VPS / Dedicated Server)
```bash
git clone https://github.com/houseofbugs/phpservermon.git /var/www/status
cd /var/www/status
composer install --no-dev --optimize-autoloader
```

---

### 2. Create the MySQL / MariaDB Database

Create a dedicated database and user with `utf8mb4` character set:

```sql
CREATE DATABASE phpservermon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'psm_user'@'localhost' IDENTIFIED BY 'CHANGE_TO_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON phpservermon.* TO 'psm_user'@'localhost';
FLUSH PRIVILEGES;
```

---

### 3. Configure `config.php` & Run the Installer

Copy [`config.php.sample`](config.php.sample) to `config.php` and fill in your database details:

```bash
cd /var/www/status
cp config.php.sample config.php
chmod 600 config.php
```

Edit `config.php`:

```php
<?php
define('PSM_DB_PREFIX', 'monitor_');
define('PSM_DB_USER', 'psm_user');
define('PSM_DB_PASS', 'CHANGE_TO_A_STRONG_PASSWORD');
define('PSM_DB_NAME', 'phpservermon');
define('PSM_DB_HOST', 'localhost');
define('PSM_DB_PORT', '3306');
define('PSM_BASE_URL', 'https://status.example.com');
define('PSM_WEBCRON_KEY', '');
```

> **Tip**: Setting `PSM_BASE_URL` ensures links inside notification emails and Discord/Teams alerts use your exact `https://` URL even when triggered from CLI cron.

Now open **`https://status.example.com/install.php`** in your browser to create the database tables and your administrator account.  
Once complete, `install.php` automatically locks itself (`HTTP 403 Forbidden`) for future visits.

---

### 4. Web Server Configuration (Apache & Nginx)

#### Apache 2.4+
Ensure `AllowOverride All` (or `AllowOverride FileInfo Limit Options`) and `mod_rewrite` are enabled so the included [`.htaccess`](.htaccess) rules protect internal directories and files:

```apache
<VirtualHost *:443>
    ServerName status.example.com
    DocumentRoot /var/www/status

    <Directory /var/www/status>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Standard TLS / SSL directives here...
</VirtualHost>
```

#### Nginx + PHP-FPM
Nginx ignores `.htaccess`, so include these security rules directly inside your `server {}` block:

```nginx
server {
    listen 443 ssl http2;
    server_name status.example.com;

    root /var/www/status;
    index index.php;

    # Block internal directories, git files, and non-static src files
    location ~ ^/(vendor|cron|logs|docs|dev|\.git)(/|$) {
        deny all;
        return 403;
    }

    location ~ ^/src/(?!templates/default/static/) {
        deny all;
        return 403;
    }

    # Block sensitive files in root or subdirectories
    location ~* (^#.*#|\.(bak|conf|dist|fla|in[ci]|log|md|orig|psd|rst|sh|sql|sw[op]|lock|phar|yaml|yml)|/(config\.php|composer\.json|Makefile))$ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock; # Adjust to your PHP-FPM socket
    }
}
```

---

### 5. Set Up the Monitoring Cron Job

Schedule `cron/status.cron.php` via CLI cron (recommended — runs directly via PHP without exposing `/cron/` over HTTP):

```cron
* * * * * /usr/bin/php /var/www/status/cron/status.cron.php --uri="https://status.example.com/" >/dev/null 2>&1
```

Available CLI options in [`cron/status.cron.php`](cron/status.cron.php):
- `--uri="https://status.example.com/"` — Base URL used in notification links.
- `--timeout=600` — Override the stale-lock timeout in seconds (default `600`; `0` disables lock timeout).
- `-s on` / `-s off` — Split online and offline checks into separate schedules (for example, checking offline services every minute with 5-second sub-checks while polling healthy services every 5–10 minutes).

> **Note on Webcron**: Direct HTTP requests to `/cron/` are blocked by `.htaccess` (`403 Forbidden`) by default. If you must trigger checks from an external webcron service, set a long random `PSM_WEBCRON_KEY` in `config.php`, explicitly allow `cron/status.cron.php` in your web server config, and call `https://status.example.com/cron/status.cron.php?webcron_key=YOUR_KEY`.

---

### 6. Post-Install Configuration

1. **Site Title & Settings**: Log in as admin → **Config → General** to set your **Site title**, language, auto-refresh interval, and log retention period (days).
2. **Email / SMTP**: Configure SMTP host, port, encryption, and sender address under **Config → Email**.
3. **Notification Targets**: Open **Targets** in the sidebar to add **Discord**, **Microsoft Teams**, **Home Assistant**, or **Generic Webhook** targets, test delivery, and assign targets per monitor (or click **Attach to all monitors**).
4. **Public Status Panel**: In **Servers**, new monitors default to **Hidden (`no`)**. Toggle **Public** on any monitor you want displayed on the unauthenticated status page (`/` or `index.php` when logged out).

---

## Upgrading from PHP Server Monitor v3.x

1. **Back up** your MySQL database (`mysqldump`) and your existing `config.php`.
2. **Remove or replace** the old v3.x files with the `v4.0.0` release files **except `config.php`** (keep your existing `config.php` in place with `chmod 600 config.php`).
3. Visit **`https://status.example.com/install.php`** in your browser to run the database upgrade.
   - Adds the `public` visibility column (`DEFAULT 'no'`). If your v3.x install used a legacy `__PUBLIC__` user assignment list, those public monitors are automatically preserved as `public = 'yes'` and the legacy `__PUBLIC__` row is removed.
   - Creates `{prefix}targets` and `{prefix}servers_targets` (`InnoDB` / `utf8mb4`) and migrates compatible legacy webhook configs (such as Discord/Teams webhooks) into Targets.
4. Confirm your cron job runs under **PHP 8.2+** (on shared hosting, check whether `php` CLI points to PHP 8.2/8.3/8.4 rather than an older system PHP binary).

---

## Credits & License

- **Upstream Project**: [PHP Server Monitor (`phpservermon/phpservermon`)](https://github.com/phpservermon/phpservermon) — Created by Pepijn Over and maintained by the PHP Server Monitor team and contributors. See [`docs/credits.rst`](docs/credits.rst) for the full upstream contributor and translator list.
- **This Fork (`houseofbugs/phpservermon`)**: Modernized for PHP 8.2–8.4, 2026 UI redesign, Public Status Panel & per-monitor visibility controls, Notification Targets system, security hardening, and regenerated SVG/PNG/ICO brand icons.
- **License**: Distributed under the **GNU General Public License v3.0 or later** ([`LICENSE`](LICENSE)).
