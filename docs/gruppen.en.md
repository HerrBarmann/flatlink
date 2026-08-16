# Groups, permissions and domains

How permissions become plans, how teams manage links together, how namespaces
and separate domains per client are kept apart. Back to the
[README](../README.en.md). – 🇩🇪 [Deutsche Fassung](gruppen.md).

## Groups and permissions

Without groups, flatlink behaves like a single-user tool: every account sees
only its own links. Groups change two things.

**Shared links.** When creating a link, a group can be chosen. The link then
belongs to the whole team: every member sees it, can change its target, style
the QR code, view the click counts and delete it. That is the actual purpose
– a printed code should not depend on whether the colleague who created it is
still in the house. Whoever originally created the link keeps it regardless
of the group.

**Permissions.** Every group carries a set of permissions its members
receive. An account in several groups has the union of all permissions.

They come in two kinds, and the distinction is not cosmetic: the first says
**what an account may do with its own links** – on an instance with paid plans
that is precisely the plan. The second says **what someone may do for others**,
and describes a role in the organisation. The interface therefore shows them in
two labelled blocks; whoever sets up a "Marketing" work group should not tick
something that costs money by accident.

*What an account may do itself:*

| Permission | Meaning |
| --- | --- |
| `custom_code` | may assign custom names instead of random codes |
| `csv_import` | may import many links at once |
| `logo_upload` | may upload custom logos for QR codes |
| `qr_unbranded` | produces QR codes without the attribution line |
| `api_access` | may use the API – and with it the browser extension |
| `bio_page` | may create link-in-bio pages |
| `bio_style` | may style them (logo and colours) |
| `link_rules` | may set switches (target by device, language, country) |

*What someone may do for others:*

| Permission | Meaning |
| --- | --- |
| `links_all` | sees and manages **all** links of the instance |
| `reports_manage` | handles abuse reports and blocks links |

A permission attached to a group ends with the membership – including a
time-limited one. What was **created** with it stays, though: existing switches
keep redirecting, a styled bio page keeps its looks, custom names remain
editable. Only new things follow the rules without the permission again. Anyone
modelling paid plans should keep it that way: a printed code pointing nowhere
because an invoice is open does more damage than the lost revenue.

### An editorial team without administrator rights

Together, the last two permissions form what elsewhere would be a role of its
own between "user" and "administrator" – without there having to be a third
role. A group "Editors" with `links_all` and `reports_manage` may:

* see, edit and block every link of the instance,
* work through incoming reports and trigger the re-check of the stock.

It explicitly may **not**: create accounts, change groups, alter settings,
read the audit log. Anyone who tries anyway gets a 403. That is the usual
split in operation: whoever tends the abuse inbox needs access to every link
– but not to the SMTP credentials.

Both permissions can also be granted separately. `links_all` alone yields an
oversight role that sees everything but handles no reports; `reports_manage`
alone a complaints desk that only ever sees the reported links.

### Namespaces

A group can carry a **prefix**. Its members then create short links
exclusively beneath it:

```
kurz.hochschule.de/bib/oeffnungszeiten     ← group "Library", prefix bib
kurz.hochschule.de/stud/mensaplan          ← group "Students", prefix stud
```

That settles the fight over short names before it starts: every unit has its
own space, and `/mensaplan` stays free for the central administration.
Whoever is in several groups with a prefix chooses at creation;
administrators are not restricted. Without a prefix everything behaves as
before.

### Limits and time limits

Groups can additionally carry **their own limits** that raise the global ones
from `config.php` – whoever is in several gets the highest value of each. And
a membership can be **time-limited**: after the cut-off date it no longer
counts, entirely without a cron job. This lets you model a tiered offering
without the software having to know a notion of "plan".

In `config.php`, `default_perms` sets what **every** signed-in account may do
additionally – even without a group. Administrators may always do everything.

Custom names in particular are a good example of why this hangs on groups:
the namespace of an instance is finite, and whoever secures `/team` takes it
from everyone else. As a group permission it can be granted deliberately,
instead of allowing it for everyone or no one.

Groups are created in the admin area under **Groups**; accounts are assigned
under **Users**. With central sign-in the assignment can also come from the
directory ([Groups from the directory](konten.en.md#groups-from-the-directory)).

### Base rules and groups

What **every** account may do is set under *Settings → Base rules*: limits
for links, statistics depth and logos, the quota for custom codes and the
permissions everyone gets. Their defaults live in `inc/config.php`; whatever
is changed in the interface overrides them and lands in `data/settings.json`.
Whoever should get more than the base frame gets it through a group.

### Two kinds of groups

A group can mean two different things, and the two have nothing to do with
each other. That is why it is chosen explicitly at creation:

| Kind | What it does | What for |
| --- | --- | --- |
| **Permissions only** | Grants permissions and limits to its members. Their links stay private. | Plans, roles, quotas |
| **Permissions and shared link management** | Additionally, links can be assigned to the group; every member can see, change and delete them. | Teams working together |

The difference is not a subtlety. If a paid plan hangs on a group and that
group is set up as a working group, it appears in every customer's assignment
field – and whoever selects it by accident releases their link to all other
customers for editing and deletion.

The interface therefore creates new groups as **permission groups**. The
opposite mistake is cheaper: a team that doesn't see its links speaks up
immediately – a leak is noticed by no one. Groups created before this
distinction still count as working groups so existing teams aren't locked
out; the *Kind* column in the group management shows for each group where you
stand.

## Multiple domains

Short links can be issued under several addresses – `client.link/shop`
instead of `your-instance.example/shop`. All domains point at the same
installation: at the same server in DNS, included in the certificate. They
are set up under *Settings* or via `'domains'` in the configuration; a domain
can be reserved for a group, just like a namespace prefix.

**A code belongs to the instance, not to the domain.** There is exactly one
`/shop`, and it resolves under every configured address. That is the
load-bearing decision, so here are both sides:

- *For:* a printed code doesn't die when a domain goes away. If a client
  moves or a domain expires, the stickers keep working. For a service whose
  entire purpose is "printed is printed", that outweighs exclusivity.
- *Against:* two clients cannot both have `/shop`. Whoever needs that gives
  them [namespace prefixes](#two-kinds-of-groups) – that is what they are
  for.

Separate namespaces per domain would be a different data model: a link would
no longer be determined by its code but by domain *and* code. That would run
through storage, API, import and every interface.

The **administration stays on the main domain** – the one from `base_url`.
One session, one cookie, one address for passkeys: a passkey registered under
`client.link` could no longer be used on the main domain. Requests to
`/admin/` under a secondary domain are therefore redirected before a session
even comes into being.

A secondary domain serves **short links only**. Start page, QR generators,
report page and administration redirect to the main domain (302, not 301 – a
domain may later become the main domain). Excepted are the pages belonging to
a code: password prompt, expired, blocked, not found. They stay under the
address the code was printed with.

The domain can be chosen at creation, at editing, in the CSV import (for the
whole run, not per row) and through the [API](../API.md) (field `domain`).
If a domain is removed again, the links remain – they then point at an
address that is no longer configured and must be switched over one by one.
The delete button says so.
