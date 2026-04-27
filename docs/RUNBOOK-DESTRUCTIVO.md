# RUNBOOK destructivo Akibara

**Fecha:** 2026-04-26 (creado en B-S1-SETUP-00, Sprint 1)
**Owner:** Alejandro Vargas (solo dev)
**Status:** Vigente. Actualizar cuando cambie el procedimiento real.

> Este runbook es **prerequisito BLOQUEANTE** de cualquier item destructivo del BACKLOG. Sin snapshot + DB backup + DOBLE OK explícito, ningún item destructivo se ejecuta.
>
> Convención: prod = `akibara.cl` en Hostinger, alias SSH `akibara`, ruta `/home/u888022333/domains/akibara.cl/public_html`. Wrappers en `bin/wp-ssh` (wp-cli vía SSH), `bin/mysql-prod` (cliente MariaDB vía túnel localhost:3308), `bin/db-tunnel` (gestiona el túnel SSH).

---

## 0. Checklist pre-destructivo (8 puntos, todos obligatorios)

Antes de ejecutar cualquier comando destructivo verificar **en este orden**:

- [ ] **1. Snapshot filesystem** del o los directorios afectados (`.private/snapshots/YYYY-MM-DD-<item-id>.tar.gz`).
- [ ] **2. Backup DB** completo (`.private/backups/YYYY-MM-DD-<item-id>-FULL.sql`) — siempre full, incluso si el cambio es de una sola tabla.
- [ ] **3. DOBLE OK explícito del usuario** capturado (literal: `OK` + `OK` o frase equivalente clara).
- [ ] **4. Rollback plan documentado** y probado mentalmente (tiempo objetivo ≤30 min).
- [ ] **5. Smoke test post-cambio** definido y ejecutable (curl + wp-cli + UI manual breve).
- [ ] **6. Sentry baseline** capturado (lista de issues/tags abiertos antes del cambio para comparar 24h después).
- [ ] **7. Escalation path** definido (qué hacer si el cambio rompe algo no previsto — restaurar tar.gz primero, después DB).
- [ ] **8. ADR o nota** si el cambio afecta arquitectura, mu-plugins load-bearing, o rompe el contrato de algún módulo.

Si **cualquiera** de los 8 puntos no está listo: STOP, no ejecutar.

---

## 1. Templates de comando

### 1.1 Snapshot filesystem (server)

```bash
# Snapshot del directorio afectado, ejemplo plugins/akibara/
ITEM_ID="b-s1-sec-03"   # cambiar por item del BACKLOG
DATE=$(date +%Y-%m-%d)
SCOPE="public_html/wp-content/plugins/akibara"   # ajustar al scope real

ssh akibara "tar -czf /tmp/akb-${DATE}-${ITEM_ID}.tar.gz /home/u888022333/domains/akibara.cl/${SCOPE}"
ssh akibara "ls -lh /tmp/akb-${DATE}-${ITEM_ID}.tar.gz"
scp "akibara:/tmp/akb-${DATE}-${ITEM_ID}.tar.gz" ".private/snapshots/${DATE}-${ITEM_ID}.tar.gz"
ls -lh ".private/snapshots/${DATE}-${ITEM_ID}.tar.gz"
```

Para snapshot completo `wp-content/`:

```bash
ssh akibara "tar -czf /tmp/akb-${DATE}-${ITEM_ID}-full.tar.gz \
  /home/u888022333/domains/akibara.cl/public_html/wp-content"
scp "akibara:/tmp/akb-${DATE}-${ITEM_ID}-full.tar.gz" ".private/snapshots/"
```

Después del scp, opcional: `ssh akibara "rm /tmp/akb-${DATE}-${ITEM_ID}*.tar.gz"`.

### 1.2 Backup DB completo (mysqldump)

`bin/mysql-prod` es el cliente, no dump. Para dump usar SSH directo:

```bash
ITEM_ID="b-s1-sec-02"
DATE=$(date +%Y-%m-%d)

# Dump remoto y stream local (nunca dejar el dump en server)
ssh akibara "mysqldump --single-transaction --quick --skip-lock-tables \
  -u u888022333_admin -p'<DB_PASSWORD>' u888022333_akibara_db" \
  > ".private/backups/${DATE}-${ITEM_ID}-FULL.sql"

ls -lh ".private/backups/${DATE}-${ITEM_ID}-FULL.sql"
head -3 ".private/backups/${DATE}-${ITEM_ID}-FULL.sql"   # verify "MySQL dump"
gzip -k ".private/backups/${DATE}-${ITEM_ID}-FULL.sql"   # opcional, ahorra espacio
```

DB credentials: leer de `.env` (variables `PROD_DB_USER`/`PROD_DB_PASSWORD`/`PROD_DB_NAME`) — nunca pegar la password en este runbook.

Backup de **una sola tabla** (cuando el alcance lo amerite — ej. `wp_bluex_logs` antes de TRUNCATE):

```bash
ssh akibara "mysqldump --single-transaction \
  -u u888022333_admin -p'<DB_PASSWORD>' u888022333_akibara_db wp_bluex_logs" \
  > ".private/backups/${DATE}-${ITEM_ID}-wp_bluex_logs.sql"
```

> **Regla:** aun para una sola tabla, hacer **además** un dump completo. El cliente puede pedir rollback de algo no previsto y el costo de un dump full extra es minutos.

### 1.3 wp-config.php / .htaccess defensa (revert via git)

Antes de tocar `wp-config.php` o `.htaccess` en server, capturar estado actual:

```bash
ssh akibara "cat /home/u888022333/domains/akibara.cl/public_html/wp-config.php" \
  > ".private/snapshots/${DATE}-${ITEM_ID}-wp-config.php.bak"
ssh akibara "cat /home/u888022333/domains/akibara.cl/public_html/.htaccess" \
  > ".private/snapshots/${DATE}-${ITEM_ID}-htaccess.bak"
```

Restore: rsync inverso o `scp` puntual.

### 1.4 Plugin / theme deactivate seguro

```bash
# Pre-deactivate: anotar plugins activos
bin/wp-ssh plugin list --status=active --format=csv > ".private/snapshots/${DATE}-${ITEM_ID}-plugins-active.csv"

# Deactivate
bin/wp-ssh plugin deactivate <slug>

# Si rompe algo: re-activate
bin/wp-ssh plugin activate <slug>
```

Para themes (cuidado, cambiar el active theme rompe el front):

```bash
bin/wp-ssh theme list --format=csv > ".private/snapshots/${DATE}-${ITEM_ID}-themes.csv"
# Activate child fallback antes de tocar el active
```

---

## 2. Plantilla de pedido DOBLE OK al usuario

Cuando un agente (Claude o humano) llegue a un punto destructivo, **debe pedir confirmación al usuario en este formato**:

```
🛑 DESTRUCTIVO — DOBLE OK requerido

Item: <B-S1-SEC-NN nombre del item>
Acción concreta: <comando exacto a ejecutar, copy-paste-ready>
Blast radius: <qué se afecta — tabla, usuario, archivo, deploy, etc.>
Pre-requisitos verificados:
  ✅ Snapshot: .private/snapshots/<archivo>
  ✅ Backup DB: .private/backups/<archivo>
  ✅ Smoke test post: <comando>
  ✅ Rollback time estimado: <≤30 min>
Riesgos residuales: <qué podría salir mal aunque rollback funcione>

¿OK? Necesito doble confirmación literal: responde "OK" y luego "OK" o equivalente claro.
```

**Si el usuario responde con un solo OK ambiguo, pedir el segundo OK explícito.** No proceder hasta tener los dos.

**Si el usuario responde con duda o pregunta, parar y aclarar.** No proceder en zona gris.

---

## 3. Rollback rápido por tipo de cambio

Tiempo objetivo ≤30 min para todos los rollbacks.

### 3.1 Rollback código (plugin / theme / mu-plugin)

```bash
ITEM_ID="b-s1-sec-03"
DATE=$(date +%Y-%m-%d)

# Restaurar snapshot via rsync (solo el path afectado)
scp ".private/snapshots/${DATE}-${ITEM_ID}.tar.gz" "akibara:/tmp/"
ssh akibara "cd /home/u888022333/domains/akibara.cl && \
  tar -xzf /tmp/akb-${DATE}-${ITEM_ID}.tar.gz"

# LiteSpeed cache purge
ssh akibara "wp litespeed-purge all" 2>/dev/null || \
  bin/wp-ssh cache flush

# Verificar
curl -o /dev/null -w "%{http_code}\n" https://akibara.cl/   # 200
```

Tiempo: 5-10 min.

### 3.2 Rollback DB (full restore)

```bash
ITEM_ID="b-s1-sec-02"
DATE=$(date +%Y-%m-%d)

# Stream restore vía SSH (NO dejar el dump en el server)
ssh akibara "mysql -u u888022333_admin -p'<DB_PASSWORD>' u888022333_akibara_db" \
  < ".private/backups/${DATE}-${ITEM_ID}-FULL.sql"

# Verificar
bin/wp-ssh db query "SELECT COUNT(*) FROM wp_users;"   # debe coincidir con pre-cambio
bin/wp-ssh user list --role=administrator --format=count
```

Tiempo: 5-15 min (dependiendo del tamaño del dump, ~30 MB ~ 1-2 min).

### 3.3 Rollback DB de una tabla (partial)

```bash
# Para wp_bluex_logs ejemplo
ITEM_ID="b-s1-sec-04"
DATE=$(date +%Y-%m-%d)

# Restaurar solo la tabla
ssh akibara "mysql -u u888022333_admin -p'<DB_PASSWORD>' u888022333_akibara_db" \
  < ".private/backups/${DATE}-${ITEM_ID}-wp_bluex_logs.sql"

bin/mysql-prod -e "SELECT COUNT(*) FROM wp_bluex_logs;"
```

Tiempo: 1-5 min.

### 3.4 Rollback config (wp-config / .htaccess)

```bash
DATE=$(date +%Y-%m-%d)
ITEM_ID="<id>"

# Subir bak directamente al path
scp ".private/snapshots/${DATE}-${ITEM_ID}-wp-config.php.bak" \
  "akibara:/home/u888022333/domains/akibara.cl/public_html/wp-config.php"
scp ".private/snapshots/${DATE}-${ITEM_ID}-htaccess.bak" \
  "akibara:/home/u888022333/domains/akibara.cl/public_html/.htaccess"

# Verificar
curl -o /dev/null -w "%{http_code}\n" https://akibara.cl/   # 200
```

Tiempo: 2-5 min.

### 3.5 Rollback user delete

Solo posible vía DB restore (3.2). WP no permite `wp user undelete`. Por eso el dump full es obligatorio antes de cualquier `wp user delete`.

Tiempo: 5-15 min (DB full restore).

### 3.6 Rollback plugin/theme deactivation

```bash
bin/wp-ssh plugin activate <slug>
# O para múltiples:
bin/wp-ssh plugin activate $(cat .private/snapshots/${DATE}-${ITEM_ID}-plugins-active.csv | cut -d, -f1 | tail -n+2)
```

Tiempo: <1 min.

---

## 4. Items destructivos del BACKLOG (mapa de referencia)

Lista nominal de items del BACKLOG-2026-04-26 que **deben** pasar por este runbook antes de ejecutar.

### Sprint 1

| Item | Acción destructiva | Scope | Backup mínimo |
|---|---|---|---|
| **B-S1-SEC-02** | `wp user delete 5/6/7/8` (+ user 18 condicional) | DB `wp_users`, `wp_usermeta`, `wp_posts` (reassign) | DB FULL |
| **B-S1-SEC-03** | rsync sin `vendor/`, `coverage/`, `tests/` + `.htaccess` defensivo | FS plugins akibara/akibara-reservas, theme akibara | Snapshot 3 plugins/themes |
| **B-S1-SEC-04** | `TRUNCATE TABLE wp_bluex_logs` + key migration a `wp-config-private.php` | DB tabla + wp-config.php | DB tabla + wp-config.php.bak |
| **B-S1-EMAIL-02** | Editar Hostinger crontab UI | Cron schedule | Screenshot crontab pre |
| **B-S1-CLEAN-01** | `rm inc/enqueue.php.bak-2026-04-25-pre-fix` | FS theme | Snapshot themes/akibara |
| **B-S1-CLEAN-02** | `rm themes/akibara/hero-section.css` | FS theme | Snapshot themes/akibara |
| **B-S1-CLEAN-03** | `rm` duplicado entre `themes/akibara/setup.php` y `inc/setup.php` | FS theme | Snapshot themes/akibara |

### Sprint 2+ (referencia, fuera de scope hoy)

| Item | Acción destructiva |
|---|---|
| **CLEAN-002** (condicional) | Eliminar tracker cart-abandoned local (post validar Brevo upstream firing 24-48h) |
| **CLEAN-005** | Idem B-S1-SEC-03 (ya cubierto Sprint 1) |
| **CLEAN-009** | Eliminar archivos legacy específicos |
| **CLEAN-010** | Idem B-S1-SEC-04 (ya cubierto Sprint 1) |
| **CLEAN-011** | Eliminar mu-plugins no load-bearing (lista por revisar) |
| **CLEAN-012** | Idem B-S1-SEC-02 |
| **CLEAN-013** / **CLEAN-014** | Cleanup leftover sprint 2-3 |
| **CLEAN-015** (ga4 disable) | Archive `modules/ga4/` + remove `AKB_GA4_API_SECRET` |
| **CLEAN-016** (finance disable) | Disable `modules/finance-dashboard/` (rebuild manga-specific Sprint 3) |
| **CLEAN-017** (series-autofill migration) | Mover migration class a legacy CLI |

---

## 5. Smoke tests post-destructivo (mínimo común)

Después de cualquier cambio destructivo, ejecutar al menos:

```bash
# 1. Home pública responde
curl -s -o /dev/null -w "home %{http_code}\n" https://akibara.cl/

# 2. Producto test E2E disponible (24261) carga (logged-out)
curl -s -o /dev/null -w "product 24261 %{http_code}\n" \
  "https://akibara.cl/?p=24261"

# 3. Admin login redirect funciona
curl -s -o /dev/null -w "admin redirect %{http_code}\n" \
  https://akibara.cl/wp-admin/

# 4. WP healthy
bin/wp-ssh core is-installed && echo "✅ WP installed"
bin/wp-ssh option get blogname

# 5. WC healthy
bin/wp-ssh wc product list --user=1 --limit=1 --format=count

# 6. wp-config-private.php (cuando exista) NO se sirve
curl -o /dev/null -w "wp-config-private %{http_code}\n" \
  https://akibara.cl/wp-config-private.php   # debe ser 403 o 404

# 7. Sentry baseline issues sin nuevos
# (chequear panel Sentry manualmente — buscar issues "first seen" en última hora)
```

Si cualquiera de los 7 falla → ejecutar rollback inmediato (sección 3) sin esperar.

---

## 6. Sentry monitor 24h post-deploy

Después de un cambio destructivo en prod:

- **Día 0** (al ejecutar): capturar lista de Sentry issues abiertos (URL + tag + count) en `audit/sprint-{N}/sentry-baseline-${ITEM_ID}.txt`.
- **Día 0+1h**: revisar Sentry dashboard. Si hay **issue nuevo** "first seen" en última hora con `release` posterior al deploy → investigar inmediatamente. Si tiene impacto cliente → rollback.
- **Día 0+24h**: comparar lista actual con baseline. Cero issues nuevos = done. Issues nuevos no relacionados al cambio = log en commit, sin rollback. Issues nuevos relacionados = decidir rollback vs hotfix forward.

Comando manual para chequear breadcrumbs:

```bash
ssh akibara "tail -200 /home/u888022333/domains/akibara.cl/public_html/wp-content/debug.log 2>/dev/null"
```

---

## 7. Escalation path (cuando rollback no basta)

Si después de rollback el sitio sigue caído o degradado:

1. **Notificar al usuario inmediatamente** con:
   - Comando ejecutado
   - Comando rollback intentado
   - Estado actual (output de smokes)
   - Hipótesis del problema
2. **Restaurar snapshot completo** `wp-content/` desde último tar.gz conocido bueno.
3. **Restaurar DB completo** desde `.private/backups/` o desde Hostinger backup (panel) — Hostinger retiene 7 días.
4. **Si Hostinger backup también roto**: contactar soporte Hostinger. Mientras tanto activar maintenance mode (`bin/wp-ssh maintenance-mode activate`).
5. **Documentar incidente** en `audit/incidents/YYYY-MM-DD-<short-name>.md` con timeline, root cause, recovery steps, prevention plan.

---

## 8. Histórico de uso

Cada vez que este runbook se aplique, agregar entrada al final:

```
| Fecha | Item | Snapshot path | Backup path | DOBLE OK ts | Rollback usado | Outcome |
|---|---|---|---|---|---|---|
| YYYY-MM-DD | B-S1-XXX | .private/snapshots/... | .private/backups/... | HH:MM | NO/SÍ + tipo | ✅/❌ + nota |
```

(Tabla vacía hoy — aún no se ejecuta ningún destructivo.)
