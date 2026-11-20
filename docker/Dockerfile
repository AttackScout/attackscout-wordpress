# Test image: official PHP CLI image + PCOV (coverage) + zip + Composer.
# PHP 7.4 is the lowest version the plugin supports (Requires PHP), 8.4 the newest:
#   docker build -t wp-plugin-test docker
#   docker build -t wp-plugin-test --build-arg PHP_IMAGE=php:8.4.27-cli-bookworm docker
ARG PHP_IMAGE=php:7.4.33-cli-bullseye
FROM composer:2.10.3 AS composer
FROM ${PHP_IMAGE}
# Debian 11 (bullseye, image of PHP 7.4) has left deb.debian.org since the end
# of its LTS (08/2026): its packages are read from archive.debian.org.
RUN if grep -q bullseye /etc/os-release; then sed -i 's|http://deb.debian.org|http://archive.debian.org|g' /etc/apt/sources.list; fi \
    && apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install zip \
    && pecl install pcov-1.0.12 \
    && docker-php-ext-enable pcov
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /app
