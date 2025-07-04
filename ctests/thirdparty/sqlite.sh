#!/bin/bash
repo='https://github.com/sqlite/sqlite.git'
. ctests/thirdparty/common
git reset --hard 86f477edaa17767b39c7bae5b67cac8580f7a8c1

CC=$pcc CFLAGS=-D_GNU_SOURCE ./configure
sed -i 's/^wl=.*/wl=-Wl,/; s/^pic_flag=.*/pic_flag=-fPIC/' libtool
$make clean
$make
$make test