<h1 align="center">flatlink</h1>

<p align="center">
  <strong>The self-hosted URL shortener – with a QR designer and link-in-bio pages.</strong><br>
  Plain PHP. No database server, no Composer, no build step –<br>
  copy the files to a web space and you're done.
</p>

<p align="center">
  <a href="LICENSE"><img alt="AGPL-3.0 license" src="https://img.shields.io/badge/License-AGPL--3.0-1a7f37"></a>
  <img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4">
  <img alt="Zero dependencies" src="https://img.shields.io/badge/Dependencies-0-0a7ea4">
  <img alt="No database server" src="https://img.shields.io/badge/Database%20server-none-555">
</p>

<p align="center">
  <img src="docs/screenshots/linkliste.png" alt="The link list with tags, groups and click counts" width="820">
</p>

> 🇩🇪 Deutsche Fassung: **[README.md](README.md)** – German is the project's
> source language; this translation follows it.

---

## The point

flatlink wants to be the best open-source URL shortener you can run yourself –
with a QR generator that goes all the way to the print shop, and link-in-bio
pages. It is built for the places that need such a thing most urgently:
universities, libraries and public bodies that cannot – or must not – hand
their links to a service outside the house. Sign-in via LDAP and Shibboleth,
groups with permissions and limits, namespaces per department and multiple
domains are therefore not add-ons but the core.

For this purpose, privacy is not a separate feature – it comes along as a way
of building. Where practically every URL shortener logs **who** clicks,
flatlink stores exactly this per link – in full, not abridged:

```json
{ "n": 1840, "last": "2026-08-14", "days": { "2026-08-14": 72 } }
```

One counter per day. No record for individual visits, hence no IP addresses,
no device or browser fingerprints, no referrers. No individual visitor can be
reconstructed from this data, because no individual visitor is ever stored.

Even the last visit is only recorded to the day. For a link with a handful of
clicks, a time of day would otherwise be the single value in the whole data
set by which one visit could be placed in time – and joined with other
sources.

This is not a statement of intent; it can be read in
[`inc/store.php`](inc/store.php) in about ten lines (`clicks_bump()`). Go
check – that is exactly why the code is open. The redirect path (`go.php`)
does not even start a session unless the link is password-protected.

<p align="center">
  <img src="docs/screenshots/statistik.png" alt="Statistics of a link: daily values, monthly overview, CSV export" width="760">
</p>

## What it looks like

The screenshots show the German interface; the language is switchable per
instance – see [What's included](#whats-included).

<table>
<tr>
<td width="50%" valign="top">
<a href="docs/screenshots/qr-designer.png"><img src="docs/screenshots/qr-designer.png" alt="QR designer with module and eye shapes, colors and live preview"></a>
<p><strong>QR designer.</strong> Module and eye shapes, free colors, a logo in
the middle, a frame with text. Export as SVG, PNG, vector PDF and EPS,
optionally in CMYK – from an in-house encoder, without any third-party
library.</p>
</td>
<td width="50%" valign="top">
<a href="docs/screenshots/qr-serie.png"><img src="docs/screenshots/qr-serie.png" alt="QR batch: select several links and download them as a ZIP"></a>
<p><strong>QR batches.</strong> Twenty table displays in one archive, with a
CSV overview for the print shop. flatlink writes the ZIP itself – even
without the PHP <code>zip</code> extension.</p>
</td>
</tr>
<tr>
<td width="50%" valign="top">
<a href="docs/screenshots/neuer-link.png"><img src="docs/screenshots/neuer-link.png" alt="Form for a new short link with name, tags and UTM builder"></a>
<p><strong>Creating.</strong> Custom name, a label for your own overview,
tags for filtering, expiry date, password protection and a builder for
campaign parameters.</p>
</td>
<td width="50%" valign="top" align="center">
<a href="docs/screenshots/link-in-bio.png"><img src="docs/screenshots/link-in-bio.png" alt="Link-in-bio page with five targets" width="260"></a>
<p align="left"><strong>Link in bio.</strong> One page with several targets
under one short code. Counted like everything else: per day, for the page and
per target, without visitor records.</p>
</td>
</tr>
</table>

## Running in five minutes

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
php -S localhost:8080 router.php
```

Open `http://localhost:8080/admin/` in the browser – the first visit creates
the admin account. (`router.php` emulates for the built-in server what the
`.htaccess` does in production – without it, a short link leads to the start
page instead of its target.) For production: copy the files to your web space,
make `data/` writable, set `base_url` in the configuration. Details under
[Installation](#installation). For an English interface, set
`'language' => 'en'` in `inc/config.php` or switch it later under *Settings*.

## Who this is for

- **Universities, libraries, schools, public administrations** that must not
  hand short links to a service outside Europe. Sign-in via LDAP or
  Shibboleth, groups with their own permissions and limits, namespaces per
  department.
- **Clubs, practices, restaurants, small businesses** that want to print a QR
  code and change its target later without replacing the sticker.
- **Agencies** serving several brands: separate domains per client, shared
  working groups, an API for automation.
- **Anyone who wants to prove a sentence instead of asserting it.** "We don't
  track" is a claim on a website. With the source code next to it, it becomes
  verifiable.

## Where it runs in production

Whether the software survives everyday use can be checked: the public service
[1337.kiwi](https://1337.kiwi) runs on the same technical base – a side effect
of the project, with its own design and the content a public offering needs.
What proves itself in operation there ends up in this source; what is added
here for organizations (central sign-in, groups, permissions) the public
service doesn't need.

Installing flatlink does **not give you an imitation of it**: a neutral theme,
your own short codes, your own domain. What remains is a discreet attribution
line in the footer – the [license](#license) requires it, and that is also all
it requires.

## What's included

- **Short links** with random or self-chosen codes, an optional label, tags
  for filtering, an optional expiry date and optional password protection
- **QR codes** from an in-house encoder (ISO/IEC 18004, byte mode,
  **versions 1–40**, error correction L/M/Q/H) – without any third-party
  library. Up to 2953 characters, so long addresses with campaign parameters
  fit too
- **QR designer** at `qr-designer.php`: module and eye shapes, free colors,
  **gradients**, **print colors in CMYK**, export as SVG, PNG, **vector PDF
  and EPS**. Signed-in users additionally get their own logo, a frame with
  text and the selection of their links on the same page – a short link can
  be created right there as well
- **Link-in-bio pages**: one page with several targets under one short code,
  counted like everything else – per day, for the page and per target,
  without visitor records
- **Static QR codes** for an **unshortened address or free text**, Wi-Fi
  access, contacts (vCard), events (iCalendar) and **GS1 Digital Link** – the
  input is stored nowhere; it is encoded straight into the graphic, so these
  codes work entirely independently of the service
- **An English interface**: German is the source language, the language is set
  per instance (`'language'` in the configuration or under *Settings*, at
  runtime). A further language is one file under `inc/lang/`; whatever a
  translation lacks stays visibly German instead of blank
- **Accounts** with self-registration via double opt-in, password reset and
  roles (user/admin), including usage limits per account
- **QR codes individually or as a batch in a ZIP**, with a CSV overview
- **Two-factor sign-in**: passkeys (WebAuthn) or one-time passwords from an
  app, with recovery codes, optionally enforceable for the whole instance
- **Data access and deletion in the profile**: data export as JSON and a
  button that really removes the account and its links – GDPR Art. 15, 17
  and 20 without a ticket system
- **Session management in the profile**: a list of active sign-ins, revoke
  one or all others; a password change signs the rest out automatically
- **An audit log of administrative actions**: who blocked, approved or
  changed what and when – administration only, never visitors; JSON lines,
  ready for a central log
- **CSV export of the link list** in the format of the built-in import –
  whoever wants to leave takes everything along; lock-in fear is not a
  business model
- **Central sign-in** via LDAP/Active Directory or via the web server
  (Shibboleth, SAML, OpenID Connect) – see
  [Accounts and sign-in](docs/konten.en.md)
- **Groups** in two modes: as a permission group (permissions and limits,
  links stay private) or as a working group whose links the whole team
  manages together
- **CSV import** for many links at once – the exports of Bitly and YOURLS can
  be uploaded unchanged
- **API** with access keys per account, see [API.md](API.md)
- **Abuse protection**: rate limits per IP (only a keyed hash is stored, no
  plain addresses), a report form, a blocking function, optional Google Safe
  Browsing – optionally with a **re-check across the stock**, against targets
  that turn malicious only after creation
- **Backup as an archive**: one button that outputs the database (copied
  consistently), settings, counters and logos as a ZIP with instructions –
  for everyone who cannot reach the data directory
- **Automatic cleanup** of never-visited links, with advance warning by mail
  (disabled by default)
- **Storage without operations**: links and accounts in one SQLite file,
  everything else in small JSON files – no database server, backup = copy
  the folder, see [How the data is stored](#how-the-data-is-stored)

## Requirements

- PHP 8.1 or newer
- Extensions: `json`, `mbstring`, `pdo_sqlite` (storage), `gd` (for
  PNG/PDF), `fileinfo` (logo upload), `openssl` (only for SMTP), `ldap`
  (only for LDAP sign-in)
- A web server with `mod_rewrite` or an equivalent rewrite facility. The
  bundled `.htaccess` additionally provides a fallback via
  `ErrorDocument 404` in case rewrites don't take effect at your host.

No database server, no Composer, no build step.

## Installation

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink
cp inc/config.example.php inc/config.php
```

Then adjust `inc/config.php` (at least `site_name`), put the files into the
webroot and make sure the web server may write into the directory – `data/`
is created on first use.

For a quick try, the built-in server is enough. It knows no rewrites, hence
the bundled router script – it emulates the `.htaccess` rules so short links
and `/api/…` work too:

```bash
php -S localhost:8080 router.php
```

**First account:** register via `register.php`. By default, mail delivery is
set to `log`, so the confirmation mail ends up in `data/mail.log` – copy the
link from there and open it. The first account created automatically gets the
admin role.

> **For real operation** there is a detailed
> **[deployment guide](DEPLOYMENT.md)** (German): permissions and web server
> configuration for Apache and nginx, mail delivery including SPF/DKIM/DMARC,
> LDAP and Active Directory, the complete Shibboleth setup including Apache
> and attribute release – plus operation, backup and a table of the most
> common pitfalls.
>
> **Your own colors, your own logo?** See the
> **[customization guide](CUSTOMIZATION.md)** (German) – update-safe via
> `assets/custom.css`, without touching the source.

## Configuration

Everything lives in `inc/config.php`; the commented template is
[`inc/config.example.php`](inc/config.example.php). The most important
switches:

| Option | Meaning |
| --- | --- |
| `site_name` | Display name in title, header and mails |
| `base_url` | Fixed base URL; empty = automatic detection |
| `sqlite_file` | Path of the storage file; empty = `data/flatlink.sqlite` |
| `language` | Interface language of the instance (`de` is the source language, `en` ships along) |
| `limits` | Links, statistics depth and logos per account (`0` = unlimited) |
| `default_perms` | Permissions every signed-in account has without a group |
| `sso` | Central sign-in via the web server (Shibboleth/SAML/OIDC) |
| `ldap` | Sign-in against LDAP or Active Directory |
| `qr_brand_text` | Optional attribution line under generated QR codes |
| `custom_code_min_len` / `custom_code_quota` | Brakes against namespace squatting on public instances |
| `mail` | `log` writes to `data/mail.log`, `smtp` really sends |
| `safe_browsing_key` | Empty = off. See the note below |
| `safety_recheck_days` | Re-check the stock every N days (`0` = off) |
| `link_gc_years` | `0` = no automatic cleanup |
| `data_dir` | Keep runtime data outside the webroot – recommended |
| `trusted_proxies` | Addresses of upstream proxies; needed for correct rate limits |

At runtime, the admin area additionally lets you switch off public link
creation and self-registration – handy when the instance is internal only.

## Manual

The README is the overview; the depth lives in dedicated documents:

| Document | Content |
| --- | --- |
| [The QR generator](docs/qr-generator.en.md) | Encoder, design options, readability check, print export (PDF, EPS, CMYK), batches, GS1 Digital Link |
| [Short links day to day](docs/kurzlinks.en.md) | Tags, campaign parameters, link in bio, migrating from Bitly or YOURLS |
| [Accounts and sign-in](docs/konten.en.md) | Passkeys and one-time passwords, LDAP, Shibboleth/SAML/OIDC, data access and deletion |
| [Groups, permissions and domains](docs/gruppen.en.md) | Permission and working groups, limits, namespaces, multiple domains per instance |
| [API.md](API.md) | the API (German) |
| [DEPLOYMENT.md](DEPLOYMENT.md) | installation for production, from file permissions to Shibboleth (German) |
| [CUSTOMIZATION.md](CUSTOMIZATION.md) | your own look without changing the core (German) |
| [SECURITY.md](SECURITY.md) | what is stored, what is not, and how to report vulnerabilities (German) |

## How the data is stored

**Links and accounts live in one SQLite file** (`data/flatlink.sqlite`).
That is not infrastructure: no server, nothing to set up, nothing to
maintain – the `pdo_sqlite` extension ships with practically every PHP. The
full record sits as JSON in a `data` column; the remaining columns are
derived copies for searching. Measured on an instance with one million links
and a hundred thousand accounts: login page 9 ms, a single account 0.01 ms,
a redirect lookup 0.01 ms – all within PHP's usual memory limit.

Everything else is small JSON files under `data/`, with `flock` against
concurrent writes and atomic writing via temp file plus `rename`:

| File | Content |
| --- | --- |
| `flatlink.sqlite` | Short links and accounts, see above |
| `clicks/<code>.json` | Click counters – deliberately one mini file per code: the redirect path writes them on every scan, without a shared write lock |
| `groups.json` | Groups: display name and permissions |
| `settings.json` | Settings changeable at runtime |
| `logos/` | Uploaded logos for QR codes |
| `ratelimit/` | Counters per IP hash (HMAC with the instance secret), deleted after 24 h |
| `secret.key` | This instance's secret for the IP hashes – treat like a password |
| `pending/` | Open confirmation tokens (registration, reset) |

A backup therefore remains a simple copy of the `data/` folder.

One honest limit remains: the admin's full list over *millions* of links still
loads the whole stock into memory even with the database – whoever really
gets there raises `memory_limit`. A per-page query is the next step, when
someone needs it.

## What's not included

So nobody goes looking for it: no statistics by country or device – that lies
in the nature of the thing. Groups share links and permissions but do not
separate tenants from each other: administrators always see everything.

Also not included are **legal notice, privacy policy and terms of service**.
Whoever runs a public instance is obliged, in Germany and large parts of the
EU, to provide such pages themselves – they depend on operator, country and
use, and cannot sensibly be shipped. Create your own pages and link them in
`page_footer()` in [`inc/helpers.php`](inc/helpers.php).

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
designer, but the whitelist in `qr.php` didn't know them – and an unknown
value is silently reset to the default there. Whoever chose "diamond" got a
square, without a word about it.

The earlier test only asked whether the result **scans**. A code whose shape
was discarded along the way scans just as well – the question was asked
wrongly. Now the same image is produced twice, once through the renderer and
once through the URL, and compared byte by byte.

## Contributing

Bug reports and pull requests are welcome. One request up front: the freedom
from dependencies is not an accident but the core of the project. A patch
that requires Composer, a build step or a database *server* will not be
merged – even if it makes things more elegant. (SQLite passes this test:
one file under `data/`, no infrastructure.)

## License

**[GNU AGPL v3](LICENSE)** with an additional attribution term under
section 7(b) of the license. What that means in practice:

**Allowed without asking** – commercially too, for paying customers too:
use it, run it yourself, change it, pass it on, rename it, restyle it, extend
it for your own purposes.

**Two conditions:**

1. **The attribution line stays visible.** Every interface must point to
   "flatlink" and link to <https://1337.kiwi/flatlink>. Translating,
   rephrasing, setting it small and discreet – all allowed. Hiding or
   removing it is not. The reference point is `origin_note()` in
   [`inc/helpers.php`](inc/helpers.php).
2. **Changes stay open.** Whoever offers a *modified* version as a network
   service must make the source of that version available to its users
   (AGPL § 13). Whoever runs it unmodified doesn't have to publish anything.

Why not MIT: because MIT allows closing the source and building a service
from it where nobody can check anymore what happens to the click data. The
whole point of this project is that you can check.

For a version without the attribution line – say, white-label – there is a
written exemption: <dennis@1337.hamburg>.
