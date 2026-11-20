#!/bin/sh
# gate/host-updates.sh on a throwaway Ubuntu 26.04 (ARC-0061): what it writes, byte for byte, and
# that it writes nothing else. Each case runs the script in a fresh container, where dpkg-query
# answers with the case's Docker versions, then lists every path on the machine changed since the run
# started and compares it with the files the script may write. Run by CI (Shell scripts); needs Docker.
#   sh tests/host-updates/run.sh
set -eu

here=$(cd "$(dirname "$0")" && pwd)
root=$(cd "$here/../.." && pwd)
image=ubuntu:26.04@sha256:d66b366dac61b575bee2aef07e9c26e8070096fbe84a3d4d3eb0da069b5d76db
failed=0

# One case: its name, then "KEY=value" for the script's environment and the fakes
case_() {
	name=$1
	shift
	envs=$(mktemp)
	printf '%s\n' "$@" >"$envs"
	if output=$(docker run --rm --network none --env-file "$envs" \
		-v "$root/gate/host-updates.sh:/opt/host-updates.sh:ro" -v "$here/inside.sh:/opt/inside.sh:ro" \
		"$image" sh /opt/inside.sh 2>&1); then
		echo "  ok   $name"
	else
		echo "  FAIL $name"
		echo "$output" | sed 's/^/       /'
		failed=1
	fi
	rm -f "$envs"
}

echo '== gate/host-updates.sh on Ubuntu 26.04'
case_ 'a new install: Docker 29, hour and reboot set, systemd told' DOCKER=29 UNATTENDED=1 SYSTEMD=ok QUIRE_SERVER_OS_UPDATE_HOUR=4 QUIRE_SERVER_REBOOT=on EXPECT=new-install
case_ 'run again: nothing changes, systemd isn'"'"'t told again' DOCKER=29 UNATTENDED=1 SYSTEMD=ok QUIRE_SERVER_OS_UPDATE_HOUR=4 QUIRE_SERVER_REBOOT=on EXPECT=again
case_ 'systemd can'"'"'t be told: the hour applies after a restart, said so' DOCKER=29 UNATTENDED=1 SYSTEMD=broken QUIRE_SERVER_OS_UPDATE_HOUR=4 EXPECT=unreachable
case_ 'an earlier start couldn'"'"'t tell systemd: told now' DOCKER=29 UNATTENDED=1 SYSTEMD=ok QUIRE_SERVER_OS_UPDATE_HOUR=4 EXPECT=untold
case_ 'an operator'"'"'s timer, read after ours, wins: said so' DOCKER=29 UNATTENDED=1 SYSTEMD=ok QUIRE_SERVER_OS_UPDATE_HOUR=4 EXPECT=timer-overridden
case_ 'an operator'"'"'s timer adds a time to ours: not said to be ours alone' DOCKER=29 UNATTENDED=1 SYSTEMD=ok QUIRE_SERVER_OS_UPDATE_HOUR=4 EXPECT=timer-added
case_ 'an upgraded server without the settings: only Docker, the operator keeps reboot and timing' DOCKER=29 UNATTENDED=1 EXPECT=operator
case_ 'an operator'"'"'s later file overrides QUIRE_SERVER_REBOOT: the machine'"'"'s value is said' DOCKER=29 UNATTENDED=1 QUIRE_SERVER_REBOOT=off EXPECT=overridden
case_ 'Docker 30 installed: nothing of Docker written, said why' DOCKER=30 UNATTENDED=1 EXPECT=untested
case_ 'hostile values: nothing written from them' DOCKER=29 UNATTENDED=1 'QUIRE_SERVER_OS_UPDATE_HOUR=4;touch /pwned' 'QUIRE_SERVER_REBOOT=on"' EXPECT=hostile
case_ 'values over several lines: refused whole, no line of them written' DOCKER=29 UNATTENDED=1 EXPECT=multiline
case_ 'QUIRE_SERVER_OS_UPDATES=off: our files removed, a reboot setting too' DOCKER=29 UNATTENDED=1 QUIRE_SERVER_OS_UPDATES=off QUIRE_SERVER_REBOOT=on QUIRE_SERVER_OS_UPDATE_HOUR=4 EXPECT=off
case_ 'the machine'"'"'s unattended upgrades are off: Docker left alone' DOCKER=29 UNATTENDED=0 EXPECT=os-off
case_ 'Docker not from apt: left alone' DOCKER=none UNATTENDED=1 EXPECT=not-from-apt

[ "$failed" = 0 ] || { echo 'Some cases failed.'; exit 1; }
