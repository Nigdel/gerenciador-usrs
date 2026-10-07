# Gerenciador de Usuarios

Sistema de gestión de usuarios que centraliza el alta y la suspensión de accesos
en varios subsistemas (Adagio, GLPI, Chatwoot, Email, Slack, EntraId, SambaAd,
…) a través de un contrato único.

La aplicación (Laravel) y el resto del entorno corren **íntegramente en Docker**:
PHP, Composer y Node solo existen dentro de los contenedores. Ver
[CLAUDE.md](CLAUDE.md) y el [README de la aplicación](src/README.md) para los
detalles de instalación y uso.

## Estructura

| Ruta | Contenido |
|---|---|
| [`docker-compose.yml`](docker-compose.yml) | Los seis servicios: app, assets, nginx, queue, scheduler y MySQL |
| [`docker/`](docker/) | Imagen de PHP y configuración de nginx |
| [`src/`](src/) | La aplicación Laravel |
| [`to-do/`](to-do/) | Planificación: [análisis del estado actual](to-do/analisis-estado-actual.md) y [plan por fases](to-do/plan-implementacion.md) |

## Arranque rápido

```bash
cp src/.env.example src/.env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

La aplicación queda en `http://localhost:8085`. Los pasos completos, con los
seeder de subsistemas, están en el [README de `src/`](src/README.md).

## Comandos frecuentes

```bash
docker compose exec app php artisan test --compact   # tests
docker compose exec app ./vendor/bin/pint             # estilo
docker compose logs -f app nginx                     # logs
docker compose exec app php artisan queue:failed     # jobs fallidos
```

Ningún comando PHP o Composer se ejecuta desde el host: siempre a través de
`docker compose exec`.

## Planificación

El trabajo pendiente está en [`to-do/`](to-do/), con el análisis de lo que hay y
el plan de fases. Las tareas se marcan con `[x]` al completarse.