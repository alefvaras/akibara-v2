# Contexto compartido — Mesa Técnica Akibara Foundation Audit

**Fecha:** 2026-04-26
**Modo:** Foundation right-sized (8 agentes, 1 ronda)

## Qué es Akibara

Tienda online de manga japonés en Chile (`https://akibara.cl`). WordPress + WooCommerce con plugin custom `akibara`, tema custom `akibara`, plugins extra `akibara-reservas` (preventa) y `akibara-whatsapp`. 11 mu-plugins custom `akibara-*`. Hosting Hostinger. Single dev.

## Escala real verificada en prod (2026-04-26)

| Métrica | Valor |
|---|---|
| Productos publicados | 1.371 (catálogo manga completo) |
| Customers reales | 8 (incluye 1 cuenta del dueño) |
| Compras reales hechas | 3 (confirmado por usuario) |
| Admins | 5 (1 real + **4 backdoors de nulled plugins legacy** — flagear P0) |
| shop_manager | 1 (Akibara_shipit, integration) |
| Plugins activos | 16 legítimos (todos custom o stack standard, NO nulled actuales) |
| mu-plugins | 11 akibara-* + 2 hostinger-* |
| Plataforma email | Brevo Free (workflow Carrito abandonado activo, key broken para custom code) |

## Mandato del usuario (literal)

- "**quiero crecer y obtener buenos fundamentos desde ya**"
- "**quiero un código robusto**"
- "**no quiero código muerto ni sobreingeniería**" (repetido 2 veces)
- "tambien cosas de marketing para crecer y habilitarla en un futuro"
- "me interesa tambien mucho el tema de la preventa y el diseño"

## Definición operativa de "robusto" (tu mandato core)

✅ Secure: sin XSS/CSRF/SQLi/secrets leak
✅ Error-handled: API failures degradan gracefully, no white screens
✅ Edge cases: out-of-stock + concurrent purchase + email bounce + payment timeout cubiertos
✅ Maintainable: nombres claros, código auditable, no magic
✅ No fragile: no asume happy path, no asume servicios externos siempre responden
❌ NO "robust" = abstracciones por si acaso, factory patterns para 1 caso, multi-* sin pedido

## Reglas duras (NO NEGOCIABLES)

1. **Tuteo chileno neutro.** PROHIBIDO voseo (confirmá/hacé/tenés/podés/vos/sos).
2. **Email testing solo a `alejandro.fvaras@gmail.com`.** Nunca a cliente real.
3. **NO modificar precios** (`_sale_price`, `_regular_price`, `_price`). Solo cupones WC.
4. **NO instalar plugins third-party.** Custom only.
5. **DOBLE OK explícito** para destructivos en server.
6. **Read-only en prod por defecto.**
7. **Branding pulido** — cambios visuales requieren MOCKUP previo.
8. **Mobile-first** prioridad responsive.
9. **NO PR review** (commit-direct-to-main) — implica rollback rápido obligatorio por item.
10. **Brevo plataforma email** (NO migrar, no proponer MailPoet/Klaviyo/etc.).

## Qué NO propongas

- Refactors a "abstract X for future flexibility" sin caso concreto
- Frameworks de testing/CI/observability complejos (over-engineering para 3 clientes)
- Multi-tenant, multi-account, multi-language, multi-currency
- Performance optimizations teóricas (sin tráfico real para validar)
- Cambios visuales sin mockup (mesa-13 SOLO observa)
- Migrar de Brevo a otra plataforma email
- Features para "100K users" — diseña para crecer, no para escalar prematuramente

## Qué SÍ propon

- Fixes que reduzcan superficie de ataque
- Defensive coding en API integrations (Brevo, BlueX, MercadoPago, Flow, Cloudflare)
- Error handling donde hoy se asume happy path
- Cleanup adicional al ya identificado en `CLEAN-SEEDS.md`
- Setup tasks (sender domain, SPF/DKIM/DMARC, security headers, etc.) que dejan foundation sólida
- **Marketing automations / campaigns / growth features que código tiene PERO no están activas** (flagear como "Growth-ready, deferred to S3+")
- **Robustness en preventa flow** (akibara-reservas) — order state machine, payment timing, fulfillment commitments
- **Diseño/responsive** baseline para que UX customer-facing sea robusta desde ya

## Tu output (formato obligatorio)

Tu archivo: `audit/round1/<NN>-<rol>.md`

Frontmatter:

```yaml
---
agent: <tu name del subagent>
round: 1
date: 2026-04-26
scope: <una línea describiendo qué cubriste>
files_examined: <count>
findings_count: { P0: N, P1: N, P2: N, P3: N }
---
```

Secciones obligatorias:

1. **`## Resumen ejecutivo`** — máx 5 bullets.
2. **`## Findings`** — uno por F-ID con esta plantilla:
   ```
   ### F-NN-NNN: <título corto>
   - **Severidad:** P0 | P1 | P2 | P3
   - **Categoría:** [SECURITY|DEAD-CODE|FRAGILE|OVER-ENGINEERED|GROWTH-READY|SETUP|UX|REFACTOR|...]
   - **Archivo(s):** path:line
   - **Descripción:** qué está mal
   - **Evidencia:** snippet o referencia concreta
   - **Propuesta:** qué hacer (NO implementes)
   - **Esfuerzo:** S | M | L
   - **Sprint sugerido:** S1 | S2 | S3 | S4+
   - **Robustez ganada:** explícito
   - **Requiere mockup:** SÍ | NO
   - **Riesgo de regresión si se actúa:** alto | medio | bajo
   ```
3. **`## Cross-cutting flags`** — findings críticos fuera de tu área que detectaste, para otros agentes/lead.
4. **`## Áreas que NO cubrí (out of scope)`** — explícito.

## Stack disponible para tus comandos

- `bin/wp-ssh <args>` — wp-cli contra prod via SSH (read-only)
- `bin/mysql-prod -e "SELECT ..."` — query DB prod via tunnel localhost:3308
- Read-only filesystem audit en `server-snapshot/public_html/`

NO modifiques prod. NO ejecutes destructivos. Solo identificá.

## Honestidad total

Si no pudiste auditar algo, declarálo en `## Áreas que NO cubrí`. NO inventes findings.
Tienda con 3 clientes — no inventes problemas que asumirían 100K users.
Tu valor está en findings concretos, no en cantidad.
