#!/bin/sh
# Installs Quire Server on this machine: many Quire sites behind one front door. As root:
#
#   sh install.sh [--email=you@example.com] [--yes]
#
# What it does, in order (each step is safe to run again: run it again to finish an interrupted
# install, and the secrets it made are kept):
#
#   1. Checks the machine; installs Docker from Docker's own repository when it's missing (asks first).
#   2. Puts the server in /srv/quire/server, its settings and new secrets in server.env (root only),
#      and the "quire-server" command in /usr/local/bin.
#   3. Builds the agent and starts the server without its front door: PostgreSQL, docker-proxy, agent.
#   4. Adopts the Quire sites already running from /srv/quire/sites (asks first): they're backed up
#      and moved behind the front door, a few seconds offline each.
#   5. Starts the front door, once nothing else holds ports 80 and 443. It never stops or removes
#      anything it didn't start: if another web server holds them, it says which and stops there.
#   6. Turns on the operating system's automatic security updates (Ubuntu and Debian).
#
# Options:
#   --email=ADDRESS    Let's Encrypt writes here about certificates (expiry, problems)
#   --yes              Answer yes to every question (installing Docker, adopting sites)
#   --source=DIR       Install from this folder (a checkout of quirecms/server) instead of downloading a release
#   --version=X.Y.Z    The release to download (this installer's own release by default)
#   --no-os-updates    Leave the operating system's updates as they are
#   --test-certificates  Certificates from the front door's own authority, not Let's Encrypt (tests)
#
# The whole script is a function called on its last line: a download cut short runs nothing.
set -eu

# Set by each release to its own version; empty until the first one.
release=''

root=${QUIRE_SERVER_ROOT:-/srv/quire}
docker_min=29.0.0
compose_min=2.24.4
# Docker's release signing key (https://docs.docker.com/engine/install/ubuntu/)
docker_key_fingerprint=9DC858229FC7DD38854AE2D88D81803C0EBFCD88

say() { printf '\033[1m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '\033[33mWarning:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# A yes/no question on the terminal (also when this script comes through a pipe); "yes" with --yes.
# Without --yes and without a terminal, the answer is no: nothing is done unasked.
ask() {
	if [ "$yes" = 1 ]; then return 0; fi
	if ! { : </dev/tty; } 2>/dev/null; then return 1; fi
	printf '%s [y/N] ' "$1" >/dev/tty
	read -r answer </dev/tty || return 1
	case "$answer" in [yY] | [yY][eE][sS]) return 0 ;; *) return 1 ;; esac
}

# Whether version $2 is at least $1 (dotted numbers; anything after them is ignored)
at_least() {
	printf '%s\n%s\n' "$1" "$2" | sed 's/^v//; s/[^0-9.].*//' | sort -c -t. -k1,1n -k2,2n -k3,3n 2>/dev/null
}

compose() {
	docker compose --project-directory "$root/server" --env-file "$root/server/server.env" "$@"
}

random_hex() {
	od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'
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
	note "Linux $(uname -m), ${memory} MB of memory, ${space} MB free on $root"
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
	case "${ID:-}" in
		ubuntu | debian) ;;
		*) die "Docker is missing: install Docker Engine for ${PRETTY_NAME:-this system} (https://docs.docker.com/engine/install/), and run this again." ;;
	esac
	ask "Docker isn't installed. Install it from Docker's repository (download.docker.com)?" || die 'Docker is needed: install it, or answer yes.'

	say 'Installing Docker'
	export DEBIAN_FRONTEND=noninteractive
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

# The server's files, from --source or a release, in a folder of its own: printed
fetch() {
	if [ -n "$source" ]; then
		[ -f "$source/compose.yaml" ] && [ -f "$source/bin/quire-server" ] || die "\"$source\" isn't Quire Server's folder (no compose.yaml, bin/quire-server)."
		printf '%s\n' "$source"

		return
	fi

	[ -n "$version" ] || die 'There is no release of Quire Server yet: install from a checkout of quirecms/server with --source=<its folder>.'
	printf '%s\n' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$' || die "\"$version\" isn't a version."
	download=$(mktemp -d)
	curl -fsSL --proto '=https' --tlsv1.2 "https://github.com/quirecms/server/archive/refs/tags/v$version.tar.gz" | tar -xz -C "$download" --strip-components=1 --no-same-owner
	printf '%s\n' "$download"
}

install_files() {
	if [ -f "$root/server/server.env" ]; then
		say "Quire Server is installed in $root/server already: finishing what's left"

		return
	fi

	say "Putting the server in $root/server"
	from=$(fetch)
	mkdir -p "$root/server/domains" "$root/server/keys" "$root/sites" "$root/backups"
	chmod 755 "$root" "$root/server" "$root/server/domains"
	# Sites' folders hold their secrets; backups hold their data; keys read Git mode sites' repositories.
	chmod 700 "$root/sites" "$root/backups" "$root/server/keys"
	tar -C "$from" --exclude=./vendor --exclude=./var --exclude=./tests --exclude=./.git -cf - . | tar -C "$root/server" -xf - --no-same-owner
	if [ "$from" != "$source" ]; then rm -rf "$from"; fi
	chown -R root:root "$root/server"
	chmod -R go-w "$root/server"

	# server.env last: its presence says the files are in place.
	umask 077
	sed -e "s/^QUIRE_SERVER_APP_SECRET=.*/QUIRE_SERVER_APP_SECRET=$(random_hex 32)/" \
		-e "s/^QUIRE_SERVER_POSTGRES_PASSWORD=.*/QUIRE_SERVER_POSTGRES_PASSWORD=$(random_hex 24)/" \
		-e "s/^QUIRE_SERVER_DATABASE_PASSWORD=.*/QUIRE_SERVER_DATABASE_PASSWORD=$(random_hex 24)/" \
		-e "s/^QUIRE_SERVER_ACME_EMAIL=.*/QUIRE_SERVER_ACME_EMAIL=$email/" \
		"$root/server/server.env.dist" >"$root/server/server.env.tmp"
	if [ "$test_certificates" = 1 ]; then printf '\n# Tests: certificates from the front door'\''s own authority\nQUIRE_SERVER_TLS_GLOBAL=local_certs\n' >>"$root/server/server.env.tmp"; fi
	chmod 600 "$root/server/server.env.tmp"
	mv "$root/server/server.env.tmp" "$root/server/server.env"
	umask 022

	# The command, knowing where the server is
	sed "s#^root=\${QUIRE_SERVER_ROOT:-/srv/quire}#root=\${QUIRE_SERVER_ROOT:-$root}#" "$root/server/bin/quire-server" >/usr/local/bin/quire-server.tmp
	chmod 755 /usr/local/bin/quire-server.tmp
	mv /usr/local/bin/quire-server.tmp /usr/local/bin/quire-server
	note "Settings and secrets: $root/server/server.env (root only)"
	note 'Command: quire-server'
}

start_core() {
	say 'Starting the server (its front door last)'
	if ! docker image inspect quire-server-agent >/dev/null 2>&1; then
		note 'Building the agent (a few minutes)'
		compose build --pull --quiet agent
	fi
	# Every container and network created (sites join the front door's network before it starts),
	# nothing started that would take a port.
	compose create --quiet-pull >/dev/null
	compose up --detach --wait --wait-timeout 300 agent >/dev/null || die 'The server didn'\''t start: see "quire-server logs".'
	note 'PostgreSQL, docker-proxy and the agent run'
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
		holders=$(ss -Hltnup 2>/dev/null | awk '$5 ~ /:(80|443)$/ { print $5, $7 }')
	elif command -v netstat >/dev/null 2>&1; then
		holders=$(netstat -ltnup 2>/dev/null | awk '$4 ~ /:(80|443)$/ { print $4, $7 }')
	fi
	containers=$(docker ps --format '{{.Names}} {{.Ports}}' | grep -E ':(80|443)->' || true)
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
	os_updates=1
	test_certificates=0

	for argument in "$@"; do
		case "$argument" in
			--yes | -y) yes=1 ;;
			--source=*) source=$(cd "${argument#--source=}" && pwd) || die "No folder \"${argument#--source=}\"." ;;
			--version=*) version=${argument#--version=} ;;
			--email=*) email=${argument#--email=} ;;
			--no-os-updates) os_updates=0 ;;
			--test-certificates) test_certificates=1 ;;
			-h | --help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
			*) die "Unknown option: $argument (see --help)" ;;
		esac
	done

	if [ -n "$email" ]; then
		printf '%s\n' "$email" | grep -Eq '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' || die "\"$email\" isn't an email address."
	fi

	# One installer at a time
	mkdir /run/quire-server-install.lock 2>/dev/null || die 'Another installation runs (or one was cut short: remove /run/quire-server-install.lock).'
	trap 'rmdir /run/quire-server-install.lock' EXIT

	check_machine
	install_docker
	install_files
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
