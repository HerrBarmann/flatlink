# Accounts and sign-in

Sign-in in two steps, passkeys and one-time passwords, central sign-in via
LDAP or the web server, and what accounts decide about their own data. Back
to the [README](../README.md). – 🇩🇪 [Deutsche Fassung](konten.md).

## The sign-in form

Signing in takes two steps: first who you are, then the proof.

That is not fashion but necessity. A passkey is tied to an account – the page
cannot know which devices qualify until it knows who is signing in. As long as
username and password shared one form, the only place left for a passkey was
*behind* the password, which is the role of a second factor. That wastes it: a
passkey is already two things at once – possession of the device **and**
unlocking it.

So:

1. **Username.** Email address or username. Anyone with a discoverable passkey
   is offered it right here, in the field's own suggestion list, and is one tap
   away from being signed in – no typing at all. The field carries
   `autocomplete="username webauthn"` for that; the search happens on the
   device, not on the server.
2. **Proof.** If passkeys exist, the prompt starts on its own. Below it,
   separated by an *or*, sits the password field — not behind a toggle, simply
   there. With no passkeys, the upper half is absent.

*Not you?* returns to step 1. Without JavaScript only the passkey button falls
away; everything else stands where it stands — there is nothing to hide and
nothing to bring back.

### What the passkey has to carry alone

As a second factor the server was content with the *User Present* bit (0x01):
somebody touched the device – the knowledge proof was the password before it.
As a replacement for the password that is not enough. flatlink additionally
requires *User Verified* (0x04): the device checked a fingerprint, a face or a
PIN. Without that, a phone left unlocked on a desk would be the entire
sign-in.

Registration in the profile therefore asks for `userVerification: 'required'` –
a device that never asks would not get through later, and that is better said
up front. The same response still passes as a second factor; the difference
lies purely in what it is being used for.

This is pinned down by [`tests/passkey-anmeldung.php`](../tests/passkey-anmeldung.php):
the test plays the authenticator itself and checks, among other things, that
one and the same response is accepted as a second factor and rejected as a
password replacement.

### What the form reveals about accounts

Named plainly, because it cannot be avoided entirely: an unknown name looks
exactly like an account without a passkey in step 2 – a password field, and
the error only after submitting. An account that *does* have a passkey is
recognisable by the prompt that starts.

That is the price of the offer, and it is the same trade the large providers
make. The path through the suggestion list in step 1 gives away nothing: there,
the device does the searching. Both paths sit behind the same failed-attempt
limiter as password sign-in.

## Two-factor sign-in

Why this is in here: anyone who takes over an account can change the target of
a short link – including one whose code has long been printed on a sign. The
damage then hits not the account holder but everyone who scans. For a
service that hands out printed codes, a password alone is a thin door.

Two methods are available, both set up in the profile. They are not mutually
exclusive – whoever registers both gets the choice at sign-in.

Strictly speaking the heading is now only accurate for the one-time password:
it stands *beside* the password. The passkey stands *in its place* (see above)
and brings its own second factor along.

### Passkeys (WebAuthn)

Fingerprint, face or device PIN, stored on the phone, the computer or a
security key. Up to ten devices per account.

The difference from a one-time password is not convenience but the **binding
to the domain**. A six-digit code can be typed into a fake sign-in page and
passed on within seconds; a passkey isn't even handed over by the browser
there, because the origin doesn't match. That is the real gain.

Implemented in [`inc/webauthn.php`](../inc/webauthn.php) – plain PHP, like
everything here: the CBOR reader is written in-house, the signature is
checked by the OpenSSL that PHP ships anyway. ES256 is supported, as is
(which phones and security keys practically always deliver) and RS256 (older
Windows Hello installations). `assets/passkey.js` only repacks between JSON
and the browser's binary interface; **verification happens exclusively on
the server** – the script can be read, changed and bypassed without any loss
of security.

Four checks make up the protection, and none of them may be dropped:

1. The challenge must be the one the server issued. It is valid for five
   minutes and exactly once.
2. The origin must be your own – this is where the phishing defence rests.
3. The hash of the domain in the device's data must match your own domain.
4. The signature must match the registered key.

Plus the signature counter: if it runs backwards, the key was presumably
copied, and the sign-in is rejected. Many devices don't count at all – only
a genuine step backwards counts as suspicious.

Passkeys need HTTPS (`localhost` excepted). On an instance without TLS the
profile doesn't show the button, instead of making a promise the browser
won't keep.

**There are no recovery codes.** A passkey cannot be written down and put in
a safe. Hence two ways back: register a second device – or an administrator
resets the second factor under *Users*. That possibility is intentional and
at the same time the weakest link in the chain; anyone who uses it should be
sure who they are talking to.

### One-time passwords from an app (TOTP)

Scan the QR code, type six digits, done. Eight recovery codes are shown
once; each is valid exactly once, in case the phone is gone. Works on any
device and in any browser – but it can be copied by hand, and thus also
entered on a fake page.

Implemented in line with RFC 6238 in plain PHP – HMAC-SHA1 and base32 come
with the language, the QR code comes from the in-house encoder. Checked
against the standard's test vectors.

Two things that are not a given:

- **The QR code is embedded, not linked.** The `otpauth` address contains
  the secret; as a URL it would end up in server logs, in the browser
  history and in the referrer. The SVG is produced in the same request.
- **A password is valid only once.** The last used counter is recorded.
  Without this lock, someone who once looked over your shoulder could sign
  in as you within the same half-minute window.

### Enforcing

Via `'totp_required'` (`off` | `admins` | `all`, also under *Settings*) the
second factor can be required. **The requirement is satisfied by either of
the two methods** – the key's name predates the passkeys and stays, so
existing configurations keep working. Whoever hasn't set one up yet is
guided to the profile after signing in instead of being locked out; the last
remaining method can then no longer be removed.

**API keys are not affected** – they are their own credential and carry no
password a second factor could protect. Anyone who wants to protect an account
particularly well therefore also reviews its key list.

## Central sign-in

Both paths are optional, default to `false` and can run in parallel with
local accounts. This section describes the principle – the step-by-step
setup including Apache configuration, SP metadata and attribute release is
in the [deployment
guide](DEPLOYMENT.md#8-shibboleth-saml-und-openid-connect) (German).

### Via the web server (Shibboleth, SAML, OpenID Connect)

The recommended path for a Shibboleth IdP. The actual sign-in is handled by
a server module – `mod_shib`, `mod_auth_mellon` or `mod_auth_openidc` – that
protects the admin area. flatlink only reads whom the server has already
authenticated. For Apache:

```apache
<Location /admin>
    AuthType shibboleth
    ShibRequestSetting requireSession 1
    Require valid-user
</Location>
```

Then, under `sso` in `config.php`, name the variable that carries the
identifier (usually `REMOTE_USER`), optionally the ones for e-mail address
and group membership, and set `login_url` to `/Shibboleth.sso/Login`.
Accounts are created automatically at first login.

> **Security note – please don't skip.** Variables the web server sets itself
> (`REMOTE_USER`, the attributes from `mod_shib`) are trustworthy. A value
> arriving as an **HTTP header** – the variable name then starts with
> `HTTP_` – is not: any client can simply make it up and impersonate any user,
> administrator included. flatlink therefore only accepts such variables if
> `trusted_proxies` contains the IP address of the reverse proxy that
> demonstrably overwrites these headers. Without that entry they are
> discarded and the sign-in fails. That is intentional.

### Via LDAP or Active Directory

Here flatlink itself asks the directory; identifier and password are entered
in the usual login form. Needs the PHP `ldap` extension.

Verification happens via a bind as the user that was found – the password is
stored nowhere and never compared with a local hash. Input is escaped before
being inserted into the search filter, so LDAP injection is not possible;
empty passwords are rejected before they could falsely pass as an
"unauthenticated bind".

Order at login: first the local password, then the directory. Local accounts
therefore keep working – important so you don't lock yourself out should the
LDAP server ever be unreachable.

With `ldap://`, be sure to enable `start_tls`, otherwise the password
crosses the network in plain text. Better yet, use `ldaps://` right away.

**When sign-in fails**, the interface deliberately does not say why –
otherwise one could work out which identifiers exist. Whoever sets up the
instance needs exactly that information though:

```bash
php tools/ldap-check.php identifier -p
```

The tool walks through extension, configuration, connection, bind, search
and password check in order and stops at the first thing that is wrong –
with a specific suggestion instead of an error number. The password is
prompted, not passed as an argument, otherwise it would sit in the process
list. The sign-in also writes its reason to the web server's error log.

### Deleting an account

Both paths – self-deletion in the profile and deletion by the administration
– clean up the same way: access tokens are revoked, pending confirmations
discarded, and the links are distributed.

| | |
| --- | --- |
| Links of a **working group** | stay with the group and merely lose their owner. That is what groups are for – a departing colleague does not take the shared poster with them. |
| Links **without a group** | would be ownerless afterwards. When the administration deletes, the administrator decides: transfer to themselves or delete as well. Anyone who deletes themselves has nobody to hand over to – there they are deleted. |

When in doubt, transfer: a printed code whose target disappears leads
nowhere, and you only notice when someone complains.

**Changing a link's owner** also works without deleting – in the edit form
of the link list, visible to administrators and accounts with `links_all`.
"Nobody" can be chosen there too: the link then belongs only to its group.
Without a group the instance refuses, otherwise nobody but the
administration would find the link any more.

### Creating accounts from the directory

With `auto_create` set to `false`, an account used to come into being only
*after* a failed sign-in attempt: the attempt created a queue entry an
administrator then approved. That works, but it makes people fail in a way
they cannot interpret – and whoever wants to prepare an account before
someone starts simply could not.

Under *Users → Create from directory* you can therefore search directly – by
name, identifier or e-mail. One click creates the account, with display name
and address from the directory; sign-in works immediately. Anyone who already
has an account appears as such and not as a button.

The search runs with the service account from `bind_dn`, i.e. with the same
rights as sign-in. Two keys control it:

| | |
| --- | --- |
| `search_filter` | **Leave empty.** The filter is then built from the attributes already configured (`uid_attr`, `name_attr`, `mail_attr`) plus `cn`, `sn`, `givenName`, `mail`. Only set it for special cases, e.g. to narrow down to a department. |
| `uid_attr` | Attribute holding the identifier. Empty = read from the `user_filter`, which is almost always right. |

That the filter grows from the configuration is the point: a directory that
keeps its display name in a custom field finds people through a hard-wired
`(cn=*%s*)` only by their identifier. Whoever set `name_attr` has already
said where the name lives; a second entry for it would be one more source of
error.

Multiple words are combined with AND, each across all attributes. "Dennis
Bormann" thus also matches an entry "Bormann, Dennis" – and two name parts
make the search narrower, not wider.

The queue remains alongside: anyone who signs in without an account still lands
there. Both lead to the same result, just from different sides.

### Groups from the directory

Both paths can adopt group memberships: with SSO from an attribute like
`isMemberOf` or `entitlement`, with LDAP from `memberOf` or via a search in
the group tree. The mapping table `group_map` maps external names to local
groups:

```php
'group_map' => [
    'urn:mace:example.org:group:marketing' => 'marketing',
    'cn=it,ou=groups,dc=example,dc=org'    => ['it', 'technik'],
],
```

If the table is empty, an external name is only taken over if a local group
of the same name exists. Names coming from the directory can never create
new groups and never invent permissions – which permissions hang on a group
is always decided by the local configuration.

### Display names

If the identifier arrives from the federation as an opaque string
(`persistent-id`, `pairwise-id`), user management is hardly usable without
real names. flatlink therefore optionally takes a display name from the
directory – with SSO via `name_var`, with LDAP via `name_attr`. The
interface then shows the name, with the technical identifier in small print
beneath. Local accounts set their display name themselves in the profile;
administrators can edit it for anyone. Search covers name, identifier and
e-mail address at once.

The role stays untouched on subsequent logins: whoever was made an
administrator here remains one. And an account managed centrally can no
longer sign in through the local password form – otherwise the central
sign-in could be bypassed with an old password.

### What centrally managed accounts can do in the profile

Anyone who signs in via LDAP or the web server has no password hash here – the
login rejects such accounts locally, and every sign-in via the directory
removes any leftover hash. The profile therefore shows no password form but
a note where the password belongs. Same for the display name: if the
directory delivers one, it wins. An e-mail address, however, can be entered
– it is only overwritten if the directory itself supplies one.

### Access, portability, deletion

Both sit in the profile, without having to go through the operator:

**Download data** delivers a JSON file with everything stored about the
account – account data, signed-in devices, the state of two-factor
authentication including passkey labels, the API access keys, groups,
permissions, limits and every short link with target, dates, click counts,
the changes made to its target and – for link-in-bio – the page itself. Not
included are means of access: the password hash, the authenticator app's
secret, the passkeys' key material, the hashes of the access keys and the
fingerprint of running sessions. They are not content, and a file containing
them would sit in the download folder afterwards. The file itself lists what
it leaves out and why. This covers Art. 15 (access) and Art. 20
(portability).

**Browser extension** shows what the instance offers – which depends on what
is set under *Settings → Browser extension*.

*Connection code* is always available. It holds the address and a freshly
created access key and sets up an already installed extension with a single
paste. The key is its own (labelled "Browser-Erweiterung" with a date) and
can be revoked separately without stopping other programs.

*Store buttons* appear as soon as addresses are entered there – for
instances whose extension is in the Chrome Web Store, the Firefox Add-ons or
the Edge Add-ons.

An instance without a store listing of its own needs nothing further: the
generic build is in the stores, asks for the address when first opened, and
the pairing code fills in address and key in one go.

**Locking an account** keeps someone out without throwing anything away:
sign-in, API keys and running sessions stop working at once, while links,
statistics and printed QR codes stay untouched. That is the difference from
deleting, and the reason both exist – a lock can be lifted, a deleted account
cannot be undone. For someone leaving the organisation, locking is almost
always the right call: the short links on their notices should keep working.

### Directory sync

Where sign-in runs through LDAP, the directory governs *access*, not
*inventory*: whoever leaves can no longer sign in – but their account and
their API keys remain. `php tools/flatlink ldap:abgleich` closes that gap. It
fetches every login name from the directory and locks the accounts that are no
longer there.

Four safeguards are built in, and they are the actual point:

* **Without `--anwenden` nothing happens.** The dry run only shows what it
  would do.
* **If the directory does not answer, it stops.** A timeout is no reason to
  shut a building out.
* **If more than 20 per cent of accounts are missing, it stops as well.** That
  usually means the search base is wrong, not that the staff was dismissed.
  `--grenze=` raises the bar when it really is that many.
* **It leaves local accounts alone**, and it only lifts locks it set itself.
* **It never locks administrators**, it lists them for review instead. Where a
  university's administrators are LDAP accounts – the normal case – a single
  run could shut all of them out at once, and the only way back would be file
  access, which shared hosting does not offer. `--auch-admins` includes them
  for anyone who really wants that.
* **It pages through the directory** and stops when the server truncated its
  answer. Active Directory returns at most 1000 entries by default, OpenLDAP
  usually 500 – and not as an error but as a subset. Mistaking that for the
  whole directory locks everyone beyond the cut.

A cron entry is enough for the regular run:

```
17 3 * * * cd /var/www/flatlink && php tools/flatlink ldap:abgleich --anwenden
```

**Delete account** removes the account and all links that hang only on it,
including click counters. Links **with a group assignment remain** and only
lose their owner – they belong to the group, others keep working with them,
and printed QR codes on them should not lead nowhere because one person
leaves. The password is asked first (with central sign-in: the own
identifier typed out); the last administrator cannot remove themselves.

On an instance with centrally managed accounts, the delete button is
misleading, because the directory recreates the account at the next sign-in.
Set `'self_delete' => false` there – the export is unaffected.

### Two privacy notes

**Google Safe Browsing** is **off** by default. Whoever enables it sends the
target URL of every newly created link to Google. For a public instance that
is effective protection against phishing abuse, for an internal one it is
usually unnecessary. Anyone who switches it on should state it in their privacy
policy.

**The web server keeps logging.** flatlink stores no IP addresses; the
access logs of Apache or nginx usually do. Whoever takes that promise
seriously shortens or disables them there.
