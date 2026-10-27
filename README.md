# Quire Server

Many Quire sites on one machine, each with its own Quire version, database and domains, behind one front door that holds their certificates. Add a site with one command; nothing else on the server restarts.

> **Status: early.** The front door, the agent and its commands, the installer, adopting sites installed before, and updating sites. Still to come: Git mode, signed releases and the server's own updates, the web console with passkeys, backups off the server, and one-click images. Until the first release of Quire Server, install it from a checkout (below).

## How it works

```
internet ── 80/443 ──► front      Caddy: a certificate per domain, at its first visit; each request to the
                                   site whose domain it names. Static: nothing to reload when sites change.
                        agent      The only part that acts on Docker, through docker-proxy. Adds and removes
                                   sites, keeps the front door's list of domains. No network listener.
                        docker-proxy  The Docker API, limited to what the agent needs.
                        database   PostgreSQL: the server's registry, and a database per site.
                        sites      /srv/quire/sites/<site>: a Quire project each (from quire/skeleton), its
                                   own containers, joined to the front door and PostgreSQL.
```

A site is an ordinary Quire project in `/srv/quire/sites/<site>` (from `quire/skeleton`), with two files the server writes next to its own: `prod.env` (its settings and secrets, readable by root only) and `compose.server.yaml` (how it joins the server: no published ports, the shared PostgreSQL, its domains, hardening). Its own files are never changed (an adopted site's `prod.env`, which it had, gets two lines changed: see below).

## Installing it

On a Linux machine (Ubuntu or Debian for Docker to be installed for you; any distribution with Docker Engine 29 and Compose 2.24.4 or later), as root:

```bash
git clone https://github.com/quirecms/framework.git /root/quire
sh /root/quire/server/install.sh --source=/root/quire/server --email=you@example.com
```

Once Quire Server is released, `--source` won't be needed: the installer downloads its release.

The installer:

1. **Checks the machine,** and installs Docker from Docker's own repository if it's missing (it asks first, and checks Docker's signing key).
2. **Puts the server in `/srv/quire/server`,** its settings and newly generated secrets in `server.env` (readable by root only), and the `quire-server` command in `/usr/local/bin`.
3. **Starts PostgreSQL, the docker-proxy and the agent.**
4. **Adopts the Quire sites already running from `/srv/quire/sites`** (it asks first; see below).
5. **Starts the front door,** once nothing else holds ports 80 and 443. If another web server holds them, it says which and stops there. It never stops anything it didn't start.
6. **Turns on automatic security updates** for the operating system (Ubuntu and Debian; `--no-os-updates` leaves them as they are).

Running it again is safe: it keeps the secrets it made and finishes what's left, e.g. after you've freed ports 80 and 443. `--yes` answers yes to its questions; `--help` lists its options.

**By hand,** for developers: copy this folder to `/srv/quire/server`, create `server.env` from `server.env.dist` (root only, with long random secrets), install `bin/quire-server` in `/usr/local/bin`, and run `quire-server up`.

### A site installed before the server

A site installed from the skeleton on its own (as in [the production docs](https://github.com/quirecms/framework/blob/main/docs/docker.md#production)) holds ports 80 and 443 itself. To run it behind the server, its folder must be in `/srv/quire/sites/<name>` (stop it, move the folder, start it again). The installer then finds it and offers to adopt it, or later: `quire-server site:adopt <name>`. Adopting it:

- **backs it up first,** to `/srv/quire/backups/<name>/before-server-<time>.tar`;
- **changes two lines of its `prod.env`:** `COMPOSE_FILE` gets `compose.server.yaml`, so that `docker compose --env-file prod.env …` keeps working as before, and `COMPOSE_PROJECT_NAME` is set. The file as it was is kept as `prod.env.before-server`. Its other files stay as they are;
- **adds `compose.server.yaml`:** no published ports, its domains on the front door's network, hardening;
- **keeps its own PostgreSQL** and its data where they are;
- **builds it while it keeps running,** then restarts it behind the front door. It's offline for a few seconds, and its certificate comes from the front door at the next visit.

If anything fails, it's put back as it was, ports included. Its domains are those of its `SERVER_NAME`, or pass them: `--domain=jane.example --domain=www.jane.example`.

## Commands

```bash
quire-server site:add jane --domain=jane.example --domain=www.jane.example --admin-email=jane@example.com --title="Jane's notes" [--invite]
quire-server site:adopt demo            # a site installed before the server (see above)
quire-server site:list
quire-server site:update jane [--to=0.2.0] [--dry-run]   # see "Updating a site"
quire-server site:undo-update jane      # its last update, within a week
quire-server site:audit jane            # known vulnerabilities in its packages
quire-server site:remove jane          # asks you to type its name; backs it up first
quire-server operations [--site=jane]  # what was done, by whom, and how it ended
quire-server operations <id>           # an operation's log
quire-server up | down | ps | logs     # the server itself
```

- **Site names** are 2 to 30 lowercase letters, digits and single dashes (`jane`, `club-news`). They name the site's folder, its containers (`jane-php-1`) and its database (`site_jane`). Its domains are separate, and the first one is its main address.
- **Adding** gets the skeleton with Composer (without running any of its code), creates the site's database, checks and builds the site, starts it, installs it, and opens its domains. The first admin gets a password, shown once, or with `--invite` an email to choose one (needs an email server, and Quire 0.1.1 or later). If anything fails, everything done is undone.
- **Sites that keep their own PostgreSQL** (adopted ones) have it removed with them; the server's PostgreSQL holds the others' databases.
- **Removing** backs the site up to `/srv/quire/backups/<site>/final-<time>.tar`, closes its domains, removes its containers, volumes and database, and moves its folder to `/srv/quire/sites/.removed/`. A site that can't start can't be backed up: `--skip-backup` removes it anyway, and its data is gone.
- Point each domain's DNS at the server. Its certificate comes at the first visit (from Let's Encrypt).

## Updating a site

`quire-server site:update jane` first shows what would change, and changes nothing until you agree:

- **Its packages:** Quire, its extensions and everything else, to the newest versions its `composer.json` allows. Symfony stays on its minor version, as Symfony Flex would keep it. `--to=0.2.0` moves to another Quire release, e.g. a new minor version.
- **Known vulnerabilities** the update fixes, and any that remain (from Packagist's advisories). `quire-server site:audit jane` lists a site's vulnerabilities at any time.
- **The files the site got from the skeleton** (`Dockerfile`, Compose files, FrankenPHP's configuration), when Quire's version changes. A file the site never edited follows the new version. A file it edited that the new version changes too stops the update: merge it by hand, or keep the site's own with `--keep-edited-files`.

Then the update:

1. **Is worked out without running any package's code.** On a copy of the site's `composer.json` and `composer.lock`, Composer only resolves versions: no scripts, no plugins. The packages are installed in the site's image build, in Docker.
2. **Is built while the site keeps running** on its current image. A failed build changes nothing.
3. **Backs the site up,** to `/srv/quire/backups/<site>/update-<time>.tar`.
4. **Starts the new version.** The site is offline for a few seconds; the front door shows visitors a "Back in a moment" page (503, `Retry-After`), so search engines come back.
5. **Checks it:** its health check, home page and sign-in page.
6. **Rolls back by itself if anything fails:** the files and image it had, and its backup restored if the new version's migrations ran (or that can't be known, for sites older than Quire 0.1.1). If even that fails, the site is marked failed, and the operation's log says where its backup is.

`quire-server site:undo-update jane` undoes the last update within a week. If that update ran no migrations, only the code goes back. Otherwise its backup from before the update is restored too, and changes made since are lost; you're asked first.

A site's versions are recorded in its folder's `composer.lock` (taken from its image when it's added or adopted). Its builds install exactly those versions; only updates change them.

## Security

The agent can act on Docker, and that's root on the machine, so the server is built around keeping that power narrow:

- **Every site's configuration is checked before it's built or started.** The check runs on all its Compose files merged, as Docker would run them. It refuses:
  - **Privileges:** privileged containers, added capabilities, host devices;
  - **The host itself:** the host's network, process, IPC or user namespaces, other runtimes, mounts of host paths, other projects' volumes or networks, files from outside the site's folder;
  - **Exposure:** published ports;
  - **Names that belong to others:** another site's domain or the database's name on a shared network, fixed container names;
  - **New settings:** any Compose setting it doesn't know yet, until it's reviewed (e.g. `use_api_socket`, which hands a container the Docker socket).

  Docker's own authorization plugins have been bypassed (CVE-2024-41110, CVE-2026-34040), so this check is the boundary.
- **Sites are isolated from the server and from each other:**
  - no Docker access, and no route to the agent or the docker-proxy;
  - a PostgreSQL role that opens only the site's own database;
  - no Linux capabilities, `no-new-privileges`, memory and process limits.
- **The front door serves only registered domains,** and gets certificates only for them. A request's Host header must also match the name its connection was encrypted for, so a forged header can't reach another container.
- **Secrets:**
  - files readable by root only, never in images or command lines;
  - database passwords sent to PostgreSQL only as SCRAM verifiers;
  - operation logs scrubbed of them.
- **Nothing is deleted without a backup,** and every operation is recorded.

What this doesn't protect against: a kernel exploit from inside a container (containers share the machine's kernel), a bug in Docker itself, and anyone who is root on the machine.
