# AGENTS.md

This is **tine-dev**, a Docker-based dev environment for [Tine 2.0](https://tine20.com) (a PHP
groupware). It wraps `docker compose` + a PHP `symfony/console` app (`./console`) to run Tine and
its supporting services locally.

## Read this first

Before doing environment work (starting/debugging the stack, building the simulated-production
image, editing `compose/*.yml` or `pullup.json`, or diagnosing install / license / "trial" banner
problems), read the project skill:

**[.opencode/skills/tine-dev-env/SKILL.md](.opencode/skills/tine-dev-env/SKILL.md)**

It documents the dev vs prod-sim environments, the `./console` command internals, the CI Dockerfile
targets, the Tine license mechanism, and common pitfalls.

## Quick orientation
- `./console` — list/run all commands (`docker:up`, `tine:install`, `src:build`, ...).
- `pullup.json` — which `compose/*.yml` files are active (dev default: `webpack, mailstack, xdebug,
  spam, clamav`; prod-sim: `build, mailstack, spam, clamav`).
- `tine20` — symlink to the Tine 2.0 source checkout (CI Dockerfile + license file live there).
- Two modes: **dev** (source bind-mounted, DEVELOPMENT mode, xdebug, webpack) and **prod-sim**
  (locally built release image via `./console src:build`, RELEASE mode, no xdebug).
- User-facing docs: `README.md`.
