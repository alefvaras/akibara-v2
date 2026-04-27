# Sprint 3 — PARALELO Cell A + Cell B + Cell H high

**Pre-requisito:** Sprint 2 DONE ✅ (2026-04-27 ~13:30 UTC-4) + Sentry 24h verde (scheduled check 2026-04-28 ~13:37).

**Esfuerzo:** 60h equivalente (3 cells paralelas) → 1 semana wall-clock.

**Modo recomendado:** 1 sesión main que lanza 3 subagents paralelos via Agent tool. NO worktrees.

## Arrancar Sprint 3

Pegar prompt de [SPRINT-EXECUTION-GUIDE-2026-04-26.md](../SPRINT-EXECUTION-GUIDE-2026-04-26.md#sprint-3--paralelo-cell-a--cell-b--cell-h-high) sección "Sprint 3 PARALELO".

## Inputs obligatorios para cells

- [`audit/sprint-2/cell-core/HANDOFF.md`](../sprint-2/cell-core/HANDOFF.md) — API pública akibara-core que cells consumen
- [`audit/sprint-2/cell-core/REDESIGN.md`](../sprint-2/cell-core/REDESIGN.md) — postmortem PHP hoisting (cells deben usar group wrap pattern)
- [`audit/CELL-DESIGN-2026-04-26.md`](../CELL-DESIGN-2026-04-26.md) — secciones Cell A, Cell B, Cell H
- [`audit/HANDOFF-2026-04-26.md`](../HANDOFF-2026-04-26.md)

## Estructura Sprint 3

```
sprint-3/
├── README.md (este file)
├── cell-a/         # akibara-preventas (will be populated by cell agent)
├── cell-b/         # akibara-marketing + finance dashboard rebuild
├── cell-h/         # Design Ops horizontal (mockups + tokens)
└── rfc/            # Core/Theme RFCs si cells necesitan cambios
```

## Lock policy

- `plugins/akibara-core/` y `themes/akibara/` son **READ-ONLY** desde cells verticales A/B
- Cells abren RFC en `audit/sprint-3/rfc/{CORE,THEME}-CHANGE-NN.md` si necesitan cambios
- mesa-15 + mesa-01 arbitran RFCs en Sprint 3.5

## DoD Sprint 3

- [ ] Plugin akibara-preventas funcionando + tests E2E
- [ ] Plugin akibara-marketing funcionando + 13 modules consolidated
- [ ] Finance dashboard rebuild manga-specific (5 widgets prioritarios)
- [ ] Cell H design tokens + component library v1
- [ ] 3 PRs merged a main vía cells
- [ ] Smoke staging GREEN + Sentry 24h verde post-deploy

## Dependencies inter-cell

| Cell | Depende de |
|---|---|
| **A** (preventas) | Cell H mockups (encargos checkbox, preventa card 4 estados, auto-OOS preventa) |
| **B** (marketing) | Cell H mockups (cookie banner, popup styling, finance dashboard 5 widgets) |
| **H** (design ops) | Recibe requirements de A+B, devuelve specs |

Si Cell H mockup no listo → cells A/B usan stubs temporales + Cell H consolida en Sprint 3.5.
