# Sprint 2 Condition #2 — Serialization Plan 4 CLEAN Destructivos

**Fecha:** 2026-04-27
**Origen:** ARCHITECTURE-PRE-REVIEW.md §Risk register #4 + DoD §CLEAN
**Regla mesa-15:** *"1 destructivo por week + 24h Sentry gap entre cada uno."*
**Memoria activa:** `feedback_robust_default` + `feedback_minimize_behavior_change`

---

## Distinción entre tipos de "destructivo"

Para no hacer Sprint 2 inviable, distinguimos 2 categorías:

### Categoría A — CLEAN destructivos (irreversibles)
- DROP TABLE, delete folder, delete class file
- Requieren backup pre + 24h Sentry watch + DOBLE OK
- **1 por week máximo** (mesa-15 rule strict)

### Categoría B — Cell Core atomic refactor deploys
- Move module código entre plugins (akibara/ legacy → akibara-core/)
- Plugin akibara/ legacy queda activo en paralelo (NO delete inmediato)
- Smoke prod 20/20 + Sentry baseline check
- **Pueden ser back-to-back si smoke + Sentry GREEN** (NO 24h gap obligatorio entre fases)
- Razón: no son destructivos irreversibles; rollback = git revert + redeploy <30min

---

## 4 CLEAN destructivos identificados

| ID | Título | Tipo | Backup | Rollback time | Risk |
|---|---|---|---|---|---|
| **CLEAN-014** | Delete `Akibara_Reserva_Migration::run()` YITH legacy class | Code delete | Snapshot tar.gz `.private/snapshots/` | <15min (revert commit) | P2 |
| **CLEAN-011** | Delete leftover plugin folders en `wp-content/uploads/` | Folder delete | Snapshot tar.gz | <15min (restore tar) | P2 |
| **CLEAN-015** | GA4 module DISABLE + archive `modules/ga4/` → `modules/_archived/ga4/` + remove `AKB_GA4_API_SECRET` | Module disable + constant unset | Snapshot tar.gz + DB backup wp_options | <30min | P1 |
| **CLEAN-016** | finance-dashboard module DISABLE (admin UI) | Module disable | Snapshot tar.gz | <15min | P1 |

---

## Decisión per item

### CLEAN-014 — Sprint 2 Week 1 ✅ KEEP

- **Justificación:** Class `run()` es one-shot migration de 2025-Q4 (YITH ↔ akibara-reservas data migration). Ya ejecutó. Code dead.
- **Risk extracción:** P2 (no consumers externos detectados — verificar grep adversarial pre-delete)
- **Sequencing:** Día 5 Week 1 (post staging + GHA setup en días 2-4)

### CLEAN-011 — Sprint 2 Week 2 ✅ KEEP

- **Justificación:** Folders huérfanos en `wp-content/uploads/` desde plugins desinstalados (yith, etc.). NO accesibles via HTTP (uploads no ejecuta PHP), pero ocupan ~50MB.
- **Risk extracción:** P2 (no funcionalidad activa)
- **Sequencing:** Día 12 Week 2 (post Cell Core Phase 1 + Phase 2 + smoke baseline)

### CLEAN-015 — Sprint 2 Week 3 ✅ KEEP

- **Justificación:** GA4 module está duplicado con plugin oficial WC GA4 (consolidación per memoria architecture). 3 customers no genera datos GA4 estadísticamente significantes. Re-evaluar M3 (50 customers/mo).
- **Risk extracción:** P1 (mide customer flow; verify no consent gating dependency post-disable)
- **Sequencing:** Día 17 Week 3 (post Cell Core Phase 3 — health-check/rut/phone)

### CLEAN-016 — DEFER a Sprint 3 Cell B 🔄 MOVED

- **Justificación rebuild atomic:** finance-dashboard se DISABLE en Sprint 2 PERO el rebuild manga-specific es Sprint 3 Cell B. Si lo deshabilitamos en Sprint 2, hay ~1 semana sin admin UI finance.
- **Memoria `feedback_minimize_behavior_change`:** atomic disable + rebuild minimiza ventana de no-funcionalidad. Cell B (Sprint 3) hace ambos en mismo PR.
- **Re-categorización:** CLEAN-016 NO se ejecuta en Sprint 2. Se ejecuta como parte de Sprint 3 Cell B akibara-marketing extraction.
- **Esto reduce Sprint 2 a 3 CLEAN destructivos** (1 per week, encaja en regla mesa-15 strict).

---

## Sprint 2 calendario destructivos (3 weeks)

```
WEEK 1 (Day 1-7) — Pre-conditions + Staging + GHA + CLEAN-014
─────────────────────────────────────────────────────────────
Day 1   Conditions #1 #2 #4 #5 (este doc)
Day 2   B-S2-INFRA-01 staging.akibara.cl ⚠️ DOBLE OK
        + Smoke staging post-deploy
Day 3   24h Sentry gap (passive watch staging deploy)
Day 4   B-S2-SETUP-01 GHA workflow + Playwright @critical
Day 5   CLEAN-014 YITH legacy delete ⚠️ DOBLE OK
        + Smoke prod + Sentry baseline check
Day 6-7 24h Sentry gap CLEAN-014 (passive watch)
        + Cell Core Phase 1 prep (kickoff prompts mesa-NN)


WEEK 2 (Day 8-14) — Cell Core Fase 1+2 + CLEAN-011
─────────────────────────────────────────────────────────────
Day 8   Cell Core Phase 1 — atomic batch deploy
        (6 módulos cero-refs: search, category-urls, order,
         email-safety, customer-edit-address, address-autocomplete)
Day 9   Smoke prod 20/20 + Sentry watch Phase 1
Day 10  Cell Core Phase 2 — atomic batch deploy
        (3 módulos con guards OK: email-template,
         product-badges, checkout-validation)
Day 11  Smoke prod 20/20 + Sentry watch Phase 2
Day 12  CLEAN-011 uploads/ leftover folders delete ⚠️ DOBLE OK
        + Smoke + Sentry baseline check
Day 13-14 24h Sentry gap CLEAN-011 (passive)
          + Phase 3 prep (defensive upgrades en theme)


WEEK 3 (Day 15-21) — Cell Core Fase 3+4 + CLEAN-015 + POST-validation
─────────────────────────────────────────────────────────────
Day 15  Cell Core Phase 3 — health-check (atomic per módulo)
        + theme update slug check
Day 16  Cell Core Phase 3 cont — rut + phone field priority fix
        + Smoke prod 20/20 + Sentry watch
Day 17  CLEAN-015 GA4 disable + archive ⚠️ DOBLE OK
        + Smoke + Sentry baseline check
Day 18  24h Sentry gap CLEAN-015 (passive)
        + Phase 4 prep (theme helper akibara_has_series_data)
Day 19  Cell Core Phase 4 — series-autofill EXTRACTION
        (atomic, dedicated PR, smoke EXHAUSTIVO con productos serie reales)
Day 20  Smoke prod 20/20 con focus en series rendering
        + Sentry watch Phase 4
Day 21  Sprint 2 POST-validation
        (mesa-15 + mesa-22 + mesa-02 ARCHITECTURE-POST-VALIDATION.md)
```

---

## Reglas duras durante destructivos

Per RUNBOOK-DESTRUCTIVO.md §0 + memoria `feedback_robust_default`:

1. **DOBLE OK explícito** Alejandro antes de ejecutar (no asume "ok ok" para destructivos)
2. **Snapshot pre-destructive obligatorio:**
   - Code: `tar -czf .private/snapshots/$(date +%F)-pre-CLEAN-XXX.tar.gz <paths>`
   - DB: `bin/mysql-prod akibara_db <table> > .private/backups/$(date +%F)-pre-CLEAN-XXX.sql` (si toca tabla)
3. **Smoke prod inmediato post-deploy:** `bash scripts/smoke-prod.sh` debe ser 20/20 PASS
4. **Sentry baseline antes:** `mcp__sentry__search_issues organizationSlug=akibara` count error types
5. **Sentry watch 24h post:** alerta si `>0 nuevos error types`
6. **Si Sentry detecta nuevo error type 24h post-destructive:** STOP siguiente destructive, investigar antes
7. **Rollback time documentado per item** (table arriba); ensayar rollback steps en PR description

---

## Rollback specifics per item

### CLEAN-014 rollback
```bash
git revert <commit-hash>
bash scripts/deploy.sh
bash scripts/smoke-prod.sh
```
Time: <15min

### CLEAN-011 rollback
```bash
tar -xzf .private/snapshots/$(date +%F)-pre-CLEAN-011-uploads.tar.gz \
  -C wp-content/uploads/
ssh akibara "cd /home/.../public_html && tar -xzf <restored>.tar.gz"
bash scripts/smoke-prod.sh
```
Time: <15min

### CLEAN-015 rollback
```bash
git revert <commit-hash>  # restore modules/ga4/ from archive
bin/wp-ssh option update akb_ga4_enabled '1'  # re-enable
# AKB_GA4_API_SECRET re-add to wp-config-private.php (manual)
bash scripts/deploy.sh
bash scripts/smoke-prod.sh
```
Time: <30min (manual constant re-add)

---

## Risk register actualizado post-decisión

| # | Riesgo | Severidad | Probabilidad | Mitigación aplicada |
|---|---|---|---|---|
| Original #4 | 4 destructivos concentrados | P1 | Alta sin mitigación | ✅ Reducido a 3 (CLEAN-016 → Sprint 3 Cell B) + 1 per week |
| Nuevo | Cell Core deploys back-to-back saturan capacity Sentry watch | P2 | Media | Categoría B no requiere 24h gap obligatorio si smoke + Sentry GREEN |
| Nuevo | CLEAN-015 GA4 disable rompe consent gating dependency | P2 | Baja | Pre-disable: grep `is_ga4_enabled` en theme + fix references |

---

**FIN. Próximo: condition #4 (bin/sync-staging.sh script).**
