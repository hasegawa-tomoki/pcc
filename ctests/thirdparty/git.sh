#!/bin/bash
repo='https://github.com/git/git.git'
. ctests/thirdparty/common
git reset --hard 54e85e7af1ac9e9a92888060d6811ae767fea1bc

$make clean
$make V=1 CC=$pcc test