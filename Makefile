all: up
.PHONY: all

up:
	docker compose up -d --remove-orphans
.PHONY: up

build:
	docker compose up -d --build
.PHONY: build

down:
	docker compose down
.PHONY: down

reload:
	docker compose down
	docker compose up -d --remove-orphans
.PHONY: reload

test:
	docker compose run --rm php ./test.sh
.PHONY: test

clean:
	docker compose down
.PHONY: clean
