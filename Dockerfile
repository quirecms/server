#syntax=docker/dockerfile:1

# The agent: PHP for its code, the Docker CLI with Compose and Buildx (it acts through the docker-proxy,
# never on the socket itself), and Composer (to get the skeleton of new sites, without running it).
FROM docker:29.9.2-cli AS docker_cli
FROM composer:2.10.3 AS composer

FROM php:8.5-cli-alpine AS agent

SHELL ["/bin/ash", "-euo", "pipefail", "-c"]

COPY --from=mlocati/php-extension-installer:2.8 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl pcntl pdo_pgsql opcache \
    && apk add --no-cache ca-certificates git tini \
    && rm /usr/local/bin/install-php-extensions

COPY --from=docker_cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /opt/quire-server
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1

COPY --link composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --no-interaction \
    && composer clear-cache

COPY --link bin bin/
COPY --link config config/
COPY --link migrations migrations/
COPY --link src src/
COPY --link .env ./
COPY --link --chmod=755 docker/entrypoint.sh /usr/local/bin/quire-server-entrypoint

RUN composer dump-autoload --no-dev --classmap-authoritative \
    && bin/console cache:warmup \
    # No setuid or setgid programs
    && (find / -xdev -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true)

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD php -r 'exit(time() - (int) @filemtime("/tmp/quire-server-agent.heartbeat") < 180 ? 0 : 1);'
ENTRYPOINT ["/sbin/tini", "--", "quire-server-entrypoint"]
CMD ["bin/console", "server:agent"]

# For working on the server itself (compose.dev.yaml): the code mounted from the checkout, its dev
# dependencies installed there; the agent's own entry point doesn't run.
FROM agent AS dev
ENV APP_ENV=test APP_DEBUG=1
WORKDIR /app
ENTRYPOINT []
CMD ["composer", "qa"]

# The agent's image: the default target (the last stage).
FROM agent AS server
