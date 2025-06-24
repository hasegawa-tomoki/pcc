ifdef FILE
TEST_SRCS=TestCase/$(FILE)
else
TEST_SRCS=$(wildcard TestCase/*.c)
endif
#TEST_SRCS=TestCase/union.c
TESTS=$(TEST_SRCS:.c=.out)

# Special rule for macro.c
TestCase/macro.out: TestCase/macro.c
	php ../pcc.php -c -o TestCase/macro.o TestCase/macro.c
	$(CC) -Wa,--noexecstack -o $@ TestCase/macro.o -xc common

TestCase/%.out: TestCase/%.c
	$(CC) -o- -E -P -C TestCase/$*.c | php ../pcc.php -c -o TestCase/$*.o -
	$(CC) -Wa,--noexecstack -o $@ TestCase/$*.o -xc common

test: $(TESTS)
	./driver.sh
	for i in $^; do echo $$i; ./$$i || exit 1; echo; done

clean:
	rm -rf tmp* $(TESTS) TestCase/*.s
	find * -type f '(' -name '*~' -o -name '*.o' ')' -exec rm {} ';'
.PHONY: test clean
