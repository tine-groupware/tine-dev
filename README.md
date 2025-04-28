tine-dev (docker dev setup)
----

[[_TOC_]]

# Pullup / console

## Quickstart

Prerequisites: git, docker, php, composer, npm and your user is in the docker group. If not see "Install and Setup Docker"

Note Mac User: install composer and npm via homebrew
`brew install composer`
`brew install node`

1. clone this git and open it `git clone https://github.com/tine-groupware/tine-dev.git tine-dev` and `cd tine-dev`
2. install symfony/console dependencies `composer install`
3. start tine20-docker setup `./console docker:up`, if you have not done this, install 4 to 6 answer y to clone repos
4. initialize icon-set submodule: `cd tine20 && git submodule init && git submodule update && cd ..`
5. install tine `./console tine:install`
6. visit https://tine.local.tine-dev.de, login as tine20admin pw: tine20admin

Note:
In case tine stops working after a branch switch or computer restart run "./console tine:reinstall" WITHOUT stopping tine before that.

## Optional

- add `eval $(~/path/to//docker/console _completion --generate-hook)` to your shell's profile (e.g. ~/.bashrc or ~/.zshrc) to enable autocomplete
- link your tine20 source `ln -s /path/to/tine/repo tine20` or just wait for console to clone it for you
- link docservice source `ln -s /path/to/docservice/repo docservice` or just wait for console to clone it for you
- link broadcasthub source `ln -s /path/to/tine20-broadcsthub/repo broadcasthub` or just wait for console to clone it for you
- install docservice dependencies, if console has cloned it you don't need to do anything: `cd docservice && composer install --ignore-platform-reqs`
- install broadcasthub dependencies, if console has cloned it you don't need to do anything: `cd broadcasthub && npm install`

## Console Commands
run `./console` in tine-dev directory to see available Commands

## pullup.json

to override default settings copy .pullup.json to pullup.json

+ composeFiles: dockerset up "modules". Take a look at ./compose (Do not include the ones with -build.yml)
+ build: if true images will be build locally
+ more: any wishes? > issue tracker 

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
    
<docker-bridge-interface> is something like "br-3ff4120010e5" which has ip:172.118.0.1 (visible with ifconfig)


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

Certificates are set up automatically. There are several options that are tried in this order:
1. Custom certificates: If there ist a certificate in configs/traefik/privatekey.pem and configs/traefik/fullchain.pem, it will be used. See Generate self-signed certificates
2. (Metaways only): letsencrypt certificate: real certificate signed by letsencrypt usefull for e.g. webauth testing. See Configure letsencrypt certificate
3. Generated cert: if no certificate is found, our web server will generate a self-signed certificate for you

## Generate self-signed certificates
It is easist to use a wildcard certificate for *.local.tine-dev.de. You can generate one with ./console docker:generateCert.

The resulting certificate and private key will be placed in configs/traefik/fullchain.pem or configs/traefik/privatekey.pem.

You may import your self-signed certificate into your browser. Be aware of the security implications.

## Configure letsencrypt certificate
Note: You can also obtain a letsencrypt certificate by other means and use it as a custom certificate.

Our letsencrypt private key is part of this repo. It will be automatically decrypted and used if properly configured. Therefore, you will need to set up sops with age and have your age key added.

* SOPS](https://github.com/getsops/sops) is a secret management tool that can be used to encrypt and decrypt secrets (files), and manage multiple keys per secret. It supports a wide range of keys and key services, such as aws kms, vault, gpg, or age. 
* age](https://github.com/FiloSottile/age) is a simple asymmetric encryption tool.

### Setup
1. download and install the sops binary (there is no ubuntu package). download and instructions. (move to /bin): https://github.com/getsops/sops/releases.
2. install age `sudo apt install age`
3. acquire age key. You can either use your own age key (preferred see: Generating and adding a new age key) or use a shared age key. It can be found in our tine dev password store as `age - seshared`.
5. add age key to `~/.config/sops/age/keys.txt`. This file may contain multiple keys. It may look like this:
```
personal test key
# created: 2025-02-20T11:05:48+01:00
# public key: age14nlgzt9mk6g6vrxj29y5vm4zz0els2y8qlcl7cdmfdxuwvgl2e3scupx0k
AGE-SECRET-KEY-1UZH4UNCDX8XV87RZ8JA7FW2LMFP0MV4G0L0DZZ6W8433RLX0WPKQVV936Y

# another example key
# created: 2025-02-20T11:10:08+01:00
# public key: age1qx8l72pa56u5ddjj60quvzhsp3wmkx60z5tu43h55n7rkhxzvvgsn3ce09
AGE-SECRET-KEY-1UN8LUTH3FE74GKJ0Z749LZEUW9Q0N27AGEEKDKYGHHE95R3AUMPQYCUQVS
```

### Generating and adding a new age key
1. a new age key can be generated with `age-keygen`. (It still needs to be added to `~/.config/sops/age/keys.txt`)
2. add the public key to `.sops.yaml` in this repo. It needs to be added under `keys` and all (required) `key_groups`
3. git commit and push to master
4. ask someone to run `find -- "$(git rev-parse --show-toplevel)" -type d -name .git -prune -o -type f \( -name '*.sops.*' \! -name .sops.yaml \) -exec sops updatekeys -- {} \;` to enrolle the new key. (This obviously requires an already enrolled key)
5. git commit and push all update secrets

### Cert is expired
When the cert is expiered, it needs to be update. This requires access to dns. Ask a dev ops.
1. `sudo certbot certonly --manual --preferred-challenges=dns -d '*.local.tine-dev.de'`
2. `echo -e 'server dns0.metaways.net\nupdate add _acme-challenge.local.tine-dev.de. 60 txt oNs2fcFzTYm47o-ltnWRyi0VR8EgTG5oht1MBtbiiq0\nsend' | nsupdate -k ~/.mwclouddns\n`
3. `sudo cat /etc/letsencrypt/live/local.tine-dev.de/fullchain.pem > configs/traefik/letsencrypt.fullchain.pem`
4. copy content manually `sudo cat /etc/letsencrypt/live/local.tine-dev.de/fullchain.pem ` to `sops configs/traefik/letsencrypt.privkey.sops.pem`

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