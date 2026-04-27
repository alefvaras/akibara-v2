# B-S1-SEC-04 — BlueX upstream responsible disclosure

**Fecha audit:** 2026-04-27
**Plugin afectado:** `bluex-for-woocommerce` (Blue Express Chile)
**Severidad:** Medium — credentials leak via plugin source code
**Reporter:** Akibara (Alejandro Vargas) — pendiente envío manual

---

## Resumen del issue

El plugin `bluex-for-woocommerce` distribuye una **API key de producción hardcoded** en su código fuente:

**Archivo:** `bluex-for-woocommerce/includes/class-wc-correios-settings.php`

**Función:** `get_tracking_bxkey()`

**Línea:**
```php
$apiKeyBase = "QUoO07ZRZ12tzkkF8yJM9am7uhxUJCbR7f6kU5Dz";
```

Esta key es usada como fallback para todas las llamadas REST a la API BlueX (`https://eplin.api.blue.cl/...`) cuando `dev_mode` está deshabilitado. Es decir, **TODA instalación del plugin sin override custom usa la misma key compartida en producción.**

---

## Riesgo

1. **Plugin source distribuido públicamente** = la key está en GitHub/marketplace de cualquier mirror del plugin.
2. **Logs locales (wp_bluex_logs)** registran cada request con `Headers={"x-api-key":"QUoO07Z..."}` plain-text. SQL dumps comparten la key en cada export.
3. **Si la key se rota desde BlueX**, todas las instalaciones del plugin se rompen simultáneamente hasta que distribuyan un update.
4. **Quota / rate limiting compartido** entre todas las instalaciones. Una tienda abusiva afecta el resto.
5. **Auditoría / billing**: BlueX no puede saber qué instalación está usando la key sin metadatos adicionales.

---

## Mitigación local Akibara aplicada

1. ✅ TRUNCATE de `wp_bluex_logs` (49.026 rows borrados, 7.7 MB de SQL).
2. ✅ Mu-plugin `akibara-bluex-logs-purge.php` instalado: cron mensual elimina filas > 30 días.
3. ✅ Backup pre-TRUNCATE en `.private/backups/2026-04-27-pre-sec-04-wp_bluex_logs.sql`.

**No aplicado** (fuera de scope local):
- Migration de la key a `wp-config-private.php` — la key es hardcoded en plugin source, NO es constante migrable.
- Patch del plugin source — sería sobrescrito en próximo update.
- Filter para redactar `x-api-key` en log_body antes del INSERT — el plugin usa `$wpdb->insert()` directo (sin filter); requeriría hookear `query` filter de wpdb (heavy + frágil).

---

## Recomendaciones BlueX (para enviar a soporte)

1. **Eliminar la API key hardcoded del source.** Forzar configuración por instalación vía settings WC admin (campo `tracking_bxkey`).
2. **Rotar la key compartida actual** — comunicar a todas las tiendas usando el plugin para que generen su propia.
3. **Agregar redaction de headers sensibles** en el helper `logger/helper.php` antes del `$wpdb->insert()`. Patrón: regex sobre `log_body` removiendo `x-api-key:[^,}]+`.
4. **Documentar** en README del plugin: "Cada tienda debe generar su propia API key. La key default es solo para testing; usar en producción viola los términos de servicio de BlueX."

---

## Plan de envío

**Email a soporte BlueX** (formato sugerido):

```
Asunto: [Disclosure] API key hardcoded en plugin bluex-for-woocommerce

Hola equipo BlueX,

Soy Alejandro Vargas, dueño de Akibara (akibara.cl, tienda manga Chile).
Detecté el siguiente issue durante una auditoría de seguridad de mi sitio:

1. El plugin bluex-for-woocommerce distribuye una API key de producción
   hardcoded en class-wc-correios-settings.php (apiKeyBase = "QUoO...").

2. El logger del plugin (logger/helper.php) registra cada request con la
   key en plain-text, vía $wpdb->insert() en wp_bluex_logs. En mi tienda
   habían ~49.000 filas con la key expuesta.

3. Cualquier export SQL de la base WP filtra la key a quien acceda al dump.

Mitigué localmente: TRUNCATE wp_bluex_logs + cron mensual de purge >30 días.
No puedo migrar la key a una env var porque está hardcoded en el plugin source.

Recomendaciones (orden de prioridad):
- Eliminar la key default del source y forzar config por instalación.
- Rotar la key actual compartida.
- Agregar redaction en el logger antes del INSERT.

Quedo atento a cualquier coordinación necesaria.

Saludos,
Alejandro Vargas
ale.fvaras@gmail.com
```

**Canal sugerido:** dev@blue.cl o el contacto comercial que onboardeó la cuenta Akibara.

**Status envío:** ⏳ Pendiente — el usuario lo envía cuando le conviene operacionalmente.

---

## Follow-up

- **Si BlueX rotara la key compartida sin avisar:** `wp_bluex_logs` mostraría errores 401 / 403 en `log_type=error`. Smoke alert: monitor diario `SELECT COUNT(*) FROM wp_bluex_logs WHERE log_type='error' AND log_timestamp > NOW() - INTERVAL 1 HOUR`.
- **Si BlueX libera fix con key per-instalación:** actualizar plugin + verificar `tracking_bxkey` setting WC admin tiene la nueva key Akibara-specific.
- **Si emerge un patrón mejor para mitigar logs upstream sin patch:** considerar un mu-plugin `query` filter wpdb que redacte `x-api-key:` en el SQL antes de ejecutar (Sprint 2 si vale la pena).
