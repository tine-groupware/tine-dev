---
name: browser
description: >-
  Use a real Chrome of the user from the tine-dev AI coding setup: watch / debug e2e tests in it (TEST_BROWSER_URL)
  and inspect the tine UI. Use when the user wants to see a test or the UI in a browser, when the Claude in Chrome
  tools are missing / not connected, or when the debug port of Chrome is needed.
license: AGPL-3.0
metadata:
  author: Cornelius Weiss with Claude Code
  version: "1.0"
---

# Chrome of the user

The client runs in a container. The user's Chrome runs on the host, so two things must be running there - the
client can't start them, tell the user the commands (for their platform) when the debug port does not answer:

1. **Chrome with remote debugging**, a separate instance with its own profile (since Chrome 136 remote debugging is
   not possible with the default profile; install extensions like Claude in Chrome there once):

   macOS:
   ```bash
   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
     --user-data-dir="$HOME/Library/Application Support/Google/Chrome-e2etests" \
     --remote-debugging-port=9222 --ignore-certificate-errors --window-size=1920,1080
   ```
   Linux:
   ```bash
   google-chrome \
     --user-data-dir="$HOME/.config/google-chrome-e2etests" \
     --remote-debugging-port=9222 --ignore-certificate-errors --window-size=1920,1080
   ```

2. **Port forwarding** - Chrome only accepts debugging connections on 127.0.0.1 of the host:
   ```bash
   socat TCP-LISTEN:9223,fork,reuseaddr TCP:127.0.0.1:9222
   ```
   (`brew install socat` resp. the package manager.) It listens on all interfaces: everyone who can reach port
   9223 of the machine controls that Chrome - the user should stop it when done.

From the containers the browser is then at `http://host.docker.internal:9223`:

* check: `curl -s http://host.docker.internal:9223/json/version` (from the client) - no answer: tell the user both
  commands above
* e2e tests in it: devctl `exec` in `e2etests` with `TEST_BROWSER_URL=http://host.docker.internal:9223` (inline,
  e.g. `TEST_BROWSER_URL=... npm test src/test/...`) - the test runs visibly in the user's Chrome. Details and the
  workflow with the Claude in Chrome extension: README "Debug in a Running Browser" and skill `e2etests` of the tine
  repo (from 2026.11).
* the tine URL in that Chrome is `https://tine.local.tine-dev.de` (the host's name, not the docker network's).
