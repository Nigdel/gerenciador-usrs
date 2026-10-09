# Plan de implementación — de aquí en adelante

Base: `main` @ `e0e4586` · Fecha: 2026-10-07 · Sustituye a las fases 3–6 del `todo` anterior.

**Reglas de trabajo** (de `CLAUDE.md`): todo comando PHP/Laravel con `docker compose exec app ...`;
cambios pequeños; `docker compose exec app php artisan test` y
`docker compose exec app ./vendor/bin/pint` antes de cada commit. Un commit por tarea.

**Esfuerzo:** S ≤ 0,5 d · M 1–2 d · L 3–5 d. **Total estimado:** ~5–6 semanas de trabajo efectivo.

```
Sprint 1  Cerrar la cola (3.3)          ~3 d   ← bloquea todo lo demás
Sprint 2  Robustez y decisiones         ~3 d
Sprint 3  Calidad: tests + CI           ~4 d   ← antes de añadir más funcionalidad
Sprint 4  UX: listados, validaciones    ~4 d
Sprint 5  Conciliación, avisos, idemp.  ~7 d
Sprint 6  Drivers reales + producción   ~8 d
```

Dependencias: S1 → S2 → S3 → (S4 ∥ S5) → S6. El Sprint 3 (CI) puede adelantarse en paralelo al 2.

---

## Sprint 1 — Cerrar la cola (Fase 3.3)

Objetivo: que cualquier operación se pueda consultar, reintentar y no deje secretos olvidados.

### 1.1 Caducidad de la contraseña en el payload (S)
**Problema:** `provisioning_operations.payload` (cifrado) conserva `password_general` para siempre.
**Ojo:** no se puede vaciar siempre al cerrar, porque *reintentar un alta fallida necesita esa contraseña*.
- En `ProvisioningOperationService::cerrar()`: si la operación queda `Completada`, quitar
  `password_general` del payload y guardar.
- Si queda `Fallida`, conservarla hasta que se reintente o caduque.
- Nuevo comando `operations:prune-secrets` (programado cada hora en `routes/console.php`, con
  `withoutOverlapping()->onOneServer()`) que borra `password_general` de operaciones terminadas hace más de
  `config('operations.secret_ttl_hours', 72)` horas, sean completadas o fallidas.
- Nuevo `config/operations.php` con el TTL.
**Aceptación:** tras un alta completada, `payload` no contiene `password_general`; tras un alta fallida
sí, hasta pasado el TTL; el comando soporta `--dry-run`.
**Tests:** `OperationSecretsTest` (completada limpia, fallida conserva, prune respeta TTL).

### 1.2 Reintentar cuenta fallida (M)
- `ProvisioningOperationService::reintentar(ProvisioningOperationAccount $fila)`:
  solo si `estado === Error` y la operación está terminada; pasa la fila a `Pendiente`, limpia `mensaje`,
  conserva `intentos`, deja la operación en `EnCurso` y encola con `ProcessOperationAccount::dispatch(...)->onQueue(COLA)`.
- Ruta: `POST gestor-users/{gestorUser}/operaciones/{operacion}/cuentas/{fila}/retry` →
  `GestorUserController::reintentar()`. Comprobar en la ruta que la fila pertenece a la operación y esta al
  usuario (404 si no, igual que el polling).
- Policy: reutilizar la ability de la acción original (alta/suspensión → `suspend`/`create`; baja → `offboard`;
  sync → `update`). Resolver con un `match` sobre `OperationType`.
- Vista `gestor-users/show`: botón "Reintentar" en cada fila `error`; el polling existente refresca el estado.
- Para `Baja`, no cambia nada en `aplicarEstadoDelUsuario()`: el usuario se marca de baja al cerrar con 0 errores.
**Aceptación:** una cuenta fallida se reintenta sin duplicar las ya correctas; la operación pasa a
`Completada` y, si era baja, el usuario queda `baja`.
**Tests:** `RetryOperationAccountTest` (solo `Error`, 404 cruzado, 403 por rol, baja se completa tras reintento,
no re-encola filas `Ok`).

### 1.3 Consulta de operaciones por API (S)
- `ApiAbility::Consultar = 'operaciones:consultar'` y actualizar `php artisan api:token` para aceptarla.
- Ruta `GET /api/operaciones/{uuid}` con `abilities:operaciones:consultar` →
  `Api\OperationController@show`, que devuelve `ProvisioningOperationService::serializar()` (nunca el payload).
- Decidir alcance: **recomendado** que un token solo vea operaciones con `origen = api` (evita que una
  integración lea operaciones hechas desde la web). 404 si no cumple.
- Documentar en el README: el alta devuelve `operacion_id`; se consulta con este endpoint hasta `terminado: true`.
**Tests:** en `Api/` — 401 sin token, 403 sin ability, 404 inexistente/ajena, 200 con forma esperada.

### 1.4 Una sola operación activa por usuario (M)
- En `ProvisioningOperationService::describir()`: dentro de la transacción, bloquear la fila del usuario
  (`GestorUser::lockForUpdate()`) y, si ya hay una operación `Pendiente`/`EnCurso`, lanzar
  `OperationInProgressException` (nueva, `RuntimeException`).
- Controladores web: capturar y redirigir con `error` ("Ya hay una operación en curso para este usuario").
  API: responder **409** con el `operacion_id` de la activa.
- Operaciones atascadas: comando `operations:expire-stuck` (cada 15 min) que marque como `Fallida` las
  `EnCurso` sin actividad en `config('operations.stuck_minutes', 30)`, para no bloquear al usuario eternamente.
  Añadir `updated_at`/`heartbeat` si hace falta.
**Aceptación:** dos POST seguidos sobre el mismo usuario: el segundo falla con mensaje claro; tras
`stuck_minutes` sin avance, el usuario queda desbloqueado.
**Tests:** `ConcurrentOperationsTest` (409/redirect, alta+baja simultáneas, expire-stuck).

**Hecho cuando:** ver, reintentar y consultar por API cualquier operación; ningún secreto persiste más
del TTL; no hay operaciones solapadas. Marcar 3.3 como `[x]`.

**Estado:** Sprint 1 cerrado (1.1, 1.2, 1.3 y 1.4 completados; 275 tests, 896 aserciones).

---

## Sprint 2 — Robustez y decisiones pendientes

### 2.1 Manejo uniforme de errores — 3.7 (S)
- Web: en `store()`, `resetPassword()` y demás, capturar `Throwable` (no solo `RuntimeException`), hacer
  `report($e)` y mostrar un mensaje genérico; mantener el mensaje específico solo para excepciones de dominio.
- Crear `App\Exceptions\ProvisioningException` (y la de 1.4) para los errores *esperables*; cualquier otra
  cosa → mensaje genérico sin detalles de infraestructura.
- API (`UserProvisioningController`, `UserSuspensionController`): `try/catch` equivalente → 422 (datos),
  409 (operación en curso), 502 (subsistema/identidad caída), nunca 500 con traza.
**Tests:** forzar una `Exception` en el registry y comprobar respuesta controlada en web y API.

### 2.2 Reset de contraseña: decisión + registro (S ahora, M después)
Hoy es síncrono y no deja rastro en `provisioning_operations`.
- **Ahora (recomendado):** mantenerlo síncrono a propósito, pero registrarlo como operación
  `OperationType::ResetPassword` ya cerrada (con resultado por cuenta, **sin** contraseña en el payload)
  para que aparezca en el panel y en el listado general. Documentar la decisión en el código.
- **Después (junto con 3.6):** moverlo a cola cuando haya canal seguro de entrega de la contraseña temporal.
**Tests:** ampliar `ResetPasswordTest` para verificar que se crea la operación y que no guarda la clave.

### 2.3 Redis: usar o quitar (S)
- **Recomendado:** quitar el servicio `redis` de `docker-compose.yml` (y `REDIS_*` si no se usan) mientras
  cola/caché/sesión sigan en `database`; reevaluar en el Sprint 6 si el volumen lo pide.
- Alternativa: pasar `QUEUE_CONNECTION` y `CACHE_STORE` a `redis` (verificar que `phpredis` está en
  `docker/php/Dockerfile`).

### 2.4 Operación del worker (S)
- Documentar `docker compose exec app php artisan queue:restart` tras cada despliegue.
- Vigilar `failed_jobs`: comando/consulta en el runbook (`php artisan queue:failed`).

**Estado:** hecho. Runbook en el README. Al verificarlo se encontró que **ningún
servicio tenía política `restart`**: `queue:restart` deja el worker parado y sin
nadie que lo levante, así que la web seguía aceptando altas que nadie
ejecutaba. Añadido `restart: unless-stopped` a app, nginx, mysql, queue y
scheduler (no a `assets`, que es un job de un solo uso). Verificado el ciclo
completo: 3 intentos con backoff → `failed_jobs` → fila en `error` con el
mensaje → operación `fallida`.

### 2.5 Higiene del repositorio y configuración (S)
- Borrar `update_tests.php`.
- Mover el plan a `to-do/` (`README.md`, `plan-implementacion.md`, estado) y arreglar el `README.md` raíz.
- `.env.example`: `APP_DEBUG=false`, `APP_LOCALE=es`/`pt_BR` según el idioma de la UI, dejar los IDs de
  tenant/cliente de Entra ID sin valor por defecto.

**Estado:** hecho. `update_tests.php` borrado (era un script de un solo uso que
ya no hacía falta y que además metía un `actingAs` global en tests que no lo
pedían); el plan y el análisis movidos a `to-do/`; README raíz reescrito —estaba
roto, empezaba por un enlace a una imagen de GitHub y el índice apuntaba a un
fichero llamado `todo` sin extensión—; `.env.example` con `APP_DEBUG=false`,
locale `es` (la UI está en español literal, sin carpeta `lang/`) y los tenant y
client_id de Entra ID sin valor por defecto. El `.env` local se ajustó igual, para
que no quedara más privilegiado que el ejemplo que se copia.

---

## Sprint 3 — Calidad: tests y CI

### 3.1 Tests de la cola (M)
Ficheros nuevos en `tests/Feature/Operations/`:
- `ProvisioningOperationServiceTest`: `describir` (cabecera + una fila por unidad; sin trabajo → `Completada`),
  `despachar` (solo `Pendiente`), `cerrar` (contadores, `Fallida` con errores), `aplicarEstadoDelUsuario`
  (baja solo con 0 errores; reactivación devuelve a `activo`).
- `ProcessOperationAccountTest`: guard `estado !== Pendiente`, usuario borrado, cuenta borrada, excepción →
  reintento, `failed()` deja la fila en `Error` y cierra la operación, `intentos` incrementa.
- `OperationPollingTest`: endpoint web (404 cruzado, sin payload en la respuesta).
Usar `Queue::fake()` para encolado y `dispatchSync`/ejecución directa del job para los casos de ejecución.

### 3.2 Tests de API completos (S)
Extender `Api/`: 201 con `operacion_id` y filas `pendiente`, 422 de validación, 401/403, 409 (Sprint 1),
`login_no_verificado` presente en la respuesta.

**Estado:** hecho. `Api/ProvisioningContractTest.php` cubre el cuerpo del 201 (una fila por subsistema,
`operacion_id` real, la contraseña general fuera de la respuesta), el 422 (validación de campos, el
`nombre_completo`/`empresa` que exige un CPF nuevo, el usuario inexistente), el 409 y el 502 sin detalle.
`login_no_verificado` se comprueba presente-aunque-sea-null, que es lo que evita que una integración se
lo lea con `??` y nunca se entere de que faltó. Los 401/403 ya estaban en `ApiAuthenticationTest` y el
409 en `ConcurrentOperationsTest`; aquí solo se repite el código desde el punto de vista del endpoint.

Al hacerlo apareció un fallo: `localizarUsuario()` usa `firstOrFail()`, y esa excepción caía en el
`Throwable` del trait y se respondía 502 —«servicio caído, reintenta»— para un CPF que no existe.
Reintentar eso no funciona nunca, así que ahora es un 422. El 502 queda para lo que sí es
infraestructura, y `ErrorHandlingTest` comprueba las dos mitades.

### 3.3 Tests por driver (M)
Contrato completo por subsistema con `Http::fake` (y LDAP simulado para Samba): `createUser`, `suspendUser`,
`reactivateUser`, `disableUser`, `getUserStatus`, `resetPassword`, `updateUser`, `loginEnUso`.
Casos con regla propia: Chatwoot (DELETE y 404/410 = `deshabilitado`, reactivar = recrear), Adagio (404 = login libre),
Samba (`ldap_rename` fallido devuelve éxito con aviso).

**Estado:** hecho. Los ocho métodos quedan cubiertos en los siete drivers (425 tests, 1247 aserciones).
Las tres reglas con regla propia del plan ya estaban cubiertas de antes: Chatwoot en `DisableUserTest` /
`EnableUserTest` / `GetUserStatusTest`, Adagio en `Adagio/LoginAvailabilityTest`, y el `ldap_rename`
fallido en `SambaAd/UpdateUserTest`. Lo que faltaba de verdad eran `updateUser()` y `loginEnUso()`.

- **Samba era el agujero grande**: ningún test había pasado nunca del guard de configuración, así que
  el enmascarado de `userAccountControl`, la resolución de DN y el `ldap_rename` no tenían cobertura.
  `SambaAd/fakes-ldap.php` declara las funciones `ldap_*()` en `namespace App\Services\Subsystems`, que es
  como PHP las resuelve antes que las globales, y `SambaAdTestCase` las carga con `require_once`.
  No se pueden poner como métodos estáticos de una clase: PHP nunca consulta ahí.
- **Adagio tenía dos fallos de producción**, ahora cubiertos por tests de regresión:
  `send()` no tenía brazo `PUT` en su `match` por método, así que `updateUser()` —que es un PUT— lanzaba
  `RuntimeException` siempre y la actualización del propietario nunca funcionó (siempre 502); y
  `loginEnUso()` capturaba `RuntimeException`, que no puede casar con `HttpClient\ConnectionException`
  (hereda de `HttpClientException`), así que la caída de red se escapaba al controlador en vez de
  devolverse el `null` de «no comprobable». El `catch` ahora es `\Throwable`.
- Un caso que salió mal por partida propia: `Http::fake()` con array **no registra `PUT`**, así que el
  PUT de `updateUser()` se escapa a la red real y en CI eso es un timeout de DNS, no un fallo claro.
  Los fakes de Adagio y Entra ID son closures, que sí interceptan todos los verbos.

### 3.4 CI con GitHub Actions (M)
`.github/workflows/ci.yml` en `push` y `pull_request`:
1. `shivammathur/setup-php` (PHP 8.3, extensiones `ldap`, `pdo_mysql`, `redis` si aplica).
2. Servicio MySQL 8.4 (o SQLite si `phpunit.xml` lo permite).
3. `composer install --no-interaction --prefer-dist` en `src/`.
4. `./vendor/bin/pint --test`.
5. `./vendor/bin/phpstan analyse` (ver 3.5).
6. `php artisan test --parallel`.
Cachear Composer. Protección de rama: exigir CI en verde para `main`.

### 3.5 Larastan (S)
`docker compose exec app composer require --dev larastan/larastan`; `phpstan.neon` con nivel 5, `paths: [app]`,
baseline inicial para no bloquear; subir un nivel por sprint.

**Estado (3.4 + 3.5):** hechos. `larastan/larastan ^3.13` (PHPStan 2.3.0); `phpstan.neon`
en nivel 5 y `phpstan-baseline.neon` con los 116 errores heredados. Tres desviaciones
del plan, por no encajar con el repo: PHP 8.5 en vez de 8.3 (el `Dockerfile` es
`php:8.5-fpm`), sin servicio MySQL (`phpunit.xml` fija sqlite `:memory:`) y sin
`--parallel` (falta ParaTest y la suite tarda 8 s).

Lo que costó descubrir:
- **La suite no corre sin `.env`**: sin él, 394 de 425 tests mueren por `APP_KEY`
  ausente. La CI copia `.env.example` e inyecta una `APP_KEY` nueva.
- **El baseline va en `includes:`, no en `parameters.baselineFile`** — PHPStan 2.x
  rechaza ese parámetro. Con él, el código heredado no bloquea y la puerta solo
  vigila errores nuevos.
- Los 116 errores del baseline se revisaron antes de aceptarlos y ninguno era un
  defecto: sobre todo tipado de `Collection` (que no es covariante) y `Model::$slug`,
  que es inferencia genérica de Larastan — las relaciones existen.
- `pint --test` fallaba en 5 ficheros heredados; se corrigieron en 3.4, porque un
  gate de estilo rojo desde el primer día bloquea todos los PR.

Pendiente del Sprint 3, cerrado: cobertura ≥ 80 % medida con **PCOV** (78,1 % →
**80,1 %**). `Http` 89,6 %, `Jobs` 90,0 %, `Services` 80,0 %.

El hueco era uno solo y grande: `SambaAdService::createUser()` tenía 100 líneas
sin cubrir —crear, fijar contraseña, habilitar, con rollback en los dos últimos
pasos— porque su `CreateUserTest` solo comprobaba la guarda de validación. Cubierto
con 15 tests, el servicio pasa de 52,6 % a 73,1 %.

Dos cosas que hacen falta para poder medirlo dos veces:

- **PCOV no va en la imagen.** Va en el job de CI, que ya instala sus extensiones.
  En local se instala a mano en el contenedor; se pierde al reconstruir, y da
  igual porque solo se usa para medir.
- **`--min` mide la media global**, no por directorio, que es lo que permite el
  formato de `php artisan test`. El desglose hay que sacarlo a mano.

**Hecho cuando:** CI verde en `main`; cobertura ≥ 80 % en `app/Services`, `app/Jobs` y `app/Http`
(medir con `php artisan test --coverage`, requiere Xdebug/PCOV en la imagen).

---

## Sprint 4 — Experiencia de uso (puede ir en paralelo al 5)

### 4.1 Listados con paginación, búsqueda y filtros (S–M)
- `GestorUserController::index()` y `SubsystemController::index()`: `paginate(25)->withQueryString()`.
- Scopes en `GestorUser`: `buscar($q)` (nombre, CPF, usuario, empresa), `delEstado($estado)`,
  `enSubsistema($slug)`; filtros por `Request` validado.
- Vistas: barra de búsqueda + selects de estado/subsistema + paginación; conservar filtros al navegar.
**Tests:** búsqueda por cada campo, combinación de filtros, paginación estable.

**Estado (4.1):** cerrado. Scopes `buscar`/`delEstado`/`enSubsistema`, `GestorUserListRequest` con
`Rule::enum`, paginación con `withQueryString()` en los dos listados y barra de filtros en las vistas.
La barra reutiliza las clases existentes (`.field`, `button button-primary`) en vez de inventar un
estilo de inputs nuevo, porque en este proyecto los inputs se estilizan por su `.field` contenedor.
El estado vacío distingue "no hay usuarios" de "ninguno coincide con el filtro", para que filtrar a
cero no parezca pérdida de datos.
La parte de 4.2 que toca el CPF también se hizo aquí, porque la búsqueda por CPF sobre una columna
unique con dos formatos mezclados no puede funcionar de forma fiable antes de fijarlo (ver 4.2).

### 4.2 Validaciones (S)
- Regla `App\Rules\Cpf` (dígitos verificadores, rechaza secuencias repetidas) y normalización a solo dígitos
  en `prepareForValidation()` de `GestorUserRequest` y `ProvisionUserRequest`. Ojo: el CPF es clave de
  búsqueda en Adagio y `unique` local; decidir un único formato de almacenamiento y migrar los existentes.
  **Decidido y hecho:** formato único de once dígitos. La migración
  `2026_10_07_100000_normalize_gestor_users_cpf` quita la puntuación y **aborta con un mensaje que
  lista los ids en conflicto** antes de escribir nada, en vez de reventar a mitad de la actualización
  con un error de índice que no dice qué filas son. Su `down()` es no-op a propósito: la puntuación
  original no se puede recuperar.
  Al validar esto destapó que el CPF de ejemplo de los tests (`12345678901`) no era válido — falla sus
  propios dígitos verificadores. Se corrigió a `12345678909` en 34 ficheros de test.
- `SuspendGestorUserRequest`: `fin_suspension` con `after_or_equal:inicio_suspension`.
  `after_or_equal` ya estaba, pero solo compara si el inicio viene en el request: si el gestor
  rellenaba solo la fecha de fin, la validación pasaba y el servicio asumía `inicio = now()`, dejando
  la suspensión con un fin anterior a su propio inicio. Se añade `required_with:fin_suspension`.
  **Sin `sometimes` delante a propósito**: ese modificador solo aplica las reglas si la clave viene en
  el request, que es justo el caso que hay que detectar. Con él, `required_with` no se dispara nunca.
  Lo mismo con `nullable`, que cortocircuita la regla.
- Unicidad de `usuario` en edición: `Rule::unique()->ignore()`. La columna era UNIQUE en base de datos
  desde la migración inicial pero no se validaba, así que editar a un login ya usado por otro usuario
  reventaba con `QueryException` en vez de volver al formulario con un error.
  `ProvisionUserRequest` **no** lleva la regla: allí el servicio reutiliza un usuario ya existente
  buscado por CPF o genera el login, y un `unique` sobre el payload rechazaría el caso de reutilización.

**Estado (4.2):** cerrado.

### 4.3 Listado general de operaciones (M)
Pantalla `operaciones.index` (solo `admin`/`operador`/`auditor` según policy): filtro por estado, tipo, usuario
y rango de fechas; enlace a la ficha del usuario; acceso rápido a "solo fallidas" con reintento (Sprint 1.2).

### 4.4 Dashboard con métricas (M)
Sustituir el lanzador por tarjetas: usuarios por estado, cuentas por estado y subsistema, operaciones
fallidas/en curso, suspensiones que vencen en 7 días, última prueba de conexión por subsistema
(`last_connection_test_*`). Consultas agregadas con caché corta (60 s).

### 4.5 Importación/exportación CSV (M, opcional)
Alta masiva con vista previa, validación por fila y reporte de errores; cada fila genera una operación.
Exportación de usuarios con filtros aplicados.

---

## Sprint 5 — Conciliación, notificaciones e idempotencia

### 5.1 Conciliación — 3.5 (M)
- Comando `accounts:reconcile` (diario, `withoutOverlapping()->onOneServer()`, con `--subsystem=` y `--dry-run`):
  para cada cuenta con estado estable (`activo`/`suspendido`/`deshabilitado`), llamar a `getUserStatus()` y
  comparar con el local; limitar el ritmo por subsistema para no saturar APIs.
- Tabla `account_discrepancies` (cuenta, estado local, estado remoto, detectada_at, resuelta_at, resolución).
- Pantalla "Discrepancias" con acciones: *aceptar remoto* (actualizar local) o *forzar local* (reaplicar
  la operación). Registrar la resolución en `account_state_logs` con origen del actor.
- No tocar cuentas con operación activa (usa la guarda del 1.4).

### 5.2 Notificaciones — 3.6 (M)
- Mailables encolados en la cola `default`: credenciales iniciales (al `email_personal`), suspensión,
  reactivación, baja. Plantillas Blade en el idioma de la UI.
- **Entrega de contraseñas:** nunca en logs ni en `payload` persistente; el correo se genera en el job del alta
  con la contraseña en memoria. Cambiar `MAIL_MAILER` a un transporte real en producción (no `log`).
- Preferencias: activar/desactivar por tipo en `config/notifications.php`.
- Con esto se puede mover el reset de contraseña a cola (cerrar 2.2 "después").

### 5.3 Idempotencia y compensación — 3.4 (M)
- API: cabecera `Idempotency-Key` en `/provisionar` y `/suspender`; tabla `idempotency_keys`
  (clave + hash del payload + `operacion_id` + respuesta); misma clave → misma respuesta; misma clave con
  payload distinto → 422.
- Fallo "el driver actuó pero no se guardó el resultado": antes de re-ejecutar una fila con `intentos > 0`,
  consultar `getUserStatus()`/existencia en el subsistema y, si ya está en el estado objetivo, marcarla `Ok`
  sin repetir.
- Política de fallo parcial del alta (decidir con el negocio): *reintentar* (por defecto, ya soportado),
  *dejar en error* o *revertir* con `deleteUser()` en las cuentas ya creadas (opción por operación).

### 5.4 Auditoría general — resto del 3.1 (S–M)
Registrar acciones de gestión (alta/edición/baja de `GestorUser`, cambios en subsistemas y en operadores),
IP y user-agent del llamante en operaciones y logs de cuenta, e intentos de login fallidos.

---

## Sprint 6 — Drivers reales y producción

### 6.1 Drivers genéricos (M–L) (pendiente, opcional)
`EmailService` y `SlackService` apuntan a endpoints de referencia: obtener la API real, ajustar rutas/campos,
cubrir con tests de contrato (Sprint 3.3). Confirmar `AdagioService::disableUser()` (hoy `DELETE`,
marcado "endpoint no confirmado").

### 6.2 Aviso de baja irreversible en Chatwoot (S) (pendiente, opcional)
En la ficha, si el usuario tiene cuenta de Chatwoot, mostrar antes de "Dar de baja" que se elimina al agente y
se pierde su historial; la reactivación lo recrea.

### 6.3 Perfil de producción (M) (hecho)
`docker-compose.prod.yml`: sin puerto MySQL publicado, credenciales por variables/secretos, `restart:
unless-stopped`, healthchecks (app, mysql, nginx), `APP_DEBUG=false`, `scheduler` con
`schedule:run` por cron o `schedule:work` supervisado, `queue:work` bajo supervisor con `--max-time` y
`queue:restart` en el despliegue. HTTPS delante (proxy).

### 6.4 Secretos (S) (hecho)
Sin valores por defecto en Compose; procedimiento de rotación. **Aviso:** rotar `APP_KEY` invalida todo lo
cifrado (`api_config` y `payload`); documentar un procedimiento de re-cifrado antes de hacerlo.

### 6.5 Backups y restauración (S)
Volcado programado de MySQL (cifrado, retención definida) y **prueba de restauración** documentada.

### 6.6 Observabilidad (M)
Logs estructurados (JSON) a stdout; canal de errores (Sentry/Flare o similar); alertas por `failed_jobs`,
operaciones `Fallida`, discrepancias nuevas y fallo de `testConnection` en subsistemas.

### 6.7 Documentación (S)
OpenAPI de la API (incluye `operacion_id`, `GET /operaciones/{uuid}`, 409, `Idempotency-Key`) y runbook:
alta, suspensión, baja, reintento, reconciliación, `queue:restart`, qué hacer ante cola atascada y
procedimiento de backup/restauración.

### 6.8 Detalles visuales (S)
Pasada final de espaciado/alineación en `gestor-users` y `subsystems`. Recordar: las clases `account-status-*`
solo existen en el CSS compilado; los estilos nuevos van en `resources/css/app.css`.

---

## Riesgos y decisiones abiertas

| # | Tema | Decisión necesaria | Cuándo |
|---|---|---|---|
| D1 | TTL de secretos en payload | ¿72 h es aceptable? | Sprint 1 |
| D2 | Alcance del token API en consultas | ¿solo operaciones `origen=api`? | Sprint 1 |
| D3 | Reset de contraseña en cola | Esperar al canal de entrega (5.2) | Sprint 2→5 |
| D4 | Formato único de CPF en BD | Solo dígitos + migración de existentes | Sprint 4 |
| D5 | Política de fallo parcial en alta | Reintentar / error / revertir | Sprint 5 |
| D6 | Redis | Mantener `database` o pasar a Redis | Sprint 2 / 6 |
| D7 | Rotación de `APP_KEY` | Procedimiento de re-cifrado | Sprint 6 |

## Definición de "terminado" del proyecto

- Todas las acciones (alta, suspensión, reactivación, reset, baja, sincronización) disponibles por web y API,
  con resultado consultable y reintento de lo fallido.
- Acceso autenticado y autorizado; toda acción de cuentas y de gestión auditada.
- Estado local conciliado con el remoto; discrepancias visibles y resolubles.
- Notificaciones activas; ningún secreto persiste más allá de su TTL.
- CI en verde (Pint, Larastan, tests), cobertura ≥ 80 % en servicios, jobs y HTTP.
- Despliegue de producción reproducible, con backups probados y runbook.
