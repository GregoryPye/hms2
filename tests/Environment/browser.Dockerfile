# syntax=docker/dockerfile:1
ARG NODE_IMAGE
FROM ${NODE_IMAGE}
LABEL hms2.harness.profile="browser"
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
# Official Playwright installer, Chromium headless shell only. The full upstream
# image also contains unused Firefox/WebKit and exceeds this vfs host's budget
# when the real PHP/MySQL services coexist. Keep browser sandboxing at runtime.
RUN --mount=type=secret,id=ca,required=true \
    NODE_EXTRA_CA_CERTS=/run/secrets/ca NPM_CONFIG_USERCONFIG=/dev/null \
      npm install --prefix /opt/browser-build --ignore-scripts --no-audit --no-fund \
        --registry=https://registry.npmjs.org @playwright/test@1.56.0 \
    && node /opt/browser-build/node_modules/playwright/cli.js install-deps chromium \
    && NODE_EXTRA_CA_CERTS=/run/secrets/ca \
      node /opt/browser-build/node_modules/playwright/cli.js install chromium --only-shell \
    && chmod -R a+rX /ms-playwright \
    && rm -rf /opt/browser-build /var/lib/apt/lists/* /tmp/*
USER 1000:1000
ENTRYPOINT ["/usr/bin/env"]
