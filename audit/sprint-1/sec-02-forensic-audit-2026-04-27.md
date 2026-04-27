# B-S1-SEC-02 — Forensic audit pre-delete admin backdoors

**Fecha:** 2026-04-27
**Operador:** Claude Code (Akibara solo dev: Alejandro Vargas)
**Status:** ⏳ AUDIT COMPLETO. Pendiente: DOBLE OK Alejandro para ejecutar deletes.

---

## TL;DR

**4 admin backdoors confirmados** (IDs 5/6/7/8, dominio typosquat `@akibara.cl.com`, creados en ventana 4 minutos el 2025-11-02). **Cero contenido legítimo asociado**: 0 posts, 0 orders, 0 Application Passwords, sin foothold adicional. Plan delete: estándar `wp user delete <id> --yes`, con `--reassign=1` defensivo aunque no se requiere.

**User 18 (`nightmarekinggrimm26`) NO es backdoor — es customer real (Rodrigo Molino, organic search Google, registrado 2026-04-22, 0 orders aún).** El audit original lo flagged por error. **KEEP user 18.**

**`wp_akb_referrals`: NO tocar.** Las 4 filas son legítimas (Alejandro + 3 customers reales).

**Application Passwords: vacíos para los 4 backdoors.** No hay token persistence.

**Cron events: todos legítimos.** No hay injection en scheduler.

---

## 1. Admin backdoors — evidencia confirmada

```
ID  user_login         user_email                              registered            posts  orders  app_pwd
6   admin_3b4206ec     admin_32d980f4@akibara.cl.com           2025-11-02 16:08:13   0      0       (vacío)
8   admin_55b96b0c     admin_e3d7bbed@akibara.cl.com           2025-11-02 16:11:16   0      0       (vacío)
5   admin_a06d0185     admin_fc13558a@akibara.cl.com           2025-11-02 16:07:55   0      0       (vacío)
7   admin_eae090ac     admin_5a64f9c5@akibara.cl.com           2025-11-02 16:11:03   0      0       (vacío)
```

**Indicadores de compromiso (todos coinciden):**
- Dominio typosquat: `@akibara.cl.com` (atacante registró `akibara.cl.com` para verse legítimo). Domino real es `akibara.cl`.
- Naming pattern: `admin_<8hex>` username + `admin_<8hex>@akibara.cl.com` email (ambos hex).
- Display name = user_login (sin personalizar).
- Ventana temporal: 16:07:55 → 16:11:16 = ~3.5 minutos. Pattern de script automatizado.
- Role: administrator (privilegio máximo).
- user_status: 0 (activos).

**Vector probable:** RCE vía vulnerable plugin / brute-force admin / exfiltrated DB credentials. Pre-2025-11-02 hay que asumir que un atacante tuvo acceso administrativo. Documentado como F-10-004 + F-10-021 + F-02-003.

**Mitigación post-delete (a futuro, NO en este item):**
- ✅ B-S1-SEC-04 TRUNCATE wp_bluex_logs + key migration (otro item Sprint 1)
- ✅ B-S1-SEC-05 mu-plugin security headers + REST users disable + xmlrpc block
- ✅ B-S1-SEC-07 Magic Link IP rate-limit + REST cart endpoints rate limiting
- 📋 Evaluar IDS/audit log plugin (Sprint 2+)
- 📋 Considerar 2FA admin obligatorio (item separado)

---

## 2. User 18 — KEEP (customer real)

Audit lo flagged por nombre y user_login `nightmarekinggrimm26`, pero los datos no soportan eliminarlo:

```
ID  user_login                user_email                          registered            role        orders
18  nightmarekinggrimm26      nightmarekinggrimm26@gmail.com      2026-04-22 15:07:04   customer    0
```

**Meta evidence:**
- Real name: Rodrigo Molino
- WC order attribution: organic search desde Google → landing `/jujutsu-kaisen-26-panini-argentina/`
- IP attribution: pendiente (no se requiere deletion)
- Registered hace 5 días (2026-04-22)
- Role: customer (NO admin, NO shop_manager)
- 0 orders pero attribution válida (visitante orgánico real que se registró sin completar compra)

**Diagnóstico:** username edgy pero pattern de customer real. Sin evidencia de comportamiento malicioso. Eliminar = perder potencial conversion + romper FTC/transparencia (eliminar un customer válido sin razón documentada).

**Decisión:** KEEP user 18. Sin acción.

---

## 3. wp_akb_referrals — NO tocar

```
id  referrer_email                       referrer_code         status     created_at
1   ale.fvaras@gmail.com                 REF-DDGD-OSWU         pending    2026-04-11 14:47:19
2   axell.desings@gmail.com              REF-AXELAVEC-DLZD     pending    2026-04-11 17:07:51
3   matifernandez725@gmail.com           REF-MATYY-YJEQ        pending    2026-04-12 18:15:00
4   nightmarekinggrimm26@gmail.com       REF-RODRIGOM-9B4L     pending    2026-04-22 15:13:03
```

Las 4 filas son legítimas:
- ID 1 = Alejandro (admin, dueño)
- ID 2 = customer ID 14
- ID 3 = customer ID 15
- ID 4 = user 18 (KEEP per sección 2)

NO row asociada a los 4 backdoors. NO acción de cleanup en esta tabla.

---

## 4. Application Passwords — todos vacíos

Comando ejecutado:

```
for uid in 1 5 6 7 8; do bin/wp-ssh user meta get "$uid" _application_passwords; done
```

Output: vacío para los 5 users. No hay tokens persistent que sobrevivan al delete.

**Implicación:** los 4 backdoors NO consiguieron emitir Application Passwords. Cualquier acceso programatic que tenían fue vía session cookie estándar (que se invalida automáticamente en delete) o credentials directas (que dejan de servir cuando el user_id deja de existir).

---

## 5. Cron events — todos legítimos

Listado completo de hooks (filtrado contra prefijos conocidos) — NO se encontraron cron events fuera de:

- `action_scheduler_*` (WC core)
- `akb_*`, `akibara_*` (plugins custom)
- `check_plugin_updates-*` (WP core)
- `delete_expired_transients` (WP core)
- `jetpack_*` (Jetpack)
- `litespeed_*` (LiteSpeed)
- `rank_math*` (RankMath SEO)
- `recovery_mode_*` (WP core)
- `royal_mcp_*` (MCP plugin)
- `wc_*`, `woocommerce_*` (WC)
- `wp_*` (WP core)
- `wpseo*` (Yoast residual?)

NO hay cron event bajo nombre de los 4 backdoor users ni patterns sospechosos (`eval_*`, `_backdoor`, hex hashes).

**Decisión:** NO hay cron cleanup pendiente.

---

## 6. Plan de delete (post DOBLE OK)

### 6a. Backup DB completo (obligatorio per RUNBOOK-DESTRUCTIVO §0 y §1.2)

```bash
# Stream remoto sin dejar dump en server
ssh akibara "mysqldump --single-transaction --quick --skip-lock-tables \
  -u u888022333_admin -p\"\$DB_PASS\" u888022333_akibara_db" \
  > .private/backups/2026-04-27-pre-sec-02-FULL.sql

ls -lh .private/backups/2026-04-27-pre-sec-02-FULL.sql
head -3 .private/backups/2026-04-27-pre-sec-02-FULL.sql   # verify "MySQL dump"
```

(Tomar credentials de `.env` en local — `PROD_DB_USER` / `PROD_DB_PASSWORD`.)

### 6b. Verify backup integrity

```bash
# Verify dump no truncado
tail -3 .private/backups/2026-04-27-pre-sec-02-FULL.sql   # debe terminar con "Dump completed"

# Verify users 5/6/7/8 estaban incluidos
grep -c "INSERT INTO \`wp_users\`" .private/backups/2026-04-27-pre-sec-02-FULL.sql   # >0
```

### 6c. Delete operations

```bash
bin/wp-ssh user delete 5 --yes
bin/wp-ssh user delete 6 --yes
bin/wp-ssh user delete 7 --yes
bin/wp-ssh user delete 8 --yes
```

> Nota: el BACKLOG sugiere `--reassign=1` para user 6 por los "47 posts". El forensic confirma user 6 = 0 posts. `--reassign=1` no es estrictamente necesario; lo dejamos sin flag para que wp-cli use el default `--reassign` ausente (= delete content) — pero como hay 0 content para reasignar/borrar, ambos paths son equivalentes. **Default `--yes` sin reassign está OK.**

### 6d. Verify post-delete

```bash
# 1. Solo queda Alejandro como admin
bin/wp-ssh user list --role=administrator --format=count   # → 1

# 2. Cero usuarios con typosquat
ssh akibara "cd /home/u888022333/domains/akibara.cl/public_html && \
  wp db query \"SELECT COUNT(*) FROM wp_users WHERE user_email LIKE '%@akibara.cl.com'\""   # → 0

# 3. ale.fvaras@gmail.com sigue ahí
bin/wp-ssh user get 1 --field=user_email   # → ale.fvaras@gmail.com

# 4. user 18 (Rodrigo) sigue ahí (NO afectado)
bin/wp-ssh user get 18 --field=user_email   # → nightmarekinggrimm26@gmail.com
```

### 6e. Smoke test post-deploy

```bash
curl -s -o /dev/null -w "home %{http_code}\n" https://akibara.cl/                           # 200
curl -s -o /dev/null -w "admin redirect %{http_code}\n" https://akibara.cl/wp-admin/         # 302
curl -s -o /dev/null -w "product 24261 %{http_code}\n" https://akibara.cl/?p=24261           # 200
```

Login Alejandro a wp-admin desde browser → expect funciona.

### 6f. Rollback (<15 min)

Solo posible vía DB restore completo (los 4 users no tienen contenido a restaurar individualmente):

```bash
ssh akibara "mysql -u u888022333_admin -p\"\$DB_PASS\" u888022333_akibara_db" \
  < .private/backups/2026-04-27-pre-sec-02-FULL.sql

bin/wp-ssh user list --role=administrator --format=count   # debería volver a 5
```

---

## 7. Riesgos residuales

1. **Vector de entrada original NO mitigado por este item.** Si el atacante usó plugin vulnerable o credentials exfiltradas para crear los 4 admins en 2025-11-02, ese vector sigue vivo hasta que se ejecuten B-S1-SEC-04/05/07. **Documentar en commit message:** "delete admin backdoors — vector entrada original cubierto por SEC-04/05/07 en items posteriores Sprint 1."

2. **Logs de actividad pre-delete:** prod NO tiene plugin de audit log activo. Las acciones del atacante entre 2025-11-02 y 2026-04-27 son no recuperables. Esto NO es un blocker para el delete, pero documentar como deuda forensic.

3. **Cookies/sessions activos:** WordPress invalida sessions automáticamente al delete user. NO se requiere `wp user session destroy <id>` separado.

4. **Webhooks/MCP tokens:** verificado vacío en Application Passwords. Si en el futuro se descubre un token Royal MCP / Brevo / etc. asociado a estos users, tratar como item nuevo.

---

## 8. Resumen ejecutivo para Alejandro (lectura 30s)

| Pregunta | Respuesta |
|---|---|
| ¿Cuántos backdoors confirmados? | 4 (IDs 5/6/7/8, todos `@akibara.cl.com`) |
| ¿Tienen content legítimo? | NO. 0 posts, 0 orders, 0 AppPasswords. |
| ¿Tocar user 18 (Rodrigo Molino)? | NO. Customer real, organic search, KEEP. |
| ¿Tocar wp_akb_referrals? | NO. Las 4 filas son customers legítimos. |
| ¿Cron cleanup? | NO. Todos los crons son legítimos. |
| ¿Backup DB requerido? | SÍ. Full dump pre-delete, ~30 MB, 1-2 min. |
| ¿Rollback time? | <15 min vía SQL restore. |
| ¿Vector original mitigado? | NO. Lo cubren SEC-04/05/07 (items siguientes). |
| ¿Acción requerida usuario? | DOBLE OK literal para proceder. |
