tine-dev (docker dev setup)
----

[[_TOC_]]

# Pullup / console

## Quickstart

Prerequisites: git, docker, php, composer, npm and your user is in the docker group. If not see "Install and Setup Docker"

Note for Mac users: install composer and npm via homebrew:
```
brew install composer
brew install node
```

1. clone this repository: 
   ```
   git clone https://github.com/tine-groupware/tine-dev.git tine-dev
   cd tine-dev
   ```
2. install symfony/console dependencies: 
   ```
   composer install
   ```
3. get tine20 repository: 
   - OPTION 1: via console script
     - next step (`docker:up`) will ask you:
        > tine20 dir is not linked. Should it be cloned? [yes]:  
         [0] yes  
         [1] no  
         [2] ignore
       
   - OPTION 2: by yourself
     - clone from GitHub/GitLab:
       ```
       cd ..
       git clone https://github.com/tine-groupware/tine.git tine20
       cd -
       ```
     - link tine20 repository to tine-dev:
       ```
       ln -s ../tine20 tine20
       ```
4. run Docker setup:
   ```
   ./console docker:up
   ```
5. open a new Terminal window (closing the window will stop the Docker containers)
6. install tine:
   ```
   ./console tine:install`
   ```
7. visit https://tine.local.tine-dev.de, login as `tine20admin` pw: `tine20admin`

Note:
In case tine stops working after a branch switch or computer restart run "./console tine:reinstall" WITHOUT stopping tine before that.

## Optional

- for console commands autocomplete on Linux (bash) add `eval $(~/path/to//docker/console _completion --generate-hook)` to your shell's profile (e.g. ~/.bashrc)
- for autocomplete on macOS (zsh) add `autoload -Uz compinit && compinit` and below `eval $(~/path/to//docker/console _completion --generate-hook)` to your shell's profile (e.g. ~/.zshrc)
- link your tine20 source `ln -s /path/to/tine/repo tine20` or just wait for console to clone it for you
- link docservice source `ln -s /path/to/docservice/repo docservice` or just wait for console to clone it for you
- link broadcasthub source `ln -s /path/to/tine20-broadcsthub/repo broadcasthub` or just wait for console to clone it for you
- install docservice dependencies, if console has cloned it you don't need to do anything: `cd docservice && composer install --ignore-platform-reqs`
- install broadcasthub dependencies, if console has cloned it you don't need to do anything: `cd broadcasthub && npm install`

## Console Commands
Run `./console` in tine-dev directory to see available Commands

## pullup.json

To override default settings copy `.pullup.json` to `pullup.json`

+ composeFiles: docker sets up "modules". Take a look at `./compose` dir
+ more: any wishes? > issue tracker 

## Simulated production environment (src:build)

The dev setup serves the app from your linked source (DEVELOPMENT mode) and serves
uncompiled JS from a webpack dev-server container.  
To simulate the production
environment (release image: compiled JS bundles, RELEASE mode, action queue worker):

1. Stop and remove all containers: `./console docker:down`
2. `./console src:build` — builds `tinegroupware/built:local` from the CI Dockerfile
   (target `built`). A custom `--tag` is persisted to `pullup.json` automatically.
3. Edit `pullup.json`: replace `"webpack"` with `"build"` and remove `"xdebug"` (the production image has no xdebug).  
   Note: "build" must be the first entry in "composeFiles"!
4. `./console docker:up` — the web container now runs the built image (app baked in,
   no source mount).
5. `./console tine:install` — installs tine (same command as the dev setup).
    + Note: Docker re-uses an existing database.  
      For a clean install run (while the containers are still up and running):

          ./console tine:uninstall
          docker compose exec cache redis-cli flushall
          ./console tine:install
          ./console docker:up

Back to dev: restore `"webpack"` in `pullup.json`, run `./console docker:up` (and maybe a clean install as well).

## Fresh local dev environment

Use this script to clean up your dev environment and start 100% fresh.  
tine is installed without any output to keep the logs clean.  
All options can also be combined.

WARNING: deletes all local docker images, volumes and networks!  
WARNING: overwrites your local `pullup.json` file!

    # default, all steps, silent tine:install
    ./scripts/fresh-dev.sh

    # state wipe only, keeps images and volumes and caches, faster
    KEEP_IMAGES=1 ./scripts/fresh-dev.sh

    # leaves pullup.json untouched
    KEEP_PULLUP=1 ./scripts/fresh-dev.sh

    # outputs tine:install logs for debugging
    TINE_INSTALL_LOGS=1 ./scripts/fresh-dev.sh

What it does (without any options):

    ./console docker:down
    docker volume rm tine20_tinefiles
    docker image prune -a -f
    docker network prune -f
    rm -rf tine20/tine20/vendor
    rm -rf tine20/tine20/Tinebase/js/node_modules
    rm -rf cache/node_modules_*
    rm -rf data/composer
    cp .pullup.json pullup.json
    ./console docker:up --detached
    ./console tine:awaitdb
    ./console tine:install >/dev/null 2>&1
    ./console docker:logs -f

# Development

Read `.agents/skills/tine-dev-env/SKILL.md` to get an overview and context of the tine-dev setup.

## Tine source (tine20 symlink)

`tine20` is a symlink to a git checkout of the Tine project (https://github.com/tine-groupware/tine).
In dev mode it is mounted into the web container at `/usr/share/tine20` (DEVELOPMENT mode),
so PHP changes take effect immediately — run `./console tine:clearcache` if cached code hides your changes.

Sub paths of the checkout (`tine20/tine20`, `tests`, webpack build dirs, ...) are mounted from the volume
`tine20-src` (`docker-compose.yml`, bound to the checkout root `TINE20_DIR`) — not as bind mounts: docker follows
symlinks in bind sources, so a symlink planted in the (container writable) checkout could mount any host
dir. `volume.subpath` mounts don't follow symlinks. `./console docker:checkmounts` (also run by
`docker:up` and `docker:webpackrestart`) rejects bind mounts of checkout sub paths,
docker socket mounts, privileged mode etc. for services not in `pullup.json` `"trustedServices"`
(default `["traefik", "devctl"]`). After moving the checkout: `./console docker:down && docker volume rm
tine20_tine20-src tine-dev-tine20-src` (neither compose nor `docker run` of `./console` recreate them, `docker:checkmounts`
refuses to start).

Switching branches: `./console src:changeBranch --git --branch <branch>` does the git checkout (without
`--git`: after a checkout by hand), submodule update, then composer install, npm install, webpack restart and cache clear in one go
(`--nonpm` skips npm). The dev web image is selected per branch (`main` → `tinegroupware/dev:main-8.5`,
see `$imageMap` in `cli/Commands/Docker/DockerCommand.php`), so run `./console docker:up` afterwards.

## Source tooling (runs in a container against your checkout)

- `./console src:composer "<cmd>"` — composer in the Tine source context, e.g. `src:composer "require metaways/timezoneconverter"`
- `./console src:npm "<cmd>"` — npm in `Tinebase/js`, e.g. `src:npm "install --save-dev <pkg>"`
- `./console src:npminstall` — (re)install npm dependencies
- `./console src:langHelper "<cmd>"` — translation helper, e.g. `src:langHelper "-- '-u --app Calendar'"`
- `./console src:build` — build the simulated production image (see "Simulated production environment")

## JavaScript

In dev mode the JS is served uncompiled from the webpack-dev-server container (`compose/webpack.yml`).
After larger JS changes restart it: `./console docker:webpackrestart`.

## Tine runtime helpers

- `./console tine:cli "<options>"` — run a Tine CLI method in the web container, e.g.
  `tine:cli "--method Tinebase.clearCache --username tine20admin --password tine20admin"` (don't pass `--config`)
- `./console tine:setup "<options>"` — run `setup.php` with arguments (e.g. `--setLicense`, see SKILL.md)
- `./console tine:demodata` — create demo data
- `./console tine:clearcache` — clear all caches
- `./console tine:test` / `./console tine:setuptest` — run the Tine unit tests / setup tests
  (dev mode only: the tests live in the `tests` mount, which prod-sim does not have)
- `./console docker:cli web` — shell into the web container
- `./console docker:log` — follow the container logs

## E2E tests

The `e2etest` compose module adds the container `e2etests` for the jest/puppeteer tests in
`tine20/tests/e2etests`. Its image `ghcr.io/tine-groupware/tine-dev/puppeteer` (`dockerfiles/puppeteer`) is the
one the e2e jobs of the tine CI use as well, built by the CI of this repo for amd64 and arm64. It uses Debian's
chromium (puppeteer's own Chrome download has no linux/arm64 build).

Add `"e2etest"` to `composeFiles` in `pullup.json`, then:

~~~
./console docker:up
./console tine:demodata                     # some tests need demo data
docker exec e2etests npm install            # once / after package.json changes
docker exec e2etests npm test src/test/CrewScheduling/crewScheduling.test.js
~~~

The tests use `TEST_URL` etc. from `tine20/tests/e2etests/.env`. Inside the docker network
`tine.local.tine-dev.de` resolves to traefik (network alias set in `compose/e2etest.yml`), so the
default `TEST_URL` works. Error screenshots end up in `tine20/tests/e2etests/screenshots/Error/`.
AI coding clients run the tests with the devctl tool `exec` (see "AI coding clients").

**Watch the tests in your Chrome** (from tine 2026.11, see `tine20/tests/e2etests/README.md` "Debug in a
Running Browser"): start a separate Chrome on the host with its own profile and
`--remote-debugging-address=0.0.0.0 --remote-debugging-port=9222` (commands for macOS / Linux: client skill
`browser`). Without `--remote-debugging-address` the containers can't reach it. With it, everyone who reaches port
9222 of your machine controls that Chrome - close it when done.

~~~
docker exec -e TEST_BROWSER_URL=http://172.118.200.1:9222 e2etests npm test src/test/...
~~~

`172.118.200.1` is the service `chrome-proxy` (`compose/e2etest.yml`), which forwards to port 9222 of the host.
Chrome rejects debugging requests with a host name like `host.docker.internal` ("Host header is specified and is not
an IP address or localhost"), and the IP of the host differs per platform, so the proxy gives a fixed IP on all
platforms.

The same Chrome is used by Claude in Chrome (`claude --chrome` in the claude-code container, extension installed
in that Chrome instance), see the client skill `browser`.

## Docs

The `docs` compose module serves the tine docs (`tine20/docs`, MkDocs with live reload) at
https://docs.local.tine-dev.de/. See `tine20/docs/developers/mkdocs.md`.

## Adding a compose module

Create `compose/<name>.yml` (see the existing files), add `"<name>"` to `composeFiles` in `pullup.json`,
then `./console docker:up`.

# Links

* https://linuxize.com/post/how-to-remove-docker-images-containers-volumes-and-networks/

# Install and Setup Docker & other Prerequisites

## Docker

https://docs.docker.com/engine/install/

## Add user to docker group (Linux)

https://docs.docker.com/engine/install/linux-postinstall/

## Install composer & npm (Ubuntu)

    sudo apt install composer npm

# Check/Get Docker Image

Our dev image is on docker hub:
https://hub.docker.com/r/tinegroupware/dev

# Show Containers

    docker ps

# Run bash in Container

    docker exec -it tine20 /bin/sh

# Open tine in Browser

[tine.local.tine-dev.de](https://tine.local.tine-dev.de/) - nginx

[tine.local.tine-dev.de](https://tine.local.tine-dev.de/) - webpack served

[tine.local.tine-dev.de/setup.php](https://tine.local.tine-dev.de/setup.php) - webpack served setup

[localhost:4002](http://localhost:4002) Phpmyadmin Username:tine20 Password:tine20pw

# Other Useful Functions
## Containers

    docker ps
    docker ps -a            # shows all (not running only) containers
    docker rm <container id/name>

## Images

    docker image ls
    docker image ls -a      # shows all containers, as well as deps
    docker image rm <image id/name>

# Debugging with Xdebug
## Debugging with PhpStorm

this is the default xdebug.ini:

    zend_extension=xdebug.so
    xdebug.default_enable=on
    xdebug.remote_enable=on
    xdebug.remote_handler=dbgp
    xdebug.remote_port=9001
    xdebug.remote_host=172.18.0.1
    xdebug.remote_autostart=on

you need to define a "PHP remote debug" server in PhpStorm:

     name: debugger
     server: 
       name: tine20docker
       host: 172.18.0.1 (or tine20docker)
       port: 9001 
       debugger: Xdebug
       path mapping:
         /local/tine/tests -> /usr/share/tests
         /local/tine/tine20 -> /usr/share/tine20
     
     ide key: serverName=tine20docker

open Xdebug port in PhpStorm

    File | Settings | Languages & Frameworks | PHP | Debug | Xdebug
    - Debug port : 9001
    - [x] can accept external connections 
    
if you have a different IP, you might need to use the XDEBUG_CONFIG env vars in docker-compose.yml

## Debugging with vscode:
You will need to install the "PHP Debug" extension by xdebug. The launch configuration to listen for xdebug connection, is already configured in this repo. Just start listening with "Listen for Xdebug".

## Troubleshooting

some tips on testing your xdebug/phpstorm setup:

### Check Connectivity

on docker host:

    $ netstat -tulpen | grep 9001
    tcp        0      0 0.0.0.0:9001            0.0.0.0:*               LISTEN      1000       2918160    14641/java

in container:

    $ nc -vz 172.118.0.1 9001
    172.118.0.1 (172.118.0.1:9001) open

### Check xdebug Log

- activate xdebug log in container (add `remote_log=/tine/logs/xdebug.log` in xdebug.yml)
- look into log (default path: /tine/logs/xdebug.log)

### Allow iptables Access from Container -> Host

    sudo iptables -I INPUT 1 -i <docker-bridge-interface> -j ACCEPT
    
`<docker-bridge-interface>` is something like "br-3ff4120010e5" which has ip:172.118.0.1 (visible with ifconfig)

OR use ufw (IP might differ):

    sudo ufw allow from 172.16.0.0/12 to any port 9001

### Docker Network Problems (for example: "ERROR: Pool overlaps ...")

you might need to remove old / unused docker networks:

    ➜  docker network ls                                                                                                                                 git:(phil|✚4⚑2
    NETWORK ID     NAME                           DRIVER    SCOPE
    833313480af2   docker_internal_network        bridge    local
    0ed859aaf6ea   tine20_internal_network        bridge    local
    92b66a6b4791   tine-docker_external_network   bridge    local
    c6e1e1f2a5cb   tine-docker_internal_network   bridge    local

    ➜  docker network rm docker_internal_network tine20_internal_network docker_external_network docker_internal_network

OR

    ➜  docker network prune

# Debug / Test Stuff with Fake Previews

sometimes you don't have a working doc service but need to test files with previews.

## Copy Some Images to Container:

    docker cp ~/Pictures/image1.png tine20:/tine/files
    docker cp ~/Pictures/image2.png tine20:/tine/files
    
## Patch tine20/Tinebase/FileSystem/Preview/ServiceV1.php

```php
     public function getPreviewsForFile($_filePath, array $_config)
     {
        // just for testing
        $blob1 = file_get_contents('/tine/files/ssh_password.png');
        $blob2 = file_get_contents('/tine/files/tine20_datenbanken.png');
        return array('thumbnail' => array('blob'), 'previews' => array($blob1, $blob2));
        // [...]
     }
```
## Configure Previews (config.inc.php)

```php
'filesystem' => array(
    'createPreviews' => true,
    'previewServiceVersion' => 1,
),
```

## Create Previews for files

     docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php tine20.php  --method Tinebase.fileSystemCheckPreviews  --username=test --password=test"

# Add Document Service

NOTE: some fonts are not available on the minimal docker image ... so don't
 wonder about strange looking texts ... ;) 

## Clone, Initialize and Link Repository

    git clone git@gitlab.metaways.net:tine20/documentPreview.git
    cd documentPreview
    composer install
    cd /path/to/tine20-docker
    ln -s /patch/to/docservice docservice
    
## Configure

note: this only works with tine20.com/2018.11* branches

```php
'filesystem' => array(
    'createPreviews' => true,
    'previewServiceVersion' => 2,
    'previewServiceUrl' => 'http://docservice/v2/documentPreviewService',
),
```

# Add tine Broadcasthub
## Clone, Initialize and Link Repository

    git clone git@gitlab.metaways.net:tine20/tine20-broadcasthub.git broadcasthub
    cd broadcasthub
    # Make sure NODE_ENV is not set or is not "production"
    # development dependencies have to get installed
    npm install
    cd /path/to/tine20-docker
    ln -s /patch/to/broadcasthub broadcasthub

Make sure to always fetch the latest production docker image for the tine broadcasthub, change the tag in file `compose/broadcasthub` accordingly.


## Configure
There is a setup task in the tine repository for adding an `auth_token` record: `setup.php --add_auth_token --`.
`tine:install` runs it with the token `longlongtoken` for tine20admin if the broadcasthub is active. The setup task
was removed in 2026.11, there no token is created.

Formerly this record had to be inserted manually via [phpMyAdmin](#open-tine20-in-browser) in order to connect with a websocket client to the tine Broadcasthub websocket server:

    INSERT INTO tine20_auth_token (id, auth_token, account_id, valid_until, channels) VALUES ('longlongid', 'longlongtoken', (select id from tine20_accounts where login_name = "tine20admin"), ADDDATE(NOW(), INTERVAL 1 YEAR), '["broadcasthub"]');

## Development
Follow the setup instructions above. Make sure to link your local tine Broadcasthub repository into the docker setup. Prior to run `./console docker:up` copy `.pullup.json` to `pullup.json` and change the entry `broadcasthub` to `broadcasthub-dev`. This way a development container for the tine Broadcasthub is ran rather than the production container. The development container has the following features (see `compose/broadcasthub-dev.yml` for complete setup):

* The tine Broadcasthub code is mounted from localhost into the container
* DEBUG is set to full debug output. This output is displayed along with all other logs when `./console docker:up` is used to pullup the `tine20/docker` setup
* Node is executed by `nodemon` within the container. `nodemon` automatically restarts `node` in the container on file changes in the local tine Broadcasthub repository. A file change can also be simulated with `touch app.js` on localhost

Adapt the websocket URL in `broadcasthub/dev/client.js` to match the URL of the tine Broadcasthub in the docker setup, i.e. change the port in `ws://localhost:8080` to whatever port the tine Broadcasthub is exposed to in the docker setup (see `compose/broadcasthub-dev.yml`).

Now you can start the development websocket client: `node broadcasthub/dev/client.js` and check if broadcast messages are received.

In order to trigger a websocket broadcast message, either log into the Redis CLI of the `tine20/docker` setup using something like `docker exec -it cache redis-cli` and execute something like `publish broadcasthub "A broadcast message!"`. Or log into the tine frontend, open the file manager and upload a file. Running `dev/trigger.js` does not work here because the `tine20/docker` Redis service is not exposed to the localhost and only available from within the `docker-compose` environment.

NOTE (2021-09-29): The websocket client in the tine client and the markup of changed files in file manager do not exist yet.


# Clear tine Cache

    docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php tine20.php --method=Tinebase.clearCache --username test --password test"
    
# TODO: Add phing invocations

# Restart webpack-dev-server

im tine container:

    ps aux | grep webpack
    kill [PID]

# Use ramdisk for SQL Storage

davor muss der alte db container gelöscht werden, sonst greift das mount nicht:

    docker rm db

ramdisk erzeugen:

    sudo mkdir /mnt/ramdisk
    sudo mount -t tmpfs -o size=512m tmpfs /mnt/ramdisk
    
wenn man mag, kann das mount in die /etc/fstab geschoben werden.
    
    tmpfs   /mnt/ramdisk tmpfs   nosuid,size=512M   0 0

docker-compose:

    # start docker with ramdisk & webpack
    php scripts/docker.php webpack ramdisk

achtung: man verliert natürlich seine db nach dem reboot!

achtung 2: man darf sonst nichts in die ramdisk legen, sonst meckert mysql/maria

# Sentry

you need to add sentry to your /etc/hosts file (because of CSRF):

    127.0.0.1       localhost sentry

First boot:

    ./console docker:up sentry [...]
    docker exec -it sentry bash
    ./entrypoint.sh sentry upgrade

# Use MySQL Instead of MariaDB

add "mysql" to your pullup.json!

# https
(From now ) Only https on port 443 is supported for services integrated in tine. Like tine itself (tine.local.tine-dev.de), broadcasthub (broadcasthub.local.tine-dev.de) or onlyoffice (onlyoffice.local.tine-dev.de). Backend only services like documentpreview and utility applications like phpmyadmin (localhost:4002
) still use http without domain nam

Update: Letsencrypt now works for every one. It is recommended. See [Configure letsencrypt certificate](#Configure-letsencrypt-certificate)

Certificates are set up automatically. There are several options that are tried in this order:
1. Custom certificates: If there ist a certificate in configs/traefik/privatekey.pem and configs/traefik/fullchain.pem, it will be used. See Generate self-signed certificates
2. letsencrypt certificate: real certificate signed by letsencrypt. See Configure letsencrypt certificate

## Generate self-signed certificates
It is easist to use a wildcard certificate for *.local.tine-dev.de. You can generate one with ./console docker:generateCert.

The resulting ca, certificate and private key will be placed in configs/traefik/ca.pem, configs/traefik/fullchain.pem or configs/traefik/privatekey.pem.

You may import your self-signed certificate into your browser. Be aware of the security implications.
1. (firefox) goto Settings -> search("Certificates")  -> Certificates -> View Certificates… or (chrome) goto chrome://settings/certificates  
3. press Authorities -> Import
4. select `/<path to docker setup>/configs/traefik/ca.pem`
5. check `Trust this CA to identify websites.` or `Trust this certificate for identifying websites`
6. press OK

## Configure letsencrypt certificate
Our private key for *.local.tine-dev.de is included in this repo. It is encrypted with the password "well-known". This should only hide it from
automatic security scanners. (Who will otherwise spam as that we leaked a key.)

### Setup

0. if a custom cert was used before, remove it `rm configs/traefik/privkey.pem`
1. install openssl
2. run `./console docker:up` for the changes to take effect

### Cert is expired
The certificate is distributed in our git repo. If its outdate, try pulling. After pulling a `./console docker:up` is needed to load the new private key.

If there is no new certificate available in git. It is in the file `configs/traefik/letsencrypt.privkey.sops.pem`, ask a devops to update the certificat. The certe needs to be generated manualy. 

Updateing the certificate requries updateing the dns. This can be done using nsupdate, but requires a key.
1. `sudo certbot certonly --manual --preferred-challenges=dns -d '*.local.tine-dev.de'`
2. `echo -e 'server dns0.metaways.net\nupdate add _acme-challenge.local.tine-dev.de. 60 txt oNs2fcFzTYm47o-ltnWRyi0VR8EgTG5oht1MBtbiiq0\nsend' | nsupdate -k ~/.mwclouddns`
3. press enter (to contine requesting a certificate) and wait for cert to be acquired
4. `sudo cat /etc/letsencrypt/live/local.tine-dev.de/fullchain.pem > configs/traefik/letsencrypt.fullchain.pem`
5. `sudo cat /etc/letsencrypt/live/local.tine-dev.de/privkey.pem | openssl enc -aes-256-cbc -a -e -salt -pbkdf2 -pass pass:well-known > configs/traefik/letsencrypt.privkey.pem.aes`
6. `echo -e 'server dns0.metaways.net\nupdate delete _acme-challenge.local.tine-dev.de. 60 txt oNs2fcFzTYm47o-ltnWRyi0VR8EgTG5oht1MBtbiiq0\nsend' | nsupdate -k ~/.mwclouddns`

### Adding more service
* take a look at the other service. (broadcasthub is a good example)
* the service should use there own domain -> <service-name>.local.tine-dev.de
* we use traefik and traefik labeles to configure our reverse proxy
    ```
        - traefik.enable=true
        - traefik.http.routers.broadcasthub.rule=Host(`broadcasthub.local.tine-dev.de`)
        - traefik.http.routers.broadcasthub.entrypoints=websecure
        - traefik.http.routers.broadcasthub.tls=true
        - traefik.http.services.broadcasthub.loadbalancer.server.port=80
    ```
* if a service needs to be accessable under its domain, you can use links. Example: make tine accessable to the broadcasthub throug the revers proxy as `tine.local.tine-dev.de`. Docker will add an /etc/hosts `tine.local.tine-dev.de <traefik internal ip>` in the broadcasthub continer:
    ```
    # this is a override file to add a broadcasthub container
    services:
    broadcasthub-service: # technical debt: should be simply named broadcasthub
        ...
        links:
        - traefik:tine.local.tine-dev.de
        ...
    ```
* routing based on path (webpack):
    ```
    ...
        labels:
            - traefik.http.routers.webpack.rule=Host(`tine.local.tine-dev.de`) && (PathPrefix(`/webpack-dev-server`) || PathPrefix(`/sockjs-node`) || PathRegexp(`\.js$`))
    ```
# Activate tine Cronjob

~~~shell
$ docker exec --user root -it tine20-web-1 sh
$ cat > /etc/crontabs/tine20
*/1    *   *   *   *   tine20.php --method Tinebase.triggerAsyncEvents
~~~

# Mailcatcher
Our mail setup (or more precisely postfix) forwards all non-local mails to a mailcatcher. Mailcatcher is reachable under [https://mailcatcher.local.tine-dev.de](https://mailcatcher.local.tine-dev.de).


Note: Do not click on quit in mailcatcher ui, it stops the container!

# Build Util Docker images
Util docker images for docker dev and tine ci are build from this repo.

There image specification should life in `./dockerfiles/<image name>`

The images are build by the ci. To trigger a build create a tag and push it. Resulting images will be pushed to ghrc.io (github container registry) and our gitlab container registry.

The gitlab container registry dose not support multi architecture image. Images from there should only be used by the ci.

### git tag schema for images
This is our git tag schema for docker images. It needs to be followed other wise the ci will not work.
`<image name>-image-<app version>[-0mw<release counter>][-<extra eg. rc>]`

image name (normaly the same `./dockerfiles/<image name>`, see .gitlab-ci.yml). eg.:
* docker
* ldap
* node
* node-element
* dovecot
* mailstackcontrol
* postfix
* opencode
* puppeteer (app version = node version; the image of the e2e tests, also used by the tine CI)
* devctl (own software like mailstack: semantic version, no app_version file, no `-mw`)
* claude-code (app version = Claude Code version)

App version is the version of the base image / software. It is specified in `./dockerfiles/<image name>/.meta/app_version`. The `tag app_version` and `app_version file` muss match otherwise the ci will fail!
Updating the app version: Update app version file and create a new tag.

Mailstack is an exception exception. It dose not has app_version files. Its app version is a semantic version of the mailstack container image. It should be increased by semantic version standart.
Mailstack container tags do not have an `-mw`.

mw version: Images we modify should have an mw version postfix `-0mw<release counter>`.
The Release counter will be reset for each app version. It should start with
* 0 if our modification was not by the update
* 1 if our modification was changed in any way

## Image not published yet

Compose files reference the images as published on ghcr. Until the CI built an image (tag pushed, see above), build
it locally with exactly that name and tag - compose only pulls images that don't exist locally, otherwise
`docker:up` fails with `denied`:

~~~
# e.g. image ghcr.io/tine-groupware/tine-dev/puppeteer:22-mw1 from dockerfiles/puppeteer
docker build -t ghcr.io/tine-groupware/tine-dev/puppeteer:22-mw1 \
  --build-arg APP_VERSION=$(cat dockerfiles/puppeteer/.meta/app_version) dockerfiles/puppeteer
# images without .meta/app_version (e.g. devctl): leave out --build-arg
~~~

## New image repository (first time image push)

When a new image is added, we need to create a new repository on ghcr.io by manually building and pushing the image:

Example with opencode image :
~~~
docker login ghcr.io -u <username> -p <token> # token needs packages:write scope
cd dockerfiles/opencode
docker build -t opencode:1.17.7-mw1 . --build-arg APP_VERSION=$(cat .meta/app_version)
docker tag opencode:1.17.7-mw1 ghcr.io/tine-groupware/tine-dev/opencode:1.17.7-mw1       
docker push ghcr.io/tine-groupware/tine-dev/opencode:1.17.7-mw1
~~~

The packages need to be added to the github repository and made public (inherit repo access):
https://github.com/orgs/tine-groupware/packages 

# AI coding clients

AI coding clients run as containers. `compose/aicoding.yml` holds the shared parts, and every
client has its own module, so only the client you actually use is started:

| module                  | contents                                              |
|-------------------------|-------------------------------------------------------|
| `aicoding`              | `devctl`, `gitlab-mcp`, network `devctl-net`          |
| `aicoding-claude-code`  | Claude Code container (`claude-code`)                 |
| `aicoding-opencode`     | opencode server (`opencode`)                          |

~~~
"composeFiles": [ ..., "aicoding", "aicoding-claude-code" ]
~~~

`./console` refuses to start if an `aicoding-*` client module is listed without `aicoding`.

Clients get **no docker access**. `devctl` (`dockerfiles/devctl`) has the docker socket and offers a fixed
set of operations as MCP server `http://devctl:3003/mcp` (`Authorization: Bearer ${MCP_AUTH_TOKEN}`, only
reachable in the internal network `devctl-net`):

| tool              | does                                                                                 |
|-------------------|--------------------------------------------------------------------------------------|
| `change_branch`   | `./console src:changeBranch` for the branch checked out (the client does the checkout) |
| `up`              | `./console docker:up -d`                                                             |
| `restart_webpack` | `./console docker:webpackrestart`                                                    |
| `exec`            | `sh -c <command>` in `web` or `e2etests` (tests, tine cli, ...)                      |
| `tine_console`    | `./console tine:<install\|demodata\|clearcache\|test\|...> [args]`                    |
| `ps`, `logs`      | container status, log tail of a service                                              |
| `push`            | push the checked out branch to `origin`, only `pu/<DEVCTL_DEVELOPER>/...`, optionally force with lease |
| `fetch`           | fetch a branch (any, e.g. of a merge request to review or build on) or the whole remote |

devctl mounts tine-dev and the checkout at their host paths and runs `./console` as on the host
(`TINE_DEV_DIR`, `TINE_DEV_USER`, `TINE_DEV_ARM64`, `HOMEDIR` are passed by `./console`); it never
recreates itself and the AI clients (`DEVCTL_SKIP_SERVICES`, their env may only be set in your shell). It never checks out - the client does
the checkout in its container; fetch and push run via the tools of the same name.

**Settings and tokens** (`.env` of tine-dev):

~~~
# shared secret between the AI clients and their MCP servers (gitlab-mcp, devctl), any random string
MCP_AUTH_TOKEN=...            # e.g. openssl rand -hex 32

# gitlab-mcp: read MRs, pipelines and jobs, answer and resolve review threads, open MRs
GITLAB_API_URL=https://gitlab.metaways.net/api/v4
GITLAB_ACCESS_TOKEN=...

# devctl tools push and fetch (optional)
GITLAB_PUSH_TOKEN=...
DEVCTL_DEVELOPER=cw           # your short name: pushes only pu/cw/...
# DEVCTL_GIT_REMOTE=origin                             (default)
# DEVCTL_GIT_URL_PREFIX=https://gitlab.metaways.net/   (default, the push url of the remote must start with it)

# Claude Code: an API key, or log in interactively in the container
# ANTHROPIC_API_KEY=...
~~~

Create both gitlab tokens as **personal access tokens** in gitlab (avatar → Preferences → Access tokens),
preferably *fine-grained* tokens limited to the projects you work on (e.g. `tine20/tine20`), with an expiry date.
Keep them separate: the AI client uses `GITLAB_ACCESS_TOKEN` through gitlab-mcp for every request,
`GITLAB_PUSH_TOKEN` is used by devctl for git fetch and push only.

| token | permissions (fine-grained) | not |
|-------|----------------------------|-----|
| `GITLAB_ACCESS_TOKEN` | Repository → Merge Request: Create, Read, Update (read MRs, notes, threads, resolve threads, new MRs); CI/CD → Pipeline: Read (pipelines, their jobs), CI/CD → Job: Read (job logs, failing CI of an MR), CI/CD → Job artifact: Read (e.g. test reports, screenshots) | repository write, delete, admin |
| `GITLAB_PUSH_TOKEN` | Repository: Read and Write (git fetch, git push), Merge Request: Read | everything else |

Limit the tokens to all projects you work on with the client (e.g. `tine20/tine20` and `tine20/docker`).
The permission names of fine-grained tokens may differ between gitlab versions; gitlab names a missing permission
in its 403 answer (e.g. `insufficient_granular_scope` ... `[Job: Read]` when reading a job log without it). Without fine-grained tokens: `GITLAB_PUSH_TOKEN` needs the scopes `read_repository` and `write_repository`, gitlab-mcp
`api` (which allows much more - prefer fine-grained tokens). After changing a token: `docker compose up -d
gitlab-mcp` (gitlab-mcp is not recreated by devctl) resp. `./console docker:up -d` (devctl).

**Push:** the token stays in devctl, the client never sees it (git gets it via askpass from the environment).
devctl pushes the checked out branch `pu/<DEVCTL_DEVELOPER>/...` to `DEVCTL_GIT_REMOTE`, only via https and only if
its push url starts with `DEVCTL_GIT_URL_PREFIX` and contains no credentials (the client can't change the url,
`.git/config` is read-only for it); hooks and fsmonitor are disabled, force push only with lease. Use an https
remote for that, ssh remotes are not supported. Merge requests are opened via gitlab-mcp. Note: anything pushed to
`pu/...` can run in merge request pipelines, keep protected CI variables protected.

**Skills for the clients:** `configs/aicoding/skills` holds skills for working in this setup, e.g.
`mergerequests` (review MRs, work on them, answer review threads with gitlab-mcp and devctl). They are mounted
read-only into the clients (Claude Code: `~/.claude/skills`), as the clients don't see tine-dev. Skills about the
tine code itself belong into the tine repo (`.agents/skills`), skills for working on tine-dev into
`.agents/skills` here.

**Git protection:** clients get a writable checkout incl. `.git` (commits), but the parts of `.git`
that make git run code — `config` (fsmonitor, filters, aliases, ...), `hooks`, `modules`, `worktrees`
and `commondir` (git reads config and hooks from the dir it names) — are mounted read-only, and every
path leading to them is a mount point, so it can't be renamed and replaced. `./console` creates
`.git/commondir` containing `.` (the git dir itself) for that. Clients can't run git commands that
write the config (`push -u`, `branch --set-upstream-to`, `remote add`, `submodule init`); run them on
the host. `./console` runs git with fsmonitor and hooks disabled. Remaining risk: nested repos —
git (and shell prompts showing git status) in a sub dir uses the nearest `.git`, e.g. a planted
`tine20/Foo/.git` or the composer source installs in `tine20/vendor/*/.git`; check `git rev-parse
--show-toplevel` before running git in sub dirs, and don't register vendor repos in your IDE.

**Adding a new client:** create `compose/aicoding-<client>.yml` with the client service and add
`devctl-net` to its `networks` and the devctl MCP server to its config (plus `internal_network`/`external_network` as needed).
Nothing in `aicoding.yml` needs to change.

**Migrating from the old `opencode` module:** replace `"opencode"` in `pullup.json` with
`"aicoding", "aicoding-opencode"` (and/or `"aicoding-claude-code"`), then run
`docker compose up -d --remove-orphans` once (or `docker rm -f` the containers you dropped)
and `./console docker:up`.

## opencode

see https://opencode.ai/docs/

opencode runs in server mode (`opencode serve`, port 4096), exposed via traefik.

1. Add your LLM provider credentials: `METAWAYS_AI_BASE_URL` + `METAWAYS_AI_API_KEY` in `.env`
   (or export them in your shell before `./console docker:up`).
   Alternatively adjust `configs/opencode/config/opencode.json` for your own LLM provider
   (do not commit API keys to the repo).
2. Add `"aicoding", "aicoding-opencode"` to `composeFiles` in `pullup.json` and run `./console docker:up`.
3. Open https://opencode.local.tine-dev.de/
   - OR run in shell:
   ~~~
   docker exec -it opencode bash
   opencode
   ~~~
4. [browser] add project "tine20" via "+" button and adding the dir "/tine20"
   (your Tine checkout, writable; the tine-dev repo is mounted read-only at `/tine-dev`).
5. The repo bundles a skill with environment context:
   `.agents/skills/tine-dev-env/SKILL.md` (in the container: `/tine-dev/.agents/...`).

You can also run opencode directly on your host in this repo — `AGENTS.md` and the skill above
provide the environment context.

### Show available models of provider

~~~
curl -s $METAWAYS_AI_BASE_URL/models -H "Authorization: Bearer $METAWAYS_AI_API_KEY" | jq
~~~

### UFW rule

You might need to add a UFW rule to allow outgoing connections to the AI-API server:

    sudo ufw route allow from any to $METAWAYS_AI_API_IPADDRESS

## claude-code
    docker exec -it claude-code claude --chrome

Paths in the container:

- `/workspace/tine20` — your Tine checkout (writable, work and commit here; git config, hooks etc. read-only)
- no `/workspace/tine-dev` and no docker: the stack is controlled via the devctl MCP server (see above)
