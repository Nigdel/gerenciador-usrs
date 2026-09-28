# Project Context

## Architecture

This is a Laravel application running entirely inside Docker.

The host/WSL environment is only used to manage the project and Docker.

PHP, Composer, Artisan and the Laravel runtime are inside the Docker container.

## Docker

The main application container is:

    app

The application runs at:

    /var/www/html

PHP version is determined by the Docker image.

## Important Rules

- NEVER run `php` directly from the host.
- NEVER run `composer` directly from the host.
- NEVER run `php artisan` directly from the host.
- NEVER assume PHP or Composer are installed on the host.
- Use Docker Compose to execute PHP/Laravel commands.

Examples:

    docker compose exec app php artisan migrate

    docker compose exec app php artisan test

    docker compose exec app composer install

    docker compose exec app php artisan route:list

## Before modifying Docker configuration

Inspect the existing:

- docker-compose.yml
- Dockerfile
- container configuration

Do not recreate the Docker environment unless necessary.

## Development workflow

1. Inspect the existing project structure.
2. Inspect relevant configuration.
3. Determine how the application currently works.
4. Make the smallest appropriate change.
5. Run the relevant tests inside the Docker container.
6. Report what changed and what was verified.

## Important

Do not invent infrastructure that is not present in the project.

Prefer the existing Docker/Compose configuration over creating alternative local environments.

## Command Execution

When a command involves PHP/Laravel:

WRONG:

    php artisan test
    composer install
    php -v

CORRECT:

    docker compose exec app php artisan test
    docker compose exec app composer install
    docker compose exec app php -v
