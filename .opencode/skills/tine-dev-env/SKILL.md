---
name: tine-dev-env
description: "Use when working in the tine-dev repository (the Tine 2.0 Docker dev setup): starting/debugging the local dev stack (docker:up, tine:install), building the simulated-production image (./console src:build), editing compose/*.yml or pullup.json, the Tine license mechanism, or diagnosing install / license / trial banner / environment problems. Covers the dev vs prod-sim environments, the CI Dockerfile targets, the ./console command internals, and the 29-vs-30 application count."
---

# tine-dev environment setup

Reference for the `tine-dev` repo: a Docker-based dev environment for **Tine 2.0** (a PHP
groupware). It wraps `docker compose` plus a PHP `symfony/console` app (`./console`) to bring up
Tine and supporting services (mailstack, spam, clamav, db, cache, traefik). Two modes exist:
**dev** (default) and **simulated production** (a locally built release image).

Read this first when you need to understand how the environment is wired before editing files.

## The two environments

| | Dev (default) | Prod-sim (`src:build`) |
|---|---|---|
| `pullup.json` composeFiles | `webpack, mailstack, xdebug, spam` | `build, mailstack, spam` |
| Web image | pre-built `tinegroupware/dev:<branch>-<php>` (no app code) | locally built `tinegroupware/built:*` (app baked in) |
| App source | host bind-mounted at `/usr/share/tine20` (DEVELOPMENT mode) | baked into image (RELEASE mode, compiled JS) |
| JS | webpack-dev-server container (uncompiled) | compiled FAT bundles in image |
| xdebug | yes (dev image) | **no** (built image) |
| Log level | DEBUG (7, `TINE20_LOGGER_PRIORITY=7` in `docker-compose.yml`) | NOTICE (5, template default — `!override` drops the var) |
| Apps installed (licensed) | 30 (incl. `ExampleApplication`) | 29 |
| License | `TINE20_LICENSE_PATH` in `docker-compose.yml` + `tests` mount | `TINE20_LICENSE_PATH` + single-file mount in `compose/build.yml` |

The built image **does contain the app code** (the `built` Dockerfile target copies
`/usr/share/tine20` from the `build` stage: compiled JS, RELEASE mode, `composer --no-dev`,
`ExampleApplication` removed). What is **not** baked in is the **database install** —
`./console tine:install` is still required after `docker:up` in both modes; it only performs the
DB setup and stores the license.

## Repo layout (key files)
- `console` — PHP entrypoint; registers every command (see "Console commands").
- `cli/Commands/Docker/DockerCommand.php` — base class. Builds the `docker compose` command,
  derives env (`WEB_IMAGE`, `BUILT_IMAGE`, table prefix, `IMAGE_SUFFIX`), reads `pullup.json`.
  Key methods: `getComposeString()`, `getComposeEnv()`, `initCompose()`, `active($name)`,
  `updateConfig()`, `getComposeConf()`.
- `cli/Commands/Docker/DockerUpCommand.php` — `docker:up`.
- `cli/Commands/Tine/TineCommand.php` — base for `tine:*` commands (`tineInstall`,
  `tineUninstall`, `setupCli`, `tineCli`, `mailstackInit`, ...).
- `cli/Commands/Src/BuildCommand.php` — `src:build` (builds the prod image).
- `docker-compose.yml` — base services (traefik, db, cache, web). The dev `web` env sets
  `TINE20_LICENSE_PATH`, `TINE20_LOGGER_PRIORITY=7`, and mounts `./tine20/tests:/usr/share/tests/:ro`.
- `compose/*.yml` — optional add-on services, merged in `pullup.json` order. `compose/build.yml`
  is the prod-sim override of the `web` service. `compose/lessLogs.yml` lowers the dev log level
  to NOTICE (5).
- `.pullup.json` — default config (`webpack, mailstack, xdebug, spam`).
- `pullup.json` — your local override (copy of `.pullup.json` + edits). `src:build` writes
  `builtImage` here.
- `tine20` — **symlink to a git checkout of the Tine 2.0 project**
  (`https://github.com/tine-groupware/tine.git`); link it with `ln -s /path/to/tine/repo tine20`
  or let `./console` clone it (`BaseCommand::getTineDir()` prompts when `tine20/tine20.php` is
  missing). The CI Dockerfile is `tine20/ci/dockerimage/Dockerfile`; the license file is
  `tine20/tests/tine20/Tinebase/License/license_contract_MW-TEST-3.0.0.pem`.
- `README.md` — user-facing docs (quickstart, prod-sim flow, xdebug, certs, mailcatcher, ...).

## Console commands (run `./console` to list)
- Docker: `docker:up`, `docker:stop`, `docker:down`, `docker:pull`, `docker:log`, `docker:cli`,
  `docker:composestring`, `docker:webpackrestart`, `docker:generateCert`.
- Tine: `tine:install`, `tine:uninstall`, `tine:reinstall`, `tine:update`, `tine:test`,
  `tine:setuptest`, `tine:demodata`, `tine:cli`, `tine:setup`, `tine:awaitdb`, `tine:clearcache`.
- Src: `src:build`, `src:changeBranch`, `src:composer`, `src:npm`, `src:npminstall`,
  `src:langHelper`.

How `./console` builds the docker command (see `DockerCommand.php`):
- `getComposeString()` →
  `WEB_IMAGE=… WEBPACK_IMAGE=… HOMEDIR=… IMAGE_SUFFIX=-arm64 TINE20_DATABASE_TABLEPREFIX=… BUILT_IMAGE=… docker compose -f docker-compose.yml -f compose/<each pullup.json entry>.yml`.
- `getComposeEnv()` derives `WEB_IMAGE`/`WEBPACK_IMAGE` from `$imageMap` (keyed by branch
  basename; `main` → `tinegroupware/dev:main-8.5`), `IMAGE_SUFFIX` = `-arm64` on Apple Silicon,
  `TINE20_DATABASE_TABLEPREFIX` = 7 chars of the sanitized branch (or `tine20.tableprefix` from
  config when < 9 chars), and `BUILT_IMAGE` from `pullup.json.builtImage`.
- `initCompose()` reads `pullup.json` (falls back to `.pullup.json`); adds `compose/env.yml` if a
  `.env` exists.
- `active($name)` → is that compose file in the current set? Commands branch on it (e.g.
  `tine:install` runs `mailstack init`/`reset` only when mailstack is active, and always flushes
  redis before installing).

## The Tine license (most common cause of the "trial" banner)
Symptom: login page shows `tine ® trial — Please contact Metaways Infosystems GmbH to buy a valid license.`

- `TINE20_LICENSE_PATH` is **install-time only**: the container script
  `tine20/ci/dockerimage/scripts/tine20_install` appends `license='<path>'` to `setup.php --install`
  only if the var is set **and** the file exists.
- At install time the license is stored into the filesdir volume
  (`/var/lib/tine20/files/<prefix>/Tinebase/license.pem`, the `tinefiles` volume); at runtime it
  is read from there, **not** from `TINE20_LICENSE_PATH` — so it persists across `docker:up`.
- Without a stored license → trial banner, and 5 license-gated apps are skipped at install.
- Test license (mounted in both dev and prod-sim):
  `tine20/tests/tine20/Tinebase/License/license_contract_MW-TEST-3.0.0.pem`.
- Apply to a running stack without reinstalling (flag spelling is `--setLicense`):
  ```
  ./console tine:setup "--setLicense -- file=/usr/share/tests/tine20/Tinebase/License/license_contract_MW-TEST-3.0.0.pem"
  ```
  Heavier alternative: `./console tine:reinstall`.

### Application counts
- 5 apps are license-gated: `UserManual, GDPR, EFile, OnlyOfficeIntegrator, DFCom`
  (`tine20/Tinebase/License/Abstract.php::_featureNeedsPermission`).
- With a valid license: **dev installs 30, prod-sim 29** — dev additionally has
  `ExampleApplication`, which the prod image removes. Without a license: 25 (dev) / 24 (prod-sim).
- Verify (replace `<prefix>` with `TINE20_DATABASE_TABLEPREFIX`):
  `docker exec tine20-web-1 sh -c 'mysql -h db -utine20 -ptine20pw tine20 -N -e "SELECT name FROM <prefix>applications ORDER BY name;"'`

## Building the prod image (src:build)
- `./console src:build` runs, from the `tine20` repo:
  `docker build -f ci/dockerimage/Dockerfile --target built --build-arg PHP_IMAGE=php<ver> --build-arg RELEASE=… --build-arg CODENAME=<branch> --build-arg REVISION=<git short> --tag <tag> .`
- Default tag `tinegroupware/built:local`; `--tag` is persisted to `pullup.json.builtImage`
  (becomes the `BUILT_IMAGE` env for `docker:up`).
- CI Dockerfile multi-stage targets: `php8.x` → `base` (nginx/php-fpm/supervisor/cron) →
  `source` (composer install) → `jsbuild` (webpack) → `build` (RELEASE mode:
  `rm -rf ExampleApplication`, build translations, `composer install --no-dev`) → **`built`**
  (runtime image; copies the app from `build`, no xdebug, no `/usr/share/tests`).

## Full prod-sim sequence (from scratch)
```
./console docker:down
docker image prune -a -f
docker volume prune -f
docker network prune -f
cp .pullup.json pullup.json
sed -i '' 's/"webpack"/"build"/g' pullup.json   # macOS sed; on Linux use: sed -i
sed -i '' '/xdebug/d' pullup.json
./console src:build
./console docker:up
./console tine:install
curl -k https://tine.local.tine-dev.de/health   # verify
```
This mirrors the "Fail-safe commands" block in `README.md` § Simulated production environment
(the README also documents a lighter everyday flow without the prune steps).
Back to dev: restore `"webpack"` (and `"xdebug"`) in `pullup.json`, then `./console docker:up`.

## Log output and speed: dev vs prod-sim
The `tine:install` output differs drastically (dev ~46k lines, prod-sim ~40 lines) — this is
**log level, not different behavior**:
- Dev: `TINE20_LOGGER_PRIORITY=7` (DEBUG) in `docker-compose.yml` → every DEBUG/INFO line prints.
- Prod-sim: `compose/build.yml`'s `environment: !override` drops that var, so the gomplate
  template default of `5` (NOTICE) applies → only WARN/NOTICE lines survive.
- The WARN/NOTICE lines in the prod-sim output are benign: `No valid email address`
  (Tinebase_EmailUser::checkAllowedDomain), `Custom field already exists: matrix_id`,
  `Import exception: Der Name muss eindeutig sein.` During `tine:reinstall` the uninstall phase
  also logs transient non-fatal errors (`Application Filemanager not found`,
  `Table '<prefix>applications' doesn't exist`).
- Dev installs are also slower (expected): xdebug, VirtioFS bind-mounted source on Mac,
  DEVELOPMENT mode.
- Only visible difference on the website: dev shows "Example Application" in the app menu;
  prod-sim does not.

## Gotchas
- `compose/build.yml` uses `!override` (compose ≥ 2.24.4) to fully replace the `web` env/volumes.
  Any base `web` setting you need in prod-sim must be re-added there (license env + mount are
  already re-added). Other compose files (mailstack/spam/clamav) use plain lists and merge on top.
- The built image has **no xdebug** — never add `"xdebug"` to `pullup.json` in prod-sim mode
  (would break PHP-FPM startup).
- The built image contains the app code but not the DB install — `tine:install` is required after
  `docker:up` in both modes.
- `tine:install` reuses the existing DB. For a clean install use `tine:reinstall` (uninstall+install)
  or prune the volumes.
- The same DB is shared between dev and prod-sim — switching modes does not wipe data.
- After editing `opencode.json` or any config-time file, opencode must be restarted.
