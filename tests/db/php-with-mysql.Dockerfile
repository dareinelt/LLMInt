# tests/db/php-with-mysql.Dockerfile
#
# Test-Image für die datenbankgestützten Suiten in tests/db/.
#
# Das offizielle php:8.2-cli-alpine bringt mysqlnd, aber kein pdo_mysql mit.
# Ohne pdo_mysql kann getDb() keine Verbindung aufbauen, die DB-Suiten könnten
# also nicht laufen. Deshalb genau eine zusätzliche Erweiterung.
#
# Gebaut wird das Image von `./tests/run.sh db` (einmalig, danach aus dem
# Docker-Cache) und bei Bedarf direkt:
#
#   docker build -f tests/db/php-with-mysql.Dockerfile -t llmint-test-php:8.2 tests/db

FROM php:8.2-cli-alpine

RUN apk add --no-cache $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && apk del $PHPIZE_DEPS
