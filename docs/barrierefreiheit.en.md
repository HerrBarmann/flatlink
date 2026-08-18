# Accessibility

🇩🇪 [Deutsche Fassung](barrierefreiheit.md)

This page states what was checked, what came out of it, and what is still
missing. It is a **self-assessment**, not a certificate: there has been no
audit by a testing body and no testing with screen-reader users. Anyone who
needs it for procurement can take it as a starting point and have the missing
parts examined.

The benchmark is **WCAG 2.1 Level AA**, which BITV 2.0 (Germany) and
EN 301 549 refer to.

## What is built in

**Text contrast.** Calculated, not estimated – for the colour pairs the
bundled theme actually uses:

| Combination | Contrast | Required |
| --- | --- | --- |
| Body text on a card | 16.1:1 | 4.5:1 |
| Muted text (`.muted`, labels) | 5.6:1 | 4.5:1 |
| Text link | 7.1:1 | 4.5:1 |
| Primary button (text on accent) | 5.3:1 | 4.5:1 |
| Input field | 17.2:1 | 4.5:1 |
| Skip link | 5.1:1 | 4.5:1 |
| Demo notice band | 7.8:1 | 4.5:1 |

A word on method, because there is a trap here: a purely automated DOM check
measures **wrongly** on this project as soon as a surface paints its
background through a pseudo-element – such as the slanted colour bands of the
1337.kiwi variant. A script that only reads `background-color` up the parent
chain sees the light ground underneath and reports rows of violations that do
not exist; one that includes pseudo-elements takes the decorative stroke over
a heading for the background and calculates just as wrongly. Both dead ends
were walked here before the pairs were calculated by hand. Anyone re-checking
should know this.

Whoever sets their own colours (`assets/custom.css`) leaves the checked state
and must recalculate.

**Keyboard operation.** Every function is reachable without a mouse. Focus is
visible: `:focus-visible` draws a 2 px outline in the accent colour around
links, buttons, disclosure widgets and fields – on dark bands in the light
paper colour. `:focus-visible` instead of `:focus` means mouse clicks leave
no outline, keyboard use does.

**Skip link.** The first tab stop is "skip to content"; it becomes visible on
focus and jumps past the header to `<main id="inhalt">`.

**Structure.** One `<h1>` per page, headings without level jumps, landmarks
(`header`, `nav`, `main`, `footer`) on every page, `lang` on the `<html>`
element from the configured language.

**Forms.** Every field has an associated `<label>` (or an `aria-label` where
no visible text makes sense). Errors appear as text above the form, not only
as colour. Required fields carry `required`, input kinds carry `type` and
`inputmode` – a phone keyboard adapts accordingly.

**Link-in-bio footer.** Imprint and privacy links sit in their own
`<nav aria-label>` landmark. Their colours inherit from the page design –
operators or page owners who set their own colours are responsible for that
contrast themselves (see themes above).

**Images.** Every `<img>` has an `alt`; purely decorative graphics carry an
empty one. The QR code in the preview carries a description; the data-record
proof on the front page is marked `role="img"` with an `aria-label`, so a
screen reader does not read it character by character.

**Motion.** There are no automatic animations, no carousel, nothing blinking.
Transitions are short and purely decorative.

**Zoom.** The page can be enlarged; there is no `user-scalable=no`. Input
fields are at least 16 px on touch devices, so iOS does not zoom in by itself
on tap. At 200 % magnification the content stays readable; wide blocks
(tables, code samples) scroll within themselves instead of pushing the page
sideways.

**Without JavaScript.** Creating, editing, deleting, signing in and the
redirect all work without scripts. JavaScript only improves the QR designer
(live preview) and copying to the clipboard.

## What has not been checked

- **No complete automated check** of all pages and states, for the reason
  above: on this layout it would be less reliable than calculating the pairs
  by hand.
- **No testing with screen readers** (NVDA, JAWS, VoiceOver) by people who
  use them daily. The markup is set to the best of our knowledge, but nobody
  has heard how it sounds.
- **No audit by a testing body**, i.e. no BITV test under the official
  procedure.
- **The QR designer** is the weakest spot: a tool with many controls and a
  live preview. The controls are operable and labelled, but whether one can
  design meaningfully without seeing the preview is doubtful. The generated
  files are unaffected.
- **Colour choices in custom themes**: whoever uses `custom.css` leaves the
  checked state.

## When something does not work

Barriers are bugs like any other. They belong in the
[issues](https://github.com/HerrBarmann/flatlink/issues) – with the assistive
technology used, the page, and what did not work. Operators of an instance
are legally responsible themselves; for them this page is the basis of their
own statement, not its replacement.

## For your own statement

Public bodies need their own accessibility statement on their instance. What
belongs there and is provided here: the benchmark (WCAG 2.1 AA), the state of
implementation, the known exceptions (above) and a contact channel for
reports. What each body must add itself: the date of creation, the assessment
procedure, its own reporting office and – in the EU – the reference to the
enforcement procedure under the Web Accessibility Directive (2016/2102); in
Germany that is conciliation under § 16 BGG.

A fill-in template (in German, matching the German legal framework) is
included in the [German version](barrierefreiheit.md) of this page.
