#!/bin/sh
# Arranque del contenedor de aplicación.
#
# Los cachés de Laravel se generan aquí y no en el build por una razón concreta:
# `config:cache` y `route:cache` escriben en el disco el valor de cada variable
# de entorno leída durante la compilación. Si se generaran al construir la
# imagen, quedarían congelados los valores de entonces y las credenciales que
# llegan en tiempo de ejecución —APP_KEY, DB_PASSWORD— no se aplicarían nunca.
#
# El orden importa: config y rutas antes que vistas, porque compilar vistas
# puede necesitar resolver configuración.
set -e

generar_caches() {
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
    php artisan event:cache --no-interaction

    # storage:link falla si el enlace ya existe, que es el caso normal al
    # reiniciar. No es motivo para no arrancar.
    php artisan storage:link --no-interaction >/dev/null 2>&1 || true
}

esperar_mysql() {
    # El healthcheck de mysql en compose ya ordena el arranque, pero quien
    # ejecute el contenedor suelto (un `docker run`, o un despliegue que quite
    # la dependencia) no tiene esa garantía, y sin base de datos la primera
    # migración falla con un error que no explica la causa real.
    if [ -z "${DB_HOST:-}" ]; then
        return 0
    fi

    echo "Esperando a la base de datos en ${DB_HOST}:${DB_PORT:-3306}..."

    intentos=0
    hasta=60

    while [ "$intentos" -lt "$hasta" ]; do
        if php -r '
            $host = getenv("DB_HOST");
            $puerto = getenv("DB_PORT") ?: "3306";
            try {
                new PDO("mysql:host={$host};port={$puerto}", getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
                exit(0);
            } catch (Throwable $e) {
                exit(1);
            }
        ' 2>/dev/null; then
            echo "Base de datos disponible."
            return 0
        fi

        intentos=$((intentos + 1))
        sleep 2
    done

    # Agotados los intentos se continúa igualmente: el arranque sin base de datos
    # sirve para Diagnosticar (migraciones pendientes, credenciales mal puestas)
    # y el healthcheck es quien decide si el servicio entra en rotación.
    echo "La base de datos no respondió a tiempo; se continúa igualmente." >&2
}

esperar_mysql
generar_caches

# Se ejecuta lo que llegue en CMD, que es distinto según el servicio: php-fpm
# en `app`, schedule:work en el scheduler y queue:work en el worker. Los tres
# necesitan los mismos cachés, así que la preparación va aquí y no en cada uno.
exec "$@"