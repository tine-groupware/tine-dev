# e2e test image (jest + puppeteer) for the e2e jobs of the tine CI (tine20 ci/gitlab-ci/test_js_jobs.yml) and the
# e2etests container of tine-dev (compose/e2etest.yml). Moved here from tine20 ci/helperimages/puppeteer: built for
# amd64 and arm64 by the CI of this repo, no manual builds.
#
# Debian's chromium on both architectures instead of puppeteer's Chrome for Testing download: the latter has no
# linux/arm64 build. Same browser everywhere, CI and local (e.g. Apple Silicon).
#
# Debian's firefox-esr for the tests in Firefox (TEST_BROWSER=firefox in tests/e2etests, puppeteer drives it via
# WebDriver BiDi): puppeteer's own Firefox download has no linux/arm64 build either. The tests find it at
# /usr/bin/firefox-esr (PUPPETEER_EXECUTABLE_PATH is chromium).
#
# No npm install of the e2e test dependencies in the image (they are in the tine repo): the CI job and tine-dev run
# npm install in tests/e2etests.

# node version, see .meta/app_version
ARG APP_VERSION
FROM node:${APP_VERSION}-trixie-slim

RUN apt-get update &&\
    apt-get install -y --no-install-recommends chromium firefox-esr fonts-liberation fonts-noto-color-emoji \
                       ca-certificates wget jq curl php-cli php-mbstring git unzip s3cmd && \
    rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    PUPPETEER_SKIP_DOWNLOAD=true

RUN curl -sS https://getcomposer.org/installer -o composer-setup.php \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php

WORKDIR /e2etests
