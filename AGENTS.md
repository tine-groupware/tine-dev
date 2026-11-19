# AGENTS.md

This is **tine-dev**, a Docker-based dev environment for [Tine 2.0](https://tine20.com) (a PHP
groupware). It wraps `docker compose` + a PHP `symfony/console` app (`./console`) to run Tine and
its supporting services locally.

## Read this first

Before doing environment work (starting/debugging the stack, building the simulated-production
image, editing `compose/*.yml` or `pullup.json`, or diagnosing install / license / "trial" banner
problems), read the project skill:

**[.agents/skills/tine-dev-env/SKILL.md](.agents/skills/tine-dev-env/SKILL.md)**

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

## Skills and public content

- This repo and the tine repo are public (GitHub). No internal hostnames / URLs, internal project paths, customer
  names or customer data in skills, docs, compose files or code.
- Skills of the AI coding setup (`configs/aicoding/skills`: coding, mergerequests, browser) contain only what is
  specific to this setup: the devctl / gitlab-mcp tools, the limits of the client, the stack. Project conventions
  (target branch, which tests to run, merge requests, failed pipelines, reviews) belong into the skills of the tine
  repo (`.agents/skills/contributing`, `review`, maintained in the oldest supported major branch and merged forward)
  - the skills here refer to them.
- Skills for this repo itself (stack, `./console`, images): `.agents/skills/tine-dev-env`.
