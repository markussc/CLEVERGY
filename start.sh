#!/bin/sh

# set script directory as working directory

cd "$(dirname "$(realpath "$0")")";

# build docker containers
docker-compose --file docker-compose.yml build

# start docker-compose
docker-compose --file docker-compose.yml up -d
