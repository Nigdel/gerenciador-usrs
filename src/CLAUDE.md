# Project Context

Laravel app ejecutándose íntegramente en Docker. Las reglas de este fichero son las
mismas del `CLAUDE.md` de la raíz del repositorio; aquí se repiten para que valgan
también al trabajar dentro de `src/`.

## Architecture

PHP, Composer, Artisan y el runtime de Laravel viven dentro del contenedor Docker.

El host/WSL solo se usa para gestionar el proyecto y Docker.

La aplicación corre en:

    /var/www/html

## Docker

El contenedor principal de la aplicación es:

    app

La versión de PHP la determina la imagen de Docker.

## Important Rules

- NEVER run `php` directly from the host.
- NEVER run `composer` directly from the host.
- NEVER run `php artisan` directly from the host.
- NEVER assume PHP or Composer are installed on the host.
- Use Docker Compose to execute PHP/Laravel commands.

Ejemplos:

    docker compose exec app php artisan migrate

    docker compose exec app php artisan test

    docker compose exec app composer install

    docker compose exec app php artisan route:list

    docker compose exec app ./vendor/bin/pint

## Before modifying Docker configuration

Inspecciona lo que ya existe:

- docker-compose.yml
- Dockerfile
- configuración de contenedores

No recrees el entorno Docker salvo que sea necesario.

## Development workflow

1. Inspecciona la estructura del proyecto.
2. Inspecciona la configuración relevante.
3. Determina cómo funciona la aplicación actualmente.
4. Haz el cambio más pequeño y adecuado.
5. Ejecuta las pruebas relevantes dentro del contenedor.
6. Informa qué cambió y qué se verificó.

## Important

No inventes infraestructura que no exista en el proyecto.

Prefiere la configuración Docker/Compose existente antes que crear entornos locales alternativos.

## Laravel Boost

Las guidelines específicas del framework están en `AGENTS.md` (generadas por
`laravel/boost`, ya instalado). Léelas antes de escribir código.

Lo que sí aplica aquí, por conflicto con el comando de host que trae la bootstrap
original de Boost:

- Comandos Artisan: `docker compose exec app php artisan [command] --help`
- Tests: `docker compose exec app php artisan test --compact` (o `vendor/bin/phpunit`
  con la misma sintaxis)
- Estilo: `docker compose exec app ./vendor/bin/pint --dirty`

Usa `php artisan make:` para crear ficheros, también vía Docker.

## Command Execution

Cuando un comando implique PHP/Laravel:

MAL:

    php artisan test
    composer install
    php -v
    ./vendor/bin/pint

BIEN:

    docker compose exec app php artisan test
    docker compose exec app composer install
    docker compose exec app php -v
    docker compose exec app ./vendor/bin/pint