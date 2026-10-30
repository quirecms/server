# Security policy

Quire Server runs as root and manages Docker: a flaw in it can be a flaw in every site on a server. Please report one privately.

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions or pull requests.**

Report them privately through GitHub instead:

1. Go to the [Security tab](https://github.com/quirecms/server/security) of this repository.
2. Click **Report a vulnerability**.
3. Describe the issue, with as much of this as you can:
   - the version (`quire-server version`) or commit;
   - what an attacker needs (e.g. "a Git mode site's repository", "a site's folder", "the network");
   - steps to reproduce, or a proof of concept;
   - the impact: what an attacker gets.

Only the maintainers can see your report.

## What happens next

- We acknowledge your report within **3 working days**.
- We confirm or rule out the issue, and keep you updated while we work on a fix.
- We aim to release a fix within **90 days** of the report, much sooner for anything that reaches the host or another site.
- Once a fix is released, we publish a security advisory and credit you, unless you prefer to stay anonymous.

Please give us a reasonable chance to fix the issue before you disclose it publicly.

## Supported versions

Security fixes go into the latest release. Servers update themselves with `quire-server update`, which checks a release's signatures before any of it runs (see "Updating the server" in the [README](README.md)).

## Threat model

What Quire Server is built to hold, so you can tell a vulnerability from a limit.

**Who's trusted:** whoever is root on the machine, and the owner who runs `quire-server` (through `sudo`). Quire Server's own releases, checked by their signatures, and Quire's runtime images, checked the same way before every build.

**Who isn't:**

- **A site's code and its Compose files**, above all a Git mode site's repository, which anyone who can push to it controls. A site must not reach the host, the agent, the docker-proxy, the server's PostgreSQL beyond its own database, another site's files, secrets, images, database or traffic, or the server's settings. Every site's whole configuration is checked before it's built or started, on the files it names and on what Compose makes of them.
- **What a site hands back:** files copied out of its containers (only plain files are taken), its backups and database dumps (restored as the site's own role, into its own database), its `composer.json` (Composer runs in a container of its own, with no way to the server's networks, over HTTPS only).
- **The network:** the front door serves only registered domains, and a request's Host header must match the name it was encrypted for.
- **Backups off the server:** a backup's list of sites and its deploy keys are checked one by one, and only plain files come back.

**Defended in depth, not by design:**

- The docker-proxy gives the agent what Docker gives it: whatever reaches it can start a privileged container. Sites have no route to it, and Composer runs where it can't reach it either; the agent itself is root-equivalent.
- An enabled extension of a site runs with that site's full trust: it's the site's code.

**Out of scope:** a kernel exploit from inside a container (containers share the machine's kernel), a flaw in Docker itself, and anyone who is root on the machine or holds `server.env` (it holds the server's secrets and the backups' keys).
