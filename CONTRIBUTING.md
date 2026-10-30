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

## What a change needs

- **Tests that fail without it.** For a fix, run the new test against the code before your change and see it fail. For anything a site controls, a hostile case: what an attacker would write, refused.
- **The end-to-end part it touches**, when it touches what Docker, Compose or PostgreSQL do (`E2E_ONLY=<part>`); a check of your own there when unit tests can only script the outside world.
- **Least privilege:** a program the agent runs gets only what it's given (`ProcessRunner`), a site only what it's allowed (`ComposePolicy`).
- **The README** when a command, a setting or what an owner sees changes.

Pull requests are reviewed for the above, and for messages: what an owner reads when something fails says what happened, whether their data is safe, and what to do next.

## Commands

A command is a class in `src/Console/` named `server:<name>`, run as `quire-server <name>` (`bin/quire-server` adds the prefix). A new one goes in the README's list of commands and in `bin/quire-server`'s header. `tests/Console/` shows how they're tested, against a real PostgreSQL schema where they read the registry (`tests/Registry/RegistryTestCase.php`).

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
