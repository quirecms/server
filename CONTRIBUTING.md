# Contributing to Quire Server

Thank you for helping. Quire Server runs as root and manages Docker, so every change is held to one bar: it can't open a way from a site to the host or to another site, and it's tested so that it can't quietly stop holding.

Security issues: please don't open a public issue or pull request, see [SECURITY.md](SECURITY.md).

## Setting up

Everything runs in Docker, against a PostgreSQL of its own (`compose.dev.yaml`). The first run builds the development image, which takes about 5 minutes.

```bash
export HOST_UID=$(id -u) HOST_GID=$(id -g)
docker compose -f compose.dev.yaml run --rm php composer install
docker compose -f compose.dev.yaml run --rm php composer qa     # code style, Rector, PHPStan, tests: what CI runs
docker compose -f compose.dev.yaml run --rm php composer fix    # apply code style and Rector's changes
docker compose -f compose.dev.yaml down                          # stop the development database
```

Two checkouts side by side (e.g. worktrees) share one development database and image unless you set `COMPOSE_PROJECT_NAME` differently for each.

CI also runs ShellCheck on `bin/quire-server` and every `*.sh` file, `composer audit`, and the end-to-end test (see the README's "Working on Quire Server"). To run ShellCheck as CI does:

```bash
docker run --rm -v "$PWD:/m:ro" koalaman/shellcheck-alpine:v0.11.0 sh -c 'shellcheck -s sh -e SC2016 /m/bin/quire-server $(find /m -name "*.sh" -not -path "/m/vendor/*")'
```

## Before you open a pull request

- **Tests that fail without it.** For a fix, run the new test against the code before your change and see it fail. For anything a site controls, a hostile case: what an attacker would write, refused.
- **`composer qa` and ShellCheck pass.**
- **The end-to-end part it touches**, when it touches what Docker, Compose or PostgreSQL do (`E2E_ONLY=<part>`); a check of your own there when unit tests can only script the outside world.
- **Least privilege:** a program the agent runs gets only what it's given (`ProcessRunner`), a site only what it's allowed (`ComposePolicy`).
- **Messages:** what an owner reads when something fails says what happened, whether their data is safe, and what to do next.
- **The README** when a command, a setting or what an owner sees changes.
- **An entry in [CHANGELOG.md](CHANGELOG.md),** under "Unreleased", when what an owner sees or does changes; under "Updating from …" when they have to do something after updating. A release's notes are its section there.
- **Commits on a branch** are free-form: they're squashed when merging. `Co-authored-by` is the only trailer, for a human co-author.

Pull requests are reviewed for the above.

## Pull requests

Pull requests are squashed: the title becomes the commit's subject on `main`, followed by `(#N)`, and the description becomes its body. Write both for the change as a whole, never by copying the branch's commit messages.

**The title** is `<Verb> <what changes>`:
- It starts with an imperative verb, capitalized, e.g. Add, Fix, Refuse, Let, Make, Move, Remove, Rename, Read, Run, Show, Keep.
- It names one change, as whoever uses it sees it. No colon, no semicolon and no list of several changes: if it needs a list, the title is the umbrella and the list goes under "What changes".
- At most 72 characters, with no trailing period, no `feat:`-style prefix, no product name and no version. Only Dependabot's `chore:` stays, so dependency updates can be filtered.
- A release's pull request is titled exactly `Release X.Y.Z` (or `Release X.Y.Z-rc.N`), and the one that moves `main` on to the next minor exactly `Start X.Y`.

Good: "Refuse a release whose tag isn't signed". Not: "feat: release tags are checked", "Releases: signed tags, checksums and docs".

**The description** has exactly these sections, in this order, and nothing before, between or after them (the template has them):
- `## Why`: one to three sentences about the problem, for whoever uses it.
- `## What changes`: one behavior per bullet. A breaking change is the first bullet, starting "Breaking:".
- `## Tested`: what ran, then `NOT RUN: <what>, because <why>` for anything that didn't.
- `## Refs`: public links only (issues, pull requests, published advisories, RFCs, upstream docs). Leave the section out when there are none.

A release's pull request uses the same sections: "Why" is `Release X.Y.Z.`, and "What changes" has one bullet per changelog heading.

**Nothing internal in public text.** Titles, descriptions, commit messages, branch names, tag messages, release notes and the changelog say what the code changes and why, for a reader outside the project. They carry no internal tracking codes or decision numbers, no names of who decided, reviewed or approved what, and no paths outside the repository.

**Before you send it, check:**
1. Does the title start with an imperative verb?
2. Does the title name one change, with no colon or list?
3. Is it 72 characters or fewer, with no prefix and no trailing period?
4. Does the description have exactly Why, What changes, Tested and (when there are any) Refs, in that order, and nothing else?
5. Is there nothing internal in either?

If the branch gains commits after the pull request is opened, update the title and description so they still describe the whole change.

**Branches** are `<kind>/<slug>`, where the kind is one of `feat`, `fix`, `docs`, `chore`, `ci` or `security`, and the slug is lowercase with hyphens and describes the change, with no internal code. A release is prepared on `chore/release-X.Y.Z`. `release/X.Y` is reserved for maintenance branches.

**A fix of an embargoed vulnerability** is developed in the repository's private security advisory fork, never on a public branch. Until the advisory is published, its title, description and branch describe the change neutrally: no advisory ID, and no description of the exploit. The changelog's Security entry and the advisory ID are added at the release.

## Releases

- **Tags and release branches:** a release tag is `vX.Y.Z`, or `vX.Y.Z-rc.N` for a release candidate, with no other labels; annotated, SSH-signed by a key in `.github/release-signers` (`git tag -s v0.6.0 -m "Quire Server 0.6.0"`), on a commit of `main` that passed CI. The release workflow refuses any other tag before anything that can publish runs; `.github/verify-tag.sh v0.6.0` checks a tag the same way. Releases come from `main`; `release/X.Y` is created from `vX.Y.0` only when an older minor needs a fix after `main` has moved on (fixed on `main` first, then `git cherry-pick -x`).
- **What a release publishes:** a GitHub release titled `Quire Server X.Y.Z` (a pre-release for `-rc.N`) with its CHANGELOG section (Keep a Changelog: `## [X.Y.Z] - YYYY-MM-DD`) and its image's digest as notes; the agent's image with its SBOM and provenance, scanned, then signed (servers install it by digest and check that signature); and the release's files with their checksums, signed with Sigstore. Before 1.0, a minor release may break things and a patch only fixes them; "Updating from …" says what an owner must do.

Changes to how Quire Server is released (`.github/release-signers`, the workflows and `.github/verify-tag.sh`) need a code owner's review (`.github/CODEOWNERS`).

## Commands

A command is a class in `src/Console/` named `server:<name>`, run as `quire-server <name>` (`bin/quire-server` adds the prefix). A new one goes in the README's list of commands and in `bin/quire-server`'s header. `tests/Console/` shows how they're tested; `BackupsSetupCommandTest` is one that reads and writes the registry, on a real PostgreSQL schema of its own (`tests/Registry/RegistryTestCase.php`), with the programs it runs scripted (`tests/ScriptedRunner.php`). Internal commands (the agent's own) are `hidden: true`: "quire-server help" lists the others, by what they're for.

## The Compose policy

`src/Docker/ComposePolicy.php` decides what a site may ask of Docker: it's the boundary between a site and the host. It reads the configuration Compose makes of a site's files (`docker compose config --format json`); `ComposeFiles` reads the files themselves first, for what Compose resolves away (`include`, `extends` of another file).

It is fail-closed: a service setting it doesn't know is refused. Each setting is in one of three lists:

- `SAFE_SERVICE_KEYS`: allowed as it is, whatever its value (it has no power over the host or other sites);
- `CHECKED_SERVICE_KEYS`: allowed, with its value checked in `service()` or a method of its own;
- `FORBIDDEN_SERVICE_KEYS`: refused whenever it asks for anything, with the reason shown to the owner.

Below a service's settings it's fail-closed too: `NESTED_KEYS` lists, level by level, what's allowed in a build, `deploy`, a mount, a network a service joins, a top-level volume or network and its IPAM, and so on. A setting at any level that isn't listed is refused. Booleans are read however they're written (`true`, `"true"`, `"yes"`, `1`).

To add a rule:

1. Find the setting's shape in Compose's output (it's often not what you write): `docker compose config --format json --no-env-resolution` on a scratch file.
2. Move the setting to the right list (or add it to its level in `NESTED_KEYS`), and check its value where the others are checked.
3. Add hostile cases to `ComposePolicyTest::hostile()` (each a change to a valid site and the words of the refusal), and harmless values to `testHarmlessValuesOfForbiddenSettingsPass()`.
4. Add a golden case: a folder in `tests/Docker/golden/` with the site's `compose.yaml`, a `prod.env`, and in `expect.txt` the words of the refusal. `GoldenComposeTest` runs the real `docker compose config` on it, the agent image's own Compose, so the policy is checked against what Compose writes, not what we think it writes; a new Compose version that writes a setting another way fails here, in CI.
5. Say it in the README's "Security" section.
