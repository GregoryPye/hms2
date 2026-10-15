# syntax=docker/dockerfile:1
ARG COMPOSER_IMAGE
ARG PHP_IMAGE
FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE}
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN --mount=type=secret,id=ca,required=true \
    apt-get -o Acquire::https::CaInfo=/run/secrets/ca update \
    && apt-get -o Acquire::https::CaInfo=/run/secrets/ca install -y --no-install-recommends git unzip libzip-dev libicu-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j2 bcmath pdo_mysql sockets intl mbstring zip pcntl curl \
    && curl --cacert /run/secrets/ca -fsSL https://pecl.php.net/get/redis-6.2.0.tgz -o /tmp/redis.tgz \
    && pecl install /tmp/redis.tgz && docker-php-ext-enable redis \
    && curl --cacert /run/secrets/ca -fsSL https://pecl.php.net/get/pcov-1.0.12.tgz -o /tmp/pcov.tgz \
    && pecl install /tmp/pcov.tgz && docker-php-ext-enable pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/*
WORKDIR /app
COPY source/composer.json source/composer.lock /app/
RUN --mount=type=secret,id=ca,required=true \
    COMPOSER_HOME=/tmp/composer COMPOSER_CAFILE=/run/secrets/ca \
    composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins --no-autoloader \
    && rm -rf /tmp/*
COPY source/ /app/
RUN composer dump-autoload --no-scripts --no-plugins
USER 1000:1000
ENTRYPOINT ["/usr/bin/env"]
