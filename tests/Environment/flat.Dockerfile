# syntax=docker/dockerfile:1
ARG SOURCE_IMAGE
FROM ${SOURCE_IMAGE} AS prepared
FROM scratch
# vfs materializes every parent layer as a full tree. Copy the unchanged prepared
# filesystem once so integrated PHP/MySQL/Chromium workers fit this host's disk.
COPY --from=prepared / /
ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
USER 1000:1000
ENTRYPOINT ["/usr/bin/env"]
