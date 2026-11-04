#!/bin/sh
# Quire Server end to end, in a throwaway Docker-in-Docker container: a site installed by hand, then
# the server installed with install.sh (which adopts it), a real site from Packagist added and
# removed with quire-server, the front door's routing and refusals, the sites' isolation. Nothing touches the machine's own Docker but that one container.
#
#   tests/e2e/run.sh [agent-image]
#   KEEP=1 tests/e2e/run.sh …          keeps the machine and the sites afterwards, to look around
#   E2E_ONLY=git tests/e2e/run.sh …    the server and one part only: adopt, sites or git
#   E2E_ONLY=release tests/e2e/run.sh  this checkout's install.sh installs the latest published release
#                                             (its signatures checked) and adds a site with its agent
#   E2E_ONLY=update tests/e2e/run.sh … the server, installed from this checkout, updates itself to the
#                                             published release (E2E_UPDATE_TO, else the oldest: 0.1.0)
#   E2E_ONLY=backups tests/e2e/run.sh … backups off the server (restic, on an S3 service of its own)
#   E2E_ONLY=database tests/e2e/run.sh … the server's PostgreSQL upgraded to the next major
#                                             (E2E_POSTGRES_NEXT, else 19rc1-alpine), undone, cleaned
#   E2E_ONLY=links tests/e2e/run.sh …  sites' links (D-34): the latest release installed with a site on the
#                                             shared networks, moved to this checkout (its files and agent),
#                                             a second site added; what each reaches of the other and the server
#   E2E_CACHE=1 tests/e2e/run.sh …     keeps the machine's images and build cache between runs
#                                             (in the volume quire-server-e2e-cache; one run at a time)
#
# With an agent image (e.g. one built with "docker build -t quire-server-agent:test ."), it's
# loaded instead of built inside. Needs privileged containers (Docker-in-Docker).
set -eu

here=$(cd "$(dirname "$0")/../.." && pwd)
dind=quire-server-e2e-$$
image=${1:-}
failures=0

pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; failures=$((failures + 1)); }
check() { if eval "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
inside() { docker exec "$dind" sh -c "$1"; }

# This run's logs (runs may go side by side: E2E_ONLY)
logs=$(mktemp -d)

cleanup() {
	if [ "${KEEP:-}" = 1 ]; then echo "Kept: docker exec -it $dind sh  (remove it with: docker rm -f -v $dind)"; return; fi
	docker rm -f -v "$dind" >/dev/null 2>&1 || true
	rm -rf "$logs"
}
trap cleanup EXIT

echo '== A machine of its own (Docker-in-Docker)'
# Whether a part runs (all of them unless E2E_ONLY names one; the release and the update only when named)
want() { [ -z "${E2E_ONLY:-}" ] || [ "$E2E_ONLY" = "$1" ]; }
release() { [ "${E2E_ONLY:-}" = release ]; }
updating() { [ "${E2E_ONLY:-}" = update ]; }
backingup() { [ "${E2E_ONLY:-}" = backups ]; }
upgrading() { [ "${E2E_ONLY:-}" = database ]; }
linking() { [ "${E2E_ONLY:-}" = links ]; }
# Installed from the latest release (its signatures checked), not from this checkout
from_release() { release || linking; }
cache=''
if [ "${E2E_CACHE:-}" = 1 ]; then cache='-v quire-server-e2e-cache:/var/lib/docker'; fi
# Docker Hub refuses anonymous pulls now and then (CI's runners are anonymous): the machine's image, and
# what Docker pulls inside it from Docker Hub, come through Google's mirror of it (Docker falls back to
# Docker Hub itself). Pinned by digest: the same image from either.
# On a host whose AppArmor restricts user namespaces (Ubuntu 23.10 and later), the machine runs under the
# profile gate/host-setup.sh loads (E2E_DIND_APPARMOR, its output): its own Docker has no AppArmor, so
# sites' rootless builders inside it get their user namespace from it.
apparmor=''
case "${E2E_DIND_APPARMOR:-none}" in none | '') ;; *) apparmor="--security-opt apparmor=$E2E_DIND_APPARMOR" ;; esac
# shellcheck disable=SC2086 # $cache and $apparmor are empty or two words
docker run -d --privileged --name "$dind" $cache $apparmor -e DOCKER_TLS_CERTDIR= \
	mirror.gcr.io/library/docker:29.9.2-dind@sha256:9e79b879b177fbd72c34376a3dd3d91747da5db49e1f560f76aea59561471977 \
	--registry-mirror=https://mirror.gcr.io >/dev/null
until inside 'docker info' >/dev/null 2>&1; do sleep 1; done
# With the cache, what an earlier run left but its images and build cache
# (Its containers restart with the cached Docker: removed, and the folders their mounts left behind.)
# The agent's image too: install.sh builds it only when there's none, so a cached one would be an
# earlier checkout's (its build layers stay cached).
if [ -n "$cache" ]; then inside 'docker rm -f $(docker ps -aq) >/dev/null 2>&1; docker rmi -f quire-server-agent >/dev/null 2>&1; docker volume prune -af >/dev/null; docker network prune -f >/dev/null; rm -rf /srv/quire /root/blog; true'; fi
inside 'apk add -q --no-cache curl openssl'
# The machine's routes as Ubuntu's ip lists them (busybox's doesn't list every table)
if ! release; then inside 'apk add -q --no-cache iproute2'; fi
# (Nothing else: install.sh needs only what a fresh machine has.)

if want adopt; then
echo '== A site installed by hand before the server (docs/docker.md): it holds ports 80 and 443'
inside 'mkdir -p /srv/quire/sites && docker run --rm -v /srv/quire/sites:/srv/quire/sites composer:2.10.3 create-project quire/skeleton /srv/quire/sites/demo "^0.1" --no-install --no-scripts --no-plugins --no-interaction --no-progress -q'
inside 'cd /srv/quire/sites/demo && umask 077 && printf "# By hand\nCOMPOSE_FILE=compose.yaml:compose.prod.yaml\nSERVER_NAME=demo.test\nAPP_SECRET=%s\nPOSTGRES_PASSWORD=%s\n" "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" > prod.env'
inside 'cd /srv/quire/sites/demo && docker compose --env-file prod.env up --detach --wait --build >/dev/null 2>&1 && docker compose --env-file prod.env exec -T php bin/console quire:install --no-interaction --title="Made by hand" --admin-email=demo@example.com >/dev/null'
check 'it holds port 443 itself' "inside 'docker port demo-php-1' | grep -q '443/tcp'"
# shellcheck disable=SC2034 # read by a check (eval)
demo_env=$(inside 'cat /srv/quire/sites/demo/prod.env')
fi

echo '== Installing the server with install.sh (it adopts the site)'
docker exec "$dind" mkdir -p /root/quire-server
tar -C "$here" --exclude=./.git --exclude=./vendor --exclude=./var --exclude=./tests -cf - . | docker exec -i "$dind" tar -C /root/quire-server -xf -

if from_release; then
	# The latest release, as a user gets it, but checked by this checkout's install.sh; with root's
	# umask as strict as it gets (cosign runs as a user of its own and must still read the files).
	install='umask 077; sh /root/quire-server/install.sh --yes --test-certificates --no-os-updates --unsupported-os 2>&1'
	check 'a release that doesn'\''t exist, or a version that isn'\''t one, installs nothing' "! inside 'sh /root/quire-server/install.sh --version=0.0.0 --yes --no-os-updates --unsupported-os' && ! inside 'sh /root/quire-server/install.sh \"--version=0.1.0/../..\" --yes --no-os-updates --unsupported-os' && ! inside 'test -e /srv/quire/server'"
else
	install='sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates --unsupported-os 2>&1'
fi
# The installer with more options
installing() { inside "${install% 2>&1} $* 2>&1"; }
# This checkout's agent (the links part moves the release's server to it later)
if [ -n "$image" ] && ! release; then
	docker save "$image" | docker exec -i "$dind" docker load -q
	inside "docker tag $image quire-server-agent"
fi

# The Docker-in-Docker machine is Alpine: not a supported system, so it takes --unsupported-os.
check 'on a system other than Ubuntu 26.04 LTS or later, nothing installs without --unsupported-os' "! inside 'sh /root/quire-server/install.sh --yes --no-os-updates' && inside 'sh /root/quire-server/install.sh --yes --no-os-updates 2>&1' | grep -q 'runs on Ubuntu 26.04 LTS' && ! inside 'test -e /srv/quire/server'"
if ! from_release; then
# A VPN's route on part of the sites' default range: sites there would lose their traffic to it
inside 'ip route add 10.72.8.0/24 dev eth0'
inside "$install" > "$logs"/install-routed.log || true
check 'a range the machine routes elsewhere is refused before the server starts, with a free one named' "grep -q '10.72.8.0/24 (eth0)' $logs/install-routed.log && grep -q 'again with --site-networks=10.73.0.0/16, which nothing here routes' $logs/install-routed.log && ! grep -q 'Quire Server runs' $logs/install-routed.log && [ -z \"\$(inside 'docker ps -aq --filter label=com.docker.compose.project=quire-server')\" ]"
check 'a range the agent wouldn'\''t take is refused, with why' "installing --site-networks=10.72.1.0/16 | grep -q \"10.72.1.0/16 isn't a network's own address\" && ! inside 'grep -q 10.72.1.0 /srv/quire/server/server.env'"
inside 'ip route del 10.72.8.0/24 dev eth0'
fi
inside "$install" | tee "$logs"/install.log | sed 's/^/    /'
check 'the installer finished' "grep -q 'Quire Server runs' $logs/install.log"
check 'it warned that the system is untested' "grep -q 'going on, as --unsupported-os asks, untested' $logs/install.log"
# What didn't start says why
if ! grep -q 'Quire Server runs' "$logs"/install.log; then inside 'cd /srv/quire/server && docker compose --env-file server.env logs --no-color host-setup gate agent 2>&1 | tail -60' | sed 's/^/    /' || true; fi
check 'the stack is up and healthy' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\" | grep -q \"agent healthy\"'"
published=$(inside "docker ps --format '{{.Names}} {{.Ports}}'" | grep -c '0.0.0.0' || true)
check 'only the front door publishes ports' "[ $published = 1 ]"
if release; then
check 'the release'\''s signatures were checked' "grep -q 'Its signature checks out' $logs/install.log && grep -q 'Its image'\''s signature checks out too' $logs/install.log"
check 'it runs the release'\''s signed image, by digest' "inside 'quire-server version' | grep -Eq '^Agent: ghcr.io/quirecms/server@sha256:[0-9a-f]{64}\$' && [ \"\$(inside 'docker inspect quire-server-agent-1 --format {{.Config.Image}}')\" = \"\$(inside 'sed -n s/^QUIRE_SERVER_IMAGE=//p /srv/quire/server/server.env')\" ]"
check 'nothing was built on the machine' "[ -z \"\$(inside 'docker images -q quire-server-agent')\" ]"
elif ! linking; then
check 'it says it was built from a checkout (no release image)' "inside 'quire-server version' | grep -q 'built from a checkout'"
fi
check 'every image of the stack is pinned by digest' "! inside 'cd /srv/quire/server && docker compose --env-file server.env config --images' | grep -v '^quire-server-agent\$' | grep -vq '@sha256:'"
if ! from_release; then
check 'the Docker gate has no network at all' "[ \"\$(inside 'docker inspect quire-server-gate-1 --format \"{{.HostConfig.NetworkMode}}\"')\" = none ]"
check 'only the gate holds Docker'\''s socket (and the updater, when it runs)' "[ \"\$(inside 'docker ps --format \"{{.Names}}\" | xargs docker inspect --format \"{{.Name}} {{range .Mounts}}{{.Source}} {{end}}\"' | grep -c docker.sock)\" = 1 ] && inside 'docker inspect quire-server-gate-1 --format \"{{range .Mounts}}{{.Source}} {{end}}\"' | grep -q docker.sock"
check 'the agent reaches Docker through the gate only' "[ \"\$(inside 'docker inspect quire-server-agent-1 --format \"{{range .Config.Env}}{{println .}}{{end}}\"' | grep ^DOCKER_HOST=)\" = DOCKER_HOST=unix:///run/quire-gate/trusted/trusted.sock ]"
check 'the host was set up for builds' "inside 'docker run --rm -v quire-server_host_facts:/facts:ro alpine:3.22 cat /facts/builder-apparmor' | grep -Eqx 'none|unconfined|yorvogate-buildkit-rootless'"
fi
check 'its secrets are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/server/server.env')\" = 600 ] && [ \"\$(inside 'stat -c %a /srv/quire/sites')\" = 700 ]"

resolve='--resolve demo.test:443:127.0.0.1 --resolve jane.test:443:127.0.0.1 --resolve www.jane.test:443:127.0.0.1 --resolve evil.test:443:127.0.0.1'
if want adopt; then
check 'the adopted site is served by the front door, with its data' "inside \"curl -sk $resolve https://demo.test/\" | grep -q 'Made by hand'"
check 'it publishes no ports any more' "[ -z \"\$(inside 'docker port demo-php-1')\" ]"
check 'it keeps its own PostgreSQL, off the server'\''s network' "inside 'docker ps --format \"{{.Names}}\"' | grep -q '^demo-database-1\$' && ! inside 'docker inspect demo-php-1 --format \"{{json .NetworkSettings.Networks}}\"' | grep -q quire-data"
check 'it can'\''t reach the server'\''s PostgreSQL' "! inside 'docker exec demo-php-1 php -r \"exit(@fsockopen(\\\"quire-database\\\", 5432, \\\$e, \\\$m, 3) ? 0 : 1);\"'"
check 'it was backed up first' "inside 'ls /srv/quire/backups/demo/before-server-*.tar'"
check 'its settings as they were are kept (root only)' "[ \"\$(inside 'cat /srv/quire/sites/demo/prod.env.before-server')\" = \"\$demo_env\" ] && [ \"\$(inside 'stat -c %a /srv/quire/sites/demo/prod.env.before-server')\" = 600 ]"
check 'two lines of its prod.env changed' "inside 'grep -c compose.server.yaml /srv/quire/sites/demo/prod.env' | grep -q 1 && inside 'grep -q \"^COMPOSE_PROJECT_NAME='\\''demo'\\''\" /srv/quire/sites/demo/prod.env'"
check 'its image has its own name' "inside 'docker inspect demo-php-1 --format \"{{.Config.Image}}\"' | grep -q '^quire-sites/demo/php-prod'"
check 'the adoption is recorded' "inside 'quire-server operations' | grep -q 'site.adopt.*succeeded'"
check 'its versions are recorded in its folder' "inside 'test -f /srv/quire/sites/demo/composer.lock'"
fi

server_env=$(inside 'sha256sum /srv/quire/server/server.env')
inside "$install" > "$logs"/install-again.log || true
check 'running the installer again changes nothing' "grep -q 'installed in /srv/quire/server already' $logs/install-again.log && grep -q 'Quire Server runs' $logs/install-again.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"
if want adopt && ! from_release; then
check 'once a site has its addresses, the installer doesn'\''t change the range' "! installing --site-networks=10.73.0.0/16 > $logs/install-range.log && grep -q 'keep them, so --site-networks can' $logs/install-range.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"
fi
# As PostgreSQL's superuser, in its container (local connections need the password too: SCRAM only)
postgres_password=$(inside "sed -n 's/^QUIRE_SERVER_POSTGRES_PASSWORD=//p' /srv/quire/server/server.env" | tr -d "'")
pg() { inside "docker exec -e PGPASSWORD=$postgres_password quire-server-database-1 psql -U postgres -Atc \"$1\""; }

if want adopt && ! from_release; then
echo '== Problems reach the owner (D-35): once when they start, once when they are over'
# The owner's mailbox: Mailpit, where the agent reaches it (the server's network)
inside 'docker run -d --name mailpit --network quire-data --network-alias mailpit -p 127.0.0.1:8025:8025 axllent/mailpit:v1.33.0@sha256:9b3a463be15354c0f0eba91697d80e3e0d8a5623ef0e627cacc836bc17fd5231 >/dev/null'
inside 'cp -p /srv/quire/server/server.env /root/server.env.before-notify && sed -i -e "s#^QUIRE_SERVER_MAILER_DSN=.*#QUIRE_SERVER_MAILER_DSN=smtp://mailpit:1025#" -e "s#^QUIRE_SERVER_NOTIFY_EMAIL=.*#QUIRE_SERVER_NOTIFY_EMAIL=owner@example.com#" -e "s#^QUIRE_SERVER_NAME=.*#QUIRE_SERVER_NAME=e2e#" /srv/quire/server/server.env && grep -q "^QUIRE_SERVER_NOTIFY_EMAIL=owner@example.com" /srv/quire/server/server.env && quire-server up >/dev/null 2>&1'
# Subjects of the mails Mailpit got, one per line
mails() { inside "curl -s 'http://127.0.0.1:8025/api/v1/messages?limit=50'" | tr ',' '\n' | sed -n 's/^"Subject":"\(.*\)"$/\1/p'; }
# Waits (up to 3 minutes: the agent's rounds are a minute apart) for a mail whose subject has this
mailed() { for _ in $(seq 36); do if mails | grep -qF "$1"; then return 0; fi; sleep 5; done; return 1; }
check 'a test notification arrives by email' "inside 'quire-server notify:test' | grep -q 'Sent: email to owner@example.com' && mailed 'Quire Server e2e: a test'"
# Last night, as the agent records it: demo's automatic update failed
inside "docker exec -e PGPASSWORD=$postgres_password quire-server-database-1 psql -U postgres -d quire_server -qc \"INSERT INTO server.operation (id, action, site, channel, requested_by, status, error, finished_at) VALUES (gen_random_uuid(), 'site.update', 'demo', 'agent', 'automatic', 'failed', 'planted-error-text', now())\""
check 'the agent tells of it, by itself' "mailed 'Quire Server e2e: The automatic update of demo failed'"
check 'the mail says what to do, and no tool output' "inside \"curl -s 'http://127.0.0.1:8025/api/v1/message/latest'\" > $logs/problem-mail.json && grep -q 'quire-server operations' $logs/problem-mail.json && ! grep -q planted-error-text $logs/problem-mail.json && ! grep -qF \"$postgres_password\" $logs/problem-mail.json"
check 'quire-server status lists it, with its key' "inside 'quire-server status' | grep -q 'The automatic update of demo failed.*key operation:site.update:demo'"
check 'every other command says a problem needs you' "inside 'quire-server site:list 2>&1 >/dev/null' | grep -q '1 problem needs you'"
check 'status reads the front door'\''s certificates' "inside 'quire-server status' | grep -q 'demo.test  certificate until'"
# The owner updates demo by hand, and it works
inside "docker exec -e PGPASSWORD=$postgres_password quire-server-database-1 psql -U postgres -d quire_server -qc \"INSERT INTO server.operation (id, action, site, channel, requested_by, status, finished_at) VALUES (gen_random_uuid(), 'site.update', 'demo', 'cli', 'root', 'succeeded', now())\""
check 'it is told once more when it is over, and only then' "mailed 'Quire Server e2e: Fixed: The automatic update of demo failed' && [ \"\$(mails | grep -cF 'The automatic update of demo failed')\" = 2 ]"
check 'and then nothing needs you' "inside 'quire-server status' | grep -q '^No problems' && ! inside 'quire-server site:list 2>&1 >/dev/null' | grep -q 'needs you'"
inside 'docker rm -f mailpit >/dev/null && cp -p /root/server.env.before-notify /srv/quire/server/server.env && quire-server up >/dev/null 2>&1'
fi

if linking; then
echo '== A site of a release before links (D-34): on the networks every site shares'
inside 'quire-server site:add jane --domain=jane.test --admin-email=jane@example.com --title="Jane" 2>&1' > "$logs"/add-jane.log || true
check 'jane runs on the release' "inside 'quire-server site:list' | grep jane | grep -q running"
# A container's networks and addresses: "<network>=<address> …"
attached() { inside "docker inspect $1 --format '{{range \$name, \$n := .NetworkSettings.Networks}}{{\$name}}={{\$n.IPAddress}} {{end}}'"; }
check 'it is on quire-edge and quire-data, as every site was' "attached jane-php-1 | grep -q quire-edge= && attached jane-php-1 | grep -q quire-data="

echo '== The server moves to this checkout (its files and its agent, as an update replaces them)'
inside 'tar -C /root/quire-server -cf - . | tar -C /srv/quire/server -xf - --no-same-owner'
inside "sed -i 's/^QUIRE_SERVER_IMAGE=.*/QUIRE_SERVER_IMAGE=/' /srv/quire/server/server.env"
inside 'quire-server up 2>&1' > "$logs"/up-links.log || true
# The agent's start moves each site to its link (a few seconds offline each)
inside 'for i in $(seq 150); do quire-server logs agent 2>&1 | grep -q "Automatic updates" && break; sleep 2; done'
inside 'quire-server logs agent 2>&1 | grep -v "^ *$" | tail -8' | sed 's/^/    /'
check 'the agent is this checkout'\''s' "inside 'quire-server version' | grep -q 'built from a checkout'"
check 'jane left the shared networks' "! attached jane-php-1 | grep -Eq 'quire-(edge|data)=' && ! attached jane-worker-1 | grep -Eq 'quire-(edge|data)='"
check 'jane is on its link, its own network at its addresses' "attached jane-php-1 | grep -Eq 'quire-link-jane=10\.72\.0\.(1[6-9]|2[0-9]|3[01]) ' && [ \"\$(inside \"docker network inspect jane_default --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'\")\" = 10.72.0.32/27 ]"
check 'its link has no way out, and the containers'\'' addresses from .16' "[ \"\$(inside \"docker network inspect quire-link-jane --format '{{.Internal}} {{range .IPAM.Config}}{{.Subnet}} {{.IPRange}}{{end}}'\")\" = 'true 10.72.0.0/27 10.72.0.16/28' ]"
check 'the front door is on it at .2, PostgreSQL at .3 as quire-database' "attached quire-server-front-1 | grep -q 'quire-link-jane=10.72.0.2 ' && attached quire-server-database-1 | grep -q 'quire-link-jane=10.72.0.3 ' && inside \"docker inspect quire-server-database-1 --format '{{(index .NetworkSettings.Networks \\\"quire-link-jane\\\").DNSNames}}'\" | grep -q quire-database"
check 'jane is served with its data, and its role opens its database' "inside \"curl -sk $resolve https://jane.test/\" | grep -q Jane && inside 'docker exec jane-php-1 php bin/console dbal:run-sql -q \"SELECT 1\"'"
check 'it trusts only the front door'\''s address as its proxy' "[ \"\$(inside 'docker exec jane-php-1 printenv SYMFONY_TRUSTED_PROXIES')\" = 10.72.0.2 ]"

echo '== A second site, added on a link of its own'
inside 'quire-server site:add kim --domain=kim.test --admin-email=kim@example.com --title="Kim" 2>&1' > "$logs"/add-kim.log || true
check 'kim runs, on its own link and addresses' "inside 'quire-server site:list' | grep kim | grep -q running && attached kim-php-1 | grep -Eq 'quire-link-kim=10\.72\.0\.(8[0-9]|9[0-5]) ' && attached quire-server-front-1 | grep -q 'quire-link-kim=10.72.0.66 '"
docker cp "$here/tests/e2e/try-links.php" "$dind:/root/try-links.php"
inside 'docker cp /root/try-links.php jane-php-1:/app/var/try-links.php'
from_jane() { inside "docker exec jane-php-1 php /app/var/try-links.php $1"; }
kim_link=$(inside "docker inspect kim-php-1 --format '{{(index .NetworkSettings.Networks \"quire-link-kim\").IPAddress}}'" || true)
kim_own=$(inside "docker inspect kim-php-1 --format '{{(index .NetworkSettings.Networks \"kim_default\").IPAddress}}'" || true)
reached=$(from_jane "tcp $kim_link:80 $kim_own:80 kim.test.quire.internal:80 kim-php-1:80 10.72.0.66:443 10.72.0.67:5432 jane.test.quire.internal:80" || true)
echo "$reached" | sed 's/^/    /'
check 'from jane, nothing of kim answers, by address or by name' "[ \"\$(echo \"\$reached\" | head -4 | grep -c ' not reached\$')\" = 4 ]"
check 'nor the front door or PostgreSQL on kim'\''s link' "[ \"\$(echo \"\$reached\" | sed -n 5,6p | grep -c ' not reached\$')\" = 2 ]"
check 'a control: jane reaches itself by its name on its link' "echo \"\$reached\" | grep -qx 'jane.test.quire.internal:80 reached'"
kim_password=$(inside "grep '^QUIRE_DATABASE_PASSWORD=' /srv/quire/sites/kim/prod.env | cut -d\\' -f2")
server_password=$(inside "sed -n 's/^QUIRE_SERVER_DATABASE_PASSWORD=//p' /srv/quire/server/server.env" | tr -d "'")
check 'kim'\''s role, with its real password, is refused from jane' "[ \"\$(from_jane \"login site_kim site_kim $kim_password\")\" = refused ]"
check 'PostgreSQL'\''s superuser, with its real password, is refused from a site' "[ \"\$(from_jane \"login postgres postgres $postgres_password\")\" = refused ]"
check 'so is the server'\''s own role' "[ \"\$(from_jane \"login quire_server quire_server $server_password\")\" = refused ]"
check 'a control: kim'\''s role logs in from kim' "inside 'docker exec kim-php-1 php bin/console dbal:run-sql -q \"SELECT 1\"'"
check 'PostgreSQL reads its rules without an error' "[ \"\$(pg 'SELECT count(*) FROM pg_hba_file_rules WHERE error IS NOT NULL')\" = 0 ]"

echo '== The server'\''s containers made again: "quire-server up" puts them back on every link'
inside 'cd /srv/quire/server && docker compose --env-file server.env up --detach --wait --force-recreate --no-deps front database >/dev/null 2>&1' || true
check 'made again, they are on no link' "! attached quire-server-front-1 | grep -q quire-link- && ! attached quire-server-database-1 | grep -q quire-link-"
inside 'quire-server up >/dev/null 2>&1' || true
check 'after "up", the front door and PostgreSQL are back on both links, at their addresses' "attached quire-server-front-1 | grep -q 'quire-link-jane=10.72.0.2 ' && attached quire-server-front-1 | grep -q 'quire-link-kim=10.72.0.66 ' && attached quire-server-database-1 | grep -q 'quire-link-jane=10.72.0.3 ' && attached quire-server-database-1 | grep -q 'quire-link-kim=10.72.0.67 '"
check 'both sites are served again' "inside \"curl -sk $resolve https://jane.test/\" | grep -q Jane && inside 'curl -sk --resolve kim.test:443:127.0.0.1 https://kim.test/' | grep -q Kim"

echo '== A network the machine routes on the sites'\'' range (a VPN added later)'
inside 'ip route add 10.72.200.0/24 dev eth0'
check '"quire-server up" names it, with what to do' "inside 'quire-server up 2>&1' | grep -q '^  10.72.200.0/24 (eth0)\$'"
inside 'ip route del 10.72.200.0/24 dev eth0'
check 'and says nothing once it'\''s gone' "! inside 'quire-server up 2>&1' | grep -q 'routes part of the sites'"

echo '== Removing a site takes its link and its login'
inside 'quire-server site:remove kim --yes >/dev/null 2>&1' || true
check 'kim'\''s link is gone, the front door and PostgreSQL off it' "! inside 'docker network ls --format {{.Name}}' | grep -q quire-link-kim && ! attached quire-server-front-1 | grep -q quire-link-kim && ! attached quire-server-database-1 | grep -q quire-link-kim"
check 'its login is gone from PostgreSQL'\''s rules' "[ \"\$(pg \"SELECT count(*) FROM pg_hba_file_rules WHERE 'site_kim' = ANY(user_name)\")\" = 0 ]"
check 'jane is still served' "inside \"curl -sk $resolve https://jane.test/\" | grep -q Jane"
fi

if updating; then
to=${E2E_UPDATE_TO:-0.1.0}
echo "== The server updates itself to Quire Server $to (published, its signatures checked)"
inside 'mkdir -p /srv/quire/server/keys && echo key > /srv/quire/server/keys/blog && chmod 600 /srv/quire/server/keys/blog'
check 'it says a release is available' "inside 'quire-server update --check' | grep -q 'is available (this server runs a build from a checkout)'"
check 'a release that doesn'\''t exist changes nothing' "! inside 'quire-server update --to=0.0.0 --yes' >/dev/null 2>&1 && inside 'quire-server version' | grep -q 'built from a checkout'"
check 'a version that isn'\''t one is refused' "! inside 'quire-server update \"--to=0.1.0/../x\" --yes' >/dev/null 2>&1"

# A release that doesn't start in time (here: the wait cut to a second) is rolled back by itself.
inside 'printf "QUIRE_SERVER_UPDATE_WAIT_SECONDS=1\n" >> /srv/quire/server/server.env'
server_env=$(inside 'sha256sum /srv/quire/server/server.env')
inside "quire-server update --to=$to --yes 2>&1" > "$logs"/update-failed.log || true
sed 's/^/    /' "$logs"/update-failed.log
check 'an update that doesn'\''t start is rolled back' "grep -q 'the server runs as it was' $logs/update-failed.log"
check 'it runs as before: the build from the checkout' "inside 'quire-server version' | grep -q 'built from a checkout' && inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'agent healthy'"
check 'its files and settings are as before' "[ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ] && inside 'grep -q \"^  updater:\" /srv/quire/server/compose.yaml'"
check 'the failed update is recorded' "inside 'quire-server operations' | grep -q 'server.update.*failed'"
check 'no backup is kept of a rolled-back update' "[ -z \"\$(inside 'ls /srv/quire/backups/server 2>/dev/null')\" ]"
inside "sed -i '/^QUIRE_SERVER_UPDATE_WAIT_SECONDS=/d' /srv/quire/server/server.env"

inside "quire-server update --to=$to --yes 2>&1" > "$logs"/update.log || true
sed 's/^/    /' "$logs"/update.log
check 'the update checked the release'\''s signature' "grep -q 'Its signature checks out' $logs/update.log"
check "it runs Quire Server $to, the release's signed image by digest" "inside 'quire-server version' | grep -q \"^Quire Server $to\\\$\" && inside 'quire-server version' | grep -Eq '^Agent: ghcr.io/quirecms/server@sha256:[0-9a-f]{64}\$'"
inside 'quire-server ps --format "{{.Service}} {{.State}} {{.Health}}"' > "$logs"/ps.log 2>&1 || true
sed 's/^/    /' "$logs"/ps.log
check 'every part runs, healthy' "[ \"\$(grep -c ' running' $logs/ps.log)\" = 4 ] && ! grep -Eq 'unhealthy|starting' $logs/ps.log"
check 'it runs the release'\''s files, not a build' "inside 'grep -qx COMPOSE_FILE=compose.yaml /srv/quire/server/server.env || grep -qx \"COMPOSE_FILE='\''compose.yaml'\''\" /srv/quire/server/server.env' && [ -z \"\$(inside 'grep -l build: /srv/quire/server/compose.yaml')\" ]"
check 'its settings, secrets and keys are kept' "inside 'grep -q ^QUIRE_SERVER_POSTGRES_PASSWORD=. /srv/quire/server/server.env' && [ \"\$(inside 'stat -c %a /srv/quire/server/server.env')\" = 600 ] && inside 'grep -qx key /srv/quire/server/keys/blog'"
check 'it was backed up first (root only)' "inside 'ls /srv/quire/backups/server/*/files.tar /srv/quire/backups/server/*/database.dump' && [ \"\$(inside 'stat -c %a /srv/quire/backups/server/*/database.dump')\" = 600 ]"
check 'the update is recorded' "inside 'quire-server operations' | grep -q 'server.update.*succeeded'"
check 'its secrets never reached a command line' "! grep -q \"\$(inside 'sed -n s/^QUIRE_SERVER_POSTGRES_PASSWORD=//p /srv/quire/server/server.env' | tr -d \"'\")\" $logs/update.log"
fi

if release; then
echo '== Adding a site with the release'\''s agent'
inside 'quire-server site:add jane --domain=jane.test --admin-email=jane@example.com --title="Jane" 2>&1' | tee "$logs"/add.log | sed 's/^/    /'
check 'the site runs' "inside 'quire-server site:list' | grep -q running"
check 'it is served through the front door' "inside \"curl -sk $resolve https://jane.test/\" | grep -q Jane"
fi

if backingup; then
echo '== Backups off the server (restic, on S3-compatible storage of its own)'
# The agent's own nightly backups and updates off on this machine: only the checks below make them.
inside "printf 'QUIRE_SERVER_BACKUP_HOUR=off\\nQUIRE_SERVER_UPDATE_HOUR=off\\n' >> /srv/quire/server/server.env && quire-server up >/dev/null 2>&1"
# The storage: rclone's S3 server, on the agent's network only, with a key of its own
inside 'docker run -d --name s3 --network quire-data rclone/rclone:1.71.2 serve s3 --addr :9000 --auth-key e2e-key,e2e-secret /data >/dev/null'
until inside 'docker logs s3 2>&1' | grep -q 'Starting s3 server'; do sleep 1; done
inside 'quire-server site:add jane --domain=jane.test --admin-email=jane@example.com --title="Jane before" 2>&1' > "$logs"/add.log || sed 's/^/    /' "$logs"/add.log
check 'there are none before they are set up' "inside 'quire-server backups jane 2>&1' | grep -q 'backups:setup'"

repository='--repository=s3:http://s3:9000/quire-backups/server-1 --access-key=e2e-key --region=us-east-1'
server_env=$(inside 'sha256sum /srv/quire/server/server.env')
check 'connecting to a repository that isn'\''t there saves nothing' "! inside 'QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-secret QUIRE_SERVER_BACKUP_SETUP_PASSWORD=0123456789abcdefghijk quire-server backups:setup $repository --existing --yes 2>&1' | tee $logs/setup-missing.log | grep -q 'are set up' && grep -q 'no backup repository there' $logs/setup-missing.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"
inside "QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-secret quire-server backups:setup $repository --yes 2>&1" | tee "$logs"/setup.log | sed 's/^/    /'
check 'setting them up creates the repository, and shows its password once' "grep -q 'are set up' $logs/setup.log && grep -q 'shown only now' $logs/setup.log"
check 'its settings and password are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/server/server.env')\" = 600 ] && inside 'grep -q ^QUIRE_SERVER_BACKUP_PASSWORD=. /srv/quire/server/server.env'"
password=$(inside "sed -n \"s/^QUIRE_SERVER_BACKUP_PASSWORD='\\(.*\\)'\$/\\1/p\" /srv/quire/server/server.env")
check 'the agent has them' "inside 'quire-server backups jane 2>&1' | grep -q 'No backup of \"jane\" off the server yet'"

# A table of the site's application, outside Quire's schema: its backups have it too
orders() { inside "docker exec $1-php-1 bin/console dbal:run-sql 'SELECT count(*) FROM public.app_order'" 2>/dev/null | grep -oE '[0-9]+' | tail -1; }
inside "docker exec jane-php-1 bin/console dbal:run-sql -q 'CREATE TABLE public.app_order (id int PRIMARY KEY)' && docker exec jane-php-1 bin/console dbal:run-sql -q 'INSERT INTO public.app_order VALUES (1), (2)'" >/dev/null
# What an earlier backup cut short left in the site's container, where 0.3.0 always wrote it
inside "docker exec jane-php-1 sh -c 'echo partial > /tmp/quire-off-server-backup.tar'"
inside 'quire-server backups:now 2>&1' | tee "$logs"/now.log | sed 's/^/    /'
check 'every site and the server are backed up off the server' "grep -q 'Backed up off the server: jane, server' $logs/now.log"
check 'each site'\''s backups are listed on their own' "[ \"\$(inside 'quire-server backups jane' | grep -cE '^ +[0-9a-f]{8} ')\" = 1 ] && [ \"\$(inside 'quire-server backups server' | grep -cE '^ +[0-9a-f]{8} ')\" = 1 ]"
check 'what is stored is encrypted (no site data in it as text)' "! inside 'docker exec s3 sh -c \"grep -rl -e site_jane -e \\\"Jane before\\\" /data\"' >/dev/null 2>&1"
check 'its secrets aren'\''t in its log' "! inside \"quire-server operations \$(inside 'quire-server operations' | grep backups.nightly | head -1 | awk '{print \$1}')\" | grep -q -e e2e-secret -e '$password'"

inside "docker exec jane-php-1 bin/console quire:settings:set site.title 'Jane after'" >/dev/null
inside "docker exec jane-php-1 bin/console dbal:run-sql -q 'INSERT INTO public.app_order VALUES (3)'" >/dev/null
check 'the site changed since its backup' "inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane after'"
server_snapshot=$(inside 'quire-server backups server' | grep -oE '^ +[0-9a-f]{8} ' | tr -d ' ' | head -1)
check 'another backup than the site'\''s own isn'\''t restored to it' "inside 'quire-server backups:restore jane --snapshot=$server_snapshot --yes 2>&1' | grep -q 'no such backup' && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane after'"
inside 'quire-server backups:restore jane --yes 2>&1' | tee "$logs"/restore.log | sed 's/^/    /'
check 'restoring it from off the server puts it back as it was' "grep -q 'is as it was on' $logs/restore.log && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
check 'its application'\''s own tables too' "[ \"\$(orders jane)\" = 2 ]"
check 'the restore is recorded, and it was backed up first' "inside 'quire-server operations' | grep -q 'site.restore.*succeeded' && inside 'ls /srv/quire/backups/jane/before-restore-*.tar'"
check 'nothing of the restore is left on the server' "[ -z \"\$(inside 'ls -d /srv/quire/backups/jane/from-off-server-* /srv/quire/backups/jane/off-server-*.tar 2>/dev/null')\" ]"

echo '== Backups that prove where they come from (D-36)'
# restic on the repository as anyone with its password would (in the agent's container, which has it)
anyone() { inside "docker exec -e RESTIC_REPOSITORY=s3:http://s3:9000/quire-backups/server-1 -e RESTIC_PASSWORD='$password' -e AWS_ACCESS_KEY_ID=e2e-key -e AWS_SECRET_ACCESS_KEY=e2e-secret -e AWS_DEFAULT_REGION=us-east-1 -e RESTIC_CACHE_DIR=/tmp/anyone quire-server-agent-1 restic $1"; }
public_key=$(inside 'quire-server status' | grep -o 'ed25519:[A-Za-z0-9+/=]*' | head -1)
check 'the server signs its backups: its public key is shown, at setup too' "[ -n '$public_key' ] && tr -d '\n ' < $logs/setup.log | grep -qF '$public_key'"
check 'the list of the backups it made is signed, and backed up' "anyone 'snapshots --json --tag quire-provenance' | grep -q '\"id\"'"
check 'its key is in none of them' "! anyone 'snapshots --json' | grep -q /server/signing && [ \"\$(inside 'stat -c %a /srv/quire/server/signing/backups.key')\" = 600 ]"
# The audit's ISO-08: a snapshot under jane's tags, made with the password, newer than jane's own
inside "docker exec quire-server-agent-1 sh -c 'echo planted > /srv/quire/backups/jane/off-server-planted.tar'"
planted=$(anyone 'backup --json --host quire-server --tag quire-site --tag site:jane /srv/quire/backups/jane/off-server-planted.tar' | sed -n 's/.*"snapshot_id":"\([0-9a-f]*\)".*/\1/p')
inside 'rm -f /srv/quire/backups/jane/off-server-planted.tar'
check 'a snapshot someone else made, under the site'\''s tags, is refused' "[ -n '$planted' ] && inside 'quire-server backups:restore jane --snapshot=$planted --yes 2>&1' | tr -s ' \n' ' ' | grep -q 'one this server made' && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
inside 'quire-server backups:restore jane --yes 2>&1' > "$logs"/restore-latest.log || true
check '"latest" is the newest this server made, not the one planted after it' "grep -q 'is as it was on' $logs/restore-latest.log && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
anyone "forget $planted" >/dev/null

inside 'quire-server backups:check 2>&1' > "$logs"/check.log || true
check 'the stored data checks out' "grep -q 'The backups check out' $logs/check.log"

server_env=$(inside 'sha256sum /srv/quire/server/server.env')
check 'a wrong password for the repository saves nothing' "inside 'QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-secret QUIRE_SERVER_BACKUP_SETUP_PASSWORD=not-the-password-at-all quire-server backups:setup $repository --existing --yes 2>&1' | grep -q 'doesn.t open with this password' && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"
# As a server that replaces this one would: the repository, its keys and its password
inside "QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-secret QUIRE_SERVER_BACKUP_SETUP_PASSWORD='$password' quire-server backups:setup $repository --existing --yes 2>&1" > "$logs"/setup-existing.log || true
check 'another server connects to the repository with its password' "grep -q 'are set up' $logs/setup-existing.log && [ \"\$(inside 'quire-server backups jane' | grep -cE '^ +[0-9a-f]{8} ')\" -ge 1 ]"

echo '== A site from a backup, as a new site: a copy here, and moved from another server'
inside 'quire-server backups:restore jane --as=jane-copy --domain=copy.test --yes 2>&1' | tee "$logs"/copy.log | sed 's/^/    /'
check 'a copy of a site runs from its backup, with its data' "grep -q 'jane-copy\" runs, as \"jane\" was' $logs/copy.log && inside 'curl -sk --resolve copy.test:443:127.0.0.1 https://copy.test/' | grep -q 'Jane before'"
check 'the copy has settings of its own (no secret of the site'\''s)' "[ \"\$(inside \"sed -n 's/^APP_SECRET=//p' /srv/quire/sites/jane/prod.env\")\" != \"\$(inside \"sed -n 's/^APP_SECRET=//p' /srv/quire/sites/jane-copy/prod.env\")\" ] && inside 'quire-server site:list' | grep -q 'jane-copy.*running'"
check 'with its application'\''s own tables' "[ \"\$(orders jane-copy)\" = 2 ]"
check 'the site itself is untouched' "inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before' && inside 'quire-server site:list' | grep -q 'jane .*running'"
check 'a name taken isn'\''t restored over' "inside 'quire-server backups:restore jane --as=jane --domain=other.test --yes 2>&1' | grep -q 'exists already' && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
# This machine as a second server, under another name in the same bucket: the first one's backups are another server's.
inside "QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-secret QUIRE_SERVER_BACKUP_SETUP_PASSWORD='$password' quire-server backups:setup $repository --existing --host=server-2 --yes >/dev/null 2>&1"
check 'another server'\''s backups are listed by its name' "! inside 'quire-server backups jane' | grep -qE '^ +[0-9a-f]{8} ' && inside 'quire-server backups jane --from-server=quire-server' | grep -qE '^ +[0-9a-f]{8} '"
check 'another server'\''s backups need its public key' "inside 'quire-server backups:restore jane --from-server=quire-server --as=moved --domain=moved.test --yes 2>&1' | tr -s ' \n' ' ' | grep -q 'signed-by' && ! inside 'quire-server site:list' | grep -q moved"
inside "quire-server backups:restore jane --from-server=quire-server --signed-by='$public_key' --as=moved --domain=moved.test --yes 2>&1" | tee "$logs"/moved.log | sed 's/^/    /'
check 'a site moves from another server'\''s backups' "grep -q 'moved\" runs' $logs/moved.log && inside 'curl -sk --resolve moved.test:443:127.0.0.1 https://moved.test/' | grep -q 'Jane before'"

echo '== A whole server from its backups (as a server that replaces it)'
# The first server's sites, as its last backup lists them (only jane): gone from here first.
inside 'quire-server site:remove jane --yes >/dev/null 2>&1'
inside 'quire-server backups:restore-server --from-server=quire-server --yes 2>&1' | tee "$logs"/restore-server.log | sed 's/^/    /'
# Its key, given once (for "moved"), is trusted for its backups since
check 'every site of the old server is restored, with its name and domains' "grep -Eq 'jane +restored' $logs/restore-server.log && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
check 'with its application'\''s own tables' "[ \"\$(orders jane)\" = 2 ]"
check 'with its update policy' "inside 'quire-server site:updates jane' | grep -q 'fix a known vulnerability'"
check 'a server with no backups is refused' "inside 'quire-server backups:restore-server --from-server=no-such-server --yes 2>&1' | grep -q 'no backup of the server'"

echo '== Backups that survive a stolen server.env (D-37): a bucket that keeps what is deleted, proved'
# S3 with versions and Object Lock (versitygw), and the AWS CLI to make its buckets as an owner would
inside 'docker run -d --name locked-s3 --network quire-data --tmpfs /data --tmpfs /versions -e ROOT_ACCESS_KEY=e2e-key -e ROOT_SECRET_KEY=e2e-locked-secret versity/versitygw:v1.10.0@sha256:89cad13cc981986ee284cbafea4613f9533ce6fd5f37a2c96c4c4fb8adb3a316 posix --versioning-dir /versions /data >/dev/null'
s3() { inside "docker run --rm --network quire-data -e AWS_ACCESS_KEY_ID=e2e-key -e AWS_SECRET_ACCESS_KEY=e2e-locked-secret -e AWS_DEFAULT_REGION=us-east-1 amazon/aws-cli:2.31.1@sha256:5006ff9131b0559e277a81ffdce20f90edd7fb2ecc82a3d8e3132939cda28288 --endpoint-url http://locked-s3:7070 $1"; }
until s3 's3api list-buckets' >/dev/null 2>&1; do sleep 1; done
s3 's3api create-bucket --bucket open' >/dev/null
s3 's3api create-bucket --bucket locked --object-lock-enabled-for-bucket' >/dev/null
s3 's3api put-object-lock-configuration --bucket locked --object-lock-configuration ObjectLockEnabled=Enabled,Rule={DefaultRetention={Mode=COMPLIANCE,Days=30}}'
locked_setup() { inside "QUIRE_SERVER_BACKUP_SETUP_SECRET_KEY=e2e-locked-secret quire-server backups:setup --repository=s3:http://locked-s3:7070/$1 --access-key=e2e-key --region=us-east-1 --yes 2>&1" | tr -s ' \n' ' '; }
check 'a bucket that keeps nothing deleted is said to be unprotected, at setup' "locked_setup open/server | grep -q 'aren.t protected: the bucket doesn.t keep versions'"
check 'one with Object Lock is proved protected' "locked_setup locked/server | grep -Eq 'are protected: what.s deleted stays recoverable for (29|30) days'"
inside 'quire-server backups:now >/dev/null 2>&1'
check 'every night says so, and that every backup it made is there' "inside 'quire-server status' | grep -q 'stays recoverable' && inside 'quire-server status' | grep -q 'Every backup this server made and keeps is there'"
# Someone with server.env deletes them all, with its own keys
sleep 2
before=$(inside 'date -u +%Y-%m-%dT%H:%M:%SZ')
sleep 2
s3 's3 rm --recursive --quiet s3://locked/server'
inside 'quire-server backups:now >/dev/null 2>&1' || true
# What "status" says once the agent's next round (a minute at most) has made it a problem
status_says() { for _ in $(seq 24); do if inside 'quire-server status' | tr -s ' \n' ' ' | grep -q "$1"; then return 0; fi; sleep 5; done; return 1; }
check 'that they are gone is a problem, the same night' "status_says 'The backup repository is gone from the bucket, with the [0-9]* backups this server made.*backups:recover'"
inside "quire-server backups:recover --before=$before --yes 2>&1" | tee "$logs"/recover.log | sed 's/^/    /'
check 'they come back as they were, with the server'\''s own keys' "grep -q 'Brought back [1-9][0-9]* file' $logs/recover.log && inside 'quire-server backups jane' | grep -qE '^ +[0-9a-f]{8} ' && inside 'quire-server backups:check 2>&1' | grep -q 'The backups check out'"
inside 'quire-server backups:now >/dev/null 2>&1' || true
check 'and the next night finds them all there' "inside 'quire-server status' | grep -q 'Every backup this server made and keeps is there'"
inside 'docker rm -f locked-s3 >/dev/null'
fi

if upgrading; then
next=${E2E_POSTGRES_NEXT:-19rc1-alpine}
major=${next%%[!0-9]*}
echo "== The server's PostgreSQL upgraded to $major ($next)"
# The agent's nightly updates and backups off on this machine: nothing else runs meanwhile.
inside "printf 'QUIRE_SERVER_UPDATE_HOUR=off\\nQUIRE_SERVER_BACKUP_HOUR=off\\n' >> /srv/quire/server/server.env && quire-server up >/dev/null 2>&1"
inside 'quire-server site:add jane --domain=jane.test --admin-email=jane@example.com --title="Jane" 2>&1' > "$logs"/add.log || sed 's/^/    /' "$logs"/add.log
inside "docker exec jane-php-1 bin/console quire:settings:set site.title 'Jane before'" >/dev/null
title() { inside "curl -sk $resolve https://jane.test/" | grep -q "$1"; }
check 'a site runs on the server'\''s PostgreSQL 18' "title 'Jane before' && pg 'SHOW server_version' | grep -q '^18\\.'"
check 'this release runs only 18: there is nothing to upgrade to' "inside 'quire-server database:upgrade --check' | grep -q 'newest this release runs'"

# The next major, as a release would name it: pinned by digest
inside "docker pull -q postgres:$next >/dev/null"
digest=$(inside "docker image inspect postgres:$next --format '{{index .RepoDigests 0}}'" | sed 's/^postgres@//')
inside "printf 'services:\\n  database:\\n    image: postgres:%s@%s\\n' '$next' '$digest' > /srv/quire/server/database/postgres-$major.yaml"
check "the release runs $major too, and says so" "inside 'quire-server database:upgrade --check' | grep -q -- '--to=$major'"

check "a site whose runtime has older PostgreSQL tools isn't upgraded from (its backups would fail)" "inside 'quire-server database:upgrade --yes 2>&1' | tr -s ' \\n' ' ' | grep -q 'their backups would fail' && pg 'SHOW server_version' | grep -q '^18\\.'"
# Quire's runtime ships PostgreSQL 18's tools today: from here on, the site stands for one built on a
# runtime with $major's (only what it says its version is changes; nothing below backs it up on $major)
printf '#!/bin/sh\n[ "$1" = --version ] && echo "pg_dump (PostgreSQL) %s.0" && exit 0\nexec /usr/local/bin/pg_dump.real "$@"\n' "$major" > "$logs"/pg_dump
docker cp "$logs"/pg_dump "$dind:/root/pg_dump"
# Written from inside, as root: the site's containers drop every capability, so root changes only
# files it owns (a file put there by "docker cp" keeps its owner from outside).
inside 'docker exec -u root jane-php-1 mv /usr/local/bin/pg_dump /usr/local/bin/pg_dump.real && docker exec -i -u root jane-php-1 sh -c "cat > /usr/local/bin/pg_dump && chmod 755 /usr/local/bin/pg_dump" < /root/pg_dump'
check "a site built on a runtime with $major's tools says so" "inside 'docker exec jane-php-1 pg_dump --version' | grep -q \"PostgreSQL) $major\""

pg 'CREATE TABLE stray (id int)' >/dev/null
check 'a table in PostgreSQL'\''s own database is refused, nothing changed' "inside 'quire-server database:upgrade --yes 2>&1' | grep -q 'holds tables' && title 'Jane before' && pg 'SHOW server_version' | grep -q '^18\\.'"
pg 'DROP TABLE stray' >/dev/null

# Something holding a connection the copy wouldn't see the writes of: from inside PostgreSQL's container
inside "docker exec -d -e PGPASSWORD=$postgres_password quire-server-database-1 psql -U postgres -c 'SELECT pg_sleep(900)'"
sleep 2
inside 'quire-server database:upgrade --yes 2>&1' > "$logs"/upgrade-busy.log || true
sed 's/^/    /' "$logs"/upgrade-busy.log
check 'an upgrade that fails is rolled back by itself' "grep -q 'Something still uses' $logs/upgrade-busy.log && grep -q 'the server runs as it was' $logs/upgrade-busy.log"
check 'the server and the site run as before, on 18' "pg 'SHOW server_version' | grep -q '^18\\.' && title 'Jane before' && inside 'quire-server site:list' | grep -q 'jane .*running' && inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'agent healthy'"
check 'its unfinished copy is gone' "[ \"\$(inside 'docker run --rm -v quire-server_database_data:/v alpine ls /v')\" = 18 ] && ! inside 'docker ps -a --format {{.Names}}' | grep -q database-upgrade"
pg "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE query LIKE '%pg_sleep(900)%' AND pid <> pg_backend_pid()" >/dev/null

inside "quire-server database:upgrade --to=$major --yes 2>&1" > "$logs"/upgrade.log || true
sed 's/^/    /' "$logs"/upgrade.log
check "it runs PostgreSQL $major" "pg 'SHOW server_version' | grep -q '^$major' && inside 'grep -q postgres-$major.yaml /srv/quire/server/server.env'"
check 'the copy was checked against the original' "grep -Eq 'The same in both: the roles and their rights, [0-9]+ databases' $logs/upgrade.log"
check 'the site runs with its data' "title 'Jane before' && inside 'quire-server site:list' | grep -q 'jane .*running'"
inside "docker exec jane-php-1 bin/console quire:settings:set site.title 'Jane after'" >/dev/null
check 'and writes to it' "title 'Jane after'"
docker cp "$here/tests/e2e/try-databases.php" "$dind:/root/try-databases.php"
inside 'docker cp /root/try-databases.php jane-php-1:/app/var/try-databases.php'
check 'its role is still refused the server'\''s databases' "[ \"\$(inside 'docker exec jane-php-1 php /app/var/try-databases.php' | grep -Ec ': (refused|denied)\$')\" = 3 ]"
check 'the agent runs, with its registry' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'agent healthy' && inside 'quire-server operations' | grep -q 'server.database-upgrade.*succeeded'"
check "18's data stays, beside $major's" "[ \"\$(inside 'docker run --rm -v quire-server_database_data:/v alpine ls /v' | tr '\\n' ' ')\" = \"18 $major \" ]"
check 'its record is root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/backups/database/*/upgrade.json' | sort -u)\" = 600 ]"
check 'its secrets never reached its log' "! grep -q \"\$(inside 'sed -n s/^QUIRE_SERVER_POSTGRES_PASSWORD=//p /srv/quire/server/server.env' | tr -d \"'\")\" $logs/upgrade.log"
inside 'quire-server down >/dev/null 2>&1 && quire-server up >/dev/null 2>&1'
check "the server starts on $major again" "pg 'SHOW server_version' | grep -q '^$major' && title 'Jane after'"
# As if the machine had stopped right after the switch: its record says "switched", the agent didn't
# start, the site ran (it starts by itself) and wrote to the new major
inside "sed -i 's/\"state\": \"done\"/\"state\": \"switched\"/' /srv/quire/backups/database/*/upgrade.json && docker stop quire-server-agent-1 >/dev/null"
inside "docker exec jane-php-1 bin/console quire:settings:set site.title 'Jane meanwhile'" >/dev/null
inside 'quire-server database:upgrade --recover 2>&1' > "$logs"/recover.log || true
sed 's/^/    /' "$logs"/recover.log
check "an upgrade cut short after the switch is finished on $major, with what the site wrote since" "grep -q 'The upgrade is finished' $logs/recover.log && pg 'SHOW server_version' | grep -q '^$major' && title 'Jane meanwhile' && inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'agent healthy' && [ \"\$(inside 'docker run --rm -v quire-server_database_data:/v alpine ls /v' | tr '\\n' ' ')\" = \"18 $major \" ]"
inside "docker exec jane-php-1 bin/console quire:settings:set site.title 'Jane after'" >/dev/null

inside 'quire-server database:upgrade --undo --yes 2>&1' > "$logs"/undo.log || true
sed 's/^/    /' "$logs"/undo.log
check 'undoing it runs 18 again, with its data as the upgrade left it' "pg 'SHOW server_version' | grep -q '^18\\.' && title 'Jane before' && inside 'quire-server site:list' | grep -q 'jane .*running'"
inside "quire-server database:upgrade --to=$major --yes 2>&1" > "$logs"/upgrade-again.log || true
check 'upgrading again starts from a fresh copy, the earlier one kept aside' "grep -q 'from an earlier upgrade is kept as' $logs/upgrade-again.log && pg 'SHOW server_version' | grep -q '^$major' && title 'Jane before' && inside 'docker run --rm -v quire-server_database_data:/v alpine ls /v' | grep -q \"^$major\\.before-\""
inside 'quire-server database:upgrade --clean --yes 2>&1' | sed 's/^/    /'
check "cleaning removes 18's data, and what was kept aside" "[ \"\$(inside 'docker run --rm -v quire-server_database_data:/v alpine ls /v')\" = $major ]"
check 'after that, it can'\''t be undone' "inside 'quire-server database:upgrade --undo --yes 2>&1' | grep -q 'data isn.t there any more' && pg 'SHOW server_version' | grep -q '^$major'"
fi

if want sites; then
# The agent's nightly updates off on this machine: only the checks below update the site.
inside "printf 'QUIRE_SERVER_UPDATE_HOUR=off\\n' >> /srv/quire/server/server.env && quire-server up >/dev/null 2>&1"
check 'automatic updates can be off for the whole server' "inside 'quire-server logs agent' | grep -q 'Automatic updates: off on this server'"
check '"down -v" is refused: the server'\''s data stays' "inside 'quire-server down -v 2>&1' | grep -q 'keeps the server.s data' && inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'database healthy'"
# Another program took ports 80 and 443 while the front door was gone, then let them go
inside 'docker rm -f quire-server-front-1 >/dev/null && docker run -d --name other-web -p 80:80 -p 443:443 alpine:3.22 sleep 1d >/dev/null'
inside 'quire-server up >/dev/null 2>&1' || true
inside 'docker rm -f other-web >/dev/null'
inside 'quire-server up 2>&1' > "$logs"/up-after-ports.log || true
check 'after a port conflict, "up" gets the front door its ports back' "grep -q 'starting it again' $logs/up-after-ports.log && inside 'docker port quire-server-front-1 443'"
echo '== Adding a site (a few minutes: it is built)'
# Its third domain is one public DNS knows: it names 127.0.0.1, the front door itself (as a real domain names the server)
inside 'quire-server site:add jane --domain=jane.test --domain=www.jane.test --domain=jane.127.0.0.1.sslip.io --admin-email=jane@example.com --title="Jane" 2>&1' | tee "$logs"/add.log | sed 's/^/    /'
check 'the site runs' "inside 'quire-server site:list' | grep -q running"
check 'a password was shown once (no email server)' "grep -q 'signs in with the password' $logs/add.log"

check 'HTTPS through the front door' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
check 'its second domain too' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://www.jane.test/_quire/health\")\" = 200 ]"
check 'HTTP redirects to HTTPS' "inside \"curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -H 'Host: jane.test' http://127.0.0.1/\" | grep -q '308 https://jane.test/'"
check 'a forged Host header gets 421, not another container' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' -H 'Host: quire-database' https://jane.test/\")\" = 421 ]"
check 'a Host other than the TLS name gets 421' "[ \"\$(inside \"curl -sk --resolve www.jane.test:443:127.0.0.1 -o /dev/null -w '%{http_code}' -H 'Host: jane.test' https://www.jane.test/\")\" = 421 ]"
check 'an unknown domain gets no certificate' "! inside \"curl -sk $resolve -o /dev/null https://evil.test/\""
check 'the site publishes no ports' "[ -z \"\$(inside 'docker port jane-php-1')\" ]"
caps=$(inside "docker inspect jane-php-1 jane-worker-1 --format '{{.HostConfig.CapDrop}} {{.HostConfig.CapAdd}} {{.HostConfig.SecurityOpt}}'" || true)
echo "$caps" | sed 's/^/    /'
check 'the site runs without capabilities (the web one: only to bind port 80)' "[ \"\$(echo \"\$caps\" | head -1)\" = '[ALL] [CAP_NET_BIND_SERVICE] [no-new-privileges:true]' ] && [ \"\$(echo \"\$caps\" | tail -1)\" = '[ALL] [] [no-new-privileges:true]' ]"
check 'the site has no own PostgreSQL (the shared one)' "! inside 'docker ps --format \"{{.Names}}\"' | grep -q jane-database"
check 'its role opens its database' "inside 'docker exec jane-php-1 php bin/console dbal:run-sql -q \"SELECT 1\"'"
docker cp "$here/tests/e2e/try-databases.php" "$dind:/root/try-databases.php"
inside 'docker cp /root/try-databases.php jane-php-1:/app/var/try-databases.php'
tried=$(inside 'docker exec jane-php-1 php /app/var/try-databases.php' || true)
echo "$tried" | sed 's/^/    /'
check 'its role is refused the server'\''s databases (with its real password)' "[ \"\$(echo \"\$tried\" | grep -Ec ': (refused|denied)\$')\" = 3 ]"
check 'the site has no way to Docker (no socket, no gate on its networks)' "! inside 'docker inspect jane-php-1 jane-worker-1 --format \"{{range .Mounts}}{{.Source}} {{.Name}} {{end}}\"' | grep -Eq 'docker.sock|gate_'"
# The gate holds what Docker receives for the site even where the Compose policy would miss something:
# requests sent straight to the site's socket, as its Compose's are (in the agent, which mounts them).
site_docker() { inside "docker exec quire-server-agent-1 env DOCKER_HOST=unix:///run/quire-gate/sites/jane.sock docker $1 2>&1"; }
# On the site's own network: the gate refuses Docker's default one (a container joins its scope's networks)
own='--network jane_default --label com.docker.compose.project=jane --cap-drop ALL --security-opt no-new-privileges --memory 64m --pids-limit 64'
check 'the gate: a container as the policy allows it starts (a control)' "site_docker 'run --rm --name jane-probe $own alpine:3.22 true'"
check 'the gate: a privileged container is refused' "site_docker 'run --rm --name jane-probe $own --privileged alpine:3.22 true' | grep -qi privileged"
check 'the gate: the host'\''s files are refused' "! site_docker 'run --rm --name jane-probe $own -v /:/host alpine:3.22 true' >/dev/null && ! site_docker 'volume create --label com.docker.compose.project=jane --opt type=none --opt o=bind --opt device=/ jane_host' >/dev/null"
check 'the gate: a capability the policy doesn'\''t give is refused' "site_docker 'run --rm --name jane-probe $own --cap-add SYS_ADMIN alpine:3.22 true' | grep -qi SYS_ADMIN"
check 'the gate: the host'\''s namespaces are refused' "! site_docker 'run --rm --name jane-probe $own --pid host alpine:3.22 true' >/dev/null"
check 'the gate: another project'\''s name is refused' "! site_docker 'run --rm --name other-probe --label com.docker.compose.project=other --cap-drop ALL --security-opt no-new-privileges --memory 64m --pids-limit 64 alpine:3.22 true' >/dev/null"
check 'the gate: the server'\''s own containers aren'\''t there for a site' "site_docker 'exec quire-server-database-1 id' | grep -q 'No such container' && site_docker 'container inspect quire-server-agent-1' | grep -q 'No such container'"
check 'the gate: a shell in the site'\''s builder is refused (it may only build)' "site_docker 'exec quire-buildkit-jane id' | grep -q 'dial-stdio'"
check 'the gate: an image can'\''t be pushed' "! site_docker 'push quire-sites/jane/php-prod' >/dev/null"
check 'the gate: an image can'\''t be named in another'\''s place' "! site_docker 'tag quire-sites/jane/php-prod quire-sites/other/php-prod' >/dev/null && ! site_docker 'tag quire-sites/jane/php-prod quire-server-agent:latest' >/dev/null"
check 'the site'\''s build ran on its own socket, its image loaded there' "inside 'quire-server logs gate' | grep '\"scope\":\"jane\"' | grep -q '\"endpoint\":\"image.load\"'"
builder=$(inside "docker inspect quire-buildkit-jane --format '{{.HostConfig.Privileged}} {{.Config.User}} {{.HostConfig.CapAdd}} {{.HostConfig.NetworkMode}}'" || true)
echo "    builder: $builder"
check 'its builder is rootless: not privileged, user 1000, no capabilities, on the sandbox network' "[ \"\$builder\" = 'false 1000:1000 [] quire-sandbox' ]"
check 'its settings are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/sites/jane/prod.env')\" = 600 ]"
check 'the operation is recorded' "inside 'quire-server operations' | grep -q 'site.add.*succeeded'"
operation=$(inside 'quire-server operations' | grep site.add | head -1 | awk '{print $1}')
password=$(inside "grep '^QUIRE_DATABASE_PASSWORD=' /srv/quire/sites/jane/prod.env | cut -d\\' -f2")
check 'its log has the steps, and no secret' "inside 'quire-server operations $operation' | grep -q 'Opening it to visitors' && ! inside 'quire-server operations $operation' | grep -q '$password'"

echo '== Updating the site'
check 'its versions are recorded in its folder' "inside 'test -f /srv/quire/sites/jane/composer.lock'"
check 'the front door says "back in a moment" while a site is down' "inside 'docker stop jane-php-1 >/dev/null' && inside \"curl -sk $resolve -D- https://jane.test/\" | grep -q '^HTTP/2 503' && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Back in a moment'; inside 'docker start jane-php-1 >/dev/null; sleep 15'"
check 'with a domain public DNS knows too, not a loop to the front door itself' "inside 'docker stop jane-php-1 >/dev/null' && [ \"\$(inside \"curl -sk --resolve jane.127.0.0.1.sslip.io:443:127.0.0.1 -o /dev/null -w '%{http_code}' https://jane.127.0.0.1.sslip.io/\")\" = 503 ]; inside 'docker start jane-php-1 >/dev/null; sleep 15'"
# An older Symfony YAML with a known vulnerability, as if the site had been made a while ago: built and
# running. (Composer refuses such versions: --no-blocking, only here, to make the site an old one.)
inside 'docker exec quire-server-agent-1 composer update symfony/yaml --with=symfony/yaml:8.1.0 --with-all-dependencies --no-blocking --no-install --no-scripts --no-plugins --ignore-platform-req=ext-* --no-interaction -q --working-dir=/srv/quire/sites/jane' 2>&1 | sed 's/^/    /'
inside 'cd /srv/quire/sites/jane && docker compose --env-file prod.env up --detach --wait --build > /root/rebuild.log 2>&1 || { tail -40 /root/rebuild.log; docker compose --env-file prod.env ps -a; docker compose --env-file prod.env logs --tail 30; exit 1; }'
check 'it runs an older symfony/yaml' "inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
check 'the audit finds its vulnerability' "inside 'quire-server site:audit jane 2>&1' | grep -q 'symfony/yaml'"
inside 'quire-server site:update jane --dry-run 2>&1' | tee "$logs"/plan.log | sed 's/^/    /'
check 'the plan shows the update fixes it, and changes nothing' "grep -q 'Fixes symfony/yaml' $logs/plan.log && grep -q 'Nothing changed' $logs/plan.log && inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
inside 'quire-server site:update jane --yes 2>&1' | tee "$logs"/update.log | sed 's/^/    /'
check 'the site is updated' "grep -q 'is up to date' $logs/update.log && ! inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
check 'it answers after its update' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
check 'the image it ran is kept to undo' "inside 'docker image inspect quire-sites/jane/php-prod:before-update' >/dev/null"
inside 'quire-server site:undo jane --yes 2>&1' | tee "$logs"/undo.log | sed 's/^/    /'
check 'undoing it puts the older version back' "grep -q 'as it was before its update' $logs/undo.log && inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"' && [ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"

# A runtime image not signed by Quire's runtime workflow (a tag that never is): nothing is built on it.
inside "sed -i '1a ARG RUNTIME=ghcr.io/quirecms/runtime:0.0-unsigned' /srv/quire/sites/jane/Dockerfile"
inside 'quire-server site:update jane --yes 2>&1' | tee "$logs"/unsigned.log | sed 's/^/    /'
check 'a runtime image that doesn'\''t check out isn'\''t built on' "grep -q 'runtime:0.0-unsigned didn.t check out' $logs/unsigned.log && grep -q 'the site is as it was before' $logs/unsigned.log && inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
inside "sed -i '/0.0-unsigned/d' /srv/quire/sites/jane/Dockerfile"

# A broken update: the site's own code fails every request in the new version.
inside 'mkdir -p /srv/quire/sites/jane/src/EventListener && printf "%s\n" "<?php" "namespace App\\EventListener;" "use Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener;" "use Symfony\\Component\\HttpKernel\\Event\\RequestEvent;" "final class Broken { #[AsEventListener] public function __invoke(RequestEvent \$event): void { throw new \\RuntimeException(\"broken on purpose\"); } }" > /srv/quire/sites/jane/src/EventListener/Broken.php'
inside 'quire-server site:update jane --yes 2>&1' | tee "$logs"/broken.log | sed 's/^/    /'
check 'a broken update is rolled back by itself' "grep -q 'the site is as it was before' $logs/broken.log && inside 'quire-server operations' | grep -q 'site.update.*failed'"
check 'the site answers as before' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
# Quire 0.1.1 and later say how many migrations ran (quire:about): none did, so its data was kept.
check 'its database is kept: no migrations ran, as quire:about said' "! inside \"quire-server operations \$(inside 'quire-server operations' | grep site.update | head -1 | awk '{print \$1}')\" | grep -q 'Restoring its backup'"
check 'it is still running' "inside 'quire-server site:list' | grep jane | grep -q running"
inside 'rm -rf /srv/quire/sites/jane/src/EventListener'

echo '== Automatic updates (what the night does, run now)'
check 'a site gets security updates by default' "inside 'quire-server site:updates jane' | grep -q 'fix a known vulnerability'"
inside 'quire-server site:updates jane off >/dev/null'
# (The adopted site, in a run of every part, still gets its own: only jane's are off.)
inside 'quire-server site:update --all --automatic 2>&1' > "$logs"/automatic-off.log || true
check 'a site whose automatic updates are off gets none' "! grep -q jane $logs/automatic-off.log && grep -Eq 'No site gets automatic updates|demo' $logs/automatic-off.log"
inside 'quire-server site:updates jane security >/dev/null'
inside 'quire-server site:update --all --automatic 2>&1' | tee "$logs"/automatic.log | sed 's/^/    /'
check 'its known vulnerability is fixed by itself' "grep -Eq 'jane +updated +fixes symfony/yaml' $logs/automatic.log && ! inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
check 'it is recorded as made by itself' "inside 'quire-server operations' | grep site.update | head -1 | grep -q 'automatic'"
check 'it answers after its automatic update' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
inside 'quire-server site:update --all --automatic 2>&1' > "$logs"/automatic-again.log
check 'the next run finds nothing to fix' "grep -Eq 'jane +up to date +no known vulnerability' $logs/automatic-again.log"
check 'Composer ran in a container of its own, gone after' "[ -z \"\$(inside 'docker ps -aq --filter label=com.quirecms.server.sandbox')\" ] && inside 'docker network inspect quire-sandbox'"
check 'that container doesn'\''t reach the database (nor the gate, which has no network)' "! inside 'docker run --rm --network quire-sandbox alpine:3.22 sh -c \"nc -zw5 quire-database 5432\"'"
# A composer.json in the site's folder that has Composer post to a plain-HTTP address (the docker-proxy's, before the gate)
inside 'cp /srv/quire/sites/jane/composer.json /root/jane-composer.json && sed -i "s#^    \"type\": \"project\",#    \"type\": \"project\", \"repositories\": [{\"type\": \"composer\", \"url\": \"http://docker-proxy:2375\", \"options\": {\"http\": {\"method\": \"POST\"}}}], \"config\": {\"secure-http\": false},#" /srv/quire/sites/jane/composer.json'
check 'a composer.json fetching other than over HTTPS is refused' "inside 'quire-server site:audit jane 2>&1' | tr -s ' \\n' ' ' | grep -q 'over HTTPS only' && inside 'quire-server site:update jane --dry-run 2>&1' | tr -s ' \\n' ' ' | grep -q 'over HTTPS only'"
inside 'cp /root/jane-composer.json /srv/quire/sites/jane/composer.json'
fi

if want git; then
echo '== A site in Git mode (a repository on an SSH Git server, deployed from it)'
# A Git server only the agent's network reaches, accepting nothing but the keys added to it.
inside 'docker run -d --name git-server --network quire-data alpine:3.22 sh -c "apk add -q --no-cache openssh git && ssh-keygen -A && adduser -D -s /usr/bin/git-shell git && sed -i \"s/^git:!:/git:*:/\" /etc/shadow && mkdir -p /home/git/.ssh && touch /home/git/.ssh/authorized_keys && chown -R git:git /home/git && chmod 700 /home/git/.ssh && exec /usr/sbin/sshd -D -e" >/dev/null'
inside 'docker run --rm -v /root/blog:/root/blog composer:2.10.3 create-project quire/skeleton /root/blog/w "^0.1" --no-install --no-scripts --no-plugins --no-interaction --no-progress -q'
until inside 'docker exec git-server sh -c "id git && pgrep sshd"' >/dev/null 2>&1; do sleep 2; done
commit() { inside "docker exec git-server sh -c 'cd /tmp/w && $1 && git add -A && git -c user.email=dev@example.com -c user.name=Dev commit -qm \"$2\" && git push -q /home/git/blog.git main && chown -R git:git /home/git/blog.git'"; }
inside 'tar -C /root/blog/w -cf - . | docker exec -i git-server sh -c "mkdir -p /tmp/w && tar -C /tmp/w -xf - && cd /tmp/w && git init -q -b main && git init -q --bare -b main /home/git/blog.git && git config --global --add safe.directory /home/git/blog.git"'
commit 'true' 'The site'
key=$(inside 'quire-server site:key blog' | grep '^ssh-ed25519 ')
inside "docker exec git-server sh -c 'echo \"$key\" >> /home/git/.ssh/authorized_keys'"
check 'its deploy key is root'\''s only, outside its folder' "[ \"\$(inside 'stat -c %a /srv/quire/server/keys/blog /srv/quire/server/keys/blog/id_ed25519' | tr '\n' ' ')\" = '700 600 ' ]"
inside 'quire-server site:add blog --git=ssh://git@git-server/home/git/blog.git --branch=main --domain=blog.test --admin-email=dev@example.com 2>&1' | tee "$logs"/git-add.log | sed 's/^/    /'
gresolve='--resolve blog.test:443:127.0.0.1'
check 'it runs its repository'\''s branch' "[ \"\$(inside \"curl -sk $gresolve -o /dev/null -w '%{http_code}' https://blog.test/\")\" = 200 ]"
check 'its checkout is clean (the server'\''s files excluded)' "[ -z \"\$(inside 'git -C /srv/quire/sites/blog status --porcelain')\" ] && inside 'test -f /srv/quire/sites/blog/prod.env'"
check 'no key in its folder or its image' "! inside 'grep -rl \"PRIVATE KEY\" /srv/quire/sites/blog' && ! inside 'docker exec blog-php-1 grep -rl \"PRIVATE KEY\" /app'"
commit 'mkdir -p public && echo two > public/hello.txt' 'Two'
inside 'quire-server site:deploy blog 2>&1' | sed 's/^/    /'
check 'a deploy runs the new commit' "[ \"\$(inside \"curl -sk $gresolve https://blog.test/hello.txt\")\" = two ]"
# A commit whose Compose file asks for one of the server's settings (Compose fills in "${…}" from the
# environment it runs in before the site's env file)
inside "printf '%s\n' '{print} /^    environment:\$/ && !done {print \"      QUIRE_LEAK: \${QUIRE_SERVER_POSTGRES_ADMIN_URL:-none}\"; done = 1}' > /root/leak.awk && docker cp /root/leak.awk git-server:/tmp/leak.awk"
commit 'awk -f /tmp/leak.awk compose.prod.yaml > /tmp/c && cat /tmp/c > compose.prod.yaml' 'Settings'
inside 'quire-server site:deploy blog 2>&1' | sed 's/^/    /'
check 'a site'\''s Compose file gets none of the server'\''s settings' "[ \"\$(inside 'docker exec blog-php-1 printenv QUIRE_LEAK')\" = none ]"
check 'it runs with its own APP_SECRET, not the agent'\''s' "s=\$(inside 'docker exec blog-php-1 printenv APP_SECRET') && [ -n \"\$s\" ] && inside \"grep -qxF \\\"APP_SECRET='\$s'\\\" /srv/quire/sites/blog/prod.env\" && [ \"\$s\" != \"\$(inside 'docker exec quire-server-agent-1 printenv APP_SECRET')\" ]"
# Commits reading files outside the site (another site's prod.env could be named), then its own server files
commit 'printf "include:\n  - path: compose.yaml\n    env_file: ../../server/server.env\n" >> compose.prod.yaml' 'Include'
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-include.log | sed 's/^/    /'
check 'a commit including other files is refused, never read' "grep -q '\"include\" reads other files' $logs/git-include.log && grep -q 'as it was before' $logs/git-include.log"
commit 'git revert --no-commit HEAD' 'Back'
commit 'printf "services: {}\n" > compose.server.yaml' 'Its own server file'
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-tracked.log | sed 's/^/    /'
check 'a commit with the server'\''s own files is refused, they stay' "grep -q 'the server.s own files' $logs/git-tracked.log && inside 'grep -q cap_drop /srv/quire/sites/blog/compose.server.yaml'"
commit 'git revert --no-commit HEAD' 'Back again'
check 'its image is named in its own namespace' "[ \"\$(inside 'docker inspect blog-php-1 --format \"{{.Config.Image}}\"')\" = quire-sites/blog/php-prod ]"
# A commit building an image under another site's name (its next start would run this code)
inside "printf '%s\n' '{print} /^services:\$/ && !done {print \"  squat:\"; print \"    build: .\"; print \"    image: quire-sites/jane/php-prod\"; done = 1}' > /root/squat.awk && docker cp /root/squat.awk git-server:/tmp/squat.awk"
commit 'awk -f /tmp/squat.awk compose.prod.yaml > /tmp/c && cat /tmp/c > compose.prod.yaml' 'Squat'
# jane's image as it is (none, unless the sites part made jane in this run)
jane_image() { inside 'docker image inspect --format "{{.Id}}" quire-sites/jane/php-prod 2>/dev/null' || true; }
jane_before=$(jane_image)
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-squat.log | sed 's/^/    /'
check 'a commit naming another site'\''s image is refused, never built' "grep -q 'quire-sites/jane/php-prod' $logs/git-squat.log && grep -q 'as it was before' $logs/git-squat.log && [ \"\$(jane_image)\" = '$jane_before' ]"
commit 'git revert --no-commit HEAD' 'Back'
# A commit adding a service with Docker's defaults (NET_RAW) in the web container's network
inside "printf '%s\n' '{print} /^services:\$/ && !done {print \"  sidecar:\"; print \"    image: alpine:3.22\"; print \"    network_mode: service:php\"; done = 1}' > /root/sidecar.awk && docker cp /root/sidecar.awk git-server:/tmp/sidecar.awk"
commit 'awk -f /tmp/sidecar.awk compose.prod.yaml > /tmp/c && cat /tmp/c > compose.prod.yaml' 'Sidecar'
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-sidecar.log | sed 's/^/    /'
check 'a commit adding a service with Docker'\''s defaults is refused, never started' "tr -s ' \\n' ' ' < $logs/git-sidecar.log | grep -q 'cap_drop: \[ALL\]. is missing' && ! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^blog-sidecar'"
commit 'git revert --no-commit HEAD' 'Back again'
inside "printf '\nQUIRE_SERVER_GIT_POLL_SECONDS=60\n' >> /srv/quire/server/server.env && quire-server up >/dev/null 2>&1 && quire-server site:poll blog on >/dev/null"
commit 'echo three > public/hello.txt' 'Three'
waited=0
# Until the agent's deploy has finished (not just the new version answering: it's checked and recorded after).
until inside 'quire-server operations --site=blog' | grep 'site.deploy' | head -1 | grep -q 'polling.*succeeded' || [ $waited -ge 420 ]; do sleep 15; waited=$((waited + 15)); done
check 'the agent deploys a new commit by itself' "[ \"\$(inside \"curl -sk $gresolve https://blog.test/hello.txt\")\" = three ] && inside 'quire-server operations --site=blog' | grep 'site.deploy' | grep -q polling"
inside 'quire-server site:poll blog off >/dev/null'
inside 'quire-server site:undo blog --yes 2>&1' | sed 's/^/    /'
check 'undoing a deploy runs the commit before' "[ \"\$(inside \"curl -sk $gresolve https://blog.test/hello.txt\")\" = two ]"
commit 'ln -sf /srv/quire/server/keys/blog/id_ed25519 public/key.txt' 'A link to its own key'
inside 'quire-server site:deploy blog 2>&1' | sed 's/^/    /'
check 'a symbolic link in the repository is a plain file, not the key' "inside \"curl -sk $gresolve https://blog.test/key.txt\" | grep -q '^/srv/quire/server/keys/blog/id_ed25519' && ! inside \"curl -sk $gresolve https://blog.test/key.txt\" | grep -q 'PRIVATE KEY'"
# A commit adding a service on the docker-proxy's network (which is root on the host)
inside "printf '%s\n' '{print} /^services:\$/ && !done {print \"  proxied:\"; print \"    image: alpine:3.22\"; print \"    network_mode: quire-docker\"; done = 1}' > /root/network.awk && docker cp /root/network.awk git-server:/tmp/network.awk"
commit 'awk -f /tmp/network.awk compose.prod.yaml > /tmp/c && cat /tmp/c > compose.prod.yaml' 'Network'
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-network.log | sed 's/^/    /'
check 'a commit joining another network is refused, never started' "grep -q 'network_mode: quire-docker' $logs/git-network.log && grep -q 'as it was before' $logs/git-network.log && ! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^blog-proxied'"
# A commit giving the site's web container every privilege
inside "printf '%s\n' '{print} /^  php:\$/ && !done {print \"    privileged: true\"; done = 1}' > /root/privileged.awk && docker cp /root/privileged.awk git-server:/tmp/privileged.awk"
commit 'awk -f /tmp/privileged.awk compose.prod.yaml > /tmp/c && cat /tmp/c > compose.prod.yaml' 'Privileged'
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-hostile.log | sed 's/^/    /'
check 'a commit asking for privileges is refused, never built' "grep -q 'privileged' $logs/git-hostile.log && grep -q 'as it was before' $logs/git-hostile.log && [ \"\$(inside 'docker inspect blog-php-1 --format \"{{.HostConfig.Privileged}}\"')\" = false ] && [ \"\$(inside \"curl -sk $gresolve -o /dev/null -w '%{http_code}' https://blog.test/key.txt\")\" = 200 ]"
check 'updates of a Git mode site are made in its repository' "inside 'quire-server site:update blog --yes 2>&1' | grep -q 'follows a Git repository'"
inside 'quire-server site:remove blog --yes 2>&1' | sed 's/^/    /'
check 'removing it deletes its deploy key' "! inside 'test -e /srv/quire/server/keys/blog'"
fi

if [ "${KEEP:-}" = 1 ]; then echo "$failures check(s) failed so far; the site is kept."; exit 0; fi

if want sites; then
echo '== A release a crash cut short, put back at the agent'\''s start'
# As a power cut in the middle of a rebuild leaves it: its intent, naming the image it ran before (the
# one kept to undo its update, which differs from the one it runs now)
before=$(inside "docker image inspect quire-sites/jane/php-prod:before-update jane-php-prod:before-update --format '{{.Id}}' 2>/dev/null" | head -1)
image=$(inside "docker inspect jane-php-1 --format '{{.Config.Image}}'")
inside "printf '{\"operation\": \"x\", \"what\": \"rebuild\", \"image\": \"%s\", \"image_before\": \"%s\", \"backup\": null, \"put_back\": []}\n' '$image' '$before' > /srv/quire/backups/jane/release.json && chmod 600 /srv/quire/backups/jane/release.json"
inside 'docker restart quire-server-agent-1 >/dev/null'
waited=0
until inside 'quire-server operations --site=jane' | grep site.recover | grep -qv running || [ $waited -ge 300 ]; do sleep 10; waited=$((waited + 10)); done
check 'the agent puts the site back as it was before, by itself' "inside 'quire-server operations --site=jane' | grep -q 'site.recover.*succeeded' && [ \"\$(inside \"docker inspect jane-php-1 --format '{{.Image}}'\")\" = \"$before\" ] && ! inside 'test -e /srv/quire/backups/jane/release.json' && [ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"

echo '== Removing the site'
inside 'quire-server site:remove jane --yes 2>&1' | sed 's/^/    /'
check 'its final backup is kept' "inside 'ls /srv/quire/backups/jane/final-*.tar'"
check 'its folder is archived, not deleted' "inside 'ls -d /srv/quire/sites/.removed/jane-*'"
# (Caddy keeps a certificate it already has in its cache: the handshake may still succeed, but nothing is served.)
check 'it is no longer served (421)' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 421 ]"
check 'its containers are gone' "! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^jane-'"
check 'its database is gone' "pg \"SELECT count(*) FROM pg_database WHERE datname = 'site_jane'\" | grep -qx 0"

fi

if want adopt; then
echo '== Removing the adopted site'
inside 'quire-server site:remove demo --yes 2>&1' | sed 's/^/    /'
check 'its final backup is kept' "inside 'ls /srv/quire/backups/demo/final-*.tar'"
check 'its containers and its own database'\''s volume are gone' "! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^demo-' && ! inside 'docker volume ls -q' | grep -q '^demo_database_data\$'"
check 'the server'\''s PostgreSQL still runs' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'database healthy'"
fi

echo
if [ "$failures" -eq 0 ]; then echo 'All checks passed.'; else echo "$failures check(s) failed."; exit 1; fi
