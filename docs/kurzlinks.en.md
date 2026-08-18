# Short links day to day

Order and tools around the links: tags, campaign parameters, link-in-bio
pages and migrating from another service. Back to the
[README](../README.md). – 🇩🇪 [Deutsche Fassung](kurzlinks.md).

## Tags

From a few hundred links on, search alone stops being enough. Each link takes
up to eight tags, entered comma-separated. Above the list sits a cloud of all
assigned tags with their counts; one click filters, another on "show all"
lifts the filter again. Filter and search can be combined.

Tags are **stored lowercased**: "Campaign" and "campaign" should be the same
drawer, otherwise you have both after a week. They are order, not
permission – to control access, use
[groups](gruppen.en.md#two-kinds-of-groups).

Also available through the [API](../API.md) (field `tags`, filter `?tag=`)
and in the CSV import (column `schlagworte` or `tags`).

## Campaign parameters (UTM)

When creating or editing a link, `utm_source`, `utm_medium`, `utm_campaign`,
`utm_term` and `utm_content` can be set. They are appended to the target
address; existing query parameters stay untouched, an anchor stays at the
end.

**Nothing is evaluated here.** These parameters are the only way to tell the
*target site's* statistics – Matomo, Plausible, Google Analytics – where
someone came from. The short link itself still counts only visits per day: no
origin, no device, no per-visit record. Whoever sets UTM parameters
deliberately hands the origin to the target site. A tool, not a
recommendation – which is why the block is collapsed and empty until someone
uses it.

**No separate storage.** The parameters live in the target URL and nowhere
else. The builder reads them from there and writes them back there – storing
them additionally on the link would mean maintaining two truths. Whoever
edits the address by hand thereby edits the campaign, and the form shows the
new state on next opening.

Values already used appear as suggestions. That is the cheapest protection
against the typo that splits an evaluation into two halves.

Also available in the CSV import (for the whole run, not per row) and through
the [API](../API.md) (field `utm`).

## Link in bio

One page with several targets under one short code – for the profile on a
social network, the sticker on a shop window, the footer of a menu. Created
under *Link in bio* in the admin area, provided the account has the
`bio_page` permission.

Technically such a page is **one entry in the short-link store** that carries
a list of targets instead of one target address (`kind: "bio"`). It thereby
inherits code assignment, ownership, group membership, access checks, expiry
date, blocking, deletion and QR code – there is no second store and no second
permission model. If it belongs to a working group, all members maintain it.

The targets are maintained as field pairs – display name and address, more
via *Add link*. Without JavaScript, three empty rows are always available, so
the page remains usable then as well.

Counting works like everywhere: one counter per day for the page and one per
target. To make the latter possible, the buttons point at the page's own code
with a running number (`/abc123?i=2`) instead of directly at the target
address. No visitor record is created – the number only says *which* target
was clicked, not *by whom*.

The page is **delivered as its own document**, not in the instance's theme:
no header navigation, no hint about sign-in or plans, and out of the box no
attribution line in the footer either. It belongs to whoever created it –
whoever scans the QR code on the shop window wants the menu, not the
shortener's menu.

Whoever runs a public instance with free accounts has good reasons for an
attribution line and sets it via `bio_footer_text` (lead-in) and
`bio_footer_glyph` (symbol); `''` leaves just the wordmark, `null` – the
default – omits the whole line.

The order of the targets can be changed with arrow buttons per row – without
JavaScript it stays the order of the fields, which works too, just more
laboriously. What a page without custom styling looks like is set by
`bio_default_colors`; if empty, a neutral dark gray remains. Where no custom
logo is set, the instance's wordmark sits at the top, built like the one in
its page header.

Accounts with the `bio_style` permission additionally choose **a logo from
the logo library and four colors** (background, text, buttons and their
labels). Color values are validated against `#rrggbb` before they reach the
style block; anything else falls back to the default. The logo is served by
`logo.php` – an endpoint that hands out exactly one file, and only if its
identifier is recorded in the library. How many pages an account may create
is the `bio` limit in the base rules and can be raised per group.

Search engines are excluded by default (`noindex`); whoever wants the page to
be found ticks that explicitly. A page that hangs on a door as a QR code does
not also need to sit in the index.

## Migrating from another service

The CSV import under *Links → CSV import* recognizes columns by the header
row instead of their order. The export of **Bitly** (`Bitlink`, `Long URL`,
`Title`) and that of **YOURLS** (`keyword`, `url`, `title`) can therefore be
uploaded unchanged. If the code column contains a full address like
`bit.ly/3xYz9`, the last part is used – so the short codes are preserved, and
printed codes keep pointing where they should after the domain switch.

Recognized are, among others, `long url` / `url` / `ziel` for the target,
`keyword` / `bitlink` / `slug` / `code` for the short code, `title` / `name`
for the label and `expires` / `ablaufdatum` for the expiry date. Without a
header row, the fixed order `url;code;expiry;title` applies.

The import is open to **every account**, so a migration doesn't fail at a
permission: without the `csv_import` right, a run reaches as far as there is
room left in the account's link quota – which applies at creation anyway.
Only bulk operation beyond that hangs on the permission.

How many rows a run then accepts is set in `'import_max_rows'` (default
100). Whoever takes over a larger stock raises the value and lets the import
run in peace.
