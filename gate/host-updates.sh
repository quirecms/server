#!/bin/sh
# The machine's automatic updates, as far as Quire Server is concerned (ARC-0061, D-50, D-51): Docker's
# packages get their updates like Ubuntu's, within the major versions this release is tested on, and
# the hour and the reboot the owner set in server.env. Run as root on the host, by the server's
# "host-setup" service (compose.yaml), after host-setup.sh, each time the server starts.
#
# It writes exactly these files, and nothing else (a test runs it and compares the machine before and
# after):
#   /etc/apt/apt.conf.d/52quire-server-docker      Docker's origin for unattended-upgrades
#   /etc/apt/preferences.d/quire-server-docker     Docker's packages held to the tested majors
#   /etc/apt/apt.conf.d/52quire-server-reboot      only with QUIRE_SERVER_REBOOT (on/off)
#   /etc/systemd/system/apt-daily-upgrade.timer.d/quire-server.conf
#                                                  only with QUIRE_SERVER_OS_UPDATE_HOUR (0-23)
# and removes them when what they're for no longer applies. QUIRE_SERVER_OS_UPDATES=off removes all
# four: the machine's updates are then left alone. A setting server.env doesn't have is left
# to the machine: an operator's own reboot and timing (e.g. a "52yorvolabs-…" file, read after ours)
# stay in charge. Origins are only ever added, never cleared.
#
# Docker's origin is written when the machine's unattended upgrades are on (APT::Periodic::
# Unattended-Upgrade "1"), Docker came from Docker's repository, and every installed package of it is
# within a tested major. Otherwise nothing of it is written, and the agent says why ("quire-server
# status"): an untested major isn't moved, held or downgraded.
#
# Prints what it found, one "key=value" a line, for the agent (/run/quire-host/host-updates). Always
# exits 0: the server starts whatever the machine's updates are.
set -u

# The majors this release is tested on: package, apt's version pattern. A release that's tested on a
# new major changes this list (and the agent's floor, src/Host/HostUpdates.php).
majors='docker-ce 5:29.*
docker-ce-cli 5:29.*
docker-ce-rootless-extras 5:29.*
containerd.io 2.*
docker-buildx-plugin 0.*
docker-compose-plugin 5.*'

origin_file=/etc/apt/apt.conf.d/52quire-server-docker
pin_file=/etc/apt/preferences.d/quire-server-docker
reboot_file=/etc/apt/apt.conf.d/52quire-server-reboot
timer_dir=/etc/systemd/system/apt-daily-upgrade.timer.d
timer_file=$timer_dir/quire-server.conf

say() { echo "$1=$2"; }

# systemd is told over D-Bus's system bus, not its private socket: host-setup's container has a PID
# namespace of its own, and systemd refuses the private socket to a process it can't see ("Failed to
# connect to system scope bus via local transport: No data available"); the bus takes it, as root's.
export SYSTEMCTL_FORCE_BUS=1

# The timer's time as systemd has it now ("*-*-* 04:30:00 UTC"), empty when systemd doesn't say
calendar() {
	systemctl show -p TimersCalendar --value apt-daily-upgrade.timer 2>/dev/null | sed -n 's/.*OnCalendar=\([^;]*\) ;.*/\1/p' | head -1
}

# Writes a file whole, only when its content changes (a cut-short write never leaves half of one).
# Prints "changed" or "same".
put() {
	file=$1 content=$2
	# Both through $(…), which drops trailing newlines alike
	if [ -f "$file" ] && [ "$(cat "$file")" = "$(printf '%s' "$content")" ]; then echo same; return 0; fi
	mkdir -p "$(dirname "$file")"
	temporary=$(mktemp "$(dirname "$file")/.quire-server.XXXXXX") || return 1
	if ! { printf '%s\n' "$content" >"$temporary" && chmod 644 "$temporary" && mv "$temporary" "$file"; }; then
		rm -f "$temporary"
		return 1
	fi
	echo changed
}

drop() { [ ! -e "$1" ] || rm -f "$1"; }

# What the machine does in the end, whoever's file says it (an operator's, read after ours, wins):
# apt's own reading of every file, and the timer as systemd has it. What "quire-server status" shows.
effective() {
	reboot_now=''
	eval "$(apt-config shell reboot_now Unattended-Upgrade::Automatic-Reboot)"
	case "$reboot_now" in true | 1 | yes) say effective_reboot on ;; *) say effective_reboot off ;; esac
	now=$(calendar)
	say effective_update_time "${now:-unknown}"
}

if ! command -v dpkg-query >/dev/null 2>&1 || ! command -v apt-config >/dev/null 2>&1; then
	say docker_updates no-apt
	exit 0
fi

# 1. Docker's packages
docker_updates=on
if [ "${QUIRE_SERVER_OS_UPDATES:-}" = off ]; then
	docker_updates=off
elif [ -z "$(dpkg-query -W -f='${Version}' docker-ce 2>/dev/null)" ]; then
	docker_updates=not-from-apt
else
	unattended=''
	eval "$(apt-config shell unattended APT::Periodic::Unattended-Upgrade)"
	[ "$unattended" = 1 ] || docker_updates=os-updates-off
fi

pins='' untested=''
if [ "$docker_updates" = on ]; then
	while read -r package pattern; do
		version=$(dpkg-query -W -f='${Version}' "$package" 2>/dev/null || true)
		[ -n "$version" ] || continue
		# shellcheck disable=SC2254 # the pattern is apt's glob, matched as one
		case "$version" in
			$pattern) ;;
			*) untested="$untested $package=$version" ;;
		esac
		pins="${pins}Package: $package
Pin: version $pattern
Pin-Priority: 990

"
	done <<EOF
$majors
EOF
	[ -z "$untested" ] || docker_updates='untested-major'
fi

if [ "$docker_updates" = on ]; then
	# 990, not 1001: above 1000 apt would install the pinned version even as a downgrade
	if put "$pin_file" "# Written by Quire Server (gate/host-updates.sh): Docker's packages stay within the majors
# this release is tested on; a release tested on a newer one moves them.

${pins%?}" >/dev/null && put "$origin_file" '// Written by Quire Server (gate/host-updates.sh): Docker'"'"'s packages get their updates
// like Ubuntu'"'"'s, within the majors /etc/apt/preferences.d/quire-server-docker allows. Only adds an
// origin: the stock ones stay.
Unattended-Upgrade::Origins-Pattern {
	"origin=Docker,label=Docker CE,archive=${distro_codename},component=stable,site=download.docker.com";
};' >/dev/null; then
		:
	else
		docker_updates=failed
	fi
fi
if [ "$docker_updates" != on ]; then
	drop "$origin_file"
	drop "$pin_file"
fi
say docker_updates "$docker_updates"
[ -z "$untested" ] || say docker_untested "${untested# }"

# QUIRE_SERVER_OS_UPDATES=off (--no-os-updates): the machine's updates are left alone, all of them
if [ "$docker_updates" = off ]; then
	drop "$reboot_file"
	[ ! -e "$timer_file" ] || { rm -f "$timer_file"; systemctl daemon-reload >/dev/null 2>&1 || true; }
	say reboot machine
	say update_hour machine
	effective
	exit 0
fi

# 2. The reboot, only as server.env says
case "${QUIRE_SERVER_REBOOT-unset}" in
	unset | '')
		drop "$reboot_file"
		say reboot machine
		;;
	on | off)
		[ "$QUIRE_SERVER_REBOOT" = on ] && value=true || value=false
		if put "$reboot_file" "// Written by Quire Server (gate/host-updates.sh), from QUIRE_SERVER_REBOOT in server.env:
// the machine restarts by itself, right after the updates, when one needs it (a new kernel).
Unattended-Upgrade::Automatic-Reboot \"$value\";
Unattended-Upgrade::Automatic-Reboot-WithUsers \"true\";
Unattended-Upgrade::Automatic-Reboot-Time \"now\";" >/dev/null; then
			say reboot "$QUIRE_SERVER_REBOOT"
		else
			say reboot failed
		fi
		;;
	*)
		# Not one of the two: nothing is written from it, and what was there stays
		say reboot invalid
		;;
esac

# 3. The hour, only as server.env says
hour=${QUIRE_SERVER_OS_UPDATE_HOUR-}
changed='' expected=''
if [ -z "$hour" ]; then
	[ ! -e "$timer_file" ] || { rm -f "$timer_file"; changed=1; }
	say update_hour machine
# Matched whole, as one value: grep would take a first line "5" and let the next ones into the file
elif case "$hour" in [0-9] | 1[0-9] | 2[0-3]) true ;; *) false ;; esac then
	if result=$(put "$timer_file" "# Written by Quire Server (gate/host-updates.sh), from QUIRE_SERVER_OS_UPDATE_HOUR in server.env
[Timer]
OnCalendar=
OnCalendar=*-*-* $hour:30:00 UTC
RandomizedDelaySec=15m"); then
		[ "$result" = same ] || changed=1
		expected="*-*-* $(printf '%02d' "$hour"):30:00 UTC"
		say update_hour "$hour"
	else
		say update_hour failed
	fi
else
	say update_hour invalid
fi
# systemd reads the file again when it changed, or when it still has another time (an earlier start
# couldn't tell it). Then what it has is what's said: the hour applies now, only after the machine's
# next restart (systemd couldn't be told), or never (an operator's timer, read after ours, wins).
reloaded=yes
if [ -n "$changed" ] || { [ -n "$expected" ] && [ "$(calendar)" != "$expected" ]; }; then
	systemctl daemon-reload >/dev/null 2>&1 || { reloaded=no; say update_hour_reload failed; }
fi
if [ -n "$expected" ]; then
	now=$(calendar)
	if [ "$now" = "$expected" ]; then
		say update_hour_applied yes
	elif [ "$reloaded" = no ] || [ -z "$now" ]; then
		say update_hour_applied no
	else
		say update_hour_applied overridden
	fi
fi
effective
exit 0
