# syntax=docker/dockerfile:1

# A final release's image when it has release candidates: its highest candidate's, the one that was
# tested, with only its version changed (the agent reads QUIRE_SERVER_VERSION when it runs, and an
# update checks the agent says it's the release it installed). No RUN, so nothing is built again: the
# layers are the candidate's, and .github/release-promote-check.sh refuses the image otherwise.
#
# TESTED is the candidate's image by digest, as .github/release-image.sh checked it. No default: an
# empty one names no image, and the build fails. (hadolint can't see that it's a digest: DL3006.)
ARG TESTED
# hadolint ignore=DL3006
FROM ${TESTED}

ARG QUIRE_SERVER_VERSION
ENV QUIRE_SERVER_VERSION=${QUIRE_SERVER_VERSION}
LABEL org.opencontainers.image.version="${QUIRE_SERVER_VERSION}"
