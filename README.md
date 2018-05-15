tine20-docker
----

# install and setup docker

TODO: add osx setup

## install docker and docker-compose

    sudo apt install docker docker-compose

## add yourself to the docker group (to work without sudo)

_relogin required!_

    sudo usermod -a -G docker <your userid>

# init repos

## clone this repo

    git clone git@gitlab.metaways.net:r.jerger/tine20-docker.git

## link your tine20 repo

    cd tine20-docker
    ln -s /path/to/tine/repo tine20

# build image

## npm install

    cd tine20-docker/tine20/tine20/Tinebase/js
    npm install

## composer install

    cd tine20-docker/tine20/tine20/
    composer install --ignore-platform-reqs

## docker build (only needed if you do not want to use the ready image)

note: obsolete when we have a docker registry

    docker build . -t tine20

## build for other versions of tine20

note: does not work yet as we only have php 7.2 (which does not work with 2017.11)

for example 2017.11 (which has a different webpack start command):

    git checkout 2017.11
    docker build . -t tine20:2017.11

# run tine20 dockerized

TODO: add docker registry stuff when we have it

## docker-compose up

    docker-compose up

## show containers

    docker ps

## run bash in container

    docker exec -it tine20 /bin/bash

## open tine20 in browser

[localhost:4000](http://localhost:4000/) - nginx

[localhost:4001](http://localhost:4001/) - webpack served

[localhost:4001/setup.php](http://localhost:4001/setup.php) - webpack served setup

## install tine

    docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php setup.php --install --config /tine/customers/localhost/config.inc.php \
      -- adminLoginName=test adminPassword=test acceptedTermsVersion=1000"

## uninstall tine

    docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php setup.php --uninstall"

## update tine

    docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php setup.php --update"

## create demodata tine

    docker exec --user nginx tine20 sh -c "cd /tine/tine20/ && php tine20.php  --method Tinebase.createAllDemoData  --username=test --password=test"

## run unittests

TODO

# other useful functions

## containers

    docker ps
    docker ps -a            # shows all (not running only) containers
    docker rm <container id/name>

## images

    docker image ls
    docker image ls -a      # shows all containers, as well as deps
    docker image rm <image id/name>
