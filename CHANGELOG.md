# Changelog

All notable changes to Quire Server. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). A release is made from a section here: its version's section is its notes.

## [Unreleased]

### Fixed

- The agent goes on with its round when a step fails, whichever step. Before, three of them (the front door's domains, Git mode sites' polling, the nightly updates) read the registry outside the round's error handling: when PostgreSQL restarted right then ("quire-server up" after the machine's memory changed, an update), the agent stopped, Docker started it again, and the operations it was running were marked failed. Now the step says it failed, and works again at the next round.

## [0.5.0] - 2026-11-09

Quire 0.2.0's sites: server contract 2, backups that say when they lack a stored file, and every way a site's code comes in held to the contract. Also downloads that ride out GitHub's short outages.

### Updating from 0.4.0

`quire-server update` moves a server to 0.5.0, checked by 0.4.0's own updater. There is nothing to do after it, and no site is started again. Two flaws of 0.4.0's updater are dealt with on the way, by 0.5.0's files and its agent:

- 0.4.0 couldn't update to any release with a Docker gate: it stopped at "Checking the sites' scopes with the release's Docker gate failed … yorvogate.yaml: is a directory", and the server ran as it was. 0.5.0's Compose files pass that check.
- 0.4.0's updates removed the key that signs the server's backups, with the release's files (a copy stays in the update's backup of the server). 0.5.0's agent puts it back from there as it starts, before any night needs it: `quire-server logs agent` says so. The public key you kept stays the server's.

Nothing changes for sites on Quire 0.1, which never report an incomplete backup. Once a site is on Quire 0.2.0 (`quire-server site:update <site> --to=0.2.0`, by hand: automatic updates stay on its minor version), a backup of it that lacks some stored files **stops** an update, a take-over and a removal, where 0.4.0 went on (`--skip-backup` still removes a site anyway), and fails the night's backups. Fix what it names (the backup says which files and why), and run it again.

### Added

- **The server keeps server contract 2**, which Quire 0.2.0 and later require (their backups exit with 3 when incomplete, see Changed): this server updates sites to them, where an older one refuses and says to update the server first.
- **Every way a site's code comes in checks the server contract it keeps, not only updates.** Before, only `site:update` refused a Quire release needing a newer server, and only when the release feed listed it. Now `site:add` (with `--quire-version` or not), Git sites (added and each deploy), taking a site over (`adopt`) and adding one from a backup (`restore --as`, bringing back a removed site) refuse it too, in two places:
  - before the build, when the release feed says the locked Quire release needs a newer server: nothing is built;
  - once the new code runs, from its own `quire:about` (code the feed doesn't list, such as a Git site's branch): a new site is undone before it's installed or restored, a site being taken over is put back as it ran, and an update or a deploy is rolled back.

  The message says to update the server first. The `com.quirecms.contract` label isn't used for this: it comes from the site's own `compose.yaml`, which may be out of date.

- **The server records the public key it signs its backups with.** A key file that went missing, or another key in its place, is refused, with how to put the server's own back: no backup is signed with a new key the owner didn't choose, which other servers wouldn't trust. **`quire-server backups:new-key`** starts a new one on purpose, and shows its public key for the servers that restore this one's backups.

### Changed

- **A site's backup that lacks some stored files is no longer taken for a good one.** Quire's `quire:backup` is to exit 3 when it wrote a backup without some stored files (missing, damaged, or on a disk the site no longer declares): until now it exited 0 with a warning, and the server went on. From now on:
  - an update, taking a site over and removing a site stop, since the backup is the way back; the backup is kept, and the message names the files;
  - restoring a backup and undoing an update go on, and say the backup of the site as it is is incomplete;
  - off the server, it's sent all the same, tagged `incomplete`, and the night fails; `backups` marks it, and retention keeps it apart, so incomplete nights never push out the last complete backup;
  - on the server, it's marked (`<kind>-<time>.incomplete` beside it), `backups --local` says so, and a site's newest complete backup is kept as well as its newest, however old.

  Sites on today's Quire never exit 3, so nothing changes for them until a Quire release with this fix.
- **Which stored files an incomplete backup lacks comes from its manifest**, as server contract 2 defines it (each file with why: `missing`, `damaged`, `unknown_disk`), not from what `quire:backup` prints, whose wording is for people. A backup whose manifest says it's incomplete is taken for one even when `quire:backup` exited 0. When the manifest can't be read, the exit code decides, and what `quire:backup` printed is shown instead. What the manifest names is shown without control characters and cut short: it comes from the site.

### Fixed

- **Updating the server to a release with a Docker gate** works. The updater unpacked the release where only it could see it, so Docker made a folder in place of the gate's settings it mounts, and the gate's check of the sites' scopes failed: no server on 0.4.0 could update. It unpacks it in the server's folder now, and the release's gate reads the release's own settings.
- **Updating the server keeps the key that signs its backups** (`signing/`), and the agent's work folder: they were taken for the release's files, backed up with them and removed. The next night made a new key, and servers that trusted this one's backups refused them, with nothing saying why.
- **The installer and `quire-server update` download a release's files for a minute** when GitHub fails for a moment (HTTP 408, 429, 500, 502, 503, 504 or no answer in time): tried 6 more times, 10 s apart, where curl's 3 quick tries gave up after 7 s. GitHub answered 504 for a minute at a time on 2026-11-07, and an install stopped at "Couldn't download release.env". Finding the latest release is tried again the same way, and so is Docker's signing key when the installer installs Docker.
- **Getting a site's files from the skeleton** (`site:add`, an update's plan, `restore --as`) is tried once more, 30 s later, when a download fails on the way (a host's 5xx or 429, a connection cut), as a site's build is since 0.4.0: GitHub answered 504 to every download for a minute at a time on 2026-11-07, and adding a site failed.
- **`quire-server update`, `backups:setup` and `database:upgrade` right after another one** no longer refuse with "An update runs already": the container of the one before, which Docker removes a moment after it ends, was taken for a running one. A running one is still refused, now named ("quire-server database:upgrade runs already").

## [0.4.0] - 2026-11-07

Docker behind a gate, each site on a network link of its own, problems told to the owner, backups that prove where they come from, Quire's code checked against its signed feed, and a server that holds hundreds of sites. Also every Low and Info of the server audit of 2026-10-29.

### Updating from 0.3.1

`quire-server update` moves a server to 0.4.0, checked by 0.3.1's own updater. The first start of 0.4.0's agent then:

- **removes what 0.3.1 ran:** the docker-proxy, its network and the privileged builders. Docker is reached through YorvoGate from then on;
- **moves each site to its link:** each site is started again on its own networks, every container made again (a few seconds offline each, one site after the other), with `restart: unless-stopped` for every service, its own PostgreSQL included.

Then:

- **Run `quire-server up` once.** It sizes PostgreSQL's connections for the machine's memory (`QUIRE_SERVER_DATABASE_CONNECTIONS`): PostgreSQL starts again with them, every site offline a few seconds. Until then it keeps 200, the limit of 0.3.1.
- **Keep the backups' new public key.** With backups off the server set up, the first night makes the server's key for them; `quire-server status` shows its public key. Keep it with the backups' password, somewhere other than the server: another server needs it to restore this one's backups.
- **Backups made by 0.3.1 aren't signed:** restoring one needs `--unsigned` (only for a backup you know comes from your server).
- **Sites that ask for more are refused** when they're next started, updated or deployed, until their files change: a service name with a `-`, a site or project name of exactly 12 hex digits, Docker's or the server's own labels, annotations, a set-user-ID `tmpfs`, memory above 64 GiB, ulimits other than `nofile`, `nproc`, `core` and `stack`. Sites made from Quire's skeleton ask for none of these.
- **Ubuntu 26.04 LTS or a later LTS release** is what the server runs on. A server on another system keeps running, but sites' rootless builders rely on Ubuntu's AppArmor profile, and nothing checks the server there.

### Added

- **Docker behind [YorvoGate](https://github.com/yorvolabs/yorvogate) 0.2.2** (pinned by digest), which reads every request's body before Docker gets it. Each site's Compose runs on a socket of its own, held to what the server allows the site; the agent's own operations go to a trusted socket. Before the gate is replaced (an install, an update), the new gate checks every site's scope (`yorvogate check`).
- **Rootless builders:** each site builds on a BuildKit of its own, as user 1000, not privileged, with no capabilities. `host-setup` loads an AppArmor profile that grants only them a user namespace, at every start of the server.
- **A network link per site** (D-34): its own range of addresses (a /26 per site from `QUIRE_SERVER_SITE_NETWORKS`, `10.72.0.0/16` by default, room for 1,024 sites), shared only with the front door and PostgreSQL. No site reaches another, a site's PostgreSQL role logs in only from its link, and sites trust only the front door as their proxy. The installer refuses a range the machine routes elsewhere and names a free one (`--site-networks=`); `quire-server up` and `update` name a route on it that appeared since.
- **Problems reach the owner** (D-35): failed nightly work (backups, automatic updates, rebuilds, Git deploys), backups that stopped running, certificates missing or not renewed, a disk filling up, memory running out, notifications that couldn't be sent. Each is told once when it starts, weekly while it lasts and once when it's over, by email and to a webhook (HTTPS, signed as GitHub signs its webhooks). `quire-server status` lists them, `--dismiss=<key>` stops one, `quire-server notify:test` sends a test.
- **Backups prove where they come from** (D-36): the server records each snapshot it makes and signs the list of those it keeps with a key of its own (Ed25519). A restore takes only a snapshot this server made, or one another server's signed list vouches for (`--signed-by=ed25519:…`).
- **Backups that survive a stolen `server.env`** (D-37): a bucket with Object Lock and versioning keeps what's deleted; setup and every night prove whether it does, and say so. Each night checks every backup the server made is still there; `quire-server backups:recover --before=<time>` brings the repository back as it was.
- **Quire's code is the code Quire released** (D-38): every build checks each `quire/*` package in the site's `composer.lock` against the commit Quire's signed feed names for its version, and a new site's skeleton against the feed's files.
- **A way back after removing, restoring or undoing:** `quire-server site:restore-removed`, `quire-server backups <site> --local` and `quire-server backups:restore <site> --file=<name>`; each of those operations ends with the command that undoes it.
- **`quire-server database:upgrade`:** the server's PostgreSQL to a newer major (18 to 19) and back, copied beside the old one and checked before the switch; `--check`, `--undo`, `--clean`, `--recover`.
- **`quire-server site:domains`:** a site's domains, or others instead.
- **`quire-server help`** lists the commands by what they're for; `quire-server site:auto-updates`; the installer offers Let's Encrypt's staging certificates (`--staging-certificates`).

### Changed

- **Ubuntu 26.04 LTS and later LTS releases only.** The installer refuses interim releases and other distributions; `--unsupported-os` installs anyway, with a warning.
- **Sites at scale** (D-39, D-40), from two scale tests of 133 and 139 sites on 32 GB:
  - a site's builder runs only while it builds, its cache trimmed to its newest 512 MB after each build;
  - a site's php runs 4 PHP threads, whatever the machine's CPUs; a session idle for 11 minutes ends; a site's role holds at most 16 connections; PostgreSQL's connections follow the machine's memory (200 to 1,000);
  - when memory runs out, the kernel stops a site's container first, PostgreSQL last; a new site isn't built while less than 1.5 GB is free (`QUIRE_SERVER_BUILD_MEMORY_MB`).
- **Failures say what happened and what to do,** not a tool's raw output; the tool's own words go to the operation's log. A long build says every 30 seconds that it still runs.
- **A build that fails for a reason that passes by itself** (a host's 5xx or 429, a connection cut) is tried once more, 30 seconds later.
- **The Compose policy** refuses service names with a `-`, site and project names of 12 hex digits, the server's and Docker's labels, annotations, set-user-ID `tmpfs`, memory above 64 GiB and ulimits other than the gate's; one live site per Compose project.
- **Inputs are held tighter:** domains (punycode, lookalike and mixed scripts, special-use names), Git addresses (no token, no private address unless `QUIRE_SERVER_GIT_PRIVATE_HOSTS` names it, Git held to the address checked, no redirects), branches.
- `no-new-privileges` is written in Docker's own spelling (`no-new-privileges=true`), so Docker 29 no longer warns; the policy still takes every spelling.
- Webhooks are sent over TLS 1.2 or newer, said in the sender.
- Symfony 8.1.11, with the fixes for the twelve advisories published on 2026-11-04.

### Fixed

- **Sites come back after a reboot:** every container of a site gets `restart: unless-stopped`, its own PostgreSQL too, and the server's PostgreSQL reloads its logins without Docker counting it as stopped by hand.
- **`quire-server update --undo`, and the rollback of a failed update, restore the server's registry whole,** in one transaction: an older release's registry over a newer one failed when a newer migration had added a table with a key on an older one (0.4.0's `site_network`), and the server stayed on the newer release. A restore that fails at any point changes nothing.
- A failed add, restore as a new site or adoption no longer leaves its builder, its cache and its gate scope behind.
- A deploy that would delete files edited in the site's folder is refused, naming them.
- Operation logs are scrubbed of secrets also when encoded (base64, JSON), and of passwords in addresses.
- The updater trusts only the records it sealed, with a key the agent doesn't reach; release archives with links or paths outside them are refused.
- The installer builds the server beside and moves it in whole, takes over a lock a cut-short run left, and stops when a release file changed since it was installed.
- The front door says "No site here" over HTTP for a domain it doesn't serve.

### Security

The server audit of 2026-10-29: its Mediums left open in 0.3.1 (M-01 the Docker gate, M-06 the feed's commits, M-08 the sites' links, M-11 the backups' origin, M-13 the backups' protection, M-14 problems told, M-19 a way back, M-20 failures said), and every Low and Info.

## [0.3.1] - 2026-10-30

The fixes for the server audit's Criticals, Highs and most of its Mediums.

### Security

- Programs the agent runs get only the basics of its environment, never the server's settings (C-1).
- Composer runs in a container of its own, with no capabilities, on a network that reaches only the internet (C-3).
- Sites' images are held to `quire-sites/<project>/`; the front door reaches sites by names only Docker's network knows (`<domain>.quire.internal`).
- Each site builds on a BuildKit of its own, pinned by digest.
- A release's intent is written before it's applied, and put back at the agent's start if it was cut short.
- The Compose policy reads nested settings from allow-lists, checked against the real `docker compose config`.
- `SECURITY.md` and `CONTRIBUTING.md`; a release waits for CI on its commit, and its image is scanned.

## [0.3.0] - 2026-10-28

### Added

- A backup becomes a new site: a copy on this server, or a site moved from another server (`backups:restore --as`).
- A whole server from its backups, in one step (`backups:restore-server`).

## [0.2.0] - 2026-10-28

### Added

- The server updates itself (`quire-server update`): checked first, backed up, rolled back by itself, `--undo` within a week.
- Sites update themselves every night (`security`, `all` or `off`), with Quire's signed release feed checked.
- Sites build on Quire's runtime images only when their signature checks out, and are built again when a newer runtime is published.
- Backups off the server: every night, encrypted, kept and checked (restic, on an S3-compatible bucket).

## [0.1.1] - 2026-10-28

### Fixed

- The installer installs releases: cosign may read their files.

## [0.1.0] - 2026-10-28

The first release.

### Added

- The front door (Caddy: a certificate per domain at its first visit), the agent, and adding and removing sites.
- The installer, which adopts sites installed before it.
- Updating sites, rolling back by themselves, and undo.
- Sites deployed from a Git repository.
- Signed releases: images and files checked before installing.

[Unreleased]: https://github.com/quirecms/server/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/quirecms/server/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/quirecms/server/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/quirecms/server/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/quirecms/server/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/quirecms/server/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/quirecms/server/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/quirecms/server/releases/tag/v0.1.0
