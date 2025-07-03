FROM alpine:3.10

WORKDIR /root

RUN apk add dovecot dovecot-mysql dovecot-pop3d dovecot-lmtpd dovecot-pigeonhole-plugin

RUN adduser -H -D vmail

RUN mkdir /var/vmail && chown vmail:vmail /var/vmail

COPY etc/dovecot/ /etc/dovecot/

CMD ["/usr/sbin/dovecot", "-F"]