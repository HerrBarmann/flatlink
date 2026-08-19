# API

Create, change and delete short links and read click counts — from a script,
a point-of-sale system, an editorial tool. 🇩🇪 [Deutsche Fassung](API.md).

The API can do **nothing the account could not do through the interface**.
Permissions, limits, namespaces and group membership apply unchanged: a key
is a second way to sign in, not a second authorisation. All rules come from
the same code the admin interface uses
([`inc/linkrules.php`](../inc/linkrules.php)).

---

**Machine-readable:** the same API as OpenAPI 3.1 in
[`openapi.yaml`](openapi.yaml) – for generated clients, Postman, Insomnia or an
entry in a tool catalogue. What you read here are the reasons; the shapes are
over there.

## Prerequisites

The account needs the **`api_access`** permission. Like all permissions it
is attached to a group; an administrator enables it. If it is to apply to
everyone on an instance, it belongs in `'default_perms'` in the
configuration.

## Creating a key

*Profile → API → Create.* The key is shown **once** and never again — only
its hash is stored. If it is lost, revoke it and create a new one.

A key starts with `flk_`. That is deliberate: if it accidentally shows up in
a log or a public repository, it is recognisable as such and can be searched
for.

### Scope: allowed to do less than the account

A key can never do **more** than its account may – permissions, limits and
groups apply unchanged. It can do **less**, and that is the point: a key
travels further than a password. It sits in a point-of-sale system, in a
workshop job, in a pairing code someone passes on via the clipboard.

| Scope | Allowed |
| --- | --- |
| **Full access** | everything the account may (default) |
| **Create and edit** | everything except `DELETE` |
| **Read only** | `GET` only |

A call beyond the scope ends in **403** with `scope_exceeded`, before it
touches the hourly allowance.

On top of that there is **own links only**: such a key sees and changes
exclusively what was created with it – not even what the same account creates
through the interface. Everything else is answered like someone else's link,
that is with 404. For a point-of-sale system producing review codes every day
this is the real safeguard: a stolen key cannot reach the rest of the stock.

Keys from before this release carry no scope and keep the full one – they do
not suddenly stop working. Since 3.5.3 the pairing code for the browser
extension creates a key **without the right to delete**; the extension needs
none.

## Authentication

```
Authorization: Bearer flk_…
```

If the server strips the `Authorization` header before PHP sees it — the
case on some shared hosting — this works too:

```
X-Api-Key: flk_…
```

The bundled `.htaccess` additionally passes `Authorization` through as an
environment variable, so the usual way works almost everywhere.

**Session cookies are deliberately not accepted.** Otherwise another site in
a signed-in user's browser could issue requests and change their links.
Without cookies that attack surface does not exist — which is also why the
API needs no CSRF token.

## Addresses

| Form | When |
| --- | --- |
| `/api/links/abc123` | with the rule from the bundled `.htaccess` |
| `/api.php/links/abc123` | if the server provides `PATH_INFO` |
| `/api.php?p=/links/abc123` | fallback if it does not |

Request body: JSON (`Content-Type: application/json`) or form fields — both
are read.

---

> For everyday use there is the [browser extension](../extension/README.md): it
> uses exactly this API to shorten the open page with one click.

## Endpoints

### `GET /me`

Account, permissions, limits, remaining allowance.

```bash
curl -H "Authorization: Bearer $KEY" https://example.org/api/me
```

```json
{
  "account": "anna",
  "role": "user",
  "groups": ["pro"],
  "permissions": ["logo_upload", "custom_code", "api_access"],
  "limits": { "links": "500", "links_used": 42, "stats_days": "365", "logos": "100" },
  "domains": ["example.org", "kunde.link"],
  "assignable_groups": [],
  "rate_limit_per_hour": 300
}
```

### `GET /links`

All links the account has access to.

| Parameter | Meaning |
| --- | --- |
| `q` | searches code, target and name |
| `group` | only links in this group |
| `tag` | only links with this tag |
| `limit` | 1–200, default 50 |
| `offset` | for paging |

```json
{ "total": 42, "limit": 50, "offset": 0, "links": [ … ] }
```

### `POST /links`

```bash
curl -X POST https://example.org/api/links \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.org/some/long/address","title":"Menu"}'
```

| Field | Required | Notes |
| --- | --- | --- |
| `url` | yes | http/https; a missing scheme becomes `https://` |
| `code` | no | custom name; needs the `custom_code` permission |
| `title` | no | name for your own overview |
| `tags` | no | tags for organising — list or comma-separated string; at most eight, 24 characters each, stored lowercase |
| `domain` | no | address the link should live under; empty or unknown = main domain |
| `utm` | no | object with `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`; appended to `url` |
| `expires` | no | `YYYY-MM-DD`, today at the earliest |
| `group` | no | only working groups of the account |
| `password` | no | access protection before the redirect |

Response **201** with the created link, plus a `Location` header.

### `GET /links/{code}`

A single link.

### `PATCH /links/{code}`

Changes **only the fields passed**. A call that merely sets the target
leaves the name untouched — unlike a form, which always submits all its
fields. To remove a name, pass `"title": ""` explicitly.

In addition to the `POST` fields: `disabled` (`true`/`false`) blocks the
link, `"password": ""` removes the access protection.

`utm` behaves the same way within its object: only the parameters passed are
touched. `{"utm": {"utm_campaign": "winter"}}` swaps the campaign and leaves
`utm_source` in place; an empty value removes a single parameter. The
parameters are **not stored separately** — they live in `url`, and `utm` in
the response is derived, not stored.

### `DELETE /links/{code}`

Deletes the link and its click counter. Response `{"deleted": "abc123"}`.

### Switches (`rules`)

```json
{ "rules": [
    { "wenn": "device", "ist": "mobile", "url": "https://apps.apple.com/…" },
    { "wenn": "lang",   "ist": "en",     "url": "https://example.com/en" }
] }
```

`GET` includes the list, `PATCH` sets it (an empty list removes all
switches). Attributes: `device` (`mobile`/`tablet`/`desktop`), `lang` and
`country` (two letters each), `split` (a share from 1 to 99). The first
matching switch wins, otherwise `url` applies. Needs the `link_rules`
permission; at most eight per link. Evaluated on every request — none of it
is stored, only how often each switch fired is counted.

### `GET /links/{code}/stats`

```json
{ "code": "abc123", "total": 42, "last": "2026-08-14", "days": { "2026-08-14": 7 },
  "refs": { "google.com": 12, "-": 30 },
  "devices": { "mobile": 28, "desktop": 14 },
  "languages": { "de": 35, "en": 7 } }
```

`refs`, `devices` and `languages` are sums over all visits — no time series
and no record per visit. They are absent when the instance has `'click_dims'
=> false`. `-` stands for visits without a recognisable origin (typed, QR
code, app).

`days` reaches back only as far as the account may see statistics. **`last`
is day-precise, not second-precise** — there is no finer value because none
is stored. Individual visits do not exist, so they do not exist in the API
either: there is no endpoint returning a single click, its time or its
address, because no such thing is kept anywhere.

---

### Two fields since 3.0

| Field | Meaning |
| --- | --- |
| `lang` | Language of the target URL, two letters. Basis of the switches' language negotiation: only with it can a visitor with a matching second language be routed correctly. |
| `max_visits` | Visit limit. Whole number, 1 or greater; once reached, the link returns 410. Empty or 0 = unlimited. **Every redirect handed out** counts towards it, including those from bots and HEAD requests – unlike the statistics, which leave both out. It governs the short link, it does not protect the destination (see [Short links](kurzlinks.en.md#visit-limit)). |

Both apply to `POST /links` and `PATCH /links/{code}` and appear in every
link response.

### Tags (`/tags`)

Tags live on individual links; here they can be managed across everything
you can access at once – "reachable" being the same set that applies per
link: your own links plus those of your working groups, everything for
administrators.

| Call | Effect |
| --- | --- |
| `GET /tags` | every tag in use, with its link count |
| `PATCH /tags/{tag}` | rename, field `name`; if a link carries both, they merge |
| `DELETE /tags/{tag}` | remove from all links |

```bash
curl -X PATCH https://example.org/api/tags/campain \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"name":"campaign"}'
```

```json
{ "tag": "campaign", "renamed_from": "campain", "links": 12 }
```

The new name is treated the same way as on a link: lowercased, at most 24
characters. A tag none of your links carries returns 404. That turns the
typo in thirty links into one call instead of thirty `PATCH`es.

### `GET /health`

The one endpoint **without a key** – meant for monitoring: a watchdog should
not have to store an access key. If the instance answers and its storage is
readable, you get 200:

```json
{ "status": "pass" }
```

Otherwise 503 with `"fail"`. Deliberately nothing more – an anonymously
reachable endpoint reveals neither version nor numbers.

## Fetching a QR code

A short link's QR code is not a separate API resource but the same public
route the interface uses – the code belongs to the link, not to an account,
and therefore needs no key:

```bash
curl -o code.svg "https://example.org/qr.php?c=abc123&format=svg"
```

`format` can be `svg`, `png`, `pdf` or `eps`; plus the designer's styling
parameters (`style`, `eye`, `fg`, `bg`, `size`, `margin` …) – the complete
list is right below. Unknown codes return 404, the rate limit is
`qr_rate_limit` (default 600 per hour per address, tighter for the heavy
print formats).

### Designing the QR code

Everything the designer can do is available as a parameter to `qr.php` – it
is the same generator, not a second implementation of one.

| Parameter | Values | Default |
| --- | --- | --- |
| `format` | `svg`, `png`, `pdf`, `eps` | `svg` |
| `size` | edge length in pixels (PDF rasterises at 2048 minimum) | 512 |
| `margin` | quiet zone in modules, 0–10 | 4 |
| `ecc` | error correction `L`, `M`, `Q`, `H` | `M` |
| `style` | `square`, `rounded`, `smooth`, `dot`, `diamond`, `bars-v`, `bars-h` | `square` |
| `eye` | `square`, `rounded`, `circle`, `leaf` | `square` |
| `eyecore` | same values; empty = like the ring | empty |
| `fg`, `bg` | `#rrggbb`; `bg=none` makes the background transparent | `#16181D`, `#ffffff` |
| `eyefg`, `eyecorefg` | eye colours; empty = like the modules | empty |
| `grad`, `fg2`, `ga` | gradient `linear`/`radial`, second colour, angle | off |
| `logo`, `ls`, `lpad` | logo from the library, size in percent, padding | – |
| `ftext` | frame text, at most 24 characters | empty |
| `mm` | target width in millimetres for PDF and EPS | – |
| `download` | set = as a file instead of in the browser | off |

Two things deliberately **cannot** be set by parameter:

- The **attribution line** under the code (`qr_brand_text`) depends on the
  `qr_unbranded` permission of the link's **owner**, not of the caller.
  Otherwise anyone could strip it off with a parameter.
- The **logo library**: `logo` only accepts ids the instance knows – no
  foreign addresses.

The rate limit is `qr_rate_limit` (default 600 per hour per address), plus
`qr_rate_limit_print` (60) for the heavy print formats. Signed-in accounts
are exempt from both.

## Errors

```json
{ "error": { "code": "rejected", "message": "Invalid target URL (http/https only)." } }
```

| Status | `code` | Meaning |
| --- | --- | --- |
| 401 | `no_key`, `bad_key`, `no_account` | key missing, unknown or revoked |
| 403 | `no_permission` | account lacks `api_access` |
| 403 | `scope_exceeded` | key may not use this method (see scope) |
| 404 | `not_found` | link or endpoint does not exist |
| 405 | `method_not_allowed` | method not available here |
| 409 | `not_created` | code already taken |
| 422 | `rejected` | input violates a rule |
| 429 | `rate_limited`, `too_many_attempts` | hourly limit reached |

**404 instead of 403 for other people's links** is deliberate: otherwise the
API could be used to find out which short codes are already taken.

## Limits

`'api_rate_limit'` in the configuration, default 300 requests per hour per
key. Counted per key, not per IP — a server using the API always comes from
the same address. Failed authentications are counted separately per IP – at
most 60 per hour, so keys cannot be brute-forced. Successful calls do not
touch that budget; they only count against the hourly limit above.

At most ten keys per account.

## Example: creating a batch

```bash
#!/bin/sh
KEY="flk_…"
BASE="https://example.org/api"

while IFS=';' read -r url name; do
  curl -s -X POST "$BASE/links" \
    -H "Authorization: Bearer $KEY" \
    -H "Content-Type: application/json" \
    -d "$(printf '{"url":"%s","title":"%s"}' "$url" "$name")" \
  | sed -n 's/.*"short_url": "\([^"]*\)".*/\1/p'
done < list.csv
```

For a one-off move from another service, the CSV import in the interface is
the shorter path — it understands the exports of Bitly, YOURLS, Shlink and
Kutt directly.
