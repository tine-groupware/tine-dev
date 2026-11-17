FROM node:22-bookworm-slim

# Claude Code version, see .meta/app_version
ARG APP_VERSION

USER root

# Install base tools required for Claude Code and apt repository setup
RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    git \
    gnupg \
    ripgrep \
    && rm -rf /var/lib/apt/lists/*

# PHP CLI (php -l etc.). No docker cli: clients have no docker access, they use the devctl MCP server
RUN apt-get update \
    && apt-get install -y php-cli \
    && rm -rf /var/lib/apt/lists/*

# Install Claude Code CLI globally
RUN npm install -g @anthropic-ai/claude-code@${APP_VERSION}

# Create necessary directories and set ownership for non-root user 'node' (UID 1000)
RUN mkdir -p /home/node/.claude /workspace && \
    chown -R node:node /home/node /workspace

# Switch to non-root user 'node' (UID 1000)
USER node
WORKDIR /workspace

# Keep container running for interactive exec sessions
CMD ["sleep", "infinity"]
