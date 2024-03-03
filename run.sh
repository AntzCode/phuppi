#!/bin/bash
git submodule update --init
docker-compose --env-file=.env up -d

