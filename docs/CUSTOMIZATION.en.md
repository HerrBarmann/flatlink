# Customizing flatlink

🇩🇪 [Deutsche Fassung](CUSTOMIZATION.md)

flatlink ships deliberately plain: a reserved blue-grey that claims nothing.
It is meant as a base coat, not a finished appearance. This guide shows how
to make it yours.

**Contents**

1. [The one rule](#1-the-one-rule)
2. [Colours](#2-colours)
3. [Logo, favicon and name](#3-logo-favicon-and-name)
4. [Footer](#4-footer)
5. [Type](#5-type)
6. [Individual areas](#6-individual-areas)
7. [QR codes](#7-qr-codes)
8. [A complete example](#8-a-complete-example)
9. [What happens on update](#9-what-happens-on-update)
10. [Your own pages and functions](#10-your-own-pages-and-functions)
11. [Where the line is](#11-where-the-line-is)

---

## 1. The one rule

> **Never edit `assets/style.css`.**
> Anything of your own belongs in `assets/custom.css`.

That file is loaded **after** the standard stylesheet and therefore
overrides it. It is excluded via `.gitignore`, so a `git pull` never touches
it. Whoever edits `style.css` instead will hit a conflict on the next update
— and eventually an instance that can no longer be updated.

Getting started:

```bash
cp assets/custom.example.css assets/custom.css
```

The template contains every variable with its default value and
commented-out examples for the most common requests. From here on, save and
reload is all it takes — a timestamp in the query string makes sure the
browser never shows anything stale.

---

## 2. Colours

The fastest route to your own appearance. The whole interface rests on nine
variables; replace them and the instance is recolored without touching a
single rule.

| Variable | What for |
| --- | --- |
| `--paper` | page background |
| `--surface` | cards and panels |
| `--ink` | text and borders |
| `--muted` | secondary text, labels |
| `--line` | separators |
| `--accent` | signal colour: primary action, success |
| `--accent-deep` | the accent as **text** on a light ground |
| `--accent-tint` | hover, highlighted rows |
| `--on-accent` | text on a filled accent surface |

```css
:root {
    --paper:       #FFFDF7;
    --surface:     #FFF6E0;
    --ink:         #2B1A08;
    --muted:       #7A6244;
    --line:        #EBDCBD;
    --accent:      #C8102E;
    --accent-deep: #96001F;
    --accent-tint: #FBE9EC;
    --on-accent:   #FFFFFF;
}
```

Three things recolourings regularly trip over:

**`--accent` and `--accent-deep` are not the same thing.** The first is a
surface (buttons, borders), the second is text on a light background. A red
that looks good as a surface is often too light as body text. That is why
there are two values — set both, not just one.

**Check the contrast.** `--muted` on `--paper` and `--accent-deep` on
`--surface` are the critical pairs. They should reach at least 4.5:1 so the
text stays readable for people with impaired vision. A contrast calculator
takes ten seconds.

**Do not forget the dark appearance.** If you only set the light values, the
instance still looks blue-grey — or worse, half-recolored — for everyone
whose system is set to dark. The second block is part of the job:

```css
@media (prefers-color-scheme: dark) {
    :root {
        --paper:       #14100A;
        --surface:     #1F1810;
        --ink:         #F2E9DA;
        --muted:       #B0A08A;
        --line:        #35291B;
        --accent:      #E8556E;   /* stronger on dark, or it gets lost */
        --accent-deep: #F2919F;
        --accent-tint: #2A1A1E;
        --on-accent:   #14100A;
    }
}
```

Do not simply invert the light values: dark surfaces swallow colour, the
accent has to be stronger and lighter there.

Anyone who deliberately offers only a light appearance leaves the block out and
sets `:root { color-scheme: light; }` instead — then form fields render
light as well.

---

## 3. Logo, favicon and name

These three live in `inc/config.php`, not in the stylesheet:

```php
'site_name' => 'Short links of Example University',
'logo'      => 'logo.svg',      // file in assets/
'favicon'   => 'favicon.svg',   // file in assets/
```

The logo appears left of the name in the header. Put the file into `assets/`
and enter just the filename. SVG is the best choice — razor sharp on every
screen and usually only a few kilobytes.

The size is decided by the stylesheet, not the file:

```css
.brand-logo { height: 2.2em; }        /* default: 1.7em */
.brand { font-size: 1.15rem; }        /* font size of the name */
```

Just the logo, without the wordmark? Then hide the name — but keep it
accessible to screen readers:

```css
.brand-logo { height: 2.4em; }
.brand { font-size: 0; }              /* hides the text, not the image */
```

The name from `site_name` also appears in the page title, in system mails
and in the footer — it should stand on its own without the logo.

---

## 4. Footer

Additional links come from the configuration:

```php
'footer_links' => [
    'Imprint' => 'imprint.html',
    'Privacy' => 'https://example.org/privacy',
],
```

Relative targets are resolved against the webroot, absolute ones
(`https://…`) lead outside. A plain HTML file next to `index.php` is
entirely sufficient.

**Whoever runs a public instance probably needs this.** In Germany and large
parts of the EU a legal notice and a privacy statement are mandatory.
flatlink deliberately ships no templates for them — they depend on operator,
purpose and usage, and a bundled template would do more harm than good.

### The origin line

The line with the small kiwi in the page footer **is required by the
license** — as an additional term under § 7(b) of the AGPL. It may be
translated, reworded and set discreetly; that is expressly part of the deal.
Anyone who wants to fit it to their own design overrides `.origin` in
`assets/custom.css` or replaces `origin_note()` via
[`inc/local.php`](#10-your-own-pages-and-functions) — as long as "flatlink"
is named and <https://1337.kiwi/flatlink> stays linked.

```php
'show_origin' => false,
```

This switch exists for tests and for the case below. **On its own it does
not permit running without the line.** For an edition without it — say,
white-label — there is a written waiver: <dennis@1337.hamburg>.

---

## 5. Type

Left alone, flatlink uses system fonts: a monospace for everything
product-like — header, short links, codes, buttons, labels — and the
operating system's default sans-serif for body text. Nothing is loaded, it
is there instantly, and it looks right everywhere.

Your own typeface:

```css
:root {
    --mono: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
}
body {
    font-family: "IBM Plex Sans", -apple-system, BlinkMacSystemFont, sans-serif;
}
```

With your own font file — put it into `assets/fonts/`:

```css
@font-face {
    font-family: "Housetype";
    src: url("fonts/housetype.woff2") format("woff2");
    font-weight: 400;
    font-display: swap;
}
body { font-family: "Housetype", sans-serif; }
```

> **Please self-host, do not pull from a CDN.** An external font service
> sees the IP address of every single visitor. For software whose whole
> point is *not* recording visitors, that would be an own goal — and in the
> EU legally dicey on top.

Keep a real monospace for `--mono`. Several places are built on equal
character width, such as aligned numbers and codes.

---

## 6. Individual areas

When the variables are not enough, these are the classes to hook into:

| Class | Area |
| --- | --- |
| `.site-head` / `.site-foot` | header and footer |
| `.brand` / `.brand-logo` | wordmark and logo |
| `.hero` | title area of the start page |
| `.card` | cards: forms, lists, notices |
| `.card.highlight` | highlighted card |
| `.btn` / `.btn-primary` | buttons |
| `.flash-ok` / `.flash-err` | feedback messages |
| `.tag` / `.tag-on` | group badges |
| `.table-scroll table` | tables |
| `.short-row` / `.grid-form` / `.check` | form layouts |
| `.designer` | two-column layout of the QR designer |
| `.origin` | origin line in the footer |
| `main` | content area; grows so the footer stays at the bottom |
| `body.<name>` | a whole design variant, see below (`body_class`) |

The QR designer has three extension points in `inc/local.php`:
`designer_description()` supplies the meta description, `designer_intro()`
content above and `designer_outro()` below the tool. The page itself stays
core code while an instance can grow it into a discoverable landing page.

A few tried-and-tested tweaks:

```css
/* Angular instead of round */
:root { --radius: 0; }
.btn { border-radius: 0; }

/* Header in the brand colour */
.site-head {
    background: var(--accent);
    border-bottom: 0;
    padding-inline: 1rem;
    margin-inline: -1rem;
}
.site-head .brand,
.site-head nav a { color: var(--on-accent); }

/* Cards with shadow instead of border */
.card {
    border-color: transparent;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.08), 0 1px 2px rgb(0 0 0 / 0.04);
}

/* Wider page */
.wrap { max-width: 1140px; }
```

### Variants you can switch off

A bigger rebuild is risky: if you end up not liking it, it has to be cut out
again — and usually something good is lost along the way. For that there is
`body_class` in the configuration. The value lands as a class on `<body>`,
and everything written under it applies only as long as it is set:

```php
// inc/config.php
'body_class' => 'angular',
```

```css
/* assets/custom.css */
body.angular { --radius: 0; }
body.angular .card { border-width: 2px; box-shadow: 5px 5px 0 var(--ink); }
body.angular .btn { border-radius: 0; }
```

An empty value switches the whole variant off without deleting a line of CSS
— and a different value switches to the next one. Two drafts can be
maintained side by side and switched between.

For **full-width colour bands** that break out of the content column, this
pattern works well:

```css
body.variant .band { position: relative; }
body.variant .band::before {
    content: "";
    position: absolute;
    z-index: -1;               /* behind the content, not in the way */
    inset: 0;
    left: 50%;
    width: 100vw;
    transform: translateX(-50%);
    background: var(--accent-tint);
    clip-path: polygon(0 0, 100% 0, 100% calc(100% - 3rem), 0 100%);
}
body.variant { overflow-x: clip; }   /* not 'hidden': that breaks position: sticky */
```

Two stumbling blocks here: with a visible scrollbar, a `100vw` wide element
is slightly wider than the content — hence `overflow-x: clip` on the `body`.
And such a surface must not reach below the last content, not even "just a
bit to be safe": it lengthens the scroll area, and the page scrolls into
empty space.

---

## 7. QR codes

The generated codes can be adapted independently of the website's look.

**An attribution line under every code** — useful when printed codes should
be recognisable:

```php
'qr_brand_text' => 'example-university.edu',
```

It appears as a discreet line below frameless codes and inside the band of
framed ones. Empty means: no line.

**Clean lettering in PNG.** Frame and sender texts are always set cleanly in
SVG. For PNG and PDF the image library needs a TrueType file: put any `.ttf`
into `assets/fonts/`, the first one found is used. Without a file a coarse
system font is used instead — readable, but not pretty.

No font is bundled on purpose, so no third-party font licence attaches to
the project. Freely usable options include DejaVu Sans Mono, JetBrains Mono
or Inter.

**Colours and shapes of the codes** are set by each user in the QR designer
— that is part of the interface, not configuration.

---

## 8. A complete example

This is what a fully converted instance looks like — warm paper, strong red,
angular shapes, your own logo. Tested, not imagined.

`inc/config.php`:

```php
'site_name'    => 'Short links of Example University',
'logo'         => 'logo.svg',
'footer_links' => [
    'Imprint' => 'imprint.html',
    'Privacy' => 'https://example.org/privacy',
],
```

`assets/custom.css`:

```css
:root {
    --paper: #FFFDF7; --surface: #FFF6E0; --ink: #2B1A08; --muted: #7A6244;
    --line: #EBDCBD; --accent: #C8102E; --accent-deep: #96001F;
    --accent-tint: #FBE9EC; --on-accent: #FFFFFF;
    --radius: 0;
}
@media (prefers-color-scheme: dark) {
    :root {
        --paper: #14100A; --surface: #1F1810; --ink: #F2E9DA; --muted: #B0A08A;
        --line: #35291B; --accent: #E8556E; --accent-deep: #F2919F;
        --accent-tint: #2A1A1E; --on-accent: #14100A;
    }
}
.btn { border-radius: 0; }
```

`assets/logo.svg` — as a placeholder, this is enough to try it out:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40">
  <rect width="60" height="40" rx="3" fill="#C8102E"/>
  <text x="30" y="26" font-family="monospace" font-size="17" font-weight="700"
        fill="#fff" text-anchor="middle">EU</text>
</svg>
```

That is all it takes. No source touched, everything update-safe.

---

## 9. What happens on update

A `git pull` replaces `assets/style.css` — your `custom.css` stays
untouched, as do `inc/config.php`, `assets/fonts/` and all your own image
files.

What that means: your overrides keep applying, but when a new version
renames classes or rebuilds areas, individual rules can end up matching
nothing. **Variables are safe from that** — they are the most reliable layer
and the reason to solve as much as possible through them. Rules aimed at
concrete classes are less so.

After a bigger update it is therefore worth a short look at your own
instance. New options appear first in `inc/config.example.php` and
`assets/custom.example.css`.

---

## 10. Your own pages and functions

Additional pages are just more files in the webroot — they can include
`inc/store.php` and reuse all the building blocks. They are added to the
navigation via the configuration:

```php
'nav_links'       => ['Help' => 'help.php'],      // always visible
'nav_links_guest' => ['Pricing' => 'pricing.php'], // signed-out visitors only
```

If these pages need helper functions of their own, they belong in
**`inc/local.php`**. If the file exists it is loaded automatically; it is
exempt from updates and thus the right place for everything only your
installation needs.

```php
<?php
// inc/local.php
function notice_box(string $text): string
{
    return '<div class="card highlight"><p>' . e($text) . '</p></div>';
}
```

It can only **add**, though: existing functions cannot be overridden in PHP.

## 11. Where the line is

Without touching the source, you can **not** change:

- **The exact wording of the interface.** The language can be switched
  instance-wide (`'language' => 'en'`), but rewording individual texts means
  editing the language files in the core.
- **Structure and order of the existing pages.** Which card sits where is
  decided by the individual PHP script.
- **The order of navigation items.** Your own entries always come first.

Anyone who needs those cannot avoid a fork — the license expressly allows
it. Expect to merge by hand occasionally on updates.

And if your customisation is missing a hook that would help others too: [say
so](https://github.com/HerrBarmann/flatlink/issues). That is exactly how
this guide came to be.
