# Deploying the real cloud server (Ubuntu 22.04/24.04 VPS)

This is the runbook for standing up the actual, internet-reachable
`cloud`-mode server — what build-plan_1.md's S0-07 describes and this
project has run only against local MySQL/XAMPP until now. Run every
command below yourself, over your own SSH session — nothing here is
executed for you.

Replace `example.com` with your real domain and `soft_off_online` with
whatever database name/user you prefer, consistently, everywhere they
appear below.

---

## 0. Before you start

- [ ] DNS: an `A` record for your domain already points at this VPS's
      IP. HTTPS in step 5 can't issue a certificate until this resolves.
- [ ] You can SSH in as a user with `sudo`.
- [ ] **Getting the code onto the server** — this repo currently has
      **no git remote configured** (found during an earlier audit), so
      `git clone` from GitHub isn't an option yet. Pick one:
      - Push this repo to a GitHub/GitLab remote first (private repo is
        fine), then `git clone` it on the server. Better long-term —
        every future deploy is then `git pull` + a few artisan commands.
      - Or `rsync`/`scp` the project directory straight to the server
        for a first pass, and set up git later. Faster right now, worse
        for the second deploy.
      This runbook assumes you've picked one and the code is about to
      land in `/var/www/soft-off-online` on the server.

---

## 1. System packages

```bash
sudo apt update && sudo apt upgrade -y

# PHP 8.3 — composer.json requires ^8.3; Ubuntu's own repos may lag
# behind, so use Ondřej Surý's PPA for a specific, current version.
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-mysql \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-bcmath \
    php8.3-gd php8.3-intl

# ext-zip specifically: nativephp/desktop (Sprint 7) requires it just to
# let `composer install` complete — the server will never actually use
# NativePHP's desktop packaging (bootstrap/providers.php only loads that
# provider for `native:*` commands or the packaged app's own runtime,
# never on this server), but composer still needs to unpack the package.

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# MySQL 8 — matches what this project has used throughout (the plan's
# own §1 allows "MySQL 8 acceptable if the team knows it better")
sudo apt install -y mysql-server
sudo mysql_secure_installation

# Nginx + Certbot (Let's Encrypt)
sudo apt install -y nginx certbot python3-certbot-nginx

# Node — only needed to build frontend assets (Vite/Tailwind) once per
# deploy; not a long-running dependency
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs

# git — only needed if you're cloning rather than rsyncing
sudo apt install -y git
```

## 2. Database

```bash
sudo mysql -u root -p
```
```sql
CREATE DATABASE soft_off_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'soft_off_online'@'localhost' IDENTIFIED BY 'a-real-generated-password';
GRANT ALL PRIVILEGES ON soft_off_online.* TO 'soft_off_online'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Get the code in place and configure it

```bash
sudo mkdir -p /var/www/soft-off-online
sudo chown -R $USER:$USER /var/www/soft-off-online
cd /var/www/soft-off-online

# Whichever you picked in step 0:
git clone <your-remote-url> .
# — or —
# rsync the project here from your dev machine

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci
npm run build

cp .env.example .env
php artisan key:generate --force
```

Edit `.env` — these are the values that matter for this deployment
(leave everything else at its `.env.example` default unless you have a
specific reason not to):

```ini
APP_NAME="Your App Name"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

# F3 — this has no default; the app refuses to boot without it set
# exactly right, on purpose (a server that silently booted in local
# mode would keep an outbox instead of writing the canonical sync_log,
# and every device would silently stop receiving changes).
APP_MODE=cloud

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=soft_off_online
DB_USERNAME=soft_off_online
DB_PASSWORD=the-real-password-from-step-2

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Do **not** set `SYNC_DEVICE_ID` here — that's a local-laptop-only
setting (`DeviceIdentity`); a cloud install must not have it.

```bash
php artisan migrate --force
php artisan db:seed --force   # optional — creates a demo user + 25 customers, same as local
```

## 4. Web server (Nginx + PHP-FPM)

`/etc/nginx/sites-available/soft-off-online`:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/soft-off-online/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/soft-off-online /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# HTTPS — this is what makes it "the online version": a laptop's
# HttpSyncApi will only ever be pointed at an https:// SYNC_SERVER_URL
sudo certbot --nginx -d example.com
```

```bash
sudo chown -R www-data:www-data /var/www/soft-off-online
sudo chmod -R 775 /var/www/soft-off-online/storage /var/www/soft-off-online/bootstrap/cache
```

## 5. Queue worker (only if you need it)

`QUEUE_CONNECTION=database` above means queued jobs sit in a table until
something processes them. `sync:run` itself is deliberately **not**
queued (S3-08) and is also local-mode-only — the cloud server never
runs it at all. What might still queue: Fortify's password-reset/email
-verification notifications, which `config/fortify.php` only *disables*
in local mode (S0-06) — they're active here. If you're not using those
flows yet, you can skip this section for now; add it the day you are.

`/etc/systemd/system/soft-off-online-worker.service`:

```ini
[Unit]
Description=Soft Off-Online queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/soft-off-online
ExecStart=/usr/bin/php8.3 artisan queue:work --sleep=3 --tries=3
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now soft-off-online-worker
```

No `schedule:run` cron entry is needed on this server — the only
registered schedule (`routes/console.php`'s `sync:run`) is local-only
and simply never runs here.

## 6. Verify it's actually reachable

From your own machine (not the server):

```bash
curl -I https://example.com/api/sync/ping   # expect 204, no auth needed
```

If that doesn't come back clean, don't go further until it does —
everything below depends on it.

## 7. Provision the first real device

Still on the server:

```bash
cd /var/www/soft-off-online
php artisan sync:device:provision "First Laptop" D01 --owner=<a-real-user-id-from-your-users-table>
```

This prints a one-time provisioning code (expires in 7 days). On the
**laptop** (not the server), with a real build of this app pointed at
this domain:

```bash
php artisan sync:register https://example.com D01 <the-provisioning-code>
```

This writes `SYNC_SERVER_URL=https://example.com` and
`SYNC_DEVICE_TOKEN=...` into that laptop's own `.env`. Restart the
laptop app, then "Sync Now" (or `php artisan sync:run`) actually talks
to the real server from here on.

## 8. Backups — don't skip this

`scripts/backup-mysql.ps1`/`restore-mysql.ps1` (S6-07,
`docs/backup-restore-procedure.md`) are **PowerShell** — they don't run
on this Linux VPS as-is. You need a Linux equivalent
(`mysqldump` + cron, or `mysqldump` + a small bash script following the
same shape) before real customer data lives here. Don't treat backups
as "already handled" just because a version of this exists for Windows.

## 9. Every future deploy

```bash
cd /var/www/soft-off-online
git pull                      # if using git — see step 0
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
sudo systemctl restart soft-off-online-worker   # if step 5 is set up
```
