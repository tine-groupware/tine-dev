ARG APP_VERSION

FROM node:${APP_VERSION}-alpine

RUN apk add bash git python3 make gcc g++
RUN mkdir -p /.cache/node-gyp/ && chmod 777 /.cache/node-gyp/