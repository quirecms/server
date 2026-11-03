#syntax=docker/dockerfile:1

# The agent: PHP for its code, the Docker CLI with Compose and Buildx (it acts through the Docker gate,
# never on the socket itself), Composer (to get the skeleton of new sites and work out updates, without
# running any package's code), Git with SSH (Git mode sites' repositories), cosign with curl and tar
# (the updater checks and unpacks a new release of the server), and restic (backups off the server).
#
# Images are pinned by digest (a tag can be moved, a digest can't); Dependabot proposes new ones.
FROM docker:29.9.2-cli@sha256:5e6baf67325a79f64ed3bed6863954d5e05d0f3d4e57cbdb14809346814bbfdb AS docker_cli
FROM composer:2.11.0@sha256:278dfc7bd35169965d03f87194816f43bf4e6b6ea61707c2114e52ad12fe24ce AS composer
FROM ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:0f06154836d2de34f887a5c273506d52ca43edaa1d9e53e6510fa78a48f69c2d AS cosign
FROM restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510 AS restic
# The Docker gate, for the tests only (they check the scopes the agent writes with the gate itself);
# the server runs its image (compose.yaml), the same digest (a test keeps them equal).
FROM ghcr.io/yorvolabs/yorvogate:0.2.2@sha256:a70cdc48b759bc7c554879202d41ce4ba4520fd5e44788cec5ce020f887b660b AS yorvogate
FROM mlocati/php-extension-installer:2.12@sha256:e772f8d2c1b88ab5872611b400410b4ab1839a6a0ff5ce460eb2105a35d62953 AS php_extension_installer

FROM php:8.5-cli-alpine@sha256:e899a75c775804e5466dbd9c96b31f8b6c6ae2ddb053a8466223a0c32992ff8e AS agent

SHELL ["/bin/ash", "-euo", "pipefail", "-c"]

COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/
# The base image's packages with their fixes (it's rebuilt less often than Alpine fixes them)
RUN apk upgrade --no-cache \
    && install-php-extensions intl pcntl pdo_pgsql opcache \
    && apk add --no-cache ca-certificates curl git openssh-client openssh-keygen tar tini \
    && rm /usr/local/bin/install-php-extensions

COPY --from=docker_cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=docker_cli /usr/local/libexec/docker/cli-plugins/docker-buildx /usr/local/libexec/docker/cli-plugins/docker-buildx
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=cosign /ko-app/cosign /usr/local/bin/cosign
COPY --from=restic /usr/bin/restic /usr/local/bin/restic

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
# The host's setup for builds (the host-setup service runs it from this image)
COPY --link gate gate/
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
COPY --from=yorvogate /usr/local/bin/yorvogate /usr/local/bin/yorvogate
WORKDIR /app
ENTRYPOINT []
CMD ["composer", "qa"]

# The agent's image: the default target (the last stage).
FROM agent AS server
