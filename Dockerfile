FROM php:8.2-apache

# ── System dependencies ────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        # LDAP client libraries (needed for php-ldap and Apache mod_authnz_ldap)
        libldap2-dev \
        libsasl2-dev \
        # PDF → text extraction
        poppler-utils \
        # cURL (HTTP requests to LLM / SD endpoints)
        libcurl4-openssl-dev \
        # libxml2 (PHP xml extension)
        libxml2-dev \
        # ICU (PHP intl extension)
        libicu-dev \
        # Multibyte string (PHP mbstring extension)
        libonig-dev \
        # ZIP (PHP zip extension)
        libzip-dev \
        # Kerberos / GSSAPI support for Windows SSO via REMOTE_USER
        libapache2-mod-auth-gssapi \
        krb5-user \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ─────────────────────────────────────────────────────────────
RUN docker-php-ext-configure ldap \
        --with-libdir="lib/$(dpkg-architecture -qDEB_HOST_MULTIARCH)/" \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        ldap \
        curl \
        mbstring \
        fileinfo \
        intl \
        xml \
        zip

# ── Apache modules ─────────────────────────────────────────────────────────────
# rewrite  – pretty URLs / .htaccess rewrites
# headers  – manipulate response headers
# auth_gssapi   – Kerberos / Windows SSO (sets REMOTE_USER)
# authnz_ldap   – optional LDAP-based HTTP authentication
# ldap          – shared LDAP connection pool used by authnz_ldap
RUN a2enmod rewrite headers auth_gssapi authnz_ldap ldap

# ── Custom configuration ───────────────────────────────────────────────────────
COPY docker/php.ini    /usr/local/etc/php/conf.d/llmint.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# ── Application code ───────────────────────────────────────────────────────────
COPY --chown=www-data:www-data . /var/www/html/

# Ensure upload/output directories exist and are writable by the web server
RUN mkdir -p /var/www/html/doc_uploads /var/www/html/sd_output \
    && chown -R www-data:www-data \
        /var/www/html/doc_uploads \
        /var/www/html/sd_output \
    && chmod 755 \
        /var/www/html/doc_uploads \
        /var/www/html/sd_output

# ── Entrypoint ─────────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
