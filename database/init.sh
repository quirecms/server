#!/bin/sh
# Runs once, when the server's PostgreSQL is created: the registry's database, owned by its own role
# (not the administrative one, which only the agent uses to manage sites' databases).
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
	--set=password="$QUIRE_SERVER_DATABASE_PASSWORD" <<-'SQL'
	CREATE ROLE quire_server LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'password';
	CREATE DATABASE quire_server OWNER quire_server TEMPLATE template0 ENCODING 'UTF8';
	REVOKE ALL ON DATABASE quire_server FROM PUBLIC;
	GRANT CONNECT, TEMPORARY ON DATABASE quire_server TO quire_server;
SQL
