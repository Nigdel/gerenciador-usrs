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

## Despliegue

El perfil de producción es [`docker-compose.prod.yml`](docker-compose.prod.yml),
con `docker/php/Dockerfile.prod`, `docker/nginx/prod.conf`, `docker/php/php.ini.prod`
y `docker/php/entrypoint.sh`. No es el de desarrollo con otras variables: el
código va compilado dentro de la imagen (no hay volúmenes), el worker y el
scheduler están supervisados, y MySQL no se publica.

```bash
cp .env.example .env
docker compose exec app php artisan key:generate --show   # copiar a APP_KEY
$EDITOR .env                                            # rellenar contraseñas
docker compose -f docker-compose.prod.yml up --build -d
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

La aplicación queda en `http://localhost:8085` (o en el `APP_PORT` que se haya
puesto en `.env`).

**Aviso al alternar entre los dos stacks:** levantar el de producción deja
cachés de Laravel (`config:cache`, `route:cache`) en `src/bootstrap/cache/`,
porque el volumen del código es el mismo árbol que monta el de desarrollo. Ese
`config.php` cacheado tiene prioridad sobre el `phpunit.xml`, así que el
testsuite pasa a usar MySQL y `session=database` en vez de SQLite en memoria, y
falla con errores de migración y 419 (CSRF) que no tienen nada que ver con los
tests. Si pasa, hay que limpiarlos desde el stack de desarrollo:

```bash
docker compose exec app php artisan optimize:clear
```

Los dos perfiles pueden levantarse a la vez: el de producción usa su propio
nombre de proyecto (`gerenciador-usrs-prod`) y sus propias imágenes
(`gerenciador-usrs-prod-app`, `gerenciador-usrs-prod-web`). Si coincidieran, un
`up` del stack de producción recrearía los contenedores del de desarrollo con la
imagen de producción —que no lleva PHPUnit ni las dependencias de desarrollo—
y el otro stack se quedaría inservible sin ningún aviso.

**Sobre el HTTPS:** el stack publica HTTP y el TLS lo termina un proxy externo,
que debe reenviar `X-Forwarded-Proto`. Si se despliega sin proxy, hay que poner
`session.cookie_secure=0` en `docker/php/php.ini.prod`; con la cookie marcada
como segura sobre una URL `http://` el navegador la rechaza y nadie puede
iniciar sesión.

**Al rotar `APP_KEY`:** todo lo cifrado con la clave anterior queda ilegible —los
`api_config` de los subsistemas y los payloads de las operaciones pendientes—,
y no hay recifrado automático. Ver la tarea 6.4 de
[plan-implementacion.md](to-do/plan-implementacion.md).

## Operación y Backups

El sistema realiza volcados cifrados de la base de datos diariamente vía scheduler.

### Restauración de datos
Para restaurar la base de datos desde un archivo cifrado:
1. Colocar el archivo `.enc` en `storage/app/backups/` (dentro del contenedor).
2. Ejecutar el comando de restauración:
   ```bash
   docker compose exec app php artisan db:restore storage/app/backups/nombre_del_archivo.sql.enc
   ```
3. Confirmar la acción cuando el sistema advierta que se sobrescribirá la base de datos actual.

*Nota: Requiere que `DB_BACKUP_PASSWORD` esté configurada en el `.env`.*

Comandos del stack:

```bash
docker compose -f docker-compose.prod.yml ps                # estado y salud
docker compose -f docker-compose.prod.yml logs -f app nginx
docker compose -f docker-compose.prod.yml exec app php artisan schedule:list
docker compose -f docker-compose.prod.yml down               # parar (conserva datos)
docker compose -f docker-compose.prod.yml down -v            # parar y BORRAR la base
```

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