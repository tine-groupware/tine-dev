---
name: mergerequests
description: >-
  Create merge requests (assigned to the user), review merge requests, work on them (fixes, follow-ups) and answer
  review threads in the tine-dev AI coding setup (gitlab-mcp + devctl). Use when the user mentions a merge request /
  MR (e.g. "MR 9568", "!9568"), asks to create / open an MR, for a review, review comments, or code changes / tests
  on the code of a merge request.
license: AGPL-3.0
metadata:
  author: Cornelius Weiss with Claude Code
  version: "1.3"
---

# Merge requests

## Tooling

In the tine-dev AI coding setup the client has no docker access and no git credentials. It uses two MCP servers:

* **gitlab-mcp**: read MRs, diffs, discussions, pipelines and job logs; create threads and replies, resolve threads,
  open MRs. `project_id` = the URL-encoded path of the project in gitlab (from `git remote get-url origin`, e.g.
  `group%2Fproject`). If gitlab is reachable only from an internal network / VPN, a 403 "Request forbidden by
  administrative rules" means: not reachable, tell the user, don't retry.
  A 403 `insufficient_granular_scope` names the permission the token of gitlab-mcp lacks (e.g. `[Pipeline: Read]`
  for the jobs of a pipeline). A plain MR comment (`create_merge_request_note`) needs `[Work Item: Create]` -
  without it, post the same text as a thread (`create_merge_request_thread`).
* **devctl**: `fetch`, `push`, `change_branch`, `tine_console`, `exec` (web, e2etests), `up`, `restart_webpack`,
  `ps`, `logs`.

### Missing or insufficient gitlab tokens

The user creates the tokens, the client never sees or handles them. When gitlab-mcp answers 401 (no / invalid /
expired token) or 403 `insufficient_granular_scope`, or devctl `fetch` / `push` fail with an authentication error
(`GITLAB_GIT_TOKEN`), don't retry. Tell the user what failed and give them the token script to copy:

1. Print the content of `gitlab-create-tokens.js` (next to this file, `~/.claude/skills/mergerequests/`) in a code
   block. If a permission is missing that the script doesn't request yet, add it to the list in your copy and say
   so (the permission names: GraphQL query `accessTokenPermissions`, e.g. `create_work_item`).
2. Instructions: log in to gitlab in the browser, open the developer console on any gitlab page, paste the script,
   Enter. It creates `GITLAB_ACCESS_TOKEN` and `GITLAB_GIT_TOKEN` and prints the lines for the `.env` of tine-dev.
3. Then: put them into `.env` of tine-dev and recreate on the host: `docker compose up -d gitlab-mcp` (gitlab-mcp)
   resp. `docker compose up -d devctl` (git token; `./console docker:up` skips both). New tokens don't replace old
   ones - revoke those in gitlab (avatar → Preferences → Access tokens).

Never ask the user to paste a token into the chat.

The checkout is `/workspace/tine20`. Its `.git/config` and hooks are read-only for the client, so:

* run git with `-c safe.directory=*` if git complains about the ownership
* `git checkout --no-track -b ...` - tracking would write the config
* no `git submodule update` (writes `.git/modules`) - devctl `change_branch` updates the submodules. Never commit
  the submodule pointer by accident.

## Just a review (no code changes, no tests)

Read everything via gitlab-mcp, no checkout needed:

1. `get_merge_request` (title, description, `diff_refs`, target branch), `list_merge_request_changed_files`,
   `get_merge_request_file_diff` for the files, `mr_discussions` (existing threads).
2. Read the code around the changes (local checkout of the target branch, or `get_file_contents` with `ref`).
3. Write the review (see "Reviews").

## Code changes or tests on an MR

Like switching a branch in skill `coding` (keep the local branch up to date with origin, stop on divergence),
with the MR specifics:

1. **Get the branch:** devctl `fetch` with `branch` = source branch of the MR. It is then available as
   `origin/<branch>`.
2. **Check out:**
   * own MR (`pu/<you>/...`): `git checkout --no-track -b <branch> origin/<branch>` (or, if the branch exists
     locally, check it out and `git merge --ff-only origin/<branch>`)
   * MR of someone else: never commit to their branch - create an own branch on top of it:
     `git checkout --no-track -b pu/<you>/<topic> origin/<their branch>`
3. **Apply the branch to the stack:** devctl `change_branch` (images and db table prefix of the branch, composer,
   npm, webpack, cache). Takes minutes. Without it the stack runs the new code against the database of the previous
   branch: login hangs, tests fail for no reason.
4. **Database:** devctl `tine_console` `reinstall` (or `install` for a table prefix never installed before), plus
   `demodata` if tests need demo data. reinstall drops the data of that table prefix - fine for MR branches, ask
   before doing it on the user's own working branch.
5. **Run tests:** PHPUnit via `tine_console` `test` (e.g. `args: ["Admin_Controller_SchedulerTaskTest"]`), e2e tests
   via `exec` in `e2etests` (`npm test src/test/...`), see the skills `phpunit` and `e2etests` of the tine repo. Only the
   tests the change needs, the full suites run in the CI (skill `contributing`, "Which tests to run"). For a quick check of
   a JS fix a small puppeteer script in `tests/e2etests` run via `exec` works too (e.g. on the login page, which loads
   ExtJS without login) - never commit it.
6. **Commit:** as in skill `contributing` - only when the user asks.
7. **Push:** devctl `push` (only own branches `pu/<you>/...`, `force: true` = force with lease after amend/rebase).
8. **MR:** see "Create a merge request" below. For a fix on top of someone else's MR: target branch = their
   source branch, and link it in the review thread of the original MR.
9. **Afterwards:** tell the user which branch the checkout and the stack are on now; offer to switch back
   (checkout + `change_branch`).

## Create a merge request

Conventions (title, description, assignee, `.agents` changes): skill `contributing` of the tine repo, "Merge
request" (read it from `origin/main` if the checkout doesn't have it). For every new MR, only after the user asked:

1. **Push first** (devctl `push`), then gitlab-mcp `create_merge_request`: `target_branch`, `title`,
   `remove_source_branch: true`, `squash: false` (the gitlab project preselects squash, the commits are merged as
   they are - see skill `contributing`), labels if needed (e.g. `allow-failure-prevent-dot-agents-changes`). Labels
   count from the next pipeline on: after adding one later, a retry is not enough - push the branch rebased onto the
   current target branch.
2. **Description** as in skill `contributing`, ending with
   "🤖 Generated with [Claude Code](https://claude.com/claude-code)".
3. **Assign it to the user:** the gitlab token belongs to the user, so the user is the author of the MR - pass
   `assignee_ids: [<user's gitlab id>]` (known from a former MR) or set it afterwards with `update_merge_request`
   (`author.id` of the `create_merge_request` result). Reviewers only when the user names them.
4. Tell the user the MR link (and later the pipeline result if asked).

## Failed pipeline

The procedure (rebase first, random fails are fixed - in the same MR if it isn't a draft and targets the oldest
major branch with the test, otherwise in an own MR) is in skill `contributing`, "Failed pipeline".
With the tools here:

1. **Rebase:** devctl `fetch` of the source and the target branch, `git rebase origin/<target branch>`, devctl
   `push` with `force: true`. A rebase that stops on a file the MR changes although `git status` shows no local
   changes: `git update-index --refresh`, then `git rebase --continue` (stale index stat on the mount).
2. **Failing test:** `list_pipeline_jobs` (`scope: failed`), `get_pipeline_job_output` (`offset` counts from the end
   of the log - the e2e summary is in the last ~120 lines) or `get_pipeline_test_report` (with `jmespath`, e.g.
   `test_suites[].test_cases[?status=='failed'].name`). Artifacts (screenshots) come back as text - not usable.
3. **Other pipelines** of the target branch / other MRs: `list_pipelines`, `list_merge_request_pipelines`.
4. **Reproduce** the race with a temporary e2e test / puppeteer script via `exec` in `e2etests` (never commit it,
   always `afterAll(() => lib.closeBrowser())` - otherwise jest doesn't exit and `exec` hangs until its timeout;
   run it with `timeout 300 npm test ... > /tmp/x.log 2>&1` and grep the log).

## Reviews

The guidelines (what to check, how to write findings, English, marking agent posts, answering threads): skill
`review` of the tine repo. With the tools here:

* Findings at a line: `create_merge_request_thread` with `position` (`base_sha`, `head_sha`, `start_sha` from
  `diff_refs`, `position_type: text`, `new_path` / `old_path`, `new_line` of the changed line).
* Summary: `create_merge_request_thread` without position (or `create_merge_request_note`, see "Tooling").
* Answer / resolve threads: `create_merge_request_discussion_note`, `resolve_merge_request_thread`.
* Reproduce cheaply: `exec`, a small puppeteer script.

## Pitfalls

* New devctl tools appear in the client only after a reconnect (`/mcp`) or restart.
* Never test with fake credentials against the real gitlab (failed logins can get the IP blocked); use a local
  fake remote.
* `exec` / `change_branch` / `tine_console` can run for minutes - wait for the result, don't start them twice.
* Server errors (page errors, broken JSON responses): read `/workspace/logs` (tine log as JSON, complete PHP
  errors), see "Read the logs" in skill `coding`.
