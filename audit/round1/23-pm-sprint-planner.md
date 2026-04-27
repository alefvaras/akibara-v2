---
agent: mesa-23-pm-sprint-planner
round: 1
date: 2026-04-26
scope: Capacity baseline, sprint sizing per known item, Sprint 1 propuesto, growth-deferred roadmap, DoD universal
files_examined: 3 (CONTEXT-FOR-AGENTS.md, CLEAN-SEEDS.md, HOMEPAGE-SNAPSHOT.md)
findings_count: { P0: 1, P1: 3, P2: 2, P3: 1 }
---

## Resumen ejecutivo

- **Capacity real solo dev part-time: 25-30h efectivas/semana max**. Todo lo demás es ficción que va a romper sprints.
- **Sprint 1 cargado realista: 14 items, ~22h efectivas**. Cabe en 1 semana SI el día 1 se hace 100% backup + DOBLE OK con usuario para destructivos. Si el dev no tiene backup setup, Sprint 1 dura 1.5 semanas.
- **Item más subestimado por mesa: CLEAN-002 (cart-abandoned local)**. Está marcado M (2-4h) pero realmente es L (1-2 días) porque depende de validar Brevo upstream tracker firing → eso requiere setup SPF/DKIM/DMARC en Cloudflare DNS + activar plugin tracker + smoke test 24h. NO se puede CLEAN-002 hasta que F-PRE-002 esté DONE.
- **3 items sin DoD claro en CLEAN-SEEDS.md** (F-PRE-005, F-PRE-006, F-PRE-009) — los muevo a S2/S3 con DoD propuesto.
- **Growth-deferred roadmap construido para 4 milestones** (5/25/50/100 clientes/mes) — 80% de "growth features" deben estar en S3+ o más allá. NO cargar Sprint 1 con marketing automations.

---

## Capacity baseline (dev solo, restricciones)

### Capacidad efectiva

| Variable | Valor asumido |
|---|---|
| Dev | 1 (Alejandro), part-time |
| Horas brutas/semana | 30-40 |
| Horas efectivas en código | **25-30h max realistas** (resto: admin, customer service, debugging no planeado, contexto-switching) |
| Velocity histórico | **No hay datos. Asumir conservador 25h/semana hasta validar 2 sprints.** |
| Code review | Ninguno (commit-direct-to-main) |
| Test suite automatizado | Limitado (sin coverage robusta) |
| Staging environment | NO existe |
| CI/CD | Manual rsync |

### Implicancias operativas (multiplicadores de tiempo)

Cada item del backlog tiene tiempo real = `tiempo_código + overhead operacional`. Para Akibara, multipliers son:

| Tipo de cambio | Overhead extra obligatorio |
|---|---|
| Toca emails | +30 min (verify Brevo + guard test send to alejandro.fvaras@gmail.com) |
| Toca prod (server) | +15 min (snapshot tar.gz + DOBLE OK explícito al usuario) |
| Toca DB | +30 min (backup mysqldump + verify post + rollback path) |
| Toca branding/visual | +0h código pero **bloqueado hasta mockup aprobado** (mockup no es sprint hours, es cliente hours) |
| Toca pricing/cupones | +30 min (verify NO modifica `_sale_price` directo, solo WC_Coupon) |
| Toca preventa flow | +1h (regression test orden completo: reservar → admin → fulfill) |

### Restricciones que bloquean items

1. **Sin staging**: items "destructivos" (CLEAN-001..004, SEC-P0-001) requieren DOBLE OK + backup pre-cambio.
2. **Sin code review**: rollback plan documentado en commit message es OBLIGATORIO. Si rollback toma >30 min, item no va a S1.
3. **Sin test suite robusto**: smoke test manual post-deploy es DoD obligatorio.
4. **Branding pulido**: mesa-13 SOLO observa. Cualquier cambio visual requiere mockup ANTES → mockup loop adds 1-3 días por item.
5. **Brevo Free 300/día compartido**: cualquier feature que dispare emails (welcome, back-in-stock, next-volume) consume cuota → hay techo natural a "growth features email".

---

## Sprint sizing per item conocido

Formato cada item:
- **Esfuerzo revisado:** S/M/L/XL ajustado por overhead
- **DoD verificable:** checklist objetivo
- **Smoke test post-deploy:** comando o paso concreto
- **Rollback plan:** cómo deshacer
- **Dependencies:** qué debe estar DONE antes
- **Sprint:** S1/S2/S3+

---

### CLEAN-001 — Delete `akibara-sentry-customizations.php`

- **Esfuerzo revisado:** S (15 min código + 15 min snapshot + 15 min monitoring setup = **45 min total**)
- **DoD verificable:**
  - [ ] Archivo `mu-plugins/akibara-sentry-customizations.php` removido en local
  - [ ] Snapshot tar.gz del archivo en `.private/snapshots/2026-04-NN-clean-001.tar.gz`
  - [ ] Commit a main con rollback path en mensaje
  - [ ] Deploy a prod via rsync
  - [ ] Sentry UI muestra al menos 1 event nuevo en 24h post-cleanup (verifica que upstream sigue firing)
- **Smoke test post-deploy:**
  - `curl -I https://akibara.cl` → expect HTTP 200
  - Trigger error intencional vía wp-admin (ej: visit página inexistente con debug log on) → verify event en Sentry dashboard
- **Rollback plan:** `tar -xzf .private/snapshots/2026-04-NN-clean-001.tar.gz -C wp-content/mu-plugins/` + `git revert <hash>`
- **Dependencies:** Ninguna (item independiente)
- **Sprint:** **S1**
- **Riesgo regresión:** Bajo (Sentry upstream provee toda la funcionalidad)

---

### CLEAN-002 — Delete `cart-abandoned/` local module

- **Esfuerzo revisado:** **L (1-2 días)** — la mesa lo marcó M pero ignora que depende de F-PRE-002 (Brevo tracker firing) primero
- **DoD verificable:**
  - [ ] F-PRE-002 (Brevo upstream tracker) DONE primero — verificación 24h de que workflow Brevo está recibiendo eventos `cart abandoned`
  - [ ] Módulo `plugins/akibara/modules/cart-abandoned/` removido (incluye cron registration)
  - [ ] Tabla DB `akibara_abandoned_carts` dropped (con backup mysqldump pre-drop)
  - [ ] Transients limpiados: `wp transient delete --all` o equivalente filtrado
  - [ ] Snapshot tar.gz del módulo + dump SQL de tabla
  - [ ] Hook registrations removidas de loader principal (no quedan referencias huérfanas)
- **Smoke test post-deploy:**
  - Producto test 24261, agregar al cart como user `alejandro.fvaras@gmail.com`, abandonar 1h, verify email Brevo upstream llega
  - `wp cron event list | grep -i abandon` → expect 0 results (no cron de módulo viejo)
  - `wp db query "SHOW TABLES LIKE '%abandoned%'"` → expect 0 results
- **Rollback plan:** restore tar.gz + restore SQL dump tabla + reactivar registration. **Tiempo rollback: ~30 min** (en límite del budget).
- **Dependencies:** F-PRE-002 DONE (Brevo tracker validado firing)
- **Sprint:** **S2** (porque F-PRE-002 toma 1-3 días con Cloudflare DNS propagation)
- **Riesgo regresión:** Medio (si Brevo upstream tracker no firea, los clientes que abandonan carrito NO reciben email = pérdida revenue silenciosa)

---

### CLEAN-003 — Delete `akibara-brevo-smtp.php`

- **Esfuerzo revisado:** S (30 min código + 30 min validation refs + 30 min smoke test email = **1.5h total**)
- **DoD verificable:**
  - [ ] Grep en codebase: `grep -r "akb_brevo_get_api_key\|akb_brevo_api_key_is_constant" plugins/ themes/ mu-plugins/` → expect 0 results activos
  - [ ] Archivo removido + snapshot
  - [ ] Verify path actual de envío email (probablemente plugin oficial Brevo o wp_mail nativo) sigue trabajando
- **Smoke test post-deploy:**
  - Test order con producto test 24261 → verify email "tu pedido se ha recibido" llega a `alejandro.fvaras@gmail.com`
  - `wp eval 'wp_mail("alejandro.fvaras@gmail.com", "smoke-test-clean003", "test post-clean003");'` → expect llega
- **Rollback plan:** restore tar.gz + git revert
- **Dependencies:** Ninguna directa, pero recomiendo hacer DESPUÉS de mesa-10 confirme que ningún módulo activo lo necesita
- **Sprint:** **S1**
- **Riesgo regresión:** Bajo si grep da 0, **alto** si hay referencias ocultas (ej: action hook con string callback)

---

### CLEAN-004 — Remove `AKB_BREVO_API_KEY` constante de wp-config.php

- **Esfuerzo revisado:** S (5 min código + 15 min verify no `wp_remote_post` a Brevo en custom = **20 min total**)
- **DoD verificable:**
  - [ ] CLEAN-003 DONE primero
  - [ ] `grep -r "AKB_BREVO_API_KEY\|wp_remote_post.*brevo" plugins/ themes/ mu-plugins/` → expect 0 hits
  - [ ] Línea `define('AKB_BREVO_API_KEY', ...)` removida de wp-config.php
  - [ ] Snapshot wp-config.php pre-cambio
- **Smoke test post-deploy:**
  - `curl -I https://akibara.cl` → expect 200 (no fatal por undefined constant)
  - `wp eval 'echo defined("AKB_BREVO_API_KEY") ? "STILL DEFINED" : "OK removed";'` → expect "OK removed"
  - Test send email transaccional → verify llega
- **Rollback plan:** restore wp-config.php desde snapshot
- **Dependencies:** CLEAN-003 DONE
- **Sprint:** **S1** (después de CLEAN-003)
- **Riesgo regresión:** Bajo

---

### SEC-P0-001 — Delete 4 admin backdoor accounts

- **Esfuerzo revisado:** **M (2h)** — la mesa lo marcó S (15 min) pero NO incluye:
  - Verify user 6 ~47 posts (sí son productos importados legítimos? injection?) → 30 min audit por mesa-10
  - Audit cron jobs creados por estos users → 30 min
  - Audit modificaciones wp-config/.htaccess/mu-plugins → 30 min
  - DOBLE OK explícito (espera respuesta usuario)
  - Backup completo DB pre-deletion → 15 min
  - Comando de delete + reasign → 15 min
- **DoD verificable:**
  - [ ] Mesa-10 confirma user 6 posts son legítimos (productos importados) — si son injection, primero borrar/quarantine posts
  - [ ] Backup DB completo: `mysqldump akibara_db > .private/backups/2026-04-NN-pre-sec-p0-001.sql`
  - [ ] DOBLE OK del usuario via texto explícito antes de ejecutar
  - [ ] Comandos ejecutados:
    ```bash
    bin/wp-ssh user delete 6 --reassign=1 --yes
    bin/wp-ssh user delete 5 --yes
    bin/wp-ssh user delete 7 --yes
    bin/wp-ssh user delete 8 --yes
    ```
  - [ ] Verify post-delete: `bin/wp-ssh user list --role=administrator --format=count` → expect 1 (solo el dueño legítimo)
- **Smoke test post-deploy:**
  - `bin/wp-ssh user list --role=administrator` → expect solo Alejandro
  - `bin/wp-ssh user list --role=shop_manager` → expect Akibara_shipit
  - Login como Alejandro a wp-admin → expect funciona
  - Visit `/wp-admin/users.php` → expect listado correcto sin huérfanos
- **Rollback plan:**
  - Restore SQL dump: `mysql akibara_db < .private/backups/2026-04-NN-pre-sec-p0-001.sql` (revierte deletion)
  - **Tiempo rollback: <15 min** ✓ dentro del budget
- **Dependencies:** Mesa-10 audit completo (posts, cron, files, options) ANTES de delete
- **Sprint:** **S1 PRIMER ITEM** (más urgente por ser P0 security)
- **Riesgo regresión:** Bajo si reasign correcto, **medio** si user 6 tiene posts/options huérfanos no detectados

---

### F-PRE-001 — BlueX API key plain text en logs DB

- **Esfuerzo revisado:** **M (3h)**:
  - Identificar todas las ubicaciones donde se loggea (1h)
  - Crear logger sanitizer + redact regex para `x-api-key` (1h)
  - Limpiar logs existentes vía SQL UPDATE redact (30 min)
  - Backup pre-update + smoke test (30 min)
  - Considerar key rotation BlueX (separado, async con BlueX support team)
- **DoD verificable:**
  - [ ] Backup DB pre-cleanup
  - [ ] SQL para limpiar: `UPDATE wp_options SET option_value = REPLACE(option_value, 'QUoO07ZRZ12tzkkF8yJM9am7uhxUJCbR7f6kU5Dz', '[REDACTED]') WHERE option_name LIKE 'akb_log%' OR option_name LIKE '%bluex%'` (ejemplo, ajustar al schema real)
  - [ ] Logger refactor: redact pattern `/x-api-key:\s*\S+/i` → `x-api-key: [REDACTED]` antes de persist
  - [ ] Verify: `SELECT * FROM wp_options WHERE option_value LIKE '%QUoO07Z%'` → expect 0 rows
  - [ ] Decisión documentada: rotar key BlueX SÍ/NO (depende de si dump SQL fue compartido fuera del control del usuario)
- **Smoke test post-deploy:**
  - Trigger BlueX API call (cualquier order que dispare integración) → verify log entry tiene `[REDACTED]` no la key real
  - `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_options WHERE option_value LIKE '%QUoO07Z%'"` → expect 0
- **Rollback plan:** Restore DB backup. Es destructivo en logs históricos pero los logs no son business critical (info debugging).
- **Dependencies:** Mesa-10 confirma scope completo de logs antes de UPDATE
- **Sprint:** **S1** (P0 security)
- **Riesgo regresión:** Bajo

---

### F-PRE-002 — Brevo upstream tracker config

- **Esfuerzo revisado:** **L (1-2 días)** — DNS propagation no es instant
  - Validar sender domain `akibara.cl` en Brevo UI (15 min)
  - Configurar SPF en Cloudflare DNS (15 min) + propagation 1-24h
  - Configurar DKIM en Cloudflare DNS (15 min, requiere TXT record from Brevo)
  - Configurar DMARC en Cloudflare DNS (15 min)
  - Verificar plugin Brevo official tracker activo en wp-admin (15 min)
  - Test cart abandonment con producto test 24261 (1h) — incluye esperar 1h de "abandonment timer"
  - Verify Brevo dashboard muestra event `cart abandoned` registrado (15 min)
- **DoD verificable:**
  - [ ] `dig akibara.cl TXT +short` muestra SPF record incluyendo Brevo
  - [ ] `dig brevo._domainkey.akibara.cl TXT +short` muestra DKIM
  - [ ] `dig _dmarc.akibara.cl TXT +short` muestra DMARC policy
  - [ ] Brevo UI dashboard "Senders & IP" muestra `akibara.cl` como ✓ verified
  - [ ] Brevo UI "Automations > Carrito abandonado" stats > 0 después de test (mínimo 1 evento iniciado tras smoke test)
- **Smoke test post-deploy:**
  - Add producto test 24261 al cart como `alejandro.fvaras@gmail.com` logueado
  - Esperar 1h (o setear timer Brevo a 5 min si soportado para test)
  - Verify email "Olvidaste tu carrito" llega a alejandro.fvaras@gmail.com
- **Rollback plan:** Si Brevo tracker rompe send transactional (improbable), revert plugin tracker activation. SPF/DKIM/DMARC NO se rollback (son adds, no replaces de records existentes).
- **Dependencies:** Acceso Cloudflare DNS confirmado, acceso Brevo UI confirmado
- **Sprint:** **S1** (bloqueante para CLEAN-002)
- **Riesgo regresión:** Bajo

---

### F-PRE-008 — WP core file integrity check

- **Esfuerzo revisado:** **S (1h)**:
  - `bin/wp-ssh core verify-checksums` (5 min)
  - Si reporta archivos modificados, audit grep por patterns maliciosos (eval/base64_decode/gzinflate) (30 min)
  - Documentar findings en mesa-10 (15 min)
  - Si archivos limpios pero mtime updated, validar contra origen WP update legítimo (10 min)
- **DoD verificable:**
  - [ ] Comando `wp core verify-checksums` ejecutado
  - [ ] Output documentado en `audit/round1/10-security.md` (mesa-10 owner)
  - [ ] Si modifications detectadas: lista de archivos + diff vs WP source oficial + decisión replace/keep
- **Smoke test post-deploy:** No deploy en este finding; es solo audit. Si se decide replace files, smoke test = home HTTP 200 + checkout flow.
- **Rollback plan:** Si replace de archivos rompe algo, restore desde snapshot WP core pre-replace.
- **Dependencies:** Ninguna
- **Sprint:** **S1** (P0 si se confirma backdoor; P3 si es WP update legítimo)
- **Riesgo regresión:** Bajo (es audit, no modifica nada por defecto)

---

### F-PRE-011 — Productos test E2E visibles en home pública

- **Esfuerzo revisado:** **S (30 min)**:
  - Decisión approach: change product status `private` (más seguro, 5 min) vs filter por SKU `TEST-AKB-*` en queries home (30 min) vs categoría `_test` oculta (45 min)
  - **Recomendación:** status `private` (más simple, garantiza no aparecen en queries públicas WC)
  - Aplicar a productos 24261/24262/24263 (5 min)
  - Verify home no muestra (5 min)
- **DoD verificable:**
  - [ ] Productos 24261/24262/24263 con `post_status = private`
  - [ ] Home `https://akibara.cl/` no muestra "[TEST E2E]" en ninguna sección
  - [ ] Producto sigue accesible para dev logueado (testing E2E sigue funcionando)
- **Smoke test post-deploy:**
  - Visit `https://akibara.cl/` en navegador incógnito → verify NO "TEST E2E" en hero/preventas/últimas llegadas
  - Visit `https://akibara.cl/?p=24261` logueado como admin → expect producto loads
- **Rollback plan:** `wp post update 24261 24262 24263 --post_status=publish`
- **Dependencies:** Ninguna
- **Sprint:** **S1 INMEDIATO** (UX/marketing leak visible a clientes reales)
- **Riesgo regresión:** Bajo

---

### F-PRE-012 — Producto agotado con tag preventa

- **Esfuerzo revisado:** **S (1h)**:
  - Audit lógica display preventa vs agotado (30 min) — mesa-22 owner
  - Decidir reglas mutuamente excluyentes (agotado supera preventa o vice versa) (15 min)
  - Si es bug del producto test (meta keys mal seteadas), corregir meta del producto 24263 (15 min)
- **DoD verificable:**
  - [ ] Documentado: regla de display "si stock = 0 entonces no muestra badge preventa, muestra solo agotado"
  - [ ] Producto test 24263 con meta correcta (no muestra ambos badges)
  - [ ] Si requiere code fix en theme/plugin, commit + DoD code-level
- **Smoke test post-deploy:**
  - Visit producto test 24263 → expect display consistente (agotado XOR preventa, no ambos)
- **Rollback plan:** revert meta values producto 24263, o git revert si code fix
- **Dependencies:** F-PRE-011 (porque resolver visibility test product primero)
- **Sprint:** **S1** después de F-PRE-011
- **Riesgo regresión:** Bajo

---

### F-PRE-013 — Sale price layout broken

- **Esfuerzo revisado:** **S (1.5h)**:
  - Identificar template responsable: `templates/loop/price.php` o `themes/akibara/woocommerce/...` (30 min)
  - Override con HTML correcto + CSS spacing (45 min)
  - Verify desktop + mobile (15 min)
- **DoD verificable:**
  - [ ] Producto "The Climber 15" muestra precios separados visualmente:
    - precio original tachado: `$14.000`
    - precio actual: `$13.500`
  - [ ] Sin texto duplicado "El precio original era... El precio actual es..."
  - [ ] Mobile (390px) y desktop (1440px) ambos legibles
- **Smoke test post-deploy:**
  - Lighthouse o screenshot mobile + desktop del producto en home → verify layout correcto
  - Ningún producto en sale muestra el bug
- **Rollback plan:** git revert template override
- **Dependencies:** Mockup NO requerido (es bug fix, no cambio diseño nuevo). Mesa-13 ratifica que es fix no cambio visual.
- **Sprint:** **S1**
- **Riesgo regresión:** Bajo

---

### F-PRE-014 — Categoría `Uncategorized` visible

- **Esfuerzo revisado:** **S (15 min)** — resuelto automáticamente por F-PRE-011 si productos test pasan a status private
- **DoD verificable:**
  - [ ] Después de F-PRE-011 done, verify NO "Uncategorized" visible en home
- **Sprint:** **S1** (subsumed por F-PRE-011)

---

### F-PRE-003 — Popup module hardcoded a 1 step (welcome)

- **Esfuerzo revisado:** N/A (no se acciona)
- **DoD verificable:** N/A
- **Sprint:** **DEFERRED** — YAGNI por usuario explícito. NO refactor proactivo.
- **Acción mesa-23:** Documentar como "candidato refactor cuando aparezca segundo caso de uso" en BACKLOG-FUTURE.md, NO en sprints.

---

### F-PRE-004 — `next-volume` cron config Hostinger

- **Esfuerzo revisado:** **S (30 min)**:
  - Verificar Hostinger crontab (5 min)
  - Si no, agregar entry `*/5 * * * * wget -q -O - https://akibara.cl/wp-cron.php > /dev/null 2>&1` (5 min)
  - Disable WP_CRON in wp-config si lo agregamos a crontab real (5 min)
  - Smoke test: trigger manualmente y verify email next-volume sale (15 min)
- **DoD verificable:**
  - [ ] Hostinger crontab muestra entry wp-cron cada 5 min
  - [ ] `wp config get DISABLE_WP_CRON` → true (ya no depende de tráfico web)
  - [ ] `wp cron event run akb_next_volume_check` → verify dispatch sin error
  - [ ] Test con producto que tenga next-volume known → verify email sent (a alejandro.fvaras@gmail.com)
- **Smoke test post-deploy:**
  - `wp cron event list` → expect cron `akb_next_volume_check` listed con next_run sensato
  - Wait 5-10 min → verify cron actually ran (logs)
- **Rollback plan:** Remove crontab entry, restore `WP_CRON` enabled.
- **Dependencies:** Acceso SSH Hostinger para crontab edit
- **Sprint:** **S2** (no urgente, pero útil para tener crons reales firing)
- **Riesgo regresión:** Bajo

---

### F-PRE-005 — Consolidación `descuentos`/`welcome-discount`/`marketing-campaigns`

- **Esfuerzo revisado:** **L (2-3 días)** — refactor consolidación
- **Sprint:** **S3** — NO sprint inicial. Esperar a tener tráfico real para validar qué módulos se usan vs no.
- **Acción:** mesa-23 pone en BACKLOG con DoD a definir cuando se llegue al sprint.

---

### F-PRE-006 — `finance-dashboard` widget refactor

- **Esfuerzo revisado:** **L (2 días) — Opción B manga-specific minimal**
- **Sprint:** **S3**
- **Acción:** Mockup primero (mesa-13), luego implementación.

---

### F-PRE-007 — Brevo Free 300/día compartido

- **Esfuerzo revisado:** N/A (no es item de código, es decisión de billing)
- **Sprint:** **S+future** — disparador: cuando >50 clientes/mes
- **Acción:** Documentar trigger en growth-deferred roadmap (sección abajo).

---

### F-PRE-009 — Leftover plugin folders en uploads/

- **Esfuerzo revisado:** **S (1h)**:
  - Mesa-10 audit por PHP backdoors en `uploads/mailpoet/`, `uploads/wpseo-redirects/`, `uploads/wpml/`, `uploads/smush/` (30 min)
  - Si limpio: `rm -rf` esos folders + snapshot pre-delete (15 min)
  - Smoke test home + admin (15 min)
- **DoD verificable:**
  - [ ] Mesa-10 confirma 0 archivos PHP sospechosos en esos folders
  - [ ] Snapshot tar.gz de los folders pre-delete
  - [ ] `rm -rf` ejecutado
  - [ ] Home + admin funcionan
- **Sprint:** **S2** (no urgente, pero limpia disk space)

---

### F-PRE-010 — Cuenta `nightmarekinggrimm26`

- **Esfuerzo revisado:** **S (15 min)**:
  - Mesa-10 verifica si es customer real (orders? meta?) o cuenta sospechosa
  - Si sospechosa: incluir en SEC-P0-001 (delete con DOBLE OK)
  - Si real: documentar y dejar
- **Sprint:** **S1** (si security) o **info-only** (si real)

---

## Sprint 1 propuesto (foundation + critical security)

**Capacity:** 25h efectivas. Si 1ra semana incluye setup backups + DOBLE OK loops, el sprint puede deslizarse a 1.5 semanas.

### Sprint 1 — Items priorizados (orden de ejecución)

| # | Item | Esfuerzo | Dependencies | Acumulado |
|---|---|---|---|---|
| 1 | **SEC-P0-001** — Delete admin backdoors (DOBLE OK + audit mesa-10 antes) | M (2h) | Mesa-10 audit user 6 posts | 2h |
| 2 | **F-PRE-001** — Limpiar BlueX key de logs + logger redact | M (3h) | Mesa-10 confirma scope | 5h |
| 3 | **F-PRE-008** — WP core integrity check | S (1h) | Ninguna | 6h |
| 4 | **F-PRE-011** — Productos test E2E → status private | S (30 min) | Ninguna | 6.5h |
| 5 | **F-PRE-012** — Bug agotado + preventa display | S (1h) | F-PRE-011 | 7.5h |
| 6 | **F-PRE-013** — Fix sale price layout broken | S (1.5h) | Ninguna | 9h |
| 7 | **F-PRE-014** — Verify Uncategorized hidden | S (15 min) | F-PRE-011 | 9.25h |
| 8 | **F-PRE-002** — Brevo SPF/DKIM/DMARC + tracker validation (DNS propagation async) | L (4h activo + 24h wait) | Acceso Cloudflare/Brevo | 13.25h |
| 9 | **CLEAN-001** — Delete sentry-customizations | S (45 min) | Ninguna | 14h |
| 10 | **CLEAN-003** — Delete brevo-smtp legacy | S (1.5h) | Grep references clean | 15.5h |
| 11 | **CLEAN-004** — Remove AKB_BREVO_API_KEY constante | S (20 min) | CLEAN-003 DONE | ~16h |
| 12 | **F-PRE-010** — Verify cuenta nightmarekinggrimm26 | S (15 min) | Mesa-10 audit | 16.25h |
| Buffer | Imprevistos / debugging / customer issues | — | — | +5-8h |

**Total estimado: ~22h efectivas + 5-8h buffer = 27-30h** → cabe en 1 semana SI el dev tiene 30h efectivas. Si está más cerca de 25h, deslizar items 11-12 a Sprint 2.

### Items NO en Sprint 1 (justificación)

- **CLEAN-002** (cart-abandoned local): bloqueado por F-PRE-002 24h validation → S2.
- **F-PRE-004** (next-volume cron): no urgente → S2.
- **F-PRE-005** (descuentos consolidation): refactor grande → S3.
- **F-PRE-006** (finance-dashboard): requiere mockup → S3.
- **F-PRE-009** (leftover plugin folders): cleanup no urgente → S2.

### Sprint 2 propuesto (cleanup + email infra)

- CLEAN-002 (cart-abandoned local delete, post Brevo validated)
- F-PRE-004 (next-volume cron Hostinger)
- F-PRE-009 (leftover plugin folders)
- + items de R1 que aparezcan en findings de mesa-02/05/10/15/22

---

## Growth-deferred roadmap (qué activar a qué milestone)

**Filosofía:** Diseñar para crecer, no para escalar prematuramente. 80% del código de marketing automations debe quedar **deferred** hasta que haya tráfico real para validar valor.

### Hoy (3 clientes/mes verificados)

**Foco:** Foundation (security, cleanup, baseline robusto).

**Activo:**
- Order confirmations transaccionales
- Brevo upstream "Carrito abandonado" workflow (workflow Activo, falta validation tracker)
- Order fulfillment (BlueX, MercadoPago, Flow integraciones)

**NO activar todavía:**
- Newsletter signup forms
- Welcome series (3 emails)
- Back-in-stock notifications
- Next-volume sale notifications (código existe, cron no firing)
- Marketing campaigns (CyberDay/BlackFriday)
- Popup welcome (no traffic real)
- Review request flow agresivo

### Milestone 1 — 5 clientes/mes (estable 1 mes)

**Trigger:** 5 órdenes/mes durante 1 mes consecutivo.

**Activar:**
- Newsletter signup form en home (footer + popup welcome) — REQUIERE MOCKUP mesa-13
- Welcome series email (1 email "gracias por suscribirte" → CTA categorías populares)
- Smoke test review-request post-delivery (ya existe código, validar flujo)

**Habilitadores técnicos requeridos:**
- F-PRE-002 DONE (Brevo sender domain verificado)
- F-PRE-007 monitorear cuota 300/día — sigue OK a 5 clientes/mes (~25 emails/mes transaccional)

### Milestone 2 — 25 clientes/mes (estable 2 meses)

**Trigger:** 25 órdenes/mes durante 2 meses consecutivos.

**Activar:**
- Welcome series multi-step (3 emails: bienvenida + categorías + descuento primera compra cupón WC)
- Back-in-stock notifications (single product page form)
- Next-volume sale email (cron firing real, validado en F-PRE-004)
- Review-request automation completa post-delivery

**Considerar:**
- Cuota Brevo Free: 300/día compartido. A 25 clientes/mes (~125 transactional/mes) + welcome (~25/mes nuevos suscriptores) + back-in-stock (variable) → llegamos al ~150 emails/mes, OK aún.

**NO activar todavía:**
- Marketing campaigns flexibles
- Popup welcome con segmentación
- Finance dashboard refactor (mantener vanilla widget)

### Milestone 3 — 50 clientes/mes (estable 2 meses)

**Trigger:** 50 órdenes/mes durante 2 meses.

**ACCIÓN OBLIGATORIA:** **Upgrade Brevo Free → Standard $18/mo** (de 300/día compartido a 20K/mes dedicado).
- Razón: 50 clientes/mes (~250 transactional) + automations (~200/mes) + abandoned cart (~50/mes) + back-in-stock + next-volume → puede saturar 300/día en spike days
- Riesgo si NO upgrade: order confirmation NO llega a cliente que compró → brand damage + dispute risk

**Activar:**
- Marketing campaigns (CyberDay/BlackFriday) — ahora con base real para validar conversion rate
- Refactor finance-dashboard manga-specific (F-PRE-006 Opción B) — mockup primero
- F-PRE-005 consolidación descuentos/welcome-discount/marketing-campaigns

**Considerar:**
- Performance audit completo (Lighthouse, query optimization, image lazy load) — ahora hay tráfico real para validar
- CDN para imágenes producto (Cloudflare Images o equivalente)

### Milestone 4 — 100 clientes/mes (estable 3 meses)

**Trigger:** 100 órdenes/mes durante 3 meses.

**Activar:**
- Segmentation Brevo (lectores manga shounen vs seinen vs josei vs cómics)
- Personalized recommendations email
- Loyalty/rewards program (cupones recurrent customers)
- A/B testing welcome popup (cuando hay tráfico para significancia)

**Considerar:**
- Hire ayuda externa (designer freelance, ad-hoc) — solo dev no escala más allá de este punto
- Staging environment dedicado (subdomain `staging.akibara.cl`)
- CI/CD básico (GitHub Actions deploy on push to `production` branch)
- Test suite robusto (PHPUnit + WP-Browser para flow completo checkout)

### Milestone 5+ — 250+ clientes/mes (no planificar todavía)

**Acción:** Re-audit completo. Probable necesidad de migrar arquitectura (microservices? headless? PHP versionado actualizado?).

---

## DoD universal template

Aplicable a TODO item del BACKLOG. Si un item NO cumple TODOS los aplicables, NO se marca DONE.

```markdown
## Definition of Done

### Aplica a TODOS los items
- [ ] Cambio commiteado a `main` con mensaje claro (qué + por qué + rollback)
- [ ] Smoke test post-cambio ejecutado (mínimo: home HTTP 200, producto test load, checkout flow basic)
- [ ] Documentación inline si requerida (PHPDoc para nuevas funciones públicas)

### Aplica si toca emails
- [ ] Test send a `alejandro.fvaras@gmail.com` (NUNCA cliente real)
- [ ] Verify Brevo dashboard muestra event registered
- [ ] Verify cuota Brevo no comprometida (chequear remaining/day)

### Aplica si toca prod (server)
- [ ] Snapshot pre-cambio en `.private/snapshots/YYYY-MM-DD-<item-id>.tar.gz`
- [ ] DOBLE OK explícito del usuario antes de ejecutar destructivo
- [ ] Rollback path verificado (probado mentalmente o en local)

### Aplica si toca DB
- [ ] Backup mysqldump pre-cambio en `.private/backups/YYYY-MM-DD-<item-id>.sql`
- [ ] Verify post-cambio (query de validación incluida)
- [ ] Tiempo rollback documentado < 30 min

### Aplica si toca branding/visual
- [ ] Mockup aprobado por usuario (linked en commit message)
- [ ] Verify mobile (390px) + desktop (1440px) ambos OK
- [ ] Mesa-13 ratifica (observador, no bloqueante pero recomendado)

### Aplica si toca pricing/cupones
- [ ] Verify NO se modifica `_sale_price`, `_regular_price`, `_price` directos
- [ ] Solo via WC_Coupon API
- [ ] Smoke test order con cupón → verify total correcto

### Aplica si toca preventa flow
- [ ] Regression test orden completo: reservar → admin recibe → fulfill → cliente recibe email
- [ ] Producto test 24262 (preventa) usado para test
```

---

## Findings PM

### F-23-001: CLEAN-002 está subestimado en CLEAN-SEEDS.md (M en lugar de L)

- **Severidad:** P2
- **Categoría:** SCOPE-RISK
- **Archivo(s):** `CLEAN-SEEDS.md` línea 26 (sizing M, debería ser L)
- **Descripción:** CLEAN-002 está marcado "M (2-4h)" pero realmente es L (1-2 días). La razón: depende de F-PRE-002 (Brevo upstream tracker validado) que requiere DNS propagation 1-24h. No se puede CLEAN-002 antes que F-PRE-002 esté DONE + 24h monitoring de que Brevo workflow recibe eventos. Si lead-01 ratifica CLEAN-002 como S1 en R2 sin debate, vamos a romper sprint.
- **Evidencia:** CLEAN-002 dice "depende de fix Brevo upstream tracker" pero no incluye 24h validation period en sizing.
- **Propuesta:** Lead-01 reasignar CLEAN-002 a Sprint 2. Sprint 1 = setup F-PRE-002, Sprint 2 = CLEAN-002 con Brevo verificado.
- **Esfuerzo:** N/A (es nota PM, no acción)
- **Sprint sugerido:** N/A (R2 lead decision)
- **Robustez ganada:** Evita regresión silenciosa donde clientes dejan de recibir email "olvidaste tu carrito" porque borramos local antes de validar upstream
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** N/A

### F-23-002: SEC-P0-001 está subestimado (S en lugar de M)

- **Severidad:** P2
- **Categoría:** SCOPE-RISK
- **Archivo(s):** `CLEAN-SEEDS.md` línea 82 (sizing S, debería ser M)
- **Descripción:** SEC-P0-001 está marcado "S (15 min)" pero NO incluye:
  - Mesa-10 audit user 6 posts (~47 posts) — 30 min
  - Mesa-10 audit cron jobs creados — 30 min
  - Mesa-10 audit modificaciones wp-config/.htaccess/mu-plugins — 30 min
  - DOBLE OK explícito al usuario (espera respuesta async)
  - Backup DB completo pre-deletion — 15 min
- **Evidencia:** Items pre-cleanup en CLEAN-SEEDS.md líneas 67-71 son trabajo real que mesa-10 hace, no son "asumidos hechos".
- **Propuesta:** Reasignar a M (~2h total) en BACKLOG R2.
- **Esfuerzo:** N/A
- **Sprint sugerido:** N/A
- **Robustez ganada:** Evita borrar user 6 con 47 posts legítimos (productos importados) sin reasign, dejando posts huérfanos
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** N/A

### F-23-003: Falta criterio "decision" para 3 P3 items con riesgo silent scope creep

- **Severidad:** P3
- **Categoría:** PROCESS-GAP
- **Archivo(s):** N/A (process)
- **Descripción:** F-PRE-003 (popup hardcoded), F-PRE-005 (descuentos overlap), F-PRE-006 (finance-dashboard) son refactors grandes. Riesgo: en R2/R3 algún agent técnico propone "limpio esto rápido" y se carga al sprint sin validation de que NO es YAGNI.
- **Propuesta:** Lead-01 establecer regla R2: "Cualquier item marcado L/XL tiene que ser presentado en R2 con justificación explícita de por qué NO es YAGNI antes de entrar a backlog". Si no hay justificación, va a `BACKLOG-FUTURE.md` no a sprints.
- **Esfuerzo:** N/A
- **Sprint sugerido:** N/A (process)
- **Robustez ganada:** Evita carga sprints con refactors que después no se usan
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** N/A

### F-23-004: Sin staging environment → items destructivos requieren proceso especial

- **Severidad:** P1
- **Categoría:** PROCESS-GAP
- **Archivo(s):** N/A (operational)
- **Descripción:** Sin staging, los items destructivos (CLEAN-001..004, SEC-P0-001, F-PRE-001 limpieza logs) van directo a prod. Si rompe, rollback obligatorio en <30 min. Hoy NO está garantizado el flujo "snapshot pre + DOBLE OK + smoke post + rollback documentado".
- **Evidencia:** Sin proceso documentado actualmente. Solo el dev sabe el flujo.
- **Propuesta:** Sprint 1 ítem 0 (pre-trabajo): documentar `RUNBOOK-DESTRUCTIVO.md` con:
  - Comando snapshot tar.gz template
  - Comando mysqldump backup template
  - Cómo pedir DOBLE OK (template texto a usuario)
  - Comando rollback rápido por tipo de cambio
- **Esfuerzo:** S (1h)
- **Sprint sugerido:** S1 ítem 0 (antes de cualquier destructivo)
- **Robustez ganada:** Garantiza rollback en <30 min para todos los items destructivos
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** Bajo (es solo doc)

### F-23-005: Mesa propone potencialmente 80+ findings en R1 pero capacity es 12-14 items en S1

- **Severidad:** P1
- **Categoría:** EXPECTATIONS-MISALIGN
- **Archivo(s):** N/A (process)
- **Descripción:** 8 agentes en R1 producen 5-15 findings cada uno = 40-120 findings totales esperados. Capacity Sprint 1 = 12-14 items. Si lead-01 carga el 30% top a S1 sin filtrar por restricciones operativas, sprint imposible.
- **Propuesta:** Lead-01 en R2 explicitar regla: "Top 12 items P0/P1 entran a S1, resto va a S2/S3+ con razón documentada del deferral. NO loadear S1 con >25h efectivas ajustadas por overhead operacional."
- **Esfuerzo:** N/A (process)
- **Sprint sugerido:** N/A
- **Robustez ganada:** Evita BACKLOG inejecutable
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** N/A

### F-23-006: Sin velocity histórico → primer sprint es experiment, no commitment

- **Severidad:** P3
- **Categoría:** PROCESS-GAP
- **Descripción:** Sin sprints previos medidos, las 25-30h efectivas/semana son asunción, no dato. Realidad puede ser 15h o 35h. Sprint 1 sirve también para CALIBRAR velocity real.
- **Propuesta:** Al final de Sprint 1, mesa-23 (en hipotético Round 8 retro) calcula: items committed vs items DONE, horas estimadas vs horas reales. Ajusta capacity para Sprint 2.
- **Esfuerzo:** N/A (process)
- **Sprint sugerido:** Post-S1 retro (no formal, solo doc 1 página)
- **Robustez ganada:** Sprints 2+ basados en datos, no asunción
- **Requiere mockup:** NO

### F-23-007: F-PRE-002 tiene dependency externa (DNS propagation) no controlable por dev

- **Severidad:** P2
- **Categoría:** SCHEDULE-RISK
- **Descripción:** F-PRE-002 (Brevo SPF/DKIM/DMARC + tracker validation) tiene DNS propagation 1-24h fuera del control del dev. Si Sprint 1 trata F-PRE-002 como "1 día de trabajo", el sprint slip es inevitable.
- **Propuesta:** Documentar F-PRE-002 con 2 fases:
  - Fase A (dev work): config DNS + plugin tracker activo — 1h
  - Fase B (espera + validate): 24h DNS propagation + smoke test cart abandonment — 0h dev pero 1-2 días calendario
  - Empezar F-PRE-002 día 1 del sprint para que Fase B se complete antes del fin del sprint
- **Esfuerzo:** N/A (es scheduling note)
- **Sprint sugerido:** S1 día 1
- **Robustez ganada:** Evita CLEAN-002 bloqueado al final del sprint
- **Requiere mockup:** NO

---

## Cross-cutting flags

- **Mesa-01 lead-arquitecto:** Establecer en R2 la regla "Sprint 1 max 25h efectivas adjusted, items L/XL requieren justificación NO-YAGNI explícita en R3 votación".
- **Mesa-10 security:** Audit user 6 posts ANTES de SEC-P0-001 ejecutar (47 posts pueden ser productos legítimos importados o injection).
- **Mesa-10 security:** Confirmar scope de F-PRE-001 (todas las ubicaciones de log con BlueX key) antes de SQL UPDATE.
- **Mesa-10 security:** Audit folders en `wp-content/uploads/` (mailpoet, wpseo-redirects, wpml, smush) para PHP backdoors antes de F-PRE-009.
- **Mesa-22 wp-master:** Validar approach `post_status=private` para productos test (vs filter por SKU vs categoría oculta) en F-PRE-011.
- **Mesa-09 email-qa:** Diseñar test plan F-PRE-002 con timer cart abandonment en Brevo (puede haber config para acortar 1h → 5 min para test).
- **Mesa-13 branding observador:** Ratificar que F-PRE-013 (sale price layout) es bug fix y NO requiere mockup nuevo.

---

## Áreas que NO cubrí (out of scope)

- **Findings técnicos específicos** de seguridad, código, infra, responsive, design — son owners de las otras 7 mesas en R1.
- **Calibración velocity real** — requiere data de Sprint 1 ejecutado, no se puede hacer en R1.
- **Mockups específicos para items que requieren visual** — mesa-13 observa, mockups vienen del usuario o designer freelance, no de mesa-23.
- **Decisiones de billing Brevo** (cuándo upgrade $18/mo) — recomendación documentada en growth-deferred roadmap, decisión final del usuario.
- **Estimaciones de revenue / conversion rates** — sin tráfico real para baseline (3 clientes/mes), cualquier proyección sería ficción.
- **Análisis de competencia / market sizing** — fuera del scope técnico-operacional de mesa-23.
- **Hiring / freelancer planning** — mencionado en milestone 4 pero decisión del usuario, no del PM agent.
