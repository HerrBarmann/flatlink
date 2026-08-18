# The QR generator

Everything about QR codes in flatlink: the two kinds of code, the in-house
encoder, design options, readability, print export, batches and the GS1
Digital Link. Back to the [README](../README.md). –
🇩🇪 [Deutsche Fassung](qr-generator.md).

## Two kinds of QR code

The designer offers both paths, and the difference is the one decision to
make before printing:

**With a short link.** The code points at your own instance. The target can
be changed at any time without replacing the printed code, and there is a
click count. The code needs the instance as long as it is in circulation.

**Without shortening** (`qr-designer.php?m=statisch`). The address sits
directly in the code. Nothing is stored, the code runs through nobody, and it
still works if the instance no longer exists. In exchange, the target is
fixed.

The static path also takes `mailto:`, `tel:` or simply a text. If something
domain-shaped lacks a scheme, `https://` is prepended – otherwise the input
is left untouched.

## Five types, one generator

A QR code contains text – what that text means is decided by the application
reading it. The same encoder therefore does more than addresses:

| Tab | File | What the code holds |
| --- | --- | --- |
| Link | `qr-designer.php` | an address, a short link or free text |
| Wi-Fi | `wlan-qr.php` | `WIFI:` – network name, encryption, password |
| Contact | `vcard-qr.php` | a vCard 3.0 |
| Event | `termin-qr.php` | an iCalendar entry (`VEVENT`) |
| GS1 | `gs1-qr.php` | a GS1 Digital Link (see below) |

The tabs appear on each of these pages (`qr_type_nav()`); signed in, the
first one leads to the designer in the admin area, where logos and the link
assignment come on top.

All four extra types produce **static** codes: the data lives in the code
itself, nothing is stored, and they keep working even if this instance no
longer exists. The price is that they cannot be changed any more – whoever
needs that takes a short link.

The inputs go to `qr.php` via POST, not as address parameters: a Wi-Fi
password has no business in server logs or browser history.

## The logo library

It has a **page of its own** under *Logos* in the administration
(`admin/logos.php`). That is not cosmetics: the selection in the designer
belongs to designing one code, the library is an inventory maintained
independently of it – whoever uploads a logo rarely wants to build a QR code
in that moment. The designer therefore keeps only the selection field and a
pointer here.

On the page every logo is a card: preview on a checkered ground (so you can
see where the transparency sits in cut-out images), name, owner, sharing and
delete button. Logos shared by others via a group appear as well, but
without management – use yes, change no.

Whoever has the `logo_upload` permission can upload their own logos; how
many is bounded by the `logos` limit. A logo belongs to whoever uploaded it.

**Sharing.** Every own logo can be shared with groups. Members of those
groups then find it in their selection, marked with the account it belongs
to. The special value "all signed-in accounts" opens it to everyone.

Sharing means **permission to use**, not to manage: renaming and deleting
stay with the owner (and administrators), and the logo still counts towards
their quota. Whoever sees a shared logo in their list can use it, but take
it from nobody.

Technically the share lives in `data/logos.json` as a list of group ids
(`shared`); the star `*` stands for all accounts. Groups that no longer
exist are discarded on save.

### The logo no longer cuts modules

Modules touching the logo's clear area are not drawn at all – previously the
area was laid over the finished modules, leaving half moons and bar stumps at
its edge. The handful of extra missing modules is well within the error
correction's budget; with a logo it is set to H anyway. Whoever disables the
clear area (`logoShape: none`) still gets the logo directly on the modules –
that is meant for transparent logos.

## The encoder

Plain PHP following ISO/IEC 18004, byte mode, versions 1–40, all four error
correction levels, mask selection via the standard's penalty score.

Only **two number rows per level** are copied from the standard – ECC
codewords per block and block counts from table 9. Everything else follows
computationally: the total codeword count from the geometry of the matrix,
the split into short and long blocks from a division with remainder, the
positions of the alignment patterns from the step-width rule. A table of 320
hand-typed values would have been the more likely source of errors.

This is not verified by eyeballing: all **160 combinations** of version and
error correction are filled to the brim, rendered and read back byte by byte
with a third-party decoder (`zbarimg`). The maximum lengths that come out –
2953 / 2331 / 1663 / 1273 bytes for L/M/Q/H – are exactly those of the
standard.

## Module shapes and background

Seven shapes for the data modules: square, rounded, strongly rounded, dots,
diamond, and vertical and horizontal **bars**. The bars are the only case
where a shape reaches beyond one module: consecutive dark modules merge into
a continuous stroke with round ends. The runs are computed once by
[`moduleRuns()`](../inc/qrlib.php) for all three drawing paths, so SVG, PNG
and vector output cannot drift apart.

The **background can be switched to transparent** (`bg=none`). In PNG the
area becomes genuinely transparent, in SVG the base rectangle is omitted, in
PDF and EPS the paper shows through – which is the same thing. The
readability check says what it can say about this: whether the code reads is
then decided by the surface beneath it, and that cannot be checked from here.

## Eyes

The outer ring and the inner core can be shaped separately (square, rounded,
circular, leaf) and colored separately. Empty means "like the one above": the
core takes the ring's shape and color, the ring takes the data modules'
color – so the default remains exactly what it was before.

**The circular ring is deliberately not a full circle** but a very strongly
rounded square (radius 3.0 instead of 3.5 modules). Measured over 1224
combinations of module shape, eye shape, content and raster size: with a full
circle, 90 % of the generated images scanned; with 3.0 it is 100 %. The
reason is in the standard – a scanner looks for lines on which the finder
pattern shows the ratio 1:1:3:1:1; with a square that holds on each of the
seven rows, with a full circle only near the center. The 0.5 modules change
little about the look and everything about the reliability.

**A note on the leaf shape that shows how design is handled here.** It
initially had a radius of 3.5 modules, i.e. one corner half cut away – pretty,
but the code failed at several raster sizes while the other shapes read
cleanly at the same sizes. The finder pattern must keep the ratio 1:1:3:1:1
along every scan line through its center; cutting half of it away leaves the
territory a scanner knows. The radius was therefore pulled back to 2.0.
Design must not make a code unreadable.

## Gradients

Linear with a free direction or radial from the inside out, plus four
presets. The gradient covers the data modules and the eyes; the background
stays a single color.

**Coloring happens per module, not with the gradient tool of the respective
format.** SVG and PDF could do a smooth gradient, PNG and EPS level 2 cannot
– four formats with two methods would be four results differing in detail. Of
all places, the print export is where nobody wants to figure out why the file
looks different from the preview. A QR code consists of tiles anyway; one
color per tile is indistinguishable from a smooth gradient at any reasonable
size.

**This does not mix with CMYK**, and that is why the print color wins there:
a gradient in four-color printing is a decision of its own – screening, ink
coverage, paper – and a silently converted gradient would be a poor answer to
it. The interface says so instead of letting it happen.

## Readability

The more can be designed, the more easily a code emerges that looks good on
screen and fails on the table display. The designer therefore checks along
with every change and shows hints next to the preview:

- **Contrast** between foreground and background, separately also for the
  second gradient color and the eye colors – a gradient is often strong at
  one end and too pale at the other. Also a warning when the code is lighter
  than its ground.
- **Quiet zone** below the standard's four modules.
- **Logo share** against what the chosen error correction level carries.
- **Output size**: pixels per module for PNG, millimeters per module for PDF
  and EPS.

Checking happens **on the server** ([`inc/qrcheck.php`](../inc/qrcheck.php)),
not in the browser: the thresholds belong to the rules of the service and
should not depend on what a browser happens to execute – and the batch
download has no script at all.

**Where the numbers come from, and where they don't.** Margin, logo share and
module size follow the standard and the capacity of the error correction;
those can be recomputed. The contrast thresholds cannot: a software decoder
still reads light gray on white (1.3:1) flawlessly from a clean PNG and
cannot substantiate them. What makes a code fail is the camera – noise,
slanted light, paper the ink bleeds into. The values follow the symbol
contrast of the grading standards for printed codes and deliberately sit on
the cautious side.

Behind the logo lies a **cleared area** (rounded, square, circular or none,
with adjustable padding). It is not ornamentation: a logo that half-covers
modules confuses recognition more than a cleanly cut-out area, which the
error correction absorbs.

## Export for print

Five formats from the same template:

| Format | What for |
| --- | --- |
| SVG | Web and further processing, with the logo embedded |
| PNG | Screen, office, everything pixel-based |
| **PDF** | real vectors, one page at the desired size |
| **EPS** | typesetting and imaging – the format print shops ask for |

PDF and EPS contain **no raster graphics**: the code consists of paths and
can be scaled to poster size without going soft. The PDF of an ordinary code
is about 4 kB – a fraction of the embedded image it used to carry.

**CMYK.** Whoever specifies the four print colors gets them *unchanged* in
PDF and EPS. Conversion only happens in the other direction: SVG, PNG and the
preview show an approximation, because a screen cannot do CMYK. Without a
color profile there is no right answer for that – the print file is
authoritative, and the interface says so.

Both formats take their geometry from the same source as the SVG
([`QrRenderer::vectorOps()`](../inc/qrlib.php)); text uses Courier from the
standard repertoire of both formats, i.e. without an embedded font file and
without a license question.

This is proven without Ghostscript: a test program reads the generated files
back, draws the contained paths and has `zbarimg` scan them – across all
module and eye shapes, with frame, with attribution line and in CMYK.

## Text in PNG output

Frame and attribution text are set properly in SVG. For PNG and PDF, GD needs
a TrueType file: put any `.ttf` into `assets/fonts/`; the first one found is
used. Without a file, a coarse GD system font kicks in. No font is bundled on
purpose, so no third-party font license attaches to the project.

## QR batches as ZIP

Twenty table displays, an exhibition, a sticker series: *QR batch* in the
header packs the QR codes of several links into one archive. The full design
panel applies to the whole batch – shapes, eyes, colors, gradients, error
correction, frame text and logo – with a live preview on the first link of
the list. At most 200 codes per archive.

The path leads through the list: filter by tag or group, then the button
above the list – the selection is already made.

The ZIP contains **an overview as CSV**. Whoever hands a batch to a print
shop needs the mapping from file to target, not just the images; and the file
names additionally carry the link's label, so they still mean something on
someone else's desk.

The archive is written by [`inc/zip.php`](../inc/zip.php) – **without the PHP
`zip` extension**. It is not enabled everywhere and wants a real file on
disk: write first, deliver, clean up. Exactly the case that fails on cheap
hosting and never on the developer's machine. The format itself is manageable
once you drop what nobody needs here: no encryption, no split archives, no
ZIP64. Compression uses `gzdeflate()` where it helps – otherwise data is
stored; both are part of the format.

## GS1 Digital Link

Besides short-link, Wi-Fi, vCard and event codes, `qr.php` also produces
**GS1 Digital Links** – the address form that is to appear on packaging next
to or instead of the barcode from "Sunrise 2027" on:

```
POST qr.php
  t=gs1
  gtin=4006381333931       article number, 8/12/13/14 digits
  lot=LOT-42               batch (optional)
  serial=SN-0001           serial number (optional)
  mhd=2027-12-31           best-before date (optional)
  resolver=https://…       your own resolver (optional)
```

This becomes `https://id.gs1.org/01/04006381333931/10/LOT-42?17=271231`. The
order of the components is fixed by the GS1 syntax and not a matter of taste;
readers rely on it. The **GTIN's check digit is recomputed** – if it doesn't
match, you get an error message instead of a code that gets noticed on a
pallet.

What flatlink does **not** do: run a resolver. What appears on scanning is
decided by the operator of the configured address; without one, the code
points at GS1's own service. The logic lives in
[`inc/gs1.php`](../inc/gs1.php); flatlink does not ship an interface for it –
it is quickly built as a page of your own.
