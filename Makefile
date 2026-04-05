DOCKER_IMAGE ?= dorpmaster/dbal-amphp-postgres-dev:latest
DOCKER_BUILD ?= docker build -t $(DOCKER_IMAGE) .
COMPOSE_FILE ?= compose.yml
DOCKER_COMPOSE ?= docker compose -f $(COMPOSE_FILE)
DBAL_AMP_PG_HOST ?= host.docker.internal
DBAL_AMP_PG_PORT ?= 55432
DBAL_AMP_PG_DBNAME ?= dbal_amp_pg_test
DBAL_AMP_PG_USER ?= dbal_amp_pg_user
DBAL_AMP_PG_PASSWORD ?= dbal_amp_pg_password
DBAL_AMP_PG_APPLICATION_NAME ?= dbal-amphp-postgres-tests
DOCKER_RUN ?= docker run --rm \
	-v $(PWD):/app \
	-w /app \
	$(DOCKER_IMAGE)
DOCKER_RUN_INTERACTIVE ?= docker run --rm -it \
	-v $(PWD):/app \
	-w /app \
	$(DOCKER_IMAGE)

.PHONY: build shell php composer cs stan test integration-up integration-down test-integration

build:
	$(DOCKER_BUILD)

shell:
	$(DOCKER_RUN_INTERACTIVE) sh

php:
	$(DOCKER_RUN) php $(ARGS)

composer:
	$(DOCKER_RUN) composer $(ARGS)

cs:
	$(DOCKER_RUN) vendor/bin/phpcs --standard=phpcs.xml.dist

stan:
	$(DOCKER_RUN) vendor/bin/phpstan analyse -c phpstan.neon.dist

test:
	$(DOCKER_RUN) vendor/bin/phpunit --testsuite default

integration-up:
	DOCKER_IMAGE=$(DOCKER_IMAGE) \
	DBAL_AMP_PG_PORT=$(DBAL_AMP_PG_PORT) \
	DBAL_AMP_PG_DBNAME=$(DBAL_AMP_PG_DBNAME) \
	DBAL_AMP_PG_USER=$(DBAL_AMP_PG_USER) \
	DBAL_AMP_PG_PASSWORD=$(DBAL_AMP_PG_PASSWORD) \
	DBAL_AMP_PG_APPLICATION_NAME=$(DBAL_AMP_PG_APPLICATION_NAME) \
	$(DOCKER_COMPOSE) up -d --wait postgres

integration-down:
	DOCKER_IMAGE=$(DOCKER_IMAGE) \
	DBAL_AMP_PG_PORT=$(DBAL_AMP_PG_PORT) \
	DBAL_AMP_PG_DBNAME=$(DBAL_AMP_PG_DBNAME) \
	DBAL_AMP_PG_USER=$(DBAL_AMP_PG_USER) \
	DBAL_AMP_PG_PASSWORD=$(DBAL_AMP_PG_PASSWORD) \
	DBAL_AMP_PG_APPLICATION_NAME=$(DBAL_AMP_PG_APPLICATION_NAME) \
	$(DOCKER_COMPOSE) down -v --remove-orphans

test-integration:
	DOCKER_IMAGE=$(DOCKER_IMAGE) \
	DBAL_AMP_PG_PORT=$(DBAL_AMP_PG_PORT) \
	DBAL_AMP_PG_DBNAME=$(DBAL_AMP_PG_DBNAME) \
	DBAL_AMP_PG_USER=$(DBAL_AMP_PG_USER) \
	DBAL_AMP_PG_PASSWORD=$(DBAL_AMP_PG_PASSWORD) \
	DBAL_AMP_PG_APPLICATION_NAME=$(DBAL_AMP_PG_APPLICATION_NAME) \
	$(DOCKER_COMPOSE) up --abort-on-container-exit --exit-code-from integration integration
