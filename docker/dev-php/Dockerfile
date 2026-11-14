# Development PHP image for SightPress: PHP 8.5 CLI with PCOV for code coverage, intl, SQLite and Composer.
#
# Build:  docker build -t sightpress/dev-php:8.5 docker/dev-php
# Use:    docker run --rm -it --user "$(id -u):$(id -g)" -e HOME=/tmp \
#           -v "$PWD/..:/workspace" -w /workspace/core sightpress/dev-php:8.5 composer check
# (Mount the parent folder so the sibling repos used as Composer path repositories are visible.)
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libsqlite3-dev libicu-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" intl \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'memory_limit=512M'; \
        echo 'date.timezone=UTC'; \
        echo 'pcov.enabled=1'; \
    } > /usr/local/etc/php/conf.d/sightpress-dev.ini
