ARG APP_VERSION

FROM node:${APP_VERSION}-trixie

RUN apt update && apt install -y iproute2 net-tools

RUN corepack enable