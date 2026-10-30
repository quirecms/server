# Quire Server

Many Quire sites on one machine, each with its own Quire version, database and domains, behind one front door that holds their certificates. Add a site with one command; nothing else on the server restarts.

> **Status: early.** The front door, the agent and its commands, the installer, adopting sites installed before, updating sites, sites deployed from a Git repository, signed releases and the server's own updates, and backups off the server. Still to come: the web console with passkeys, and one-click images.

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
curl -fsSL https://github.com/quirecms/server/releases/latest/download/install.sh -o install.sh
sh install.sh --email=you@example.com
```

To check the installer itself first (with [cosign](https://docs.sigstore.dev/cosign/system_config/installation/)):

```bash
version=0.3.0   # the release you install (its tag, without the "v")
curl -fsSLO https://github.com/quirecms/server/releases/download/v$version/install.sh
curl -fsSLO https://github.com/quirecms/server/releases/download/v$version/install.sh.sigstore.json
cosign verify-blob install.sh --bundle install.sh.sigstore.json \
  --certificate-identity "https://github.com/quirecms/server/.github/workflows/release.yaml@refs/tags/v$version" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

The identity names that version's tag: a signature of another release (an older one, with its known flaws) doesn't check out.

From a checkout instead (a version not released yet, or your own changes): `git clone https://github.com/quirecms/server.git && sh server/install.sh --source=server`. The agent is then built on the machine.

The installer:

1. **Checks the machine,** and installs Docker from Docker's own repository if it's missing (it asks first, and checks Docker's signing key).
2. **Gets the release and checks it before running anything:** its signature (Sigstore: made by this repository's release workflow, for that very version), its archive's checksum, and its image's signature. Anything that doesn't check out stops the installation.
3. **Puts the server in `/srv/quire/server`,** its settings and newly generated secrets in `server.env` (readable by root only), and the `quire-server` command in `/usr/local/bin`.
4. **Starts PostgreSQL, the docker-proxy and the agent,** from images pinned by digest: the release's agent image (`ghcr.io/quirecms/server`), never built on the machine.
5. **Adopts the Quire sites already running from `/srv/quire/sites`** (it asks first; see below).
6. **Starts the front door,** once nothing else holds ports 80 and 443. If another web server holds them, it says which and stops there. It never stops anything it didn't start. When the machine's firewall is ufw and it's on, 80/tcp, 443/tcp and 443/udp are allowed in it.
7. **Turns on automatic security updates** for the operating system (Ubuntu and Debian; `--no-os-updates` leaves them as they are).

Running it again is safe: it keeps the secrets it made and finishes what's left, e.g. after you've freed ports 80 and 443. `--yes` answers yes to its questions; `--help` lists its options.

**By hand,** for developers: copy this repository to `/srv/quire/server`, create `server.env` from `server.env.dist` (root only, with long random secrets, and `COMPOSE_FILE=compose.yaml:compose.build.yaml` to build the agent), install `bin/quire-server` in `/usr/local/bin`, and run `quire-server up`.

### Releases

Each release ([GitHub releases](https://github.com/quirecms/server/releases)) has:

- **the agent's image,** `ghcr.io/quirecms/server:<version>`, for x86_64 and arm64, with its SBOM and build provenance, signed with Sigstore by the release workflow (keyless: no key to steal; the signature is in a public transparency log);
- **`release.env`,** naming the image by digest and the files by checksum, signed the same way, with `release.env.sigstore.json`;
- **the server's files** (`quire-server-<version>.tar.gz`) and **`install.sh`** (installing that version, with its own signature).

Every image Quire Server runs, and every GitHub Action that builds it, is pinned by digest or commit; Dependabot proposes updates. `quire-server version` says which release a server runs.

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
quire-server version                    # which release this server runs
quire-server update [--to=0.2.0] [--check] [--undo]   # the server itself, see "Updating the server"
quire-server database:upgrade [--check] [--to=19] [--undo] [--clean]   # its PostgreSQL, see "Upgrading PostgreSQL"
quire-server site:update jane [--to=0.2.0] [--dry-run]   # see "Updating a site"
quire-server site:update --all [--automatic]             # every site; --automatic: what the night does, now
quire-server site:updates jane [security|all|off]        # which updates it gets by itself, every night
quire-server site:undo jane             # its last update or deploy, within a week
quire-server site:audit jane            # known vulnerabilities in its packages
quire-server site:key blog              # Git mode: a site's deploy key (see "Sites from a Git repository")
quire-server site:deploy blog [--commit=…]
quire-server site:poll blog on|off      # Git mode: deploy new commits by itself
quire-server site:remove jane          # asks you to type its name; backs it up first
quire-server backups:setup              # backups off the server: where, and their password (see "Backups")
quire-server backups jane | backups server      # what's stored off the server
quire-server backups:now [jane]         # back up now (everything, or one site)
quire-server backups:restore jane [--snapshot=…]   # put a site back as a backup has it
quire-server backups:restore jane --as=copy --domain=…   # a new site from it (--from-server: another server's)
quire-server backups:restore-server --from-server=old   # every site of another server, from its backups
quire-server backups:check [--read=5]   # check the stored backups
quire-server operations [--site=jane]  # what was done, by whom, and how it ended
quire-server operations <id>           # an operation's log
quire-server up | down | ps | logs     # the server itself ("down -v" is refused: it would delete every site's database;
                                       # up and down wait for no operation to run, or --force)
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
7. **If the machine stops in the middle** (a crash, a power cut), the agent puts the site back by itself when it starts again, as a failed update is rolled back: the files and image it had, and its backup when one was taken (`quire-server operations` shows it as `site.recover`). The same goes for deploys and rebuilds.

`quire-server site:undo jane` undoes the last update within a week. If that update ran no migrations, only the code goes back. Otherwise its backup from before the update is restored too, and changes made since are lost; you're asked first.

### Automatic updates

Every night (from 03:00 UTC; `QUIRE_SERVER_UPDATE_HOUR` in `server.env` sets the hour, or `off` for none on the server), each site gets the updates its policy says, made as above: built beside, backed up, checked, rolled back by itself.

- **`security`** (the default): when a package the site runs has a known vulnerability, that package (and what it needs) is updated, if an update fixes it. Nothing else changes.
- **`all`**: everything its `composer.json` allows.
- **`off`**: none; `site:update` by hand.

**A newer runtime is built on too:** a site whose Dockerfile builds on Quire's runtime images (sites from Quire's skeleton) is built again, its code as it is, when they have a newer signed build than the one it was built on (they're rebuilt every week with PHP's and Debian's security fixes), Git mode sites included, with the same backup, check and rollback (`site.rebuild` in the operations).

`quire-server site:updates jane all` sets it; `quire-server operations` shows what was done, by `automatic`. Some updates always wait for you: one that changes files of the skeleton the site edited, and one that changes Quire's version without Quire's signed release feed vouching for it. Git mode sites get none: their updates are made in their repository.

`quire-server site:update --all` updates every site now, one after the other (asking once); `--all --automatic` does what tonight would.

### Quire's release feed

Each Quire release publishes a feed, signed by Quire's release workflow (Sigstore): every release, its security fixes, what to do when updating, and what it requires. Before an update changes a site's Quire, the server checks the feed's signature and reads it: the plan shows the security fixes and upgrade notes, and a release this server can't run is refused (one that keeps a newer server contract: update the server first; one that needs a newer PostgreSQL than the server's, for sites on the server's PostgreSQL).

A site's versions are recorded in its folder's `composer.lock` (taken from its image when it's added or adopted). Its builds install exactly those versions; only updates change them.

## Updating the server

`quire-server update` moves the server itself (its agent, front door, docker-proxy and PostgreSQL's settings) to the latest release; `--to=0.2.0` to another one, `--check` only says whether there's a newer one. It runs in the **updater**, a container of the release that runs now: the new release is checked before any of it is used, and the agent isn't asked to replace itself.

1. **Gets the release and checks it as the installer does:** `release.env` signed by Quire Server's release workflow for exactly that version's tag (Sigstore), the archive's checksum, the image by digest. Its Compose files must work with this server's settings. Its image is pulled. Nothing has changed yet.
2. **Waits for operations on sites to finish** (none starts meanwhile), and stops the agent. Sites keep running.
3. **Backs the server up** to `/srv/quire/backups/server/<time>/`: its files and `server.env`, and its database.
4. **Replaces the server's files** with the release's (its `server.env`, the front door's domains and the deploy keys stay), names the new image in `server.env`, and starts it. Sites are offline only for the seconds the front door is replaced, when the release changes it.
5. **Checks each part is healthy and the agent is the new release.** If not, it **rolls back by itself:** the files and settings from the backup, the database too if the new release's migrations ran.

`quire-server update --undo` goes back to the release before the last update, within a week. If that update's migrations ran, its database backup is restored too, and what changed in the registry since is lost; you're asked first.

A server installed from a checkout (`--source`) moves to releases the same way.

An update keeps the PostgreSQL major version the server runs (a release's patch versions of it come with the release). A release that no longer runs it is refused until PostgreSQL is upgraded.

## Upgrading PostgreSQL

A new major version of PostgreSQL (18 to 19) can't read the old one's data: moving to it is a step of its own, which you choose the time for. The sites that use the server's PostgreSQL are offline while their data is copied (minutes for most). Sites that keep their own PostgreSQL (adopted ones) aren't touched.

```bash
quire-server database:upgrade --check     # which major runs, and which this release can upgrade to
quire-server database:upgrade             # to the newest one this release runs (--to=19 for another)
```

It runs in the updater, and:

1. **Checks first, changing nothing:** this release runs the new major (its image pinned by digest, like every other), there's room on the server for a copy, and every database can be copied (PostgreSQL's own `postgres` database must be empty), and every site's runtime has PostgreSQL tools of the new major at least: sites back up with their runtime's `pg_dump`, which refuses a newer server, so their backups would fail after it. It gets the new major's image.
2. **Backs every site and the server up off the server**, when that's set up (`--no-backup` to skip it).
3. **Waits for operations on sites to finish, stops the agent and every container that uses the server's PostgreSQL.** Visitors get the front door's "back in a moment" page. Nothing else may be connected, or it stops there: what it wrote would be lost. Then the databases are **closed to everyone but PostgreSQL's superuser** until it's done (their connection limit: 0), so no site writes to the old major while it's copied.
4. **Starts the new major beside the old one,** on the same volume, in a folder of its own (`/var/lib/postgresql/19`; the old one's data is never touched), and **copies the roles** (with their passwords' hashes, so sites keep theirs) **and every database** into it with the new major's own `pg_dump` and `pg_restore`.
5. **Checks the copy against the original:** the same roles, rights and settings; in each database the same tables (with their exact row counts), indexes, sequences, functions, constraints, triggers and policies. Anything different stops it.
6. **Switches:** `server.env` names the new major (`COMPOSE_FILE` gets `database/postgres-19.yaml`), PostgreSQL starts on it, then the sites and the agent.

If anything fails, it **puts the server back by itself:** the old major starts on its data, the unfinished copy is removed, the sites start again. Each step is written down first (`/srv/quire/backups/database/`), so an upgrade cut short (the machine restarted) is finished with `quire-server database:upgrade --recover`: put back on the old major if it stopped while copying, or finished on the new one if it had switched already, since the sites may have written to it since. Nothing else runs until it is.

The old major's data stays on the server. `quire-server database:upgrade --undo` goes back to it, within a week: what the sites and the server wrote since is lost (it stays in the new major's data). `quire-server database:upgrade --clean` removes the data of the majors that don't run, once you're sure; until then the server holds both. An update of the server made before an upgrade can't be undone after it.

## Backups

Every site is backed up on the server before anything risky (an update, a restore, removing it), in `/srv/quire/backups/<site>/`, and kept for two weeks (each site's newest one, and the final one of a site that was removed, for good). **Backups off the server** are what survive losing the server itself: set them up once.

```bash
quire-server backups:setup
```

It asks for an S3-compatible bucket (DigitalOcean Spaces, Backblaze B2, Hetzner Object Storage, AWS S3…) and a key for it, checks they work, creates the backup repository there, and **shows its password once: keep it, with the bucket's key, somewhere other than this server** (a password manager). Without them the backups can't be read, and if the server is lost, they're the way back. Run again on a server whose backups are set up, it asks before replacing them, and keeps the settings it replaces (the old password among them) in `server.env.before-<time>`.

Then, every night (from 02:00 UTC; `QUIRE_SERVER_BACKUP_HOUR` in `server.env`, or `off`):

- **every running site** is backed up (`quire:backup`: Quire's data and files; and the rest of its database, e.g. an application's own tables, with `pg_dump` as the site's own role) and **the server's own** (its registry, the front door's domains, Git mode sites' deploy keys);
- they're sent off the server with [restic](https://restic.net): **encrypted on the server** (AES-256) before they leave it, and stored once per unique piece, so a night adds only what changed;
- **kept** 7 daily, 4 weekly and 6 monthly, for each site on its own (`QUIRE_SERVER_BACKUP_KEEP`); on Sundays the storage only forgotten backups used is freed, and 5% of the stored data is read back to check it;
- a site that fails doesn't stop the others; `quire-server operations` shows each night's run.

`quire-server backups jane` lists a site's backups off the server; `quire-server backups:restore jane` puts it back as its latest one has it (or `--snapshot=<ID>`): the site is backed up as it is first, and put back if it doesn't answer after. `quire-server backups:check` checks them at any time.

**A backup can become a new site:** a copy on this server (to try an update on, say), or a site moved from another server that backs up to the same bucket. Its code and files are the backup's (each site's folder is backed up with its data, but what a build makes again), its data is restored, and its settings are its own: its database, password and secret (everyone signs in again).

```bash
quire-server backups:restore jane --as=jane-copy --domain=copy.jane.example
quire-server backups:restore jane --from-server=old-server --as=jane --domain=jane.example
```

To move sites to a new server: install it, connect it to the same backups with `quire-server backups:setup --existing --host=new-server` (the bucket, its key and the password; each server names itself in the backups with `--host`), list the old server's with `quire-server backups jane --from-server=old-server`, and restore each. A Git mode site's code is in its repository, not its backups: add it from there, then restore its data with `backups:restore`. **A whole server, in one step:** on a new server connected to the old one's backups, `quire-server backups:restore-server --from-server=old-server` restores every site the old one had when it was last backed up (its backup lists them), from each one's latest backup, with the same name and domains and its update policy; Git mode sites come from their repository, with their deploy key from the old server's backup. Then point the domains' DNS at the new server.

The bucket's key can delete backups: someone who takes over the server can too. For backups that survive that, give the key no right to delete, and let the bucket's own rules (object lock, lifecycle) keep and expire them.

## Sites from a Git repository

For developers: a site can follow a branch of your repository (a project made from `quire/skeleton`) instead of being managed by the server. Its code, Quire's version and its extensions are then yours to change, in the repository; the server builds and runs what you commit.

```bash
quire-server site:key blog        # prints the site's public deploy key
```

Add that key to the repository as a **read-only deploy key** (GitHub: Settings → Deploy keys; GitLab: Settings → Repository → Deploy keys). It can read that one repository and nothing else. Then:

```bash
quire-server site:add blog --git=git@github.com:me/blog.git --branch=main --domain=blog.example --admin-email=me@example.com
quire-server site:deploy blog            # the branch's newest commit (or --commit=<all 40 characters>)
quire-server site:poll blog on           # or let the agent deploy new commits by itself
```

- **A deploy works like an update:** the commit is built while the site keeps running, the site is backed up, the new version started and checked, and rolled back by itself if it fails. `quire-server site:undo blog` goes back to the commit before, within a week.
- **With polling on,** the agent asks the repository for new commits every 5 minutes (`QUIRE_SERVER_GIT_POLL_SECONDS` in `server.env`, at least 60). A commit that fails isn't tried again by itself, nor is one you undid: the next commit is.
- **Public repositories** can be followed over HTTPS without a key (`--git=https://github.com/me/blog.git`).
- **Updates are made in the repository:** run `composer update` there and commit `composer.lock`. `quire-server site:update blog --dry-run` shows what's available. Commit a `composer.lock`, or every build resolves the versions anew.
- **Deploying on push** (a webhook) comes with the web console.

What the server guards against, whoever controls the repository:

- **Deploy keys stay out of reach.** They're in `/srv/quire/server/keys/<site>`, readable by root only, outside the site's folder, so they can never be committed or built into an image. They're deleted when the site is removed; remove them from the repository too.
- **Git can't be used to reach the server.** Only SSH and HTTPS repositories are followed: no local paths, `file://` or `ext::` (which runs a command). No hooks or submodules run. Symbolic links are checked out as plain files, so a repository can't point the site's build at the server's files.
- **Hosts are trusted on first use.** A host's key is trusted the first time the site reaches it, and only that key afterwards, per site.
- **What a deploy builds is exactly the commit:** files the commit doesn't have are removed, the server's own files (`prod.env`, `compose.server.yaml`) are kept and never seen by Git, and only commits of the site's branch are deployed.
- **Every commit's Compose configuration goes through the same check as any site's.** One asking for privileges, host mounts or published ports is refused before it's built.

## Security

The agent can act on Docker, and that's root on the machine, so the server is built around keeping that power narrow:

- **Every site's configuration is checked before it's built or started.** The check runs on all its Compose files merged, as Docker would run them. It refuses:
  - **Privileges:** privileged containers, added capabilities, host devices;
  - **The host itself:** the host's network, process, IPC or user namespaces, any network but the site's own (`network_mode` names none but another of its services), other runtimes, mounts of host paths, other projects' volumes or networks, files from outside the site's folder;
  - **Exposure:** published ports;
  - **Names that belong to others:** another site's domain or the database's name on a shared network, fixed container names;
  - **New settings:** any Compose setting it doesn't know yet, until it's reviewed (e.g. `use_api_socket`, which hands a container the Docker socket).

  Docker's own authorization plugins have been bypassed (CVE-2024-41110, CVE-2026-34040), so this check is the boundary.
- **Sites are isolated from the server and from each other:**
  - no Docker access, and no route to the agent or the docker-proxy;
  - a PostgreSQL role that opens only the site's own database, with a share of the server's PostgreSQL it can't exceed (32 connections, 5 GB of temporary files; queries and idle transactions end after 15 and 10 minutes unless the site asks otherwise), and connections kept for the server itself;
  - every service of a site, whoever wrote it: no Linux capabilities but a few harmless ones added back, `no-new-privileges`, memory and process limits, one container each (a site's own extra service without them is refused).
- **Composer runs in a container of its own** when the server audits or plans a site's update: the agent's image, as nobody, with no capabilities, on a network of its own that only reaches the internet, with only a copy of the site's composer.json and composer.lock. A composer.json that has it fetch other than over HTTPS (`secure-http: false`, `http://` repositories, request `options`) is refused.
- **The front door serves only registered domains,** and gets certificates only for them. A request's Host header must also match the name its connection was encrypted for, so a forged header can't reach another container. It reaches each site by a name only Docker's network knows (`<domain>.quire.internal`), never the public one: a site that's down gives "Back in a moment", not a loop back to the server.
- **Secrets:**
  - files readable by root only, never in images or command lines;
  - never in what the agent hands to the programs it runs: they get only the basics of its environment (PATH, HOME, the locale), so a site's Compose file can't read the server's settings with `${…}`, and each site runs with its own `APP_SECRET`;
  - database passwords sent to PostgreSQL only as SCRAM verifiers;
  - operation logs scrubbed of them.
- **Nothing is deleted without a backup,** and every operation is recorded. **Backups off the server are encrypted on it** (restic) before they leave; their password and the bucket's keys are in `server.env` (root only), never in a command line or a log.
- **Sites build on Quire's runtime images only when they check out:** before every build, each runtime image a site's Dockerfile names (`ghcr.io/quirecms/runtime`, `RUNTIME_BUILDER` and `RUNTIME`) must be signed by quirecms/framework's runtime workflow (Sigstore), and the build gets it by the digest that was checked. One that doesn't check out stops the build (an update is rolled back). Other base images are the site's choice, built on as they are and noted in the operation's log.
- **Upgrading PostgreSQL never changes the old major's data:** the new one is filled beside it, checked, and only then switched to. The copy runs with the new major's own tools, passwords only through the environment, and database names only as arguments of a fixed script (anything but plain names is refused).
- **Updates of the server are checked before any of their code runs,** by the release that runs (see "Updating the server"). The updater is the one part with Docker itself rather than the docker-proxy, since it may replace the docker-proxy: it runs only on demand, only the installed release's code, and takes nothing but a version.

What this doesn't protect against: a kernel exploit from inside a container (containers share the machine's kernel), a bug in Docker itself, and anyone who is root on the machine or holds `server.env`. [SECURITY.md](SECURITY.md) has the whole threat model, and how to report a vulnerability.

## Working on Quire Server

The tests and quality checks run in Docker, against a PostgreSQL of their own (`compose.dev.yaml`):

```bash
export HOST_UID=$(id -u) HOST_GID=$(id -g)
docker compose -f compose.dev.yaml run --rm php composer install
docker compose -f compose.dev.yaml run --rm php composer qa    # code style, Rector, PHPStan, tests
```

The end-to-end test runs the whole server in a Docker-in-Docker machine of its own: the installer, a site from Packagist, adopting, updating, Git mode. It needs privileged containers.

```bash
docker build -t quire-server-agent:test .
tests/e2e/run.sh quire-server-agent:test                 # everything, one part after another (an hour or more)
E2E_ONLY=git E2E_CACHE=1 tests/e2e/run.sh quire-server-agent:test   # one part, with a build cache
KEEP=1 tests/e2e/run.sh quire-server-agent:test          # keep the machine, to look around
E2E_ONLY=release tests/e2e/run.sh                        # this install.sh installs the latest release
E2E_ONLY=update tests/e2e/run.sh quire-server-agent:test # this checkout updates itself to a release
```

CI runs the six parts (`adopt`, `sites`, `git`, `release`, `update`, `backups`) in parallel, on clean machines. How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md).

Sites are found and managed through the contract every Quire site keeps (`Quire\Runtime\ServerContract`, [docs](https://github.com/quirecms/framework/blob/main/docs/docker.md#tools-that-manage-sites)): Quire Server uses no Quire code of its own.

## License

MIT: see [LICENSE](LICENSE).
