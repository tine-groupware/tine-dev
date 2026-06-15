ARG APP_VERSION

FROM ghcr.io/pilinux/opencode:${APP_VERSION}

USER root

RUN install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt update \
    && apt install -y docker-ce-cli docker-buildx-plugin docker-compose-plugin php-cli

ENV DOCKER_HOST="tcp://socket-proxy:2375"

# we are running as user 1000 -> node later
# create necessary directories and set permissions
RUN mkdir -p /home/node/.local/share/opencode/ && \
  mkdir -p /home/node/.local/state/opencode && \
  mkdir -p /home/node/.config/opencode/ && \
  chown -R node:node /home/node

USER 1001

ENV DOCKER_HOST="tcp://socket-proxy:2375"
