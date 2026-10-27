# Quire Server

Many Quire sites on one machine, each with its own Quire version, database and domains, behind one front door that holds their certificates. Add a site with one command; nothing else on the server restarts.

> **Status: early.** This is the foundation: the front door, the agent and its commands. Still to come: adopting a site that's already running and a one-line installer (next), updates, the web console with passkeys, backups off the server, and one-click images. Until the installer exists, set it up by hand as below.

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

A site is an ordinary Quire project in `/srv/quire/sites/<site>` (from `quire/skeleton`), with two files the server writes next to its own: `prod.env` (its settings and secrets, readable by root only) and `compose.server.yaml` (how it joins the server: no published ports, the shared PostgreSQL, its domains, hardening). Its own files are never changed.

## Setting it up (by hand, for now)

On a Linux machine with Docker Engine 29 and Compose 2.24.4 or later, as root:

```bash
mkdir -p /srv/quire/sites /srv/quire/backups /srv/quire/server/domains
chmod 700 /srv/quire/sites /srv/quire/backups
cp -r server/. /srv/quire/server/          # this folder
install -m 755 /srv/quire/server/bin/quire-server /usr/local/bin/quire-server

cd /srv/quire/server
cp server.env.dist server.env && chmod 600 server.env
sed -i "s/^QUIRE_SERVER_APP_SECRET=.*/QUIRE_SERVER_APP_SECRET=$(openssl rand -hex 32)/; s/^QUIRE_SERVER_POSTGRES_PASSWORD=.*/QUIRE_SERVER_POSTGRES_PASSWORD=$(openssl rand -hex 24)/; s/^QUIRE_SERVER_DATABASE_PASSWORD=.*/QUIRE_SERVER_DATABASE_PASSWORD=$(openssl rand -hex 24)/" server.env
# set QUIRE_SERVER_ACME_EMAIL and, to send email from sites, QUIRE_SERVER_MAILER_DSN

quire-server up
```

Ports 80 and 443 must be free: the front door takes them.

## Commands

```bash
quire-server site:add jane --domain=jane.example --domain=www.jane.example --admin-email=jane@example.com --title="Jane's notes" [--invite]
quire-server site:list
quire-server site:remove jane          # asks you to type its name; backs it up first
quire-server operations [--site=jane]  # what was done, by whom, and how it ended
quire-server operations <id>           # an operation's log
quire-server up | down | ps | logs     # the server itself
```

- **Site names** are 2 to 30 lowercase letters, digits and single dashes (`jane`, `club-news`). They name the site's folder, its containers (`jane-php-1`) and its database (`site_jane`). Its domains are separate, and the first one is its main address.
- **Adding** gets the skeleton with Composer (without running any of its code), creates the site's database, checks and builds the site, starts it, installs it, and opens its domains. The first admin gets a password, shown once, or with `--invite` an email to choose one (needs an email server, and Quire 0.1.1 or later). If anything fails, everything done is undone.
- **Removing** backs the site up to `/srv/quire/backups/<site>/final-<time>.tar`, closes its domains, removes its containers, volumes and database, and moves its folder to `/srv/quire/sites/.removed/`. A site that can't start can't be backed up: `--skip-backup` removes it anyway, and its data is gone.
- Point each domain's DNS at the server. Its certificate comes at the first visit (from Let's Encrypt).

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
