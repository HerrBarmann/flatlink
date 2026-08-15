# Accounts and sign-in

Two-factor sign-in with passkeys or one-time passwords, central sign-in via
LDAP or the web server, and what accounts decide about their own data. Back
to the [README](../README.en.md). – 🇩🇪 [Deutsche Fassung](konten.md).

## Two-factor sign-in

Why this is in here: whoever takes over an account can change the target of a
short link – including one whose code has long been printed on a sign. The
damage then hits not the account holder but everyone who scans. For a service
that hands out printed codes, a password alone is a thin door.

Two methods are available, both set up in the profile. They don't exclude
each other – whoever registers both gets the choice at sign-in.

### Passkeys (WebAuthn)

Fingerprint, face or device PIN, stored on the phone, the computer or a
security key. Up to ten devices per account.

The difference from a one-time password is not convenience but the **binding
to the domain**. A six-digit code can be typed into a fake sign-in page and
passed on within seconds; a passkey isn't even handed over by the browser
there, because the origin doesn't match. That is the real gain.

Implemented in [`inc/webauthn.php`](../inc/webauthn.php) – plain PHP, like
everything here: the CBOR reader is written in-house, the signature is
checked by the OpenSSL that PHP ships anyway. Supported are ES256 (which
phones and security keys practically always deliver) and RS256 (older
Windows Hello installations). `assets/passkey.js` only repacks between JSON
and the browser's binary interface; **verification happens exclusively on
the server** – the script can be read, changed and bypassed without any loss
of security.

Four checks make up the protection, and none of them may be dropped:

1. The challenge must be the one the server issued. It is valid for five
   minutes and exactly once.
2. The origin must be your own – this is where the phishing defense hangs.
3. The hash of the domain in the device's data must match your own domain.
4. The signature must match the registered key.

Plus the signature counter: if it runs backwards, the key was presumably
copied, and the sign-in is rejected. Many devices don't count at all – only a
genuine step backwards counts as suspicious.

Passkeys need HTTPS (`localhost` excepted). On an instance without TLS the
profile doesn't show the button, instead of making a promise the browser
won't keep.

**There are no recovery codes.** A passkey cannot be written down and put in
a safe. Hence two ways back: register a second device – or an administrator
resets the second factor under *Users*. That possibility is intentional and
at the same time the weakest link of the chain; whoever uses it should be
sure who they are talking to.

### One-time passwords from an app (TOTP)

Scan the QR code, type six digits, done. Eight recovery codes are shown once;
each is valid exactly once, for the case that the phone is gone. Works on any
device and in any browser – but it can be copied by hand, and thus also
entered on a fake page.

Implemented after RFC 6238 in plain PHP – HMAC-SHA1 and base32 come with the
language, the QR code comes from the in-house encoder. Checked against the
standard's test vectors.

Two things that are not a given:

- **The QR code is embedded, not linked.** The `otpauth` address contains the
  secret; as a URL it would end up in server logs, in the browser history and
  in the referrer. The SVG is produced in the same request.
- **A password is valid only once.** The last used counter is recorded.
  Without this lock, someone who once looked over your shoulder could sign in
  themselves within the same half-minute window.

### Enforcing

Via `'totp_required'` (`off` | `admins` | `all`, also under *Settings*) the
second factor can be required. **The requirement is satisfied by either of
the two methods** – the key's name predates the passkeys and stays, so
existing configurations keep working. Whoever hasn't set one up yet is guided
to the profile after signing in instead of being locked out; the last
remaining method can then no longer be removed.

**API keys are not affected** – they are their own credential and carry no
password a second factor could protect. Whoever wants to protect an account
particularly well therefore also reviews its key list.

## Central sign-in

Both paths are optional, default to `false` and can run in parallel with
local accounts. This section describes the principle – the step-by-step setup
including Apache configuration, SP metadata and attribute release is in the
[deployment guide](../DEPLOYMENT.md#8-shibboleth-saml-und-openid-connect)
(German).

### Via the web server (Shibboleth, SAML, OpenID Connect)

The recommended path for a Shibboleth IdP. The actual sign-in is handled by a
server module – `mod_shib`, `mod_auth_mellon` or `mod_auth_openidc` – that
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
> `HTTP_` – is not: any client can invent it freely and impersonate any user,
> administrator included. flatlink therefore only accepts such variables if
> `trusted_proxies` contains the IP address of the reverse proxy that
> demonstrably overwrites these headers. Without that entry they are
> discarded and the sign-in fails. That is intentional.

### Via LDAP or Active Directory

Here flatlink itself asks the directory; identifier and password are entered
in the usual login form. Needs the PHP `ldap` extension.

Verification happens via a bind as the found user – the password is stored
nowhere and never compared with a local hash. Input is escaped before being
inserted into the search filter, so LDAP injection is not possible; empty
passwords are rejected before they could falsely pass as an "unauthenticated
bind".

Order at login: first the local password, then the directory. Local accounts
therefore keep working – important so you don't lock yourself out when the
LDAP server is unreachable for once.

With `ldap://`, be sure to enable `start_tls`, otherwise the password crosses
the network in plain text. Better yet, use `ldaps://` right away.

### Groups from the directory

Both paths can take over group memberships: with SSO from an attribute like
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
of the same name exists. Names coming from the directory can never create new
groups and never invent permissions – which permissions hang on a group is
always decided by the local configuration.

### Display names

If the identifier arrives from the federation as an opaque string
(`persistent-id`, `pairwise-id`), user management is hardly usable without
real names. flatlink therefore optionally takes a display name from the
directory – with SSO via `name_var`, with LDAP via `name_attr`. The interface
then shows the name, with the technical identifier in small print beneath.
Local accounts set their display name themselves in the profile;
administrators can maintain it everywhere. Search covers name, identifier
and e-mail address at once.

The role stays untouched on renewed login: whoever was made an administrator
here remains one. And an account managed centrally can no longer sign in
through the local password form – otherwise the central sign-in could be
bypassed with an old password.

### What centrally managed accounts can do in the profile

Whoever signs in via LDAP or the web server has no password hash here – the
login rejects such accounts locally, and every sign-in via the directory
removes any leftover hash. The profile therefore shows no password form but a
note where the password belongs. Same for the display name: if the directory
delivers one, it wins. An e-mail address, however, can be entered – it is
only overwritten if the directory itself supplies one.

### Access, portability, deletion

Both sit in the profile, with no detour via the operator:

**Download data** delivers a JSON file with everything stored about the
account – account data, signed-in devices, the state of two-factor
authentication including passkey labels, the API access keys, groups,
permissions, limits and every short link with target, dates, click counts,
the changes made to its target and – for link-in-bio – the page itself.
Not included are means of access: the password hash, the authenticator
app's secret, the passkeys' key material, the hashes of the access keys and
the fingerprint of running sessions. They are not content, and a file
containing them would sit in the download folder afterwards. The file itself
lists what it leaves out and why. This covers Art. 15 (access) and Art. 20
(portability).

**Delete account** removes the account and all links that hang only on it,
including click counters. Links **with a group assignment remain** and only
lose their owner – they belong to the group, others keep working with them,
and printed QR codes on them should not point into the void because one
person leaves. The password is asked first (with central sign-in: the own
identifier typed out); the last administrator cannot remove themselves.

On an instance with centrally managed accounts, the delete button is
misleading, because the directory recreates the account at the next sign-in.
Set `'self_delete' => false` there – the export is unaffected.

### Two privacy notes

**Google Safe Browsing** is **off** by default. Whoever enables it sends the
target URL of every newly created link to Google. For a public instance that
is an effective protection against phishing abuse, for an internal one it is
usually unnecessary. Whoever switches it on should state it in their privacy
policy.

**The web server keeps logging.** flatlink stores no IP addresses; the access
logs of Apache or nginx usually do. Whoever takes the claim seriously
shortens or disables them there.
