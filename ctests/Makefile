ifdef FILE
TEST_SRCS=TestCase/$(FILE)
else
TEST_SRCS=$(wildcard TestCase/*.c)
endif
#TEST_SRCS=TestCase/union.c
TESTS=$(TEST_SRCS:.c=.out)

TestCase/%.out: TestCase/%.c
	$(CC) -o- -E -P -C TestCase/$*.c | php ../pcc.php -o TestCase/$*.s -
	$(CC) -Wa,--noexecstack -o $@ TestCase/$*.s -xc common

test: $(TESTS)
	#./driver.sh
	for i in $^; do echo $$i; ./$$i || exit 1; echo; done

clean:
	rm -rf tmp* $(TESTS) TestCase/*.s
	find * -type f '(' -name '*~' -o -name '*.o' ')' -exec rm {} ';'
.PHONY: test clean
