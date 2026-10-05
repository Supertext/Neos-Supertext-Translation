# Supertext Neos demo: Neos 9.1 + Neos.Demo (EN/DE, plus empty FR and IT) + this package.
# Build context is the repository root:
#   docker build -f demo/Dockerfile -t supertext-neos-demo .
# Needs a MariaDB/MySQL database (DB_* variables) and a volume at /data for uploads.
# See docs/DEVELOPER.md -> Demo.

FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev unzip git \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" intl zip gd opcache pdo_mysql exif \
 && a2enmod rewrite headers expires deflate \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    FLOW_CONTEXT=Production \
    FLOW_REWRITEURLS=1

WORKDIR /var/www/neos

# Dependencies first (cached), then this package and the demo package.
COPY demo/composer.json demo/composer.lock ./
COPY demo/DistributionPackages DistributionPackages/
COPY . DistributionPackages/Supertext.NeosTranslation/
RUN rm -rf DistributionPackages/Supertext.NeosTranslation/demo \
 && composer install --no-dev --no-interaction --no-progress --optimize-autoloader

COPY demo/Settings.yaml Configuration/Settings.yaml
COPY demo/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/php.ini /usr/local/etc/php/conf.d/neos.ini
COPY demo/entrypoint.sh /usr/local/bin/neos-entrypoint
RUN chmod +x /usr/local/bin/neos-entrypoint

# No VOLUME instruction: Railway rejects it and mounts its own volume at /data.
# Locally: docker run -v neosdemo:/data ...
ENTRYPOINT ["neos-entrypoint"]
CMD ["apache2-foreground"]
