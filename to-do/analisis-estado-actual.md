# Análisis del estado actual

Revisión: 2026-10-09 · Rama `main` · Base `ded66b5` (`Implement Secrets rotation command and SecretService`).

## Validación ejecutada

- `docker compose exec app php artisan test --compact`: **458 tests, 1341 aserciones, todos pasan**.
- `docker compose exec app ./vendor/bin/pint --test`: **falla**, 11 archivos tienen problemas de formato.
- `docker compose exec app ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`: **falla con 4 errores**: un tipo `view-string` y tres llamadas a `env()` fuera de configuración.
- El último [CI de GitHub Actions](https://github.com/Nigdel/gerenciador-usrs/actions/runs/37938577058) falla en Pint; por eso no ejecuta los pasos de tests, cobertura ni Larastan. Los tests sí se ejecutaron localmente dentro del contenedor.

## Estado funcional

### Implementado y cubierto por pruebas

- Aplicación Laravel en Docker Compose con PHP-FPM, Nginx, MySQL, worker, scheduler y compilación de assets.
- Login web, roles y Policies; API con Sanctum y abilities.
- Alta y operaciones por cuenta en cola; suspensión inmediata/programada, reactivación automática, baja, sincronización y restablecimiento de contraseña.
- Persistencia y consulta web/API de operaciones, bloqueo de operaciones concurrentes, reintento de cuentas fallidas y expiración/poda de secretos.
- Cifrado de `Subsystem::api_config`, validación/normalización de CPF, disponibilidad de usuario y manejo de errores en web/API.
- Búsqueda, filtros y paginación de usuarios y subsistemas; listado de operaciones; dashboard básico.
- Tests de servicios, operaciones y drivers Adagio, GLPI, Chatwoot, Email, Entra ID, Samba AD y Slack.
- CI configurado con Pint, tests, cobertura y Larastan, aunque el último run está rojo.

### Parcial, incompleto o no verificado

| Área | Estado comprobado |
|---|---|
| Auditoría general | Existe tabla/servicio y se registran intentos fallidos de login. El servicio no está integrado en las acciones generales de administración; no equivale a una auditoría completa. |
| Conciliación | Existen modelo, tabla y tarea diaria, pero `AccountsReconcileCommand::handle()` está vacío. No detecta ni resuelve discrepancias. |
| Idempotencia API | Hay tabla, modelo y servicio, pero los middlewares actuales solo continúan la petición y no están asociados a las rutas; no protege las operaciones. |
| Perfil de producción | `docker-compose.prod.yml` no es desplegable tal como está: referencia un `Dockerfile` raíz que no existe y publica el servicio PHP-FPM como si atendiera HTTP. |
| Gestión/rotación de secretos | Hay un comando de rotación de `APP_KEY`, pero rotarla invalida los datos cifrados existentes y el comando solo actualiza `.env`. `SecretService` usa `env()` directamente y Larastan lo reporta. |
| Prueba de rotación | `tests/Feature/Console/SecretsRotateTest.php` está fuera de `src/tests`, que es el testsuite de `src/phpunit.xml`; no se ejecuta con `php artisan test` dentro de `app`. |
| Dashboard | Implementación básica; faltan métricas operativas completas y alertas. |
| Drivers Email/Slack | Son adaptadores genéricos; falta confirmar y probar sus APIs reales. |

## Pendientes prioritarios

1. Recuperar CI: corregir los 11 hallazgos de Pint y los 4 errores de Larastan; volver a ejecutar workflow completo, incluidos cobertura y tests.
2. Implementar de verdad conciliación e idempotencia; añadir tests en `src/tests` y eliminar o conectar los middlewares vacíos.
3. Hacer que auditoría registre las acciones administrativas relevantes y probarlo.
4. Rehacer el perfil de producción a partir de los servicios e imagen existentes; validar build y arranque reales.
5. Definir una rotación segura de `APP_KEY` compatible con `api_config` y payloads cifrados; mover su test al testsuite activo y leer secretos desde configuración.
6. Añadir backup y prueba de restauración, notificaciones y documentación OpenAPI/runbook completa.
7. Confirmar APIs reales de Email/Slack y el comportamiento de deshabilitación de Adagio antes de producción.

## Matriz resumida

| Capacidad | Estado |
|---|---|
| Autenticación, autorización y abilities API | Implementado |
| Ciclo de vida del usuario y operaciones asíncronas | Implementado; suite de tests pasa |
| Persistencia, consulta, reintento y secretos de operaciones | Implementado y cubierto |
| Listados y validaciones | Implementado |
| Tests de drivers y orquestación | Amplios; 458 tests pasan en ejecución local |
| Pint, Larastan y CI | Configurados, pero no pasan actualmente |
| Auditoría administrativa | Parcial |
| Conciliación de cuentas | No implementada; comando vacío |
| Idempotencia en la API | No operativa; middleware sin lógica y rutas sin integración |
| Perfil de producción | No desplegable todavía |
| Rotación segura de secretos | Parcial |
| Notificaciones, backups y OpenAPI | Pendientes |
