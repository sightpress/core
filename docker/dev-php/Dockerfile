# Development PHP image for SightPress: PHP 8.5 CLI with PCOV for code coverage, intl, gd (JPEG, PNG, WebP,
# FreeType), exif, SQLite, Redis (session store tests) and Composer.
#
# Build:  docker build -t sightpress/dev-php:8.5 docker/dev-php
# Use:    docker run --rm -it --user "$(id -u):$(id -g)" -e HOME=/tmp \
#           -v "$PWD/..:/workspace" -w /workspace/core sightpress/dev-php:8.5 composer check
# (Mount the parent folder so the sibling repos used as Composer path repositories are visible.)
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libsqlite3-dev libicu-dev \
        libfreetype-dev libjpeg62-turbo-dev libpng-dev libwebp-dev $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" intl gd exif \
    && pecl install pcov redis \
    && docker-php-ext-enable pcov redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'memory_limit=512M'; \
        echo 'date.timezone=UTC'; \
        echo 'pcov.enabled=1'; \
    } > /usr/local/etc/php/conf.d/sightpress-dev.ini
