<h1 align="center">flatlink</h1>

<p align="center"> <strong>The self-hosted URL shortener – with a QR
designer and link-in-bio pages.</strong><br> Plain PHP. No database server,
no Composer, no build step –<br> copy the files to your web host and you're
done. </p>

<p align="center"> <a href="LICENSE"><img alt="AGPL-3.0 license"
src="https://img.shields.io/badge/License-AGPL--3.0-1a7f37"></a> <img
alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4"> <img
alt="Zero dependencies"
src="https://img.shields.io/badge/Dependencies-0-0a7ea4"> <img alt="No
database server"
src="https://img.shields.io/badge/Database%20server-none-555"> </p>

<p align="center"> <img src="docs/screenshots/en/link-list.webp" alt="The
link list with tags, groups and click counts" width="820"> </p>

> 🇩🇪 Deutsche Fassung: **[README.de.md](README.de.md)** – German is the project's
> source language; this translation follows it.

---

## What flatlink is

A URL shortener you run yourself: shorten links, generate QR codes fit for
the print shop, build link-in-bio pages – on your domain, in your building.
Plain PHP, no database server, no Composer, no build step.

It is built for universities, libraries and public bodies that are not
allowed to hand their links to an outside service. That is why sign-in via
LDAP and Shibboleth, groups with permissions and limits, namespaces per
department and multiple domains are core, not add-ons.

And it counts visits without recording visitors: no IP addresses, no record
per request, no profiles – [verifiable in the source](#what-is-not-stored),
not merely claimed.

## Features

### Short links

| | |
| --- | --- |
| **Code** | random or custom, with a minimum length and a quota to curb squatting on short names |
| **Order** | a label for your own overview, up to eight tags, filtering and search across the inventory |
| **Time window** | a start date (print the code before the target exists) and an expiry date |
| **Visit limit** | "only the first 50" – after that the link answers with 410 |
| **Protection** | a password before the redirect, and blocking of individual links |
| **Switches** | one link, several targets – by device, language, country or share (A/B). The language switch negotiates against the target's own language, storing nothing in the process |
| **Campaigns** | a builder for `utm_*` parameters, with suggestions from what you already use |
| **Migration** | CSV import from Bitly, YOURLS, Shlink and Kutt – short codes survive; CSV export in the same format |
| **Multiple domains** | one per client or institution, all in a single instance |

### QR codes

| | |
| --- | --- |
| **In-house encoder** | ISO/IEC 18004, byte mode, versions 1–40, all four error correction levels – without any third-party library, verified with a foreign decoder across all 160 combinations |
| **Designer** | seven module shapes, four eye shapes (ring and core separately), free colours, gradients, a frame with text |
| **Logo** | from a library that can be shared with groups; modules under the logo are omitted entirely rather than clipped |
| **Five types** | address/short link, Wi-Fi access, contact (vCard), event (iCalendar), GS1 Digital Link – the last four store nothing at all |
| **Print** | SVG, PNG, vector PDF and EPS, optionally in CMYK – the format print shops ask for |
| **Batches** | twenty codes in one ZIP, with an overview as CSV |
| **Readability check** | contrast, quiet zone, logo coverage and module size, live while you design |

### Link in bio

One page with several targets under a single short code – for the profile on
a social network, the sticker in a shop window. Your own colours and logo,
legal notice and privacy links in the footer (your own or the instance's).
Counted per day, for the page and per target.

### Statistics without visitor profiles

| | |
| --- | --- |
| **What is counted** | visits in total and per calendar day, plus origin, device class and language **as sums** |
| **What does not count** | known bots, HEAD requests and the signed-in owner |
| **What is missing** | times of day, IP addresses, records per visit, recognition – none of it exists, so none of it is in the API either |
| **Switchable** | `'click_dims' => false` leaves the plain counters and nothing else |
| **Export** | CSV per link and across the whole inventory |

### Accounts, groups, sign-in

| | |
| --- | --- |
| **Accounts** | self-registration via double opt-in, password reset, roles, per-account limits |
| **Sign-in** | two-step: a passkey **instead of** the password (WebAuthn, with user verification) or a one-time password (TOTP) beside it, enforceable instance-wide; accounts without one get the offer once a month |
| **Central sign-in** | LDAP and Active Directory with directory search; Shibboleth, SAML and OpenID Connect through the web server |
| **Groups** | as a permission group (rights and limits) or a working group (the team manages the links together) |
| **Namespaces** | a prefix per department – `/lib/opening-hours` belongs to the library |
| **Data access** | export and delete button in the profile, Art. 15/17/20 GDPR without a ticket system |
| **Sessions** | a list of active sign-ins; end one or all others |

### Operations

| | |
| --- | --- |
| **Installation** | copy the files – or use a container image for amd64 and arm64 |
| **Backup** | one button that writes database, settings, counters and logos into a ZIP; plus a text export for versionable backups |
| **API** | links, tags and a health endpoint for monitoring, with access keys per account |
| **Browser extension** | "shorten this page" for Chrome and Firefox, pointed at your own instance |
| **Abuse protection** | rate limits, a report form, blocking, optionally Google Safe Browsing including a re-check pass over the existing links |
| **Audit log** | who administered what, and when – administration only, never visitors |
| **Cleanup** | never-visited links after N years, with advance warning by mail; periods set in the ground rules, off by default |
| **Demo mode** | a public playground that resets itself – without cron |
| **Bilingual** | interface in German or English, switchable at runtime |
| **Accessible** | tested against WCAG 2.1 AA, with a [self-assessment](docs/barrierefreiheit.en.md) and a statement template for public bodies |

### What the others do not have

The comparison with Shlink, YOURLS and Kutt, reduced to features:

- **QR codes fit for the print shop.** Vector PDF, EPS and CMYK from an
  in-house encoder – elsewhere the export stops at PNG.
- **Switches with language negotiation.** A Chinese browser with English as
  a second language gets the English page; a German browser gets the German
  one.
- **Link-in-bio in the same tool**, under the same permissions and counters.
- **Central sign-in for institutions** – Shibboleth and LDAP, not just OAuth
  for individual accounts.
- **Statistics that need no profiles.** The question "where do my clicks
  come from?" is answered without storing a single visit.
- **No database server, no build step.** It runs on a three-euro web host.

What remains exclusive to the commercial services is the thing this project
does not build: visitor profiles.

## What it looks like

<table> <tr> <td width="50%" valign="top"> <a
href="docs/screenshots/en/qr-designer.webp"><img
src="docs/screenshots/en/qr-designer.webp" alt="QR designer with module and
eye shapes, colours and live preview"></a> <p><strong>QR designer.</strong>
Module and eye shapes, free colours, a logo in the middle, a frame with
text. Export as SVG, PNG, vector PDF and EPS, optionally in CMYK – from an
in-house encoder, without any third-party library.</p> <p><strong>Five
types, one generator.</strong> Besides URLs and short links also Wi-Fi
access, contacts (vCard), events (iCalendar) and GS1 Digital Links –
reachable via tabs, with the same design options. These four are static: the
data lives in the code itself, nothing is stored, and they keep working even
without the instance.</p> </td> <td width="50%" valign="top"> <a
href="docs/screenshots/en/qr-batch.webp"><img
src="docs/screenshots/en/qr-batch.webp" alt="QR batch: select several links
and download them as a ZIP"></a> <p><strong>QR batches.</strong> Twenty
table tents in one archive, with a CSV overview for the print shop. flatlink
writes the ZIP itself – even without the PHP <code>zip</code> extension.</p>
<a href="docs/screenshots/en/logo-library.webp"><img
src="docs/screenshots/en/logo-library.webp" alt="Logo library with preview,
renaming and group sharing"></a> <p><strong>Logo library.</strong> Upload
your own logos, rename them and share them with groups – whoever may use one
sees it in the designer and on link-in-bio pages. Sharing means permission
to use, not to manage.</p> </td> </tr> <tr> <td width="50%" valign="top"> <a
href="docs/screenshots/en/new-link.webp"><img
src="docs/screenshots/en/new-link.webp" alt="Form for a new short link with
name, tags and UTM builder"></a> <p><strong>Creating.</strong> Custom name,
a label for your own overview, tags for filtering, expiry date, password
protection and a builder for campaign parameters.</p> </td> <td width="50%"
valign="top" align="center"> <a
href="docs/screenshots/en/link-in-bio.webp"><img
src="docs/screenshots/en/link-in-bio.webp" alt="Link-in-bio page with five
targets" width="260"></a> <p align="left"><strong>Link in bio.</strong> One
page with several targets under one short code. Counted like everything
else: per day, for the page and per target, without visitor records.</p>
</td> </tr> </table>

## Installation

### Requirements

| | |
| --- | --- |
| **PHP** | 8.1 or newer |
| **Required extensions** | `json`, `mbstring`, `pdo_sqlite` |
| **Optional** | `gd` (PNG and PDF export), `fileinfo` (logo upload), `openssl` (SMTP), `ldap` (LDAP sign-in only) |
| **Web server** | Apache, nginx, Caddy – anything that can rewrite paths |
| **Write access** | for exactly one directory: `data/` |

No database server, no Composer, no build step. To see what you have:

```bash
php -v && php -m | grep -E '^(json|mbstring|pdo_sqlite|gd|fileinfo|openssl|ldap)$'
```

### Route 1: Upload the files

The usual case on shared hosting, with no command line at all.

1. [Download the latest release as a
   ZIP](https://github.com/HerrBarmann/flatlink/releases/latest) and unpack
   it.
2. Copy `inc/config.example.php` to `inc/config.php` and set `base_url` in
   it (see [Configuration](#configuration)).
3. Upload everything except `tests/`, `tools/` and `extension/` to the
   webroot – those three are command-line tools and have no business being
   on the web.
4. `data/` is created on first use. If you get an error instead, the web
   server lacks write permission in the target folder.

### Route 2: Container

One image, one volume, no database service:

```bash
docker run -d -p 8080:8080 \
  -e FLATLINK_BASE_URL="http://localhost:8080" \
  -v flatlink-data:/var/lib/flatlink \
  ghcr.io/herrbarmann/flatlink:latest
```

There the configuration comes from `FLATLINK_*` environment variables; a
mounted `inc/config.php` still takes precedence. Details in the [Docker
guide](docs/docker.en.md) – it also carries ready-made Kubernetes manifests.

### Route 3: With Git

If you have a command line on the server, updating later is a `git pull`:

```bash
cd /var/www
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink && cp inc/config.example.php inc/config.php
```

Set permissions – the web server writes to `data/` and nowhere else:

```bash
sudo chown -R root:www-data /var/www/flatlink
sudo find /var/www/flatlink -type d -exec chmod 750 {} \;
sudo find /var/www/flatlink -type f -exec chmod 640 {} \;
sudo mkdir -p /var/www/flatlink/data
sudo chown -R www-data:www-data /var/www/flatlink/data
sudo chmod 700 /var/www/flatlink/data
```

### First start

Open `/admin/` in a browser. The first visit walks you through the setup and
creates the administrator account – the first account automatically gets the
admin role.

After that, glance at *Settings*: the instance checks for itself whether its
data directory is reachable from outside, and whether mail delivery works.

**To try it out**, the built-in server is enough. It does not support
rewrites, hence the bundled router script – it emulates the `.htaccess`
rules so that short links and `/api/…` work:

```bash
php -S localhost:8080 router.php
```

## Configuration

Everything lives in `inc/config.php`. The commented template with **all**
options is [`inc/config.example.php`](inc/config.example.php); anything
absent there keeps its default. What follows are the switches people
actually touch.

### The essentials

```php
'site_name' => 'Short links of Example University',
'base_url'  => 'https://s.example.org',   // no trailing slash
'data_dir'  => '/var/lib/flatlink',       // empty = data/ next to the application
```

> **`base_url` is not a matter of taste.** Left empty, flatlink guesses the
> address from the `Host` header – which is user input. Someone triggering a
> password-reset mail for another account could otherwise point the link in
> it at their own domain and capture the token. flatlink therefore sends
> **no** mails containing links while the address is missing. The session
> cookie's `secure` flag depends on it as well.

> **`data_dir` belongs outside the webroot.** It holds password hashes,
> valid reset tokens and, in mail mode `log`, complete mails in plain text.
> If your host does not allow that, the bundled `.htaccess` protects it –
> but only Apache reads that file.

### Who may do what

| Option | Meaning |
| --- | --- |
| `public_mode` | `on`, `prefix` or `off` – may people shorten without an account? |
| `registration` | `on` or `off` – self-registration |
| `default_perms` | permissions every signed-in account has without a group |
| `limits` | links, statistics depth and logos per account (`0` = unlimited) |
| `custom_code_min_len`, `custom_code_quota` | curbs on squatting short names |

Both public shortening and self-registration can also be switched at runtime
under *Settings*, without touching the file.

### Sign-in

| Option | Meaning |
| --- | --- |
| `ldap` | LDAP or Active Directory; `tools/ldap-check.php` verifies the settings from the command line |
| `sso` | Shibboleth, SAML or OpenID Connect through the web server |
| `totp_required` | enforce a second factor: `off`, `admins` or `all` |

Local accounts always keep working alongside. **Keep at least one local
administrator account** – if the directory fails, you would otherwise be
locked out of your own administration.

### Mail

```php
'mail' => [
    'mode' => 'smtp',                 // 'log' writes to data/mail.log
    'host' => 'mail.example.org',
    'port' => 587,                    // 587 STARTTLS, 465 TLS, 25 in-house relay
    'user' => 'no-reply@example.org', // empty = no authentication
    'pass' => '…',
    'from' => 'no-reply@example.org',
],
```

Without these, mode `log` applies: the confirmation mail lands in
`data/mail.log` and you copy the link from there. Good for trying things
out, not for production.

### Operations and security

| Option | Meaning |
| --- | --- |
| `trusted_proxies` | addresses of upstream proxies – **without them, rate limit and sign-in lock apply to all visitors collectively by accident** |
| `safe_browsing_key` | Google Safe Browsing; empty = off |
| `safety_recheck_days` | re-check existing links every N days (`0` = off) |
| `link_gc_years`, `link_gc_years_unreachable`, `link_gc_note` | remove never-visited links after N years (`0` = off) – also under *Settings → Ground rules* |
| `click_dims` | `false` counts visits only, without origin/device/language |
| `demo_mode` | public playground with a self-reset |
| `language` | `de` or `en` |

> **For real operation** the **[deployment guide](docs/DEPLOYMENT.en.md)**
> takes it step by step: web server blocks for Apache and nginx, mail
> delivery including SPF/DKIM/DMARC, LDAP and Active Directory, the complete
> Shibboleth setup – plus operation, backup and a table of the most common
> pitfalls.
>
> **Your own colours, your own logo?** That is what the **[customization
> guide](docs/CUSTOMIZATION.en.md)** is for – update-safe via
> `assets/custom.css`, without touching the source.

## What is not stored

Where practically every URL shortener logs **who** clicks, flatlink stores
exactly this per link – in full, not abridged:

```json
{ "n": 1840, "last": "2026-08-14", "days": { "2026-08-14": 72 },
  "refs": { "google.com": 210, "-": 1630 },
  "devs": { "mobile": 1402, "desktop": 438 },
  "langs": { "de": 1701, "en": 139 } }
```

Counters, nothing else. No record of individual visits, hence no IP
addresses and no stored device or browser fingerprints. The lower three
lines answer the most common question asked of any statistic – *where do my
clicks come from?* – without following visitors to do it: three coarse
attributes are derived from each request and **added up**. From the referrer
only the hostname survives (never the path, which can carry a search query),
from the browser identifier one of three words, from the language list two
letters. No single visit can be read out of a total, because no single visit
is ever stored.

Even the last visit is recorded only to the day. For a link with a handful
of clicks, a time of day would otherwise be the single value in the whole
data set by which one visit could be placed in time – and joined with other
sources.

This is not a statement of intent; it can be read in
[`inc/store.php`](inc/store.php) in about ten lines (`clicks_bump()`). Go
check – that is exactly why the code is open. The redirect path (`go.php`)
does not even start a session unless the link is password-protected.

If even that is too much for you, switch it off (`'click_dims' => false`)
and the first line is all that remains.

<p align="center"> <img src="docs/screenshots/en/statistics.webp"
alt="Statistics of a link: daily values, monthly overview, CSV export"
width="760"> </p>

## Who this is for

- **Universities, libraries, schools, public administrations** that must not
  hand short links to a service outside Europe. Sign-in via LDAP or
  Shibboleth, groups with their own permissions and limits, namespaces per
  department.
- **Clubs, practices, restaurants, small businesses** that want to print a
  QR code and change its target later without replacing the sticker.
- **Agencies** serving several brands: separate domains per client, shared
  working groups, an API for automation.
- **Anyone who wants to prove a claim rather than merely assert it.** "We
  don't track" is a claim on a website. With the source code next to it, it
  becomes verifiable.

Whether the software survives everyday use can be checked: the public
service [1337.kiwi](https://1337.kiwi) runs on the same technical base – a
side effect of the project, with its own design and the content a public
offering needs. Installing flatlink does **not** give you an imitation of
it: a neutral theme, your own codes, your own domain.

The **Hamburg University of Music and Drama** runs a second instance, and its
requirements shaped a good deal of what is in here: sign-in against the
university directory, groups with their own permissions and limits, namespaces
per department, and the CSV import that took over an existing YOURLS stock.
Features built for one real institution rather than for a checklist – which is
why the directory sync, for one, refuses to act on an incomplete answer.

### And who it is not for

One architectural decision rules some deployments out, and it is better said
here than after the installation: **flatlink runs as a single instance.**
Links and accounts live in one SQLite file, and SQLite takes one writer at a
time. That is what makes the whole thing dependency-free — no database
server, no migrations, a backup is a file copy — and it is why redirects cost
microseconds. But it means:

- **No horizontal scaling.** The Kubernetes manifest says `replicas: 1` and
  strategy `Recreate` for that reason. Two pods would mean two writers.
- **No multi-region, no zero-downtime rolling updates.** During a restart —
  about one and a half seconds — the service is unavailable.
- **No Postgres or MySQL option.** Not "not yet": adding one would mean a
  database server, and that is the dependency the project is built to avoid.

What this is *not* is a capacity problem. One CPU serves **2306 redirects per
second**, and 831 link creations per second across 20 connections; twenty
thousand links change nothing about that. Any university, any agency, any
company runs into their own bandwidth long before they run into this.

So the question is not "is it fast enough" but "does our operating standard
demand more than one replica". If it does, this is the wrong software, and
that should be clear before the first `git clone`.

## Manual

The README is the overview; the depth lives in dedicated documents:

| Document | Content |
| --- | --- |
| [The QR generator](docs/qr-generator.en.md) | Encoder, design options, readability check, print export (PDF, EPS, CMYK), batches, GS1 Digital Link |
| [Short links day to day](docs/kurzlinks.en.md) | Tags, campaign parameters, link in bio, migrating from Bitly or YOURLS |
| [Accounts and sign-in](docs/konten.en.md) | Passkeys and one-time passwords, LDAP, Shibboleth/SAML/OIDC, data access and deletion |
| [Groups, permissions and domains](docs/gruppen.en.md) | Permission and working groups, limits, namespaces, multiple domains per instance |
| [Browser extension](extension/README.md) | "shorten this page" for Chrome and Firefox, pointed at your own instance |
| [API](docs/API.en.md) | the API |
| [openapi.yaml](docs/openapi.yaml) | the same as OpenAPI 3.1, for generated clients |
| [Deployment](docs/DEPLOYMENT.en.md) | production setup, condensed – the [German guide](docs/DEPLOYMENT.md) is the step-by-step reference |
| [Docker and Kubernetes](docs/docker.en.md) | image, environment variables, volume, health endpoint, ready-made manifests |
| [Customisation](docs/CUSTOMIZATION.en.md) | your own look without changing the core |
| [What flatlink will never do](docs/niemals.md) | the features that will never exist here – and why (German) |
| [Accessibility](docs/barrierefreiheit.en.md) | self-assessment against WCAG 2.1 AA, with a statement template for public bodies |
| [Security](docs/SECURITY.en.md) | what is stored, what is not, and how to report vulnerabilities |

## How the data is stored

**Links and accounts live in one SQLite file** (`data/flatlink.sqlite`).
That is not infrastructure: no server, nothing to set up, nothing to
maintain – the `pdo_sqlite` extension ships with practically every PHP. The
full record sits as JSON in a `data` column; the remaining columns are
derived copies for searching. Measured on an instance with one million links
and a hundred thousand accounts: login page 9 ms, a single account 0.01 ms,
a redirect lookup 0.01 ms – all within PHP's usual memory limit.

Since 4.0 all growing state lives in this one file – settings, groups, logo
metadata, open confirmations, the audit log, sessions. Next to it stays only
what is a file for a reason:

| Path | Content |
| --- | --- |
| `flatlink.sqlite` | all of the above – short links, accounts, keys, settings, groups, audit, sessions |
| `clicks/<code>.json` | Click counters – deliberately one mini file per code: the redirect path writes them on every scan, without a shared write lock |
| `logos/` | Uploaded logo files (their metadata lives in the database) |
| `ratelimit/` | Counters per IP hash (HMAC with the instance secret), deleted after 24 h |
| `secret.key` | This instance's secret for the IP hashes – treat like a password |

A backup therefore remains a simple copy of the `data/` folder – or one
click on *Download backup* in the settings.

**Why the click counters are not in the database:** they are written on
*every* scan in the redirect path, and a shared write lock would be the
worst possible idea exactly there. One file per code knows no neighbour –
which is why a single CPU manages thousands of redirects per second.

One honest limit remains: the admin's full list over *millions* of links
still loads the whole stock into memory even with the database – anyone who
really gets there raises `memory_limit`. A per-page query is the next step,
when someone needs it.

## What's not included

So nobody goes looking for it: no statistics by country or device – that
follows from the design. Groups share links and permissions but do not
separate tenants from each other: administrators always see everything.

Also not included are **legal notice, privacy policy and terms of service**.
Whoever runs a public instance is obliged, in Germany and large parts of the
EU, to provide such pages themselves – they depend on operator, country and
use, and cannot sensibly be shipped. Create your own pages and link them in
`page_footer()` in [`inc/helpers.php`](inc/helpers.php).

## Command line

```
php tools/flatlink hilfe
```

Accounts, API keys and links from the shell – for setting up in a container,
for automation, and for the day nobody can sign in any more:

```
php tools/flatlink konto:anlegen alice --admin     # create an admin
php tools/flatlink konto:passwort alice            # new password
php tools/flatlink konto:sperren alice             # lock, keeping the links
php tools/flatlink schluessel:anlegen alice --umfang=read
php tools/flatlink ldap:abgleich                   # dry run
php tools/flatlink zustand                         # quick self-check
```

There is no separate login: whoever can run this can read `inc/config.php`
anyway. `.htaccess` keeps `tools/` away from the web.

## Tests

No test library, no configuration – two PHP files run against the built-in
server:

```bash
php -S localhost:8080 router.php &
php tests/optionen.php http://localhost:8080
```

[`tests/optionen.php`](tests/optionen.php) checks that every design option
actually arrives at `qr.php`. The occasion was a bug another test could not
find: four module shapes were built in the renderer and offered in the
designer, but the allowlist in `qr.php` didn't recognise them – and an
unknown value is silently reset to the default there. Anyone who chose
"diamond" got a square, without a word about it.

The earlier test only asked whether the result **scans**. A code whose shape
was discarded along the way scans just as well – the question was asked
wrongly. Now the same image is produced twice, once through the renderer and
once through the URL, and compared byte by byte.

## Contributing

Bug reports and pull requests are welcome. One request up front: the freedom
from dependencies is not an accident but the core of the project. A patch
that requires Composer, a build step or a database *server* will not be
merged – even if it makes things more elegant. (SQLite passes this test: one
file under `data/`, no infrastructure.)

## License

**[GNU AGPL v3](LICENSE)** with an additional attribution term under section
7(b) of the license. What that means in practice:

**Allowed without asking** – commercially too, for paying customers too: use
it, run it yourself, change it, pass it on, rename it, restyle it, extend it
for your own purposes.

**Two conditions:**

1. **The attribution line stays visible.** Every interface must point to
   "flatlink" and link to <https://1337.kiwi/flatlink>. Translating,
   rephrasing, setting it small and discreet – all allowed. Hiding or
   removing it is not. The reference point is `origin_note()` in
   [`inc/helpers.php`](inc/helpers.php).
2. **Changes stay open.** Whoever offers a *modified* version as a network
   service must make the source of that version available to its users (AGPL
   § 13). Anyone who runs it unmodified doesn't have to publish anything.

Why not MIT: because MIT allows closing the source and building a service
from it where nobody can check anymore what happens to the click data. The
whole point of this project is that you can check.

For a version without the attribution line – say, white-label – there is a
written exemption: <dennis@1337.hamburg>.
