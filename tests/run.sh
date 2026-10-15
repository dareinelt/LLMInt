#!/bin/sh
#
# tests/run.sh - runs the whole LLMInt test suite.
#
#   ./tests/run.sh          # PHP and JavaScript tests
#   ./tests/run.sh php      # only tests/php/test_*.php
#   ./tests/run.sh js       # only tests/js/*.test.js
#   ./tests/run.sh db       # only tests/db/test_*.php, against a real MySQL
#
# PHP is executed with a local `php` when one is on PATH and with the official
# php:8.2-cli-alpine image otherwise; the JavaScript tests use a local `node`
# when available and node:22-alpine otherwise. Both fallbacks only need Docker,
# so the suite runs on a machine without PHP or Node installed.
#
# `db` is opt-in and NOT part of `all`: those tests need a running MySQL server,
# while `all` must work with nothing but Docker. `db` uses a MySQL that is
# already described by DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS (for example a CI
# service container) or starts a throwaway mysql:8.0 container and removes it
# afterwards. When the tests themselves run in a container (no local php), the
# DB_* values are passed on to it, so DB_HOST has to be reachable from inside
# that container (host.docker.internal on Docker Desktop, not 127.0.0.1). Since php:8.2-cli-alpine ships without pdo_mysql, `db` uses the
# image from tests/db/php-with-mysql.Dockerfile (built once, then cached).
# The db tests are integration tests: they empty the `endpoints` table, so they
# must only run against a dedicated test database, never against a live install.
#
# Requires a POSIX shell. On Windows run the docker commands from
# tests/README.md instead, or use Git Bash.

set -u

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd) || exit 1
cd "$ROOT" || exit 1

SELECT=${1:-all}
FAILED=0

case "$SELECT" in
    all | php | js | db) ;;
    *)
        printf 'Unbekannter Selektor "%s". Erlaubt sind: all, php, js, db.\n' "$SELECT"
        exit 1
        ;;
esac

have() {
    command -v "$1" >/dev/null 2>&1
}

run_php() {
    if have php; then
        php "$@"
    else
        docker run --rm -v "$ROOT:/app" -w /app php:8.2-cli-alpine php "$@"
    fi
}

run_node() {
    if have node; then
        node "$@"
    else
        docker run --rm -v "$ROOT:/app" -w /app node:22-alpine node "$@"
    fi
}

# ── Datenbankgestützte Tests (./tests/run.sh db) ───────────────────────────────

DB_TEST_IMAGE=${LLMINT_TEST_DB_IMAGE:-mysql:8.0}
PHP_TEST_IMAGE=${LLMINT_TEST_PHP_IMAGE:-llmint-test-php:8.2}
DB_TEST_NAME=llmint_test
DB_TEST_PASS=llmint_test
DB_CONTAINER=
DB_NETWORK=
DB_EXTERNAL=0

db_cleanup() {
    [ -n "$DB_CONTAINER" ] && docker rm -f "$DB_CONTAINER" >/dev/null 2>&1
    [ -n "$DB_NETWORK" ] && docker network rm "$DB_NETWORK" >/dev/null 2>&1
    return 0
}

# Baut das Testimage mit pdo_mysql einmalig; danach kommt es aus dem Cache.
ensure_php_test_image() {
    docker image inspect "$PHP_TEST_IMAGE" >/dev/null 2>&1 && return 0
    printf 'Baue %s (einmalig) ...\n' "$PHP_TEST_IMAGE"
    docker build -q -f tests/db/php-with-mysql.Dockerfile -t "$PHP_TEST_IMAGE" tests/db >/dev/null
}

start_db_container() {
    DB_NETWORK="llmint-test-net-$$"
    DB_CONTAINER="llmint-test-db-$$"

    docker network create "$DB_NETWORK" >/dev/null || return 1
    docker run -d --name "$DB_CONTAINER" --network "$DB_NETWORK" \
        -e MYSQL_ROOT_PASSWORD="$DB_TEST_PASS" -e MYSQL_DATABASE="$DB_TEST_NAME" \
        "$DB_TEST_IMAGE" >/dev/null || return 1

    i=0
    while [ "$i" -lt 60 ]; do
        if docker exec "$DB_CONTAINER" mysqladmin ping -h 127.0.0.1 -uroot -p"$DB_TEST_PASS" >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    printf 'MySQL-Container wurde nicht rechtzeitig bereit.\n'
    return 1
}

# Führt einen DB-Test im Wegwerf-Container aus.
run_php_db_container() {
    docker run --rm --network "$DB_NETWORK" \
        -e DB_HOST="$DB_CONTAINER" -e DB_PORT=3306 \
        -e DB_NAME="$DB_TEST_NAME" -e DB_USER=root -e DB_PASS="$DB_TEST_PASS" \
        -v "$ROOT:/app" -w /app "$PHP_TEST_IMAGE" php "$@"
}

# Führt einen DB-Test gegen die von außen konfigurierte Datenbank aus.
run_php_db_external() {
    if have php; then
        php "$@"
    else
        # Die Verbindungsdaten müssen in den Container durchgereicht werden,
        # sonst greift dort wieder die Vorgabe aus tests/db/bootstrap.php.
        # DB_HOST muss aus dem Container heraus erreichbar sein (Docker Desktop:
        # host.docker.internal; Linux: --add-host bzw. ein gemeinsames Netz).
        docker run --rm \
            -e DB_HOST="${DB_HOST}" -e DB_PORT="${DB_PORT:-3306}" \
            -e DB_NAME="${DB_NAME:-llmint}" -e DB_USER="${DB_USER:-root}" \
            -e DB_PASS="${DB_PASS:-llmint}" \
            -v "$ROOT:/app" -w /app "$PHP_TEST_IMAGE" php "$@"
    fi
}

run_db_suite() {
    banner "$1"
    if [ "$DB_EXTERNAL" -eq 1 ]; then
        run_php_db_external "$1" || FAILED=1
    else
        run_php_db_container "$1" || FAILED=1
    fi
}

banner() {
    printf '\n== %s ==\n' "$1"
}

if [ "$SELECT" = "all" ] || [ "$SELECT" = "php" ]; then
    for file in tests/php/test_*.php; do
        [ -f "$file" ] || continue
        banner "$file"
        run_php "$file" || FAILED=1
    done
fi

if [ "$SELECT" = "all" ] || [ "$SELECT" = "js" ]; then
    for file in tests/js/*.test.js; do
        [ -f "$file" ] || continue
        banner "$file"
        run_node --test "$file" || FAILED=1
    done
fi

if [ "$SELECT" = "db" ]; then
    if ! have docker && [ -z "${DB_HOST:-}" ]; then
        printf 'Fuer die DB-Tests wird Docker oder eine konfigurierte Datenbank (DB_HOST) benoetigt.\n'
        exit 1
    fi

    trap 'db_cleanup' EXIT INT TERM

    if [ -n "${DB_HOST:-}" ]; then
        # Die Datenbank kommt von außen (z. B. CI-Servicecontainer); geprüft wird
        # nur, dass die Verbindungsdaten gesetzt sind.
        DB_EXTERNAL=1
        printf 'Nutze die konfigurierte Datenbank %s:%s/%s.\n' \
            "${DB_HOST}" "${DB_PORT:-3306}" "${DB_NAME:-llmint}"
    else
        ensure_php_test_image || exit 1
        start_db_container || exit 1
        printf 'Wegwerf-MySQL %s ist bereit.\n' "$DB_CONTAINER"
    fi

    for file in tests/db/test_*.php; do
        [ -f "$file" ] || continue
        run_db_suite "$file"
    done
fi

if [ "$FAILED" -ne 0 ]; then
    printf '\nTests fehlgeschlagen.\n'
    exit 1
fi

printf '\nAlle Tests bestanden.\n'
