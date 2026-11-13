ARG NODE_VERSION=22-bookworm-slim
FROM node:${NODE_VERSION}

USER root

# Install base tools required for Claude Code and apt repository setup
RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    git \
    gnupg \
    ripgrep \
    && rm -rf /var/lib/apt/lists/*

# Add official Docker apt repository and install Docker CLI + PHP CLI
RUN DOCKER_REPO="download.docker.com/linux/debian" \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL "https://${DOCKER_REPO}/gpg" | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://${DOCKER_REPO} \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update \
    && apt-get install -y \
      docker-ce-cli \
      docker-buildx-plugin \
      docker-compose-plugin \
      php-cli \
    && rm -rf /var/lib/apt/lists/*

# Install Claude Code CLI globally
RUN npm install -g @anthropic-ai/claude-code

# Create necessary directories and set ownership for non-root user 'node' (UID 1000)
RUN mkdir -p /home/node/.claude /workspace && \
    chown -R node:node /home/node /workspace

# Point Docker CLI to the socket-proxy container
ENV DOCKER_HOST="tcp://socket-proxy:2375"

# Switch to non-root user 'node' (UID 1000)
USER node
WORKDIR /workspace

# Keep container running for interactive exec sessions
CMD ["sleep", "infinity"]
