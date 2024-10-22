FROM tinegroupware/dev:2023.11-8.2-arm64

RUN cd /tmp \
 && apk add go \
 && git clone https://github.com/kelseyhightower/confd.git \
 && cd confd \
 && make \
 && install -c /tmp/confd/bin/confd /usr/sbin/confd
