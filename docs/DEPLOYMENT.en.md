# Running flatlink in production

The condensed English guide: from a blank machine to a working instance. The
[German deployment guide](DEPLOYMENT.md) is the detailed reference — it
walks through Shibboleth metadata, LDAP debugging and hardening step by
step; this page covers the same ground far enough to run an instance
confidently.

## One instance, not a cluster

Before you start: flatlink runs as **one** instance. Links and accounts live
in a single SQLite file, and SQLite takes one writer at a time. Two pods, two
servers behind a load balancer or an active-active setup do not work – not
poorly, but not at all.

This is not a capacity question: one CPU serves 2306 redirects per second. It
is an operations question. If your standard demands several replicas or
zero-downtime updates, stop reading here.

For resilience the classic route remains: one machine, regular backups, a
restart. Coming back up takes about a second and a half.

## 1. Two decisions first

**Public or internal?** A public instance where anyone can register needs
abuse protection: rate limits, a report form, strict custom-name rules,
possibly Safe Browsing. An internal instance for one organisation usually
does not — it needs central sign-in and groups instead. The defaults aim at
the internal case; self-registration and public shortening can be switched
off under *Settings*.

**Where do accounts come from?** Local accounts always work. LDAP / Active
Directory and Shibboleth / SAML / OIDC can run in parallel. Keep at least
one local administrator account — if the directory fails, you would
otherwise be locked out of your own administration.

## 2. Requirements

- **PHP 8.1+** with `json`, `mbstring` (core), `gd` (PNG/PDF export),
  `fileinfo` (logo uploads), `openssl` (SMTP), `ldap` (only for LDAP
  sign-in)
- A web server that can rewrite paths (Apache with `mod_rewrite`, nginx,
  Caddy)
- Write permission for the `data/` directory — nothing else

No database server, no Composer, no build step.

Running containers anyway? Then the [Docker guide](docker.en.md) is the
shorter path — one image, one volume, no steps 3 and 4 of this page.

```bash
# Debian / Ubuntu
sudo apt install php-gd php-mbstring php-ldap
```

## 3. Installation

```bash
cd /var/www
sudo git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink && sudo cp inc/config.example.php inc/config.php
```

Set permissions: the web server writes to `data/` and nowhere else; the
configuration contains credentials and is nobody else's business.

```bash
sudo chown -R root:www-data /var/www/flatlink
sudo find /var/www/flatlink -type d -exec chmod 750 {} \;
sudo find /var/www/flatlink -type f -exec chmod 640 {} \;
sudo mkdir -p /var/www/flatlink/data
sudo chown -R www-data:www-data /var/www/flatlink/data
sudo chmod 700 /var/www/flatlink/data
```

**Better: move `data/` out of the webroot** — it holds password hashes,
valid reset tokens and, in mail mode `log`, complete mails:

```php
'data_dir' => '/var/lib/flatlink',
```

**Set `base_url` — this is not a matter of taste.** Left empty, flatlink
guesses the address from the `Host` header, which is user input: someone
triggering a password-reset mail for someone else's account could point the
link in it at their own domain and capture the token. flatlink therefore
sends **no** mails containing links while `base_url` is missing. The session
cookie's `secure` flag is derived from it as well.

```php
'site_name' => 'Short links of Example University',
'base_url'  => 'https://s.example.org',
```

## 4. Web server

flatlink needs one rewrite: everything that is not a real file goes to
`go.php`, which resolves the short code. Apache reads the bundled
`.htaccess` (requires `AllowOverride All` and `mod_rewrite`). For nginx:

```nginx
server {
    listen 443 ssl;
    server_name s.example.org;
    root /var/www/flatlink;
    index index.php;

    location ~ ^/(inc|data|tests|tools|extension|\.git)(/|$) { deny all; }
    location ~ ^/(Dockerfile|docker-compose\.ya?ml|docker-entrypoint\.sh|\.dockerignore|\.gitignore)$ { deny all; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location / {
        try_files $uri $uri/ /go.php?c=$uri&$args;
    }

    location /api/ {
        rewrite ^/api(/.*)$ /api.php$1 last;
    }
}
```

The blocked directories are not optional: `tests/`, `tools/` and
`extension/` are command-line and build material; better still, do not
upload them at all.

## 5. First start

Open `/admin/` — the first run walks you through creating the first
administrator. Then check *Settings*: it verifies that the data directory is
protected (an active self-test fetches a canary file via your own
`base_url`) and that mail delivery works.

## 6. Mail

```php
'mail' => [
    'mode' => 'smtp',                    // or 'log' for testing: mails go to a file
    'host' => 'mail.example.org',
    'port' => 587,                       // 587 STARTTLS, 465 TLS, 25 in-house relay
    'user' => 'no-reply@example.org',    // empty = no authentication
    'pass' => '…',
    'from' => 'no-reply@example.org',
],
```

In-house relays on port 25 without authentication work: STARTTLS is used
when offered, credentials are only sent encrypted, and flatlink aborts
rather than sending credentials unencrypted.

## 7. LDAP / Active Directory

```php
'ldap' => [
    'enabled'     => true,
    'uri'         => 'ldaps://ldap.example.org:636',
    'base_dn'     => 'ou=people,dc=example,dc=org',
    'user_filter' => '(uid=%s)',         // AD: '(sAMAccountName=%s)'
    'mail_attr'   => 'mail',
    'name_attr'   => 'displayName',
    'auto_create' => false,              // accounts only via approval or directory search
],
```

Sign-in binds as the found user; passwords are never stored. With
`auto_create` off, accounts come from the approval queue (a failed sign-in
leaves a request an admin approves) or directly from *Users → Create from
directory*, which searches the directory by name, identifier or mail.

When sign-in fails and the interface will not say why (deliberately), run:

```bash
php tools/ldap-check.php someuser -p
```

It walks extension → configuration → connection → bind → search → password
and stops at the first broken step with a concrete suggestion.

Directory groups can map to flatlink groups (`group_map`); `group_sync`
decides how they combine with locally assigned ones: `merge` (default),
`replace`, `off`.

## 8. Shibboleth / SAML / OIDC

The web server module (`mod_shib`, `mod_auth_mellon`, `mod_auth_openidc`)
does the authentication; flatlink only reads the server variables it leaves
behind (`REMOTE_USER`, display name, mail). Values arriving as **HTTP
headers** are accepted only from proxies listed in `trusted_proxies` —
anyone can forge a header, and an identity a client can simply claim is no
identity at all. The German guide covers SP metadata and attribute release
in detail.

## 9. Operations

**Updates:** `git pull`. `data/` and `inc/config.php` stay untouched. After
updating, glance at `inc/config.example.php` — new options appear there
first and fall back to sane defaults if absent from your config.

**Backup** is copying `data/` plus `inc/config.php`. For versioned backups
(rsync, borg, git) there is an export that writes the database as SQL text —
a day of operation is a few changed lines, not a new binary:

```bash
php tools/backup-export.php /var/backups/flatlink
```

**A public demo** is one switch: `'demo_mode' => true` shows a notice band
(sign-in `demo / demo-1234`) and rebuilds the whole inventory from a fixed
demo set roughly every `demo_reset_minutes` — lazily on page load, so no
cron is needed and it runs on shared hosting.

**Monitoring:** `inc/probe.php` performs the webroot self-test; the settings
page shows mail and Safe Browsing state. Failed webhook deliveries and Safe
Browsing failures are surfaced in the admin UI rather than logged silently.

## 10. Performance

flatlink itself needs no tuning: a counted redirect costs about 0.2 ms in
PHP, the database connection is reused per worker via
`PDO::ATTR_PERSISTENT`, and click counting is a single append to a file.
What still makes a difference sits with the operator, in descending order
of impact:

1. **PHP-FPM with `mpm_event`** (or nginx + FPM) instead of `mod_php` with
   `mpm_prefork`. Under prefork, every open connection – including an idle
   keep-alive one – pins a full process with PHP inside, and HTTP/2 is
   impossible. The switch raises capacity under load, not the time of a
   single request.
2. **OPcache** is on almost everywhere (the default). The last step is
   `opcache.validate_timestamps=0`: PHP then stops checking files for
   changes. The price is a new duty – **reload `php-fpm` after every
   update**, or the old code keeps running without a word. If you have no
   deployment script to do that, keep the default.
3. **Check `pm.max_children`** of the FPM default: Debian ships 5, meant
   for servers that share their memory with many things. A flatlink worker
   needs ~40 MB; on a dedicated machine a multiple of that is fine.
4. **HTTP/2 and TLS 1.3** help the admin area (parallel assets) and first
   impressions. The individual scan stays network-bound: DNS, TCP and the
   TLS handshake of a cold phone cost three to four round trips before PHP
   even starts – no server tuning changes that.

On **shared hosting** none of this is configurable – and none of it is
needed: pick a current PHP version in the hosting panel, done. That is
exactly the case flatlink is built for.

## 11. When something breaks

- **500 on some pages after an update:** you probably uploaded only part of
  the release. The upload list in each release note names every changed file
  — new files are the ones sync tools like to skip.
- **Sign-in loops or mails without links:** `base_url` is missing or wrong.
- **LDAP says "sign-in failed":** `php tools/ldap-check.php user -p`.
- **`data/` accessible from outside:** the settings page tells you — move it
  out of the webroot or fix the server block above.
