#!/bin/sh
# Whether a final release's image is its highest release candidate's with only its version changed
# (.github/promote.Dockerfile), before it's scanned, signed or named by release.env. Deny by default:
# for each platform, the two manifests must be the same but for their config, and the two configs the
# same once only their creation time, their history, the QUIRE_SERVER_VERSION entry of their
# environment and their version label are left out. Those two must then say the final's version, and
# the history may only add entries that add no layer. A field added to images some day differs, and
# is refused. Both images must have the same platforms (their attestations aside). Everything is read
# from the registry by digest, and checked against its digest.
#
#   .github/release-promote-check.sh <image> <candidate's digest> <final's digest> <version>
#
# Prints "digest=sha256:…", the final's, for $GITHUB_OUTPUT. Needs curl, jq and sha256sum; the
# registry is the image's (ghcr.io: an anonymous token, the image is public).
set -eu

image=${1:?Usage: .github/release-promote-check.sh <image> <candidate digest> <final digest> <version>}
tested=${2:?Usage: .github/release-promote-check.sh <image> <candidate digest> <final digest> <version>}
final=${3:?Usage: .github/release-promote-check.sh <image> <candidate digest> <final digest> <version>}
version=${4:?Usage: .github/release-promote-check.sh <image> <candidate digest> <final digest> <version>}
registry=${image%%/*}
repository=${image#*/}
label=org.opencontainers.image.version

refuse() {
	echo "::error::$1" >&2
	exit 1
}

for digest in "$tested" "$final"; do
	printf '%s\n' "$digest" | grep -Eqx 'sha256:[0-9a-f]{64}' || refuse "\"$digest\" isn't an image digest."
done
[ "$tested" != "$final" ] || refuse "The final's image is the candidate's ($tested): its version wasn't changed."
printf '%s\n' "$version" | grep -Eqx '[0-9]+\.[0-9]+\.[0-9]+' || refuse "\"$version\" isn't a final release's version."

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

token=$(curl -fsS --retry 3 "https://$registry/token?scope=repository:$repository:pull" | jq -er .token) ||
	refuse "$registry gave no token to read $repository."
accept='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'

# fetch manifests|blobs <digest> <file>: from the registry, and only if it's what its digest says
fetch() {
	curl -fsSL --retry 3 -H "Authorization: Bearer $token" -H "Accept: $accept" -o "$3" "https://$registry/v2/$repository/$1/$2" ||
		refuse "$image's $1 $2 can't be read from $registry."
	[ "sha256:$(sha256sum "$3" | cut -d ' ' -f 1)" = "$2" ] || refuse "What $registry served for $2 isn't what its digest says."
}

# The platforms of an index, attestations aside: "os/architecture[/variant] digest", one per line
platforms() {
	jq -er '
		select(.mediaType == "application/vnd.oci.image.index.v1+json" or .mediaType == "application/vnd.docker.distribution.manifest.list.v2+json")
		| [.manifests[] | select(.annotations["vnd.docker.reference.type"] != "attestation-manifest")]
		| if length == 0 then error("no platform") else . end
		| .[] | "\(.platform.os)/\(.platform.architecture)\(if .platform.variant then "/" + .platform.variant else "" end) \(.digest)"
	' "$1" | sort
}

fetch manifests "$tested" "$work/tested.index"
fetch manifests "$final" "$work/final.index"
platforms "$work/tested.index" > "$work/tested.platforms" 2>/dev/null || refuse "The candidate's image ($tested) isn't an index of platforms."
platforms "$work/final.index" > "$work/final.platforms" 2>/dev/null || refuse "The final's image ($final) isn't an index of platforms."
[ "$(cut -d ' ' -f 1 "$work/tested.platforms")" = "$(cut -d ' ' -f 1 "$work/final.platforms")" ] ||
	refuse "The final's image is for $(cut -d ' ' -f 1 "$work/final.platforms" | tr '\n' ' ')but the candidate's for $(cut -d ' ' -f 1 "$work/tested.platforms" | tr '\n' ' ')."
[ -z "$(cut -d ' ' -f 1 "$work/tested.platforms" | uniq -d)" ] || refuse "The candidate's image names a platform twice."

# What a config must keep once its version is left out, and the version it says
kept='del(.created, .history) | .config.Env |= map(select(startswith("QUIRE_SERVER_VERSION=") | not)) | del(.config.Labels["'$label'"])'
said='[(.config.Env // [])[] | select(startswith("QUIRE_SERVER_VERSION="))] as $env | if ($env | length) == 1 then "\($env[0] | ltrimstr("QUIRE_SERVER_VERSION=")) \(.config.Labels["'$label'"] // "")" else "none" end'

while read -r platform digest; do
	other=$(awk -v p="$platform" '$1 == p { print $2 }' "$work/final.platforms")
	fetch manifests "$digest" "$work/tested.manifest"
	fetch manifests "$other" "$work/final.manifest"
	[ "$(jq -cS 'del(.config.digest, .config.size)' "$work/tested.manifest")" = "$(jq -cS 'del(.config.digest, .config.size)' "$work/final.manifest")" ] ||
		refuse "$platform: the final's manifest differs from the candidate's in more than its config (its layers are not the tested ones)."
	fetch blobs "$(jq -r .config.digest "$work/tested.manifest")" "$work/tested.config"
	fetch blobs "$(jq -r .config.digest "$work/final.manifest")" "$work/final.config"
	jq -S "$kept" "$work/tested.config" > "$work/tested.kept"
	jq -S "$kept" "$work/final.config" > "$work/final.kept"
	cmp -s "$work/tested.kept" "$work/final.kept" ||
		refuse "$platform: the final's config differs from the candidate's in more than its version: $(diff "$work/tested.kept" "$work/final.kept" | grep '^[<>]' | head -n 6 | tr -s ' \n' ' ')"
	[ "$(jq -r "$said" "$work/final.config")" = "$version $version" ] ||
		refuse "$platform: the final's image says it's \"$(jq -r "$said" "$work/final.config")\" (environment, label), not $version."
	[ "$(jq -r "$said" "$work/tested.config")" != none ] || refuse "$platform: the candidate's image doesn't say its version."
	# The history: the candidate's, then only entries that add no layer
	jq -e --slurpfile final "$work/final.config" '
		[(.history // [])[] | del(.created)] as $before
		| [($final[0].history // [])[] | del(.created)] as $after
		| ($after | length) >= ($before | length)
		and $after[0:($before | length)] == $before
		and ($after[($before | length):] | all(.empty_layer == true))
	' "$work/tested.config" >/dev/null || refuse "$platform: the final's history isn't the candidate's with only entries that add no layer."
done < "$work/tested.platforms"

echo "digest=$final"
