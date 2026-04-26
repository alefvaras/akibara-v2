# Cleanup Report — Akibara V2 — 2026-04-26

## Resumen

| Métrica | Valor |
|---------|-------|
| `public_html/` antes | 2.3 GB |
| `public_html/` después | **1.4 GB** |
| Espacio recuperado | **~900 MB** |
| Items eliminados | ~30 paths (5 niveles `staging/` + 9 docs internos + 3 backups config + 4 dirs dev + PHPs ejecutables + logs antiguos + helpers `/tmp`) |
| Riesgo seguridad detectado | SÍ (P0 corregidos en mismo paso) |
| Sitio funcionando post-cleanup | ✅ HTTP 200 home + producto test |

## Snapshots / backups locales (restore disponible)

```
~/Documents/akibara-v2/snapshots/
├── akb-snapshot-20260426-1904.tar.gz         (242M) — código original sin uploads
├── akb-excluded-20260426-1906.tar.gz         (1.0G) — uploads/cache/litespeed/upgrade
├── akb-pre-cleanup-staging-20260426-1917.tar.gz  (177M) — staging/ pre-rm
├── akb-pre-cleanup-misc-20260426-1917.tar.gz     ( 56M) — docs/configs/dirs/uploads pre-rm
└── akb-pre-cleanup-tmpfiles-20260426-1917.tar.gz (726B) — /tmp helpers pre-rm

~/Documents/akibara-v2/.private/
└── akb-prod-dump.sql                         ( 28M) — DB dump intencional del usuario

~/Documents/akibara-v2/server-snapshot/public_html/  (1.4G) — mirror limpio post-cleanup
```

## Raw findings por categoría

Outputs en `audit/raw/A_top_dirs.txt`, `B_stage_dev.txt`, `C_backups.txt`, `D_ide.txt`, `E_security.txt`, `F_logs.txt`, `G_old.txt`, `H_node.txt`, `I_themes.txt`.

### A — Top dirs pesados

`./staging` 842 MB (5 niveles anidados — el problema principal). Resto: `wp-content/uploads` 1.1 GB (legítimo), `wp-content/plugins` 223 MB (76 MB akibara custom).

### B — Stage/dev residuales (BORRADO)

- `staging/`, `staging/staging/`, `staging/staging/staging/`, `staging/staging/staging/staging/`, `staging/staging/staging/staging/staging/`
- `wp-config.php.bak-20260426-102907`, `wp-config.php.bak-pre-sentry-migration-1777163938`, `.htaccess.bk`

### C — Backups y dumps

- Solo legítimos detectados (`.sql` schema files de plugin litespeed-cache, no son dumps reales).
- `slider-2025-10-01__20-41-19.zip` (89 KB en uploads) → BORRADO.
- Dump SQL del usuario en `/tmp/akb-prod-dump.sql` (28 MB) → bajado a `.private/` y borrado de server.

### D — IDE / editor

Vacía. Sin `.vscode`, `.idea`, `.DS_Store`, etc.

### E — Riesgo seguridad

- ✅ NO había `.git/` expuesto.
- ⚠️ Falsos positivos: `wp-includes/Text/Diff/Engine/shell.php` (core WP), `themes/akibara/template-parts/single-product/info.php` (template legítimo), `.env.sample` Mercado Pago (ejemplo, no real), `bluex-for-woocommerce/debug-config.php` (define BLUEX_DEBUG=false).
- 🔴 BORRADO: `test_smart.php` (PHP test ejecutable en root), `wp-content/uploads/mailchimp-for-wp/debug-log.php` (PHP en uploads/, vector típico de webshells), archivo `0` (basura).
- 🔴 BORRADO docs internos accesibles vía HTTP: `AGENTS.md` (27K), `AUDIT_PROGRESS.md` (107K), `MIGRATION_GUIDE.md` (23K), `MULTI_COURIER.md` (29K), `REVIEW_THEME.md` (13K), `SFTP_SYNC.md` (5.9K), `audi.md` (14K), `tienda.md` (10K), `progress.txt` (29K), `docs/`, `scripts/`, `tests/`, `modules/`.
- ✅ Mantenido: `llms.txt` (estándar discoverability).

### F — Logs

- `error_log-20260421-1776729604.gz` (147K) → BORRADO.
- `wp-content/akb-slash-debug.log` (8.8K) → BORRADO.
- `error_log` activo (4K) → BORRADO (WP regenera si hay errores).
- `wp-content/uploads/wc-logs/google-for-woocommerce-2026-04-18-*.log` (1.3 MB) → mantenido (log activo Google for WooCommerce).
- `/tmp/akb-analyze.log`, `/tmp/akb-batch.log`, `/tmp/akb-eval-1777083586734.php` → BORRADOS de server.

### G — Themes/plugins -old/-bak

Vacía. Limpio.

### H — node_modules

Vacía. Limpio.

### I — Themes default WP

Solo `akibara` custom. Sin defaults WordPress (twentytwenty/etc) — considerar agregar uno como fallback en BACKLOG (no urgente).

## Verificación post-cleanup

```
Home: 200
Producto test 24261: 200
AGENTS.md: 404           ✅
AUDIT_PROGRESS.md: 404   ✅
test_smart.php: 404      ✅
staging/: 404            ✅
wp-admin: 302            ✅ (redirect a login)
wp-config.php intacto    ✅ (mode 600)
.htaccess intacto        ✅
```

## Items que NO se tocaron

- `wp-content/uploads/*` (imágenes productos, intactas)
- `wp-content/plugins/akibara*` (custom — auditar Paso 3)
- `wp-content/themes/akibara/*` (custom — auditar Paso 3)
- `wp-content/mu-plugins/akibara*` (custom — auditar Paso 3)
- `wp-config.php`, `.htaccess` activos
- `php.ini` (custom override del usuario)
- `wp-content/uploads/wc-logs/*` (logs activos WC)

## Items para revisar en BACKLOG (Paso 3)

1. **`bluex-for-woocommerce/debug-config.php`** — confirmar que `BLUEX_DEBUG=false` permanezca en prod.
2. **Theme fallback** — considerar mantener un default WP (twentytwentyfour) deshabilitado para fallback.
3. **`/tmp` cleanup automático** — los archivos `akb-eval-*.php` y `akb-prod-dump.sql` quedaron por scripts manuales; agregar TTL o usar dirs no expuestas.
4. **`llms.txt` (336K)** — revisar contenido para no exponer info sensible (es público intencionalmente, pero verificar que no incluya internals).
5. **Plugin Mercado Pago `vendor/mp-plugins/php-sdk/.env.sample`** — falso positivo aquí, pero confirmar que `.env` real no exista.

## Próximo paso

Paso 1 — Verificar setup base (du, trivy version, docker ps).
