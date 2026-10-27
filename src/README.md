# Gestor de Usuarios (Laravel)

Sistema de gestión de usuarios que centraliza el alta y la suspensión de accesos
en múltiples subsistemas (Adagio, GLPI, Chatwoot, Email, Slack, EntraId,
SambaAd, ...) a través de un contrato universal.

Hay dos tipos de persona y conviene no confundirlos, porque todo lo demás cuelga
de ahí:

- **User** — quien opera el sistema. Entra al panel o llama a la API. No existe
  en Adagio ni en ningún subsistema.
- **GestorUser** — el empleado al que se le da de alta cuentas. No entra al
  panel; existe porque hay que provisionarlo y suspensiónarlo.

El alta es **asíncrona**: la petición o el click crean una *operación* y
responden al instante, y es un job aparte el que va hablando con cada subsistema.
Todo lo que hay que saber de ese diseño está en [El flujo de una
operación](#el-flujo-de-una-operación).

## Índice

- [1. Cómo funciona](#1-cómo-funciona)
  - [Los dos modelos](#los-dos-modelos)
  - [El contrato universal con los subsistemas](#el-contrato-universal-con-los-subsistemas)
  - [El flujo de una operación](#el-flujo-de-una-operación)
  - [Clases principales](#clases-principales)
- [2. Puesta en marcha](#2-puesta-en-marcha)
  - [Requisitos del host](#requisitos-del-host)
  - [Primera instalación](#primera-instalación)
  - [Uso diario](#uso-diario)
- [3. API](#3-api)
  - [Autenticación](#autenticación)
  - [`GET /api/usuarios/consultar`](#get-apiusuariosconsultar)
  - [`POST /api/usuarios/provisionar`](#post-apiusuariosprovisionar)
  - [`POST /api/usuarios/suspender`](#post-apiusuariosuspender)
  - [`GET /api/operaciones/{uuid}`](#get-apioperacionesuuid)
  - [Errores y códigos de respuesta](#errores-y-códigos-de-respuesta)
  - [Idempotencia](#idempotencia)
- [4. Extender el proyecto](#4-extender-el-proyecto)
  - [Agregar un subsistema nuevo](#agregar-un-subsistema-nuevo)
  - [Llevar el módulo a otro proyecto Laravel](#llevar-el-módulo-a-otro-proyecto-laravel)
- [5. Operación](#5-operación)
  - [Tras cada despliegue](#tras-cada-despliegue)
  - [Tareas programadas](#tareas-programadas)
  - [Operaciones atascadas](#operaciones-atascadas)
  - [Jobs fallidos](#jobs-fallidos)
  - [Comandos propios](#comandos-propios)
  - [Recetas de Tinker](#recetas-de-tinker)
  - [Por qué no hay Redis](#por-qué-no-hay-redis)
- [6. Calidad y verificación](#6-calidad-y-verificación)
  - [CI](#ci)
  - [Cobertura](#cobertura)
  - [Análisis estático (Larastan)](#análisis-estático-larastan)
- [7. Limitaciones conocidas](#7-limitaciones-conocidas)

---

## 1. Cómo funciona

### Los dos modelos

- **User** (`app/Models/User.php`): autenticación del sistema — operadores,
  administradores y auditors. Campos de autenticación, `role` y datos de perfil.
  Es el dueño de los tokens de la API.
- **GestorUser** (`app/Models/GestorUser.php`): identidad de los empleados
  finales cuyas cuentas se provisionan. Guarda `cpf`, `usuario`, la
  `password_general` y, por relación, sus cuentas en cada subsistema.

`password_general` se hashea solo al guardar, en el hook `saving()` de
`GestorUser::booted()`, sin importar desde dónde se asigne el atributo. El modelo
`User` usa el cast `'password' => 'hashed'`.

El `User` tiene hoy campos de perfil que están en el modelo equivocado y que se
moverán a `GestorUser` o desaparecerán en fases futuras.

### El contrato universal con los subsistemas

Cada subsistema implementa `SubsystemServiceInterface` (`app/Contracts`), que
define `createUser`, `suspendUser`, `reactivateUser`, `disableUser` y
`getUserStatus`.

`AdagioService` implementa además `IdentityProviderInterface`, el contrato
extra que solo tiene el subsistema marcado como proveedor de identidad:
`findByCpf` y `existsByEmail`. Es la fuente de verdad para decidir si un alta es
un alta o una persona que ya existe y hay que reutilizar.

`BaseSubsystemService` (`app/Services/Subsystems`) es la clase base con el
cliente HTTP ya configurado a partir de `api_url`/`api_config` del registro en
BD. Cada subsistema (`AdagioService`, `GlpiService`, `ChatwootService`,
`EmailService`, `SlackService`, `EntraIdService`, `SambaAdService`) extiende esta
base e implementa su propia lógica — con un método por operación en vez de un
único `run(payload)`.

### El flujo de una operación

```
  POST /api/usuarios/provisionar   (o el panel)
              │
              ▼
  UserProvisioningService ──► GestorUser en BD
              │
              ▼
  ProvisioningOperation + una fila por cuenta, todas en 'pendiente'
              │
              ▼
  ProcessOperationAccount por fila, a la cola «subsistemas»
              │
              ▼
  el worker habla con cada subsistema ──► 'ok' / 'error'
              │
              ▼
  operación 'completada' o 'fallida'  ──► GET /api/operaciones/{uuid}
```

Lo que sale de la petición es el identificador de la operación, no el resultado.
Cuando la respuesta es `201`, las cuentas **todavía no están creadas**: eso es lo
que hace falta consultar después con `GET /api/operaciones/{uuid}`.

Dos reglas que salen de este diseño y que conviene no saltarse:

- **Una operación por usuario a la vez.** Si ya hay una en curso, la petición
  responde `409`. Encadenar operaciones sobre la misma persona haría que dos jobs
  tocaran la misma cuenta simultáneamente.
- **El trabajo sale de la petición, no de ella.** Por eso `queue:restart` tras
  cada despliegue no es opcional: ver [Tras cada
  despliegue](#tras-cada-despliegue).

### Clases principales

- **`SubsystemServiceRegistry`**: traduce el `slug` guardado en la tabla
  `subsystems` a la clase concreta, según `config/subsystems.php`. Es el único
  punto que conoce el mapeo slug → clase.
- **`UsernameGeneratorService`**: cuando el CPF no existe en Adagio, propone
  `usuario` = `nombre.primerApellido`, validando contra Adagio (`existsByEmail`)
  con el dominio `empresa.com.br`; si ya está en uso prueba con
  `nombre.segundoApellido`.
- **`UserProvisioningService`**: orquesta todo el flujo de alta — consulta
  Adagio por CPF, reutiliza o genera datos, guarda el `GestorUser` local, y crea
  la cuenta en cada subsistema solicitado (o en todos los activos si el JSON no
  trae `subsistemas`).
- **`UserSuspensionService`**: orquesta la suspensión — localiza al usuario por
  `cpf` o `usuario`, resuelve las cuentas a suspender (las indicadas o todas las
  que tenga), llama a `suspendUser` en cada subsistema y actualiza
  `inicio_suspension`/`fin_suspension`/`motivo_suspension` localmente.

---

## 2. Puesta en marcha

PHP, Composer, Node.js y npm se ejecutan dentro de contenedores; no hace falta
instalarlos en el host. **Nunca** se ejecutan directamente sobre el host: todo
va por `docker compose exec app`.

### Requisitos del host

- Git.
- Docker Engine con Docker Compose v2 (`docker compose`).
- Puertos `8085` (aplicación) y `3307` (MySQL) disponibles.

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
docker compose exec app php artisan config:clear
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

### Uso diario

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

---

## 3. API

Cuatro endpoints, definidos en `src/routes/api.php` y cerrados con
`auth:sanctum`.

| Método | Ruta                        | Nombre                     | Ability                 |
| ------ | --------------------------- | -------------------------- | ----------------------- |
| `GET`  | `/api/usuarios/consultar`   | `api.usuarios.consultar`   | `usuarios:consultar`    |
| `POST` | `/api/usuarios/provisionar` | `api.usuarios.provisionar` | `usuarios:provisionar`  |
| `POST` | `/api/usuarios/suspender`   | `api.usuarios.suspender`   | `usuarios:suspender`    |
| `GET`  | `/api/operaciones/{uuid}`   | `api.operaciones.show`     | `operaciones:consultar` |

### Autenticación

Cada endpoint exige su propia ability: el rol del usuario dueño del token no
abre nada por sí solo, solo las abilities emitidas. Sin token, `401`; con token
sin la ability, `403`.

El token se emite por comando, nunca por HTTP:

```bash
docker compose exec app php artisan api:token {integracion} \
  --abilities=usuarios:consultar,usuarios:provisionar,usuarios:suspender,operaciones:consultar

# Emitir para el usuario admin del sistema en vez de preguntar el email:
docker compose exec app php artisan api:token {integracion} --admin --abilities=...

# Revocar en vez de emitir (invalida el token anterior de esa integración):
docker compose exec app php artisan api:token {integracion} --revoke
```

`api:token` borra los tokens anteriores con el mismo nombre de integración, así
que emitir de nuevo invalida el que la integración ya tenía desplegado. El texto
en claro solo se muestra una vez.

Las peticiones van como `Authorization: Bearer <token>`. `Accept:
application/json` es buena costumbre pero no es imprescindible: como todo lo que
cae bajo `/api/` fuerza la respuesta en JSON, los errores llegan parseables
aunque el cliente no lo pida.

Hay rate limit por integración, no por IP: **60 peticiones/minuto por token** y
**600/minuto entre todas** (`AppServiceProvider::boot()`). Al superarlo, `429`
con `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` y
`X-RateLimit-Reset`.

### `GET /api/usuarios/consultar`

Consulta previa al alta: recibe un CPF y devuelve los datos reutilizables que ya
existen, junto con la fuente de la que salieron.

```bash
curl "http://localhost:8085/api/usuarios/consultar?cpf=123.456.789-09" \
  -H "Authorization: Bearer 1|tu-token" \
  -H "Accept: application/json"
```

Los tres casos posibles son:

```json
{ "userdata": null }
```

```json
{
    "userdata": {
        "source": "self",
        "id": 42,
        "nombre_completo": "Juan Carlos Perez Gomez",
        "cpf": "12345678909",
        "usuario": "juan.perez",
        "empresa": "Acme",
        "estado": "activo",
        "email_personal": "juan.perez@gmail.com",
        "telefono_personal": "+55 62 90000-0000",
        "telefono_trabajo": "+55 62 90000-1111",
        "direccion_particular": "Rua Exemplo, 123"
    }
}
```

```json
{
    "userdata": {
        "source": "adagio",
        "nombre_completo": "Juan Carlos Perez Gomez",
        "email_personal": "juan.perez@empresa.com.br",
        "cpf": "12345678909",
        "usuario": "juan.perez",
        "external_account_id": "77"
    }
}
```

- Busca primero en el sistema. Si el CPF ya es un `GestorUser`, devuelve
  `source: "self"` y no consulta Adagio: es la señal de que **no hay que crear**
  otro usuario.
- Si no está en el sistema, consulta Adagio. Si existe allí, devuelve
  `source: "adagio"` con los campos ya cadastrados para reutilizarlos.
- Si no está en ninguno, `userdata` es `null` y el alta puede continuar.
- Si Adagio no responde, devuelve `503` en vez de `null`: no se puede afirmar
  que el CPF no exista.
- El CPF se normaliza a dígitos antes de validarse, igual que en el alta.

### `POST /api/usuarios/provisionar`

```bash
curl -X POST http://localhost:8085/api/usuarios/provisionar \
  -H "Authorization: Bearer 1|tu-token" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 0f8b1c2e-..." \
  -d '{
        "cpf": "123.456.789-09",
        "empresa": "Acme",
        "subsistemas": ["adagio", "glpi"]
      }'
```

```json
{
    "cpf": "123.456.789-09",
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

- `cpf` es el único campo obligatorio. Acepta puntuación, pero se normaliza a
  dígitos antes de validarse, y la regla `Cpf` rechaza los que no son válidos.
- Si `subsistemas` se omite, se crea en **todos** los subsistemas activos.
- Si el `cpf` ya existe en Adagio, se reutilizan `nombre_completo`,
  `email_personal`, `cpf` y `usuario` ya cadastrados ahí, ignorando lo que venga
  en el resto del payload para esos campos.
- Responde `201` con `usuario`, `operacion_id`, `tipo`, `estado`,
  `subsistemas[]` —las cuentas aún en `pendiente`— y `login_no_verificado`,
  que avisa de que el login propuesto no se pudo confirmar contra algún
  subsistema y hay que verificarlo antes de entregar las credenciales.

### `POST /api/usuarios/suspender`

```json
{
    "cpf": "123.456.789-09",
    "subsistemas": ["glpi"],
    "motivo_suspension": "Licencia médica",
    "inicio_suspension": "2026-09-21",
    "fin_suspension": "2026-10-05"
}
```

```bash
curl -X POST http://localhost:8085/api/usuarios/suspender \
  -H "Authorization: Bearer 1|tu-token" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
        "cpf": "123.456.789-09",
        "subsistemas": ["glpi"],
        "motivo_suspension": "Licencia médica"
      }'
```

- Identifica al usuario por `cpf` **o** por `usuario`; uno de los dos es
  obligatorio.
- `motivo_suspension` es obligatorio. `inicio_suspension` y `fin_suspension` son
  opcionales, y `fin_suspension` tiene que ser posterior al inicio.
- Si `subsistemas` se omite, se suspende en **todos** los subsistemas donde el
  usuario tenga cuenta.
- Si ya estaba suspendido en un subsistema, esta llamada solo actualiza
  `inicio_suspension`, `fin_suspension` y `motivo_suspension`.
- Responde `200` con `operacion_id`, `tipo`, `estado` y `subsistemas[]`.
- Con `fin_suspension`, la suspensión es programada: `accounts:apply-pending-suspensions`
  la aplica cuando llega la fecha y `accounts:reactivate-expired` la revierte
  cuando termina. Ver [Tareas programadas](#tareas-programadas).

### `GET /api/operaciones/{uuid}`

```bash
curl http://localhost:8085/api/operaciones/0f8b... \
  -H "Authorization: Bearer 1|tu-token" \
  -H "Accept: application/json"
```

```json
{
    "uuid": "0f8b...",
    "tipo": "alta",
    "estado": "en_curso",
    "terminado": false,
    "resumen": "Sin procesar",
    "cuentas": [
        {
            "subsistema": "email",
            "estado": "ok",
            "mensaje": "Cuenta creada",
            "intentos": 1
        },
        {
            "subsistema": "glpi",
            "estado": "error",
            "mensaje": "Timeout",
            "intentos": 3
        }
    ]
}
```

- `terminado` pasa a `true` cuando no queda ninguna cuenta pendiente. Es el
  único bucle que necesita la integración; no hay que interpretar `estado`.
- Los estados terminales son `completada` (todas las cuentas salieron bien) y
  `fallida` (alguna tuvo error). En `fallida`, `cuentas[].mensaje` dice por qué.
- Requiere la ability `operaciones:consultar`, independiente de las de
  escritura: un token que solo informa del estado no puede crear usuarios.
- Un token **solo ve las operaciones que él mismo originó** por la API. Las
  hechas desde la web responden `404`, igual que las inexistentes: que la
  operación exista ya es información de otro.
- Nunca devuelve el `payload` de la operación, que lleva la contraseña general en
  claro.

### Errores y códigos de respuesta

| Código        | Cuándo                                                                                                                                             | Cuerpo                             |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| `401` / `403` | sin token, o sin la ability que exige la ruta                                                                                                       | —                                  |
| `422`         | los datos llegaron bien formados pero la situación no lo permite (el nombre no da para un login, no hay `cpf` ni `usuario`, el usuario no existe…), o la `Idempotency-Key` ya se usó con otro cuerpo | `{ "message": "…" }` con el motivo |
| `425`         | la misma `Idempotency-Key` sigue procesándose; reintentar en un segundo devuelve la respuesta ya guardada | `{ "message", "retry_after" }`     |
| `409`         | el usuario ya tiene una operación sin terminar                                                                                                      | `{ "message", "operacion_id" }`    |
| `429`         | se pasó del rate limit (60/min por token, 600/min global)                                                                                           | `Retry-After`, `X-RateLimit-*`     |
| `502`         | algo se rompió por dentro: Adagio o un subsistema no responden, falta la extensión `ldap`, error de programación                                    | `{ "message": "…" }` genérico      |

El `422` se distingue del `400` a propósito: es un `422` cuando la misma
petición puede aceptarse más tarde sin tocar un solo campo, y un `400` cuando
el payload en sí está mal. Los errores de validación de Laravel ya devuelven
`422` con el detalle campo a campo.

El `502` nunca incluye el mensaje de la excepción original. Ese texto puede
llevar un nombre de columna, la URL de un LDAP o un rastro de pila, y quien
recibe la respuesta no puede hacer nada con eso —pero sí puede aprender mucho
quien lo lea. El detalle va al log.

El `409` no es un error de la petición —el alta está bien formada— sino un
conflicto con el trabajo anterior:

```json
{
    "message": "Ya hay una operación en curso para este usuario (Alta, iniciada el 21/09/2026 14:32).",
    "operacion_id": "0f8b..."
}
```

El `operacion_id` que viene ahí es el de la operación que bloquea: se consulta
hasta que termine y se repite la llamada. Desde el panel el mismo caso sale como
un aviso y la operación en curso se ve en la ficha del usuario.

Lo que **no** resuelve el `409` es una operación cuyo worker murió: esa se queda
'en curso' para siempre y sigue bloqueando al usuario. Ver [Operaciones
atascadas](#operaciones-atascadas).

### Idempotencia

Los dos `POST` pasan por el middleware `idempotencia`. Con la cabecera
`Idempotency-Key`, repetir la misma clave con el mismo cuerpo devuelve la
respuesta original —byte a byte, incluido el status— en vez de ejecutar una
segunda vez. Es lo que hace segura la respuesta a un timeout.

Sin la cabecera la petición también se ejecuta, solo que sin esa garantía de
reintento. La clave siempre vuelve en la respuesta, así que la integración puede
distinguir la original de la repetida leyendo la cabecera en vez de comparar
cuerpos.

Qué pasa en cada caso:

| Situación                                       | Respuesta                                   |
| ----------------------------------------------- | ------------------------------------------- |
| Clave nueva                                     | Se ejecuta y se guarda la respuesta         |
| Misma clave, mismo cuerpo                       | Se reenvía la respuesta guardada            |
| Misma clave, la anterior sigue en curso         | `425`, con `retry_after: 1`                 |
| Misma clave, cuerpo distinto                    | `422`, «ya se usó con un cuerpo distinto»   |
| Error `4xx`                                      | La clave se libera y se puede reintentar    |
| Error `5xx`                                      | Se guarda el fallo: repetir da lo mismo     |

Sin clave no cambia nada —la idempotencia es optaria, para no obligar a las
integraciones que nunca reintentan a inventar una clave— y una clave en blanco
cuenta como ausente, no como una válida: un espacio se guardaría tal cual y dos
peticiones distintas acabarían compartiendo fila.

Las claves caducadas las poda `idempotency:prune`; si no se reintenta nada, esa
tarea es la única que evita que la tabla crezca sin control.

El middleware corre antes que la validación a propósito: una clave repetida con
otro cuerpo tiene que contestar `422` por el conflicto, no esconderse detrás de un
«falta el campo nombre». El `422` del conflicto y el de la validación comparten
código a propósito —el remedio es el mismo: mandar la clave original con su
cuerpo, o una clave nueva—, pero `425` es distinto: ahí no hay conflicto con el
negocio, es que la respuesta anterior todavía no existe.

---

## 4. Extender el proyecto

### Agregar un subsistema nuevo

1. Crear `app/Services/Subsystems/NuevoService.php` implementando
   `SubsystemServiceInterface` (extender `BaseSubsystemService`).
2. Agregarlo a `config/subsystems.php` → `drivers`.
3. Insertar la fila correspondiente en la tabla `subsystems`
   (`nombre`, `slug`, `api_url`, `api_config`, ...).

No se requiere tocar `UserProvisioningService` ni `UserSuspensionService`: ambos
operan solo contra el contrato universal.

### Llevar el módulo a otro proyecto Laravel

1. Copia estas carpetas dentro de un proyecto Laravel existente (11.x o
   superior recomendado, requiere PHP 8.1+ por los enums):
   - `app/Models`, `app/Enums`, `app/Contracts`, `app/DTO`, `app/Services`,
     `app/Http/Controllers/Api`, `app/Http/Requests`, `app/Http/Resources`
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

4. Configura en `.env` las credenciales de cada subsistema (`ADAGIO_API_URL`,
   `ADAGIO_API_TOKEN`, `GLPI_API_URL`, etc. — ver `SubsystemSeeder`).

---

## 5. Operación

### Tras cada despliegue

```bash
docker compose exec app php artisan queue:restart
```

**Hay que ejecutarla en cuanto se despliega**, no es opcional. El worker tiene el
código viejo cargado en memoria: `queue:restart` no mata el proceso, escribe una
marca de tiempo en la caché y el worker la ve al terminar el job que tiene entre
manos y se reinicia solo. Sin esto, un despliegue deja ejecutando la versión
anterior hasta que alguien reinicie el contenedor a mano — y como los jobs se
ejecutan dentro de peticiones que escriben en base de datos, el síntoma no es un
error visible, sino un alta a medias con el esquema nuevo.

La orden funciona entre contenedores porque `queue` y `app` comparten la misma
caché en MySQL. Si algún día la caché pasa a un Redis con un store por
contenedor, esta instrucción deja de propagarse silenciosamente y habría que
reiniciar el worker de otra forma.

El worker corre con `restart: unless-stopped`, y eso es lo que hace que la orden
sirva: al recibir la señal, `queue:work` sale, y Docker lo vuelve a levantar con
el código nuevo. Sin esa política, `queue:restart` lo dejaba parado para siempre
y la web seguía aceptando altas que nadie ejecutaba — un fallo que no se ve en
ningún log de la aplicación.

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

### Tareas programadas

El contenedor `scheduler` corre `schedule:work`. Lo programado, tal cual está hoy:

| Frecuencia  | Comando                              | Qué hace                                                          |
| ----------- | ------------------------------------ | ----------------------------------------------------------------- |
| */10 min    | `accounts:reactivate-expired`        | Reactiva las cuentas cuya suspensión ya venció                     |
| */10 min    | `accounts:apply-pending-suspensions`  | Aplica las suspensiones agendadas cuya fecha de inicio ya pasó      |
| */10 min    | `gestor-users:reactivate-expired`     | Reactiva a los gestores suspendidos cuya fecha de fin ya venció    |
| cada hora   | `operations:prune-secrets`           | Borra la contraseña general del payload de operaciones vencidas    |
| cada hora   | `idempotency:prune`                  | Elimina las claves de idempotencia caducadas                       |
| a diario    | `db:backup`                          | Volcado cifrado de MySQL                                            |
| a diario    | `accounts:reconcile`                 | Verifica y crea discrepancias de cuentas                            |

Todas llevan `withoutOverlapping()` y `onOneServer()`, así que una pasada
lenta no se pisa con la siguiente. Para verlas:

```bash
docker compose exec app php artisan schedule:list
```

#### Cambiar la frecuencia

`schedule:work` despierta cada minuto y lanza lo que esté vencido, así que
`everyTenMinutes()` significa «como mucho una pasada cada 10 minutos»; no hace
falta un cron externo. La programación vive en `routes/console.php`, y cada
tarea se puede ajustar por separado:

```php
// routes/console.php
Schedule::command('gestor-users:reactivate-expired')
    ->everyTenMinutes()   // ->everyMinute(), ->everyFiveMinutes(),
    ->withoutOverlapping() // ->everyFifteenMinutes(), ->everyThirtyMinutes(),
    ->onOneServer();       // ->hourly(), ->dailyAt('02:00'), ->cron('*/20 * * * *')
```

Las tres tareas de suspensión (`accounts:reactivate-expired`,
`accounts:apply-pending-suspensions` y `gestor-users:reactivate-expired`) son
bloques independientes: se puede acelerar solo la de gestores sin tocar las
otras.

Tras editar el archivo hay que reiniciar el contenedor del scheduler para que
relea la programación (aquí la opcache no revalida timestamps) y confirmar con
`schedule:list`:

```bash
docker compose restart scheduler
docker compose exec app php artisan schedule:list
```

Las fechas de fin (`fin_suspension`, `fin_suspension_gestor`) se guardan como
`YYYY-MM-DD` a las 00:00 y la comparación es `<= ahora`. Por eso la reactivación
ocurre en la primera pasada después de la medianoche del día de fin: con
`everyTenMinutes()` son hasta 10 minutos después, no al final de ese día. Para
reactivar antes de esa fecha hay que usar el botón de la ficha (ver abajo).

Para reactivar a mano sin esperar a la pasada del scheduler (por ejemplo, tras
corregir un problema en un subsistema), usa el mismo comando que corre la tarea:

```bash
# Ver qué gestores se reactivarían, sin tocar nada
docker compose exec app php artisan gestor-users:reactivate-expired --dry-run

# Encolar la reactivación de todos los gestores con la fecha de fin vencida
docker compose exec app php artisan gestor-users:reactivate-expired
```

El comando encola la misma operación que el botón «Reactivar gestor» de la
ficha: reactiva las cuentas que se capturaron al suspenderlo y, si todas
terminan bien, devuelve al gestor a `activo` y limpia motivo y fechas. Si
alguna cuenta falla, el gestor sigue suspendido; se puede reintentar la
operación desde el historial de la ficha o volver a lanzar el comando.

Si la fecha de fin todavía no llegó, el comando no toca al gestor: para
adelantar la reactivación hay que usar el botón de la ficha.

### Operaciones atascadas

⚠️ **El comando que limpia esto no está programado.** `operations:expire-stuck`
existe y funciona, pero no aparece en `schedule:list`: no hay ningún
`Schedule::command('operations:expire-stuck')` en `routes/console.php`, y el
`schedule()` de `app/Console/Kernel.php` está vacío. Hoy solo se ejecuta si se
llama a mano.

Las consecuencias son concretas: una operación cuyo worker murió se queda
`en_curso` para siempre, el usuario que tiene en curso nunca vuelve a admitir un
alta ni una suspensión porque todos los `POST` le responden `409`, y no hay nada
en el panel que lo|Apunte. El `updated_at` de la operación sirve de latido —lo
mueve cualquier `save()` de la cabecera o de sus cuentas—, así que una operación
sana se renueva sola mientras avanza y una muerta hace tiempo que no lo hace.

Mientras no esté programado, hay que pasarlo a mano:

```bash
# Ver primero qué cerraría, sin tocar nada:
docker compose exec app php artisan operations:expire-stuck --dry-run

# Cerrarlas:
docker compose exec app php artisan operations:expire-stuck
```

Cierra como `fallida` las que llevan más de `OPERATIONS_STUCK_MINUTES` (30 por
defecto) sin actividad, y pasa sus cuentas pendientes a `error` con el motivo a
la vista, para poder reintentarlas desde la ficha del usuario.

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
job fallido —eso ya avisó— sino las operaciones `fallida` en el panel, que son
las que requieren que alguien pulse «Reintentar».

Desde el panel, la operación se puede reintentar entera desde la ficha del
usuario, que además vuelve a encolar solo las cuentas que fallaron.

`queue:flush` borra la tabla entera sin preguntar, así que úsalo solo cuando ya
se revisó qué había: es la única forma de perder el rastro de un fallo sin haber
mirado el mensaje.

Los servicios `queue` y `scheduler` arrancan a la vez que MySQL, así que en un
`docker compose up` recién hecho pueden morir alguna vez con
`Connection refused` hasta que la base esté lista. Con `restart:
unless-stopped` se levantan solos; sin esa política, esa carrera mataba el worker
para el resto de la sesión.

### Comandos propios

```bash
docker compose exec app php artisan users:create-admin        # primer administrador
docker compose exec app php artisan subsystems:test-connection # prueba todos los subsistemas activos
docker compose exec app php artisan operations:expire-stuck   # cerrar operaciones atascadas (ver arriba)
docker compose exec app php artisan secrets:rotate            # rotar APP_KEY
docker compose exec app php artisan db:backup / db:restore    # volcado cifrado
```

> El comando de prueba de subsistemas está registrado como
> `subsystems:test-conection` —con una `n` de más, el nombre con el que está
> registrado de verdad— y `subsystems:test-connection` funciona por alias.

### Recetas de Tinker

```bash
docker compose exec app php artisan tinker
```

**Cambiar la contraseña de un operador** (`User`, tabla `users`). El modelo
tiene el cast `'password' => 'hashed'`, así que asignas texto plano:

```php
$u = App\Models\User::where('email', 'admin@klios.com')->first();
$u->password = 'NovaSenha@123';
$u->save();
```

**Cambiar la de un gestor** (`GestorUser`, tabla `gestor_users`). El hash lo
pone el hook `saving()` de `GestorUser::booted()`:

```php
$g = App\Models\GestorUser::where('usuario', 'jperez')->first();
$g->password_general = 'NovaSenha@123';
$g->save();
```

> En los dos casos: **nunca** con `->update(['password' => ...])`. El `update()` de
> Eloquent va directo al query builder y no dispara ni casts ni eventos de
> modelo, así que la contraseña se guardaría en claro.
>
> Y ojo: cambiar la `password_general` del gestor **no** la propaga a las cuentas
> que ya tiene en Samba AD, GLPI o donde sea. Eso lo hace
> `UserProvisioningService`, llamando a `resetPassword()` en cada subsistema
> afectado; en el panel es el botón de la ficha del usuario
> (`POST gestor-users/{gestorUser}/reset-password`).

Crear el primer administrador, si no se hizo con `users:create-admin`:

```php
App\Models\User::create([
    'name' => 'Super Admin',
    'email' => 'admin@klios.com.br',
    'password' => 'una-clave-larga',
    'role' => 'admin',
]);
```

Limpiezas puntuales — todas irreversibles, míralas dos veces:

```php
// Cuentas de un subsistema concreto (subsystem_id = 14):
App\Models\UserSubsystemAccount::where('subsystem_id', 14)->delete();

// Un subsistema, solo si no tiene cuentas registradas:
App\Models\Subsystem::find(15)?->delete();

// Todas las operaciones de provisionamiento:
App\Models\ProvisioningOperation::query()->delete();
```

### Por qué no hay Redis

La cola, la caché y las sesiones van a MySQL (`QUEUE_CONNECTION`, `CACHE_STORE`
y `SESSION_DRIVER` están en `database`), y ningún driver usa `Redis::`
directamente. El contenedor no hacía nada, así que se quitó en el Sprint 2.3.

Si alguna vez hace falta —un caché compartido entre contenedores, locks de cola
para un worker con varias réplicas— hay que **añadir las dos cosas**: el
servicio en `docker-compose.yml` y la extensión `phpredis` en
`docker/php/Dockerfile`. Hoy la imagen solo compila `gd` y `ldap`; poner
`REDIS_HOST=redis` sin la extensión falla en runtime, no al arrancar.

---

## 6. Calidad y verificación

Las tres puertas de esta sección corren en la CI descrita abajo.

### CI

`.github/workflows/ci.yml` (en la raíz del repo, no en `src/`) corre en cada
`push` y cada `pull_request`: `pint --test`, `phpunit`, cobertura y Larastan.
Corre sobre **PHP 8.5**, la misma versión del `Dockerfile`, para que un verde en
CI signifique lo mismo que un verde en el contenedor.

La protección de rama no se configura desde un fichero: para exigir la CI en
verde antes de mergear hay que activarla en _Settings → Branches → main_ dentro
de GitHub.

### Cobertura

`php artisan test --coverage --min=80` es puerta en CI. Hoy está en **80,1 %**
global, y por directorio: `Http` 89,6 %, `Jobs` 90,0 %, `Services` 80,0 %.

Requiere **PCOV**, que no viene en la imagen a propósito: un driver de cobertura
en el contenedor que además sirve peticiones es peso que no se necesita. En local
hay que instalarlo antes de medir:

```bash
docker compose exec app sh -c 'yes "" | pecl install pcov && echo "extension=pcov.so" > /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini'
```

Eso toca el contenedor en marcha, no el `Dockerfile`, así que se pierde al
reconstruir. PCOV antes que Xdebug: la suite tarda unos 10 s con PCOV y varios
minutos con Xdebug, y para contar líneas dan lo mismo.

`--min` mide la **media global**, no por directorio: es lo que permite el
formato de `php artisan test`. El desglose por directorio hay que sacarlo a mano
con `--coverage` y es el que de verdad dice si algo está sin cubrir.

### Análisis estático (Larastan)

`phpstan.neon` fija **nivel 5** sobre `app`. El código heredado entra en
`phpstan-baseline.neon` —415 líneas, 116 errores en su mayoría de tipado de
`Collection`, que no es covariante—, así que la puerta vigila **errores nuevos**,
no los antiguos. Ese baseline se regenera a mano
(`vendor/bin/phpstan analyse --generate-baseline`), nunca se acepta en
automático.

Dos detalles que salen de haberlo montado:

- El baseline va en `includes:` de `phpstan.neon`, no como parámetro: PHPStan 2.x
  rechaza `parameters.baselineFile` con `Unexpected item`.
- `reportUnmatchedIgnoredErrors: false`, porque si no cada arreglo real del
  código deja el baseline con una entrada que ya no coincide y la CI se pone roja
  sola.

Los siguientes sprints suben un nivel; el baseline es lo que lo hace posible.

Tres cosas que la CI necesita y que no son obvias:

- **Genera `src/.env` ella misma.** `.env` está en `.gitignore`, así que no
  llega a la CI, y sin él la suite falla entera: 394 de 425 tests, casi todos,
  mueren por `APP_KEY` ausente. El paso copia `.env.example` y le inyecta una
  `APP_KEY` nueva.
- **`ldap` está en la lista de extensiones.** `SambaAdService` llama a `ldap_*`
  sin condición. Los tests no usan la extensión de verdad (la sustituyen por
  funciones declaradas en el mismo namespace, que es como PHP las resuelve antes
  que las globales), pero sin la extensión cargada el resto de la aplicación
  tampoco arranca.
- **No hay servicio de base de datos.** `phpunit.xml` fija `DB_CONNECTION=sqlite`
  con `DB_DATABASE=:memory:`.

La CI **no corre `php artisan test --parallel`**: eso exige `brianium/paratest`,
que no es dependencia del proyecto, y la suite tarda unos 8 segundos.

---

## 7. Limitaciones conocidas

- **Las rutas y campos de cada llamada HTTP dentro de cada `*Service` son un
  patrón de referencia**, no una integración probada contra el API real. Hay que
  ajustarlos a cada subsistema (Adagio, GLPI, Chatwoot, etc.).
- **`operations:expire-stuck` no está programado**, así que una operación
  bloqueada bloquea al usuario para siempre. Ver [Operaciones
  atascadas](#operaciones-atascadas).
- **El modelo `User` tiene campos de perfil** que pertenecen a `GestorUser` o que
  van a desaparecer; se moverán en fases futuras.
- **El dominio para proponer usuario y email es `empresa.com.br`**, resuelto en
  `UsernameGeneratorService::resolverDominio()`. Si hay varios dominios, eso
  tiene que pasar a ser una tabla de empresas.
- **No hay OpenAPI generado.** La referencia de la API es este README, que
  puede quedarse corto frente a los payloads reales.