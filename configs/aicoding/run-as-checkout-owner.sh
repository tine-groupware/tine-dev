#!/bin/sh
# Entrypoint of the AI coding clients (compose/aicoding-*.yml): runs the command as user node with the uid/gid of
# the owner of the bind mounted tine checkout ($CHECKOUT_PATH).
#
# The images run as node (uid 1000). On Linux bind mounts keep the uid of the host: if the checkout belongs to
# another uid (e.g. 1001), the client can't write it. Docker Desktop (macOS) maps the ownership, there nothing
# changes. The container starts as root (compose: user root), adapts node to the owner and drops to node.
# Sessions: docker exec -u node ... (the name is resolved at exec time, so they get the adapted uid).
set -eu

user=node
path="${CHECKOUT_PATH:?CHECKOUT_PATH not set}"

if [ "$(id -u)" != 0 ]; then
    exec "$@"
fi

uid=$(stat -c %u "$path")
gid=$(stat -c %g "$path")
old_uid=$(id -u "$user")
old_gid=$(id -g "$user")

# root-owned checkout (e.g. a Docker Desktop setting): keep node as is, never run the client as root
if [ "$uid" != 0 ] && { [ "$uid" != "$old_uid" ] || [ "$gid" != "$old_gid" ]; }; then
    # not usermod / groupmod: they chown the whole home dir, incl. the bind mounts in it (claude-data, read-only
    # configs). The ids may exist already (e.g. gid 100 users), duplicates are fine for this purpose.
    sed -i "s/^$user:\([^:]*\):$old_uid:$old_gid:/$user:\1:$uid:$gid:/" /etc/passwd
    sed -i "s/^$user:\([^:]*\):$old_gid:/$user:\1:$gid:/" /etc/group
    # files of the image in the home dir (e.g. ~/.local of opencode). -xdev: not into the bind mounts, their
    # mount points don't belong to the old ids (they belong to the host user)
    find "/home/$user" -xdev \( -user "$old_uid" -o -group "$old_gid" \) -exec chown -h "$uid:$gid" {} + || true
fi

export HOME="/home/$user" USER="$user" LOGNAME="$user"
if command -v setpriv >/dev/null 2>&1; then
    exec setpriv --reuid="$(id -u "$user")" --regid="$(id -g "$user")" --init-groups -- "$@"
fi
exec runuser -u "$user" -- "$@"
