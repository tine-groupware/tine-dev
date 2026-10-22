#!/usr/bin/env bash
# 100% fresh local dev environment:
#   ./scripts/fresh-dev.sh                      (full wipe: images + state + dep caches)
#   KEEP_IMAGES=1 ./scripts/fresh-dev.sh        (state wipe only, keeps images, faster)
#   KEEP_PULLUP=1 ./scripts/fresh-dev.sh        (leaves pullup.json untouched)
#   TINE_INSTALL_LOGS=1 ./scripts/fresh-dev.sh  (outputs tine:install logs)

set -euo pipefail
cd "$(dirname "$0")/.."

if [ -t 1 ]; then
  BLUE=$'\033[1;94m'
  RESET=$'\033[0m'
else
  BLUE=''
  RESET=''
fi

step() {
  printf '\n%s==> %s%s\n\n' "$BLUE" "$*" "$RESET"
}

step "[1/8] Stopping and removing containers + networks (DB data lives in the db container -> wiped)"
./console docker:down

step "[2/8] Removing filesdir volume (user files + stored license)"
docker volume rm tine20_tinefiles 2>/dev/null || true

if [ "${KEEP_IMAGES:-0}" != "1" ]; then
  step "[3/8] Removing ALL unused docker images/networks (affects every project on this machine!)"
  docker image prune -a -f
  docker network prune -f

  step "[4/8] Removing local dependency caches (recreated by docker:up)"
  rm -rf tine20/tine20/vendor \
         tine20/tine20/Tinebase/js/node_modules \
         cache/node_modules_* \
         data/composer

  # TODO: Remove as soon as fixed "dovecot:1.0.10" image is provided.
  step "[4b/8] Rebuilding fixed dovecot image (shadows broken registry 1.0.9)"
  docker build -t ghcr.io/tine-groupware/tine-dev/dovecot:1.0.9 dockerfiles/mailstack/dovecot
else
  step "[3-4/8] Skipped Docker cleanup (KEEP_IMAGES=1)"
fi

if [ "${KEEP_PULLUP:-0}" != "1" ]; then
  step "[5/8] Resetting compose modules to dev defaults (pullup.json)"
  cp .pullup.json pullup.json
else
  step "[5/8] Skipped pullup.json reset (KEEP_PULLUP=1)"
fi

step "[6/8] Starting stack in detached mode"
./console docker:up --detached

COMPOSE=$(./console docker:composestring | tail -n 1)

step "[7/8] Waiting for database ..."
./console tine:awaitdb

if [ "${TINE_INSTALL_LOGS:-0}" != "1" ]; then
  step "[8/8] Installing Tine (mailstack init/reset, redis flush, install, postInstall) silently"
  ./console tine:install >/dev/null 2>&1
else
  step "[8/8] Installing Tine (mailstack init/reset, redis flush, install, postInstall)"
  ./console tine:install
fi

step "Done."
echo "ATTENTION: Stack runs in detached mode."
echo "How to stop Docker containers:"
echo "./console docker:stop"

echo
printf "Press any key to follow the live logs (Ctrl+C exits the script; the stack keeps running) ..."
echo
read -r -n 1 < /dev/tty
eval "$COMPOSE logs -f"