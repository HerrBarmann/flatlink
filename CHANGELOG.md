# Changelog

> 🇩🇪 Deutsche Fassung: **[CHANGELOG.de.md](CHANGELOG.de.md)**

flatlink went from its first release to 5.4 in eleven days, across 63 releases.
This file condenses them into the five series and the handful of releases that
change something for an existing installation. The full notes for every single
version — German prose with an English summary — are on
[the releases page](https://github.com/HerrBarmann/flatlink/releases).

## Versioning

From 5.4.2 onwards, the **first digit changes only on a real break**: a
migration you have to know about, an interface that is gone, a default that
changed. Some earlier major bumps were milestones rather than breaks — 3.0 was
explicitly "swap the files, no migration step" — and that is not how they will
be spent from here on.

Not every fix gets its own release either. They are collected.

## Upgrade notes

Everything not listed here updates by copying the files over.

| Version | What to do |
| --- | --- |
| **5.0.0** | If a domain still points at the server but you remove it from the configuration, its links stop resolving. Nothing is deleted — adding the domain back brings them all back. |
| **4.0.0** | Everyone signs in once more. Files are imported automatically and renamed to `.uebernommen`, never deleted. The audit log moved to `tools/flatlink audit`. |
| **3.3.0** | Containers only: the image listens on **8080** instead of 80. Port mapping becomes `8080:8080`. |
| **3.1.0** | If your config sets `'api_doc_url' => 'API.md'`, change it to `'docs/API.md'`. |

---

## 5.x — One namespace per domain (2026-08-21 → 2026-08-24)

Until 4.5 a short code belonged to the *instance* and resolved under every
configured address, so a client bringing their own domain could reach every
other client's links through it. A link is now identified by `(domain, code)`;
the main domain carries the empty string, so single-domain servers notice
nothing and every existing record stays valid.

The other half of the series removed the last reason a clicked link cost disk
inodes — the real ceiling on shared hosting — and then made sure no visitor
ever waits for the database while that happens.

* **5.0.0** — Namespaces per domain. Migration is automatic and transactional.
* **5.0.2** — Security: three `hook_fire()` calls looked their record up
  *without* the domain, so a webhook payload could carry a different link's
  target and owner. `link_get()` now marks what namespace it found a record in.
* **5.1.0 / 5.2.0** — The click counter moved into the database. A clicked link
  went from three files to **none**. Measured at 33,193 clicks/s against 34,169
  for the old file path — 2.9 % slower.
* **5.2.1 – 5.2.3** — Counting happens after the response is closed. With a
  write lock deliberately held, a redirect went from 3.92 s to 3.3 ms, worker
  occupancy from 5.04 s to 0.23 s, and the link-in-bio page from 5.04 s to
  1.0 ms. In that band clicks are dropped rather than waited out — stated
  plainly rather than glossed over.
* **5.2.4** — The four public QR generators were hardcoded German while the
  page declared `lang="en"`. All strings go through `t()`; a guard test renders
  every public page in English and fails on leftover German.
* **5.3.0** — `qr_public` (auto | on | off) offers the static QR tools publicly
  even when public shortening is off. Individual logos can be marked public.
* **5.4.0 / 5.4.1** — The browser extension is in the Chrome Web Store and on
  AMO; both addresses ship as defaults.

## 4.x — One storage, and scale (2026-08-20 → 2026-08-21)

Everything that grows or must be shared moved into the SQLite file behind the
single seam in `inc/db.php`. The series then spent itself on two things: making
a redirect cheap, and making sure nothing in the codebase grew with the stock.
Load-tested at 5 and 50 million links under a 128 MB memory limit.

The privacy promise was audited against the code twice, and both times the code
was pulled up to the promise rather than the other way around.

* **4.0.0** — Settings, groups, logo metadata, the SSO queue, confirmations, the
  audit log and PHP sessions each become a proper table.
* **4.0.1** — Profiling showed ~90 % of a redirect was SQLite connection setup.
  Persistent connections cut it to 0.005 ms; in-PHP time per redirect went from
  2.5–3.3 ms to 0.13–0.24 ms.
* **4.2.0** — `links_each()`, a generator that walks the stock at constant
  memory. At 500,000 links the admin list went from a fatal error to 45 ms.
* **4.3.0** — Security: since 4.1 the click log had kept origin, device and
  language of a *single* visit linked together in one line, which contradicted
  the README's central promise. The tuple was dissolved.
* **4.4.0** — The follow-up review noted that dissolving the tuple had only
  moved the linkage into line *adjacency*. Attributes are now counted directly
  as sums into `clickdims`; the log holds nothing but bare date lines. The
  privacy sentence is now literally true.
* **4.4.2** — A line-by-line audit of the privacy policy against the code.
  Timestamps truncated to the stated precision; `audit_prune()` gives the audit
  log the cap the policy promises.
* **4.5.0** — Four places that grew with the stock made independent of it. Deep
  pagination went from 92.5 s to 0.74 ms, the stock counter from 32 s to
  0.003 ms.
* **4.5.1** — The honest ceiling written down: on shared hosting it is the
  **inode quota**, not the database. Two older published figures were
  re-measured because they did not hold.

## 3.x — Feature parity, then operations (2026-08-18 → 2026-08-20)

3.0 closed the last gaps against Shlink and YOURLS-with-plugins. What remains
exclusive to commercial competitors is what this project refuses to build:
visitor profiles. The rest of the series made flatlink deployable by someone
other than its author — a container image, Kubernetes manifests, a command
line, directory sign-in, and a security review after most steps.

* **3.0.0** — Counters count *people* (bots, HEAD requests and the signed-in
  owner excluded, still without storing anything), visit caps, Shlink and Kutt
  import, demo mode, imprint/privacy links on bio pages. Documentation complete
  in English, plus an accessibility statement.
* **3.1.0** — Instance-wide tag management over the API, unauthenticated
  `GET /health`.
* **3.2.0 / 3.3.0** — Container image for amd64 and arm64 at
  `ghcr.io/herrbarmann/flatlink`, then rootless so it runs under the
  `restricted` pod security standard and on OpenShift.
* **3.2.1** — Security: `docker-compose.yml` was served over HTTP — exactly
  where an operator puts SMTP and LDAP credentials. Denied now, along with
  `.git`.
* **3.3.2** — The API's brute-force brake counted *every* request and never
  reset on success, capping the interface at 60 calls per hour regardless of
  `api_rate_limit`.
* **3.5.0** — The browser extension speaks English and German.
* **3.5.2** — Security: the visit limit checked against the bot-filtered
  counter, so `User-Agent: curl/8.0` bypassed it. A second unfiltered counter
  now backs the limit.
* **3.5.3** — API keys can be created with a reduced scope, and bound to their
  own links.
* **3.6.0** — Accounts can be locked across every route including LDAP, SSO and
  the API. Directory sync, `tools/flatlink`, and `docs/openapi.yaml`.
* **3.6.1** — Security: the directory sync searched in one go and never checked
  the result code, but Active Directory caps answers at 1000 entries and
  returns a *subset* rather than an error. With 1200 accounts that meant 200
  real people locked out. It pages now and aborts on a truncated answer.
* **3.7.0 / 3.8.0** — Sign-in in two steps, which is what a passkey needed: it
  replaces the password rather than following it. Accounts without one are
  offered it monthly, controlled by `passkey_hint`.
* **3.9.0** — Automatic cleanup of never-visited links became configurable,
  including the reference the warning mail cites — it had quoted the terms of
  one particular instance on every installation.

## 2.x — From shortener to tool (2026-08-14 → 2026-08-18)

The QR generator grew from an add-on into the reason to use flatlink, the
interface learned English, and the storage moved from JSON files to SQLite.
By the end of the series a second institution could run it: groups with their
own permissions, directory sign-in, and a logo library that belongs to
somebody.

* **2.1.0** — Encoder up to version 40, vector export with CMYK for print,
  seven module shapes, four eye shapes, readability check.
* **2.2.0** — 833 strings translated; English README and manuals.
* **2.4.0** — Links and accounts move into SQLite. The JSON storage is gone.
* **2.5.0** — Signed-in devices, an audit log for administrative actions,
  scheduled activation, CSV export, archive backup.
* **2.6.0** — Where clicks came from (host only, sums only, no time series per
  attribute), routes with several targets, sharing previews, webhooks,
  accessibility fixes — and a written list of what flatlink will never do.
* **2.8.0** — All QR types in the core, logos released to groups, permissions
  split into what an account may do itself and what it may do for others,
  central sign-in made usable, SMTP against in-house relays.
* **2.9.0** — Link ownership, accounts from the directory, backups that suit
  rsync, borg and Git.
* **2.9.5** — Security release after an external review. All eight findings
  from the 2.5.0 review confirmed fixed; test scripts refuse to run outside the
  CLI, `.htaccess` blocks `tests/`, `tools/` and `extension/`, webhook targets
  are checked against internal addresses.

## 1.0.0 — 2026-08-13

Initial release.
