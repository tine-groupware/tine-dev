---
name: coding
description: >-
  Work on the tine code in the tine-dev AI coding setup: switch branches (always up to date with origin), apply the
  branch to the stack (change_branch, install/reinstall, demodata), develop a fix or feature on an own branch, test,
  commit and push. Use when the user asks to switch/check out a branch, set up a branch with demo data, or to fix a
  bug / implement something in the tine code.
license: AGPL-3.0
metadata:
  author: Cornelius Weiss with Claude Code
  version: "1.0"
---

# Coding in the tine-dev AI coding setup

The client has no docker access and no git credentials. The stack and git remotes are reached via devctl
(`fetch`, `push`, `change_branch`, `tine_console`, `exec`, ...), gitlab via gitlab-mcp - see the skill
`mergerequests` for working on merge requests. The checkout is `/workspace/tine20`; its `.git/config` and hooks
are read-only: run git with `-c safe.directory=*` if needed, `checkout --no-track`, no `git submodule update`
(`change_branch` does it).

## Switch to a branch

Always in this order, each step finished before the next:

1. **Bring it up to date:** devctl `fetch` with `branch` = the branch. Never work on a stale local branch.
2. **Check out:**
   * local branch exists: `git checkout <branch>`, then `git merge --ff-only origin/<branch>`. If that fails (the
     local branch has own commits or diverged), stop and ask the user - never reset or rebase it on your own.
   * no local branch: `git checkout --no-track -b <branch> origin/<branch>`
   * uncommitted changes in the checkout: ask the user first (they are the user's work), don't stash or discard
3. **Apply the branch to the stack:** devctl `change_branch` (submodules, images and db table prefix of the branch,
   composer, npm, webpack, cache). Takes minutes - wait for the result. Without it the stack runs the new code against the
   database of the previous branch: login hangs, tests fail for no reason.
4. **Database:** devctl `tine_console`:
   * `install` if the table prefix of the branch was never installed (errors about missing tables / setup)
   * `reinstall` if it was installed with another code state (e.g. after big changes) - it drops the data of that
     prefix: fine for major branches and MR branches, ask first on the user's own working branches
5. **Demo data** (if asked for, or for manual tests / e2e tests): `tine_console` `demodata`.
6. Tell the user what was done and which branch the checkout and the stack are on now.

## Fix a bug / implement something

1. Switch to the target branch (above): the oldest supported major branch the fix belongs into (e.g. `2025.11`) -
   it is merged forward into the newer ones.
2. Create an own branch on top of it: `git checkout --no-track -b pu/<you>/<topic> <target branch>`.
3. Reproduce first (test, `exec` in web, a small puppeteer script in `tests/e2etests` run via `exec` - never
   commit it), then fix. Read AGENTS.md of the tine repo for conventions.
4. Tests: PHPUnit via `tine_console` `test`, e2e via `exec` in `e2etests` (skills `phpunit`, `e2etests` of the tine
   repo). Add tests for the fix where the repo has them.
5. Commit (conventional commits, why in the body) - only when the user asks for it.
6. Push: devctl `push` (only own branches `pu/<you>/...`). Merge request: gitlab-mcp `create_merge_request`,
   target = the target branch, see skill `mergerequests`.
