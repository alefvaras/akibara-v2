# Sesión Akibara V2 — continuación

> **Cómo se usa:** copia este archivo entero como primer mensaje al abrir una sesión nueva en `~/Documents/akibara-v2/`. Es un starter prompt corto; el contexto completo está en `PROMPT-INICIO-AKIBARA-V2.md` §10.

---

Estás trabajando conmigo en **Akibara** (https://akibara.cl), tienda de manga Chile. Workspace: `~/Documents/akibara-v2/`. Trabajo solo. Stack: WP + WooCommerce + plugin/tema custom `akibara`. Hosting Hostinger.

## Antes de hacer NADA

1. `cd ~/Documents/akibara-v2/`
2. Lee `PROMPT-INICIO-AKIBARA-V2.md` completo — especialmente **§10** (estado al cierre de la sesión anterior).
3. Verifica stack vivo:
   ```bash
   docker compose ps && bin/db-tunnel status && git log --oneline -5
   ```
4. Si `mariadb` está down: `docker compose up -d mariadb`. Si vas a consultar DB prod y el tunnel está down: `bin/db-tunnel up`.
5. Confirma en una respuesta breve qué entendiste del §10 y propón el próximo paso. Espera mi decisión antes de arrancar trabajo nuevo.

## Reglas duras (NO NEGOCIABLES — releer §1 del MD largo)

- **Tuteo chileno neutro.** PROHIBIDO voseo (confirmá/hacé/tenés/podés/vos).
- **Email testing** siempre a `alejandro.fvaras@gmail.com`. Jamás cliente real.
- **NO modificar precios** (`_sale_price`, `_regular_price`, `_price`). Descuentos solo cupones WC nativos.
- **NO instalar plugins third-party.** Custom only.
- **DOBLE OK explícito** para destructivos en server (rm, drop, truncate, delete masivo).
- **Productos test 24261/24262/24263** ya tienen fixes aplicados (§10.8). NO tocar salvo nuevos fixes específicos con OK.
- **Read-only en prod por defecto.** Inspección OK; escribir requiere OK explícito.
- **Honestidad total** — reportar fallas, dudas, ambigüedad apenas la detectes. Cero acciones silenciosas.
- **Branding** pulido — cambios visuales requieren MOCKUP previo aprobado.
- **NO instalar PHP/Node/Composer/WP-CLI/MariaDB vía Homebrew.** Todo va por Docker (OrbStack). Se desinstaló intencionalmente — no reinstalar. Usar `bin/php`, `bin/composer`, `bin/node`, `bin/wp`, etc.
- **Commit directo a `main`** — sin feature branches.

## Estado del plan ejecutivo

| Paso | Estado |
|------|--------|
| 0 — Sync + audit + cleanup | ✅ — server -900 MB, reporte en `audit/cleanup-report-2026-04-26.md` |
| 1 — Verify setup | ✅ |
| 2 — git init + .gitignore | ✅ — branch `main` local, sin remote |
| 3 — Mesa técnica auditoría | ⏸ ~85 unidades, 14 agentes × 2 iteraciones, ~5–6h |
| 4 — Scripts workflow | ⏸ |
| 5 — CLAUDE.md mínimo | ⏸ |
| 6 — Smoke E2E producto 24261 | ⏸ — corre contra prod, no contra WP local |
| 7 — Renombrar repo viejo | n/a — `~/Documents/akibara/` no existe |

**Fixes aplicados a prod:**
- 24262 (Preventa): `_akb_reserva=yes`, `_akb_reserva_tipo=preventa`
- 24263 (Agotado): categoría → solo `Uncategorized` (Preventas removida)

## Tooling clave

| Comando | Qué hace |
|---------|----------|
| `bin/wp-ssh post meta list 24262` | wp-cli contra prod via SSH |
| `bin/mysql-prod -e "SELECT ..."` | query a DB prod via tunnel (auto-arranca tunnel) |
| `bin/db-tunnel {up,down,status}` | gestiona tunnel localhost:3308 → akibara:127.0.0.1:3306 |
| `bin/php`, `bin/composer`, `bin/wp` | CLIs vía Docker, montan el proyecto |
| `docker compose up -d mariadb` | levanta DB local (no es prod) |

## Recordatorios finales

- NO hagas operaciones destructivas sin DOBLE OK explícito.
- NO toques `wp-content/uploads/`, `wp-config.php`, `.htaccess`, ni `akibara*` en plugins/themes/mu-plugins sin justificación clara.
- NO toques productos test salvo los fixes específicos descritos en §10.8 del MD largo.
- Reporta cualquier ambigüedad o riesgo apenas lo detectes — honestidad total > apariencia de progreso.
