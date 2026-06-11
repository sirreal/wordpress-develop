#!/bin/sh
set -eu

PHP_CONFIG_BIN="${PHP_CONFIG:-php-config}"

cargo build --release
phpize
./configure --enable-wp-html-api-rust --with-php-config="${PHP_CONFIG_BIN}"
make
