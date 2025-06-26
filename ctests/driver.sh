#!/bin/bash
pcc='php ../pcc.php'

tmp=`mktemp -d /tmp/pcc-test-XXXXXX`
trap 'rm -rf $tmp' INT TERM HUP EXIT
echo > $tmp/empty.c

check() {
    if [ $? -eq 0 ]; then
        echo "testing $1 ... passed"
    else
        echo "testing $1 ... failed"
        exit 1
    fi
}

# -o
rm -f $tmp/out
$pcc -c -o $tmp/out $tmp/empty.c
[ -f $tmp/out ]
check -o

# --help
$pcc --help 2>&1 | grep -q pcc
check --help

# -S
echo 'int main() {}' | $pcc -S -o - - | grep -q 'main:'
check -S

# Default output file
rm -f $tmp/out.o $tmp/out.s
echo 'int main() {}' > $tmp/out.c
(cd $tmp; php $OLDPWD/../pcc.php -c out.c)
[ -f $tmp/out.o ]
check 'default output file'

(cd $tmp; php $OLDPWD/../pcc.php -c -S out.c)
[ -f $tmp/out.s ]
check 'default output file'

# Multiple input files
rm -f $tmp/foo.o $tmp/bar.o
echo 'int x;' > $tmp/foo.c
echo 'int y;' > $tmp/bar.c
(cd $tmp; php $OLDPWD/../pcc.php -c $tmp/foo.c $tmp/bar.c)
[ -f $tmp/foo.o ] && [ -f $tmp/bar.o ]
check 'multiple input files'

rm -f $tmp/foo.s $tmp/bar.s
echo 'int x;' > $tmp/foo.c
echo 'int y;' > $tmp/bar.c
(cd $tmp; php $OLDPWD/../pcc.php -c -S $tmp/foo.c $tmp/bar.c)
[ -f $tmp/foo.s ] && [ -f $tmp/bar.s ]
check 'multiple input files'

# Run linker
rm -f $tmp/foo
echo 'int main() { return 0; }' | $pcc -o $tmp/foo -
$tmp/foo
check linker

rm -f $tmp/foo
echo 'int bar(); int main() { return bar(); }' > $tmp/foo.c
echo 'int bar() { return 42; }' > $tmp/bar.c
$pcc -o $tmp/foo $tmp/foo.c $tmp/bar.c
$tmp/foo
[ "$?" = 42 ]
check linker

# a.out
rm -f $tmp/a.out
echo 'int main() {}' > $tmp/foo.c
(cd $tmp; php $OLDPWD/../pcc.php foo.c)
[ -f $tmp/a.out ]
check a.out

# -E
echo foo > $tmp/out
echo "#include \"$tmp/out\"" | $pcc -E - | grep -q foo
check -E

echo foo > $tmp/out1
echo "#include \"$tmp/out1\"" | $pcc -E -o $tmp/out2 -
cat $tmp/out2 | grep -q foo
check '-E and -o'

# -I
mkdir $tmp/dir
echo foo > $tmp/dir/i-option-test
echo "#include \"i-option-test\"" | $pcc -I$tmp/dir -E - | grep -q foo
check -I

# -D
echo foo | $pcc -Dfoo -E - | grep -q 1
check -D

# -D
echo foo | $pcc -Dfoo=bar -E - | grep -q bar
check -D

# -U
echo foo | $pcc -Dfoo=bar -Ufoo -E - | grep -q foo
check -U

# BOM marker
printf '\xef\xbb\xbfxyz\n' | $pcc -E -o- - | grep -q 'xyz'
check 'BOM marker'

# -fcommon
echo 'int foo;' | $pcc -S -o- - | grep -q '\.comm foo'
check '-fcommon (default)'

echo 'int foo;' | $pcc -fcommon -S -o- - | grep -q '\.comm foo'
check '-fcommon'

# -fno-common
echo 'int foo;' | $pcc -fno-common -S -o- - | grep -q '^foo:'
check '-fno-common'

echo OK
