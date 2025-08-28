ARG APP_VERSION

FROM osixia/openldap:${APP_VERSION}

COPY ./init.ldif /container/service/slapd/assets/config/bootstrap/ldif/bootstrap.ldif
COPY ./mozillaabalpha.schema /container/service/slapd/assets/config/bootstrap/schema/mozillaabalpha.schema
