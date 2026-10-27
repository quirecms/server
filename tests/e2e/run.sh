#!/bin/sh
# Quire Server end to end, in a throwaway Docker-in-Docker container: a site installed by hand, then
# the server installed with install.sh (which adopts it), a real site from Packagist added and
# removed with quire-server, the front door's routing and refusals, the sites' isolation. Nothing touches the machine's own Docker but that one container.
#
#   server/tests/e2e/run.sh [agent-image]
#   KEEP=1 server/tests/e2e/run.sh …     keeps the machine and the site afterwards, to look around
#
# With an agent image (e.g. one built with "docker build -t quire-server-agent:test server"), it's
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

cleanup() {
	if [ "${KEEP:-}" = 1 ]; then echo "Kept: docker exec -it $dind sh  (remove it with: docker rm -f -v $dind)"; return; fi
	docker rm -f -v "$dind" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo '== A machine of its own (Docker-in-Docker)'
docker run -d --privileged --name "$dind" -e DOCKER_TLS_CERTDIR= docker:29.9.2-dind >/dev/null
until inside 'docker info' >/dev/null 2>&1; do sleep 1; done
inside 'apk add -q --no-cache curl openssl'
# (Nothing else: install.sh needs only what a fresh machine has.)

echo '== A site installed by hand before the server (docs/docker.md): it holds ports 80 and 443'
inside 'mkdir -p /srv/quire/sites && docker run --rm -v /srv/quire/sites:/srv/quire/sites composer:2.10.3 create-project quire/skeleton /srv/quire/sites/demo "^0.1" --no-install --no-scripts --no-plugins --no-interaction --no-progress -q'
inside 'cd /srv/quire/sites/demo && umask 077 && printf "# By hand\nCOMPOSE_FILE=compose.yaml:compose.prod.yaml\nSERVER_NAME=demo.test\nAPP_SECRET=%s\nPOSTGRES_PASSWORD=%s\n" "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" > prod.env'
inside 'cd /srv/quire/sites/demo && docker compose --env-file prod.env up --detach --wait --build >/dev/null 2>&1 && docker compose --env-file prod.env exec -T php bin/console quire:install --no-interaction --title="Made by hand" --admin-email=demo@example.com >/dev/null'
check 'it holds port 443 itself' "inside 'docker port demo-php-1' | grep -q '443/tcp'"
# shellcheck disable=SC2034 # read by a check (eval)
demo_env=$(inside 'cat /srv/quire/sites/demo/prod.env')

echo '== Installing the server with install.sh (it adopts the site)'
docker exec "$dind" mkdir -p /root/quire-server
tar -C "$here" --exclude=./vendor --exclude=./var --exclude=./tests -cf - . | docker exec -i "$dind" tar -C /root/quire-server -xf -

if [ -n "$image" ]; then
	docker save "$image" | docker exec -i "$dind" docker load -q
	inside "docker tag $image quire-server-agent"
fi

inside 'sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates 2>&1' | tee /tmp/quire-e2e-install.log | sed 's/^/    /'
check 'the installer finished' "grep -q 'Quire Server runs' /tmp/quire-e2e-install.log"
check 'the stack is up and healthy' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\" | grep -q \"agent healthy\"'"
published=$(inside "docker ps --format '{{.Names}} {{.Ports}}'" | grep -c '0.0.0.0' || true)
check 'only the front door publishes ports' "[ $published = 1 ]"
check 'the docker-proxy network has no way out' "inside 'docker network inspect quire-docker --format \"{{.Internal}}\"' | grep -q true"
check 'its secrets are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/server/server.env')\" = 600 ] && [ \"\$(inside 'stat -c %a /srv/quire/sites')\" = 700 ]"

resolve='--resolve demo.test:443:127.0.0.1 --resolve jane.test:443:127.0.0.1 --resolve www.jane.test:443:127.0.0.1 --resolve evil.test:443:127.0.0.1'
check 'the adopted site is served by the front door, with its data' "inside \"curl -sk $resolve https://demo.test/\" | grep -q 'Made by hand'"
check 'it publishes no ports any more' "[ -z \"\$(inside 'docker port demo-php-1')\" ]"
check 'it keeps its own PostgreSQL, off the server'\''s network' "inside 'docker ps --format \"{{.Names}}\"' | grep -q '^demo-database-1\$' && ! inside 'docker inspect demo-php-1 --format \"{{json .NetworkSettings.Networks}}\"' | grep -q quire-data"
check 'it can'\''t reach the server'\''s PostgreSQL' "! inside 'docker exec demo-php-1 php -r \"exit(@fsockopen(\\\"quire-database\\\", 5432, \\\$e, \\\$m, 3) ? 0 : 1);\"'"
check 'it was backed up first' "inside 'ls /srv/quire/backups/demo/before-server-*.tar'"
check 'its settings as they were are kept (root only)' "[ \"\$(inside 'cat /srv/quire/sites/demo/prod.env.before-server')\" = \"\$demo_env\" ] && [ \"\$(inside 'stat -c %a /srv/quire/sites/demo/prod.env.before-server')\" = 600 ]"
check 'two lines of its prod.env changed' "inside 'grep -c compose.server.yaml /srv/quire/sites/demo/prod.env' | grep -q 1 && inside 'grep -q \"^COMPOSE_PROJECT_NAME='\\''demo'\\''\" /srv/quire/sites/demo/prod.env'"
check 'its image has its own name' "inside 'docker inspect demo-php-1 --format \"{{.Config.Image}}\"' | grep -q '^demo-php-prod'"
check 'the adoption is recorded' "inside 'quire-server operations' | grep -q 'site.adopt.*succeeded'"

server_env=$(inside 'sha256sum /srv/quire/server/server.env')
inside 'sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates 2>&1' > /tmp/quire-e2e-install-again.log || true
check 'running the installer again changes nothing' "grep -q 'installed in /srv/quire/server already' /tmp/quire-e2e-install-again.log && grep -q 'Quire Server runs' /tmp/quire-e2e-install-again.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"

echo '== Adding a site (a few minutes: it is built)'
inside 'quire-server site:add jane --domain=jane.test --domain=www.jane.test --admin-email=jane@example.com --title="Jane" 2>&1' | tee /tmp/quire-e2e-add.log | sed 's/^/    /'
check 'the site runs' "inside 'quire-server site:list' | grep -q running"
check 'a password was shown once (no email server)' "grep -q 'signs in with the password' /tmp/quire-e2e-add.log"

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
check 'its role is refused the server'\''s databases (with its real password)' "[ \"\$(echo \"\$tried\" | grep -c ': denied')\" = 3 ]"
check 'the site can'\''t reach the docker-proxy' "! inside 'docker exec jane-php-1 php -r \"exit(@fsockopen(\\\"docker-proxy\\\", 2375, \\\$e, \\\$m, 3) ? 0 : 1);\"'"
check 'its settings are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/sites/jane/prod.env')\" = 600 ]"
check 'the operation is recorded' "inside 'quire-server operations' | grep -q 'site.add.*succeeded'"
operation=$(inside 'quire-server operations' | grep site.add | head -1 | awk '{print $1}')
password=$(inside "grep '^QUIRE_DATABASE_PASSWORD=' /srv/quire/sites/jane/prod.env | cut -d\\' -f2")
check 'its log has the steps, and no secret' "inside 'quire-server operations $operation' | grep -q 'Opening it to visitors' && ! inside 'quire-server operations $operation' | grep -q '$password'"

if [ "${KEEP:-}" = 1 ]; then echo "$failures check(s) failed so far; the site is kept."; exit 0; fi

echo '== Removing the site'
inside 'quire-server site:remove jane --yes 2>&1' | sed 's/^/    /'
check 'its final backup is kept' "inside 'ls /srv/quire/backups/jane/final-*.tar'"
check 'its folder is archived, not deleted' "inside 'ls -d /srv/quire/sites/.removed/jane-*'"
# (Caddy keeps a certificate it already has in its cache: the handshake may still succeed, but nothing is served.)
check 'it is no longer served (421)' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 421 ]"
check 'its containers are gone' "! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^jane-'"
check 'its database is gone' "! inside 'docker exec quire-server-database-1 psql -U postgres -Atc \"SELECT 1 FROM pg_database WHERE datname = '\''site_jane'\''\"' | grep -q 1"

echo '== Removing the adopted site'
inside 'quire-server site:remove demo --yes 2>&1' | sed 's/^/    /'
check 'its final backup is kept' "inside 'ls /srv/quire/backups/demo/final-*.tar'"
check 'its containers and its own database'\''s volume are gone' "! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^demo-' && ! inside 'docker volume ls -q' | grep -q '^demo_database_data\$'"
check 'the server'\''s PostgreSQL still runs' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\"' | grep -q 'database healthy'"

echo
if [ "$failures" -eq 0 ]; then echo 'All checks passed.'; else echo "$failures check(s) failed."; exit 1; fi
