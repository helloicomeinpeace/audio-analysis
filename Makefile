.PHONY: up down build migrate test shell logs queue restart fresh composer

up:
	docker compose up -d --build

down:
	docker compose down

build:
	docker compose build

migrate:
	docker compose exec app php artisan migrate

test:
	docker compose exec app php artisan test

shell:
	docker compose exec app sh

logs:
	docker compose logs -f

queue:
	docker compose logs -f queue

restart:
	docker compose restart

fresh:
	docker compose exec app php artisan migrate:fresh

composer:
	docker compose exec app composer install

docs:
	docker compose exec app php artisan l5-swagger:generate
