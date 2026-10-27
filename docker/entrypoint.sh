#!/bin/sh
# The agent's start: wait for PostgreSQL, bring the registry's schema up to date, harden PostgreSQL,
# then run the agent (or the command given, e.g. by "quire-server").
set -eu

if [ "${1:-}" = 'bin/console' ] && [ "${2:-}" = 'server:agent' ]; then
	attempts=60
	until php bin/console dbal:run-sql -q 'SELECT 1' >/dev/null 2>&1; do
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
