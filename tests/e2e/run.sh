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
cache=''
if [ "${E2E_CACHE:-}" = 1 ]; then cache='-v quire-server-e2e-cache:/var/lib/docker'; fi
# shellcheck disable=SC2086 # $cache is empty or two words
docker run -d --privileged --name "$dind" $cache -e DOCKER_TLS_CERTDIR= docker:29.9.2-dind >/dev/null
until inside 'docker info' >/dev/null 2>&1; do sleep 1; done
# With the cache, what an earlier run left but its images and build cache
# (Its containers restart with the cached Docker: removed, and the folders their mounts left behind.)
# The agent's image too: install.sh builds it only when there's none, so a cached one would be an
# earlier checkout's (its build layers stay cached).
if [ -n "$cache" ]; then inside 'docker rm -f $(docker ps -aq) >/dev/null 2>&1; docker rmi -f quire-server-agent >/dev/null 2>&1; docker volume prune -af >/dev/null; docker network prune -f >/dev/null; rm -rf /srv/quire /root/blog; true'; fi
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

if release; then
	# The latest release, as a user gets it, but checked by this checkout's install.sh; with root's
	# umask as strict as it gets (cosign runs as a user of its own and must still read the files).
	install='umask 077; sh /root/quire-server/install.sh --yes --test-certificates --no-os-updates 2>&1'
	check 'a release that doesn'\''t exist, or a version that isn'\''t one, installs nothing' "! inside 'sh /root/quire-server/install.sh --version=0.0.0 --yes --no-os-updates' && ! inside 'sh /root/quire-server/install.sh \"--version=0.1.0/../..\" --yes --no-os-updates' && ! inside 'test -e /srv/quire/server'"
else
	install='sh /root/quire-server/install.sh --source=/root/quire-server --yes --test-certificates --no-os-updates 2>&1'
	if [ -n "$image" ]; then
		docker save "$image" | docker exec -i "$dind" docker load -q
		inside "docker tag $image quire-server-agent"
	fi
fi

inside "$install" | tee "$logs"/install.log | sed 's/^/    /'
check 'the installer finished' "grep -q 'Quire Server runs' $logs/install.log"
check 'the stack is up and healthy' "inside 'quire-server ps --format \"{{.Service}} {{.Health}}\" | grep -q \"agent healthy\"'"
published=$(inside "docker ps --format '{{.Names}} {{.Ports}}'" | grep -c '0.0.0.0' || true)
check 'only the front door publishes ports' "[ $published = 1 ]"
if release; then
check 'the release'\''s signatures were checked' "grep -q 'Its signature checks out' $logs/install.log && grep -q 'Its image'\''s signature checks out too' $logs/install.log"
check 'it runs the release'\''s signed image, by digest' "inside 'quire-server version' | grep -Eq '^Agent: ghcr.io/quirecms/server@sha256:[0-9a-f]{64}\$' && [ \"\$(inside 'docker inspect quire-server-agent-1 --format {{.Config.Image}}')\" = \"\$(inside 'sed -n s/^QUIRE_SERVER_IMAGE=//p /srv/quire/server/server.env')\" ]"
check 'nothing was built on the machine' "[ -z \"\$(inside 'docker images -q quire-server-agent')\" ]"
else
check 'it says it was built from a checkout (no release image)' "inside 'quire-server version' | grep -q 'built from a checkout'"
fi
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
check 'its image has its own name' "inside 'docker inspect demo-php-1 --format \"{{.Config.Image}}\"' | grep -q '^quire-sites/demo/php-prod'"
check 'the adoption is recorded' "inside 'quire-server operations' | grep -q 'site.adopt.*succeeded'"
check 'its versions are recorded in its folder' "inside 'test -f /srv/quire/sites/demo/composer.lock'"
fi

server_env=$(inside 'sha256sum /srv/quire/server/server.env')
inside "$install" > "$logs"/install-again.log || true
check 'running the installer again changes nothing' "grep -q 'installed in /srv/quire/server already' $logs/install-again.log && grep -q 'Quire Server runs' $logs/install-again.log && [ \"\$(inside 'sha256sum /srv/quire/server/server.env')\" = \"$server_env\" ]"
# As PostgreSQL's superuser, in its container (local connections need the password too: SCRAM only)
postgres_password=$(inside "sed -n 's/^QUIRE_SERVER_POSTGRES_PASSWORD=//p' /srv/quire/server/server.env" | tr -d "'")
pg() { inside "docker exec -e PGPASSWORD=$postgres_password quire-server-database-1 psql -U postgres -Atc \"$1\""; }

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
inside 'quire-server backups:restore jane --from-server=quire-server --as=moved --domain=moved.test --yes 2>&1' | tee "$logs"/moved.log | sed 's/^/    /'
check 'a site moves from another server'\''s backups' "grep -q 'moved\" runs' $logs/moved.log && inside 'curl -sk --resolve moved.test:443:127.0.0.1 https://moved.test/' | grep -q 'Jane before'"

echo '== A whole server from its backups (as a server that replaces it)'
# The first server's sites, as its last backup lists them (only jane): gone from here first.
inside 'quire-server site:remove jane --yes >/dev/null 2>&1'
inside 'quire-server backups:restore-server --from-server=quire-server --yes 2>&1' | tee "$logs"/restore-server.log | sed 's/^/    /'
check 'every site of the old server is restored, with its name and domains' "grep -Eq 'jane +restored' $logs/restore-server.log && inside \"curl -sk $resolve https://jane.test/\" | grep -q 'Jane before'"
check 'with its application'\''s own tables' "[ \"\$(orders jane)\" = 2 ]"
check 'with its update policy' "inside 'quire-server site:updates jane' | grep -q 'fix a known vulnerability'"
check 'a server with no backups is refused' "inside 'quire-server backups:restore-server --from-server=no-such-server --yes 2>&1' | grep -q 'no backup of the server'"
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
check 'its role is still refused the server'\''s databases' "[ \"\$(inside 'docker exec jane-php-1 php /app/var/try-databases.php' | grep -c ': denied')\" = 3 ]"
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
check 'a site whose automatic updates are off gets none' "inside 'quire-server site:update --all --automatic 2>&1' | grep -q 'No site gets automatic updates'"
inside 'quire-server site:updates jane security >/dev/null'
inside 'quire-server site:update --all --automatic 2>&1' | tee "$logs"/automatic.log | sed 's/^/    /'
check 'its known vulnerability is fixed by itself' "grep -Eq 'jane +updated +fixes symfony/yaml' $logs/automatic.log && ! inside 'grep -A1 \"\\\"name\\\": \\\"symfony/yaml\\\"\" /srv/quire/sites/jane/composer.lock' | grep -q '\"v8.1.0\"'"
check 'it is recorded as made by itself' "inside 'quire-server operations' | grep site.update | head -1 | grep -q 'automatic'"
check 'it answers after its automatic update' "[ \"\$(inside \"curl -sk $resolve -o /dev/null -w '%{http_code}' https://jane.test/\")\" = 200 ]"
inside 'quire-server site:update --all --automatic 2>&1' > "$logs"/automatic-again.log
check 'the next run finds nothing to fix' "grep -Eq 'jane +up to date +no known vulnerability' $logs/automatic-again.log"
check 'Composer ran in a container of its own, gone after' "[ -z \"\$(inside 'docker ps -aq --filter label=com.quirecms.server.sandbox')\" ] && inside 'docker network inspect quire-sandbox'"
check 'that container reaches neither the docker-proxy nor the database' "! inside 'docker run --rm --network quire-sandbox alpine:3.22 sh -c \"wget -qT5 -O- http://docker-proxy:2375/_ping || nc -zw5 quire-database 5432\"'"
# A composer.json in the site's folder that has Composer post to the docker-proxy
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
inside 'quire-server site:deploy blog 2>&1' | tee "$logs"/git-squat.log | sed 's/^/    /'
check 'a commit naming another site'\''s image is refused, never built' "grep -q 'quire-sites/jane/php-prod' $logs/git-squat.log && grep -q 'as it was before' $logs/git-squat.log && ! inside 'docker image inspect quire-sites/jane/php-prod'"
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
