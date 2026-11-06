#!/bin/sh
# Installs Quire Server on this machine: many Quire sites behind one front door. As root:
#
#   sh install.sh [--email=you@example.com] [--yes]
#
# What it does, in order (each step is safe to run again: run it again to finish an interrupted
# install, and the secrets it made are kept):
#
#   1. Checks the machine (Ubuntu 26.04 LTS or a later LTS release); installs Docker from Docker's own repository when it's missing (asks first).
#   2. Gets a release and checks it: its signature (Sigstore, made by quirecms/server's release
#      workflow for that very version), its archive's checksum and its image's signature; nothing is
#      run that doesn't check out. Puts the server in /srv/quire/server, its settings and new secrets
#      in server.env (root only), and the "quire-server" command in /usr/local/bin.
#      Refuses a range for sites' addresses that the machine routes elsewhere (a VPC, a VPN), and
#      names a free one.
#   3. Starts the server without its front door (PostgreSQL, the Docker gate, agent), with the release's
#      images, by digest (or the agent built from --source).
#   4. Adopts the Quire sites already running from /srv/quire/sites (asks first): they're backed up
#      and moved behind the front door, a few seconds offline each.
#   5. Starts the front door, once nothing else holds ports 80 and 443. It never stops or removes
#      anything it didn't start: if another web server holds them, it says which and stops there.
#   6. Turns on the operating system's automatic security updates.
#
# Options:
#   --email=ADDRESS    Let's Encrypt writes here about certificates (expiry, problems)
#   --yes              Answer yes to every question (installing Docker, adopting sites)
#   --source=DIR       Install from this folder (a checkout of quirecms/server) instead of a release
#   --version=X.Y.Z    The release to install (this installer's own release, or the latest)
#   --site-networks=CIDR  The private range sites' addresses come from (10.72.0.0/16 by default),
#                      before any site has its addresses
#   --no-os-updates    Leave the operating system's updates as they are
#   --unsupported-os   Install on another system anyway, untested (see "Supported systems" in the README)
#   --test-certificates  Certificates from the front door's own authority, not Let's Encrypt (tests,
#                      without a public name: browsers warn)
#   --staging-certificates  Certificates from Let's Encrypt's staging authority (tests on a public
#                      name, e.g. <ip>.sslip.io, without using up Let's Encrypt's limits: browsers warn)
#
# The whole script is a function called on its last line: a download cut short runs nothing.
set -eu

# Set by each release to its own version; empty until the first one.
release=''

root=${QUIRE_SERVER_ROOT:-/srv/quire}
docker_min=29.0.0
compose_min=2.24.4
# Ubuntu's LTS releases from this one on: the only systems the server is tested on
ubuntu_min=26.04
# Docker's release signing key (https://docs.docker.com/engine/install/ubuntu/)
docker_key_fingerprint=9DC858229FC7DD38854AE2D88D81803C0EBFCD88

say() { printf '\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[33mWarning:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# A yes/no question on the terminal (also when this script comes through a pipe); "yes" with --yes.
# Without --yes and without a terminal, the answer is no: nothing is done unasked, and it says so.
ask() {
	if [ "$yes" = 1 ]; then return 0; fi
	# In a subshell: a redirection that fails on ":" (a special built-in) ends the whole script.
	if ! (: </dev/tty) 2>/dev/null; then
		warn "$1 There's no terminal to answer on: the answer is no (--yes answers yes)."
		return 1
	fi
	printf '%s [y/N] ' "$1" >/dev/tty
	read -r answer </dev/tty || return 1
	case "$answer" in [yY] | [yY][eE][sS]) return 0 ;; *) return 1 ;; esac
}

# Whether version $2 is at least $1 (dotted numbers; anything after them is ignored)
at_least() {
	printf '%s\n%s\n' "$1" "$2" | sed 's/^v//; s/[^0-9.].*//' | sort -c -t. -k1,1n -k2,2n -k3,3n 2>/dev/null
}

# From the server's folder: Compose reads COMPOSE_FILE (server.env) relative to where it runs.
compose() {
	(cd "$root/server" && docker compose --project-directory "$root/server" --env-file "$root/server/server.env" "$@")
}

# A random secret of N bytes, in hex: checked, as an empty one in server.env would go unseen
random_hex() {
	hex=$(od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n') || die "Couldn't read random bytes for a secret."
	[ "${#hex}" -eq $(($1 * 2)) ] || die "Couldn't make a secret of $1 random bytes."
	printf '%s' "$hex"
}

check_machine() {
	say 'Checking the machine'
	[ "$(id -u)" -eq 0 ] || die 'Run it as root, e.g. "sudo sh install.sh".'
	[ "$(uname -s)" = Linux ] || die 'Quire Server runs on Linux.'
	case "$(uname -m)" in
		x86_64 | aarch64 | arm64) ;;
		*) die "This machine's processor ($(uname -m)) isn't supported: x86_64 or arm64." ;;
	esac

	memory=$(awk '/^MemTotal:/ { print int($2 / 1024) }' /proc/meminfo 2>/dev/null || echo 0)
	if [ "$memory" -lt 1800 ]; then warn "This machine has ${memory} MB of memory: 2 GB is the least for the server and a site."; fi
	mkdir -p "$root"
	space=$(df -Pm "$root" | awk 'NR == 2 { print $4 }')
	if [ "$space" -lt 10240 ]; then warn "${space} MB free on $root: sites need room to build (10 GB or more)."; fi
	note "$(system_name), $(uname -m), ${memory} MB of memory, ${space} MB free on $root"

	supported_system && return 0
	if [ "$unsupported_os" = 1 ]; then
		warn "$(system_name) isn't a supported system (Ubuntu $ubuntu_min LTS or a later LTS release): going on, as --unsupported-os asks, untested. The Docker gate's builders rely on Ubuntu's AppArmor."
		return 0
	fi
	die "Quire Server runs on Ubuntu $ubuntu_min LTS or a later LTS release; this is $(system_name). (--unsupported-os installs anyway, untested.)"
}

# The system's name from /etc/os-release, e.g. "Ubuntu 26.04 LTS"
system_name() {
	# shellcheck disable=SC1091
	(. /etc/os-release 2>/dev/null && printf '%s\n' "${PRETTY_NAME:-${NAME:-Linux}}") || echo Linux
}

# Whether this is Ubuntu, an LTS release, $ubuntu_min or later. Interim releases get 9 months of
# security updates: too short for a server.
supported_system() {
	[ -r /etc/os-release ] || return 1
	(
		# shellcheck disable=SC1091
		. /etc/os-release
		[ "${ID:-}" = ubuntu ] || exit 1
		case "${VERSION:-}" in *LTS*) ;; *) exit 1 ;; esac
		printf '%s\n' "${VERSION_ID:-}" | grep -Eq '^[0-9]+\.[0-9]+$' || exit 1
		at_least "$ubuntu_min" "$VERSION_ID"
	)
}

install_docker() {
	if command -v docker >/dev/null 2>&1; then
		engine=$(docker version --format '{{.Server.Version}}' 2>/dev/null) || die 'Docker is installed but not running: start it ("systemctl start docker") and run this again.'
		at_least "$docker_min" "$engine" || die "Docker Engine $engine is too old: $docker_min or later is needed. Update it, and run this again."
		plugin=$(docker compose version --short 2>/dev/null) || die 'Docker Compose is missing: install the "docker-compose-plugin" package.'
		at_least "$compose_min" "$plugin" || die "Docker Compose $plugin is too old: $compose_min or later is needed."
		note "Docker Engine $engine, Compose $plugin"

		return
	fi

	[ -r /etc/os-release ] || die 'Docker is missing, and this system is unknown: install Docker Engine, and run this again.'
	# shellcheck disable=SC1091
	. /etc/os-release
	[ "${ID:-}" = ubuntu ] || die "Docker is missing: install Docker Engine for ${PRETTY_NAME:-this system} (https://docs.docker.com/engine/install/), and run this again."
	ask "Docker isn't installed. Install it from Docker's repository (download.docker.com)?" || die 'Docker is needed: install it, or answer yes.'

	say 'Installing Docker'
	export DEBIAN_FRONTEND=noninteractive
	# Ubuntu's needrestart would draw its progress bars and ask about services: it restarts what it must, quietly
	export NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1
	apt-get update -qq
	apt-get install -qq -y ca-certificates curl gnupg >/dev/null
	install -m 0755 -d /etc/apt/keyrings
	key=$(mktemp)
	curl -fsSL --proto '=https' --tlsv1.2 "https://download.docker.com/linux/$ID/gpg" -o "$key"
	# The key must be Docker's: its fingerprint, not just a download over HTTPS.
	gpg --show-keys --with-colons "$key" 2>/dev/null | grep -q "^fpr:*$docker_key_fingerprint:" || { rm -f "$key"; die "Docker's signing key didn't match its known fingerprint: not installing."; }
	install -m 0644 "$key" /etc/apt/keyrings/docker.asc
	rm -f "$key"
	cat >/etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/$ID
Suites: ${VERSION_CODENAME:?}
Components: stable
Signed-By: /etc/apt/keyrings/docker.asc
EOF
	apt-get update -qq
	apt-get install -qq -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin >/dev/null
	systemctl enable --now docker >/dev/null 2>&1 || true
	docker version --format '{{.Server.Version}}' >/dev/null 2>&1 || die 'Docker was installed but doesn'\''t run: see "systemctl status docker".'
	note "Docker Engine $(docker version --format '{{.Server.Version}}'), Compose $(docker compose version --short)"
}

# The release workflow that signs releases, and cosign, which checks them (pinned by digest)
repository=quirecms/server
issuer=https://token.actions.githubusercontent.com
cosign_image=ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:0f06154836d2de34f887a5c273506d52ca43edaa1d9e53e6510fa78a48f69c2d

# The server's files, from --source, or from a release whose signatures check out: sets $from (a
# folder) and $image (the release's agent image, by digest; empty for --source)
fetch() {
	image=''
	if [ -n "$source" ]; then
		[ -f "$source/compose.yaml" ] && [ -f "$source/bin/quire-server" ] || die "\"$source\" isn't Quire Server's folder (no compose.yaml, bin/quire-server)."
		from=$source

		return
	fi

	if [ -z "$version" ]; then
		latest=$(curl -fsSL --proto '=https' --tlsv1.2 -o /dev/null -w '%{url_effective}' "https://github.com/$repository/releases/latest") || die "Couldn't reach github.com/$repository."
		case "$latest" in
			*/releases/tag/v*) version=${latest##*/releases/tag/v} ;;
			*) die 'There is no release of Quire Server yet: install from a checkout of quirecms/server with --source=<its folder>.' ;;
		esac
	fi
	printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' || die "\"$version\" isn't a version."

	say "Checking Quire Server $version"
	work=$(mktemp -d)
	mkdir "$work/files"
	# cosign's image runs as a user of its own: it may read the release's files (public ones, which
	# only root may change), whatever root's umask.
	chmod 755 "$work"
	download() {
		curl -fsSL --proto '=https' --tlsv1.2 --retry 3 -o "$work/$1" "https://github.com/$repository/releases/download/v$version/$1" || die "Couldn't download $1 of Quire Server $version."
		chmod 644 "$work/$1"
	}
	# Signed by this repository's release workflow, for this very tag, through GitHub's identity provider
	identity="https://github.com/$repository/.github/workflows/release.yaml@refs/tags/v$version"
	cosign() {
		docker run --rm -v "$work:/release:ro" "$cosign_image" "$@"
	}

	download release.env
	download release.env.sigstore.json
	cosign verify-blob --bundle /release/release.env.sigstore.json --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" /release/release.env >/dev/null 2>&1 \
		|| die "The signature of Quire Server $version didn't check out: not installing. (Signed by $identity?)"
	note 'Its signature checks out (Sigstore: signed by its release workflow)'

	value() { sed -n "s/^$1=//p" "$work/release.env" | head -n 1; }
	[ "$(value VERSION)" = "$version" ] || die 'The release names another version: not installing.'
	archive=$(value ARCHIVE)
	digest=$(value ARCHIVE_SHA256)
	image=$(value IMAGE)
	printf '%s\n' "$archive" | grep -Eq '^quire-server-[0-9A-Za-z.-]+\.tar\.gz$' || die 'The release names no archive: not installing.'
	printf '%s\n' "$digest" | grep -Eq '^[0-9a-f]{64}$' || die 'The release names no checksum: not installing.'
	printf '%s\n' "$image" | grep -Eq "^ghcr\.io/$repository@sha256:[0-9a-f]{64}\$" || die 'The release names no image: not installing.'

	download "$archive"
	[ "$(sha256sum "$work/$archive" | cut -d ' ' -f 1)" = "$digest" ] || die "$archive isn't the release's (its checksum differs): not installing."
	cosign verify --certificate-identity "$identity" --certificate-oidc-issuer "$issuer" "$image" >/dev/null 2>&1 \
		|| die "The signature of the release's image didn't check out: not installing."
	note 'Its image'\''s signature checks out too'

	# Its checksum is signed; still, only plain files and folders, inside it (MAINT-11): no link, no
	# hard link, no absolute path or "..", whatever the archive says
	tar -tvzf "$work/$archive" | cut -c1 | grep -qv '^[d-]$' && die "$archive has links in it: not installing."
	tar -tzf "$work/$archive" | grep -Eq '^/|(^|/)\.\.(/|$)' && die "$archive has paths outside itself: not installing."
	tar -xzf "$work/$archive" -C "$work/files" --strip-components=1 --no-same-owner
	from="$work/files"
}

install_files() {
	if [ -f "$root/server/server.env" ]; then
		say "Quire Server is installed in $root/server already: finishing what's left"
		# Its files as the release (or the last update) left them, before any of them runs as root
		# (SUPPLY-08): one changed since is said, not run
		if [ -f "$root/server/.installed-files" ]; then
			changed=$(cd "$root/server" && sha256sum -c --quiet .installed-files 2>/dev/null | sed 's/: .*//' | head -n 20)
			[ -z "$changed" ] || die "Files of the installed server changed since it was installed or updated: $(echo "$changed" | tr '\n' ' ')
       Not running them. If you changed them, put them back; to start from a clean copy of the release, move $root/server away (mv $root/server $root/server.old, keeping server.env, keys and domains) and run this again."
			note 'Its files are as installed'
		fi

		return
	fi

	# Not one an install cut short left half done (or someone else's): it's put in place whole, below
	if [ -e "$root/server" ]; then
		die "$root/server is there, but without server.env: an install was cut short before it finished, or the folder isn't Quire Server's. Move it away (mv $root/server $root/server.old) and run this again."
	fi

	fetch
	say "Putting the server in $root/server"
	# Built beside, then moved into place in one step: the folder is a whole release, or isn't there
	staging="$root/.server-installing"
	rm -rf "$staging"
	mkdir -p "$staging/domains" "$staging/keys" "$root/sites" "$root/backups"
	chmod 755 "$root" "$staging" "$staging/domains"
	# Sites' folders hold their secrets; backups hold their data; keys read Git mode sites' repositories.
	chmod 700 "$root/sites" "$root/backups" "$staging/keys"
	tar -C "$from" --exclude=./vendor --exclude=./var --exclude=./tests --exclude=./.git -cf - . | tar -C "$staging" -xf - --no-same-owner
	if [ "$from" != "$source" ]; then rm -rf "$work"; fi
	chown -R root:root "$staging"
	chmod -R go-w "$staging"
	# Each of the release's files and its checksum, as the updater writes them: checked when this runs again
	(cd "$staging" && find . -type f ! -name 'server.env*' ! -name .installed ! -name .installed-files ! -path './domains/*' ! -path './keys/*' -print0 | xargs -0 sha256sum | LC_ALL=C sort) >"$staging/.installed-files.new"
	mv "$staging/.installed-files.new" "$staging/.installed-files"
	chmod 600 "$staging/.installed-files"

	# server.env last: its presence says the files are in place.
	umask 077
	# Each made first, checked, then written (a failure in "$(…)" inside sed's arguments would go unseen)
	app_secret=$(random_hex 32)
	postgres_password=$(random_hex 24)
	database_password=$(random_hex 24)
	sed -e "s/^QUIRE_SERVER_APP_SECRET=.*/QUIRE_SERVER_APP_SECRET=$app_secret/" \
		-e "s/^QUIRE_SERVER_POSTGRES_PASSWORD=.*/QUIRE_SERVER_POSTGRES_PASSWORD=$postgres_password/" \
		-e "s/^QUIRE_SERVER_DATABASE_PASSWORD=.*/QUIRE_SERVER_DATABASE_PASSWORD=$database_password/" \
		-e "s/^QUIRE_SERVER_ACME_EMAIL=.*/QUIRE_SERVER_ACME_EMAIL=$email/" \
		-e "s#^QUIRE_SERVER_IMAGE=.*#QUIRE_SERVER_IMAGE=$image#" \
		-e "s#^COMPOSE_FILE=.*#COMPOSE_FILE=compose.yaml${source:+:compose.build.yaml}#" \
		"$staging/server.env.dist" >"$staging/server.env"
	if [ "$test_certificates" = 1 ]; then printf '\n# Tests: certificates from the front door'\''s own authority\nQUIRE_SERVER_TLS_GLOBAL=local_certs\n' >>"$staging/server.env"; fi
	if [ "$staging_certificates" = 1 ]; then printf '\n# Tests: certificates from Let'\''s Encrypt'\''s staging authority (browsers warn; real public names only)\nQUIRE_SERVER_TLS_GLOBAL='\''acme_ca https://acme-staging-v02.api.letsencrypt.org/directory'\''\n' >>"$staging/server.env"; fi
	# What was checked and installed (root only): the release, its archive's checksum and its image
	if [ -n "$image" ]; then printf 'VERSION=%s\nARCHIVE_SHA256=%s\nIMAGE=%s\n' "$version" "$digest" "$image" >"$staging/.installed"; fi
	chmod 600 "$staging/server.env"
	umask 022
	mv "$staging" "$root/server"

	# The command: the one of the release in the server's folder (an update brings its own), knowing
	# where the server is
	printf '#!/bin/sh\n# Quire Server: runs the command of the release installed in %s/server.\nQUIRE_SERVER_ROOT=%s exec sh %s/server/bin/quire-server "$@"\n' "$root" "$root" "$root" >/usr/local/bin/quire-server.tmp
	chmod 755 /usr/local/bin/quire-server.tmp
	mv /usr/local/bin/quire-server.tmp /usr/local/bin/quire-server
	note "Settings and secrets: $root/server/server.env (root only)"
	note 'Command: quire-server'
}

# The range sites' addresses come from (QUIRE_SERVER_SITE_NETWORKS, D-34): none this machine routes
# elsewhere. Docker doesn't check an explicit subnet against the host's routes, so sites with addresses
# a VPN or a cloud network also has would lose their traffic to it. Refused before any site has its
# addresses (sites keep the ones they have); reported after that, as "quire-server up" does.
check_site_range() {
	# A release from before sites' links has no range
	[ -f "$root/server/bin/site-range.sh" ] || return 0
	# shellcheck source=/dev/null
	. "$root/server/bin/site-range.sh"
	links=$(docker network ls --format '{{.Name}}' | grep '^quire-link-' || true)
	if [ -n "$site_networks" ]; then
		problem=$(site_range_problem "$site_networks")
		[ -z "$problem" ] || die "--site-networks: $problem"
	fi

	if [ -n "$site_networks" ] && [ "$site_networks" != "$(site_range "$root/server")" ]; then
		[ -z "$links" ] || die "Sites have their addresses in $(site_range "$root/server") already: they keep them, so --site-networks can't change it here. Set QUIRE_SERVER_SITE_NETWORKS in $root/server/server.env for sites added from then on (see \"Site networks\" in the README)."
		umask 077
		{ grep -v '^QUIRE_SERVER_SITE_NETWORKS=' "$root/server/server.env"; echo "QUIRE_SERVER_SITE_NETWORKS=$site_networks"; } >"$root/server/server.env.tmp"
		chmod 600 "$root/server/server.env.tmp"
		mv "$root/server/server.env.tmp" "$root/server/server.env"
		umask 022
	fi

	range=$(site_range "$root/server")
	problem=$(site_range_problem "$range")
	[ -z "$problem" ] || die "QUIRE_SERVER_SITE_NETWORKS in $root/server/server.env: $problem"
	overlaps=$(site_range_overlaps "$range")
	if [ -z "$overlaps" ]; then
		note "Sites' addresses: $range"

		return
	fi
	if [ -n "$links" ]; then
		site_range_warn "$root/server"

		return
	fi

	free=$(site_range_free)
	die "This machine routes part of the range sites' addresses come from ($range) elsewhere: $(printf '%s' "$overlaps" | tr '\n' ',' | sed 's/,/, /g'). Sites with addresses there would lose their traffic to it. Run the installer again with ${free:+--site-networks=$free, which nothing here routes, or }--site-networks=<another private range, /8 to /22>."
}

# PostgreSQL's connections, for this machine's memory (D-39): set in server.env, also on a machine
# that was resized since the install
size_database() {
	# A release from before D-39 has no sizing
	[ -f "$root/server/bin/database-connections.sh" ] || return 0
	# shellcheck source=/dev/null
	. "$root/server/bin/database-connections.sh"
	changed=$(database_connections_sync "$root/server")
	# Said only when a PostgreSQL runs with the old value
	if [ -n "$changed" ] && docker container inspect quire-server-database-1 >/dev/null 2>&1; then note "$changed"; fi
	note "PostgreSQL takes $(database_connections_set "$root/server") connections (sized from this machine's memory)"
}

start_core() {
	say 'Starting the server (its front door last)'
	if grep -q '^QUIRE_SERVER_IMAGE=.' "$root/server/server.env"; then
		note 'Getting its images'
		compose pull --quiet
	elif ! docker image inspect quire-server-agent >/dev/null 2>&1; then
		note 'Building the agent (a few minutes)'
		compose build --pull --quiet agent
	fi
	# On a server that has sites: this release's Docker gate must accept each site's scope before it
	# replaces the running one (a newer gate may refuse what an older one took). It only reads them.
	if docker volume inspect quire-server_gate_scopes >/dev/null 2>&1; then
		refused=$(compose run --rm --no-deps --quiet-pull -T gate check --config /etc/yorvogate/yorvogate.yaml 2>&1) \
			|| die "This release's Docker gate refuses a site's scope, so the server wasn't changed: $refused"
	fi
	# Every container and network created (sites join the front door's network before it starts),
	# nothing started that would take a port.
	compose create --quiet-pull >/dev/null
	compose up --detach --wait --wait-timeout 300 agent >/dev/null || die 'The server didn'\''t start: see "quire-server logs".'
	note 'PostgreSQL, the Docker gate and the agent run'
	builders=$(compose exec -T agent cat /run/quire-host/builder-apparmor 2>/dev/null || true)
	case "$builders" in
		yorvogate-buildkit-rootless) note 'Sites build rootless, under the AppArmor profile yorvogate-buildkit-rootless' ;;
		unconfined) note 'Sites build rootless (this AppArmor has no restriction on user namespaces to lift)' ;;
		*) note 'Sites build rootless (no AppArmor on this machine)' ;;
	esac
}

adopt_sites() {
	running=$(docker ps --filter label=com.docker.compose.service=php --format '{{.Label "com.docker.compose.project.working_dir"}}' | sort -u)
	[ -n "$running" ] || return 0

	# One folder a line (a folder's name may have spaces)
	previous_ifs=$IFS
	IFS='
'
	for directory in $running; do
		IFS=$previous_ifs
		case "$directory" in
			"$root/sites/"*) ;;
			*)
				if [ -f "$directory/compose.prod.yaml" ] && grep -q 'quire/' "$directory/composer.json" 2>/dev/null; then
					warn "A Quire site runs from $directory: to manage it with the server, stop it, move its folder to $root/sites/<name>, start it again and run this again."
				fi
				continue
				;;
		esac

		name=${directory#"$root/sites/"}
		if [ -f "$directory/compose.server.yaml" ] || [ ! -f "$directory/prod.env" ]; then continue; fi

		say "Found the site \"$name\" ($directory)"
		if ! ask "Manage \"$name\" with the server? It's backed up first, then a few seconds offline."; then
			note 'Left as it is.'
			continue
		fi

		quire-server site:adopt "$name" --no-interaction || die "Adopting \"$name\" failed and it runs as it was: see \"quire-server operations\"."
	done
	IFS=$previous_ifs
}

start_front() {
	if [ -n "$(compose ps --status running --quiet front 2>/dev/null)" ]; then
		note 'The front door runs'

		return
	fi

	# Who holds ports 80 and 443: another web server, or a container
	holders=''
	if command -v ss >/dev/null 2>&1; then
		# Docker's own docker-proxy publishes a container's port: the container is listed below instead
		holders=$(ss -Hltnup 2>/dev/null | awk '$5 ~ /:(80|443)$/ && $7 !~ /"docker-proxy"/ { print $5, $7 }')
	elif command -v netstat >/dev/null 2>&1; then
		holders=$(netstat -ltnup 2>/dev/null | awk '$4 ~ /:(80|443)$/ && $7 !~ /docker-proxy/ { print $4, $7 }')
	fi
	containers=$(docker ps --format 'the container {{.Names}}: {{.Ports}}' | grep -E ':(80|443)->' || true)
	if [ -n "$holders$containers" ]; then
		printf '%s\n%s\n' "$holders" "$containers" | sed '/^$/d; s/^/    /' >&2
		die 'Ports 80 and 443 are taken (above): the front door needs them. Stop what holds them (or move those sites behind the server), and run this again.'
	fi

	say 'Starting the front door'
	compose up --detach --wait --wait-timeout 120 >/dev/null || die 'The front door didn'\''t start: see "quire-server logs front".'
}

os_updates() {
	[ "$os_updates" = 1 ] || return 0
	command -v apt-get >/dev/null 2>&1 || return 0

	say "Turning on the operating system's automatic security updates"
	if ! dpkg-query -W -f='${Status}' unattended-upgrades 2>/dev/null | grep -q 'install ok installed'; then
		DEBIAN_FRONTEND=noninteractive apt-get install -qq -y unattended-upgrades >/dev/null
	fi
	if [ ! -f /etc/apt/apt.conf.d/20auto-upgrades ]; then
		printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' >/etc/apt/apt.conf.d/20auto-upgrades
	fi
	note 'Security updates install by themselves (restarts stay yours to plan)'
}

firewall() {
	if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
		ufw allow 80/tcp >/dev/null && ufw allow 443/tcp >/dev/null && ufw allow 443/udp >/dev/null
		note 'Firewall (ufw): 80/tcp, 443/tcp and 443/udp allowed'
	fi
}

main() {
	yes=0
	source=''
	version=$release
	email=''
	site_networks=''
	os_updates=1
	unsupported_os=0
	test_certificates=0
	staging_certificates=0

	for argument in "$@"; do
		case "$argument" in
			--yes | -y) yes=1 ;;
			--source=*) source=$(cd "${argument#--source=}" && pwd) || die "No folder \"${argument#--source=}\"." ;;
			--version=*) version=${argument#--version=} ;;
			--email=*) email=${argument#--email=} ;;
			--site-networks=*) site_networks=${argument#--site-networks=} ;;
			--no-os-updates) os_updates=0 ;;
			--unsupported-os) unsupported_os=1 ;;
			--test-certificates) test_certificates=1 ;;
			--staging-certificates) staging_certificates=1 ;;
			-h | --help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
			*) die "Unknown option: $argument (see --help)" ;;
		esac
	done

	# It's written into the quire-server command and Compose's paths: a plain absolute path only.
	if ! printf '%s\n' "$root" | grep -Eq '^/[A-Za-z0-9._/-]+$' || printf '%s\n' "$root" | grep -q '\.\.'; then
		die "\"$root\" (QUIRE_SERVER_ROOT) isn't a plain absolute path."
	fi

	if [ -n "$email" ]; then
		printf '%s\n' "$email" | grep -Eq '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' || die "\"$email\" isn't an email address."
	fi

	# Only its shape here (it's written into server.env); the rest once the files are in place
	if [ -n "$site_networks" ] && ! printf '%s\n' "$site_networks" | grep -Eq '^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'; then
		die "\"$site_networks\" (--site-networks) isn't a range (10.72.0.0/16, say)."
	fi

	# One installer at a time; one cut short (a dropped SSH connection) leaves its lock, taken over
	lock=/run/quire-server-install.lock
	if ! mkdir "$lock" 2>/dev/null; then
		holder=$(cat "$lock/pid" 2>/dev/null || true)
		if [ -n "$holder" ] && kill -0 "$holder" 2>/dev/null; then
			die "Another installation runs (process $holder): wait for it to finish."
		fi
		[ -n "$holder" ] || [ -d "$lock" ] || die "Couldn't take $lock."
		warn 'An installation was cut short before: going on from where it was.'
	fi
	echo $$ >"$lock/pid"
	trap 'rm -rf "$lock"' EXIT

	check_machine
	install_docker
	install_files
	check_site_range
	size_database
	start_core
	adopt_sites
	start_front
	firewall
	os_updates

	say 'Quire Server runs'
	note 'Add a site:     quire-server site:add jane --domain=jane.example --admin-email=jane@example.com'
	note 'Its sites:      quire-server site:list'
	note 'Everything:     quire-server help'
	note "Point each site's DNS at this machine: its certificate comes at the first visit."
	if [ -z "$email" ] && ! grep -q '^QUIRE_SERVER_ACME_EMAIL=.' "$root/server/server.env"; then
		note "Tip: set QUIRE_SERVER_ACME_EMAIL in $root/server/server.env to hear from Let's Encrypt, then \"quire-server up\"."
	fi
}

main "$@"
