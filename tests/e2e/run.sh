#!/bin/sh
# Quire Server end to end, in a throwaway Docker-in-Docker container: the stack, a real site from
# Packagist added and removed with quire-server, the front door's routing and refusals, the site's
# isolation. Nothing touches the machine's own Docker but that one container.
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

echo '== Installing the server'
inside 'mkdir -p /srv/quire/sites /srv/quire/backups /srv/quire/server/domains && chmod 700 /srv/quire/sites /srv/quire/backups'
tar -C "$here" --exclude=./vendor --exclude=./var --exclude=./tests -cf - . | docker exec -i "$dind" tar -C /srv/quire/server -xf -
inside 'install -m 755 /srv/quire/server/bin/quire-server /usr/local/bin/quire-server'
inside 'umask 077; printf "QUIRE_SERVER_APP_SECRET=%s\nQUIRE_SERVER_POSTGRES_PASSWORD=%s\nQUIRE_SERVER_DATABASE_PASSWORD=%s\nQUIRE_SERVER_TLS_GLOBAL=local_certs\nQUIRE_SERVER_QUIRE_VERSION=^0.1\n" $(openssl rand -hex 32) $(openssl rand -hex 24) $(openssl rand -hex 24) > /srv/quire/server/server.env'

if [ -n "$image" ]; then
	docker save "$image" | docker exec -i "$dind" docker load -q
	inside "docker tag $image quire-server-agent"
fi

inside 'quire-server up' >/dev/null
check 'the stack is up and healthy' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\" | grep -q \"agent healthy\"'"
published=$(inside "docker ps --format '{{.Names}} {{.Ports}}'" | grep -c '0.0.0.0' || true)
check 'only the front door publishes ports' "[ $published = 1 ]"
check 'the docker-proxy network has no way out' "inside 'docker network inspect quire-docker --format \"{{.Internal}}\"' | grep -q true"

echo '== Adding a site (a few minutes: it is built)'
inside 'quire-server site:add jane --domain=jane.test --domain=www.jane.test --admin-email=jane@example.com --title="Jane" 2>&1' | tee /tmp/quire-e2e-add.log | sed 's/^/    /'
check 'the site runs' "inside 'quire-server site:list' | grep -q running"
check 'a password was shown once (no email server)' "grep -q 'signs in with the password' /tmp/quire-e2e-add.log"

resolve='--resolve jane.test:443:127.0.0.1 --resolve www.jane.test:443:127.0.0.1 --resolve evil.test:443:127.0.0.1'
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
check 'its secrets are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/sites/jane/prod.env')\" = 600 ]"
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

echo
if [ "$failures" -eq 0 ]; then echo 'All checks passed.'; else echo "$failures check(s) failed."; exit 1; fi
