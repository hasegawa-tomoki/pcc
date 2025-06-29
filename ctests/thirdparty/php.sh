#!/bin/bash
repo='https://github.com/php/php-src.git'
. ctests/thirdparty/common
git reset --hard 8d116a4ba10703c54d947d95e152d25d75d45aa0

CC=$pcc CFLAGS=-D_GNU_SOURCE ./buildconf
CC=$pcc CFLAGS=-D_GNU_SOURCE ./configure
$make
$make test
