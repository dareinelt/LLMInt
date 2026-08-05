#!/bin/sh
# docker/phpmyadmin-entrypoint.sh
#
# Generates an .htpasswd file from PMA_BASIC_AUTH_USER / PMA_BASIC_AUTH_PASSWORD
# so phpMyAdmin is HTTP Basic Auth protected by default (see
# docker/phpmyadmin-basicauth.conf), then hands off to the phpMyAdmin image's
# own /docker-entrypoint.sh (NOT the generic docker-php-entrypoint), since
# that script is what generates /etc/phpmyadmin/config.secret.inc.php (the
# blowfish secret) and config.user.inc.php before starting Apache. Skipping
# it causes: "Failed to open stream: /etc/phpmyadmin/config.secret.inc.php".

set -e

: "${PMA_BASIC_AUTH_USER:=admin}"
: "${PMA_BASIC_AUTH_PASSWORD:=changeme}"

HTPASSWD_DIR=/etc/phpmyadmin-basicauth
HTPASSWD_FILE="${HTPASSWD_DIR}/.htpasswd"

mkdir -p "$HTPASSWD_DIR"

# Generate an Apache-compatible {SHA} htpasswd entry using PHP (always
# available in this image), avoiding a dependency on apache2-utils/openssl.
php -r '
    [$user, $password, $file] = array_slice($argv, 1);
    $hash = "{SHA}" . base64_encode(sha1($password, true));
    file_put_contents($file, $user . ":" . $hash . PHP_EOL);
' "$PMA_BASIC_AUTH_USER" "$PMA_BASIC_AUTH_PASSWORD" "$HTPASSWD_FILE"

chmod 644 "$HTPASSWD_FILE"

echo "[phpmyadmin-entrypoint] Basic Auth configured for user '${PMA_BASIC_AUTH_USER}'."

exec /docker-entrypoint.sh apache2-foreground
