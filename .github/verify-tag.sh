#!/bin/sh
# Refuses a tag that may not be released: one not named vX.Y.Z or vX.Y.Z-rc.N, not annotated, or not
# signed by a key in .github/release-signers as main lists them (a tag's own commit can't add its
# signer). The release workflow runs it in a job of its own, before any job that holds
# publishing credentials. Ported from YorvoID's (ADR-0010 §6).
#
#   .github/verify-tag.sh v0.3.2
#
# Needs a checkout with origin/main (fetch-depth: 0).
set -eu

tag=${1:?Usage: .github/verify-tag.sh <tag>}

if ! printf '%s\n' "$tag" | grep -Eq '^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-rc\.[1-9][0-9]*)?$'; then
	echo "::error::$tag isn't a release tag: vX.Y.Z, or vX.Y.Z-rc.N for a release candidate."
	exit 1
fi

signers=$(mktemp)
trap 'rm -f "$signers"' EXIT
git show origin/main:.github/release-signers >"$signers"

if ! grep -Eq '^[^#[:space:]]' "$signers"; then
	echo "::error::.github/release-signers on main has no key: nothing can be released until a maintainer's key is added."
	exit 1
fi

# The tag object itself, which a checkout may not have fetched.
git fetch --quiet --no-tags --force origin "refs/tags/$tag:refs/tags/$tag"

if [ "$(git cat-file -t "refs/tags/$tag")" != tag ]; then
	echo "::error::The tag $tag isn't annotated, so it isn't signed: make it with git tag -s."
	exit 1
fi

if ! git -c gpg.format=ssh -c gpg.ssh.allowedSignersFile="$signers" tag -v "$tag"; then
	echo "::error::The tag $tag isn't signed by a key in .github/release-signers."
	exit 1
fi

# The commit the workflow runs for: a tag moved since it was pushed names another.
if [ -n "${GITHUB_SHA:-}" ] && [ "$(git rev-parse "refs/tags/$tag^{commit}")" != "$GITHUB_SHA" ]; then
	echo "::error::The tag $tag no longer names $GITHUB_SHA, the commit this run is for."
	exit 1
fi
