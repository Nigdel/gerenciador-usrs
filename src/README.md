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
