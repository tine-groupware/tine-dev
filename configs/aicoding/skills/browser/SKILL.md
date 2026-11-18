---
name: browser
description: >-
  Use a real Chrome of the user from the tine-dev AI coding setup: watch / debug e2e tests in it (TEST_BROWSER_URL)
  and inspect the tine UI. Use when the user wants to see a test or the UI in their browser, when a bug depends on
  their browser / OS, when the Claude in Chrome tools are missing / not connected, or when the debug port of Chrome
  is needed. Not needed for reproducing / checking UI bugs in general - that runs headless (skill coding).
license: AGPL-3.0
metadata:
  author: Cornelius Weiss with Claude Code
  version: "1.2"
---

# Chrome of the user

**When:** only if the user wants to watch (demo, debugging together) or asks for it, or the bug depends on their
browser / OS (fonts, HiDPI, real focus / keyboard, file chooser, clipboard, it doesn't show up headless), or the
Claude in Chrome extension is needed. Otherwise use a headless Chrome in the `e2etests` container (skill `coding`,
"Reproduce a bug"; e2e tests run headless without `TEST_BROWSER_URL`): it doesn't take the focus on the user's
desktop. When using the user's Chrome: no `bringToFront()`, and popups (edit dialogs) open windows on their desktop.

The client runs in a container, the user's Chrome runs on the host. The client can't start it - tell the user the
command (for their platform) when the debug port does not answer.

**Chrome with remote debugging**, a separate instance with its own profile (since Chrome 136 remote debugging is not
possible with the default profile; install extensions like Claude in Chrome there once):

macOS:
```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
  --user-data-dir="$HOME/Library/Application Support/Google/Chrome-e2etests" \
  --remote-debugging-address=0.0.0.0 --remote-debugging-port=9222 \
  --ignore-certificate-errors --window-size=1920,1080
```
Linux:
```bash
google-chrome \
  --user-data-dir="$HOME/.config/google-chrome-e2etests" \
  --remote-debugging-address=0.0.0.0 --remote-debugging-port=9222 \
  --ignore-certificate-errors --window-size=1920,1080
```

`--remote-debugging-address=0.0.0.0` is needed, the containers can't reach 127.0.0.1 of the host. With it, everyone
who can reach port 9222 of the machine controls that Chrome (and the sessions of its profile) - the user should close
it when done.

From the containers the browser is at `http://172.118.200.1:9222`: the service `chrome-proxy` (compose/e2etest.yml)
with a fixed IP forwards to port 9222 of the host. Don't use `host.docker.internal`: Chrome rejects debugging
requests with a host name ("Host header is specified and is not an IP address or localhost").

* check: `curl -s http://172.118.200.1:9222/json/version` (from the client)
  * connection refused / no route: `chrome-proxy` is not running, the compose file `e2etest` has to be active
  * empty reply: Chrome is not running or was started without the flags above - tell the user the command
* e2e tests in it: devctl `exec` in `e2etests` with `TEST_BROWSER_URL=http://172.118.200.1:9222` (inline,
  e.g. `TEST_BROWSER_URL=... npm test src/test/...`) - the test runs visibly in the user's Chrome. Details and the
  workflow with the Claude in Chrome extension: README "Debug in a Running Browser" and skill `e2etests` of the tine
  repo (from 2026.11).
* the tine URL in that Chrome is `https://tine.local.tine-dev.de` (the host's name, not the docker network's).

## Stack on a remote host

chrome-proxy forwards to port 9222 of the docker host - with the stack on a remote host that is not the user's
machine. The user's Chrome then needs two ssh tunnels (not tested, tell the user it's fiddly and prefer headless):

* reverse, so the containers reach the debug port: e.g. `ssh -R <docker bridge ip of the remote>:9222:localhost:9222
  <remote>` (binding to another address than localhost needs `GatewayPorts clientspecified` in the sshd config of
  the remote; don't bind to `0.0.0.0` - the port would be open on the remote host)
* forward, so the user's Chrome reaches tine: `tine.local.tine-dev.de` points to 127.0.0.1, e.g.
  `ssh -L 443:localhost:443 <remote>` (port 443 locally needs root) or another name / port for the remote

Headless runs in the `e2etests` container and works on a remote host without any of this.
