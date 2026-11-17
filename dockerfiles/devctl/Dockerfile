# devctl: MCP server for AI coding clients, see devctl.php and compose/aicoding.yml
FROM docker:cli AS docker

# ./console requires php 7.4 - 8.1 (composer.json)
FROM php:8.1-cli-alpine
RUN apk add --no-cache git openssl rsync coreutils findutils
COPY --from=docker /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-buildx \
    /usr/local/libexec/docker/cli-plugins/
COPY devctl.php /opt/devctl/devctl.php

# requests are handled in parallel (e.g. ps while change_branch runs)
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 3003
# $HOME is the host home dir (HOMEDIR of ./console, history files of web), it has to exist for realpath ~
CMD ["sh", "-c", "mkdir -p \"$HOME\" && exec php -d max_execution_time=0 -d output_buffering=0 -S 0.0.0.0:3003 /opt/devctl/devctl.php"]
