# Deploy Runbook — Sprint 3 → Prod

**Fecha:** 2026-04-27
**Sprint:** 3 (Cell A preventas + Cell B marketing + Cell H design)
**F-05 fix:** documentar orden de deploy para evitar ventana double-registration del shim `inc/encargos.php`.

---

## Contexto

Cell A migró la lógica del handler AJAX `wp_ajax_akibara_encargo_submit` desde el tema (`themes/akibara/inc/encargos.php`) al plugin `akibara-preventas/modules/encargos/module.php`.

El shim del tema fue actualizado con un guard `if (defined('AKB_PREVENTAS_ENCARGOS_LOADED')) return;` para evitar double-registration cuando el plugin está activo (RFC THEME-CHANGE-01 Opción B).

**Riesgo identificado por mesa-11 (F-05):** Si el deploy del plugin `akibara-preventas` (que define `AKB_PREVENTAS_ENCARGOS_LOADED`) llega ANTES del deploy del tema actualizado (con el guard), hay una ventana donde:
- Plugin activo → `add_action('wp_ajax_akibara_encargo_submit', 'akb_encargo_submit_handler')`
- Tema sin guard activo todavía → `add_action('wp_ajax_akibara_encargo_submit', 'akibara_ajax_encargo_submit')` (legacy)
- Ambos handlers registrados → WordPress ejecuta los dos en orden, segundo dispara warning "headers already sent" (`wp_send_json` doble).

---

## Orden obligatorio de deploy

### Step 1 — Tema primero (con guard ya en código)

```bash
# Push del branch theme-design-s3 a prod
bash bin/deploy.sh --target=themes/akibara/

# Verifica que inc/encargos.php tiene el guard:
bin/wp-ssh ssh "head -25 /home/u123456/public_html/wp-content/themes/akibara/inc/encargos.php"
# Debe mostrar:  if ( defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) { return; }
```

### Step 2 — Plugin akibara-preventas

```bash
# Push del branch akibara-preventas a prod
bash bin/deploy.sh --target=plugins/akibara-preventas/

# Verifica plugin activo:
bin/wp-ssh wp plugin status akibara-preventas
# Debe mostrar: Status: Active
```

### Step 3 — Plugin akibara-marketing (independiente, último para reducir blast radius)

```bash
bash bin/deploy.sh --target=plugins/akibara-marketing/
bin/wp-ssh wp plugin status akibara-marketing
```

### Step 4 — Smoke prod (T+0)

```bash
BASE_URL=https://akibara.cl bash scripts/smoke-prod.sh --quick
```

Si **1 FAIL** en cualquier check core: rollback inmediato. **No esperar T+15.**

### Step 5 — Verificación handler único

```bash
bin/wp-ssh eval "
  global \$wp_filter;
  \$hook = 'wp_ajax_akibara_encargo_submit';
  if (isset(\$wp_filter[\$hook])) {
    foreach (\$wp_filter[\$hook]->callbacks as \$priority => \$callbacks) {
      foreach (\$callbacks as \$cb) {
        echo \$cb['function'] . PHP_EOL;
      }
    }
  } else {
    echo 'NOT REGISTERED';
  }
"
```

**Expected output:** `akb_encargo_submit_handler` (UNA sola línea). Si aparece `akibara_ajax_encargo_submit` también → double-registration → rollback.

### Step 6 — Verificar constante plugin

```bash
bin/wp-ssh eval "echo defined('AKB_PREVENTAS_ENCARGOS_LOADED') ? 'OK' : 'MISSING';"
# Expected: OK
```

### Step 7 — Cron hooks correctos

```bash
bin/wp-ssh wp cron event list --format=table | grep -E "next_volume|reservas_check|brevo"
```

Verificar que `akibara_next_volume_check` apunta a la función del plugin (no legacy). Test:

```bash
bin/wp-ssh eval "
  global \$wp_filter;
  \$hook = 'akibara_next_volume_check';
  print_r(\$wp_filter[\$hook] ?? 'NOT REGISTERED');
"
```

Expected callback: función definida en `wp-content/plugins/akibara-preventas/modules/next-volume/module.php`.

---

## Rollback

### Si Step 4 falla (smoke prod)

```bash
# Identificar deploy timestamp
ls -la /home/u123456/.deploy-backups/

# Rollback al backup más reciente pre-deploy
bash bin/deploy.sh --rollback --target=plugins/akibara-preventas/
bash bin/deploy.sh --rollback --target=plugins/akibara-marketing/
bash bin/deploy.sh --rollback --target=themes/akibara/

# Verificar rollback
bin/wp-ssh wp plugin status akibara-preventas
# Expected: Inactive (revertido)
```

### Si solo akibara-preventas tiene issues (deploy parcial OK)

```bash
bash bin/deploy.sh --rollback --target=plugins/akibara-preventas/
# Tema sigue actualizado, plugin marketing puede quedar también si está sano
```

---

## Notas

- El módulo `next-volume` legacy en `server-snapshot/public_html/wp-content/plugins/akibara/modules/next-volume/` debe ser eliminado en el deploy package (NO sync ese subdirectorio). Si llega a prod, el guard `if (! function_exists(...))` en akibara-preventas descarta la nueva función → cron apunta a legacy. Verificar Step 7.
- Deploys vía rsync: `bin/deploy.sh` debería usar `--delete-after` para eliminar archivos antiguos del package, pero NO `--delete` (preserva `.htaccess`, `wp-config-private.php`, etc).
- `monitor-post-deploy.sh` corre 15min de tail logs Sentry/Hostinger/wp_logs post-deploy automáticamente.

---

## Sprint 3 specific verification matrix

| Check | Expected | Comando |
|---|---|---|
| Theme `inc/encargos.php` con guard | Guard en líneas 19-22 | `bin/wp-ssh ssh "grep AKB_PREVENTAS_ENCARGOS_LOADED inc/encargos.php"` |
| Plugin akibara-preventas activo | `Active` | `bin/wp-ssh wp plugin status akibara-preventas` |
| Plugin akibara-marketing activo | `Active` | `bin/wp-ssh wp plugin status akibara-marketing` |
| Customer-milestones loader OFF | comentado en línea 174 | `bin/wp-ssh ssh "grep customer-milestones plugins/akibara-marketing/akibara-marketing.php"` |
| Handler encargos registrado UNA vez | Solo `akb_encargo_submit_handler` | Step 5 eval |
| Constante plugin definida | `OK` | Step 6 eval |
| `/encargos/` 200 | HTTP 200 | smoke quick |
| `/mis-reservas/` 200 o 302 | HTTP 200/302 | smoke quick |
| REST health 200 | HTTP 200 | smoke quick |
| Cron next-volume → plugin function | function path en akibara-preventas | Step 7 |
| Sentry T+24h | 0 issues namespaces akibara | QA-SMOKE-REPORT.md §2 |
