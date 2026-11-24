#!/bin/sh
# Where a release's image comes from: built from the tag, or, for a release with release candidates,
# the image of its highest candidate, which is the one that was tested. A final tag on another commit
# than that candidate's, or on a lower candidate's, would ship an image nobody tested: refused. So is
# a candidate whose release can't be checked: its release.env must be signed for its own tag, and its
# image too, before anything of it is used. The release workflow runs it before building anything.
#
#   .github/release-image.sh <version> <commit> <image>
#
# Prints, for $GITHUB_OUTPUT, "mode=build", or "mode=promote", "digest=sha256:…" and "candidate=vX.Y.Z-rc.N".
# Needs the repository's tags (fetch-depth: 0), gh (signed in) and cosign; GITHUB_REPOSITORY names the
# repository.
set -eu

version=${1:?Usage: .github/release-image.sh <version> <commit> <image>}
commit=${2:?Usage: .github/release-image.sh <version> <commit> <image>}
image=${3:?Usage: .github/release-image.sh <version> <commit> <image>}
repository=${GITHUB_REPOSITORY:?Set GITHUB_REPOSITORY}
issuer=https://token.actions.githubusercontent.com

refuse() {
	echo "::error::$1" >&2
	exit 1
}

# A release candidate itself, and a release without candidates: built from the tag
case $version in *-*)
	echo mode=build
	exit 0
	;;
esac

highest=$(git tag -l "v$version-rc.*" | grep -E "^v$(printf '%s' "$version" | sed 's/\./\\./g')-rc\.[1-9][0-9]*\$" | sort -t . -k 4,4n | tail -n 1 || true)

if [ -z "$highest" ]; then
	echo mode=build
	exit 0
fi

# The highest candidate, on whichever commit: the final must be on its commit
on=$(git rev-list -n 1 "$highest")
[ "$on" = "$commit" ] || refuse "v$version is on $commit, but its highest release candidate, $highest, is on $on: its image is the one that was tested. Tag a new release candidate on this commit, test it, then tag v$version on it."

identity="https://github.com/$repository/.github/workflows/release.yaml@refs/tags/$highest"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

gh release download "$highest" --repo "$repository" --pattern release.env --pattern release.env.sigstore.json --dir "$work" >/dev/null 2>&1 ||
	refuse "$highest's release (its release.env and signature) can't be got: is it published? v$version promotes its image, so it needs it."
cosign verify-blob --bundle "$work/release.env.sigstore.json" --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" "$work/release.env" >/dev/null 2>&1 ||
	refuse "$highest's release.env isn't signed by this repository's release workflow for $highest: its image isn't taken."

tested=$(sed -n 's/^IMAGE=//p' "$work/release.env")
printf '%s\n' "$tested" | grep -Eq "^$(printf '%s' "$image" | sed 's/[.]/\\./g')@sha256:[0-9a-f]{64}\$" ||
	refuse "$highest's release.env names \"$tested\" as its image, not one of $image by digest."
cosign verify --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" "$tested" >/dev/null 2>&1 ||
	refuse "$highest's image ($tested) isn't signed by this repository's release workflow for $highest, or is gone."

echo mode=promote
echo "digest=${tested#*@}"
echo "candidate=$highest"
