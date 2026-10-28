#syntax=docker/dockerfile:1

# The agent: PHP for its code, the Docker CLI with Compose and Buildx (it acts through the docker-proxy,
# never on the socket itself), Composer (to get the skeleton of new sites and work out updates, without
# running any package's code), Git with SSH (Git mode sites' repositories), and cosign with curl and tar
# (the updater checks and unpacks a new release of the server).
#
# Images are pinned by digest (a tag can be moved, a digest can't); Dependabot proposes new ones.
FROM docker:29.9.2-cli@sha256:5e6baf67325a79f64ed3bed6863954d5e05d0f3d4e57cbdb14809346814bbfdb AS docker_cli
FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer
FROM ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:0f06154836d2de34f887a5c273506d52ca43edaa1d9e53e6510fa78a48f69c2d AS cosign
FROM mlocati/php-extension-installer:2.12@sha256:ac4b252c153a9f6de5578ff2c417790aba836bb49855543144606033eceda253 AS php_extension_installer

FROM php:8.5-cli-alpine@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS agent

SHELL ["/bin/ash", "-euo", "pipefail", "-c"]

COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl pcntl pdo_pgsql opcache \
    && apk add --no-cache ca-certificates curl git openssh-client openssh-keygen tar tini \
    && rm /usr/local/bin/install-php-extensions

COPY --from=docker_cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=cosign /ko-app/cosign /usr/local/bin/cosign

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /opt/quire-server
ENV APP_ENV=prod APP_DEBUG=0 COMPOSER_ALLOW_SUPERUSER=1

# Its release ("dev" when built from a checkout); set by the release workflow.
ARG QUIRE_SERVER_VERSION=dev
ENV QUIRE_SERVER_VERSION=${QUIRE_SERVER_VERSION}
LABEL org.opencontainers.image.title="Quire Server" \
      org.opencontainers.image.description="Quire Server's agent: many Quire sites on one machine, behind one front door." \
      org.opencontainers.image.source="https://github.com/quirecms/server" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="YorvoLabs" \
      org.opencontainers.image.version="${QUIRE_SERVER_VERSION}"

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
