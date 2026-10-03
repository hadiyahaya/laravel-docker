FROM serversideup/php:8.5-fpm-nginx

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

# Extra PHP extensions (pdo_mysql, redis, zip, pcntl and opcache are already included)
RUN install-php-extensions intl bcmath

# if need to install packages, use the following command
# Install Node.js v22
RUN apt-get update \
    && apt-get install -y curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

# Custom PHP settings (the "zzz-" prefix makes it load after the image's defaults)
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zzz-custom.ini

# Custom nginx settings (included inside the image's server { } block)
COPY docker/nginx/custom.conf /etc/nginx/server-opts.d/custom.conf

# Match www-data UID/GID to the host user so mounted volumes are writable
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

# Drop back to the unprivileged user
USER www-data
