ifdef FILE
TEST_SRCS=TestCase/$(FILE)
else
TEST_SRCS=$(wildcard TestCase/*.c)
endif
TESTS=$(TEST_SRCS:.c=.out)

TestCase/%.out: TestCase/%.c
	php ../pcc.php -c -o TestCase/$*.o TestCase/$*.c
	$(CC) -Wa,--noexecstack -o $@ TestCase/$*.o -xc common

test: $(TESTS)
	for i in $^; do echo $$i; ./$$i || exit 1; echo; done
	./driver.sh

clean:
	rm -rf tmp* $(TESTS) TestCase/*.s
	find * -type f '(' -name '*~' -o -name '*.o' ')' -exec rm {} ';'
.PHONY: test clean
