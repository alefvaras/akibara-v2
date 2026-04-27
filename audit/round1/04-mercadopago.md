---
agent: mesa-04-mercadopago
round: 1
date: 2026-04-26
scope: Audit completo payment integration Akibara — MercadoPago v8.7.19, Flow v3.0.8, BACS, akibara-flow-hardening, akibara-reservas, installments display, finance-dashboard, RUT validation, BlueX webhook
files_examined: 24
findings_count: { P0: 3, P1: 5, P2: 6, P3: 3 }
---

## Resumen ejecutivo

1. **MP plugin v8.7.19 es current y libre de CVEs públicos** (released 2026-04-22). Sin embargo, NINGÚN endpoint webhook de pagos custom ni del plugin MP valida `x-signature` HMAC — todos validan via re-fetch a la API. Aceptable pero atacable por DoS.
2. **P0 crítico — credenciales en plain text**: `_mp_access_token_prod` (74 chars) en `wp_options` plaintext + `AKB_BREVO_API_KEY` en wp-config.php plaintext + `DB_PASSWORD` plaintext. Si `wp_options` o snapshot se filtra, ataque inmediato.
3. **MP Custom Gateway ENABLED en prod (orden 23632, 23628, 23542)** — eleva el scope PCI de SAQ-A a **SAQ-A-EP**: la página de checkout en akibara.cl renderiza el formulario de tarjeta + tokeniza client-side. Akibara entra en responsabilidad PCI sobre TLS, JS supply chain, CSP.
4. **Flow plugin v3.0.8 NO está en wordpress.org directory** — distribuido directo por Flow.cl, sin auditorías públicas, sin tracking CVE. Mu-plugin akibara-flow-hardening.php mitiga FAIL-03 (idempotencia) y FAIL-07 (rate-limit) pero confirma que Flow API v2 NO tiene firma HMAC verificable.
5. **No se detectó PAN/CVV en código, logs ni DB** — solo `card_last_four_digits` (PCI-allowed por SAQ-A). `wp_options.woocommerce_bacs_accounts` expone número de cuenta + RUT empresa plaintext.

## Findings P0

### F-04-001: MP access_token producción almacenado en plaintext
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `wp_options.option_name = '_mp_access_token_prod'` (74 chars: `APP_USR-323310714141881-110616-...-2972601096`)
- **Descripción:** Access token producción MP en plain text en wp_options. Cualquier dump SQL (backup, debugging, .private/akb-prod-dump.sql) lo expone. Con este token: crear charges, iniciar refunds, consultar todos los pagos.
- **Propuesta:** (1) Rotar token en panel MP; (2) migrar a constante en wp-config.php con secret manager Hostinger; (3) mu-plugin akibara-secrets-helper.php abstrae lectura; (4) logger redact patterns; (5) validar .gitignore cubre wp-config y .private
- **Esfuerzo:** M (4h)
- **Sprint:** S1 inmediato

### F-04-002: BACS — datos bancarios + RUT empresa hardcoded
- **Severidad:** P0 (data exposure + business secret)
- **Categoría:** SECURITY
- **Archivo(s):** `themes/akibara/inc/bacs-details.php:22` (RUT 78.274.225-6) + `wp_options.woocommerce_bacs_accounts` (cuenta 39625300 Banco de Chile)
- **Descripción:** RUT empresa hardcoded en theme + cuenta bancaria en option. Si theme se publica/filtra, datos comerciales sensibles van también. RUT + cuenta + nombre = info suficiente para suplantación.
- **Propuesta:** (1) Mover RUT a constante wp-config o admin settings; (2) inconsistencia case "Akibara Spa" vs "AKIBARA SpA" — verificar nombre legal SII; (3) CSP que restrinja modificación DOM; (4) audit trail si BACS settings modificado
- **Esfuerzo:** S (1h)
- **Sprint:** S1

### F-04-003: BlueX webhook permite ANY request si secret no configurado
- **Severidad:** P0
- **Categoría:** SECURITY
- **Archivo(s):** `themes/akibara/inc/bluex-webhook.php:33-41`
- **Descripción:** Si AKB_BLUEX_WEBHOOK_SECRET no configurado, endpoint acepta TODA request POST. Atacante puede marcar órdenes como completed/on-hold sin auth. Auto-generation solo dispara en admin_init.
- **Propuesta:** (1) `return false` si secret vacío; (2) Forzar generation en register_activation_hook; (3) Admin notice rojo si vacío; (4) Replay protection (timestamp + nonce); (5) Validar order_id realmente tiene tracking BlueX
- **Esfuerzo:** S (1h)
- **Sprint:** S1

## Findings P1

### F-04-004: MP plugin NO valida x-signature HMAC en webhooks
- **Severidad:** P1
- **Archivo(s):** `plugins/woocommerce-mercadopago/src/Notification/WebhookNotification.php:53-85`
- **Descripción:** Plugin MP v8.7.19 usa "callback verification" (re-fetch API) en lugar de validar header x-signature. Seguro contra spoofing pero atacable por DoS amplificado y race conditions duplicate processing.
- **Propuesta:** Mu-plugin akibara-mercadopago-hardening.php espejo de flow-hardening: rate-limit per IP, idempotency check transient `akb_mp_payment_<id>` TTL 24h, logging detallado
- **Esfuerzo:** M (3-4h)
- **Sprint:** S2

### F-04-005: Flow plugin v3.0.8 — librería FlowApiV2 sin timeouts ni TLS verify explícito
- **Severidad:** P1
- **Archivo(s):** `plugins/flowpaymentfl/lib/FlowApiV2.class.php:126-154`
- **Descripción:** No setea CURLOPT_CONNECTTIMEOUT/CURLOPT_TIMEOUT (request puede colgar indefinidamente bloqueando PHP thread). No setea CURLOPT_SSL_VERIFYPEER explícito. No valida response body structure.
- **Propuesta:** Mu-plugin con pre_http_request filter para inyectar timeouts a `*.flow.cl`. Reportar a Flow.cl. Plan B: documentar como riesgo conocido + monitor Sentry
- **Esfuerzo:** M (2h)
- **Sprint:** S2

### F-04-006: Flow plugin — undefined variable $service en log statements
- **Severidad:** P1
- **Archivo(s):** `plugins/flowpaymentfl/includes/class-wc-gateway-flowpayment.php:113, 185`
- **Descripción:** `$this->log('Calling the flow service: ' . $service . ...)` — `$service` nunca se define. PHP NOTICE llena wc-logs/debug.log. Con display_errors=On podría leak en respuesta webhook.
- **Propuesta:** NO modificar plugin (regla). Override mu-plugin. Reportar a Flow.cl. Verificar Hostinger PHP display_errors=Off
- **Esfuerzo:** S (30 min)
- **Sprint:** S2

### F-04-007: MP Custom Gateway ENABLED — Akibara entra en scope PCI SAQ-A-EP
- **Severidad:** P1 (compliance)
- **Categoría:** SECURITY
- **Archivo(s):** `wp_options.woocommerce_woo-mercado-pago-custom_settings: enabled = "yes"` + orders 23632/23628/23542 confirman uso
- **Descripción:** Gateway "Tarjeta de crédito o débito" (Custom mode) activo + en uso. Renderiza form de tarjeta directamente en checkout Akibara + tokeniza client-side. A diferencia de Basic (redirect SAQ-A), Custom mete a Akibara en SAQ-A-EP: responsable por TLS, JS supply chain, CSP.
- **Propuesta:** Decisión estratégica:
  - **Opción A** (recomendada para tienda en arranque): Desactivar Custom, dejar solo Basic (redirect, hosted, SAQ-A). UX cambia: 1 click "Continuar a MP" en vez de form inline.
  - **Opción B** (mantener UX): Asumir SAQ-A-EP. CSP estricto + SRI en MP SDK + auditoría JS quarterly + annual SAQ-A-EP self-assessment
- **Esfuerzo:** S (Opción A 5 min toggle) | XL (Opción B semanas)
- **Sprint:** S1 (decisión + Opción A) o S3+ (Opción B)
- **Requiere mockup:** SÍ si Opción A

### F-04-008: MP IPN Webhook NO maneja idempotencia
- **Severidad:** P1
- **Archivo(s):** `plugins/woocommerce-mercadopago/src/Notification/WebhookNotification.php:84`
- **Descripción:** Cuando MP envía webhook múltiples veces para mismo payment_id (caso normal: MP reintenta), el plugin re-procesa cada vez. Re-fetch API + processStatus actualiza order + duplicate emails al cliente.
- **Propuesta:** En el mu-plugin de F-04-004 agregar idempotency guard transient `akb_mp_proc_<id>` TTL 60s
- **Sprint:** S2

## Findings P2-P3

### F-04-009: akibara-reservas atomic_stock_check no es atómico
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-cart.php:18-49`
- **Descripción:** Lock se libera ANTES de retornar resultado. Otro proceso puede pasar el check entre release y caller act. Lock ineficaz. WP transients no son atómicos.
- **Propuesta:** wp_cache_add (atómico con Redis/Memcached) o INSERT con UNIQUE constraint o eliminar (false sense of security)
- **Sprint:** S2

### F-04-010: MP Webpay — RUT cliente NO se pasa al gateway, hardcoded '0'
- **Severidad:** P2 (UX + fraud detection)
- **Archivo(s):** `plugins/woocommerce-mercadopago/src/Transactions/TicketTransaction.php:50-55`
- **Descripción:** Akibara captura RUT cliente vía rut module pero NO lo pasa a MP. MP Anti-Fraude usa documento + IP para scoring. Defaultea a '0' que baja approve rate.
- **Propuesta:** Filter mu-plugin para inyectar RUT real desde _billing_rut + REMOTE_ADDR
- **Sprint:** S2

### F-04-011: bacs-details.php — inconsistencia case "Akibara Spa" vs "AKIBARA SpA"
- **Severidad:** P2
- **Archivo(s):** `themes/akibara/inc/bacs-details.php:42` + `wp_options.woocommerce_bacs_accounts`
- **Propuesta:** Verificar nombre legal SII + actualizar BACS settings + ELIMINAR theme override
- **Sprint:** S1

### F-04-012: akibara-reservas — emails antes de payment_complete generan emails huérfanos
- **Severidad:** P2
- **Archivo(s):** `plugins/akibara-reservas/includes/class-reserva-orders.php:75-103`
- **Descripción:** Si MP marca processing y luego cancela (timeout 3DS, fraude), customer recibió "Reserva confirmada" → confusión.
- **Propuesta:** Mover envío email a hook woocommerce_order_status_processing/payment_complete + email diferente para BACS
- **Sprint:** S2

### F-04-013: Logs custom usan error_log() sin source filtering
- **Severidad:** P2
- **Archivo(s):** mu-plugins/akibara-flow-hardening.php, themes/akibara/inc/bluex-webhook.php
- **Propuesta:** Refactor a wc_get_logger() con source para navegabilidad WP Admin
- **Sprint:** S2

### F-04-014: rut module — sanitize_text_field sin wp_unslash
- **Severidad:** P3
- **Archivo(s):** `plugins/akibara/modules/rut/module.php:79`
- **Propuesta:** sanitize_text_field( wp_unslash( $_POST['billing_rut'] ?? '' ) )
- **Sprint:** S2

### F-04-015: installments — get_total('edit') string vs float
- **Severidad:** P3
- **Archivo(s):** `plugins/akibara/modules/installments/module.php:125`
- **Propuesta:** Cambiar a (float) WC()->cart->total
- **Sprint:** S3

### F-04-016: Flow hardening MU-plugin referencias FAIL-XX no inline
- **Severidad:** P3
- **Propuesta:** Reemplazar referencias por descripciones inline + wc_get_logger source
- **Sprint:** S3

### F-04-017: NO hay test que MP esté contratado para 3 cuotas sin interés
- **Severidad:** P3
- **Categoría:** GROWTH-READY
- **Archivo(s):** `plugins/akibara/modules/installments/module.php`
- **Descripción:** Badge "3 cuotas sin interés" basado en config local, NO estado real contrato MP. Si Akibara cancela contrato, badge sigue + cliente paga con interés en redirect = riesgo SERNAC publicidad engañosa.
- **Propuesta:** (Mínimo) doc en comment. (Robusto) cron diario `/payment_methods` API check + auto-disable si no
- **Sprint:** S3

## Cross-cutting flags

1. **F-04-CC-001 (mesa-10):** wp-config.php DB_PASSWORD débil (8 chars) + AKB_BREVO_API_KEY plaintext. Migrar a secret manager.
2. **F-04-CC-002 (mesa-10):** Confirmar `.private/akb-prod-dump.sql` NUNCA en git. Tiene `_mp_access_token_prod`.
3. **F-04-CC-003 (mesa-09):** Validar email path post-cleanup CLEAN-003 (NO ejecutar — ver F-09-001/F-10-003).
4. **F-04-CC-004 (mesa-13/branding):** Opción A F-04-007 cambia UX checkout — REQUIERE MOCKUP.
5. **F-04-CC-005 (mesa-15):** Considerar gateway custom directo Flow API v3 (HMAC) en lugar de plugin community.

## Hipótesis para Iter 2

1. ¿Atacante puede inyectar webhook BlueX falso ANTES de admin_init que genera secret?
2. ¿checkout-pudo.php $service race-conditioned para forzar precio menor envío?
3. ¿finance-dashboard cache transient envenenado por POST malicioso?
4. ¿woocommerce_bacs_accounts alterable por admin secundario sin audit trail?
5. ¿_mp_access_token_prod accesible vía REST API /wp-json/wp/v2/settings?

## Áreas que NO cubrí

- MP plugin internals (third-party): solo verifiqué firma HMAC ausente. NO fuzzing body, bypass auth, Action Scheduler integration
- Flow plugin Gutenberg checkout block: no leído
- 3DS flow real: requiere transacción sandbox autorizada
- Refunds parciales: orden 23656 cancelled pre-pago, NO refund test
- PSE/Pix/otros LATAM: solo Chile CLP cubierto
- Subscription/recurring: out of scope (manga es one-time)
- Apple Pay/Google Pay via MP Bricks: no detectado
