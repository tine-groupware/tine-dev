#!/bin/sh
cat /etc/dovecot/dovecot-oauth2.conf.ext.envsubst | envsubst > /etc/dovecot/dovecot-oauth2.conf.ext

/usr/sbin/dovecot -F