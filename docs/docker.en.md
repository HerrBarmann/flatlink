# flatlink in a container

One image, one volume, done. Back to the [README](../README.md). – 🇩🇪
[Deutsche Fassung](docker.md)

flatlink does not need a container: copying files onto a web space works
just as well, and that is what it was built for. But if you run everything
in containers anyway, here is an image that fits right in – environment
variables instead of a configuration file, one volume for the data, and a
health endpoint for your watchdog.

## In two minutes

```bash
docker run -d --name flatlink -p 8080:8080 \
  -e FLATLINK_BASE_URL="http://localhost:8080" \
  -v flatlink-data:/var/lib/flatlink \
  ghcr.io/herrbarmann/flatlink:latest
```

Then open `http://localhost:8080/admin/` – the first visit creates the
administrator account. The `docker-compose.yml` in the project does the
same: `docker compose up -d` (or `docker-compose up -d`, depending on how
Compose is installed for you).

## Environment variables

Only what you pass is set – everything else keeps its default from
`inc/config.example.php`. The full list of options lives there; these are
the ones you can set through the environment.

| Variable | Meaning |
| --- | --- |
| `FLATLINK_BASE_URL` | Address of the instance, **no trailing slash**. See below. |
| `FLATLINK_SITE_NAME` | Name in header, title and mails |
| `FLATLINK_LANGUAGE` | `de` or `en` |
| `FLATLINK_DATA_DIR` | Where data lives, default `/var/lib/flatlink` |
| `FLATLINK_PUBLIC_MODE` | `on`, `prefix` or `off` – may anyone shorten? |
| `FLATLINK_REGISTRATION` | `on` or `off` – self-registration |
| `FLATLINK_TRUSTED_PROXIES` | Addresses of upstream proxies, comma-separated |
| `FLATLINK_SMTP_HOST` … | Mail: `_PORT`, `_USER`, `_PASS`, plus `FLATLINK_MAIL_FROM` |
| `FLATLINK_LDAP_URI` … | Directory: `_BASE_DN`, `_USER_FILTER`, `_BIND_DN`, `_BIND_PASS`, `_AUTO_CREATE` |
| `FLATLINK_CLICK_DIMS` | `false` switches off origin, device and language sums |
| `FLATLINK_DEMO_MODE` | `true` turns the instance into a self-resetting playground |
| `FLATLINK_API_RATE_LIMIT` | Requests per hour and key |

An empty value counts as "not set". Booleans accept `1`, `true`, `yes` and
`on`.

**`FLATLINK_BASE_URL` is not a matter of taste.** Without it flatlink
guesses the address from the request's `Host` header – which is user input.
Someone triggering a password mail for a foreign account could point the
link in it at their own domain and capture the token. flatlink therefore
sends **no** mails containing links while the address is missing; the
container writes a note to the log on startup. Behind a proxy this is the
address seen from **outside**, not `http://flatlink:80`.

### Prefer a written-out configuration?

Then mount your own – it takes precedence, and the variables stay unused:

```yaml
volumes:
  - ./config.php:/var/www/html/inc/config.php:ro
```

Neither way is second best. The variables are the shortcut for the common
case, the file is the place for everything beyond – Shibboleth, webhooks or
several domains do not sensibly fit on a single line.

## Data

Everything mutable lives in **one** directory: `/var/lib/flatlink`. Links,
accounts, click counters, logos, the SQLite file. That is the only thing
that has to survive a new image – and therefore the only thing to back up:

```bash
docker run --rm -v flatlink-data:/data -v "$PWD":/here alpine \
  tar czf /here/flatlink-backup.tar.gz -C /data .
```

For a versionable backup there is the text export – a day of operation is a
few changed lines instead of a new binary file. The `tools/` folder is
deliberately **not** in the image for that (command-line tools have no
business being on the web), so mount it:

```yaml
volumes:
  - ./tools:/var/www/html/tools:ro
```

```bash
docker exec flatlink php /var/www/html/tools/backup-export.php /var/lib/flatlink/export
```

## Behind a proxy

The common case: Traefik, Caddy or nginx terminate TLS and forward the
request. Two things need setting then:

```yaml
environment:
  FLATLINK_BASE_URL: "https://s.example.org"
  FLATLINK_TRUSTED_PROXIES: "172.16.0.0/12"
```

Without the second entry flatlink sees the proxy's address for **all**
visitors. Rate limit and sign-in lock would then apply to everyone
collectively – a single user could block the service for the rest.

## Your own look

`assets/custom.css` is loaded after the standard stylesheet and overrides
it. In a container that means: mount it.

```yaml
volumes:
  - ./custom.css:/var/www/html/assets/custom.css:ro
```

The [customisation guide](CUSTOMIZATION.en.md) covers what goes in it –
colours, logo, type, all update-safe through variables.

## What the container does not need

- **No cron.** Cleanup, demo reset and expiry all ride on page loads.
- **No database service.** SQLite lives in the volume.
- **No second container** for the web server: Apache and PHP are in the
  image, so the bundled `.htaccess` applies unchanged – it rewrites short
  codes and blocks what should stay blocked.

## Health

`GET /api/health` needs no key and answers `{"status":"pass"}` – the
built-in `HEALTHCHECK` asks the same thing every 30 seconds. `docker ps`
shows the result as `healthy`; an external watchdog queries the same
address.

## Kubernetes

Ready-made manifests live in
[`deploy/kubernetes/flatlink.yaml`](../deploy/kubernetes/flatlink.yaml) –
namespace, ConfigMap, Secret, PVC, Deployment, Service and Ingress in one
file. What you have to adjust is the address (in the ConfigMap **and** the
Ingress) and possibly the `storageClassName`:

```bash
kubectl apply -f deploy/kubernetes/flatlink.yaml
```

**One pod, no more.** The inventory lives in a SQLite file on a volume that
exactly one pod may write to. Hence `replicas: 1` and `strategy: Recreate`
in the manifests – with the default `RollingUpdate`, two pods would briefly
share the same file during a rollout, and that is precisely where SQLite is
vulnerable. This is not a stopgap but the design: flatlink is meant for
instances that fit on a web host. If you need to scale out, you need
something else.

### What one pod carries

Being limited to one pod sounds like a ceiling, but in practice it is not.
Measured on a container with **one** CPU and 512 MB:

| | |
| --- | --- |
| Redirects | **2,300 per second** – that is the write path; every request counts a click |
| With 20,000 links stored | unchanged at 2,400 per second |
| Read only (`/api/health`) | 2,900 per second |
| Restart | reachable again after **1.5 seconds** |

2,300 per second is over eight million redirects an hour. A service with a
million clicks a month averages 0.4 per second. The instance is not the
bottleneck, and will not be for a long while.

If you do hit the ceiling, give the pod more CPU – that works, because
Apache runs several processes. Scaling out is not possible: it would mean a
second writer on the same SQLite file. That would require a different
storage layer, and it would cost this project its promise – no database
server, and a backup is a folder.

Since the question is therefore not throughput but **availability**, the
manifests include a `PodDisruptionBudget`: without it a node drain would
simply take the instance with it; with it, the cluster waits for the
replacement.

**No root.** The image listens on port 8080 and runs as `www-data` in group
0. That satisfies the `restricted` pod security standard (`runAsNonRoot`,
   `allowPrivilegeEscalation: false`, all capabilities dropped) and it also
   runs where the cluster assigns an arbitrary user id of its own –
   OpenShift, for instance. The volume is handed over via `fsGroup: 0`;
   there is no `chown` at startup any more.

**The probes hang off `/api/health`.** On the very first start the instance
creates its database and honestly reports 503 until it is done – that is
what the `startupProbe` is for.

**Credentials** belong in the Secret, not in the ConfigMap:

```bash
kubectl -n flatlink create secret generic flatlink-secrets \
  --from-literal=FLATLINK_SMTP_PASS=… \
  --from-literal=FLATLINK_LDAP_BIND_PASS=…
```

## Updating

```bash
docker compose pull && docker compose up -d
```

The volume stays, the application is replaced. No migration step is needed –
the data format grows with it and reads older data unchanged.

Pinning a version is easier to live with than `latest` if an unintended jump
would come at a bad time: `ghcr.io/herrbarmann/flatlink:3.3` stays on the
3.3 releases, `:3.3.0` on exactly that one.

## Building it yourself

```bash
git clone https://github.com/HerrBarmann/flatlink.git
cd flatlink && docker build -t flatlink .
```

Only what a running instance needs goes into the image: `tests/`, `tools/`,
`extension/` and the screenshots stay out (see `.dockerignore`). The web
server does not own a single file of the application – it may write to the
data directory and nowhere else. A break-in through PHP therefore cannot
rewrite the application.
