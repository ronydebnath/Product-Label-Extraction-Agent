#!/bin/sh
# Runs once, when the postgres data volume is first initialised. Gives the test suite its own
# database on the same server, so tests hit real Postgres without touching dev data.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE "${POSTGRES_DB}_test" OWNER "$POSTGRES_USER";
EOSQL
