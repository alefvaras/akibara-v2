# Mesa técnica Akibara — Propuestas R2

**Fecha:** 2026-04-26
**Líder:** mesa-01 lead arquitecto
**Inputs sintetizados:** 12 outputs R1 (mesa-02, 04, 07, 08, 09, 10, 12, 13, 15, 19, 22, 23) + 4 contexts (CONTEXT, CLEAN-SEEDS, HOMEPAGE-SNAPSHOT, CUSTOMER-PAGES-SNAPSHOT) + 5 memorias proyecto/feedback.

## Notas del líder

1. **Findings R1: ~190 totales, deduplicados a 30 decisiones arquitectónicas.** El resto son items granulares que entran al BACKLOG sin requerir decisión cross-cutting (ver BACKLOG-2026-04-26.md).
2. **Las 12 mesas convergen sólidamente.** mesa-02 + mesa-09 + mesa-10 + mesa-15 ratifican unánimemente que CLEAN-001 + CLEAN-003 + CLEAN-004 deben CANCELARSE. No queda debate.
3. **Hay ~5.500 LOC de growth modules con 0-1 usuarios reales** (referrals, back-in-stock, series-notify, welcome-discount, cart-abandoned). NO eliminar — diseñar growth-deferred trigger en lugar de borrar.
4. **El frontend customer-facing está MUCHO más maduro** que la narrativa inicial. Foco real R2 = security cleanup + setup foundations + bug fixes específicos + decisiones de activación growth-deferred.
5. **mesa-23 PM aporta capacity baseline crítica:** 25-30h efectivas/semana solo dev. Esto fija el techo de Sprint 1.

---

## Decisión #1: CANCELAR CLEAN-001, CLEAN-003, CLEAN-004 (3 cleanup seeds revertidos)

- **Contexto:** Las 3 seeds CLEAN del usuario asumían que `akibara-sentry-customizations.php`, `akibara-brevo-smtp.php` y `AKB_BREVO_API_KEY` eran legacy. mesa-02 (F-02-002), mesa-09 (F-09-001), mesa-10 (F-10-002 + F-10-003) las desmienten unánime: son infraestructura load-bearing. Borrarlas rompe Sentry + email transaccional + 7 callsites.
- **Findings origen:** F-02-002 P0, F-09-001 P0, F-10-002 P0, F-10-003 P0, F-15-011 (validación pattern mu-plugins).
- **Propuesta:** Mover los 3 ítems formalmente al CLEANUP-PLAN como CANCELADOS con razón técnica documentada y cross-ref. Reclasificar como infraestructura. Documentar como ADR en `docs/adr/` (a futuro) que estos mu-plugins son load-bearing y NO eliminar sin migración previa explícita.
- **Pro:** evita romper email + Sentry; consolida understanding del rol de mu-plugins; documenta para nuevos mantenedores.
- **Contra:** ningún contra técnico. El usuario puede sentir que "perdió" el cleanup esperado.
- **Riesgo:** mínimo — solo es decisión documental.
- **Esfuerzo:** S (15 min documentación)
- **Sprint sugerido:** S1
- **Robustez ganada:** Evita regresión P0 en email transaccional + Sentry tracking. Documenta protocolo "validar antes de eliminar".
- **Owner skill sugerido:** [ARCHITECTURE]
- **Mockup requerido:** NO

---

## Decisión #2: WP core verify-checksums es prerequisito BLOQUEANTE de Sprint 1

- **Contexto:** mesa-10 F-10-005 marca como P0 verificación de integridad de WP core (10 archivos modificados <90 días). Si hay backdoor/injection, cualquier cleanup posterior puede propagar payload.
- **Findings origen:** F-10-005 P0, F-PRE-008.
- **Propuesta:** Antes de cualquier acción destructiva (CLEAN, SEC, F-PRE-001), ejecutar `bin/wp-ssh core verify-checksums`. Si reporta mismatches, escalar a IR forensic completo y posponer Sprint 1.
- **Pro:** Garantiza que el sprint no propaga compromise; baseline confiable.
- **Contra:** Si hay mismatches, retrasa Sprint 1.
- **Riesgo:** Si NO se hace, riesgo de ejecutar cleanups sobre filesystem comprometido.
- **Esfuerzo:** S (30 min check + 2h forensic si mismatches)
- **Sprint sugerido:** S1 (primer ítem antes que SEC-P0-001)
- **Robustez ganada:** Garantiza filesystem confiable como base de todo el sprint.
- **Owner skill sugerido:** [SECURITY]
- **Mockup requerido:** NO

---

## Decisión #3: SEC-P0-001 expansion — workflow forense user 6 + Application Passwords

- **Contexto:** SEC-P0-001 original es delete 4 admin backdoors. mesa-10 (F-10-004) expande: user 6 tiene ~47 posts que pueden ser injection o productos legítimos importados. mesa-10 (F-10-021) agrega: WP NO revoca Application Passwords automáticamente al `user delete` → orphan tokens permanecen como persistence. mesa-02 (F-02-003) agrega: row `wp_akb_referrals.id=4` (user 18 nightmarekinggrimm26) puede ser huérfano si user 18 se confirma malicioso.
- **Findings origen:** F-10-004 P0, F-10-021 P0, F-10-024 P2, F-02-003 P1, F-PRE-010.
- **Propuesta:** Expandir SEC-P0-001 a workflow obligatorio:
  1. Audit user 6 posts (CSV dump + grep `eval`/`base64_decode`/`<script`/`window.location`)
  2. Audit user 18 (`bin/wp-ssh user get 18`) — si customer real con orders, keep; si admin/sospechoso, agregar al delete
  3. Audit Application Passwords de users 5/6/7/8 (`user meta get _application_passwords`)
  4. Audit cron events no estándar
  5. DOBLE OK explícito + backup DB pre-delete
  6. Delete with `--reassign=1` para user 6 (si posts legítimos) y plain delete para 5/7/8
  7. Cleanup `wp_akb_referrals.id=4` si user 18 confirmado malicioso
  8. Smoke test: `bin/wp-ssh user list --role=administrator --format=count` → expect 1
- **Pro:** evita propagación de payload, evita orphan persistence tokens, asegura cleanup data consistency.
- **Contra:** sube esfuerzo de S (15 min) a M (2-4h).
- **Riesgo:** sin workflow, delete ciego puede borrar productos legítimos o dejar persistence.
- **Esfuerzo:** M (2-4h)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina vector de persistence; garantiza data consistency post-cleanup.
- **Owner skill sugerido:** [SECURITY]
- **Mockup requerido:** NO
- **Bloqueada por:** Decisión #2 (verify-checksums clean)

---

## Decisión #4: CLEAN-002 cart-abandoned local — DEFERIR a S2 con condición Brevo upstream firing

- **Contexto:** CLEAN-002 propone borrar módulo local cart-abandoned (~539 LOC + tabla + cron) porque Brevo upstream cubre. PERO Brevo upstream workflow tiene 0 traffic confirmado (F-PRE-002 + F-09-005). Si borramos local antes de validar upstream firing → clientes que abandonan carrito NO reciben email = revenue silent loss. mesa-23 F-23-001 explícito: CLEAN-002 esfuerzo real es L (1-2 días) por DNS propagation.
- **Findings origen:** F-09-005 P1, F-PRE-002 P1, F-23-001 P2.
- **Propuesta:** Mantener CLEAN-002 condicional con secuencia obligatoria:
  1. **S1 setup foundations:** validar sender domain `akibara.cl` en Brevo + agregar SPF/DKIM/DMARC en Cloudflare DNS + verificar plugin oficial Brevo tracker activo + smoke test cart abandonment con producto test 24261 + alejandro.fvaras@gmail.com.
  2. **S1 monitoring:** 24-48h confirmando workflow Brevo recibe eventos.
  3. **S2 cleanup:** SI Y SOLO SI tracker firing OK → CLEAN-002 (delete módulo + DROP tabla con backup mysqldump + cron unschedule).
  4. Si tracker NO firing post-setup → mantener cart-abandoned local + investigar conflict + escalar.
- **Pro:** evita revenue silencioso por cleanup prematuro; asegura continuidad del flow abandoned cart.
- **Contra:** depende de DNS propagation no controlable por dev.
- **Riesgo:** si nunca firma upstream, el cleanup nunca ocurre y queda código duplicado.
- **Esfuerzo:** L (1-2 días total: 4h activas + 24-48h espera)
- **Sprint sugerido:** S1 (setup) + S2 (cleanup condicional)
- **Robustez ganada:** garantiza continuidad de email abandoned cart durante migración upstream.
- **Owner skill sugerido:** [EMAIL] + [SETUP/INFRA]
- **Mockup requerido:** NO

---

## Decisión #5: Cleanup vendor/ + coverage/ + dev tooling del plugin akibara

- **Contexto:** Plugin akibara deploya 74 MB de dev tools (vendor 55 MB + coverage 19 MB + composer.json + phpstan baselines + tests). Coverage HTML expone source code mapeado por línea (recon completo). composer.json declara TODO bajo require-dev → vendor/ existe SOLO para CI/dev tooling, NO runtime. Sin .htaccess que bloquee acceso público.
- **Findings origen:** F-02-001 P0, F-02-025 P3, F-09-015 P2, F-09-016 P2, F-10-006 P0, F-10-007 P0, F-15-008 P1, F-22-004 P0, CF-08-C.
- **Propuesta:** Acción doble: (1) excluir de deploy (`bin/deploy.sh` o `.distignore`: vendor/, coverage/, tests/, .phpunit.cache/, composer.json, composer.lock, phpunit.xml.dist, phpcs.xml, phpstan.neon, .phpcs-baseline, .pcp-baseline, phpstan-baseline.neon); (2) defense in depth: agregar `wp-content/plugins/akibara/.htaccess` con `Deny from all` para esos paths, por si deploy futuro falla; (3) verificar `akibara-reservas/` mismo patrón (tiene `bin/`, `tests/`).
- **Pro:** −74 MB attack surface; deploy más rápido; cleaner repo en prod; doble defensa (deploy + .htaccess).
- **Contra:** primera vez que se necesita deploy script formal — requiere setup.
- **Riesgo:** bajo (autoload runtime está en `includes/autoload.php`, no depende de `vendor/autoload.php`).
- **Esfuerzo:** M (2-3h: deploy script + .htaccess + verificar autoload + smoke test + replicar en akibara-reservas)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina recon completo; mantiene seguridad incluso si deploy falla.
- **Owner skill sugerido:** [SECURITY] + [SETUP/INFRA] + [CLEANUP]
- **Mockup requerido:** NO

---

## Decisión #6: Hostinger crontab setup como prerequisito de growth modules

- **Contexto:** WP_CRON está disabled en wp-config (línea 103). Crons del plugin (next-volume, series-notify, akibara-reservas check_dates, daily_digest, cart-abandoned, brevo, ≥6 crons) usan `wp_schedule_event` que dispara con tráfico web — sin tráfico = no firing. Usuario ya reportó "next-volume no funcionando". Sin Hostinger crontab no se pueden activar features email automation.
- **Findings origen:** F-PRE-004 P1, F-09-009 P1, F-15-017 P1.
- **Propuesta:** Configurar Hostinger crontab `*/5 * * * * wget -q -O - https://akibara.cl/wp-cron.php > /dev/null 2>&1`. Validar `bin/wp-ssh cron event list` muestra eventos scheduleados. Smoke test: `wp cron event run akb_next_volume_check` → verify no error + email a alejandro.fvaras@gmail.com.
- **Pro:** activa toda la infra cron existente; prerequisito de cualquier email automation.
- **Contra:** requiere acceso SSH Hostinger para crontab edit.
- **Riesgo:** bajo (es config, no código).
- **Esfuerzo:** S (30 min)
- **Sprint sugerido:** S1
- **Robustez ganada:** crons firing reales independiente de tráfico; foundation de growth modules.
- **Owner skill sugerido:** [SETUP/INFRA] + [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #7: Crear mu-plugin akibara-security-headers.php (HSTS + X-Frame + REST users + xmlrpc + Permissions-Policy)

- **Contexto:** mesa-22 (F-22-013, F-22-014) y mesa-10 (F-10-019, F-10-022) coinciden: NO hay security headers globales. xmlrpc.php accesible. REST `/wp-json/wp/v2/users` enumera usernames (combinado con backdoor admins → vector brute-force). HSTS ausente → downgrade attack posible. Es baseline 2026 obligatorio para tienda con auth + payments.
- **Findings origen:** F-22-013 P1, F-22-014 P1, F-10-019 P2, F-10-022 P1, F-10-014 P1.
- **Propuesta:** Crear `mu-plugins/akibara-security-headers.php` consolidado con:
  1. `send_headers` action: HSTS max-age=31536000 (después 24h validación), X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy camera/microphone/geolocation deny.
  2. `rest_endpoints` filter: unset `/wp/v2/users`.
  3. `.htaccess` block: `<Files xmlrpc.php> Require all denied </Files>`.
  4. `wp-config.php` hardening: agregar `define('WP_DEBUG_LOG', false)`, `define('WP_DEBUG_DISPLAY', false)`, `define('SCRIPT_DEBUG', false)` explícitos.
  5. NO CSP estricta inicial (rompe WC + Sentry + GA4 + Brevo). Empezar CSP-Report-Only en S3+ cuando hay tráfico.
- **Pro:** baseline 2026 sólido; defense in depth; consolidado en 1 mu-plugin auditable.
- **Contra:** HSTS irreversible en clientes que ya recibieron header (solo problema si HTTPS rompe — improbable).
- **Riesgo:** xmlrpc block puede romper integraciones (validar primero ningún plugin/integration usa XML-RPC). HSTS empezar con max-age=300 24h luego escalar.
- **Esfuerzo:** S (1-2h código + curl verify)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina enumeración usuarios, vector xmlrpc, downgrade HTTP, defaults debug peligrosos.
- **Owner skill sugerido:** [SECURITY]
- **Mockup requerido:** NO

---

## Decisión #8: Cookie consent banner — mu-plugin custom (NO third-party)

- **Contexto:** mesa-19 F-19-001 P0: Akibara carga GA4 + Microsoft Clarity + Cloudflare cookies + cookies referrals + welcome-discount ANTES de consent. Viola Ley 21.719 (vigencia plena Dec 2026) + Ley 19.628 art. 4. Sin defensa legal contra Sernac. Política Akibara: NO third-party plugins.
- **Findings origen:** F-19-001 P0, F-19-002 P1.
- **Propuesta:** Crear mu-plugin custom `akibara-cookie-consent.php`:
  1. Banner sticky bottom con 3 categorías (Necesarias / Analytics / Marketing) — Necesarias siempre on.
  2. Almacenar decisión en cookie `akb_consent_v1` (1 año) + localStorage como backup.
  3. API JS `window.akbConsent.has('analytics')` para gating.
  4. Refactor 4-5 callsites: `themes/akibara/inc/clarity.php:37-51`, `plugins/akibara/modules/ga4/module.php:160-185`, `referrals/module.php:175`, `welcome-discount/module.php:431-433` para chequear consent antes de cargar.
  5. Crear página `/cookies/` con inventario tabular (F-19-002 — Decisión #9).
  6. Link "Gestionar cookies" en footer.
- **Pro:** compliance Ley 21.719 + 19.628; defense legal vs Sernac; mantiene política no third-party.
- **Contra:** requiere mockup (UI customer-facing); refactor 4-5 callsites.
- **Riesgo:** medio — si banner mal implementado bloquea analytics o trackers que el dueño quiere activos.
- **Esfuerzo:** L (banner + refactor 4-5 callsites + página inventario)
- **Sprint sugerido:** S2 (P0 legal pero requiere mockup primero)
- **Robustez ganada:** compliance legal + defensiva contra reclamos Sernac + control granular consent customer.
- **Owner skill sugerido:** [COMPLIANCE] + [FRONTEND] + [BACKEND]
- **Mockup requerido:** SÍ (Banner UI + Página /cookies/)

---

## Decisión #9: Bootstrap legal pages expandido — `/cookies/` + Términos preventa Sernac

- **Contexto:** mesa-19 F-19-002 P1 (página `/cookies/` no existe), F-19-004 P0 (preventa sin términos específicos Sernac), F-19-007 P1 (política privacidad gaps Ley 21.719), F-19-013 P2 (devoluciones falta Sernac escalation), F-19-017 P1 (verificar `/terminos-y-condiciones/` existe).
- **Findings origen:** F-19-002 P1, F-19-004 P0, F-19-007 P1, F-19-013 P2, F-19-017 P1.
- **Propuesta:** Expandir `mu-plugins/akibara-bootstrap-legal-pages.php`:
  1. Agregar página `/cookies/` con inventario tabulado (10+ cookies documentadas en F-19-002).
  2. Sección preventa en `/devoluciones/`: refund 100% si editorial cancela / +90 días sin recibir / cliente solicita ANTES de despacho. Definir "Fecha por confirmar" claramente.
  3. Reescribir política privacidad alineada Ley 21.719: Agencia Protección Datos, "responsable" vs "encargado", plazos ARCO+P (30+30 días), `privacidad@akibara.cl` dedicado, tabla bases legales + encargados (Brevo, BlueX, Correos, MP, Flow, Cloudflare, Google).
  4. Verificar y/o crear `/terminos-y-condiciones/` (validación + bootstrap si falta).
  5. Agregar Sernac escalation en `/devoluciones/`: "puedes presentar reclamo en sernac.cl o 800 700 100".
  6. Bumpear flag bootstrap a 4 (de 2 actual) para re-bootstrap.
- **Pro:** compliance legal completo; defensa contra Sernac; transparencia preventa cubre Ley 19.496; Ley 21.719 ready (vigencia Dec 2026).
- **Contra:** requiere reescritura de contenido legal (idealmente review abogado Chile pre-Dec 2026).
- **Riesgo:** medio — contenido legal mal escrito puede ser peor que omitido. Recomendar review abogado externo.
- **Esfuerzo:** L (3-5h escritura + review)
- **Sprint sugerido:** S1 (cookies + términos preventa + sernac escalation = P0/P1) + S2 (privacidad re-write)
- **Robustez ganada:** compliance legal Chile + Ley 21.719 ready + defensa Sernac documentada.
- **Owner skill sugerido:** [COMPLIANCE]
- **Mockup requerido:** NO (es contenido), pero recomienda review abogado.
- **Bloqueada por:** Decisión #8 parcialmente (cookies banner + página comparten roadmap).

---

## Decisión #10: Encargos auto-suscribe a Brevo lista 2 sin opt-in — fix urgente compliance

- **Contexto:** mesa-19 F-19-003 P0: handler AJAX encargos automáticamente suscribe email a Brevo lista 2 (newsletter) sin checkbox opt-in. Violación clara Ley 19.628 art. 4. Combina con mesa-19 F-19-009 (newsletter footer sin checkbox explícito) y F-19-019 (sin aviso transaccionales nueva cuenta).
- **Findings origen:** F-19-003 P0, F-19-009 P1, F-19-018 P2.
- **Propuesta:**
  1. Crear lista Brevo dedicada "Encargos" (ej. ID 32) NO de marketing.
  2. Modificar handler `themes/akibara/inc/encargos.php` para escribir SOLO a lista 32.
  3. Agregar checkbox opcional `<label><input type="checkbox" name="newsletter_optin"> Quiero recibir novedades por email</label>`.
  4. Solo si checkbox marcado, agregar a lista 2 (newsletter) además.
  5. Auditar todos los demás callsites que escriban a lista 2 sin opt-in explícito.
  6. Newsletter footer (F-19-009): agregar checkbox required + double opt-in.
- **Pro:** compliance inmediato; resuelve violación P0; pattern reusable para otros forms.
- **Contra:** modifica UI form encargos (mockup mínimo).
- **Riesgo:** bajo (cambio transparente para clientes).
- **Esfuerzo:** S (30-60 min encargos) + M (newsletter footer double opt-in refactor)
- **Sprint sugerido:** S1 (encargos checkbox) + S2 (newsletter footer double opt-in)
- **Robustez ganada:** compliance + opt-in afirmativo; consistente cross-channel.
- **Owner skill sugerido:** [COMPLIANCE] + [BACKEND]
- **Mockup requerido:** SÍ (mínimo — checkbox styling)

---

## Decisión #11: Akibara_Reserva_Stock auto-OOS-to-preventa — opt-in admin explícito

- **Contexto:** mesa-02 F-02-019 P1: el flag `akb_reservas_auto_oos_enabled` (si está ON) convierte CUALQUIER producto OOS en preventa automática SIN fecha (`fecha_modo => 'sin_fecha'`). Riesgo: cliente reserva y paga manga discontinuado que jamás llega. mesa-15 F-15-021 expande: race condition con back-in-stock subscribers (notifica restock momentáneo y luego reauto-convierte preventa → 99 subs reciben "stock disponible" pero ven "Reservar ahora").
- **Findings origen:** F-02-019 P1, F-15-021 P1.
- **Propuesta:**
  1. Por default, NO auto-enable preventa al pasar OOS. Requerir admin flag explícito por producto (meta `_akb_allow_auto_preventa`).
  2. Si auto-enable se mantiene, exigir `fecha_modo='estimada'` con 4-6 weeks default (NO `sin_fecha`).
  3. Notify admin via email al activar auto-preventa para que confirme manualmente.
  4. Pre-convert: notificar suscriptores back-in-stock pendientes ANTES + delay 1h con `wp_schedule_single_event(+HOUR, 'akb_bis_will_convert_to_preventa', ...)`.
  5. Validar copy en cart/checkout: cuando preventa es `sin_fecha`, mostrar advertencia explícita "Fecha por confirmar — refund 100% si pasan +90 días" (linkea Decisión #9).
- **Pro:** evita commitments fulfillment imposibles; mantiene back-in-stock subscribers integrity; defensa Sernac (incluido en Decisión #9 términos).
- **Contra:** admin pierde "automatic" — debe activar por producto.
- **Riesgo:** bajo (config conservadora más segura).
- **Esfuerzo:** M (2-3h)
- **Sprint sugerido:** S2
- **Robustez ganada:** elimina commitment imposible + race condition cross-module + alinea con compliance Sernac.
- **Owner skill sugerido:** [BACKEND] + [ARCHITECTURE]
- **Mockup requerido:** SÍ (copy cart/checkout para preventa "fecha por confirmar")

---

## Decisión #12: Module Registry guard consistency — Opción B (DRY en Registry boot)

- **Contexto:** mesa-15 F-15-005 + mesa-02 F-02-016: solo 5 de 27 módulos defienden con `if (!akb_is_module_enabled('xxx')) return;` interno. El Registry filtra por `$mod['enabled']` antes de require_once, pero algunos módulos sin guard interno se cargan si entran por otro path. Inconsistencia.
- **Findings origen:** F-15-005 P2, F-02-016 P2.
- **Propuesta:** **Opción B (DRY):** Hacer que `Akibara_Module_Registry::boot()` SKIP el require si `enabled === false`. Eliminar guard manual interno de los 5 módulos que lo tienen (descuentos, popup, cart-abandoned, back-in-stock, welcome-discount). Single source of truth en Registry.
- **Pro:** 27 módulos consistente; menos código duplicado; feature flags realmente desactivan.
- **Contra:** requiere validar que no hay paths "manual include" que necesiten el guard interno.
- **Riesgo:** bajo (validar primero con grep si hay require directo).
- **Esfuerzo:** S (30 min Registry change + 15 min remove guards 5 módulos)
- **Sprint sugerido:** S2
- **Robustez ganada:** kill switch real funciona; menos LOC duplicado.
- **Owner skill sugerido:** [ARCHITECTURE] + [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #13: Preventa vs Encargo vs Agotado UX matrix — ADR + UI mutually exclusive

- **Contexto:** mesa-15 F-15-009 P1: 3 flujos paralelos sin guía clara. Cliente puede caer en "Reservar ahora" (preventa, paga 100%), "Avísame cuando vuelva" (back-in-stock, sin pago), "Solicitar encargo" (form, sin pago). UX confusion crítica. mesa-22 F-22-008 confirma: producto agotado puede mostrar "Preventa" badge simultáneamente.
- **Findings origen:** F-15-009 P1, F-22-008 P1, F-PRE-012.
- **Propuesta:** Documentar ADR `docs/adr/preventa-vs-encargo-vs-agotado.md` con matriz decisión:
  | Estado producto | Estado catálogo | UX | Backend |
  |---|---|---|---|
  | En catálogo + `_akb_reserva=yes` + stock>0 | Disponible | "Disponible para reservar" | preventa con stock |
  | En catálogo + `_akb_reserva=yes` + stock=0 | Preventa | "Reservar ahora" | preventa hot |
  | En catálogo + sin `_akb_reserva` + stock=0 | Agotado | "Avísame cuando vuelva" | back-in-stock |
  | NO en catálogo | Encargo | "Solicitar encargo" form | encargos form |

  Implementación: Card UI lee `Akibara_Reserva_Product::get_estado_proveedor()` para determinar single estado mostrado. Mutually exclusive UI. E2E test cubre 4 estados.
- **Pro:** UX consistente; menos confusion cliente; lower support tickets; aliado con compliance preventa (Decisión #9).
- **Contra:** modifica template renders existentes.
- **Riesgo:** medio (regression test obligatorio: 4 estados E2E).
- **Esfuerzo:** M (3-4h: ADR doc + card UI + smoke test 4 estados)
- **Sprint sugerido:** S2
- **Robustez ganada:** elimina UX ambigua; states machine clara.
- **Owner skill sugerido:** [ARCHITECTURE] + [FRONTEND]
- **Mockup requerido:** SÍ (4 estados card UI consistentes)
- **Bloqueada por:** Decisión #11 (auto-OOS-to-preventa opt-in)

---

## Decisión #14: 3 sistemas descuentos consolidación — DEFERIR a S3 con marketing-campaigns como umbrella

- **Contexto:** mesa-15 F-15-003 P1 + mesa-02 F-02-015 P2 + mesa-09 F-09-007 P1: 3 sistemas (descuentos engine + welcome-discount + marketing-campaigns) suman ~3.700 LOC con overlap real. Para 3 clientes, refactor masivo es over-engineering. Pero crecer requiere single source of truth.
- **Findings origen:** F-15-003 P1, F-02-015 P2, F-09-007 P1, F-PRE-005.
- **Propuesta:** Diseño documentado en S3 (NO ejecutar hasta tener tráfico para validar valor):
  1. **Mantener descuentos** como motor pricing por taxonomía → renombrar `pricing-rules`.
  2. **Migrar welcome-discount** a "campaña tipo welcome trigger" dentro `marketing-campaigns`. Anti-abuso (RUT, blacklist, captcha) vuelve policy module reutilizable.
  3. **Consolidar admin** a "Campañas y Descuentos" con sub-tabs.
  4. **Welcome-discount mantiene OFF** mientras tanto (popup activo cubre por ahora).
- **Pro:** single source of truth para growth; menos admin pages duplicadas; pattern consistente.
- **Contra:** L esfuerzo (3-4 sprints); requiere refactor cuidadoso.
- **Riesgo:** alto si se ejecuta prematuro (3 clientes no validan modelo). Mejor esperar growth real.
- **Esfuerzo:** L (3-4 sprints completos cuando se ejecute)
- **Sprint sugerido:** S3+ (deferred until >25 customers/mo)
- **Robustez ganada:** solo cuando hay datos para validar; hoy mantener separados es ANTI-over-engineering.
- **Owner skill sugerido:** [ARCHITECTURE] + [BACKEND]
- **Mockup requerido:** SÍ (admin UI consolidada cuando se ejecute)

---

## Decisión #15: Brevo Free → Standard upgrade — trigger >50 clientes/mes (NO sprint actual)

- **Contexto:** mesa-09 F-09-002 P0 (cuando crezca): Brevo Free 300/día compartido entre marketing + transactional. Con 7 sistemas dispatchando (welcome-series + cart-abandoned + back-in-stock + next-volume + series-notify + review-request + marketing-campaigns), worst case un día con 1 campaña 300 contactos + restock spike → cap consumido a las 10am → órdenes posteriores no reciben confirmation. Hoy 3 clientes/mes, no problema. mesa-23 confirma trigger 50 customers/mes.
- **Findings origen:** F-09-002 P0 (deferred), F-PRE-007 P1.
- **Propuesta:**
  1. **Hoy (S2):** Defensiva en código: `akb_brevo_daily_cap_check()` consultando `https://api.brevo.com/v3/account` → si quedan <30 créditos: bloquear marketing-campaigns dispatch, permitir solo TX P0 (order confirmation, password reset), notificar admin via Sentry.
  2. **Trigger >50 clientes/mes durante 2 meses:** Upgrade a Brevo Standard $18/mo (20K/mes dedicado). Decisión documentada en growth-deferred roadmap.
  3. **Priorizar emails:** tag `priority=high` para órdenes/passwords; batch marketing en hora valle (3am).
- **Pro:** circuit breaker evita order confirmations bloqueadas; upgrade trigger basado en datos reales.
- **Contra:** ninguno técnico.
- **Riesgo:** bajo.
- **Esfuerzo:** M (3-4h cap check + priorization)
- **Sprint sugerido:** S2 (cap check defensivo) + S4+ (upgrade cuando trigger)
- **Robustez ganada:** garantiza order confirmations no se bloquean por marketing burst; upgrade data-driven.
- **Owner skill sugerido:** [EMAIL] + [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #16: Theme back-in-stock duplicado — eliminar y migrar a módulo plugin

- **Contexto:** mesa-09 F-09-003 P0: theme `themes/akibara/inc/woocommerce.php:834-904` tiene sistema completo back-in-stock que duplica módulo `plugins/akibara/modules/back-in-stock/`. Bug: ambos hookeados a `woocommerce_product_set_stock_status` → cliente recibe 2 emails. Theme version usa `wp_mail` con `From: no-reply@akibara.cl` (NO validado en Brevo) y NO tiene unsubscribe link (incumple Ley 19.628 + Brevo policy). Además bypass parcial del email-testing-guard (guard solo redirige `*@akibara.cl`, emails reales `@gmail.com` van directo).
- **Findings origen:** F-09-003 P0/P2, F-09-008 P1, F-PRE-019.
- **Propuesta:**
  1. Verificar producción si meta `_akibara_notify_emails` tiene datos reales hoy.
  2. Si hay subscriptores legacy en theme → migrar a tabla `wp_akb_bis_subs` del módulo plugin.
  3. Eliminar sección `themes/akibara/inc/woocommerce.php:834-904`.
  4. Smoke test producto agotado real (24263) + flow E2E con email alejandro.fvaras@gmail.com.
  5. Documentar single source of truth: módulo plugin back-in-stock.
- **Pro:** elimina duplicate emails + bypass guard + sin unsubscribe; single SoT.
- **Contra:** requiere migración data si hay subscriptores legacy.
- **Riesgo:** medio (validar migración data antes de delete).
- **Esfuerzo:** S (50-100 LOC delete después de migración + smoke test)
- **Sprint sugerido:** S2
- **Robustez ganada:** elimina compliance violation + duplicate dispatch + bypass guard.
- **Owner skill sugerido:** [EMAIL] + [BACKEND] + [CLEANUP]
- **Mockup requerido:** NO

---

## Decisión #17: Productos test E2E (24261/62/63) → status `private` + visibility hidden

- **Contexto:** F-PRE-011 P1 (re-clasificado P3 task pre-launch por user): productos test visibles en home pública. Usuario confirmó es intencional para testing. mesa-22 F-22-007 + mesa-12 (sitemap) coinciden: solución más simple es `post_status=private` (sigue accesible para dev logged in, NO en queries públicas WC, NO en sitemap). mesa-07 F-07-006 + F-PRE-014 (Uncategorized badge) se resuelven automáticamente.
- **Findings origen:** F-PRE-011 P3, F-PRE-014 P3, F-22-007 P3, F-07-006 P3, F-PRE-012 P2 (subsumed).
- **Propuesta:** Acción única:
  1. Productos 24261/24262/24263 → `post_status=private`.
  2. Smoke test home incógnito: NO "TEST E2E" visible.
  3. Smoke test admin logged in: producto loads (testing E2E sigue funcional).
  4. Resolver simultáneamente F-PRE-012 (producto agotado + preventa simultáneo) corrigiendo meta keys del producto 24263.
- **Pro:** elimina brand damage; testing sigue funcional; resuelve 4 findings con 1 acción.
- **Contra:** ninguno.
- **Riesgo:** bajo.
- **Esfuerzo:** S (30 min admin + smoke test)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina UX leak + sitemap pollution + Uncategorized branding leak.
- **Owner skill sugerido:** [BACKEND] + [PM]
- **Mockup requerido:** NO

---

## Decisión #18: Sale price layout broken — validar antes que sea visualmente real

- **Contexto:** F-PRE-013 P2 (visible en home + single product). mesa-07 F-07-001 plantea: puede ser artefacto del accessibility tree extraction de Chrome MCP que expone `.screen-reader-text` por diseño. NO confirmó visualmente.
- **Findings origen:** F-PRE-013 P2, F-PRE-017 P2, F-07-001 P2.
- **Propuesta:** Pre-Sprint validación visual con screenshot DevTools:
  1. Si bug es visualmente real → fix template override `templates/single-product/price.php` + `templates/loop/price.php` con HTML correcto + spacing CSS.
  2. Si NO es visualmente real (false positive accessibility tree) → marcar WONTFIX con razón documentada.
  3. Si LiteSpeed cache combined CSS issue → debug separado.
- **Pro:** evita fix prematuro de algo que no es bug real.
- **Contra:** requiere validación visual (5-10 min).
- **Riesgo:** bajo.
- **Esfuerzo:** S (1.5h fix si confirmado, 5-10 min si false positive)
- **Sprint sugerido:** S1 (validación) → S2 (fix si confirmado)
- **Robustez ganada:** evita refactor templates por false positive.
- **Owner skill sugerido:** [FRONTEND]
- **Mockup requerido:** NO (es bug fix, NO cambio visual nuevo — mesa-13 ratifica)

---

## Decisión #19: Akibara_Reserva_Cart::atomic_stock_check() — fix bug $waited + defer refactor robusto

- **Contexto:** mesa-02 F-02-009 P1 + mesa-22 F-22-010 P1 + mesa-15 F-15-013 P2: función promete "atomic" pero usa transient mutex que NO es atómico. Bug literal `$waited = 0` (int), `$waited += 0.1` lo convierte a 1 (PHP coerce) → loop sale antes/después. Para 3 clientes solo theoretical risk. Hot preventas a futuro requiere refactor.
- **Findings origen:** F-02-009 P1, F-22-010 P1, F-15-013 P2, F-04-009 P2.
- **Propuesta:** Acción doble:
  1. **S1 bug fix:** `$waited = 0.0;` (float). 1 línea, evita coerce. Esto YA mejora reliability hoy.
  2. **S3 refactor robusto:** cuando aparezca volumen real (>5 reservas simultáneas), migrar a callback pattern `atomic_stock_check_and_act($product_id, $qty, callable $on_pass)` o `MySQL GET_LOCK()`. Hasta entonces, dejar transient + delay con bug fix.
- **Pro:** fix bug literal sin gold-plating; defer refactor robusto cuando hay datos reales.
- **Contra:** ninguno.
- **Riesgo:** bajo.
- **Esfuerzo:** S (5 min bug fix) + M (3-4h refactor si triggereado)
- **Sprint sugerido:** S1 (bug fix) + S3+ (refactor condicional)
- **Robustez ganada:** elimina coerce silencioso; defer refactor a cuando datos justifiquen.
- **Owner skill sugerido:** [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #20: BlueX webhook secret hard-fail + auto-generation activation

- **Contexto:** mesa-04 F-04-003 P0 + mesa-22 F-22-002 P0: si `AKB_BLUEX_WEBHOOK_SECRET` no configurado, endpoint acepta TODA request POST → atacante marca orders como completed/on-hold sin auth. Auto-generation solo en `admin_init` → si nunca admin entró post-install, webhook abierto.
- **Findings origen:** F-04-003 P0, F-22-002 P0.
- **Propuesta:**
  1. Cambiar `return true` → `return false` cuando secret vacío (hard-fail).
  2. Forzar auto-generation en `register_activation_hook` (no solo admin_init).
  3. Admin notice rojo si secret vacío + link "Regenerar" en settings.
  4. Validar header `X-BlueX-Secret` presente Y matches con `hash_equals`.
  5. Validar `order_id` realmente tiene tracking BlueX (defense in depth).
  6. Replay protection: timestamp + nonce window 5 min.
- **Pro:** elimina vector de spoofing órdenes; defense in depth.
- **Contra:** si BlueX legítimo no envía secret correcto, marcadas-completed dejan de funcionar.
- **Riesgo:** medio (validar primero secret está configurado en BlueX panel ANTES de hard-fail).
- **Esfuerzo:** S (1h)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina vector P0 webhook abierto.
- **Owner skill sugerido:** [SECURITY] + [PAYMENT]
- **Mockup requerido:** NO

---

## Decisión #21: 12horas webhook + REST endpoints — rate limiting + validation

- **Contexto:** mesa-10 F-10-011 P1 (12horas webhook abierto si OPT_WEBHOOK_TOKEN vacío) + mesa-22 F-22-001 P0 (REST `/cart/add` sin nonce ni rate limit) + F-22-003 P0 (REST `/mkt/open` y `/mkt/click` sin rate limit) + F-22-006 P1 (track_order endpoint sin rate limit IDOR risk) + mesa-10 F-10-008 P0 (Magic Link IP rate-limit confía en HTTP_CF_CONNECTING_IP sin validar) + F-10-009 P0 (encargos sin rate limit) + F-10-010 P1 (welcome-discount captcha sin rate limit) + F-10-012 P1 (search.php write-side-effect sin global rate limit).
- **Findings origen:** F-10-008/009/010/011/012 P0/P1, F-22-001/003/006 P0/P1.
- **Propuesta:** Sprint coordinado de hardening endpoints públicos:
  1. **S1 Critical:** F-22-002 (BlueX hard-fail, ver Decisión #20), F-10-008 (magic-link IP validar contra Cloudflare whitelist), F-10-009 (encargos rate limit 3/h per-IP + 2/día per-email), F-22-001 (cart/add rate limit 10/min/IP), F-10-011 (12horas webhook hard-fail si token vacío).
  2. **S2:** F-22-003 (mkt/open + mkt/click rate limit + cap array opens), F-22-006 (track_order rate limit 10/min/IP), F-10-010 (welcome-discount captcha rate limit), F-10-012 (search.php write side-effect → mover `akb_failed_searches` a tabla custom + global rate limit).
  3. Helper común: `akb_rate_limit($key, $max_requests, $window_seconds)` (ya existe en mu-plugins) — usar consistente.
- **Pro:** elimina vectors P0/P1 resource exhaustion + Brevo cap exhaustion + IDOR + spoofing.
- **Contra:** medium effort acumulado (~5-7h total).
- **Riesgo:** bajo (rate limits conservadores son safe defaults).
- **Esfuerzo:** M acumulado (S1 = 2-3h, S2 = 3-4h)
- **Sprint sugerido:** S1 (críticos) + S2 (resto)
- **Robustez ganada:** elimina muchos vectors de abuse simultáneo.
- **Owner skill sugerido:** [SECURITY] + [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #22: BACS RUT empresa + datos bancarios → mover a wp_options/admin settings

- **Contexto:** mesa-04 F-04-002 P0 + mesa-04 F-04-011 P2 + mesa-19 F-19-016: RUT empresa Akibara SpA (78.274.225-6) hardcoded en `themes/akibara/inc/bacs-details.php:22` + cuenta bancaria 39625300 Banco de Chile en `wp_options.woocommerce_bacs_accounts`. Inconsistencia case "Akibara Spa" vs "AKIBARA SpA". Si theme se filtra/forka, datos comerciales sensibles van también.
- **Findings origen:** F-04-002 P0, F-04-011 P2, F-19-016 P3.
- **Propuesta:**
  1. Mover RUT a `wp_options['akibara_business_rut']` o constante `wp-config.php`.
  2. Eliminar override theme `inc/bacs-details.php` que duplica WC BACS settings nativos.
  3. Verificar nombre legal SII oficial → unificar case.
  4. Considerar audit trail si BACS settings modificado (`update_option_woocommerce_bacs_accounts` action).
- **Pro:** elimina hardcoded sensitive data; pattern reusable; consistencia legal.
- **Contra:** ninguno técnico.
- **Riesgo:** bajo.
- **Esfuerzo:** S (1h)
- **Sprint sugerido:** S1
- **Robustez ganada:** datos comerciales sensibles fuera de theme; admin único place.
- **Owner skill sugerido:** [BACKEND] + [COMPLIANCE]
- **Mockup requerido:** NO

---

## Decisión #23: MercadoPago Custom Gateway — mantener (NO desactivar) + S3 hardening

- **Contexto:** mesa-04 F-04-007 P1: MP Custom Gateway ENABLED en prod (orden 23632/23628/23542 confirma uso). Eleva scope PCI de SAQ-A a SAQ-A-EP (Akibara entra en responsabilidad PCI sobre TLS, JS supply chain, CSP). mesa-04 ofrece Opción A (desactivar Custom, dejar solo Basic redirect SAQ-A) o Opción B (asumir SAQ-A-EP con CSP estricto + SRI + audit JS quarterly + SAQ-A-EP self-assessment).
- **Findings origen:** F-04-007 P1.
- **Propuesta:** **NO actuar precipitadamente. Decisión a Mesa-23 PM en sesión con dueño.** Tienda en arranque (3 clientes), Custom UX puede ser ventaja conversion. Pero compliance PCI tiene costo real en seguridad. Recomendación lead:
  1. **Hoy (S1):** documentar awareness en ADR `docs/adr/payment-pci-scope.md` con trade-off explícito.
  2. **S2:** F-04-004 + F-04-008 (mu-plugin akibara-mercadopago-hardening con rate-limit per IP + idempotency check transient `akb_mp_payment_<id>` TTL 24h) — esto reduce DoS amplification independiente de scope PCI.
  3. **S3+:** Si se mantiene Custom: implementar CSP estricto + SRI en MP SDK (requiere análisis de scripts third-party WC + Sentry + GA4). Si se cambia a Basic: requiere mockup checkout (UX cambia: 1 click "Continuar a MP" en vez de form inline).
- **Pro:** decisión informada por dueño; no romper flujo conversion sin alternativa preparada; hardening defensivo independiente.
- **Contra:** SAQ-A-EP scope persiste hasta decisión.
- **Riesgo:** alto si se actúa sin entender impacto conversion.
- **Esfuerzo:** S (ADR doc) + M (hardening mu-plugin) + L (CSP estricto si elige Opción B)
- **Sprint sugerido:** S1 (ADR) + S2 (hardening) + S3+ (decisión + implementación)
- **Robustez ganada:** decisión data-driven con dueño; defensiva independiente del scope.
- **Owner skill sugerido:** [PAYMENT] + [SECURITY] + [PM]
- **Mockup requerido:** SÍ si Opción A (checkout flow cambio)

---

## Decisión #24: Duplicate REST `/akibara/v1/health` endpoint — eliminar duplicación theme

- **Contexto:** mesa-22 F-22-012 P1: duplicate registro `register_rest_route('akibara/v1', '/health', ...)` en `themes/akibara/inc/health.php:19-26` Y `plugins/akibara/modules/health-check/module.php:23-36`. Theme guard `defined('AKIBARA_HEALTH_CHECK_LOADED')` depende del orden de carga. Plugin ya provee endpoint correcto.
- **Findings origen:** F-22-012 P1.
- **Propuesta:** Eliminar `themes/akibara/inc/health.php` completo. Plugin module health-check es source of truth. UptimeRobot/monitoring puede pegar a HEAD del homepage o al endpoint del plugin.
- **Pro:** elimina coupling theme→plugin guard frágil; single SoT.
- **Contra:** ninguno (plugin endpoint es funcional).
- **Riesgo:** bajo.
- **Esfuerzo:** S (15 min delete + smoke test endpoint plugin sigue funcional)
- **Sprint sugerido:** S2
- **Robustez ganada:** consistency; menos coupling implícito.
- **Owner skill sugerido:** [BACKEND] + [CLEANUP]
- **Mockup requerido:** NO

---

## Decisión #25: Duplicate `setup.php` (root vs inc/) + `enqueue.php.bak` cleanup

- **Contexto:** mesa-07 F-07-004 P2 + F-07-005 P3 + mesa-02 F-02-005 P3 + mesa-15 F-15-007 P3 + mesa-22 F-22-018 P2: theme tiene `setup.php` (root) y `inc/setup.php` byte-idénticos (PHP fatal si ambos cargan, dead code si uno solo). Y `inc/enqueue.php.bak-2026-04-25-pre-fix` (12.4 KB) leftover backup.
- **Findings origen:** F-07-004 P2, F-07-005 P3, F-02-005 P3, F-15-007 P3, F-22-018 P2, F-08-008 P3 (hero-section.css duplicado).
- **Propuesta:** Cleanup batch en S1:
  1. Verificar cuál `setup.php` se carga via grep require → borrar el redundante.
  2. Borrar `inc/enqueue.php.bak-2026-04-25-pre-fix` (commit del fix está en main confirmado).
  3. Borrar `themes/akibara/hero-section.css` (root, NO enqueued; `assets/css/hero-section.css` SÍ enqueued).
  4. Documentar en deploy script regla "exclude *.bak/.backup/.old".
- **Pro:** elimina dead code + leftover; clean repo; reduce confusion futuros mantenedores.
- **Contra:** ninguno.
- **Riesgo:** mínimo.
- **Esfuerzo:** S (30 min batch)
- **Sprint sugerido:** S1
- **Robustez ganada:** elimina riesgo PHP fatal por double-load; menos noise filesystem.
- **Owner skill sugerido:** [CLEANUP]
- **Mockup requerido:** NO

---

## Decisión #26: Duplicate robots meta tag conflict — Rank Math single source of truth

- **Contexto:** mesa-12 F-12-001 P0: en URLs con filter params (`/manga/?orderby=price`), se emiten 2 meta robots tags conflictivas (custom `noindex.php` dice `noindex,nofollow`, Rank Math dice `index,follow`). Search engines tratan como ambiguous → puede penalizar.
- **Findings origen:** F-12-001 P0, F-12-002 P1 (breadcrumb position string), F-12-003 P2.
- **Propuesta:**
  1. **S1 (P0 conflict):** Decidir Rank Math como single source. Migrar lógica de `themes/akibara/inc/seo/noindex.php` a filter `rank_math/frontend/robots` con custom rules (filter params + paginación >5).
  2. **S1 (F-12-002):** Fix `BreadcrumbList` schema: emitir `position` como `(int)$position` en JSON-LD.
  3. **S2 (F-12-003):** Re-evaluar threshold `noindex >5` para 1.371 productos / 24 per_page = 57 páginas. Considerar `noindex,follow` (no index pero crawl links).
- **Pro:** elimina ambiguous robots; SEO clean; Schema válido.
- **Contra:** ninguno.
- **Riesgo:** bajo (validar rastreo Google Search Console post-cambio).
- **Esfuerzo:** S (1h)
- **Sprint sugerido:** S1
- **Robustez ganada:** SEO sin penalización por conflict signals.
- **Owner skill sugerido:** [SEO] + [BACKEND]
- **Mockup requerido:** NO

---

## Decisión #27: Sitemap Rank Math — verificar productos test excluded + paginación correcta

- **Contexto:** mesa-12 F-12-006 P2: sitemap manejado por Rank Math, 1.371 productos requieren paginación correcta. Sin verificación, productos test 24261/62/63 pueden estar en sitemap (Decisión #17 los pone `private` lo cual los excluye automático).
- **Findings origen:** F-12-006 P2.
- **Propuesta:** Post-Decisión #17 (productos test → private), validar `curl https://akibara.cl/sitemap_index.xml` + sub-sitemaps:
  1. Productos test NO aparecen.
  2. Pagination chunks correctos (Rank Math default 200/page).
  3. Sitemap carga rápido (<3s).
  4. Si productos test aparecen aún → excluir explícito en Rank Math settings.
- **Pro:** SEO clean; sitemap correcto.
- **Contra:** ninguno.
- **Riesgo:** bajo.
- **Esfuerzo:** S (15 min validación)
- **Sprint sugerido:** S1 (post-Decisión #17)
- **Robustez ganada:** SEO foundations sólidas.
- **Owner skill sugerido:** [SEO]
- **Mockup requerido:** NO
- **Bloqueada por:** Decisión #17

---

## Decisión #28: Design tokens fantasma — definir explícitamente en :root

- **Contexto:** mesa-08 F-08-001 P1: 5 tokens (`--aki-text`, `--aki-text-muted`, `--aki-text-dim`, `--aki-surface-1`, `--aki-primary-hover`) NO existen en `:root`. 14+ use sites en checkout/woocommerce.css resuelven al fallback hardcoded. `--aki-primary-hover, #d94a30` es ORANGE (cinnabar) — branding "Manga Crimson v3" se ROMPE si se renderiza ese fallback. Otros tokens fantasma divergen entre fallback strings ('#888' vs '#A0A0A0' vs '#9a9a9a').
- **Findings origen:** F-08-001 P1, F-08-013 P3, F-08-016 P2.
- **Propuesta:** Definir explícitamente en `:root` del `design-system.css`:
  ```css
  --aki-text: var(--aki-gray-100);
  --aki-text-muted: var(--aki-gray-400);
  --aki-text-dim: var(--aki-gray-500);
  --aki-surface-1: var(--aki-surface);
  --aki-primary-hover: var(--aki-red-hover);
  ```
  + convención: fallbacks DEBEN coincidir con valor real del token (lint rule o auditoría manual S3).
- **Pro:** branding consistente; evita color shift si fallback se renderiza; auditable.
- **Contra:** ninguno.
- **Riesgo:** bajo (cambio invisible si tokens existían pero ahora fueron formales).
- **Esfuerzo:** S (45 min)
- **Sprint sugerido:** S2
- **Robustez ganada:** brand consistency garantizada; design system auditable.
- **Owner skill sugerido:** [FRONTEND]
- **Mockup requerido:** NO

---

## Decisión #29: Focus rings WCAG 2.4.13 — outline solid en lugar de box-shadow alpha

- **Contexto:** mesa-08 F-08-002 P1: patrón `outline: none + border-color + box-shadow rgba(217,0,16,0.15)` en checkout/select2/popup forms. Alpha 0.15 sobre surface #161618 = contraste 1.07:1 → INVISIBLE como focus indicator. Forms checkout, login, encargos, rastreo afectados (FAIL WCAG 2.4.13 Focus Appearance).
- **Findings origen:** F-08-002 P1, F-08-006 P2 (28 outline:none audit), F-08-010 P3 (hero hotspots).
- **Propuesta:** Cambiar a outline solid alineado con design-system:
  ```css
  outline: 2px solid var(--aki-red-bright);
  outline-offset: 2px;
  border-color: var(--aki-red-bright); /* refuerzo */
  ```
  Aplicar a 6 sites: `checkout.css:294-297`, `woocommerce.css:1695-1702`, `account.css:181-185`, `pages-custom.css:6,51`, `hero-section.css:220-224`. Auditar 28 declaraciones `outline:none` adicionales en S3.
- **Pro:** accessibility WCAG AA conforme; forms accesibles para keyboard navigation.
- **Contra:** ninguno (outline solid es estándar).
- **Riesgo:** bajo (cambio visual mínimo, solo focus state).
- **Esfuerzo:** S (1h fix 6 sites)
- **Sprint sugerido:** S1
- **Robustez ganada:** accessibility forms; mejor experience keyboard users.
- **Owner skill sugerido:** [FRONTEND]
- **Mockup requerido:** NO (es accessibility fix, NO cambio visual nuevo)

---

## Decisión #30: Customer-facing popup contraste FAIL AA — refactor a tokens

- **Contexto:** mesa-08 F-08-003 P1: 3 popups customer-facing (popup, welcome-discount) usan hex literales (`#888 #aaa #555 #1a1a1a`). 5 instancias FAIL contraste AA: `.aki-popup__legal #555 on #1a1a1a = 2.33:1` FAIL, idem `.aki-popup__no-thanks`, `.aki-popup__input::placeholder #555 on #111 = 2.53:1` FAIL. Adicional: `.akb-wd-popup__close` sin `:focus-visible` declarado.
- **Findings origen:** F-08-003 P1, F-08-018 P2 (footer newsletter placeholder), F-08-019 P2 (account login placeholder).
- **Propuesta:** Reemplazar hex por tokens existentes en `popup/popup.css` y `welcome-discount/popup.css`:
  - `#888 → var(--aki-gray-400)` (5.47:1 PASS)
  - `#555 → var(--aki-gray-400)` (PASS) o `--aki-gray-500` solo decorativo
  - `#1a1a1a → var(--aki-surface-2)`, `#111 → var(--aki-surface)`
  - Agregar `:focus-visible` a buttons close
  - Footer newsletter (`popup/popup.css:330`) + account login placeholder (`account.css:177-179`): subir alpha a 0.55 mínimo
- **Pro:** WCAG AA compliance en puntos primarios captura leads; brand consistency.
- **Contra:** ninguno.
- **Riesgo:** bajo (cambios mínimos visuales aceptables).
- **Esfuerzo:** S (1h por popup × 2 + smoke test)
- **Sprint sugerido:** S2
- **Robustez ganada:** accessibility lead-capture forms; consistencia design system.
- **Owner skill sugerido:** [FRONTEND]
- **Mockup requerido:** NO (es accessibility/token refactor, NO cambio visual nuevo)

---

## Resumen ejecutivo de decisiones

| # | Decisión | Sprint | Owner | Mockup |
|---|---|---|---|---|
| 1 | CANCELAR CLEAN-001/003/004 | S1 | ARCHITECTURE | NO |
| 2 | WP core verify-checksums prerequisito | S1 | SECURITY | NO |
| 3 | SEC-P0-001 expansion forense | S1 | SECURITY | NO |
| 4 | CLEAN-002 cart-abandoned defer S2 condicional | S1+S2 | EMAIL+SETUP | NO |
| 5 | Cleanup vendor/coverage/dev tooling | S1 | SECURITY+SETUP+CLEANUP | NO |
| 6 | Hostinger crontab setup | S1 | SETUP+BACKEND | NO |
| 7 | mu-plugin akibara-security-headers | S1 | SECURITY | NO |
| 8 | Cookie consent banner | S2 | COMPLIANCE+FRONTEND | SÍ |
| 9 | Bootstrap legal pages expandido | S1+S2 | COMPLIANCE | NO |
| 10 | Encargos opt-in fix | S1+S2 | COMPLIANCE+BACKEND | SÍ (mín) |
| 11 | Auto-OOS-to-preventa opt-in | S2 | BACKEND+ARCHITECTURE | SÍ |
| 12 | Module Registry guard DRY | S2 | ARCHITECTURE+BACKEND | NO |
| 13 | Preventa/Encargo/Agotado UX matrix | S2 | ARCHITECTURE+FRONTEND | SÍ |
| 14 | Descuentos consolidación deferred | S3+ | ARCHITECTURE+BACKEND | SÍ |
| 15 | Brevo upgrade trigger >50/mo | S2+S4+ | EMAIL+BACKEND | NO |
| 16 | Theme back-in-stock duplicado eliminate | S2 | EMAIL+BACKEND+CLEANUP | NO |
| 17 | Productos test → status private | S1 | BACKEND+PM | NO |
| 18 | Sale price layout broken validation | S1 | FRONTEND | NO |
| 19 | atomic_stock_check bug fix + defer refactor | S1+S3+ | BACKEND | NO |
| 20 | BlueX webhook hard-fail + auto-gen | S1 | SECURITY+PAYMENT | NO |
| 21 | REST endpoints + 12horas rate limiting | S1+S2 | SECURITY+BACKEND | NO |
| 22 | BACS RUT empresa → wp_options | S1 | BACKEND+COMPLIANCE | NO |
| 23 | MP Custom Gateway hardening + decisión | S1+S2+S3 | PAYMENT+SECURITY+PM | SÍ |
| 24 | Duplicate /health endpoint cleanup | S2 | BACKEND+CLEANUP | NO |
| 25 | Duplicate setup.php + enqueue.bak cleanup | S1 | CLEANUP | NO |
| 26 | Robots meta conflict + breadcrumb position | S1 | SEO+BACKEND | NO |
| 27 | Sitemap Rank Math validation | S1 | SEO | NO |
| 28 | Design tokens fantasma → :root | S2 | FRONTEND | NO |
| 29 | Focus rings WCAG outline solid | S1 | FRONTEND | NO |
| 30 | Popup contraste FAIL AA → tokens | S2 | FRONTEND | NO |

**Total decisiones:** 30 (P0/P1 prioritized; P2/P3 cluster en BACKLOG sin requerir decisión cross-cutting).

**Items que NO entran al backlog (DEFERIDOS por YAGNI universal):**
- F-PRE-003 popup hardcoded a 1 step → S4+ condicional
- F-15-015 popup module 1 caso → S4+ condicional
- F-15-022 PSR-4 incompleto → mantener intermedio
- F-15-014 filter_price flag estática → solo doc
- F-08-007 touch targets sub-44px AAA → S4+ (cumple AA)
- F-02-021 series-autofill migration → S3 verificar
- F-02-022 bootstrap-legal-pages mu-plugin one-shot → S3 (después de verify pages OK)
- F-02-024 descuentos formato v10 dead path → S3
- F-09-013 welcome-series opt-out parcial → S3
- F-12-005 IndexNow Chile valor cuestionable → backlog low
- F-08-011 strength meter colors → S4+
- F-08-014 skip-link iOS → S4+
- F-10-018 transients no auto-cleanup → S4+
- F-10-020 CSP estricta → S4+ (después de tráfico real)
