#!/bin/sh
# Quire Server end to end, in a throwaway Docker-in-Docker container: a site installed by hand, then
# the server installed with install.sh (which adopts it), a real site from Packagist added and
# removed with quire-server, the front door's routing and refusals, the sites' isolation. Nothing touches the machine's own Docker but that one container.
#
#   tests/e2e/run.sh [agent-image]
#   KEEP=1 tests/e2e/run.sh …          keeps the machine and the sites afterwards, to look around
#   E2E_ONLY=git tests/e2e/run.sh …    the server and one part only: adopt, sites or git
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
# Whether a part runs (all of them unless E2E_ONLY names one)
want() { [ -z "${E2E_ONLY:-}" ] || [ "$E2E_ONLY" = "$1" ]; }
cache=''
if [ "${E2E_CACHE:-}" = 1 ]; then cache='-v quire-server-e2e-cache:/var/lib/docker'; fi
# shellcheck disable=SC2086 # $cache is empty or two words
docker run -d --privileged --name "$dind" $cache -e DOCKER_TLS_CERTDIR= docker:29.9.2-dind >/dev/null
until inside 'docker info' >/dev/null 2>&1; do sleep 1; done
# With the cache, what an earlier run left but its images and build cache
# (Its containers restart with the cached Docker: removed, and the folders their mounts left behind.)
if [ -n "$cache" ]; then inside 'docker rm -f $(docker ps -aq) >/dev/null 2>&1; docker volume prune -af >/dev/null; docker network prune -f >/dev/null; rm -rf /srv/quire /root/blog; true'; fi
inside 'apk add -q --no-cache curl openssl'
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

if [ -n "$image" ]; then
	docker save "$image" | docker exec -i "$dind" docker load -q
	inside "docker tag $image quire-server-agent"
fi

inside 'sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates 2>&1' | tee "$logs"/install.log | sed 's/^/    /'
check 'the installer finished' "grep -q 'Quire Server runs' $logs/install.log"
check 'the stack is up and healthy' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\" | grep -q \"agent healthy\"'"
published=$(inside "docker ps --format '{{.Names}} {{.Ports}}'" | grep -c '0.0.0.0' || true)
check 'only the front door publishes ports' "[ $published = 1 ]"
check 'it says it was built from a checkout (no release image)' "inside 'quire-server version' | grep -q 'built from a checkout'"
check 'every image of the stack is pinned by digest' "! inside 'cd /srv/quire/server && docker compose --env-file server.env config --images' | grep -v '^quire-server-agent\$' | grep -vq '@sha256:'"
check 'the docker-proxy network has no way out' "inside 'docker network inspect quire-docker --format \"{{.Internal}}\"' | grep -q true"
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
check 'its image has its own name' "inside 'docker inspect demo-php-1 --format \"{{.Config.Image}}\"' | grep -q '^demo-php-prod'"
check 'the adoption is recorded' "inside 'quire-server operations' | grep -q 'site.adopt.*succeeded'"
check 'its versions are recorded in its folder' "inside 'test -f /srv/quire/sites/demo/composer.lock'"
fi

server_env=$(inside 'sha256sum /srv/quire/server/server.env')
inside 'sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates 2>&1' > "$logs"/install-again.log || true
check 'running the installer again changes nothing' "grep -q 'installed in /srv/quire/server already' $logs/install-again.log && grep -q 'Quire Server runs' $logs/install-again.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"

if want sites; then
echo '== Adding a site (a few minutes: it is built)'
inside 'quire-server site:add jane --domain=jane.test --domain=www.jane.test --admin-email=jane@example.com --title="Jane" 2>&1' | tee "$logs"/add.log | sed 's/^/    /'
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
check 'its role is refused the server'\''s databases (with its real password)' "[ \"\$(echo \"\$tried\" | grep -c ': denied')\" = 3 ]"
check 'the site can'\''t reach the docker-proxy' "! inside 'docker exec jane-php-1 php -r \"exit(@fsockopen(\\\"docker-proxy\\\", 2375, \\\$e, \\\$m, 3) ? 0 : 1);\"'"
check 'its settings are root'\''s only' "[ \"\$(inside 'stat -c %a /srv/quire/sites/jane/prod.env')\" = 600 ]"
check 'the operation is recorded' "inside 'quire-server operations' | grep -q 'site.add.*succeeded'"
operation=$(inside 'quire-server operations' | grep site.add | head -1 | awk '{print $1}')
password=$(inside "grep '^QUIRE_DATABASE_PASSWORD=' /srv/quire/sites/jane/prod.env | cut -d\\' -f2")
check 'its log has the steps, and no secret' "inside 'quire-server operations $operation' | grep -q 'Opening it to visitors' && ! inside 'quire-server operations $operation' | grep -q '$password'"

echo '== Updating the site'
check 'its versions are recorded in its folder' "inside 'test -f /srv/quire/sites/jane/composer.lock'"
check 'the front door says "back in a moment" while a site is down' "inside 'docker stop jane-php-1 >/dev/null' && inside \"curl -sk $resolve -D- https://jane.test/\" | grep -q '^HTTP/2 503' && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Back in a moment'; inside 'docker start jane-php-1 >/dev/null; sleep 15'"
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
check 'the image it ran is kept to undo' "inside 'docker image inspect jane-php-prod:before-update' >/dev/null"
inside 'quire-server site:undo jane --yes 2>&1' | tee "$logs"/undo.log | sed 's/^/    /'
check 'undoing it puts the older version back' "grep -q 'as it was before its update' $logs/undo.log && inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"' && [ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"

# A broken update: the site's own code fails every request in the new version.
inside 'mkdir -p /srv/quire/sites/jane/src/EventListener && printf "%s\n" "<?php" "namespace App\\EventListener;" "use Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener;" "use Symfony\\Component\\HttpKernel\\Event\\RequestEvent;" "final class Broken { #[AsEventListener] public function __invoke(RequestEvent \$event): void { throw new \\RuntimeException(\"broken on purpose\"); } }" > /srv/quire/sites/jane/src/EventListener/Broken.php'
inside 'quire-server site:update jane --yes 2>&1' | tee "$logs"/broken.log | sed 's/^/    /'
check 'a broken update is rolled back by itself' "grep -q 'the site is as it was before' $logs/broken.log && inside 'quire-server operations' | grep -q 'site.update.*failed'"
check 'the site answers as before' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
# Quire 0.1.1 and later say how many migrations ran (quire:about): none did, so its data was kept.
check 'its database is kept: no migrations ran, as quire:about said' "! inside \"quire-server operations \$(inside 'quire-server operations' | grep site.update | head -1 | awk '{print \$1}')\" | grep -q 'Restoring its backup'"
check 'it is still running' "inside 'quire-server site:list' | grep jane | grep -q running"
inside 'rm -rf /srv/quire/sites/jane/src/EventListener'
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
echo '== Removing the site'
inside 'quire-server site:remove jane --yes 2>&1' | sed 's/^/    /'
check 'its final backup is kept' "inside 'ls /srv/quire/backups/jane/final-*.tar'"
check 'its folder is archived, not deleted' "inside 'ls -d /srv/quire/sites/.removed/jane-*'"
# (Caddy keeps a certificate it already has in its cache: the handshake may still succeed, but nothing is served.)
check 'it is no longer served (421)' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 421 ]"
check 'its containers are gone' "! inside 'docker ps -a --format \"{{.Names}}\"' | grep -q '^jane-'"
check 'its database is gone' "! inside 'docker exec quire-server-database-1 psql -U postgres -Atc \"SELECT 1 FROM pg_database WHERE datname = '\''site_jane'\''\"' | grep -q 1"

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
