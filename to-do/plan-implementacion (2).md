# Plan de implementación

Objetivo: completar el gestor de usuarios (alta, modificación, suspensión, reactivación, restablecimiento de
contraseña y baja en todos los subsistemas) con acceso seguro, trazabilidad y operación fiable.

Reglas de trabajo (ver `CLAUDE.md`): comandos siempre con `docker compose exec app ...`; cambios pequeños;
ejecutar `docker compose exec app php artisan test` antes de cerrar cada tarea; formato con
`docker compose exec app ./vendor/bin/pint`.

Leyenda de esfuerzo: **S** ≤ 0,5 día · **M** 1–2 días · **L** 3–5 días.

---

## Fase 0 — Correcciones críticas (bloqueantes)

- [ ] **0.1 Registrar la API** (S) — añadir `api: __DIR__.'/../routes/api.php'` en `bootstrap/app.php`.
  - Aceptación: `docker compose exec app php artisan route:list --path=api` lista `provisionar` y `suspender`.
  - Test: feature test que hace `POST /api/usuarios/provisionar` y `/suspender` (con `Http::fake`).
- [ ] **0.2 Arreglar el reset de contraseña** (M) — ver Fase 2.4; mientras tanto, hacer que el controlador
  muestre error real en lugar de éxito.
- [ ] **0.3 Limpieza** (S) — eliminar `tests/Feature/UserT.php.bck`, sustituir `ExampleTest` por tests reales.
- [ ] **0.4 Decidir el rol de `User` vs `GestorUser`** (S, decisión) — recomendado: `User` = operador del
  sistema (login/roles); `GestorUser` = identidad gestionada. Documentar en `README.md` y quitar campos
  duplicados de `users` si procede.

## Fase 1 — Seguridad: autenticación y autorización

- [ ] **1.1 Login para la web** (M) — Laravel Breeze/Fortify o login propio sobre `User`; proteger todas las
  rutas web con `auth`; rate limiting en login.
- [ ] **1.2 Roles y permisos** (M) — roles mínimos: `admin` (todo), `operador` (alta/suspensión/reset),
  `auditor` (solo lectura). Implementar con Policies/Gates sobre `GestorUser`, `Subsystem`,
  `UserSubsystemAccount`.
- [ ] **1.3 Autenticación de la API** (M) — Laravel Sanctum con tokens por integración y *abilities*
  (`usuarios:provisionar`, `usuarios:suspender`); `throttle` en `/api`.
- [ ] **1.4 Acciones que cambian estado solo por POST** (S) — convertir `gestor-users/{id}/reset-password` a
  `POST` con CSRF.
- [ ] **1.5 Cifrado de secretos** (S) — cast `encrypted:array` en `Subsystem::api_config`; migración que
  re-cifra datos existentes; ocultar `api_config` en respuestas y vistas.
  - Aceptación: usuario sin permiso recibe 403; petición API sin token recibe 401.

## Fase 2 — Ciclo de vida completo del usuario

- [ ] **2.1 Suspensión en la web** (M) — formulario en `gestor-users/show` (motivo, inicio, fin, selección de
  subsistemas) que reutilice `UserSuspensionService`; mostrar inicio/fin/motivo por cuenta.
- [ ] **2.2 Reactivación automática** (M) — comando `accounts:reactivate-expired` que busque cuentas
  `suspendido` con `fin_suspension <= now()` y llame a `reactivateUser`; programarlo con el scheduler
  (`routes/console.php`, cada 5–15 min). Añadir servicio `scheduler` en Docker Compose
  (`php artisan schedule:work`).
- [ ] **2.3 Suspensión programada** (M) — si `inicio_suspension` está en el futuro, no suspender aún: dejarla
  `pendiente` y que el scheduler la aplique al llegar la fecha.
- [ ] **2.4 Restablecimiento de contraseña** (M)
  - Implementar `UserProvisioningService::resetPassword()` resolviendo el driver con el registry y llamando a
    `resetPassword($account, $newPassword)`.
  - Generar contraseña temporal segura (o aceptar una indicada) y **mostrarla/entregarla una sola vez**;
    resolver también P4 (contraseña aleatoria en el alta).
  - Botón en la vista `show` (por cuenta y "todas"), con resultado por subsistema.
- [ ] **2.5 Limpieza de suspensión al habilitar** (S) — al reactivar, poner a `null` `inicio/fin/motivo`.
- [ ] **2.6 Baja completa (offboarding)** (M) — acción "Dar de baja": deshabilitar (o eliminar si
  `supportsDeleteUser()`) en todos los subsistemas, con resumen por cuenta y estado final del `GestorUser`
  (nuevo campo `estado`: activo / suspendido / baja). Permitir borrado lógico (`SoftDeletes`).
- [ ] **2.7 Modificación propagada** (M) — al editar nombre/teléfono/email, ofrecer "sincronizar con
  subsistemas" (nuevo método opcional del contrato, p. ej. `updateUser`).
- [ ] **2.8 Generador de usuario robusto** (S) — más de 2 intentos (sufijo numérico), validar disponibilidad
  contra todos los subsistemas que implementen `existsByEmail`, no solo Adagio.

## Fase 3 — Robustez y trazabilidad

- [ ] **3.1 Auditoría** (M) — tabla `audit_logs` (actor, acción, gestor_user_id, subsystem_id, resultado,
  payload sin secretos, IP, fecha) alimentada desde los orquestadores y controladores; vista de consulta con
  filtros.
- [ ] **3.2 Colas** (L) — mover la creación/suspensión por subsistema a *jobs* (`ShouldQueue`) con reintentos y
  *backoff*; servicio `queue` en Docker Compose (`php artisan queue:work`). La petición responde con un
  identificador de operación y estado por cuenta (`pendiente`/`ok`/`error`).
- [ ] **3.3 Persistir resultados** (M) — tabla `provisioning_operations` (+ detalle por cuenta) en lugar de
  flash; botón "Reintentar cuenta fallida".
- [ ] **3.4 Idempotencia y compensación** (M) — clave de idempotencia en la API; política definida ante fallo
  parcial (reintentar / marcar `error` / revertir opcional con `deleteUser`).
- [ ] **3.5 Conciliación** (M) — comando programado que consulte `getUserStatus` de cada cuenta y registre
  divergencias; pantalla "Discrepancias" con acción de corregir.
- [ ] **3.6 Notificaciones** (M) — enviar credenciales iniciales al `email_personal`, aviso de suspensión y de
  reactivación (Mailables encolados, plantillas configurables).
- [ ] **3.7 Manejo uniforme de errores** (S) — capturar `Throwable` (no solo `RuntimeException`) en
  `GestorUserController` y orquestadores; mensajes sin datos sensibles.

## Fase 4 — Experiencia de uso

- [ ] **4.1 Listados** (S) — paginación, búsqueda (nombre, CPF, usuario, empresa) y filtros por estado y
  subsistema en `gestor-users` y `subsystems`.
- [ ] **4.2 Dashboard** (M) — totales por estado, cuentas con error, suspensiones por vencer, última prueba de
  conexión por subsistema.
- [ ] **4.3 Validaciones** (S) — validar dígitos verificadores del CPF y normalizar formato; unicidad de
  `usuario`; validar `fin_suspension >= inicio_suspension` *(verificar en los FormRequests)*.
- [ ] **4.4 Importación/exportación CSV** (M, opcional) — alta masiva con vista previa y reporte de errores.
- [ ] **4.5 Ajustar drivers genéricos** (M–L) — `EmailService` y `SlackService` apuntan a endpoints de
  referencia; adaptarlos a la API real y cubrirlos con tests contra respuestas simuladas.

## Fase 5 — Calidad y CI

- [ ] **5.1 Tests de orquestadores** (M) — `UserProvisioningService` (CPF existente/no existente, subsistemas
  filtrados, fallo parcial), `UserSuspensionService` (confirmación remota, ya suspendido) y
  `UsernameGeneratorService`.
- [ ] **5.2 Tests de API** (S) — 401/403/422/201 para provisionar y suspender.
- [ ] **5.3 Tests por driver** (M) — contrato completo (create, suspend, reactivate, disable, delete, status,
  reset) con `Http::fake`/LDAP simulado.
- [ ] **5.4 GitHub Actions** (M) — workflow que levante Compose o use la imagen PHP, ejecute `pint --test`,
  `phpstan/larastan` y `php artisan test`.
- [ ] **5.5 Análisis estático** (S) — añadir Larastan (nivel 5 → subir gradualmente).
- Meta: cobertura ≥ 80 % en `app/Services` y `app/Http`.

## Fase 6 — Producción

- [ ] **6.1 Perfil de producción** (M) — `docker-compose.prod.yml` (sin puertos de MySQL expuestos, `restart`,
  healthchecks, `APP_DEBUG=false`, servicios `queue` y `scheduler`).
- [ ] **6.2 Gestión de secretos** (S) — sin credenciales por defecto, variables por entorno, rotación documentada.
- [ ] **6.3 Backups y restauración** (S) — volcado programado de MySQL y prueba de restauración.
- [ ] **6.4 Observabilidad** (M) — logs estructurados, canal de errores, alertas ante fallos de conexión de
  subsistemas y de colas.
- [ ] **6.5 Documentación** (S) — OpenAPI de la API, runbook (alta, suspensión, baja, qué hacer ante fallos),
  actualizar `README.md`.

---

## Orden recomendado

1. **Fase 0** completa (rápida y desbloquea la API).
2. **Fase 1** antes de exponer nada fuera del entorno local.
3. **Fase 2** (2.4 → 2.1 → 2.2 → 2.6) para cerrar el ciclo de vida funcional.
4. **Fase 3.1 y 3.2** (auditoría y colas) antes de usar el sistema con volumen real.
5. **Fases 4–6** en paralelo según prioridad; **5.4 (CI)** conviene adelantarlo tras la Fase 0.

## Definición de "terminado"

- Alta, suspensión (inmediata y programada), reactivación automática, reset de contraseña y baja funcionan
  desde web y API, sobre todos los subsistemas activos.
- Todo acceso requiere autenticación y permisos; toda acción queda auditada.
- Fallos parciales son visibles y reintentables; el estado local se concilia con el remoto.
- Pipeline de CI en verde y despliegue de producción documentado.
