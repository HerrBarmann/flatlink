# Reporting vulnerabilities

🇩🇪 [Deutsche Fassung](SECURITY.md)

Thank you for taking the trouble. Reports are expressly welcome — unfinished
ones too, and ones you are not sure about.

## How

**Please no public issue** for findings that can be exploited while they are
unfixed. Instead:

- **GitHub Security Advisory** — the preferred route:
  [Report a vulnerability](https://github.com/HerrBarmann/flatlink/security/advisories/new)
- **Mail** to the address named in the
  [imprint of 1337.kiwi](https://1337.kiwi/impressum.php), ideally with
  `[flatlink]` in the subject

Helpful: affected file and line, how to reproduce the finding, and your
assessment of the impact. A proof of concept is nice, not a condition.

## What you can expect

This is a one-person project, not a company with an on-call rotation.
Realistically that means:

- Acknowledgement within **three days**
- An assessment of whether and how fast it will be fixed within **two weeks**
- For critical findings I try to be considerably faster

There is no bug bounty — there is no budget for one. If you wish, you will
be credited by name in the fix.

## What is in scope

The code in this repository. Of particular interest:

- Account takeover, privilege escalation, bypassing access control
- Bypassing the namespace or group separation
- Injection of any kind, XSS, CSRF
- Anything contradicting the privacy promise: if flatlink stores or reveals
  more about visitors than README and code claim, that is a security bug,
  not a blemish

**Not in scope:** the running instance 1337.kiwi as a target of active
testing. Please test against your own installation — it is set up in three
lines. Also out: misconfigurations of somebody else's instance, and findings
from automated scanners without a demonstrable impact.

## Known limits

Some things are not holes but deliberate decisions. For completeness:

- **`data/` sits in the webroot by default.** With `'data_dir'` it can be
  moved to any absolute path outside — strongly recommended. If it stays in
  the webroot, it is protected by the `.htaccess` on Apache and by the
  blocks from the [deployment guide](DEPLOYMENT.en.md) on nginx. Whether
  that protection actually holds is something the instance checks itself
  since 2.5.1: it places a canary file into the data directory, fetches it
  over its own `base_url` and deletes it again. The result is shown under
  *Settings* — "open" is a standing red warning, and "unclear" (the instance
  cannot reach itself) is explicitly not an all-clear.
- **Google Safe Browsing fails open:** if the service is unreachable, the
  link is created rather than rejected. Availability beats completeness of
  the check here. So this state does not stay silent, failures are counted
  and shown under *Reports* once the check keeps running into nothing.
- **Sign-in attempts are counted, not delayed.** Up to 2.5.0 a `sleep()`
  delayed the response after failed attempts. That slows attackers, but
  occupies a PHP process for its duration — on shared hosting with a handful
  of processes, exactly that is the more effective attack. Since 2.5.1 the
  instance answers immediately with 429 and `Retry-After` instead.
- **Targets in private address ranges are blocked** (10.x, 172.16–31.x,
  192.168.x, 127.x, `localhost`, `fc00::/7`, `fe80::/10`), as are addresses
  with a userinfo part (`https://bank.example@evil.tld/`). The server never
  fetches targets, so this is not about SSRF but about the short link as
  packaging for internal addresses. Names are not resolved in the process —
  that would be one network request per form submission and thus a lever
  itself. Purely internal instances set `'allow_private_targets' => true`.
- **IP hashes are pseudonymous, not anonymous.** They are built with an
  instance-own secret (`data/secret.key`) and thus cannot be reversed
  without server access — but they remain personal data in the sense of the
  GDPR and belong in the privacy statement.
- **Links and accounts live in one SQLite file** (`data/flatlink.sqlite`,
  WAL mode). Lookup, limit checks and lists are targeted queries; the file,
  like the whole `data/` folder, belongs outside the webroot or behind the
  bundled access block.
- **Click timestamps are deliberately day-precise only.** The counter
  records how often a link was visited in total and per calendar day, plus
  the day of the last visit — no time of day, no IP, no record per visit.
- **Origin, device class and language are counted as sums.** Three coarse
  attributes are derived from the request and added up per link: the
  hostname of the referring page (never the path — it can contain a search
  query), "phone/tablet/desktop" from the user agent, and two letters from
  the language list. Only the sum per value is stored; referrer and user
  agent themselves are not kept, and there is still no record per visit. At
  most 40 distinct values per attribute, the rest collects under "others" —
  also so nobody can bloat the counter file with invented origins. A
  second-precise timestamp would, for a rarely visited link, be the one
  value in the data from which a single visit could be placed in time.
  Whoever needs finer statistics builds them via `inc/local.php` — and
  amends their privacy statement.

- **Two-factor sign-in** comes in two forms, set up in the profile and
  optionally enforceable: passkeys (WebAuthn) and one-time passwords from an
  app (TOTP). Passkeys are bound to the domain and therefore effective
  against look-alike sign-in pages, which a typable code does not protect
  from. Both protect the password sign-in — **not** the API: an access key
  is its own credential and stands on its own.

- **Resetting the second factor** can be done by an administrator under
  *Users*. There are no recovery codes for passkeys, so this route is
  needed — and it is at the same time the weakest point of the chain.
  Whoever uses it must know who they are talking to.

## Retention

What disappears on its own, without a cron job — triggered by link creation,
at most once a week:

| Data | Period |
| --- | --- |
| Daily values of the click counter | 400 days |
| IP hash of the double opt-in (`verified_ip`) | 12 months |
| Rate-limit and sign-in-lock entries | 24 hours |
| Pending registrations, mail changes | 24 hours |
| Password reset procedures | 1 hour |
| Long-unused short links | `link_gc_years`, off by default (advance warning by mail) |

On top of that comes what the person concerned triggers themselves: the
profile has a data export (Art. 15/20) and a delete button (Art. 17) that
removes account, links and click counters. Links assigned to a group remain
and merely lose their owner. Can be switched off via
`'self_delete' => false` where accounts are managed centrally.

## Reports so far

| Date | Finding | Status |
| --- | --- | --- |
| 2026-08-13 | Host-header poisoning in the password reset (account takeover) | fixed |
| 2026-08-13 | Session cookie without `secure` behind a TLS-terminating proxy | fixed |
| 2026-08-13 | IP hashes reversible without a key | fixed |
| 2026-08-13 | Data loss on a full disk (`json_write`) | fixed |
| 2026-08-13 | CPU load from bcrypt on every failed sign-in | fixed |
| 2026-08-13 | `data/` not movable out of the webroot | fixed (`data_dir`) |
| 2026-08-13 | Rate limit and sign-in lock collapse behind a reverse proxy | fixed (`trusted_proxies`) |
| 2026-08-13 | No security headers, no CSP | fixed |
| 2026-08-13 | Distributed password spraying bypasses the lock | fixed (instance counter) |
| 2026-08-13 | `qr.php` reads from `$_REQUEST` | fixed |
| 2026-08-13 | Overly wide file permissions on shared hosting | fixed (0700/0600) |
| 2026-08-13 | Full parse of `links.json` on every redirect | fixed (256 buckets) |
| 2026-08-13 | External sign-in takes over a same-named local account | fixed (approval required) |
| 2026-08-13 | `migrate-links.php` triggerable without POST/CSRF | fixed |
| 2026-08-15 | N7: SVG logos stored unchecked (latent stored XSS) | fixed (allowlist sanitising on upload, `inc/svg.php`) |
| 2026-08-13 | Missing `X-Content-Type-Options` on `qr.php` | fixed |
| 2026-08-13 | Race when creating `data/secret.key` | fixed (atomic) |
| 2026-08-13 | Empty `data/links/` flips a non-migrated instance | fixed (marker) |
| 2026-08-13 | Second-precise timestamp of the last click | fixed (day-precise) |
| 2026-08-13 | `verified_ip` stored indefinitely | fixed (12 months) |
| 2026-08-13 | No data export and no self-deletion in the profile | fixed |
| 2026-08-15 | F1: public rate limit hashed IPs without the instance secret | fixed (`ip_hash`) |
| 2026-08-15 | F2: `sleep()` throttling binds PHP processes (DoS lever) | fixed (counter + 429) |
| 2026-08-15 | F3: rate limit per IPv6 address instead of per prefix; GC on every request | fixed (/64 bucketing, sampled GC) |
| 2026-08-15 | F4: webroot warning checked the configuration, not reality | fixed (self-test via HTTP fetch) |
| 2026-08-15 | F5: `qr.php` without a rate limit (CPU DoS) | fixed (`qr_rate_limit`) |
| 2026-08-15 | F6: `valid_url()` allows userinfo and private targets | fixed |
| 2026-08-15 | F7: `trusted_proxies` without CIDR ranges | fixed (`ip_in_list`) |
| 2026-08-15 | F8: Safe Browsing outage stays invisible | fixed (counter + display) |
