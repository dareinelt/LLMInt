#!/bin/bash
# docker/entrypoint.sh
#
# 1. Waits until the MySQL database accepts connections.
# 2. Runs setup.php once to create tables and seed the default admin user.
# 3. Hands off to the standard Apache foreground process.

set -e

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-llmint}"
DB_USER="${DB_USER:-llmint}"
DB_PASS="${DB_PASS:-llmint}"

echo "[entrypoint] Waiting for database at ${DB_HOST}:${DB_PORT}…"
until php -r "
    \$pdo = new PDO(
        'mysql:host=${DB_HOST};port=${DB_PORT};dbname=${DB_NAME};charset=utf8mb4',
        '${DB_USER}',
        '${DB_PASS}'
    );
" 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] Database is ready."

echo "[entrypoint] Running setup.php…"
php /var/www/html/setup.php
echo "[entrypoint] Setup complete."

echo "[entrypoint] Starting Apache…"
exec apache2-foreground
