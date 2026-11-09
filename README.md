# Quire Server

Many Quire sites on one machine, each with its own Quire version, database and domains, behind one front door that holds their certificates. Add a site with one command; nothing else on the server restarts.

> **Status: early.** The front door, the agent and its commands, the installer, adopting sites installed before, updating sites, sites deployed from a Git repository, signed releases and the server's own updates, backups off the server, Docker behind a gate, a network link per site, problems told to the owner, and room for hundreds of sites. Still to come: the web console with passkeys, and one-click images. What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## How it works

```
internet ── 80/443 ──► front      Caddy: a certificate per domain, at its first visit; each request to the
                                   site whose domain it names. Static: nothing to reload when sites change.
                        agent      The only part that acts on Docker, through the gate. Adds and removes
                                   sites, keeps the front door's list of domains. No network listener.
                        gate       YorvoGate: reads every request to Docker before Docker gets it. The
                                   agent's own on one socket, each site's on a socket of its own, held to
                                   what the site is allowed. No network at all.
                        database   PostgreSQL: the server's registry, and a database per site.
                        sites      /srv/quire/sites/<site>: a Quire project each (from quire/skeleton), its
                                   own containers, joined to the front door and PostgreSQL.
```

A site is an ordinary Quire project in `/srv/quire/sites/<site>` (from `quire/skeleton`), with two files the server writes next to its own: `prod.env` (its settings and secrets, readable by root only) and `compose.server.yaml` (how it joins the server: no published ports, the shared PostgreSQL, its domains, hardening). Its own files are never changed (an adopted site's `prod.env`, which it had, gets two lines changed: see below).

## Installing it

On Ubuntu 26.04 LTS or a later LTS release (see [Supported systems](#supported-systems)), as root (Ubuntu has curl; `wget -O install.sh <address>` works as well):

```bash
curl -fsSL https://github.com/quirecms/server/releases/latest/download/install.sh -o install.sh
sh install.sh --email=you@example.com
```

To check the installer itself first (with [cosign](https://docs.sigstore.dev/cosign/system_config/installation/)):

```bash
version=0.4.0   # the release you install (its tag, without the "v")
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
   It refuses a range for sites' addresses that the machine routes elsewhere, and names a free one (see [Site networks](#site-networks)).
4. **Starts PostgreSQL, the Docker gate and the agent,** from images pinned by digest: the release's agent image (`ghcr.io/quirecms/server`), never built on the machine. Before the agent, `host-setup` prepares the machine for sites' builds: where AppArmor restricts user namespaces (Ubuntu 23.10 and later), it loads a profile that grants one only to sites' rootless BuildKit (`/etc/apparmor.d/yorvogate-buildkit-rootless`), rather than lifting the restriction for the whole machine. It runs at every start of the server and changes nothing else.
5. **Adopts the Quire sites already running from `/srv/quire/sites`** (it asks first; see below).
6. **Starts the front door,** once nothing else holds ports 80 and 443. If another web server holds them, it says which and stops there. It never stops anything it didn't start. When the machine's firewall is ufw and it's on, 80/tcp, 443/tcp and 443/udp are allowed in it.
7. **Turns on automatic security updates** for the operating system (`--no-os-updates` leaves them as they are).

`--site-networks=<range>` chooses the range sites' addresses come from.

**Trying it without a real domain:** `--test-certificates` makes the front door its own certificate authority (no public name needed, e.g. `jane.test`), and `--staging-certificates` takes certificates from Let's Encrypt's staging authority, for a public name such as `jane.203.0.113.7.sslip.io` that points at the machine, without using up Let's Encrypt's limits. Either way, browsers warn that the certificate isn't trusted: that's expected. Without either, certificates come from Let's Encrypt itself.

The server is put in place whole: built beside it, then moved in, so a dropped connection leaves either a complete install or none (the next run finishes it, taking over the cut-short run's lock).

Running it again is safe: it keeps the secrets it made and finishes what's left, e.g. after you've freed ports 80 and 443. `--yes` answers yes to its questions; `--help` lists its options.

**By hand,** for developers: copy this repository to `/srv/quire/server`, create `server.env` from `server.env.dist` (root only, with long random secrets, and `COMPOSE_FILE=compose.yaml:compose.build.yaml` to build the agent), install `bin/quire-server` in `/usr/local/bin`, and run `quire-server up`.

### Site networks

Each site gets networks of its own, with addresses the server plans: a /26 per site, from the private range `QUIRE_SERVER_SITE_NETWORKS` (`10.72.0.0/16` by default, room for 1,024 sites). One /27 is its link, an internal network it shares only with the front door and, when it uses the server's PostgreSQL, the database. Its own services are on the other /27. A site reaches no other site, and its PostgreSQL role logs in only from its link. Sites trust only the front door's address as their proxy.

Docker doesn't check a network's addresses against the machine's routes. If a VPN or a cloud network (a VPC) also uses the range, sites with addresses there lose their traffic to it, silently. So the range is checked:

- **by the installer,** which refuses a range the machine routes elsewhere, before the server starts. It names a free one (a /16 from `10.64.0.0` to `10.95.255.255`, a stretch clouds, VPNs and container tools leave alone) to pass with `--site-networks=`, or choose another private range from /8 to /22;
- **at each site's allocation,** against Docker's other networks: a site never gets addresses another Docker network has;
- **by `quire-server up` and `update`,** which name any route on the range that appeared since, such as a VPN added later.

Once sites have their addresses, they keep them. Setting `QUIRE_SERVER_SITE_NETWORKS` in `server.env` later (then `quire-server up`) applies to sites added from then on; a wider range that holds the current one gives room for more. A route that overlaps sites' addresses has to move off them.

### Supported systems

Quire Server is tested on **Ubuntu 26.04 LTS**, and runs on it and on later LTS releases (28.04, …), on x86_64 and arm64. The installer refuses other systems:

- **Ubuntu's interim releases** (26.10, …) get nine months of security updates: too short for a server.
- **Other distributions, Debian included:** the Docker gate's builders rely on Ubuntu's AppArmor profile and its restriction on user namespaces, and the server's tests run on Ubuntu only. Another system could run it, but nothing checks that it's as safe there.

`--unsupported-os` installs anyway, with a warning, at your own risk (Docker Engine 29 and Compose 2.24.4 or later must be there already: the installer installs Docker only on Ubuntu). A system is added to this list once the server's end-to-end tests pass on it.

### Releases

Each release ([GitHub releases](https://github.com/quirecms/server/releases)) has its notes, its version's section of [CHANGELOG.md](CHANGELOG.md) (what changed, and what to do when updating), and:

- **the agent's image,** `ghcr.io/quirecms/server:<version>`, for x86_64 and arm64, with its SBOM and build provenance, signed with Sigstore by the release workflow (keyless: no key to steal; the signature is in a public transparency log);
- **`release.env`,** naming the image by digest and the files by checksum, signed the same way, with `release.env.sigstore.json`;
- **the server's files** (`quire-server-<version>.tar.gz`) and **`install.sh`** (installing that version, with its own signature).

Every image Quire Server runs, and every GitHub Action that builds it, is pinned by digest or commit; Dependabot proposes updates. `quire-server version` says which release a server runs.

The installer and `quire-server update` download a release's files from GitHub over HTTPS only. When GitHub answers 429, 500, 502, 503 or 504, or doesn't answer in time, a download is tried 6 more times, 10 s apart: GitHub answers 504 now and then for a minute at a time.

### A site installed before the server

A site installed from the skeleton on its own (as in [the production docs](https://github.com/quirecms/framework/blob/main/docs/docker.md#production)) holds ports 80 and 443 itself. To run it behind the server, its folder must be in `/srv/quire/sites/<name>` (stop it, move the folder, start it again). The installer then finds it and offers to adopt it, or later: `quire-server site:adopt <name>`. Adopting it:

- **backs it up first,** to `/srv/quire/backups/<name>/before-server-<time>.tar`;
- **changes two lines of its `prod.env`:** `COMPOSE_FILE` gets `compose.server.yaml`, so that `docker compose --env-file prod.env …` keeps working as before, and `COMPOSE_PROJECT_NAME` is set. The file as it was is kept as `prod.env.before-server`. Its other files stay as they are;
- **adds `compose.server.yaml`:** no published ports, its domains on the front door's network, hardening;
- **keeps its own PostgreSQL** and its data where they are;
- **builds it while it keeps running,** then restarts it behind the front door. It's offline for a few seconds, and its certificate comes from the front door at the next visit.

If anything fails, it's put back as it was, ports included. Its domains are those of its `SERVER_NAME`, or pass them: `--domain=jane.example --domain=www.jane.example`.

Its Compose project (its `COMPOSE_PROJECT_NAME`, or its folder's name) must follow the rules of a site's name (lowercase letters, digits and single dashes, nothing starting with `quire`, not 12 hex digits) and be no other site's: Docker's containers, volumes and networks belong to a project by its name. Any other is refused before anything changes; such a site's project must be renamed first (its volumes carry the project's name, so their data has to be copied to the new ones).

## Commands

```bash
quire-server site:add jane --domain=jane.example --domain=www.jane.example --admin-email=jane@example.com --title="Jane's notes" [--invite]
quire-server site:adopt demo            # a site installed before the server (see above)
quire-server site:list
quire-server site:domains jane [--domain=jane.example --domain=www.jane.example]   # its domains, or others instead
quire-server version                    # which release this server runs
quire-server update [--to=0.2.0] [--check] [--undo]   # the server itself, see "Updating the server"
quire-server database:upgrade [--check] [--to=19] [--undo] [--clean]   # its PostgreSQL, see "Upgrading PostgreSQL"
quire-server site:update jane [--to=0.2.0] [--dry-run]   # see "Updating a site"
quire-server site:update --all [--automatic]             # every site; --automatic: what the night does, now
quire-server site:auto-updates jane [security|all|off]   # which updates it gets by itself, every night (or site:updates)
quire-server site:undo jane             # its last update or deploy, within a week
quire-server site:audit jane            # known vulnerabilities in its packages
quire-server site:key blog              # Git mode: a site's deploy key (see "Sites from a Git repository")
quire-server site:deploy blog [--commit=…]
quire-server site:poll blog on|off      # Git mode: deploy new commits by itself
quire-server site:remove jane          # asks you to type its name; backs it up first
quire-server site:restore-removed jane  # brings it back, with its data as it was (see "Backups")
quire-server backups:setup              # backups off the server: where, and their password (see "Backups")
quire-server backups jane | backups server      # what's stored off the server
quire-server backups jane --local       # its backups on the server: before an update, a restore, an undo; its final one
quire-server backups:now [jane]         # back up now (everything, or one site)
quire-server backups:restore jane [--snapshot=…]   # put a site back as a backup has it
quire-server backups:restore jane --file=before-restore-<time>.tar   # or as one on the server has it
quire-server backups:restore jane --as=copy --domain=…   # a new site from it (--from-server: another server's)
quire-server backups:restore-server --from-server=old   # every site of another server, from its backups
quire-server backups:check [--read=5]   # check the stored backups
quire-server help                       # every command, by what it's for
quire-server status [--dismiss=<key>]  # what needs you, the nightly work, sites' certificates (see "Problems")
quire-server notify:test                # a test notification, by email and to the webhook
quire-server operations [--site=jane]  # what was done, by whom, and how it ended
quire-server operations <id>           # an operation's log
quire-server up | down | ps | logs     # the server itself ("down -v" is refused: it would delete every site's database;
                                       # up and down wait for no operation to run, or --force)
```

- **Site names** are 2 to 30 lowercase letters, digits and single dashes (`jane`, `club-news`), starting with a letter; names of exactly 12 hex digits (`deadbeefcafe`) are Docker Compose's for containers it recreates, and are refused. They name the site's folder, its containers (`jane-php-1`) and its database (`site_jane`). Its domains are separate, and the first one is its main address.
- **Adding** gets the skeleton with Composer (without running any of its code), creates the site's database, checks and builds the site, starts it, installs it, and opens its domains. The first admin gets a password, shown once, or with `--invite` an email to choose one (needs an email server, and Quire 0.1.1 or later). If anything fails, everything done is undone.
- **Sites that keep their own PostgreSQL** (adopted ones) have it removed with them; the server's PostgreSQL holds the others' databases.
- **Removing** backs the site up to `/srv/quire/backups/<site>/final-<time>.tar`, closes its domains, removes its containers, volumes and database, and moves its folder to `/srv/quire/sites/.removed/`. A site that can't start can't be backed up: `--skip-backup` removes it anyway, and its data is gone.
- Point each domain's DNS at the server. Its certificate comes at the first visit (from Let's Encrypt).
- **Changing domains:** `site:domains jane --domain=…` gives it these instead, all of them, the first as its main one. It's started again with them (a few seconds offline), and the front door serves the new ones only. Point the new ones' DNS at the server first. If anything fails, it's put back as it was.
- **Database connections:** a site's php runs 4 PHP threads (not FrankenPHP's default of 2 a CPU), each with its connection while in use; its job worker holds one. A session idle for 11 minutes ends, so a quiet site gives its connections back. The server's PostgreSQL takes as many connections as the machine's memory holds sites, 3 a site, from 200 to 1,000 (`QUIRE_SERVER_DATABASE_CONNECTIONS`, written by the installer and by `quire-server up`; a resized machine gets its new value at the next `up`, PostgreSQL starting again with it, a few seconds offline).
- **Memory:** a new site (`site:add`, `restore --as`, adoption) isn't built while less than 1.5 GB is free (`QUIRE_SERVER_BUILD_MEMORY_MB`): it's refused before anything is made, with how much is free. Updates and deploys aren't held back. If memory runs out anyway, the kernel stops a site's container first (it starts again by itself): the server's own are the last, PostgreSQL after all of them, since one of its processes stopped would take every site's database through crash recovery.
- **Builds** run in a BuildKit builder of the site's own, started for the build and stopped after it, its cache trimmed to its newest 512 MB after each one: idle, it takes no memory, and at most 512 MB of disk.
- **A download that fails on the way** (a host's 5xx or 429, a connection cut or timed out: GitHub, where packages come from, answers 504 now and then for a minute) is tried once more, 30 s later, when the site's files are got from the skeleton and when it's built. A name that doesn't resolve, a refused connection or a full disk isn't: those are said at once.

## Problems

The agent watches what nobody else does, and tells you (`quire-server status` lists it all, and every other command says when something needs you):

- **its own work that failed:** last night's backups off the server, a site's automatic update, its rebuild on a newer runtime, a Git mode site's automatic deploy. Each says what was done about it and what to do, with the operation whose log says why (`quire-server operations <id>`). It's over when that kind of work succeeds again, whoever runs it;
- **nightly backups that stopped running** (none for 36 hours);
- **certificates:** a domain without one an hour after it was added (its DNS doesn't point here, or ports 80 and 443 are closed), or one that isn't renewed in time;
- **a disk filling up** (under a tenth, or 5 GB, free);
- **memory running out** (under a tenth, or 512 MB, available; told once it has lasted 10 minutes: a build's peak passes by itself);
- **notifications that couldn't be sent.**

Each problem is told **once when it starts, again each week while it lasts, and once when it's over**, never every night: a channel nobody filters. By email to `QUIRE_SERVER_NOTIFY_EMAIL` (or Let's Encrypt's address, `QUIRE_SERVER_ACME_EMAIL`), through `QUIRE_SERVER_MAILER_DSN`; and to a webhook, `QUIRE_SERVER_NOTIFY_WEBHOOK` (HTTPS, TLS 1.2 or newer; no redirects followed). The webhook's JSON body has a `text` that Slack's and Discord's incoming webhooks show as it is, the problems as data, and `sent_at`; each request is signed as GitHub signs its webhooks, `X-Quire-Signature-256: sha256=<HMAC-SHA256 of the body>` with `QUIRE_SERVER_NOTIFY_SECRET`. Messages hold names, times, advice and operation IDs, never a secret or a tool's output (the operations' log on the server has that). The agent sends them itself, with settings only it reads. `quire-server status --dismiss=<key>` stops telling of one you know about, until it's over or happens again.

## Updating a site

`quire-server site:update jane` first shows what would change, and changes nothing until you agree:

- **Its packages:** Quire, its extensions and everything else, to the newest versions its `composer.json` allows. Symfony stays on its minor version, as Symfony Flex would keep it. `--to=0.2.0` moves to another Quire release, e.g. a new minor version.
- **Known vulnerabilities** the update fixes, and any that remain (from Packagist's advisories). `quire-server site:audit jane` lists a site's vulnerabilities at any time.
- **The files the site got from the skeleton** (`Dockerfile`, Compose files, FrankenPHP's configuration), when Quire's version changes. A file the site never edited follows the new version. A file it edited that the new version changes too stops the update: merge it by hand, or keep the site's own with `--keep-edited-files`.

Then the update:

1. **Is worked out without running any package's code.** On a copy of the site's `composer.json` and `composer.lock`, Composer only resolves versions: no scripts, no plugins. The packages are installed in the site's image build, in Docker.
2. **Is built while the site keeps running** on its current image. A failed build changes nothing.
3. **Backs the site up,** to `/srv/quire/backups/<site>/update-<time>.tar`. A backup that lacks some stored files (`quire:backup` exits 3) stops the update before anything changes: it's the way back, so it has to have everything. It's kept, and the message names the files. Restoring a backup and undoing an update go on with such a backup of the site as it is, and say so: stopping would keep you from the backup you chose. Removing a site stops too (`--skip-backup` removes it anyway).
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

A new minor version of Quire (0.1 to 0.2, say) is never taken by itself, whatever the policy: a site's `composer.json` requires the minor version it was made with (`^0.1`), and a new one may change what the site and its extensions rely on. Read its notes, then `quire-server site:update jane --to=0.2.0`.

`quire-server site:updates jane all` sets it; `quire-server operations` shows what was done, by `automatic`. Some updates always wait for you: one that changes files of the skeleton the site edited, and one that changes Quire's version without Quire's signed release feed vouching for it. Git mode sites get none: their updates are made in their repository.

`quire-server site:update --all` updates every site now, one after the other (asking once); `--all --automatic` does what tonight would.

### Quire's release feed

Each Quire release publishes a feed, signed by Quire's release workflow (Sigstore): every release, its security fixes, what to do when updating, and what it requires. Before an update changes a site's Quire, the server checks the feed's signature and reads it: the plan shows the security fixes and upgrade notes, and a release this server can't run is refused (one that keeps a newer server contract: update the server first; one that needs a newer PostgreSQL than the server's, for sites on the server's PostgreSQL).

**Every site's code is held to the server contract**, not only updates: adding a site (with `--quire-version` or not), a Git mode site's deploys, taking a site over and adding one from a backup. Before a build, a Quire release whose feed entry needs a newer contract stops it. Once the code runs, its own `quire:about` says which contract it keeps, for code the feed doesn't list (a Git site's branch, say): a newer one than the server knows undoes a new site, puts a site being taken over back as it ran, and rolls an update or a deploy back. The message says to update the server first.

**The feed vouches for the code, not just the version.** From the first release after Quire 0.1.2, it names each `quire/*` package's commit in the repository Composer installs it from, and it lists the skeleton's files with their hashes. Every build checks the site's `composer.lock` first: each `quire/*` package must be the commit the feed names for its version, downloaded from GitHub's repository of that package. A tag moved there, or other code served for it, stops the build (an update is rolled back), since Composer itself doesn't check a download against the commit its lock names. A new site's skeleton, and the one an update brings, must have exactly the feed's files. What the feed doesn't vouch for (a branch, a fork, a release before feeds named commits) is built, and said in the operation's log; so is a feed that can't be got, while one that doesn't check out stops the build.

A site's versions are recorded in its folder's `composer.lock`. A new site's first one is worked out in Composer's sandbox before it's built, so it's checked like the rest. Its builds install exactly those versions; only updates change them.

## Updating the server

`quire-server update` moves the server itself (its agent, front door, Docker gate and PostgreSQL's settings) to the latest release; `--to=0.2.0` to another one, `--check` only says whether there's a newer one. Read the release's notes first ([CHANGELOG.md](CHANGELOG.md)): some say what to do after it. It runs in the **updater**, a container of the release that runs now: the new release is checked before any of it is used, and the agent isn't asked to replace itself.

1. **Gets the release and checks it as the installer does:** `release.env` signed by Quire Server's release workflow for exactly that version's tag (Sigstore), the archive's checksum, the image by digest. Its Compose files must work with this server's settings, and its Docker gate must accept every site's scope (`yorvogate check`: a newer gate may refuse what an older one took). Its image is pulled. Nothing has changed yet.
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

The old major's data stays on the server. `quire-server database:upgrade --undo` goes back to it, within a week: what the sites and the server wrote since is lost (it stays in the new major's data; upgrading again keeps that aside, as `<major>.before-<time>`). `quire-server database:upgrade --clean` removes the data of the majors that don't run, and what was kept aside, once you're sure; until then the server holds both. An update of the server made before an upgrade can't be undone after it.

## Backups

Every site is backed up on the server before anything risky (an update, a restore, an undo, removing it), in `/srv/quire/backups/<site>/`, and kept for two weeks (each site's newest one, its newest complete one when newer ones lack some stored files, and the final one of a site that was removed, for good). **Each is a way back:** every such operation ends by naming the one it made and the command that puts the site back as it was. `quire-server backups jane --local` lists them; `quire-server backups:restore jane --file=<name>` restores one, as a restore from off the server does (the site is backed up as it is first, and put back if it doesn't answer after). Only the site's own, as plain files in its folder, are taken. **A removed site comes back** with `quire-server site:restore-removed jane`: its code and files from its folder, archived in `/srv/quire/sites/.removed/`, its data from its final backup, its domains as it had them (or `--domain=…`, if another site took one). It gets settings of its own, so everyone signs in again. A Git mode site comes from its repository instead: add it again, then restore its final backup into it. **Backups off the server** are what survive losing the server itself: set them up once.

```bash
quire-server backups:setup
```

It asks for an S3-compatible bucket (DigitalOcean Spaces, Backblaze B2, Hetzner Object Storage, AWS S3…) and a key for it, checks they work, creates the backup repository there, and **shows its password once: keep it, with the bucket's key and the server's public key for its backups (shown then, and by `quire-server status`), somewhere other than this server** (a password manager). Without them the backups can't be read, and if the server is lost, they're the way back. Run again on a server whose backups are set up, it asks before replacing them, and keeps the settings it replaces (the old password among them) in `server.env.before-<time>`.

Then, every night (from 02:00 UTC; `QUIRE_SERVER_BACKUP_HOUR` in `server.env`, or `off`):

- **every running site** is backed up (`quire:backup`: Quire's data and files; and the rest of its database, e.g. an application's own tables, with `pg_dump` as the site's own role) and **the server's own** (its registry, the front door's domains, Git mode sites' deploy keys);
- they're sent off the server with [restic](https://restic.net): **encrypted on the server** (AES-256) before they leave it, and stored once per unique piece, so a night adds only what changed;
- **kept** 7 daily, 4 weekly and 6 monthly, for each site on its own (`QUIRE_SERVER_BACKUP_KEEP`); on Sundays the storage only forgotten backups used is freed, and 5% of the stored data is read back to check it;
- a site that fails doesn't stop the others; `quire-server operations` shows each night's run;
- **a site's backup that lacks some stored files** (`quire:backup` wrote it, but a file was missing, damaged, or on a disk the site no longer declares: its exit code 3, or its manifest's `"complete": false`) is sent all the same, as the best copy there is, **tagged `incomplete`**, and the night fails, naming the files and why for each, as the backup's manifest lists them. `quire-server backups jane` marks such backups. Retention keeps them apart (it groups backups by their tags), so nights of incomplete backups never push out the last complete one.

**Backups prove where they come from.** Anyone with the backups' password (a copy of `server.env`, another server sharing the bucket) can put a snapshot in the bucket, under any site's name. So the server records each snapshot it makes in its registry, and after each night's backups it backs up the list of those it keeps, **signed with a key of its own** (Ed25519, in `/srv/quire/server/signing/`, root only: not in `server.env`, and in none of its backups). A restore takes only a snapshot this server made, or one another server's signed list vouches for, checked with that server's public key: `--signed-by=ed25519:…`, given once (it's trusted for that server from then on). A snapshot's ID is a hash of all it holds, which restic checks as it restores, so the signed ID vouches for every file in it. "Latest" means the newest one vouched for. Anything else is refused, with why; `--unsigned` restores it anyway, for a backup you know the origin of (one made before servers recorded theirs). A restore that wouldn't leave a tenth of the disk free is refused before anything is got.

`quire-server backups jane` lists a site's backups off the server; `quire-server backups:restore jane` puts it back as its latest one has it (or `--snapshot=<ID>`): the site is backed up as it is first, and put back if it doesn't answer after. `quire-server backups:check` checks them at any time.

**A backup can become a new site:** a copy on this server (to try an update on, say), or a site moved from another server that backs up to the same bucket. Its code and files are the backup's (each site's folder is backed up with its data, but what a build makes again), its data is restored, and its settings are its own: its database, password and secret (everyone signs in again).

```bash
quire-server backups:restore jane --as=jane-copy --domain=copy.jane.example
quire-server backups:restore jane --from-server=old-server --signed-by=ed25519:… --as=jane --domain=jane.example
```

To move sites to a new server: install it, connect it to the same backups with `quire-server backups:setup --existing --host=new-server` (the bucket, its key and the password; each server names itself in the backups with `--host`, and a name another server's backups have is refused), list the old server's with `quire-server backups jane --from-server=old-server`, and restore each. A Git mode site's code is in its repository, not its backups: add it from there, then restore its data with `backups:restore`. **A whole server, in one step:** on a new server connected to the old one's backups, `quire-server backups:restore-server --from-server=old-server --signed-by=ed25519:…` (the old server's public key) restores every site the old one had when it was last backed up (its backup lists them), from each one's latest backup, with the same name and domains and its update policy; Git mode sites come from their repository, with their deploy key from the old server's backup. Then point the domains' DNS at the new server.

**Backups that survive a stolen `server.env`.** Whoever has `server.env` has the bucket's keys: they can read the backups (that's what the password is for), and with an ordinary bucket, delete them all. A bucket made with **Object Lock** (compliance mode, a default retention of 30 days) and versioning keeps every version of what's deleted until its retention ends, and nobody can shorten that, not even with the account's own keys; restic works on it unchanged (a delete only hides the file; tested with versitygw: some providers ask locked buckets' uploads for a checksum, and the first `backups:now` says whether yours takes restic's). Make the bucket that way, with a key that can't delete object versions or bypass retention, before `quire-server backups:setup`. AWS S3, Backblaze B2, Wasabi, Hetzner Object Storage and OVHcloud offer Object Lock; Cloudflare R2's bucket locks don't keep versions, so restic can't remove its lock files there. Whatever the provider says, **setup and every night prove it**: with the server's own keys, on an object of their own, they try what an attacker would (delete its version, bypass a governance lock, shorten its retention), and `quire-server status` says "protected: what's deleted stays recoverable for 30 days", or why not (told once, as every problem is). An unprotected bucket isn't refused (backups that can be deleted beat none), but it's said every time.

Each night also checks that **every backup this server made, and its retention didn't forget, is still in the bucket**: one gone is someone else's doing, told the same night. Then, from a protected bucket:

```bash
quire-server backups:recover --before=2026-11-04T01:00:00Z --dry-run   # what it would bring back
quire-server backups:recover --before=2026-11-04T01:00:00Z              # the repository as it was then
```

It makes every file of the repository deleted or changed since that time current again from its version of then (backups made since are kept), with a key that may list the bucket's versions (`QUIRE_SERVER_BACKUP_RECOVER_ACCESS_KEY` and `…_SECRET_KEY`, used for this only, never saved). Then `quire-server backups:check`, and give the bucket new keys. Backups on the server itself (`/srv/quire/backups`) are root's: whoever is root on the server can delete them, and that's out of reach of any setting.

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
- **Where the repository is:** its host must be on the internet. A name that points at this machine or a private network is refused (checked each time Git connects, and Git is held to the address checked; HTTPS redirects aren't followed). A Git server on your own network, e.g. a Gitea in the same VPC, is allowed by name: `QUIRE_SERVER_GIT_PRIVATE_HOSTS=git.internal.example` in `server.env`, then `quire-server up`.
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
  - **Names that belong to others:** another site's domain or the database's name on a shared network, fixed container names, service names with a `-` (lowercase letters, digits and `_` only: Docker names a container `<site>-<service>-1`, so site `jane`'s service `blog-php` would take site `jane-blog`'s `php` container);
  - **New settings:** any Compose setting it doesn't know yet, until it's reviewed (e.g. `use_api_socket`, which hands a container the Docker socket).

  Docker's own authorization plugins have been bypassed (CVE-2024-41110, CVE-2026-34040), so the server doesn't rely on them.
- **Docker itself is behind a gate** ([YorvoGate](https://github.com/yorvolabs/yorvogate), pinned by digest), so a gap in that check isn't root on the machine. It reads each request's body as Docker would before Docker gets it. Each site's Compose runs on a socket of the site's own, which the agent opens for it with exactly what the server allows the site, so the gate holds it, whatever its files say:
  - only what carries its project's label, and names of its own (`<site>-…`, `<site>_…`); nothing of the server's or another site's (another's containers don't even exist for it);
  - the same limits as the check: no privileges, capabilities, host paths, devices, host namespaces or published ports, memory and process limits;
  - images only in its own namespace (`quire-sites/<site>/`), and no pushing them anywhere;
  - on the shared networks, only its own names;
  - its BuildKit only to build: nothing else runs in it.

  The agent's own operations (Composer's sandbox, sites' builders, the server's database dumps) go to a socket of their own. Only the agent mounts the gate's sockets, and only the gate holds Docker's socket (and the updater, while it runs).
- **Sites are isolated from the server and from each other:**
  - no Docker access, and no route to the agent or the gate;
  - no route to another site: each is on a link of its own, with only the front door and the server's PostgreSQL there, and trusts only the front door's address as its proxy (so a forged `X-Forwarded-For` header can't change the client's address, which sign-in throttling relies on). See [Site networks](#site-networks);
  - a PostgreSQL role that opens only the site's own database, and logs in only from the site's link, with a share of the server's PostgreSQL it can't exceed (16 connections, 5 GB of temporary files; queries, idle transactions and idle sessions end after 15, 10 and 11 minutes unless the site asks otherwise), and connections kept for the server itself;
  - every service of a site, whoever wrote it: no Linux capabilities but a few harmless ones added back, `no-new-privileges`, memory and process limits, one container each (a site's own extra service without them is refused).
- **Each site builds on a BuildKit of its own,** so its build cache is its own: rootless (user 1000, not privileged, no capabilities, no host paths), from an image pinned by digest, on the sandbox network (the internet only). The site reaches it through its own socket, only to build, and the image it builds is checked by the gate before it's loaded.
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
- **Updates of the server are checked before any of their code runs,** by the release that runs (see "Updating the server"). The updater is the one part with Docker itself rather than the gate, since it may replace the gate: it runs only on demand, only the installed release's code, and takes nothing but a version. Services a release no longer has are removed by it; an updater from before the Docker gate leaves the docker-proxy, which the agent removes when it starts, with the privileged builders of those releases.

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

CI runs the eight parts (`adopt`, `sites`, `git`, `release`, `update`, `backups`, `database`, `links`) in parallel, on clean machines, and every night (what isn't pinned changes: Quire's releases, its runtime images, Sigstore). How to contribute: [CONTRIBUTING.md](CONTRIBUTING.md).

Sites are found and managed through the contract every Quire site keeps (`Quire\Runtime\ServerContract`, [docs](https://github.com/quirecms/framework/blob/main/docs/docker.md#tools-that-manage-sites)): Quire Server uses no Quire code of its own.

## License

MIT: see [LICENSE](LICENSE).
