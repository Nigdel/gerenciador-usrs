# Gestor de usuarios — To-do actualizado

Revisión: 2026-10-09 · Rama `main` · Base `ded66b5`.

**Pruebas:** 458 tests / 1341 aserciones pasan dentro de Docker. **Calidad/CI:** Pint falla en 11 archivos; Larastan reporta 4 errores; el último CI se detiene en Pint. Detalle en [análisis del estado actual](./analisis-estado-actual.md).

## Estado por área

| Área | Estado |
|---|---|
| Fases 0–2: API, acceso, ciclo de vida | ✅ Implementadas |
| Sprint 1: cola, persistencia, consulta, reintento, secretos | ✅ Implementado |
| Sprint 2: errores, reset registrado, worker y configuración | ✅ Implementado |
| Sprint 3: cobertura funcional y CI | 🟡 Suite pasa localmente; Pint/Larastan/CI no pasan |
| Sprint 4: listados, filtros, validaciones, operaciones | ✅ Implementado |
| Dashboard | 🟡 Básico |
| Sprint 5: conciliación, idempotencia y auditoría | 🔴 Parcial: conciliación vacía, idempotencia desconectada, auditoría incompleta |
| Sprint 6: producción y secretos | 🔴 Parcial: Compose de producción no funciona con la imagen actual |

## Pendientes prioritarios

- [ ] **Restablecer CI en verde.** Corregir los 11 problemas de Pint y los 4 errores de Larastan; después ejecutar el workflow completo y confirmar cobertura ≥80 %.
- [ ] **Implementar conciliación real.** `accounts:reconcile` está programado, pero su `handle()` no contiene lógica. Añadir detección/resolución de discrepancias y tests.
- [ ] **Conectar idempotencia a la API.** Los middlewares actuales no hacen nada y las rutas no los usan. Definir respuesta repetida y conflicto por payload distinto; cubrir duplicados/concurrencia con tests.
- [ ] **Completar auditoría.** Registrar cambios administrativos, actor, IP y user-agent; probar que no se registran secretos.
- [ ] **Rehacer y validar `docker-compose.prod.yml`.** Usar el Dockerfile y los paths reales del proyecto; probar build y arranque del stack.
- [ ] **Asegurar rotación de `APP_KEY`.** No perder `api_config` ni payloads cifrados; usar configuración en lugar de `env()` directo y mover `SecretsRotateTest` a `src/tests`.
- [ ] **Añadir backups y una restauración comprobada.**
- [ ] **Añadir notificaciones** de credenciales y cambios de estado con transporte real en producción.
- [ ] **Completar OpenAPI y runbook** de operaciones, errores, rotación y recuperación.

## Pendientes de producto / decisiones

- [ ] Confirmar las APIs reales de Email y Slack; documentar los endpoints no confirmados de Adagio.
- [ ] Añadir aviso en la ficha sobre la eliminación/recreación de agentes de Chatwoot y pérdida de historial.
- [ ] Completar métricas del dashboard y alertas operativas.
- [ ] Decidir si se necesita importación/exportación CSV.
- [ ] Revisar detalles visuales de las vistas.

## Ya implementado; no reabrir como pendiente

- [x] Login, roles/Policies, Sanctum y abilities.
- [x] Alta, suspensión inmediata/programada, reactivación automática, baja, sincronización y reset síncrono registrado como operación.
- [x] Operaciones asíncronas por cuenta, polling web/API, exclusión de operaciones simultáneas y reintento de filas fallidas.
- [x] Poda de secretos de operaciones y cifrado de configuración de subsistemas.
- [x] Tests de orquestación, API y drivers; búsqueda, paginación, filtros y validaciones de CPF.
- [x] CI configurado en GitHub Actions (la configuración existe, pero hay que corregir los fallos señalados arriba).
