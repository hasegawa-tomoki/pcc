ifdef FILE
TEST_SRCS=test/$(FILE)
else
TEST_SRCS=$(wildcard test/*.c)
endif
TESTS=$(TEST_SRCS:.c=.out)

test/%.out: test/%.c
	php ../pcc.php -Iinclude -Itest -c -o test/$*.o test/$*.c
	$(CC) -Wa,--noexecstack -o $@ test/$*.o -xc common

test: $(TESTS)
	for i in $^; do echo $$i; ./$$i || exit 1; echo; done
	./driver.sh

clean:
	rm -rf tmp* $(TESTS) test/*.s
	find * -type f '(' -name '*~' -o -name '*.o' ')' -exec rm {} ';'
.PHONY: test clean
