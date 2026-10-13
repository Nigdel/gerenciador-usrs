# Plan de implementación — Trabajo Pendiente

Revisión: 2026-10-13 · Base: `main`

**Reglas de trabajo** (de `CLAUDE.md`): todo comando PHP/Laravel con `docker compose exec app ...`; cambios pequeños; `docker compose exec app php artisan test` y `docker compose exec app ./vendor/bin/pint` antes de cada commit. Un commit por tarea.

**Validación actual:** `docker compose exec app php artisan test --compact` pasa. Pint y Larastan presentan errores pendientes que bloquean la CI.

```
Sprint 3  Tests y CI                    🟡 gates de calidad fallan
Sprint 4  UX y listados                 🟡 dashboard incompleto
Sprint 5  Conciliación/Idempotencia     🔴 no operativo
Sprint 6  Producción y operación        🔴 backups y observabilidad pendientes
```

| Estado | Significado |
|---|---|
| 🟡 | Parcial, limitado o con validaciones pendientes |
| 🔴 | No implementado u operativo |

---

## Sprint 3 — Calidad: tests y CI

### 3.4 CI con GitHub Actions (M)
`.github/workflows/ci.yml` en `push` y `pull_request`.
- Corregir fallos de `pint --test` en los 11 archivos restantes para desbloquear la ejecución de tests y análisis en CI.

### 3.5 Larastan (S)
- Resolver los 4 errores reportados por Larastan para alcanzar el estado de "verde" en el pipeline.

---

## Sprint 4 — Experiencia de uso

### 4.4 Dashboard con métricas (M)
Sustituir el lanzador por tarjetas: usuarios por estado, cuentas por estado y subsistema, operaciones fallidas/en curso, suspensiones que vencen en 7 días, última prueba de conexión por subsistema (`last_connection_test_*`).
- Implementar consultas agregadas con caché corta (60 s).

---

## Sprint 5 — Conciliación, notificaciones e idempotencia

### 5.1 Conciliación (M)
- Implementar `AccountsReconcileCommand::handle()`: consultar `getUserStatus()` para cuentas estables y comparar con el local.
- Implementar pantalla de "Discrepancias" con acciones: *aceptar remoto* o *forzar local*.
- Registrar resoluciones en `account_state_logs`.

### 5.2 Notificaciones (M)
- Implementar Mailables encolados: credenciales iniciales, suspensión, reactivación, baja.
- Asegurar que contraseñas se generen en memoria en el job y no se persistan en logs.

### 5.3 Idempotencia y compensación (M)
- Conectar `IdempotencyMiddleware` y `IdempotencyHandler` a las rutas de provisión y suspensión.
- Implementar verificación de estado en subsistema antes de re-ejecutar filas con `intentos > 0` para evitar duplicados.

---

## Sprint 6 — Producción y operación

### 6.2 Aviso de baja irreversible en Chatwoot (S) (Opcional)
- Mostrar aviso en la ficha del usuario si tiene cuenta de Chatwoot, advirtiendo la pérdida de historial al dar de baja.

### 6.4 Secretos (S)
- Implementar procedimiento de re-cifrado de datos existentes antes de rotar `APP_KEY`.
- Corregir uso de `env()` fuera de `config/` en `SecretService` (Larastan).

### 6.5 Backups y restauración (S)
- Implementar `db:backup` (volcado cifrado con `openssl`).
- Implementar `db:restore` (descifrado e importación).
- Configurar schedule diario y documentar runbook de restauración.

### 6.6 Observabilidad (M)
- Implementar logs estructurados (JSON) a stdout.
- Configurar alertas para `failed_jobs`, operaciones `Fallida` y fallos de `testConnection`.

### 6.7 Documentación (S)
- Generar OpenAPI de la API (incluyendo `Idempotency-Key` y `GET /operaciones/{uuid}`).
- Documentar procedimientos de reconciliación y backups en el runbook.

### 6.8 Detalles visuales (S)
- Revisión final de espaciado y alineación en vistas de `gestor-users` y `subsystems`.

---

## Riesgos y decisiones abiertas

| # | Tema | Decisión necesaria | Cuándo |
|---|---|---|---|
| D2 | Reset de contraseña en cola | Definir canal seguro de entrega para mover a cola | Sprint 5 |
| D3 | Política de fallo parcial en alta | Decidir si implementar compensación (reversión) | Sprint 5 |
| D4 | Rotación de `APP_KEY` | Diseñar flujo de re-cifrado de datos | Sprint 6 |
| D5 | Idempotencia | Definir retención de claves y respuestas repetidas | Sprint 5 |
