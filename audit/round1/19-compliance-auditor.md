---
agent: compliance-auditor
round: 1
date: 2026-04-26
scope: Compliance Chile (Ley 19.628 + 21.719) + GDPR readiness — legal pages, cookie consent, RUT handling, RTBF, third-party tracking, email opt-in, preventa terms, SII boletas
files_examined: 14
findings_count: { P0: 5, P1: 6, P2: 5, P3: 3 }
---

## Resumen ejecutivo

- **NO existe banner cookie consent.** El sitio carga GA4, Microsoft Clarity, cookies Cloudflare, _ga, cookies referrals (akb_ref) y welcome-discount (akibara_wd_*) ANTES de cualquier consentimiento. Viola Ley 21.719 (vigente Dec 2026), sin defensa contra reclamos Sernac. Único guard real: `navigator.doNotTrack`. **F-19-001 P0**.
- **Encargos form (`/inc/encargos.php:75-93`) suscribe silenciosamente a Brevo lista 2 (newsletter) sin checkbox opt-in ni double-opt-in.** Violación directa Ley 19.628 art. 4. **F-19-003 P0**.
- **Footer NO tiene link a Cookies** ni la página existe. Política Privacidad menciona cookies en una sección genérica. **F-19-002 P1**.
- **Política Privacidad tiene gaps frente Ley 21.719**: no menciona Agencia Protección Datos, no diferencia "responsable"/"encargado", no especifica plazos solicitudes ARCO+P, email contacto privacidad ausente. **F-19-007 P1**.
- **akibara-reservas (preventa) NO tiene términos específicos** en producto ni checkout (refund cancelación editorial, fecha estimada vs garantizada). `/devoluciones/` excluye preventas ya despachadas pero no cubre pendientes. **Riesgo Sernac alto**. **F-19-004 P0**.

## Findings P0

### F-19-001: Sin cookie consent banner — tracking + cookies se cargan sin consent
- **Severidad:** P0
- **Categoría:** SECURITY / COMPLIANCE
- **Archivo(s):** `themes/akibara/inc/clarity.php:37-51`, `plugins/akibara/modules/ga4/module.php:160-185`, `referrals/module.php:175`, `welcome-discount/module.php:431-433`, `themes/akibara/header.php:1-9` (NO renderiza banner), `footer.php:1-93` (NO link cookies)
- **Descripción:** Akibara carga scripts third-party tracking (Microsoft Clarity, GA4, gtag.js, Google OAuth) Y setea cookies persistentes (`akb_ref` 30 días, `akibara_wd_*` 7-365 días, `akb_clarity_*`) sin pedir consentimiento. Ley 21.719 (vigencia plena Dec 2026) y Ley 19.628 art. 4 exigen consent informado. GA4 + Clarity son analytics → requieren opt-in.
- **Propuesta:** Construir mu-plugin custom `akibara-cookie-consent.php`:
  1. Banner sticky bottom 3 categorías (Necesarias / Analytics / Marketing) — Necesarias siempre on
  2. Almacenar decisión en cookie `akb_consent_v1` (1 año) + localStorage
  3. API JS `window.akbConsent.has('analytics')` para que Clarity/GA4 chequeen
  4. Refactor `clarity.php` y `ga4/module.php` para gatear snippet/enqueue
  5. Crear página `/cookies/` con inventario completo (F-19-002)
  6. Link "Gestionar cookies" en footer
  Diseño visual REQUIERE MOCKUP.
- **Esfuerzo:** L (banner + refactor 4-5 callsites + página + UI)
- **Sprint:** S2 (P0 legal pero requiere mockup)
- **Requiere mockup:** SÍ

### F-19-002: Página /cookies/ no existe + inventario cookies no documentado
- **Severidad:** P1
- **Archivo(s):** `mu-plugins/akibara-bootstrap-legal-pages.php:33-44` (solo bootstrap politica + devoluciones)
- **Descripción:** Bootstrap solo crea Política Privacidad y Devoluciones. NO crea página `/cookies/` con inventario detallado. Política Privacidad sección 8 menciona cookies genérico. Ley 21.719 requiere transparencia detallada.
- **Inventario cookies detectadas:**
  | Cookie | Origen | Propósito | Duración | Categoría |
  |---|---|---|---|---|
  | akb_ref | referrals | Tracking referrals | 30d | Marketing |
  | akibara_wd_shown | welcome-discount | Frecuencia popup | 7d | Marketing |
  | akibara_wd_dismissed_at | welcome-discount | Estado popup | 7d | Marketing |
  | akibara_wd_subscribed | welcome-discount | Estado suscripción | 365d | Funcional |
  | akibara_popup_seen | popup | Estado popup | session | Funcional |
  | _ga, _ga_* | GA4 | Analytics Google | 2 años | Analytics |
  | _clck, _clsk | Clarity (clarity.ms) | Session recordings | 1 año | Analytics |
  | wc_*, woocommerce_* | WC core | Carrito + sesión | sesión | Necesaria |
  | __cf_bm, cf_clearance | Cloudflare | Anti-bot | varía | Necesaria |
  | wordpress_logged_in_* | WP core | Auth admin | 14d | Necesaria |
- **Propuesta:** Agregar `'cookies'` al array `$pages` en bootstrap + función `akibara_legal_cookies_content()` con inventario tabulado. Bumpear flag bootstrap a 3. Agregar link footer "Política de Cookies".
- **Esfuerzo:** S (1-2h)
- **Sprint:** S1 (prerequisito de F-19-001 banner)

### F-19-003: Encargos form auto-suscribe a Brevo lista 2 (newsletter) sin consent
- **Severidad:** P0
- **Archivo(s):** `themes/akibara/inc/encargos.php:71-93`
- **Descripción:** Cuando visitante llena form "encargos" (pedir un manga no en stock), handler AJAX automáticamente lo agrega a Brevo lista ID 2 ("Newsletter") sin checkbox opt-in, sin double-opt-in. Intención usuario: hacer encargo, NO recibir newsletter. Violación clara Ley 19.628 art. 4 (consentimiento libre, expreso, informado) + futura Ley 21.719 + GDPR art. 6(1)(a).
- **Propuesta:**
  1. Crear lista Brevo dedicada "Encargos" (ej. ID 32) que NO sea de marketing
  2. Cambiar `listIds => [ 32 ]` (transaccional)
  3. Agregar checkbox opcional `<label><input type="checkbox" name="newsletter_optin"> Quiero recibir novedades por email</label>`
  4. Solo si checkbox marcado, agregar a lista 2 (newsletter) además
  5. Auditar todos los demás callsites que escriban a lista 2 sin opt-in explícito
- **Esfuerzo:** S (30-60 min)
- **Sprint:** S1

### F-19-004: akibara-reservas (preventa) sin términos específicos para Sernac
- **Severidad:** P0
- **Categoría:** COMPLIANCE / UX
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-frontend.php:1-100` + `mu-plugins/akibara-bootstrap-legal-pages.php:179-184`
- **Descripción:** Akibara cobra 100% precio preventa al momento de reserva. Cliente recibe producto cuando llega Argentina/España (~30+ días). Riesgos NO mitigados:
  1. NO término explícito qué pasa si editorial cancela lanzamiento (refund 100%? plazo?)
  2. NO término qué pasa si pasan +60 días desde fecha estimada
  3. Fecha "📦 Fecha por confirmar" / "~30 días est." NO especifica si es plazo máximo o estimado
  4. Ley 19.496 (Consumidor) art. 12A — derecho retracto 10 días desde recepción; preventa aún no recibida = caso ambiguo Sernac
  5. NO aceptación explícita términos en botón "Reservar ahora"
- **Propuesta:**
  1. Sección a `/devoluciones/` "Preventas — Reglas específicas":
     - Reembolso 100% si editorial cancela (10 días hábiles desde notificación)
     - Refund 100% si pasan +90 días sin recibir del proveedor
     - Cliente puede solicitar refund 100% en cualquier momento ANTES del despacho
     - Fecha "Por confirmar" significa que editorial aún no anunció lanzamiento
  2. Checkbox obligatorio en single product preventa: `<input type="checkbox" required> Acepto los <a href="/devoluciones/#preventas">términos de preventa</a>`
  3. Email confirmación preventa debe linkear a términos
- **Esfuerzo:** M (contenido + UI checkbox + diseño + emails)
- **Sprint:** S1
- **Requiere mockup:** SÍ (parcial — checkbox single product)

### F-19-005: GA4 Measurement Protocol envía email/IP a Google sin consent gating
- **Severidad:** P0
- **Archivo(s):** `plugins/akibara/modules/ga4/module.php:494-577`, `:619-663`, `:585-597`
- **Descripción:** Módulo GA4 server-side envía via Measurement Protocol a Google: transaction_id, value, currency, items con SKU + categorías, user_id (WP), client_id (cookie _ga). Se hace en `woocommerce_order_status_completed/processing` SIN consultar consent — incluso si cliente NO aceptó analytics, datos de compra van a Google. Transferencia internacional Chile → USA requiere base legal explícita Ley 21.719 — actualmente NO documentada.
- **Propuesta:**
  1. Gatear `akb_ga4_server_purchase()` con check de consent del cliente — si NO aceptó analytics, NO enviar
  2. Persistir consent decision en order meta cuando hace checkout
  3. Actualizar política privacidad sección 5 con transferencia internacional explícita
  4. Verificar affiliation y transaction_id no expongan PII directa
- **Esfuerzo:** M (consent meta persistence + gating + policy update)
- **Sprint:** S2 (depende F-19-001 banner)

## Findings P1

### F-19-006: RTBF (Right To Be Forgotten) — solo Brevo cubierto, falta WC user/order data
- **Severidad:** P1
- **Categoría:** COMPLIANCE
- **Archivo(s):** `plugins/akibara/modules/brevo/module.php:917-946` (eraser solo Brevo)
- **Descripción:** Módulo Brevo registra `wp_privacy_personal_data_erasers` que borra contacto Brevo cuando WP nativo dispara erase. Hay otros lugares con PII sin eraser:
  1. `wp_akibara_magic_tokens` — guarda email + IP, sin retention ni eraser
  2. `wp_akb_referrals` — guarda referrer_email, referee_email, referee_ip, sin eraser
  3. `wp_akb_wd_log` — emails con IPs (si activo), sin eraser
  4. `wp_options['akibara_encargos_log']` — array encargos con nombre/email/RUT, sin eraser
  5. Order meta `_billing_rut` HPOS — RUT cliente persiste indefinidamente
  6. User meta `billing_rut`, `akibara_google_id`, `akibara_google_avatar` — sin eraser
  WP nativo eraser borra user pero NO custom tables ni custom user meta. Ley 21.719 art. 11 exige borrado completo o anonimización.
- **Propuesta:** Crear módulo `personal-data` (custom):
  1. Eraser callback que borre/anonymize: rows en custom tables por email; user meta billing_rut, akibara_google_*; order meta `_billing_rut` (anonymize a XX.XXX.XXX-X per Ley 19.628 art. 9bis para records contables que SII obliga retener); entradas akibara_encargos_log
  2. Exporter callback que devuelva: encargos del email, referrals enviados/recibidos, magic tokens recientes, RUT
  3. Documentar retention en política privacidad
  4. Botón en `/mi-cuenta/` "Solicitar eliminación de mis datos"
- **Esfuerzo:** M (eraser/exporter para 5 stores + UI)
- **Sprint:** S2-S3
- **Requiere mockup:** SÍ

### F-19-007: Política privacidad gaps con Ley 21.719 (vigente Dec 2026)
- **Severidad:** P1
- **Archivo(s):** `mu-plugins/akibara-bootstrap-legal-pages.php:69-134`
- **Descripción:** Política privacidad bootstrappeada está alineada con Ley 19.628 (vigente actual) pero NO con Ley 21.719. Gaps:
  1. NO menciona Agencia de Protección de Datos (creada por Ley 21.719)
  2. NO diferencia "responsable" vs "encargado" del tratamiento (terminología nueva ley)
  3. NO especifica plazos concretos para responder solicitudes ARCO+P (art. 13 da 30 días + 30 prórroga)
  4. Email contacto privacidad genérico (envía a `/contacto/`)
  5. NO menciona base legal por categoría datos
  6. Sección 8 cookies demasiado genérica (F-19-002)
  7. Sección 10 menores dice "menor de 14" — Ley 21.719 art. 16 no fija edad mínima absoluta
  8. Sección 11 dice "lo comunicaremos por correo" — solo funciona para suscritos
- **Propuesta:** Reescribir `akibara_legal_privacy_content()`:
  - Nueva sección "Agencia Protección Datos" con info reclamo
  - Sección "Responsable y Encargados" con tabla (Akibara SpA + Brevo, BlueX, Correos, Mercado Pago, Webpay/Flow, Cloudflare, Google)
  - Sección "Plazos de respuesta" 30 días + prórroga
  - Email dedicado `privacidad@akibara.cl`
  - Tabla bases legales por finalidad
  - Bumpear flag bootstrap
- **Esfuerzo:** M (3-5h escritura + revisión)
- **Sprint:** S2

### F-19-008: Magic Link guarda IP del visitante 15min sin documentación retention
- **Severidad:** P1
- **Archivo(s):** `themes/akibara/inc/magic-link.php:27-39, 97-103`
- **Descripción:** Tabla `akibara_magic_tokens` guarda `ip varchar(45) NOT NULL` para rate limiting. PII bajo Ley 19.628. NO hay:
  1. Retention policy (tokens vencen 15 min pero rows persisten indefinidamente)
  2. Mención política privacidad
  3. Eraser (F-19-006)
- **Propuesta:**
  1. Cron diario borre rows con `expires_at < NOW() - INTERVAL 7 DAY`
  2. Documentar política privacidad: "Tokens magic link: 7 días"
  3. Eraser handler (F-19-006)
- **Sprint:** S2

### F-19-009: Newsletter footer (Brevo) sin checkbox consent explícito
- **Severidad:** P1
- **Archivo(s):** `themes/akibara/inc/newsletter.php:44-72`
- **Descripción:** Form newsletter footer tiene "Sin spam. Puedes salir cuando quieras." pero NO checkbox explícito. Acción submit ≠ opt-in afirmativo bajo Ley 21.719 art. 14. Handler `akibara_newsletter_subscribe()` NO implementa double opt-in (welcome-discount sí lo tiene).
- **Propuesta:**
  1. Checkbox: `<label><input type="checkbox" required> Quiero recibir novedades, lanzamientos y descuentos por email</label>`
  2. Implementar double opt-in: cambiar wp_remote_post directo a `Akibara\Infra\Brevo::sync_contact()` con flag doubleOptin=true o reusar flujo welcome-discount
  3. Email confirmación con link único
- **Esfuerzo:** M (refactor double opt-in + UI)
- **Sprint:** S2
- **Requiere mockup:** SÍ

### F-19-010: Google OAuth NO informa qué data se comparte
- **Severidad:** P1
- **Archivo(s):** `themes/akibara/inc/google-auth.php:22-38, 158, 143-147`
- **Descripción:** Flow Google OAuth solicita scopes openid email profile. Al hacer login con Google, crea cuenta WP con wc_create_new_customer(). Usuario NO ve en Akibara (antes redirect a Google) qué datos vamos a recibir y guardar (google_id, email, given_name, family_name, picture). Google muestra consent screen pero no es suficiente para Ley 21.719 — el responsable (Akibara) debe informar antes del tratamiento. Adicional: `wp_set_auth_cookie( $user->ID, true )` con remember=true (cookie 14 días) sin checkbox.
- **Propuesta:**
  1. Antes botón "Iniciar sesión con Google" en mi-cuenta, agregar:
     > "Al continuar con Google, recibiremos tu nombre, email y foto de perfil. Crearemos cuenta en Akibara con esos datos. Puedes eliminar tu cuenta en cualquier momento desde Mi Cuenta. Más info en [Política de Privacidad](/politica-de-privacidad/)."
  2. Agregar `'akibara_google_*'` user_meta a lista exporter/eraser (F-19-006)
  3. Documentar Google como "encargado del tratamiento"
- **Esfuerzo:** S (texto informativo)
- **Sprint:** S2
- **Requiere mockup:** SÍ

### F-19-011: SII / Boletas electrónicas — NO hay módulo custom integrado
- **Severidad:** P2
- **Categoría:** COMPLIANCE / SETUP
- **Descripción:** Akibara SpA con RUT 78.274.225-6 vende a clientes Chile. Ley 19.983 + obligación SII desde 2014 exige emisión Documento Tributario Electrónico (DTE). NO veo módulo custom que conecte con SII (vía SII webservice, OpenFactura, Toku) ni que solicite RUT empresa para facturación. Probablemente manejo manual.
- **Propuesta:** Consultar dueño:
  1. ¿Cómo emite boletas hoy?
  2. ¿Necesita módulo custom integración SII?
  3. ¿Necesita campo "Tipo documento" (Boleta/Factura) en checkout con RUT empresa?
  Si "manual hoy, automatizar después" → S3-S4. Si "necesito ya" → S2.
  Nota: OpenFactura/Toku son APIs (no plugins WP) → integración via custom code válida bajo "no third-party plugins"
- **Esfuerzo:** L (si decide automatizar)
- **Sprint:** S3 (necesita conversación owner primero)
- **Requiere mockup:** SÍ

### F-19-012: encargos_log crece sin límite real (200 cap, pero PII en wp_options)
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/encargos.php:50-69`
- **Descripción:** `update_option( 'akibara_encargos_log', $encargos, false )` guarda hasta 200 encargos como array serializado. Cada entry tiene nombre, email, titulo, editorial, notas. PII en tabla NO indexada → casi imposible para responder eraser request individual. Sin retention basada en tiempo. Sin search por email.
- **Propuesta:**
  1. Migrar a tabla custom `wp_akb_encargos` con índice por email + created_at
  2. Cron mensual borre encargos > 12 meses con status='completado'
  3. Eraser callback (F-19-006)
  4. UI admin minimal lista encargos pendientes
- **Esfuerzo:** M (tabla + migración + admin minimal)
- **Sprint:** S3

### F-19-013: Política devoluciones cita Ley 19.496 OK pero falta Sernac proceso
- **Severidad:** P2
- **Archivo(s):** `mu-plugins/akibara-bootstrap-legal-pages.php:136-198`
- **Descripción:** Política devoluciones cita Ley 19.496 art. retracto + plazo 10 días. Falta:
  1. Cómo escalar a Sernac si Akibara no responde
  2. Período garantía dice "6 meses" — correcto art. 21 garantía legal mínima, podría especificar libros/manga garantía defectos editoriales
  3. Mención productos preventa insuficiente (F-19-004)
- **Propuesta:** Agregar sección 9: "Si tienes problema y no logramos resolverlo, puedes presentar reclamo formal en [Sernac](https://www.sernac.cl/) llamando 800 700 100 o en [reclamos.sernac.cl](https://reclamos.sernac.cl/)"
- **Esfuerzo:** S (15 min)
- **Sprint:** S2

### F-19-014: RUT validation captura PII pero no informa al usuario por qué
- **Severidad:** P3
- **Archivo(s):** `plugins/akibara/modules/rut/module.php:36-52`
- **Descripción:** Campo RUT en checkout es required. RUT es PII Chile. NO hay tooltip/explicación de POR QUÉ se pide. Bajo Ley 21.719 principio transparencia, el responsable debe informar finalidad.
- **Propuesta:** Agregar texto helper bajo campo RUT:
  > "El RUT es necesario para que las empresas de envío puedan entregar tu pedido. No lo compartimos con terceros excepto con el courier que despachará tu compra."
- **Sprint:** S2

### F-19-015: Email transaccional wp_mail magic-link sin List-Unsubscribe header
- **Severidad:** P3
- **Categoría:** COMPLIANCE / GROWTH-READY
- **Archivo(s):** `themes/akibara/inc/magic-link.php:112-117`
- **Descripción:** Headers email magic-link son Content-Type, X-Auto-Response-Suppress, Precedence: bulk. Falta `List-Unsubscribe` (Gmail/Outlook usa para mejor deliverability) y `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058, ahora requerido por Gmail/Yahoo desde 2024). NO violación legal pero afecta entregabilidad.
- **Propuesta:** Helper `akibara_get_email_headers( $type = 'transactional' )` que devuelva headers estándar incluyendo:
  - `List-Unsubscribe: <mailto:unsubscribe@akibara.cl?subject=unsubscribe>, <https://akibara.cl/unsubscribe?token=...>`
  - `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (solo marketing, no transaccional puro)
  - Para transaccional puro `Precedence: bulk` es contraproducente — dejar solo `Auto-Submitted: auto-generated`
- **Sprint:** S3

### F-19-016: BACS bank details exposed RUT empresa sin anonymization en logs
- **Severidad:** P3
- **Archivo(s):** `themes/akibara/inc/bacs-details.php:13-37`
- **Descripción:** RUT empresa 78.274.225-6 + email contacto@akibara.cl en thank-you + email post-pago BACS. Info comercial pública (no PII cliente) → OK. Pero hardcoded en código.
- **Propuesta:** Mover RUT empresa a `wp_options['akibara_business_rut']` + `akibara_business_name`. Considerar `/transparencia/` futuro
- **Sprint:** S4+

### F-19-017: Términos y Condiciones — página existe pero contenido NO confirmado en código bootstrap
- **Severidad:** P1
- **Archivo(s):** `mu-plugins/akibara-bootstrap-legal-pages.php:15-24` (solo arregla title)
- **Descripción:** Bootstrap legal pages crea politica-de-privacidad y devoluciones. Footer linkea a `/terminos-y-condiciones/` pero bootstrap NO crea esa página — solo tiene fix title que sugiere fue creada manualmente. Si editada/borrada en wp-admin, link footer apunta a 404.
- **Propuesta:**
  1. Verificar `bin/wp-ssh post list --post_type=page --name=terminos-y-condiciones` que existe
  2. Si existe: revisar contenido contra Ley 19.496 + 21.719
  3. Si NO existe: agregar al bootstrap con contenido base (identificación Akibara SpA, alcance servicios, precios, formación contrato, IP, limitación responsabilidad, ley aplicable Tribunales Santiago)
- **Esfuerzo:** M
- **Sprint:** S1

### F-19-018: Newsletter y Welcome-discount NO sincronizan opt-out cross-channel
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/newsletter.php:99-106` + `welcome-discount/module.php:267-280`
- **Descripción:** Si user desuscribe en Brevo (clic unsubscribe), Brevo lo blacklistea. Módulo Brevo principal chequea blacklist antes de re-agregar — bien. PERO:
  - Handler newsletter footer NO chequea blacklist Brevo antes de POST
  - Handler welcome-discount tampoco
  - Cross-feature: user suscribe newsletter footer → desmarca en mi-cuenta. Hook `akibara_brevo_sync_unsubscribe` sincroniza Brevo blacklist, pero welcome-discount cookie permanece → si limpia cookies, popup re-aparece
- **Propuesta:** Refactor newsletter + welcome-discount usar `Akibara\Infra\Brevo::is_blacklisted()` antes POST. Si blacklisted → mensaje "Estás dado de baja. Para re-suscribirte, contáctanos."
- **Sprint:** S2

### F-19-019: Sin aviso "Akibara puede enviarte notificaciones transaccionales" en cuenta nueva
- **Severidad:** P3
- **Archivo(s):** `themes/akibara/inc/google-auth.php:128-148`
- **Descripción:** Cuando user nuevo se crea (Google OAuth, magic-link, checkout), recibirá emails transaccionales basado en "interés legítimo" — no requiere opt-in PERO no documentado. Buena práctica informar.
- **Propuesta:** Bienvenida / first-login con toast/modal: "Te enviaremos por email confirmaciones de tus pedidos y actualizaciones de envío. Si quieres recibir también ofertas, suscríbete a nuestra Newsletter".
- **Sprint:** S3
- **Requiere mockup:** SÍ

## Cross-cutting flags

- **CC-19-A — Hostinger backups + dump SQL contienen PII en wp_akb_referrals + akibara_magic_tokens + akibara_encargos_log**: Si dumps comparten en debugging (Slack, Gist), expones IPs + emails + RUTs. Mesa-10 incluir en scope.
- **CC-19-B — Akibara_shipit shop_manager integration**: No auditado. Si integración expone órdenes a shipit, validar privacy contract. Mesa-10/15.
- **CC-19-C — Hostinger physical hosting Chile vs USA**: Confirmar geolocation server. Si USA-EU, transferencia internacional PII chilenos requiere base legal Ley 21.719.
- **CC-19-D — `_billing_rut` order meta NO encriptado en DB**: RUT es PII Ley 19.628 art. 10. Si DB se filtra → exposición masiva. Mesa-10: encryption-at-rest.
- **CC-19-E — Reportes Brevo van a Brevo (USA/EU)**: Datos clientes sincronizados (emails + atributos compra) van a Brevo. Confirmar DPA firmado con Brevo.

## Áreas que NO cubrí

- `/terminos-y-condiciones/` contenido en producción (NO pude verificar)
- Verificación en vivo del sitio Chrome MCP (cookie banner + render policies)
- Email testing guard `akibara-email-testing-guard` (asumido funciona)
- Auditoría PCI DSS (WC + MP + Webpay + Flow procesan tarjetas su infra → SAQ A scope minimal — pero F-04-007 mesa-04 marca Custom Gateway = SAQ-A-EP)
- Auditoría HIPAA (N/A — Akibara NO maneja datos salud)
- Auditoría DPA con encargados (Brevo, BlueX, MercadoPago, Flow, Cloudflare) — requiere docs legales del owner
- Plugins third-party GDPR/Ley 19.628 compliance interna (out of scope)
- CCPA / California (N/A — Akibara vende Chile)
- Cookies third-party plugins (mercadopago_*, bluex_*, litespeed_*, RankMath)
- Chrome MCP cookie inventory live (no ejecuté document.cookie real)
- Política retención logs Sentry / Hostinger
- Validación legal contenido pages bootstrappeadas con abogado chileno (recomiendo consulta especialista pre Dec 2026)
