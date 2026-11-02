#!/bin/sh
# What the host must have for sites' builds: the AppArmor profile their rootless BuildKit runs under.
# Run as root on the host, by the server's "host-setup" service (compose.yaml) each time the server
# starts: in a container of its own, it runs this script in the host's root ("chroot /host"), with
# the host's own apparmor_parser.
#
# Rootless BuildKit makes a user namespace. Ubuntu 23.10 and later restrict that to programs whose
# AppArmor profile allows it (kernel.apparmor_restrict_unprivileged_userns=1), and the builders are
# never privileged: this profile grants them only "userns". Turning the restriction off
# (sysctl ...=0) would lift it for every process on the host, so it isn't done.
#
# Prints one word on its standard output, the AppArmor profile sites' builders run under:
#   yorvogate-buildkit-rootless  the profile is loaded (AppArmor 4 or later), by this script or
#                                already (AppArmor's profiles are the kernel's: in a container, e.g.
#                                Docker-in-Docker, the machine's own may have loaded it)
#   unconfined                   AppArmor older than 4: it can't write "userns", and has no such
#                                restriction to lift (Docker's default profile would stop BuildKit)
#   none                         the host has no AppArmor (no option is given)
# and exits with an error when AppArmor is there but the profile can't be loaded. Idempotent.
set -eu

name=yorvogate-buildkit-rootless
profile=/etc/apparmor.d/$name
loaded=/sys/kernel/security/apparmor/profiles

# Docker's own test: AppArmor's file system is there, and the module is on.
if [ ! -d /sys/kernel/security/apparmor ] || [ "$(cat /sys/module/apparmor/parameters/enabled 2>/dev/null)" != Y ]; then
	echo 'AppArmor is off on this host: builders run without a profile.' >&2
	echo none
	exit 0
fi

parser=$(command -v apparmor_parser || true)
if [ -z "$parser" ]; then
	if grep -q "^$name " "$loaded" 2>/dev/null; then
		echo "AppArmor: the profile $name is loaded already (no apparmor_parser here)." >&2
		echo "$name"
		exit 0
	fi
	echo "AppArmor is on, but apparmor_parser is missing and the profile $name isn't loaded: install the \"apparmor\" package." >&2
	exit 1
fi

if [ ! -f /etc/apparmor.d/abi/4.0 ]; then
	echo 'AppArmor is older than 4.0 here (no user namespace restriction): builders run unconfined.' >&2
	echo unconfined
	exit 0
fi

# Written whole, then moved in place: a cut-short write never leaves half a profile for the boot.
temporary=$(mktemp "/etc/apparmor.d/.$name.XXXXXX")
cat >"$temporary" <<'EOF'
# Quire Server: sites' rootless BuildKit builders (YorvoGate's profile). It grants only what the
# kernel restricts for unprivileged processes: creating a user namespace. Managed by Quire Server.
abi <abi/4.0>,
include <tunables/global>

profile yorvogate-buildkit-rootless flags=(unconfined) {
  userns,
}
EOF
chmod 644 "$temporary"
if cmp -s "$temporary" "$profile" 2>/dev/null; then rm -f "$temporary"; else mv "$temporary" "$profile"; fi

# Loaded now (and again by AppArmor at each boot, from /etc/apparmor.d)
"$parser" -r "$profile" || { echo "apparmor_parser couldn't load $profile." >&2; exit 1; }
grep -q "^$name " "$loaded" || { echo "The profile $name isn't loaded after apparmor_parser." >&2; exit 1; }
echo "AppArmor: the profile $name is loaded." >&2
echo "$name"
