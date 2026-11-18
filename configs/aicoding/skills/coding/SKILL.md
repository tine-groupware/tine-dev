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
  version: "1.2"
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

## Read the logs

The logs of the web container are mounted read-only at `/workspace/logs` (compose/aicoding.yml). Both files are
emptied when the web container is (re)started (devctl `up`, `change_branch`):

* `tine20.jsonl` - the tine log on DEBUG, one JSON object per line: `message`, `priority` (3 ERR, 4 WARN,
  5 NOTICE, 6 INFO, 7 DEBUG), `priorityName`, `user`, `request_id` (6 chars, one per request), `transaction_id`,
  `timestamp`, `logruntime`. Exceptions with their traces are in `message`.
* `php_errors.log` - PHP errors of web requests (php-fpm), complete. In docker logs nginx cuts them after ~1 KB
  (e.g. the stack trace of a fatal error). PHP errors of CLI commands (`tine_console`) are in their output.

Find the error, then read its whole request, e.g. with php (no jq in the client):

```bash
# errors and warnings of the last runs
php -r '$f = fopen("/workspace/logs/tine20.jsonl", "r"); while (($l = fgets($f)) !== false) { $e = json_decode($l, true);
  if ($e && $e["priority"] <= 4) echo $e["timestamp"], " ", $e["request_id"], " ", $e["priorityName"], " ", substr($e["message"], 0, 300), "\n"; }'
# everything of one request
grep '"request_id":"e30324"' /workspace/logs/tine20.jsonl | php -r 'while (($l = fgets(STDIN)) !== false) echo json_decode($l, true)["message"], "\n";'
tail -50 /workspace/logs/php_errors.log
```

The file grows fast on DEBUG (a `tine_console install` writes ~20 MB): filter, don't `cat` it. The devctl tool
`logs` (docker logs) is for the other containers (webpack, db, e2etests) and the nginx access log of web.

## Fix a bug / implement something

1. Find out which branches have the bug (ask the user if unclear; a feature may exist only on `main`, e.g.
   `git log origin/2026.11..origin/main -S<symbol>`). Then switch to the target branch (above): the oldest supported
   major branch the fix belongs into (e.g. `2025.11`) - it is merged forward into the newer ones.
2. Create an own branch on top of it: `git checkout --no-track -b pu/<you>/<topic> <target branch>`.
3. Reproduce, find the cause, fix, verify - see the sections below. Read AGENTS.md of the tine repo for conventions.
4. Tests: PHPUnit via `tine_console` `test`, e2e via `exec` in `e2etests` (skills `phpunit`, `e2etests` of the tine
   repo). Add tests for the fix where the repo has them. Run them targeted - see "Which tests to run".
5. Commit (conventional commits, why in the body) - only when the user asks for it.
6. Push: devctl `push` (only own branches `pu/<you>/...`). Merge request: skill `mergerequests`, "Create a merge
   request" (target = the target branch, assign it to the user). Authentication errors of `fetch` / `push` or
   gitlab-mcp: skill `mergerequests`, "Missing or insufficient gitlab tokens".

## Reproduce a bug

Reproduce before fixing - and say in the end whether a finding was reproduced or only derived from the code.

* **UI bugs: headless by default** - a temporary puppeteer script in `tests/e2etests` (e.g. `tmp-<topic>.cjs`,
  never commit it, delete it when done), run via devctl `exec` in `e2etests`. Its Chrome runs in the container,
  doesn't take the focus on the user's desktop, and works the same when the stack runs on a remote host:
  ```js
  const puppeteer = require('puppeteer'); require('dotenv').config();
  const browser = await puppeteer.launch({headless: true, defaultViewport: {width: 1920, height: 1080},
      args: ['--lang=de-DE,de', '--no-sandbox', '--disable-dev-shm-usage', '--disable-popup-blocking', '--ignore-certificate-errors']});
  const page = await browser.newPage();
  await page.setExtraHTTPHeaders({'Accept-Language': 'de'});
  await page.goto(process.env.TEST_URL, {waitUntil: 'networkidle2'});
  // login (below), the steps, measure, page.screenshot(...)
  await browser.close();
  ```
  Every run starts a new browser (logged out, no dialogs open): do the whole flow - login, setup, actions,
  measurements, screenshots - in one run.
* **The user's Chrome** (skill `browser`, `puppeteer.connect({browserURL: 'http://172.118.200.1:9222'})`) only when
  the user wants to watch, asks for it, or the bug depends on their browser / OS (fonts, HiDPI, real focus /
  keyboard, file chooser, clipboard, a bug that doesn't show up headless). Don't call `bringToFront()`, and expect
  popups to open windows on their desktop. The session and open dialogs survive between runs there.
* In both:
  * the main tab: the page with `TEST_URL` that has no opener (`!p.target().opener()`) - popups (edit dialogs)
    have the same URL
  * login page (`input[name=username]` exists, e.g. after a reinstall): empty the fields via
    `el.value = ''; el.dispatchEvent(new Event('input', {bubbles: true}))` (triple click doesn't select on macOS),
    type user / password from `tests/e2etests/.env`, click the button "Anmelden"
  * popups: `browser.once('targetcreated', t => t.page())` before the action that opens them
* **No HMR:** after a change wait for "compiled successfully" in devctl `logs` of `webpack`, then really reload
  the page (`page.reload()` - a `goto` that only changes the hash is no navigation). Check that the new code is
  loaded (e.g. `String(Some.Class.prototype.method).includes('…')`).
* **Test data via the json API** in the page (`Tine.<App>.save<Model>(…)`), unique names, delete it afterwards.
  The api functions exist only after the registry is loaded: `waitForFunction(() => typeof Tine.<App>?.<fn> === 'function')`.
  Pitfalls seen: calendar events need an attendee (the default filter shows only events of the current user);
  custom fields need grants (`account_type: 'anyone'`) and are visible only after `tine_console clearcache` and a
  reload with `Tine.Tinebase.common.reload({clearCache: true})`.
* **Measure instead of only looking:** read bounding boxes / computed styles / classes in `page.evaluate` (e.g. "the
  title is below its event") and take a screenshot (`page.screenshot`, then Read the png) to look at it.
* **Server bugs:** the logs (above), `exec` in web, PHPUnit.

## Find the cause

* Which change introduced it: `git log -S'<code>'` / `git log -L'/<function>/,+20:<file>'` on the target branch,
  and only the commits of the feature branch / time range in question. Say which commit (and since when / which
  release) in the MR.
* Read the code around the change, not only the line: callers, how values are compared / normalized (e.g. a
  check that suddenly needs `^…$`), css of the elements involved.
* Narrow it down with the reproduction: one variable at a time (with / without the tag, one / four tags, ...).

## Verify the fix

* The reproduction fails before and passes after the fix (keep the "before" numbers / screenshot).
* All places that use the changed code: other views / dialogs / apps (`git grep` the class / function), edge cases
  (empty values, many values, narrow / long content, other users / grants), and that nothing else moved (e.g.
  elements without the feature look as before).
* Tests of the repo for the area (e2e / PHPUnit), with a connected browser and headless where it matters - targeted,
  see below.

## Which tests to run

The CI runs the full suites on every MR pipeline. Locally run only what the change needs:

* the new / changed tests (`--filter <testMethod>`), and the tests of the code you changed directly (e.g. the test
  methods of the class / feature, an e2e test of the dialog)
* not whole test classes, app suites or all e2e tests for a simple fix / feature - they take minutes, and failures
  that come from the environment (e.g. eDocument validation, external services) cost time without telling anything
  about the change
* broader runs only when the task needs it (changes in shared code like Tinebase, the filesystem, the controller /
  model base classes, update scripts touching many records, a refactoring) or the user asks for it
* a test that fails: check against the target branch before blaming the change (e.g. `git stash`, run that one test,
  `git stash pop`), and say in the MR which local failures exist without the change
* after pushing: the pipeline is the full check - if the user asks, read its result (skill `mergerequests`)
* Clean up: temporary scripts, screenshots, test data.
