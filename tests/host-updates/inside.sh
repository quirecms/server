#!/bin/sh
# One case of tests/host-updates/run.sh, inside a throwaway Ubuntu 26.04: fakes Docker's packages
# (DOCKER: 29, 30 or none) and the machine's unattended upgrades (UNATTENDED), runs the script, and
# checks what it printed, the files it wrote, and that it changed nothing else (EXPECT: the case).
set -eu

fail() { echo "$1" >&2; exit 1; }

# Docker's packages, as dpkg would answer (before anything else is timed)
mkdir -p /usr/local/sbin
case "$DOCKER" in
	29) engine='5:29.10.0-1~ubuntu.26.04~resolute' containerd='2.4.2-4~ubuntu.26.04~resolute' ;;
	30) engine='5:30.0.1-1~ubuntu.26.04~resolute' containerd='2.5.0-1~ubuntu.26.04~resolute' ;;
	*) engine='' containerd='' ;;
esac
cat >/usr/local/sbin/dpkg-query <<EOF
#!/bin/sh
for package; do :; done
case "\$package" in
	docker-ce | docker-ce-cli | docker-ce-rootless-extras) [ -n '$engine' ] && printf '%s' '$engine' || exit 1 ;;
	containerd.io) [ -n '$containerd' ] && printf '%s' '$containerd' || exit 1 ;;
	docker-buildx-plugin) [ -n '$engine' ] && printf '0.38.1-1' || exit 1 ;;
	docker-compose-plugin) [ -n '$engine' ] && printf '5.7.0-1' || exit 1 ;;
	*) exit 1 ;;
esac
EOF
chmod 755 /usr/local/sbin/dpkg-query
# systemd (SYSTEMD: ok, broken, or none, the image's: no systemctl), as host-setup's container meets
# it: its private socket refused (another PID namespace), D-Bus taken only with SYSTEMCTL_FORCE_BUS.
# What it has loaded is the timer's last OnCalendar, its drop-ins read in order, as at the last boot
# or reload (in /run, which the run's check leaves out).
if [ "${SYSTEMD:-none}" != none ]; then
	mkdir -p /run/fake-systemd
	cat >/usr/local/sbin/systemctl <<'FAKE'
#!/bin/sh
loaded=/run/fake-systemd/calendar
# Every OnCalendar after the last empty one (which clears those before), as systemd lists them
timer() {
	value=$( (echo 'OnCalendar=*-*-* 06:00:00'; cat /etc/systemd/system/apt-daily-upgrade.timer.d/*.conf 2>/dev/null) | sed -n 's/^OnCalendar=\(.*\)$/\1/p' \
		| awk '$0 == "" { n = 0; next } { list[n++] = $0 } END { for (i = 0; i < n; i++) printf "{ OnCalendar=%s ; next_elapse=Sat 2026-11-21 06:00:00 UTC } ", list[i] }' \
		| sed 's/\(OnCalendar=\*-\*-\* \)\([0-9]\):/\10\2:/g')
	printf '%s\n' "$value"
}
[ "${SYSTEMCTL_FORCE_BUS:-}" = 1 ] || { echo 'Failed to connect to system scope bus via local transport: No data available' >&2; exit 1; }
[ "$SYSTEMD" = ok ] || { echo 'Failed to connect to bus: No such file or directory' >&2; exit 1; }
case "$1" in
	boot) timer >"$loaded" ;;
	daemon-reload) echo reload >>/run/fake-systemd/reloads; timer >"$loaded" ;;
	show) cat "$loaded" ;;
	*) exit 1 ;;
esac
FAKE
	chmod 755 /usr/local/sbin/systemctl
fi
# The unattended-upgrades package's own file, as 26.04 ships it (2.12ubuntu9): its origins
cat >/etc/apt/apt.conf.d/50unattended-upgrades <<'STOCK'
Unattended-Upgrade::Allowed-Origins {
	"${distro_id}:${distro_codename}";
	"${distro_id}:${distro_codename}-security";
	"${distro_id}ESMApps:${distro_codename}-apps-security";
	"${distro_id}ESM:${distro_codename}-infra-security";
};
STOCK
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "%s";\n' "$UNATTENDED" >/etc/apt/apt.conf.d/20auto-upgrades

# What a machine may have before: an operator's own reboot and timing (DevOps's on fra1-web-01), and
# our files from an earlier start with other settings
operator_conf='Unattended-Upgrade::Automatic-Reboot "true";'
operator_timer='[Timer]
OnCalendar=
OnCalendar=*-*-* 05:30:00 UTC'
if [ "$EXPECT" = operator ] || [ "$EXPECT" = off ] || [ "$EXPECT" = overridden ]; then
	printf '%s\n' "$operator_conf" >/etc/apt/apt.conf.d/52yorvolabs-unattended-upgrades
	mkdir -p /etc/systemd/system/apt-daily-upgrade.timer.d
	printf '%s\n' "$operator_timer" >/etc/systemd/system/apt-daily-upgrade.timer.d/yorvolabs.conf
	QUIRE_SERVER_OS_UPDATE_HOUR=3 QUIRE_SERVER_REBOOT=on sh /opt/host-updates.sh >/dev/null
fi

if [ "$EXPECT" = again ]; then
	sh /opt/host-updates.sh >/dev/null
fi

# Values over several lines (an env file can't carry them): the first line alone looks valid
if [ "$EXPECT" = multiline ]; then
	QUIRE_SERVER_OS_UPDATE_HOUR=$(printf '5\nUnit=evil.service')
	QUIRE_SERVER_REBOOT=$(printf 'on\nExecStart=/bin/evil')
	export QUIRE_SERVER_OS_UPDATE_HOUR QUIRE_SERVER_REBOOT
fi

# An operator's timer, read after ours: one that replaces ours, or adds a time to it
if [ "$EXPECT" = timer-overridden ]; then
	mkdir -p /etc/systemd/system/apt-daily-upgrade.timer.d
	printf '%s\n' "$operator_timer" >/etc/systemd/system/apt-daily-upgrade.timer.d/yorvolabs.conf
fi
if [ "$EXPECT" = timer-added ]; then
	mkdir -p /etc/systemd/system/apt-daily-upgrade.timer.d
	printf '[Timer]\nOnCalendar=*-*-* 17:00:00 UTC\n' >/etc/systemd/system/apt-daily-upgrade.timer.d/yorvolabs.conf
fi
# What the machine started with: systemd's timer as its files were then
if [ "${SYSTEMD:-none}" = ok ]; then SYSTEMCTL_FORCE_BUS=1 systemctl boot; fi
# An earlier start wrote the hour, but couldn't tell systemd (0.6.0-rc.2's host-setup): it has the time of before
if [ "$EXPECT" = untold ]; then
	mkdir -p /etc/systemd/system/apt-daily-upgrade.timer.d
	printf '# Written by Quire Server (gate/host-updates.sh), from QUIRE_SERVER_OS_UPDATE_HOUR in server.env\n[Timer]\nOnCalendar=\nOnCalendar=*-*-* 4:30:00 UTC\nRandomizedDelaySec=15m\n' >/etc/systemd/system/apt-daily-upgrade.timer.d/quire-server.conf
fi
rm -f /run/fake-systemd/reloads

sleep 1.1
touch /tmp/before
sleep 1.1
facts=$(sh /opt/host-updates.sh) || fail "The script exited with $?."
changed=$(find / -xdev \( -path /proc -o -path /sys -o -path /dev -o -path /tmp -o -path /run -o -path /opt \) -prune -o -newer /tmp/before -print | sort)

origin=/etc/apt/apt.conf.d/52quire-server-docker
pin=/etc/apt/preferences.d/quire-server-docker
reboot=/etc/apt/apt.conf.d/52quire-server-reboot
timer=/etc/systemd/system/apt-daily-upgrade.timer.d/quire-server.conf

has() { printf '%s\n' "$facts" | grep -qxF "$1" || fail "It didn't print \"$1\": $facts"; }
only() {
	expected=$(printf '%s\n' "$@" | sort)
	[ "$changed" = "$expected" ] || fail "Changed on the machine: [$changed], expected: [$expected]"
}
absent() { for file; do [ ! -e "$file" ] || fail "$file is there."; done; }

# Whatever the case: never a #clear (an operator's origins stay), never a downgrade, and nothing at
# all outside these files
if [ -e "$origin" ]; then
	! grep -q '#clear' "$origin" || fail "$origin clears the origins."
	grep -qF '"origin=Docker,label=Docker CE,archive=${distro_codename},component=stable,site=download.docker.com";' "$origin" || fail "$origin doesn't name Docker's repository: $(cat "$origin")"
	! grep -q 'codename=' "$origin" || fail "$origin matches on codename, which Docker's Release file doesn't have."
	[ "$(apt-config dump | grep -c 'origin=Docker')" = 1 ] || fail 'apt doesn'"'"'t read the origin.'
	apt-config dump | grep -q '"${distro_id}:${distro_codename}-security"' || fail 'The stock origins are gone.'
fi
if [ -e "$pin" ]; then
	! grep -Eq 'Pin-Priority: (100[1-9]|10[1-9][0-9]|1[1-9][0-9]{2}|[2-9][0-9]{3})' "$pin" || fail "$pin would downgrade."
	[ "$(grep -c 'Pin-Priority: 990' "$pin")" = "$(grep -c '^Package:' "$pin")" ] || fail "$pin: every package at 990."
fi
[ ! -e /pwned ] || fail 'A setting ran as a command.'

case "$EXPECT" in
	new-install)
		has docker_updates=on
		has reboot=on
		has update_hour=4
		only /etc/apt/apt.conf.d "$origin" /etc/apt/preferences.d "$pin" "$reboot" /etc/systemd/system /etc/systemd/system/apt-daily-upgrade.timer.d "$timer"
		grep -qx 'Pin: version 5:29.\*' "$pin" || fail "$pin doesn't hold Docker to 29."
		grep -qx 'Package: docker-ce-rootless-extras' "$pin" || fail "$pin leaves out docker-ce-rootless-extras."
		grep -qx 'OnCalendar=\*-\*-\* 4:30:00 UTC' "$timer" || fail "$timer: $(cat "$timer")"
		grep -qx 'Unattended-Upgrade::Automatic-Reboot "true";' "$reboot" || fail "$reboot: $(cat "$reboot")"
		has effective_reboot=on
		has update_hour_applied=yes
		has 'effective_update_time=*-*-* 04:30:00 UTC'
		;;
	again)
		has docker_updates=on
		has update_hour_applied=yes
		[ ! -e /run/fake-systemd/reloads ] || fail 'systemd was told again, with nothing changed.'
		only
		;;
	unreachable)
		# The file is written; systemd has its time of before until the machine restarts: said so
		has update_hour=4
		has update_hour_reload=failed
		has update_hour_applied=no
		grep -qx 'OnCalendar=\*-\*-\* 4:30:00 UTC' "$timer" || fail "$timer: $(cat "$timer")"
		;;
	untold)
		# Nothing to write, but systemd still had the time of before: told now
		has update_hour_applied=yes
		has 'effective_update_time=*-*-* 04:30:00 UTC'
		[ "$(cat /run/fake-systemd/reloads)" = reload ] || fail 'systemd wasn'"'"'t told.'
		# Docker's files (a first run), not the timer's: it was as it should be
		only /etc/apt/apt.conf.d "$origin" /etc/apt/preferences.d "$pin"
		;;
	timer-added)
		has update_hour_applied=overridden
		has 'effective_update_time=*-*-* 04:30:00 UTC, *-*-* 17:00:00 UTC'
		;;
	timer-overridden)
		has update_hour=4
		has update_hour_applied=overridden
		has 'effective_update_time=*-*-* 05:30:00 UTC'
		;;
	operator)
		has docker_updates=on
		has reboot=machine
		has update_hour=machine
		absent "$reboot" "$timer"
		[ "$(cat /etc/apt/apt.conf.d/52yorvolabs-unattended-upgrades)" = "$operator_conf" ] || fail 'The operator'"'"'s file changed.'
		[ "$(cat /etc/systemd/system/apt-daily-upgrade.timer.d/yorvolabs.conf)" = "$operator_timer" ] || fail 'The operator'"'"'s timer changed.'
		only /etc/apt/apt.conf.d /etc/systemd/system/apt-daily-upgrade.timer.d
		has effective_reboot=on
		# Read after ours, the operator's reboot wins
		[ "$(apt-config dump | grep 'Automatic-Reboot "' | tail -1)" = 'Unattended-Upgrade::Automatic-Reboot "true";' ] || fail 'apt reads another reboot.'
		;;
	untested)
		has docker_updates=untested-major
		printf '%s\n' "$facts" | grep -q '^docker_untested=docker-ce=5:30' || fail "It didn't say which: $facts"
		absent "$origin" "$pin"
		only
		;;
	hostile)
		has update_hour=invalid
		has reboot=invalid
		absent "$timer" "$reboot"
		only /etc/apt/apt.conf.d "$origin" /etc/apt/preferences.d "$pin"
		;;
	multiline)
		has update_hour=invalid
		has reboot=invalid
		absent "$timer" "$reboot"
		! grep -rq 'evil' /etc || fail 'A later line of a value reached a file.'
		;;
	overridden)
		# server.env says off, the operator's file (read after ours) says on: what's shown is the machine's
		has reboot=off
		has effective_reboot=on
		;;
	off)
		has docker_updates=off
		has reboot=machine
		has update_hour=machine
		absent "$origin" "$pin" "$reboot" "$timer"
		[ "$(cat /etc/apt/apt.conf.d/52yorvolabs-unattended-upgrades)" = "$operator_conf" ] || fail 'The operator'"'"'s file changed.'
		;;
	os-off)
		has docker_updates=os-updates-off
		has effective_reboot=off
		has effective_update_time=unknown
		absent "$origin" "$pin"
		only
		;;
	not-from-apt)
		has docker_updates=not-from-apt
		absent "$origin" "$pin"
		only
		;;
	*) fail "No such case: $EXPECT" ;;
esac
