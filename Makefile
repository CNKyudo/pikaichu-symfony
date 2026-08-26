
DOCKER_VERSION := $(shell docker version --format '{{.Server.Version}}')
DOCKER_COMPOSE_CMD := $(shell [ "$(DOCKER_VERSION)" \< "20.10.6" ] && echo docker-compose || echo docker compose)
PHP := $(DOCKER_COMPOSE_CMD) exec php-fpm

## ─── Environnement ────────────────────────────────────────────────────────────

up:
	$(DOCKER_COMPOSE_CMD) up -d --build
	$(PHP) composer install
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction
	@echo ""
	@echo "  Application : http://localhost:8000"
	@echo "  Adminer     : http://localhost:8080"

down:
	$(DOCKER_COMPOSE_CMD) down

restart: down up

php:
	$(DOCKER_COMPOSE_CMD) exec -u 1000 php-fpm bash

logs:
	$(DOCKER_COMPOSE_CMD) logs -f php-fpm php-nginx

## ─── Base de données ──────────────────────────────────────────────────────────

diff:
	$(PHP) php bin/console doctrine:migrations:diff

migrate:
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	$(PHP) php bin/console doctrine:fixtures:load --no-interaction

reset-database:
	$(PHP) php bin/console doctrine:database:drop --force --if-exists
	$(PHP) php bin/console doctrine:database:create
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction
	$(PHP) php bin/console doctrine:fixtures:load --no-interaction

## ─── Tests ────────────────────────────────────────────────────────────────────

test-functional:
	@echo "Cleaning and preparing test database..."
	$(PHP) php bin/console doctrine:database:drop --env=test --force --if-exists
	$(PHP) php bin/console doctrine:database:create --env=test
	$(PHP) php bin/console doctrine:migrations:migrate --env=test --no-interaction
	@echo "Running functional tests..."
	$(PHP) php bin/phpunit tests/Functional/ --testdox

test-unit:
	$(PHP) php bin/phpunit tests/Unit/ --testdox

test: test-unit test-functional

## ─── Qualité de code ──────────────────────────────────────────────────────────

fix: rector csfixer phpstan

rector:
	@echo "Running rector..."
	$(PHP) vendor/bin/rector process --config=tools/rector.php

csfixer:
	@echo "Running php-cs-fixer..."
	$(PHP) vendor/bin/php-cs-fixer fix --config=tools/.php-cs-fixer.dist.php --allow-risky=yes

phpstan:
	@echo "Running phpstan analyse (configuration tools/phpstan.dist.neon)..."
	$(PHP) vendor/bin/phpstan analyse --configuration=tools/phpstan.dist.neon

.PHONY: up down restart php logs diff migrate fixtures reset-database \
        test test-unit test-functional fix rector csfixer phpstan
