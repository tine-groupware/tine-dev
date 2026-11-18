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
  version: "1.2"
---

# Merge requests

## Tooling

In the tine-dev AI coding setup the client has no docker access and no git credentials. It uses two MCP servers:

* **gitlab-mcp** (`project_id` = `tine20%2Ftine20`): read MRs, diffs, discussions, pipelines and job logs; create
  threads and replies, resolve threads, open MRs. Gitlab is only reachable from within the company network / VPN -
  a 403 "Request forbidden by administrative rules" means: not reachable, tell the user, don't retry.
  A 403 `insufficient_granular_scope` names the permission the token of gitlab-mcp lacks (e.g. `[Pipeline: Read]`
  for the jobs of a pipeline). A plain MR comment (`create_merge_request_note`) needs `[Work Item: Create]` -
  without it, post the same text as a thread (`create_merge_request_thread`).
* **devctl**: `fetch`, `push`, `change_branch`, `tine_console`, `exec` (web, e2etests), `up`, `restart_webpack`,
  `ps`, `logs`.

### Missing or insufficient gitlab tokens

The user creates the tokens, the client never sees or handles them. When gitlab-mcp answers 401 (no / invalid /
expired token) or 403 `insufficient_granular_scope`, or devctl `fetch` / `push` fail with an authentication error
(`GITLAB_PUSH_TOKEN`), don't retry. Tell the user what failed and give them the token script to copy:

1. Print the content of `gitlab-create-tokens.js` (next to this file, `~/.claude/skills/mergerequests/`) in a code
   block. If a permission is missing that the script doesn't request yet, add it to the list in your copy and say
   so (the permission names: GraphQL query `accessTokenPermissions`, e.g. `create_work_item`).
2. Instructions: log in to gitlab in the browser, open the developer console on any gitlab page, paste the script,
   Enter. It creates `GITLAB_ACCESS_TOKEN` and `GITLAB_PUSH_TOKEN` and prints the lines for the `.env` of tine-dev.
3. Then: put them into `.env` of tine-dev and recreate on the host: `docker compose up -d gitlab-mcp` (gitlab-mcp)
   resp. `./console docker:up -d` (devctl, push token). New tokens don't replace old ones - revoke those in gitlab
   (avatar → Preferences → Access tokens).

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
3. Write the review (see "Review guidelines").

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
   tests the change needs, the full suites run in the CI (skill `coding`, "Which tests to run"). For a quick check of
   a JS fix a small puppeteer script in `tests/e2etests` run via `exec` works too (e.g. on the login page, which loads
   ExtJS without login) - never commit it.
6. **Commit:** conventional commits (see AGENTS.md of the tine repo: `fix(App): ...`), explain the why in the body.
7. **Push:** devctl `push` (only own branches `pu/<you>/...`, `force: true` = force with lease after amend/rebase).
8. **MR:** see "Create a merge request" below. For a fix on top of someone else's MR: target branch = their
   source branch, and link it in the review thread of the original MR.
9. **Afterwards:** tell the user which branch the checkout and the stack are on now; offer to switch back
   (checkout + `change_branch`).

## Create a merge request

For every new MR (fixes, features, follow-ups - also from skill `coding`), only after the user asked for it:

1. **Push first** (devctl `push`), then gitlab-mcp `create_merge_request`: `target_branch` = the target branch,
   `title` = the subject of the (main) commit, `remove_source_branch: true`.
2. **Description in English:** what was wrong / is new, the cause (and which commit introduced it, if known), the
   fix, what was tested (reproduced or only derived from the code, before / after), what is known but not done. End
   with "🤖 Generated with [Claude Code](https://claude.com/claude-code)".
3. **Assign it to the user:** the gitlab token belongs to the user, so the user is the author of the MR - set
   `update_merge_request` with `assignee_ids: [<author.id from the create_merge_request result>]` (or pass
   `assignee_ids` already when creating, if the user's gitlab id is known from a former MR). Never assign other
   people and don't set reviewers unless the user names them.
4. **Changes under `.agents/`** (skills of the tine repo): the CI job `prevent_dot_agents_changes` fails every MR
   pipeline that touches them - they are meant to be changed in the oldest major branch that has them and merged
   upwards. If the file exists only from the target branch on (e.g. `.agents/skills/e2etests` from 2026.11), set
   the label `allow-failure-prevent-dot-agents-changes` already in `create_merge_request` (`labels`) and mention
   why in the description. Otherwise ask the user whether the change belongs into an older major branch. Labels
   count from the next pipeline on: after adding one later, a retry is not enough, a new pipeline is needed (e.g.
   push the branch rebased onto the current target branch).
5. Tell the user the MR link (and later the pipeline result if asked).

## Review guidelines

* Write reviews (threads, summary) in English - not all developers speak German, and gitlab doesn't tell the
  language of another user. Replies in an existing thread: in the language of that thread.
* Verify the claims of the description against the code; reproduce the bug or the fix when it is cheap
  (`exec`, a small puppeteer script) and say whether a finding was reproduced or only derived from the code.
* Each finding: what goes wrong, in which situation (concrete inputs/state), and a concrete suggestion - code if
  short. No style nitpicks without a reason.
* Findings at a line: `create_merge_request_thread` with `position` (`base_sha`, `head_sha`, `start_sha` from
  `diff_refs`, `position_type: text`, `new_path`/`old_path`, `new_line` of the changed line). One thread per finding.
* One summary thread: overall assessment, list of the findings, what was checked.
* Mark posts as written by the agent, e.g. "Review (Claude Code, im Auftrag von <user>)" - they appear under the
  user's gitlab account.
* Answering review threads on the user's MR: reply with what was changed and in which commit, then resolve the
  thread. Don't resolve threads of others' MRs, and don't resolve a thread when the reviewer has to decide.
* Never approve or merge, never push to branches of others.

## Pitfalls

* New devctl tools appear in the client only after a reconnect (`/mcp`) or restart.
* Never test with fake credentials against the real gitlab (failed logins can get the IP blocked); use a local
  fake remote.
* `exec` / `change_branch` / `tine_console` can run for minutes - wait for the result, don't start them twice.
* Server errors (page errors, broken JSON responses): read `/workspace/logs` (tine log as JSON, complete PHP
  errors), see "Read the logs" in skill `coding`.
