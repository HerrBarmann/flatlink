# Short links day to day

Order and tools around the links: tags, campaign parameters, link-in-bio
pages, counting rules and moving in from another service. Back to the
[README](../README.md). – 🇩🇪 [Deutsche Fassung](kurzlinks.md).

## Tags

From a few hundred links onwards, search alone stops being enough. Every link
takes up to eight tags, entered comma-separated. Above the list sits a cloud
of all tags in use with their counts; one click filters, a second on "show
all" lifts the filter again. Filter and search combine.

Tags are **stored lowercase**: "Campaign" and "campaign" should be the same
drawer, otherwise you own both after a week. They are order, not permission –
whoever wants to control access uses [groups](gruppen.en.md).

Also available via the [API](API.md) (field `tags`, filter `?tag=`) and in
the CSV import (column `tags`).

## Start date and expiry

A link can carry a **start date**: before it, the link already exists – the
code is taken, the QR code printable, the address fixed – but it does not
forward yet. Whoever scans too early gets a page with the date instead of a
redirect (410, the same answer as for an expired link: the code exists but
does not lead anywhere today).

This is the case posters are printed for before the campaign runs: semester
start, press date, product launch. Together with the expiry date it stakes
out a time window; a start date after the expiry is rejected.

The link is valid **from** the named day (as the expiry is valid **up to and
including** its day). An empty field means "immediately". Also available via
the [API](API.md) (field `starts`, plus `pending` in the response) and in
the CSV export.

## What counts – and what doesn't

The counters are meant to count visits, not traffic. Three kinds of requests
therefore stay out, all without storing a crumb – the user agent is checked
and forgotten:

- **Known bots.** Messenger preview services, search engines, monitoring,
  plus tools like `curl`. Every message thrown into a chat would otherwise
  register a "click", and an uptime check would count 1,440 visitors a day.
  The redirect still happens, of course.
- **HEAD requests** – that is how tooling asks, not an audience.
- **The owner themselves** and their working group, when signed in: testing
  your freshly printed code five times should not lift your campaign by five.
  This is only checked when a session cookie comes along anyway – for
  anonymous visitors the redirect still starts no session.

## Visit limit

A link can be capped at a number of visits – "only the first 50 get the
discount". After that it answers like an expired link: 410, with a reason
instead of guesswork. The field sits under *More options* when creating and
next to the expiry date when editing; empty means unlimited. The check runs
against the counter that is kept anyway – and since bots do not count, the
limit means real visits. Via the [API](API.md) the field is `max_visits`.

## Switches: one link, several targets

A poster hangs once, but the people in front of it differ. A link can
therefore carry **switches**: whoever scans with a phone lands in the app
store; whoever prefers English gets the English page; everyone else the main
target.

| Attribute | Values | Source |
| --- | --- | --- |
| Device | `mobile`, `tablet`, `desktop` | roughly from the user agent |
| Language | two letters (`en`, `fr`) | the browser's **preferred** language |
| Country | two letters (`at`, `ch`) | from an upstream service (see below) |
| Share (A/B) | a number from 1 to 99 | a dice roll per request |

### Language switches: the target's language belongs in the picture

Languages cannot be answered one by one, only against each other – and for
that it must be known **which language the main target speaks**. That is
what the *Language of the target URL* field directly above the switches is
for.

An example: main target German, one `en` switch to the English version.

| Visitor | lands on | why |
| --- | --- | --- |
| Browser `de, en` | German page | German precedes English and is the target language |
| Browser `zh, en` | English page | Chinese matches nothing, then English kicks in |
| Browser `en` | English page | English matches the switch |
| Browser `fr` | German page | nothing matches |

The second case is where simple solutions fail: a student with a Chinese
browser and English as second language should get the English version – a
German with the same second language should not.

**Without the target language** the strict rule applies: only whoever
*prefers* the switch's language is redirected. That is the safe fallback –
better someone stays on the main target than an `en` switch collecting
everyone, because English sits in almost every browser as second language.

Codes are trimmed to two letters, so `en` also matches `en-GB`. If the
browser sends weights (`q=0.8`), those decide – not the order in the header.

The three fields of a switch are always the same: **attribute**, **value**,
**target**. Which values are valid is shown as a table below the rows, and
the input field follows the chosen attribute – for the share it becomes a
number field with bounds, otherwise it gets fitting suggestions. Whatever
still does not fit is rejected on save: "Phone" instead of `mobile` or `30%`
instead of `30` is not silently accepted. `30` means: roughly every third
request lands on this target, the rest moves on to the next switch or the
main target. That builds an A/B test without recognising anyone: the dice
roll happens on every request anew. Over many requests the ratio holds, and
more is not what a split should do – recognition would be the cleaner
statistic, but costs exactly what this project does not spend.

Switches can be set **when creating** (under *More options*) and changed at
any time. Next to every stored switch stands how often it fired – that shows
whether a switch you set is ever used.

The **first matching switch wins**; if none matches, the main target applies.
The order is the whole logic – no and/or, no nesting. Whoever needs more does
not need a short-link tool. At most eight switches per link; the permission
is called `link_rules`.

**Nothing is stored.** The attributes are checked at request time and
forgotten – what device a single visitor had or which country they came from
is written nowhere. That is exactly what separates a switch from what is
called "targeting" elsewhere: there it is the occasion to build a profile;
here it is a case distinction, as traceless as an `if`. Only **how often**
each switch fired is counted – that appears in the link's statistics.

**On the country:** flatlink ships no geo database and loads none – an
IP-to-country table would be a multiple of the whole application and does not
fit a project you upload via FTP. But many fronting services deliver the
country ready-made (Cloudflare as `CF-IPCountry`, others as
`X-Country-Code`). It is only read behind a proxy listed in
`trusted_proxies` – otherwise any visitor could claim their country by
sending the header themselves, and a switch the other side can set is none.
Without that entry, "Country" does not appear in the interface.

Via the [API](API.md) the field is `rules` and takes a list of
`{wenn, ist, url}`.

## Campaign parameters (UTM)

When creating and editing a link, `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term` and `utm_content` can be set. They are appended to the target
address; existing query parameters stay untouched, an anchor stays at the
end.

**They are not evaluated here.** These parameters are the only way to tell
the *target page's* statistics – Matomo, Plausible, Google Analytics – where
someone came from. The short link itself keeps counting only visits per day:
no origin, no device, no record per visit. Whoever sets UTM parameters passes
the origin to the target page deliberately. A tool, not a recommendation –
which is why the block is collapsed and empty until someone uses it.

**No storage of its own.** The parameters live in the target URL and nowhere
else. The builder reads them from there and writes them back – storing them
at the link as well would mean maintaining two truths. Whoever edits the
address by hand edits the campaign with it, and the form shows the new state
on next opening.

Values already in use appear as suggestions. That is the cheapest protection
against the typo that splits an evaluation in half.

Also available in the CSV import (for the whole batch, not per row) and via
the [API](API.md) (field `utm`).

## Link-in-bio

A page with several targets under one short code – for the profile in a
social network, the sticker on a shop window, the footer of a menu. Created
under *Link-in-bio* in the administration, provided the account has the
`bio_page` permission.

Technically such a page is **one entry in the link inventory** that carries a
list of targets instead of one (`kind: "bio"`). It thereby inherits code
assignment, ownership, group membership, access checks, expiry, blocking,
deletion and the QR code – there is no second store and no second permission
model. If it belongs to a working group, the whole team maintains it.

Targets are maintained as field pairs – display name and address, more via
*Add link*. Without JavaScript three empty rows are always ready, so the page
can be used even then.

With the `bio_style` permission, logo and colours come on top. The choice is
the same library as in the QR designer: your own logos and those a group has
shared (see [QR generator](qr-generator.en.md)). Being allowed to upload is
not required – whoever may use a shared logo finds it here. The logo is
fitted proportionally, never cropped: at most 96 pixels high, 240 wide – a
square uses the height, a wordmark the width. A round frame would suit a
portrait; on a logo the corners would fall off and the lettering at the edge
with them.

Only what is in your own selection can be set; an already stored logo stays
untouched on editing, so it does not vanish under a deputy's hands.

**Imprint and privacy.** The page footer can carry both mandatory links: the
instance provides its own pages in `bio_legal_defaults`, and every bio page
can replace them with its own addresses – whoever runs their page
commercially is legally responsible themselves and links **their** imprint,
not the service's. Per page one source applies completely; empty and without
a default the footer stays clear.

Counting works as everywhere: one counter per day for the page and one per
target. To make the latter possible, the buttons point to the page's own code
with a running number (`/abc123?i=2`) instead of directly at the target. No
visitor record is created — the number only says *which* target was clicked,
not *by whom*.

The page is **served as its own document**, not in the instance's theme: no
header navigation, no hint at sign-in or plans, and by default no sender line
in the footer. It belongs to whoever created it.

## Moving in from another service

The CSV import under *Links → CSV import* recognises columns by the header
row rather than their position. The exports of **Bitly** (`Bitlink`,
`Long URL`, `Title`), **YOURLS** (`keyword`, `url`, `title`), **Shlink** (web
client: `shortCode`, `longUrl`, `title`, `tags` – the pipe-separated tags
become our commas) and **Kutt** (`address`, `target`, `description`) can be
uploaded unchanged. If the code column holds a full address like
`bit.ly/3xYz9`, the last part is taken – your short codes survive the move,
and printed codes keep pointing where they should after switching the
domain.

Recognised are, among others, `long url` / `url` / `target` for the target,
`keyword` / `bitlink` / `slug` / `address` / `code` for the short code,
`title` / `name` / `description` for the name and `expires` for the expiry
date. If a header row is missing, the old column order
`url;code;expiry;name` still applies. Separators – comma, semicolon, tab –
are detected automatically.
