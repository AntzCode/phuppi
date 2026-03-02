#!/bin/bash

#
# build.sh
#
# Build script for creating the commonmark .phar package.
#
# @package Phuppi
# @author Anthony Gallon
# @copyright AntzCode Ltd
# @license GPLv3
# @link https://github.com/AntzCode/phuppi/
# @since 2.0.0
#

# build the commonmark .phar package

# clone the source files
git clone https://github.com/thephpleague/commonmark.git

# copy configuration files into project
cp stub.php commonmark
cp box.json commonmark

# install Composer dependencies using disposable Docker container
cd commonmark
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$PWD":/app \
    -e COMPOSER_CACHE_DIR=/tmp/cache \
    -w /app \
    composer:latest install

# build the .phar package
docker run --rm -v "$(pwd):/app" -w /app boxproject/box:latest compile

# report instructions to user
echo ""
echo "###################    INSTRUCTIONS   ###################"
echo "## copy src/commonmark/commonmark/commonmark.phar to src/commonmark/commonmark.phar and restart the server" 
echo "## remove src/commonmark/build/commonmark"
echo "#########################################################"

