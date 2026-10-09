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

El estado, la lista priorizada de pendientes y el plan de implementación están
en [`to-do/`](to-do/). Las tareas se marcan con `[x]` al completarse.


# to-do — Gestor de usuarios

Carpeta de planificación del proyecto.

| Archivo | Contenido |
|---|---|
| [`analisis-estado-actual.md`](to-do/analisis-estado-actual.md) | Qué está implementado, qué falta y problemas detectados en el código |
| [`todo.md`](to-do/todo.md) | Estado resumido y lista priorizada de pendientes |
| [`plan-implementacion.md`](to-do/plan-implementacion.md) | Plan por fases con tareas, criterios de aceptación y orden recomendado |

> Convención: marcar las tareas con `[x]` al completarlas. Todos los comandos PHP/Laravel se ejecutan
> con `docker compose exec app ...` (ver `CLAUDE.md`).