# Sprint 4.5 — NO-RFC

**Fecha:** 2026-04-26
**Sprint:** 4 (paralelo Cell C + Cell D)

## Estado RFCs

`audit/sprint-4/rfc/` directorio vacío al cierre del sprint. **No hubo RFCs para arbitrar.**

## Justificación

- Cell C (akibara-inventario) y Cell D (akibara-whatsapp) operaron en branches independientes con scope acotado.
- Ningún cell escaló decisión transversal a través del proceso RFC.
- Las decisiones tomadas internamente quedaron documentadas en los HANDOFF respectivos:
  * `audit/sprint-4/cell-c/HANDOFF.md` (D-01 a D-05): rename tabla, BlueX verbatim, gitleaks allowlist, BIS branding stub, commit duplicado.
  * `audit/sprint-4/cell-d/HANDOFF.md`: clase rename Controller, single-file src/, AddonContract pattern.
- Cell H mockups (Design Ops horizontal) no escaló RFC — el subagent Haiku falló por context y los mocks quedaron diferidos a Sprint 5 (no bloqueador para Cell C ni Cell D).

## Decisiones in-cell relevantes para mesa (referencia rápida)

| ID | Cell | Decisión | Riesgo |
|---|---|---|---|
| D-01 | C | Rename `wp_akb_bis_subs` → `wp_akb_back_in_stock_subs` con migración `INSERT IGNORE` | bajo (legacy intacta) |
| D-02 | C | Clases BlueX/12Horas migradas verbatim (preservar F-PRE-001) | bajo |
| D-03 | C | gitleaks allowlist `class-12horas.php:23` (option name string, no secret) | bajo |
| D-04 | C | BIS form usa branding stub hasta Cell H mock-10 | bajo (cosmético) |
| D-05 | C | Commit duplicado `35f86f0` en `feat/akibara-whatsapp` por mistracking | nulo (cosmético) |
| —    | D | Single-file src/Plugin.php (Plugin + Controller juntos) — YAGNI | nulo |
| —    | D | Clase renombrada `Akibara_WhatsApp` → `Akibara_WhatsApp_Controller` (evitar colisión PSR-4) | nulo (clase no era public API) |

## Próximos sprints

Si surgen RFCs para Sprint 5 mercadolibre, archivar en `audit/sprint-5/rfc/`.

---

**Cerrado por:** Sprint 4.5 Lock Release
**Siguiente paso:** Retrospective + QA smoke
