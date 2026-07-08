ARG APP_VERSION

# Stage 1: Build the Go binary using the official Wollomatic repository
FROM golang:1.26-alpine AS builder
RUN apk add --no-cache git
WORKDIR /app
# Pull down the latest stable release code
RUN git clone https://github.com/wollomatic/socket-proxy.git .
RUN git checkout ${APP_VERSION}
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-w -s" -o socket-proxy ./cmd/socket-proxy

# Stage 2: Final deployment runtime built on Alpine Linux (includes a shell)
FROM alpine:3.20
RUN apk add --no-cache curl bash jq docker-cli
WORKDIR /app
COPY --from=builder /app/socket-proxy /app/socket-proxy

# Re-create a low-privileged system user for security matching wollomatic defaults
RUN addgroup -S appgroup && adduser -S appuser -G appgroup
USER appuser

EXPOSE 2375
ENTRYPOINT ["/app/socket-proxy"]
