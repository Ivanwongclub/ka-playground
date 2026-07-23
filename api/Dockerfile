# KA Playground API — local/dev image. Code is volume-mounted by docker compose;
# the staging/production image build lands with the S00 STEP 6 deploy runbook (2.26).
FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl redis opcache gd exif

WORKDIR /var/www/api
