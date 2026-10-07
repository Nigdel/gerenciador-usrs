# Gestor de Usuarios (Laravel)

Sistema de gestión de usuarios que centraliza el alta y la suspensión de
accesos en múltiples subsistemas (Adagio, GLPI, Chatwoot, Email, Slack,
EntraId, SambaAd, ...) a través de un contrato universal.

## Reproducir el proyecto

### Requisitos del host

- Git.
- Docker Engine con Docker Compose v2 (`docker compose`).
- Puertos `8085` (aplicación) y `3307` (MySQL) disponibles.

PHP, Composer, Node.js y npm se ejecutan dentro de contenedores; no hace falta
instalarlos en el host.

### Primera instalación

Ejecuta los comandos desde la raíz del repositorio, donde está
`docker-compose.yml`:

```bash
git clone <URL-del-repositorio> gerenciador-usrs
cd gerenciador-usrs
cp src/.env.example src/.env
```

Revisa `src/.env`. El ejemplo ya apunta al servicio MySQL de Compose. Cambia
`APP_URL` si vas a publicar la aplicación en otro host o puerto. Si vas a
integrar subsistemas externos, configura también sus credenciales y URLs antes
de sembrarlos; las variables están referenciadas en
`src/database/seeders/SubsystemSeeder.php`.

Construye y levanta los contenedores:

```bash
docker compose up -d --build
```

El servicio `assets` instala las dependencias JavaScript fijadas en
`src/package-lock.json`, genera el manifiesto de Vite y compila los estilos
Tailwind antes de que nginx arranque. Instala las dependencias PHP y prepara la
aplicación:

```bash
docker compose exec app composer install --no-interaction --prefer-dist
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=SubsystemSeeder --force
```

Abre `http://localhost:8085`. Si ese puerto ya está ocupado, cambia el puerto
del lado izquierdo en `docker-compose.yml` y ajusta `APP_URL` en `src/.env` para
que coincidan. El puerto de MySQL expuesto al host es `3307`; entre contenedores
la aplicación se conecta a `mysql:3306`.

Los valores de MySQL incluidos en Compose son únicamente para desarrollo local;
no expongas esos valores ni `APP_DEBUG=true` en un entorno público.

`.env.example` viene con `APP_DEBUG=false` a propósito, y conviene no tocarlo:
con `true`, cualquier error inesperado devuelve al navegador la excepción
completa —traza, rutas de fichero y valores del entorno— en lugar de una página
de error. Para depurar en local se sube a `true` y, al terminar, se vuelve a
bajar; el detalle siempre está en `storage/logs/laravel.log`, que funciona con
las dos opciones.

### Uso diario y reinicio

```bash
docker compose up -d
docker compose logs -f app nginx
docker compose down
```

`docker compose down` conserva los datos de MySQL. Para borrar también la base
de datos local y empezar desde cero:

```bash
docker compose down -v
```

Después de borrar el volumen, repite los comandos de migración y seeder de la
primera instalación. Para recompilar los estilos después de cambiar dependencias
o configuración frontend, ejecuta `docker compose up -d --force-recreate assets`.

## Operación del worker

El servicio `queue` corre `queue:work --queue=subsistemas,default --tries=3`, y
`scheduler` corre `schedule:work` (reacturación automática y `expire-stuck`).

### Tras cada despliegue

```bash
docker compose exec app php artisan queue:restart
```

**Hay que ejecutarla en cuanto se despliega**, no es opcional. El worker tiene el
código viejo cargado en memoria: `queue:restart` no mata el proceso, escribe una
marca de tiempo en la caché y el worker la ve al terminar el job que tiene entre
manos y se reinicia solo. Sin esto, un despliegue deja ejecutando la versión
anterior hasta que alguien reinicie el contenedor a mano — y como los jobs se
ejecutan dentro de peticiones que escriben en base de datos, el síntoma no es
un error visible, sino un alta a medias con el esquema nuevo.

La orden funciona entre contenedores porque `queue` y `app` comparten la
misma caché en MySQL. Si algún día la caché pasa a un Redis con un store por
contenedor, esta instrucción deja de propagarse silenciosamente y habría que
reiniciar el worker de otra forma.

El worker corre con `restart: unless-stopped`, y eso es lo que hace que la
orden sirva: al recibir la señal, `queue:work` sale, y Docker lo vuelve a
levantar con el código nuevo. Sin esa política, `queue:restart` lo dejaba
parado para siempre y la web seguía aceptando altas que nadie ejecutaba —
un fallo que no se ve en ningún log de la aplicación.

`queue:restart` no espera a que termine: el job en curso se completa con el
código viejo y el siguiente ya sale con el nuevo. Para una operación que no
puede esperar, mira antes el tamaño de las colas:

```bash
docker compose exec app php artisan queue:monitor database:default:1000
docker compose exec app php artisan queue:monitor database:subsistemas:1000
```

El formato es `conexión:cola:máximo`, y la conexión aquí se llama `database`
porque es la de `.env`. Ojo: si se escribe `default:1000` a secas, Laravel lo
interpreta como el nombre de una conexión y falla.

### Jobs fallidos

Un job que agota sus tres intentos (esperando 30 s, 2 min y 10 min entre
intentos) se guarda en `failed_jobs`, y su operación se queda `fallida` con el
motivo por cuenta:

```bash
docker compose exec app php artisan queue:failed                 # listar
docker compose exec app php artisan queue:failed 42              # ver el detalle de uno
docker compose exec app php artisan queue:retry 42               # reintentarlo
docker compose exec app php artisan queue:forget 42              # descartarlo
docker compose exec app php artisan queue:flush                  # vaciar la tabla
```

El ciclo está comprobado de punta a punta: la excepción se reintenta con el
backoff declarado, al tercer intento la fila pasa a `error` con el mensaje del
driver y la operación se cierra como `fallida`. Lo que hay que mirar no es el
job fallido —eso ya avisó— sino las operaciones `fallida` en el panel, que
son las que requieren que alguien pulse «Reintentar».

Desde el panel, la operación se puede reintentar entera desde la ficha del
usuario, que además vuelve a encolar solo las cuentas que fallaron.

`queue:flush` borra la tabla entera sin preguntar, así que úsalo solo cuando
ya se revisó qué había: es la única forma de perder el rastro de un fallo sin
haber mirado el mensaje.

Los servicios `queue` y `scheduler` arrancan a la vez que MySQL, así que en un
`docker compose up` recién hecho pueden morir alguna vez con
`Connection refused` hasta que la base esté lista. Con `restart:
unless-stopped` se levantan solos; sin esa política, esa carrera mataba el
worker para el resto de la sesión.

## Redis: por qué no hay servicio `redis` en Compose

La cola, la caché y las sesiones van a MySQL (`QUEUE_CONNECTION`,
`CACHE_STORE` y `SESSION_DRIVER` están en `database`), y ningún driver usa
`Redis::` directamente. El contenedor no hacía nada, así que se quitó en el
Sprint 2.3.

Si alguna vez hace falta —un caché compartido entre contenedores, locks de cola
para un worker con varias réplicas—, hay que **añadir las dos cosas**: el
servicio en `docker-compose.yml` y la extensión `phpredis` en
`docker/php/Dockerfile`. Hoy la imagen solo compila `gd` y `ldap`; poner
`REDIS_HOST=redis` sin la extensión falla en runtime, no al arrancar.

## Integrar en otro proyecto Laravel

1. Copia estas carpetas dentro de un proyecto Laravel existente (11.x o
   superior recomendado, requiere PHP 8.1+ por los enums):
   - `app/Models`, `app/Enums`, `app/Contracts`, `app/DTO`,
     `app/Services`, `app/Http/Controllers/Api`, `app/Http/Requests`,
     `app/Http/Resources`
   - `database/migrations`, `database/seeders`
   - `config/subsystems.php`
   - Agrega el contenido de `routes/api.php` a tu propio `routes/api.php`.

2. Registra el seeder en `database/seeders/DatabaseSeeder.php`:
   ```php
   $this->call(SubsystemSeeder::class);
   ```

3. Ejecuta:
   ```bash
   php artisan migrate
   php artisan db:seed --class=SubsystemSeeder
   ```

4. Configura en `.env` las credenciales de cada subsistema
   (`ADAGIO_API_URL`, `ADAGIO_API_TOKEN`, `GLPI_API_URL`, etc. — ver
   `SubsystemSeeder`).

## Arquitectura

- **User**: Modelo de autenticación del sistema (operadores, administradores).
- **GestorUser**: Modelo de identidad de los empleados finales cuya cuenta se provisiona en los subsistemas.

(Nota: Actualmente el modelo `User` contiene campos de perfil que se moverán a `GestorUser` o eliminarán en fases futuras).

- **`SubsystemServiceInterface`** (`app/Contracts`): contrato universal que
  implementa cada subsistema — `createUser`, `suspendUser`,
  `reactivateUser`, `disableUser`, `getUserStatus`.
- **`IdentityProviderInterface`**: contrato adicional que solo implementa
  el subsistema marcado como proveedor de identidad (Adagio) —
  `findByCpf`, `existsByEmail`.
- **`BaseSubsystemService`** (`app/Services/Subsystems`): clase base con el
  cliente HTTP ya configurado a partir de `api_url`/`api_config` del
  registro en BD. Cada subsistema (`AdagioService`, `GlpiService`,
  `ChatwootService`, `EmailService`, `SlackService`, `EntraIdService`,
  `SambaAdService`) extiende esta base e implementa su propia lógica —
  igual que el patrón `BaseService`/`run(payload)` que ya usas en Gestor,
  aquí con un método por operación en vez de un único `run`.
- **`SubsystemServiceRegistry`**: traduce el `slug` guardado en la tabla
  `subsystems` a la clase concreta, según `config/subsystems.php`. Es el
  único punto que conoce el mapeo slug → clase; para agregar un
  subsistema nuevo solo hay que crear su clase, registrarla aquí y crear
  la fila en `subsystems`.
- **`UsernameGeneratorService`**: cuando el CPF no existe en Adagio,
  propone `usuario` = `nombre.primerApellido`, validando contra Adagio
  (`existsByEmail`) con el dominio `empresa.com.br`; si ya está en uso
  prueba con `nombre.segundoApellido`.
- **`UserProvisioningService`**: orquesta todo el flujo de alta —
  consulta Adagio por CPF, reutiliza o genera datos, guarda el
  `GestorUser` local, y crea la cuenta en cada subsistema solicitado (o
  en todos los activos si el JSON no trae `subsistemas`).
- **`UserSuspensionService`**: orquesta la suspensión — localiza al
  usuario por `cpf` o `usuario`, resuelve las cuentas a suspender (las
  indicadas o todas las que tenga), llama a `suspendUser` en cada
  subsistema y actualiza `inicio_suspension`/`fin_suspension`/
  `motivo_suspension` localmente.

## Endpoints

### `POST /api/usuarios/provisionar`

```json
{
  "cpf": "123.456.789-00",
  "nombre_completo": "Juan Carlos Perez Gomez",
  "email_personal": "juan.perez@gmail.com",
  "telefono_personal": "+55 62 90000-0000",
  "telefono_trabajo": "+55 62 90000-1111",
  "direccion_particular": "Rua Exemplo, 123",
  "empresa": "Acme",
  "password_general": "una-clave-segura",
  "subsistemas": ["adagio", "glpi", "entraid"]
}
```

- Si `subsistemas` se omite, se crea en **todos** los subsistemas activos.
- Si el `cpf` ya existe en Adagio, se reutilizan `nombre_completo`,
  `email_personal`, `cpf` y `usuario` ya cadastrados ahí, ignorando lo que
  venga en el resto del payload para esos campos.

### `POST /api/usuarios/suspender`

```json
{
  "cpf": "123.456.789-00",
  "subsistemas": ["glpi"],
  "motivo_suspension": "Licencia médica",
  "inicio_suspension": "2026-09-21",
  "fin_suspension": "2026-10-05"
}
```

- Si `subsistemas` se omite, se suspende en **todos** los subsistemas
  donde el usuario tenga cuenta.
- Si ya estaba suspendido en un subsistema, esta llamada solo actualiza
  `inicio_suspension`, `fin_suspension` y `motivo_suspension`.

Los dos `POST` responden **`409`** si el usuario ya tiene una operación sin
terminar. No es un error de la petición —el alta está bien formada— sino un
conflicto con el trabajo anterior, y encadenar operaciones sobre la misma
persona haría que dos jobs tocaran la misma cuenta a la vez:

```json
{
  "message": "Ya hay una operación en curso para este usuario (Alta, iniciada el 21/09/2026 14:32).",
  "operacion_id": "0f8b..."
}
```

`operacion_id` es el `uuid` de la operación que bloquea: se consulta con el
endpoint siguiente hasta que termine y se repite la llamada. Desde el panel el
mismo caso sale como un aviso y la operación en curso se ve en la ficha del
usuario.

Una operación cuyo worker muere se quedaría 'en curso' para siempre y seguiría
bloqueando al usuario, así que `operations:expire-stuck` —cada 15 minutos—
cierra como `fallida` las que llevan más de `OPERATIONS_STUCK_MINUTES` (30 por
defecto) sin actividad, y sus cuentas pendientes quedan en `error` con el
motivo a la vista para poder reintentarlas.

### `GET /api/operaciones/{uuid}`

Ambos `POST` de arriba responden `201` con un `operacion_id`: el trabajo real
sale a los subsistemas en segundo plano, así que en ese momento las cuentas
todavía **no** están creadas. Para saber si lo quedaron, hay que consultar
esta ruta con ese `operacion_id` hasta que `terminado` sea `true`.

```json
{
  "uuid": "0f8b...",
  "tipo": "alta",
  "estado": "en_curso",
  "terminado": false,
  "resumen": "Sin procesar",
  "cuentas": [
    { "subsistema": "email", "estado": "ok", "mensaje": "Cuenta creada", "intentos": 1 },
    { "subsistema": "glpi",  "estado": "error", "mensaje": "Timeout", "intentos": 3 }
  ]
}
```

- `terminado` pasa a `true` cuando no queda ninguna cuenta pendiente. Es el
  único bucle que necesita la integración; no hay que interpretar `estado`.
- Los estados terminales son `completada` (todas las cuentas salieron bien) y
  `fallida` (alguna tuvo error). En `failida`, `cuentas[].mensaje` dice por qué.
- Requiere la ability `operaciones:consultar`, independiente de las de
  escritura: un token que solo informa del estado no puede crear usuarios.
- Un token **solo ve las operaciones que él mismo originó** por la API. Las
  hechas desde la web responden `404`, igual que las inexistentes: que la
  operación exista ya es información de otro.
- Nunca devuelve el `payload` de la operación, que lleva la contraseña general
  en claro.

Para emitir un token con esa ability:

```
docker compose exec app php artisan api:token {integracion} \
  --abilities=usuarios:provisionar,usuarios:suspender,operaciones:consultar
```

### Códigos de error

| Código | Cuándo | Cuerpo |
| --- | --- | --- |
| `401` / `403` | sin token, o sin la ability que exige la ruta | — |
| `422` | los datos llegaron bien formados pero la situación no lo permite (el nombre no da para un login, no hay `cpf` ni `usuario`…) | `{ "message": "…" }` con el motivo |
| `409` | el usuario ya tiene una operación sin terminar | `{ "message", "operacion_id" }` |
| `502` | algo se rompió por dentro: Adagio o un subsistema no responden, falta la extensión `ldap`, error de programación | `{ "message": "…" }` genérico |

El `422` se distingue del `400` a propósito: es un `422` cuando la misma
petición puede aceptarse más tarde sin tocar un solo campo, y un `400` cuando
el payload en sí está mal. Los errores de validación de Laravel ya devuelven
`422` con el detalle campo a campo.

El `502` nunca incluye el mensaje de la excepción original. Ese texto puede
llevar un nombre de columna, la URL de un LDAP o un rastro de pila, y quien
recibe la respuesta no puede hacer nada con eso —pero sí puede aprender mucho
quien lo lea. El detalle va al log.

## Agregar un subsistema nuevo

1. Crear `app/Services/Subsystems/NuevoService.php` implementando
   `SubsystemServiceInterface` (extender `BaseSubsystemService`).
2. Agregarlo a `config/subsystems.php` → `drivers`.
3. Insertar la fila correspondiente en la tabla `subsystems`
   (`nombre`, `slug`, `api_url`, `api_config`, ...).

No se requiere tocar `UserProvisioningService` ni
`UserSuspensionService`: ambos operan solo contra el contrato universal.

## Notas / puntos a ajustar según tus APIs reales

- Las rutas/campos exactos de cada llamada HTTP dentro de cada
  `*Service` son un patrón de referencia; ajústalos a la API real de
  cada subsistema (Adagio, GLPI, Chatwoot, etc.).
- `password_general` se hashea automáticamente al guardar el
  `GestorUser` (ver `GestorUser::booted()`).
- El dominio usado para proponer usuario/email (`empresa.com.br`) se
  resuelve en `UsernameGeneratorService::resolverDominio()`; puedes
  reemplazarlo por una tabla de empresas si manejas varios dominios.
