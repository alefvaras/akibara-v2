# SENTRY Early Checkpoint Sprint 3.5

**Fecha:** 2026-04-27
**Tiempo:** T+ ~5h post-Sprint-3 deploy + INCIDENT-01 recovery
**Author:** main session (mesa-21-observability fallback — subagent no heredó MCP tools)
**MCP usado:** Sentry direct (org `akibara`/`php`)

---

## Verdict: 🔴 RED — 2 fatal collisions activas en prod

NO arrancar Sprint 4 hasta resolver. Hotfixes #10 + #11 están **merged en main pero NO deployados a prod**.

---

## Issues nuevos post-Sprint-3 deploy (excluyendo bluex deprecation noise + INCIDENT-01 pre-fix)

| Issue | Tipo | Events | First seen | Culprit | Status |
|---|---|---|---|---|---|
| **PHP-BC** | FatalError: Cannot redeclare class `Akibara_Email_Confirmada` | 35 | ~54 min ago | `plugins/akibara-reservas/emails/class-email-confirmada.php` (legacy) vs `plugins/akibara-preventas/emails/...` (new) | unresolved |
| **PHP-BD** | FatalError: Cannot redeclare function `akibara_brevo_editorial_list_id()` | 43 | ~49 min ago | `plugins/akibara-marketing/modules/brevo/module.php` vs `plugins/akibara/modules/brevo/module.php:110` (legacy) | unresolved |
| Total | | **78 events fatal** | últimos 50 min | | 0 users impactados |

**0 users impactados** = collisions ocurren en admin pages, cron, o REST endpoints (no customer-facing checkout/home).

---

## Verificación local (repo state)

```bash
# Hotfix #10 verificado en repo:
wp-content/plugins/akibara-preventas/emails/class-email-confirmada.php:8
  → class AKB_Preventas_Email_Confirmada (renamed) ✅

# Hotfix #11 incompleto:
server-snapshot/.../plugins/akibara/modules/brevo/module.php:110
  → function akibara_brevo_editorial_list_id (legacy STILL exists) ⚠️
wp-content/plugins/akibara-marketing/modules/brevo/module.php:81
  → function akibara_brevo_editorial_list_id (also exists)
```

**Hipótesis:** Los hotfixes están en `main` pero `bin/deploy.sh` no se ejecutó después de mergearlos. Prod sigue con código pre-hotfix.

---

## Smoke prod actual (T+5h post-deploy refactor)

```
HTTP 200 home          ✅
HTTP 302 wp-admin       ✅ (login redirect normal)
HTTP 404 mis-reservas   ⚠️ (issue conocido B-S4-PREVENTAS-01)
```

Customer-facing flow funciona. Admin/cron tienen fatales latentes generando 78 events/h.

---

## Bluex noise (excluido del análisis — continúa)

26 issues nuevos últimos 4h, todos `bluex-for-woocommerce` deprecation warnings PHP 8.2+ (`Creation of dynamic property...`). Es upstream noise conocido — no Akibara, no impactante.

---

## Recomendación

```
1. Deploy hotfixes #10 + #11 + B-5 sync via bin/deploy.sh (DOBLE OK requerido)
2. Smoke prod 9/9 post-deploy
3. Sentry T+30min monitoring — verificar PHP-BC y PHP-BD se resolvieron
4. Si PHP-BD persiste (hotfix #11 no cubre brevo function specifically):
   - Aplicar function_exists() guard en akibara-marketing/modules/brevo/module.php:81
   - O add legacy plugin akibara skip flag para brevo module también
   - Re-deploy
5. Sprint 4 arranca SOLO con verde ≥30min sin nuevos fatales akibara-*
```

---

## Releases verificadas (Sentry MCP find_releases)

Última release tag: `akibara-ccc5faf` (2026-04-26 16:40 UTC). NO hay release tags posteriores — confirmando que prod NO recibió las hotfixes #10/#11/#9 refactor formalmente como nueva release. Esto sugiere que el pipeline de deploy auto-genera Sentry release tags solo cuando deploy.sh finaliza completo, y desde 16:40 UTC del 2026-04-26 no se ha completado ningún deploy nuevo.

**Discrepancia con INCIDENT-01.md timeline:** el incident timeline dice "T+3:00 → T+3:30 Refactor + redeploy + plugins activan correctamente, smoke 9/9 PASS, site UP". Esto pudo ser un activate/deactivate manual via wp-cli sobre archivos ya rsync'ed, NO un full deploy.sh. Por eso tag de Sentry release no se actualizó y los hotfixes posteriores no llegaron a prod.

---

## Action items inmediatos

- [ ] DOBLE OK Alejandro deploy hotfixes
- [ ] `bin/deploy.sh` execute (con sus propios gates internos)
- [ ] Smoke prod 9/9 PASS post-deploy
- [ ] Sentry MCP verify PHP-BC + PHP-BD resolved (T+30min sin nuevos events)
- [ ] Update SPRINT-4-READINESS.md status
