<!--
The title is the squash commit's subject: imperative, at most 72 characters, no trailing period,
with no internal references, e.g. "Refuse a release whose tag isn't signed".
This description becomes the commit's body. See "Pull requests, commits and branches" in CONTRIBUTING.md.
-->

## Why

<!-- The problem, or what an owner couldn't do. Required. -->

## What changes

<!-- Bullets: behavior, not files. -->

-

## Tested

<!-- What ran (unit tests, the end-to-end parts), and what didn't (NOT RUN, and why). -->

-

## Refs

<!-- Only what anyone can open: an issue (Fixes #123), a pull request, a published advisory, an RFC, upstream docs. Delete the section when there's none. -->

-

## Checklist

- [ ] Tests fail without the change; anything a site controls has a hostile case, refused
- [ ] `composer qa` and ShellCheck pass
- [ ] The end-to-end part it touches ran (`E2E_ONLY=<part>`), when it changes what Docker, Compose or PostgreSQL do
- [ ] Least privilege: what the agent runs gets only what it's given (`ProcessRunner`), a site only what it's allowed (`ComposePolicy`)
- [ ] What an owner reads when it fails says what happened, whether their data is safe, and what to do next
- [ ] The README, and CHANGELOG.md under "Unreleased" (and "Updating from …" when owners must act)
