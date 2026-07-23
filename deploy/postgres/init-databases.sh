#!/bin/sh
# Runs on first initialisation of the postgres volume: create the test database.
# (For an already-initialised volume, create kap_test manually — documented in AUDIT S00.)
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE kap_test OWNER $POSTGRES_USER;
EOSQL
