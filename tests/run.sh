#!/bin/sh
#
# tests/run.sh - runs the whole LLMInt test suite.
#
#   ./tests/run.sh          # PHP and JavaScript tests
#   ./tests/run.sh php      # only tests/php/test_*.php
#   ./tests/run.sh js       # only tests/js/*.test.js
#
# PHP is executed with a local `php` when one is on PATH and with the official
# php:8.2-cli-alpine image otherwise; the JavaScript tests use a local `node`
# when available and node:22-alpine otherwise. Both fallbacks only need Docker,
# so the suite runs on a machine without PHP or Node installed.
#
# Requires a POSIX shell. On Windows run the two docker commands from
# tests/README.md instead, or use Git Bash.

set -u

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd) || exit 1
cd "$ROOT" || exit 1

SELECT=${1:-all}
FAILED=0

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

if [ "$FAILED" -ne 0 ]; then
    printf '\nTests fehlgeschlagen.\n'
    exit 1
fi

printf '\nAlle Tests bestanden.\n'
