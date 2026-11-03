# shellcheck shell=sh
# Sites' addresses (QUIRE_SERVER_SITE_NETWORKS, D-34) against this machine's own routes. Sourced by
# the "quire-server" command (and the installer). Docker doesn't check an explicit subnet against the
# host's routes: a VPN or a cloud network on the range, added after the install, would take the
# traffic of sites with addresses in it, silently.

# The range, as server.env sets it (in the server's folder given), or the default
site_range() {
	range=$(sed -n "s/^QUIRE_SERVER_SITE_NETWORKS=//p" "$1/server.env" 2>/dev/null | tail -n 1 | tr -d "'\" ")
	echo "${range:-10.72.0.0/16}"
}

# Why the range isn't one the agent takes (SiteNetworkRange: a private IPv4 network, /8 to /22, given
# by its own address), on one line; nothing when it is
site_range_problem() {
	printf '%s\n' "$1" | awk '
		function private(a, p) {
			return (a[1] == 10 && p >= 8) || (a[1] == 172 && a[2] >= 16 && a[2] <= 31 && p >= 12) || (a[1] == 192 && a[2] == 168 && p >= 16)
		}
		{
			# Spaces around it are no part of it, for the agent too
			gsub(/^[ \t]+|[ \t]+$/, "")
			if ($0 !~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/[0-9]+$/ || $0 ~ /(^|\.)0[0-9]/) { print "\"" $0 "\" isn'\''t a range (10.72.0.0/16, say)."; exit }
			split($0, r, "/"); split(r[1], a, "."); p = r[2] + 0
			if (a[1] > 255 || a[2] > 255 || a[3] > 255 || a[4] > 255 || p > 32 || length(r[2]) > 2 || length(a[1]) > 3 || length(a[2]) > 3 || length(a[3]) > 3 || length(a[4]) > 3) { print "\"" $0 "\" isn'\''t a range (10.72.0.0/16, say)."; exit }
			address = ((a[1] * 256 + a[2]) * 256 + a[3]) * 256 + a[4]
			if (address % 2 ^ (32 - p) != 0) { print $0 " isn'\''t a network'\''s own address."; exit }
			if (!private(a, p)) { print $0 " isn'\''t a private range (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16)."; exit }
			if (p < 8 || p > 22) { print $0 " is a /" p "; a range from /8 to /22 is needed (a /16 holds 1,024 sites)."; exit }
		}'
}

# The machine's routes that overlap the range, one per line ("10.72.4.0/24 (wg0)"); nothing when none
# do. Docker's own bridges are left out: sites' networks are in the range by design. So are the
# default route and routes that aren't unicast (the kernel's local and broadcast ones).
site_range_overlaps() {
	case "$1" in
		*[!0-9./]* | '') return 0 ;;
	esac
	command -v ip >/dev/null 2>&1 || return 0
	# Each Docker bridge network's interface: br-<its ID's first 12 characters>, or the name it was given
	bridges=$(docker network ls --quiet --no-trunc --filter driver=bridge 2>/dev/null \
		| xargs -r docker network inspect --format '{{.Id}} {{with index .Options "com.docker.network.bridge.name"}}{{.}}{{end}}' 2>/dev/null \
		| awk '{ print ($2 != "" ? $2 : "br-" substr($1, 1, 12)) }' | tr '\n' ' ')
	# Every table's (a VPN's policy routes too); busybox's ip lists only the main one
	{ ip -4 route show table all type unicast 2>/dev/null || ip -4 route show 2>/dev/null; } | awk -v range="$1" -v bridges=" $bridges" '
		function address(text,  part) {
			split(text, part, ".")
			return ((part[1] * 256 + part[2]) * 256 + part[3]) * 256 + part[4]
		}
		BEGIN { split(range, r, "/"); first = address(r[1]); end = first + 2 ^ (32 - r[2]) }
		$1 !~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/[0-9]+)?$/ { next }
		{
			device = "?"
			for (i = 2; i < NF; i++) if ($i == "dev") device = $(i + 1)
			if (index(bridges, " " device " ")) next
			split($1, n, "/")
			start = address(n[1])
			stop = start + 2 ^ (32 - (2 in n ? n[2] : 32))
			if (start < end && first < stop) print $1 " (" device ")"
		}' | sort -u
}

# Said on standard error, with what to do, when any route overlaps the server's range
site_range_warn() {
	range=$(site_range "$1")
	overlaps=$(site_range_overlaps "$range")
	[ -n "$overlaps" ] || return 0
	{
		echo "This machine routes part of the sites' addresses ($range, QUIRE_SERVER_SITE_NETWORKS) elsewhere:"
		printf '%s\n' "$overlaps" | sed 's/^/  /'
		echo 'That network and sites with addresses there get in each other'\''s way. Move it (a VPN, a cloud network) off the range: sites keep the addresses they have, so a new QUIRE_SERVER_SITE_NETWORKS would only help sites added after it.'
	} >&2
}

# A /16 of 10.64.0.0-10.95.255.255, the stretch clouds, VPNs and container tools leave alone (the
# README's "Site networks"), that none of this machine's routes overlaps: the default first. Nothing
# when none is free.
site_range_free() {
	for second in $(seq 72 95) $(seq 64 71); do
		if [ -z "$(site_range_overlaps "10.$second.0.0/16")" ]; then
			echo "10.$second.0.0/16"

			return 0
		fi
	done
}
