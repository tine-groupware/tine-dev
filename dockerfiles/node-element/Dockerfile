ARG APP_VERSION

FROM node:${APP_VERSION}-bullseye

RUN apt update && apt install -y iproute2 net-tools

RUN corepack enable