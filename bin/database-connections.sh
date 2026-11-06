# shellcheck shell=sh
# How many connections the server's PostgreSQL takes (QUIRE_SERVER_DATABASE_CONNECTIONS, D-39): sized
# from this machine's memory, as many sites as it holds need them. Sourced by the "quire-server"
# command and the installer, which keep server.env's value in step with the machine.
#
# A site takes about 200 MB of memory, and holds 1 connection for good (its job worker) and more while
# visitors come (one per PHP thread, 4 at most, closed after 11 idle minutes): 3 a site, with 50 for
# the server and console commands. Never fewer than 200 (what a small machine had), never more than
# 1,000 (each connection is a process: more wants a pooler).

# The value for this machine (its memory from /proc/meminfo, or the file given)
database_connections() {
	awk '
		/^MemTotal:/ {
			sites = int(($2 / 1024 - 2048) / 200)
			if (sites < 0) sites = 0
			n = 50 + 3 * sites
			if (n < 200) n = 200
			if (n > 1000) n = 1000
			print n
			found = 1
			exit
		}
		END { if (!found) print 200 }' "${1:-/proc/meminfo}"
}

# The value server.env sets (in the server's folder given), or nothing
database_connections_set() {
	sed -n "s/^QUIRE_SERVER_DATABASE_CONNECTIONS=//p" "$1/server.env" 2>/dev/null | tail -n 1 | tr -d "'\" "
}

# Puts this machine's value in server.env when it isn't there yet (said on stdout when it changes one
# that was: PostgreSQL restarts with it, every site offline a few seconds)
database_connections_sync() {
	wanted=$(database_connections "${2:-/proc/meminfo}")
	set_now=$(database_connections_set "$1")
	[ "$set_now" = "$wanted" ] && return 0
	(
		umask 077
		{ grep -v '^QUIRE_SERVER_DATABASE_CONNECTIONS=' "$1/server.env"; echo "QUIRE_SERVER_DATABASE_CONNECTIONS=$wanted"; } >"$1/server.env.tmp"
		chmod 600 "$1/server.env.tmp"
		mv "$1/server.env.tmp" "$1/server.env"
	)
	# Without one, it took compose.yaml's default
	if [ "${set_now:-200}" != "$wanted" ]; then
		echo "PostgreSQL takes $wanted connections from now on, not ${set_now:-200} (sized from this machine's memory): it starts again, every site offline for a few seconds."
	fi
}
