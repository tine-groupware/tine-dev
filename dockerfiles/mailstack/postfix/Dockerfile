FROM alpine:3.10

WORKDIR /root

RUN apk add postfix postfix-mysql

COPY etc/postfix/ /etc/postfix/

CMD ["postfix", "start-fg"]