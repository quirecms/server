#!/bin/sh
# The agent's start: its logins to PostgreSQL, wait for PostgreSQL, bring the registry's schema up to date, harden PostgreSQL,
# then run the agent (or the command given, e.g. by "quire-server").
set -eu
# What the agent writes is readable by others at most, never writable (ISO-15: a umask of 000
# left every file of a site's folder 0666)
umask 022

if [ "${1:-}" = 'bin/console' ] && [ "${2:-}" = 'server:agent' ]; then
	attempts=60
	# The server's logins first, from its network as Docker has it now (it may have been made again):
	# PostgreSQL refuses them from anywhere else.
	until php bin/console server:database-logins -q && php bin/console dbal:run-sql -q 'SELECT 1' >/dev/null 2>&1; do
		attempts=$((attempts - 1))
		if [ "$attempts" -le 0 ]; then
			echo 'PostgreSQL isn'\''t reachable.' >&2
			exit 1
		fi
		sleep 1
	done

	php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
	php bin/console server:setup
fi

exec "$@"
