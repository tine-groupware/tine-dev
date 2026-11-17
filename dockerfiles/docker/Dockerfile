ARG APP_VERSION

FROM docker:${APP_VERSION}

# --upgrade: packages of the base image (e.g. openssh-client-common) have to match the newer versions from the
# repo, otherwise apk can't select them ("breaks: openssh-client-default-...")
RUN apk add --no-cache --upgrade curl jq bash util-linux tar git composer coreutils file openssh
