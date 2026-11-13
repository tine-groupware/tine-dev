# e2e test runner (jest + puppeteer) for tine20/tests/e2etests, see compose/e2etest.yml
#
# Uses Debian's chromium instead of puppeteer's Chrome for Testing download: the latter has no
# linux/arm64 build, so it would not run on Apple Silicon.
ARG NODE_VERSION=24-trixie-slim
FROM node:${NODE_VERSION}

RUN apt-get update && apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
    fonts-noto-color-emoji \
    && rm -rf /var/lib/apt/lists/*

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    PUPPETEER_SKIP_DOWNLOAD=true

USER node
WORKDIR /tine20/tests/e2etests

# keep running for exec sessions (docker exec e2etests npm test ...)
CMD ["sleep", "infinity"]
