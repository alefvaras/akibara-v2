---
agent: code-reviewer
round: 1
date: 2026-04-26
scope: Tech-debt sweep código custom Akibara — modules akibara/, akibara-reservas, akibara-whatsapp, theme inc/, mu-plugins akibara-*
files_examined: 90
findings_count: { P0: 2, P1: 4, P2: 11, P3: 8 }
---

## Resumen ejecutivo

- **74 MB de dev tooling deployado en prod sin protección** (`vendor/` 55MB + `coverage/` 19MB en `wp-content/plugins/akibara/`). Coverage HTML expone source code mapeado por línea — disclosure attack surface + data inflate. F-02-001 P0.
- **CLEAN-003 + CLEAN-004 están MISCLASSIFIED en seeds del usuario.** El mu-plugin `akibara-brevo-smtp.php` NO es legacy — es el ÚNICO interceptor activo de `wp_mail` que rutea email transaccional vía Brevo (72 emails enviados). Hostinger bloquea PHP mail(), removerlo rompe order confirmations + password resets. Ver F-02-002 P0.
- **~5,500 LOC de "growth-ready" con 0–1 usuarios reales.** referrals (1659 LOC, 0 completed), back-in-stock (588 LOC, 0 subs), series-notify (639 LOC, 0 subs), welcome-discount (~2k LOC, 1 sub, OFF por default). Sprint S1: validar activarlos antes de seguir construyendo encima. F-02-007 P1.
- **MercadoLibre 4250 LOC con 1 listing activo.** Plenamente armado (publisher, sync, webhook, admin) pero solo 1 producto realmente sincronizado. Posible OVER-ENGINEERED si el dueño no planea re-activar pronto. F-02-008 P2.
- **Code en akibara-reservas con race conditions disfrazadas de atomic.** `Akibara_Reserva_Cart::atomic_stock_check()` usa transient como mutex (no es atómico en WP, races bajo carga). Cron `check_dates` carga objetos de orden + items en loop sin paginar mas allá de los 100. Fragile bajo escala. F-02-009 P1.

## Findings

### F-02-001: 74 MB de dev tooling deployado en prod (vendor/ + coverage/) sin .htaccess
- **Severidad:** P0
- **Categoría:** [DEAD-CODE] [SECURITY] [LEGACY]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/vendor/` (55M), `server-snapshot/public_html/wp-content/plugins/akibara/coverage/html/` (19M), `server-snapshot/public_html/wp-content/plugins/akibara/.phpunit.cache/` (900K), `server-snapshot/public_html/wp-content/plugins/akibara/tests/` (100K), plus `composer.json`, `composer.lock`, `phpcs.xml`, `phpstan.neon`, `phpunit.xml.dist`, `.phpcs-baseline`, `.pcp-baseline`
- **Descripción:** El plugin `akibara` deployó toda la infraestructura de desarrollo a producción: composer dev-deps (phpunit, phpstan, phpcs, sebastian/* 17 packages), HTML coverage report con SOURCE CODE COMPLETO mapeado línea por línea, phpunit cache, tests/. NO hay `.htaccess` que bloquee acceso público a estos paths.
- **Evidencia:**
  - `composer.json` declara TODO bajo `require-dev`. No hay `require:` runtime → vendor/ existe SOLO para CI/dev tooling.
  - `coverage/html/index.html` accesible públicamente en `https://akibara.cl/wp-content/plugins/akibara/coverage/html/index.html` — expone qué partes del código tienen tests, cobertura por archivo, source code de cada función.
  - `coverage/html/src/Infra/Brevo.php.html` muestra incluso la lógica de la API key de Brevo línea por línea con highlighting.
  - `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/phpcs` son CLI tools innecesarios en runtime.
- **Propuesta:** (1) Excluir de deploy via deploy.sh / .gitignore prod (más limpio) o `composer install --no-dev` en post-deploy hook. (2) Si NO se puede excluir del deploy (constraint de hosting), agregar `wp-content/plugins/akibara/.htaccess` con `Deny from all` para vendor/, coverage/, tests/, .phpunit.cache/, *.xml, *.neon, .*-baseline. (3) Verificar mismas paths para `akibara-reservas/` (tiene bin/, tests/) — más chico pero mismo patrón.
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Robustez ganada:** Reduce attack surface (information disclosure), reduce disk footprint 74MB, deploy 60% más rápido si se excluye del rsync.
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (autoload.php real está en `includes/autoload.php`, NO depende de vendor/composer/autoload.php)

### F-02-002: CLEAN-003 + CLEAN-004 misclassificados — `akibara-brevo-smtp.php` NO es legacy, es interceptor wp_mail activo
- **Severidad:** P0
- **Categoría:** [SECURITY-RECLASSIFY-SEED]
- **Archivo(s):** `server-snapshot/public_html/wp-content/mu-plugins/akibara-brevo-smtp.php:67`, `server-snapshot/public_html/wp-config.php` constante `AKB_BREVO_API_KEY`
- **Descripción:** Las CLEAN seeds CLEAN-003 (delete `akibara-brevo-smtp.php`) y CLEAN-004 (delete constante `AKB_BREVO_API_KEY`) están MAL clasificadas. El mu-plugin agrega filter `pre_wp_mail` (línea 67) que intercepta TODO `wp_mail()` y lo enruta a Brevo Transactional API. Es el único path de email transaccional. La opción 'akibara_brevo_mail_sent_count' en wp_options reporta **72 emails enviados** ya. El comment línea 73: "NO fallback a PHP mail() porque Hostinger lo tiene bloqueado". Removerlo rompe: order confirmations, password resets, customer notifications, mensajes de admin.
- **Evidencia:**
  ```
  bin/mysql-prod -e "SELECT option_value FROM wp_options WHERE option_name = 'akibara_brevo_mail_sent_count'"
  → 72
  ```
  - El usuario probablemente confundió "akibara-brevo-smtp.php" con un plugin third-party tipo "WP Mail SMTP" (que NO usan). Pero el archivo es un mu-plugin custom.
  - La constante `AKB_BREVO_API_KEY` está LIVE en `wp-config.php:1` (visible en `grep -E "AKB|AKIBARA" wp-config.php`). Removerla cae al fallback de option DB que tiene la misma key — funcionaría, pero ADR-001 (mencionado en línea 27 del mu-plugin) explícitamente dice que la constante es la fuente preferida porque "sobrevive rotaciones sin sync con DB" (después del leak de 2026-04-19).
- **Propuesta:** (1) Mesa-01/lead RECONSIDERAR CLEAN-003 + CLEAN-004 con el usuario en sesión. (2) Si el usuario INSISTE en remover, plan migración: primero copiar lógica de pre_wp_mail interceptor a un plugin regular (akibara/ includes/), luego deprecar mu-plugin. (3) NO ejecutar CLEAN-003/004 sin esta clarificación — riesgo de tirar email delivery en prod.
- **Esfuerzo:** S (clarificación) | M (migración si insiste)
- **Sprint sugerido:** S1 (clarificación inmediata, ANTES de cualquier cleanup)
- **Robustez ganada:** Evita romper email transaccional en prod (es la única vía de salida).
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa SIN clarificar:** alto

### F-02-003: 4 backdoors admin DELETADOS deja `wp_akb_referrals` row 4 (`nightmarekinggrimm26`) huérfano
- **Severidad:** P1
- **Categoría:** [SECURITY] [LEGACY]
- **Archivo(s):** N/A (DB row), referencia: `wp_akb_referrals` row id=4
- **Descripción:** SEC-P0-001 borra los 4 admins backdoors. El user 18 (`nightmarekinggrimm26@gmail.com`) generó código de referido (REF-RODRIGOM-9B4L) registrado en `wp_akb_referrals.id=4` el 2026-04-22. F-PRE-010 marca esta cuenta como sospechosa pero "info hasta confirmar". Si Mesa-10 confirma comprometida, el row referido queda huérfano apuntando a email/usuario eliminado.
- **Evidencia:** `wp_akb_referrals` solo tiene 4 rows, todos pending, todos generados automáticamente por user registration sin completion.
- **Propuesta:** Coordinar con Mesa-10: si user 18 se confirma malicioso, DELETE de wp_akb_referrals row id=4 + clean. Si el row tiene cookies activas en otros browsers, revocar el código (UPDATE status='expired'). Sprint S1.
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Robustez ganada:** consistencia data; no quedan códigos huérfanos cuando se borren cuentas
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-004: Migración YITH dead code en `class-reserva-migration.php` — DB tiene 0 metadatos `_ywpo*`
- **Severidad:** P2
- **Categoría:** [DEAD-CODE] [LEGACY]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-migration.php:75-186`
- **Descripción:** La función `Akibara_Reserva_Migration::run()` migra productos+orders desde YITH Pre-Order. La query base `SELECT post_id FROM wp_postmeta WHERE meta_key='_ywpo_preorder' AND meta_value='yes'` retorna 0 rows en prod (verificado: `SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '_ywpo%'` → 0). YITH ya fue migrado y no quedan datos. La función `maybe_unify_types()` (líneas 17-69) sí es útil (corre cleanup `pedido_especial` → `preventa`) y ya marcó `UNIFY_FLAG`, pero la `run()` legacy queda cargada en cada request.
- **Evidencia:** `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '_ywpo%'"` → 0
- **Propuesta:** Remover `Akibara_Reserva_Migration::run()` completa (líneas 75-186). Mantener `maybe_unify_types()` que es la única que aún corre. Reduce ~110 LOC y la confusión "¿hay que correr esta migración alguna vez más?".
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Robustez ganada:** Menos código a mantener; signal claro de qué migrations están activas
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (función NO se invoca desde ningún hook activo, solo desde admin botón "Run YITH migration" que ya no aplica)

### F-02-005: `enqueue.php.bak-2026-04-25-pre-fix` en theme inc/ — orphan backup file
- **Severidad:** P3
- **Categoría:** [DEAD-CODE] [LEGACY]
- **Archivo(s):** `server-snapshot/public_html/wp-content/themes/akibara/inc/enqueue.php.bak-2026-04-25-pre-fix` (12.4 KB)
- **Descripción:** Backup file de pre-fix dejado en el theme inc/. WP NO carga `.bak-*` files via require, pero queda como ruido + ocupa espacio. WP no protege estos paths con `.htaccess` por defecto en themes — accesible vía URL si alguien adivina el nombre (low risk porque es solo PHP source que hace enqueue de assets, sin secretos).
- **Evidencia:** `find themes/akibara/inc/ -name "*.bak*"` → 1 archivo
- **Propuesta:** `rm` con doble OK del usuario. Si quiere conservar, mover a `docs/backups/2026-04-25-enqueue-pre-fix.php` fuera del path web.
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Robustez ganada:** Eliminación de noise; cleaner dir listing
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** ninguno

### F-02-006: Leftover plugin folders en uploads/ — revslider 8.4MB, wpml/twig PHP, otros
- **Severidad:** P2
- **Categoría:** [DEAD-CODE] [SECURITY-soft] [LEGACY]
- **Archivo(s):** 
  - `wp-content/uploads/revslider/` (8.4 MB jpg/mp4)
  - `wp-content/uploads/elementor/thumbs/` (140K webp)
  - `wp-content/uploads/wpseo-redirects/` (8K)
  - `wp-content/uploads/mailpoet/` (4K)
  - `wp-content/uploads/mailchimp-for-wp/` (4K)
  - `wp-content/uploads/pum/` (84K)
  - `wp-content/uploads/annasta-filters/` (8K)
  - `wp-content/uploads/smush/` (184K incluyendo 4 .php files: index.php, integrations-log.php, resize-log.php, smush-log.php)
  - `wp-content/uploads/cache/wpml/twig/` (6 compiled PHP twig templates de WPML)
- **Descripción:** Plugins removidos de prod dejaron sus uploads. Más grave: `cache/wpml/twig/*.php` son archivos PHP compilados ejecutables. Si el WPML namespace queda registrado por algún include leftover, podrían ejecutar. `smush/*.php` también sospechosos. Ya catalogados en F-PRE-009 — confirmo y expando.
- **Evidencia:** 
  ```
  find wp-content/uploads/ -name "*.php" → 11 files (revslider/wpml/smush/mailpoet)
  find wp-content/uploads/cache/wpml/ → 6 PHP files compilados
  ```
- **Propuesta:** Mesa-10 (security) confirma ningún PHP es backdoor. Después: rm -rf de las carpetas. ESPECIAL: validar que el `.htaccess` raíz de uploads no permite ejecutar PHP — si lo permite, bloquearlo PRIMERO (`<Files *.php> Deny from all </Files>`).
- **Esfuerzo:** S
- **Sprint sugerido:** S1
- **Robustez ganada:** Atac surface −11 PHP files; ~9 MB libres
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (plugins inactivos, archivos no son leídos por código activo)

### F-02-007: ~5,500 LOC de "growth modules" con 0–1 usuarios reales
- **Severidad:** P1
- **Categoría:** [GROWTH-READY] [OVER-ENGINEERED?]
- **Archivo(s):** 
  - `modules/referrals/module.php:1659` LOC + `wp_akb_referrals` table
  - `modules/back-in-stock/module.php:588` LOC + `wp_akb_bis_subs` table
  - `modules/series-notify/module.php:639` LOC + `wp_akb_series_subs` table
  - `modules/welcome-discount/*.php:~2090` LOC (606+329+307+243+123+122+114+93+73+72) + `wp_akb_wd_log` + `wp_akb_wd_subscriptions` tables, OFF por default
  - `modules/cart-abandoned/module.php:539` LOC + `wp_akb_abandoned_carts` (option, no table)
- **Descripción:** Cinco módulos completos con DB tables, cron jobs, admin UI, AJAX endpoints — cada uno con infraestructura completa pero usage real:
  | Módulo | LOC | Tabla rows en prod |
  |---|---|---|
  | referrals | 1659 | 4 pending, 0 completed |
  | back-in-stock | 588 | 0 |
  | series-notify | 639 | 0 |
  | welcome-discount | ~2090 | 1 subscription, FEATURE FLAG OFF |
  | cart-abandoned | 539 | 0 (cubierto por Brevo upstream — CLEAN-002) |
  
  Total: ~5,515 LOC + 5 tablas DB + 5 cron jobs cargados cada request. Para una tienda con 3 clientes reales.
  
- **Evidencia:**
  ```
  bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_referrals WHERE status='completed'" → 0
  bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_bis_subs" → 0
  bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_series_subs" → 0
  bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_wd_subscriptions" → 1
  bin/mysql-prod -e "SELECT option_value FROM wp_options WHERE option_name='akibara_wd_enabled'" → 1 (pero el flag interno del módulo lo apaga)
  ```
- **Propuesta:** Aplicar memoria `feedback_no_over_engineering.md` y `project_audit_right_sizing.md`. Mesa-23 PM debe DECIDIR (no developer):
  - **Opción A — mantener TODOS:** validar setup en S1-S2 (activate features, validate Brevo template IDs, smoke test) — costo de validación 3-5h por módulo. Mantenimiento ongoing.
  - **Opción B — mantener welcome-discount + back-in-stock + series-notify** (los más alineados con catálogo manga). Deprecar referrals (1659 LOC para 0 completions). Mover a `modules-disabled/` en S2.
  - **Opción C — mantener SOLO welcome-discount + back-in-stock**. Deprecar el resto. Más alineado con scope MVP.
- **Esfuerzo:** L (decision + cleanup)
- **Sprint sugerido:** S2 (decisión PM) → S3 (cleanup según opción elegida)
- **Robustez ganada:** Foundation alineada con escala real. Menos cron jobs, menos DB queries, menos código a mantener para single dev sin red.
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** medio (asegurar activación de los que se mantengan, comunicar a clientes existentes si desactivás algo)

### F-02-008: MercadoLibre 4250 LOC para 1 listing activo en prod
- **Severidad:** P2
- **Categoría:** [OVER-ENGINEERED] [GROWTH-READY]
- **Archivo(s):** `modules/mercadolibre/` — `module.php` 36 LOC bootstrap + `includes/` 1958 LOC (api, db, orders, pricing, publisher, sync, webhook) + `admin/` 1492 LOC (settings, products-meta) + tabla `wp_akb_ml_items`
- **Descripción:** Sistema completo: OAuth flow, webhook handler, sync engine, publisher (764 LOC solo en `class-ml-publisher.php`), pricing strategies, admin UI 833 LOC. La tabla `wp_akb_ml_items` tiene 4 rows, solo 1 con `ml_status='active'`. El catalog acceso al `https://articulo.mercadolibre.cl/MLC-3874440690-...` confirma listing activo (One Punch Man 3 Ivrea España $15,465). Pero los otros 3 rows (product_id 21761, 15326) tienen `ml_item_id` vacío — sync iniciado, nunca completado.
- **Evidencia:**
  ```
  bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_ml_items" → 4
  bin/mysql-prod -e "SELECT * FROM wp_akb_ml_items WHERE ml_status='active'" → 1 row (One Punch Man 3)
  ```
  Memoria `feedback_no_over_engineering.md` cita: "no quiero sobreingeniería analiza que se puede mejorar en rendimiento" sobre ML específicamente.
- **Propuesta:** Mesa-23 PM evalúa con dueño:
  - **Opción A — mantener:** publicar +20 productos en S2-S3 para validar la ROI. Si después de eso no hay ventas via ML, deprecar.
  - **Opción B — pausar:** mover a `modules-disabled/`, mantener tabla DB, no cargar el código. Re-habilitar cuando dueño quiera retomar. Ahorra 4250 LOC en cargo de cada request admin (settings.php tiene admin_init hooks).
  - **Opción C — deprecar:** delete módulo + tabla. El listing One Punch Man se mantiene en MercadoLibre pero ya no se sincroniza desde WP. Si dueño retoma, lo crea desde cero.
- **Esfuerzo:** S (decisión) | M (deprecation si elige B/C)
- **Sprint sugerido:** S3
- **Robustez ganada:** Reduce código mantenido sin uso real
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa B/C:** bajo (listing en ML no se rompe, solo no se sincroniza); ALTO si elige C y luego cambia opinión

### F-02-009: `Akibara_Reserva_Cart::atomic_stock_check()` — transient mutex no es atómico en WP
- **Severidad:** P1
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-cart.php:18-49`
- **Descripción:** La función promete "atomic stock check" pero usa `get_transient($lock_key)` + `set_transient($lock_key)` como mutex. Esto NO es atómico:
  1. Request A llega → `get_transient('akb_stock_lock_123')` → null
  2. Request B llega simultáneo → `get_transient` → null (la set de A todavía no se persistió)
  3. Ambos hacen `set_transient` → ambos pasan al `is_in_stock()` check
  4. Race window real: 100ms+ con object cache LiteSpeed.
  
  Además: `usleep(100000)` (100ms) acumulado hasta 3s → bloquea PHP-FPM workers innecesariamente. Si 10 clientes intentan reservar el último ejemplar al mismo tiempo, 9 esperan 3s + 1 race ganador = malísima UX para preventas calientes. Y todavía puede oversold.
  
- **Evidencia:** Código línea 23-30:
  ```php
  while ( get_transient( $lock_key ) && $waited < $max_wait ) {
      usleep( 100000 ); // 100ms
      $waited += 0.1;
  }
  set_transient( $lock_key, time(), 5 );
  ```
- **Propuesta:** Para evitar oversold real en preventas (caso usuario flagged): usar `wpdb->query("SELECT ... FOR UPDATE")` dentro de transaction (`START TRANSACTION` + `COMMIT`/`ROLLBACK`) sobre `wp_postmeta` o `wc_product_meta_lookup`. Esto es DB-atómico real. Si no se quiere transaction (gateway timing), usar `wpdb->insert` con UNIQUE INDEX como mutex (insert fail = locked, insert success = lock acquired). Ver patrones WC en `WC_AJAX::add_to_cart()` (no usa locking custom — confía en stock decrement atómico de `wc_update_product_stock`).
- **Esfuerzo:** M
- **Sprint sugerido:** S2 (después de validar volumen real preventas — para 0 órdenes simultáneas hoy es solo theoretical risk)
- **Robustez ganada:** Race condition real eliminada en preventas hot
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** medio (cambiar locking strategy puede tener bug; testear con rapid-fire add-to-cart en local)

### F-02-010: `wp_akb_unify_backup_202604` table — backup data sin política de retention
- **Severidad:** P3
- **Categoría:** [LEGACY]
- **Archivo(s):** Tabla DB `wp_akb_unify_backup_202604` (5 rows)
- **Descripción:** Tabla creada como backup pre-migración del unify ENCARGO→PREVENTA (ver `class-reserva-migration.php`). Tiene 5 rows. No hay código que la lea ni la limpie. Queda como dead schema.
- **Evidencia:** `bin/mysql-prod -e "SELECT COUNT(*) FROM wp_akb_unify_backup_202604"` → 5
- **Propuesta:** S3: si la migración (UNIFY_FLAG) tiene >30 días sin issues, `DROP TABLE wp_akb_unify_backup_202604`. Antes: dump CSV a `docs/backups/unify-backup-202604.csv` por si acaso.
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Robustez ganada:** DB schema cleanup
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** ninguno (5 rows backup, nadie la consulta)

### F-02-011: `Akibara_Reserva_Cron::check_dates()` — paginación hardcoded a 100, no scaleable
- **Severidad:** P2
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-cron.php:38`
- **Descripción:** El cron cada 15 min llama `Akibara_Reserva_Orders::get_pending_orders(100)`. Si Akibara crece a tener >100 reservas pending simultáneas (escenario probable post-Cyber-Day), el cron solo procesará las primeras 100 y las fechas pasadas de las siguientes 100+ NO disparan el flujo `complete_item()` automatic. Cliente con preventa cuya fecha pasó pero queda fuera de los 100 → email "Tu reserva está lista" nunca se envía.
- **Evidencia:** `class-reserva-cron.php:38` `$order_ids = Akibara_Reserva_Orders::get_pending_orders( 100 );` — hard-coded literal.
- **Propuesta:** (1) Cambiar a paginación: while loop con offset hasta agotar resultados. (2) O: query SQL directa con `WHERE _akb_item_fecha < NOW()` para procesar SOLO los que necesitan acción. (3) Sentry alert si hit el límite de 100 (logger info → Mesa-10 dashboard).
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Robustez ganada:** Cron escalable; no se quedan reservas atrás
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-012: `Akibara_Reserva_Orders::on_fecha_cambiada()` — paginación dura 500 + N+1 queries inventarios
- **Severidad:** P2
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-orders.php:256-298`
- **Descripción:** Cuando admin cambia fecha de un producto en preventa, dispara `on_fecha_cambiada` que pulls 500 órdenes pendientes y hace `wc_get_order()` por cada una + `$order->get_items()` por cada una. Para 500 órdenes con 3 items prom = 1500 product loads. WC sin cache = 1500 SELECT queries individuales. Bajo Cyber-Day load esto puede tardar 30-60s y timeout PHP-FPM. Y solo se buscan items con `$item_product_id === $product_id` — el filtro debería estar en el SQL inicial, no en PHP loop.
- **Evidencia:** Código líneas 257-258 + foreach loop sin batch warming.
- **Propuesta:** (1) SQL directo para encontrar order_ids que tienen item con product_id específico Y estado esperando: `JOIN wc_orders + woocommerce_order_items + woocommerce_order_itemmeta WHERE meta_key='_product_id' AND meta_value=N`. Esto reduce a ~1 query inicial. (2) Batch warming de WC objects con `_prime_post_caches`.
- **Esfuerzo:** M
- **Sprint sugerido:** S3
- **Robustez ganada:** Performance + escala bien con catálogo creciendo
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** medio (cambio de SQL puede tener bug — testear con varias fechas seguidas)

### F-02-013: `Akibara_Brevo` admin — full-table SCANs por meta key sin índices
- **Severidad:** P2
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/brevo/module.php:366-371`
- **Descripción:** Las queries en el dashboard admin de Brevo:
  ```sql
  SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_akibara_brevo_synced' AND meta_value = 'yes'
  SELECT COUNT(*) FROM wp_posts WHERE post_type='shop_order' AND post_status IN (...)
  ```
  hacen full-table scan en `wp_postmeta` (no hay índice por (meta_key, meta_value)). Con catálogo creciendo 1300 productos + N órdenes, esta query puede tardar 5-10s en cada admin page load. Mismo path para HPOS (`wc_orders_meta`).
- **Evidencia:** `module.php:366-371` queries sin filtro por order_id.
- **Propuesta:** (1) Cache resultado de COUNT en transient 5 min con auto-invalidación on order status change. (2) O: agregar índice MySQL: `ALTER TABLE wp_postmeta ADD INDEX akibara_brevo_synced (meta_key, meta_value)` (cuidado, postmeta es tabla muy contestada — solo si KPI confirmado).
- **Esfuerzo:** S (cache) | M (index)
- **Sprint sugerido:** S3 (esperar feedback real de admin lentitud)
- **Robustez ganada:** Admin pages snappy bajo crecimiento
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (cache TTL corto)

### F-02-014: `finance-dashboard` 1453 LOC con cache para "10K+ órdenes" cuando hay 3 clientes
- **Severidad:** P2
- **Categoría:** [OVER-ENGINEERED]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/finance-dashboard/module.php`
- **Descripción:** Comment línea 73: "Finance Dashboard ejecuta 10+ queries sobre wc_orders. En sitios con 10K+ órdenes el admin tardaba 3-8s. Con transient de 5min el P95 cae a <200ms en hit." → Akibara tiene 3 órdenes reales totales. Toda la complejidad de cache layer + invalidation hooks + bypass param `?nocache=1` + filtro `akb_finance_bypass_cache` para una situación que NO existe en esta tienda. F-PRE-006 ya marcó refactor — confirmo y agrego: el cache layer en sí mismo es el over-engineering, no las features.
- **Evidencia:** `module.php:69-83` cache layer con bypass parameter + filter.
- **Propuesta:** En el refactor F-PRE-006 propuesto por usuario (Opción B manga-specific con top series + editoriales + preorder split): NO replicar cache layer. Render inline cada vez. Si admin se nota lento, agregar cache después de medirlo. Memoria `feedback_no_over_engineering.md`: "No cache custom sobre LiteSpeed".
- **Esfuerzo:** N/A (forma parte del refactor F-PRE-006)
- **Sprint sugerido:** S3
- **Robustez ganada:** Menos código a mantener
- **Requiere mockup:** SÍ (mesa-13 mockup del nuevo dashboard manga-specific)
- **Riesgo de regresión si se actúa:** N/A

### F-02-015: `marketing-campaigns/module.php` 1473 LOC monolithic — overlap with Brevo Workflows
- **Severidad:** P2
- **Categoría:** [OVER-ENGINEERED?] [REDUNDANT-vs-Brevo]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/marketing-campaigns/module.php` 1473 LOC + `tracking.php` 327 LOC + `welcome-series.php` 432 LOC = 2232 total
- **Descripción:** Sistema completo de campañas: scheduler con AS, segmentación (10 segments definidos), incentive box rendering (4 types), tracking pixels, welcome-series (3 emails). Memoria `project_brevo_upstream_capabilities.md` confirma que Brevo cubre nativo: workflows, drip campaigns, A/B testing, segmentation. F-PRE-005 ya flagged overlap entre `descuentos`, `welcome-discount`, `marketing-campaigns`.
- **Evidencia:** 
  - `welcome-series.php:32-34` define hooks `AKB_WS_HOOK_SEND` con AS — duplicado de Brevo Workflows.
  - `marketing-campaigns/module.php` segments → Brevo segmentation lo cubre nativo.
  - `tracking.php` 327 LOC → Brevo tracker JS lo hace nativo.
- **Propuesta:** Mesa-23 PM evalúa migrar `welcome-series` a Brevo Workflow templated (3 emails con delay). Después: deprecar `welcome-series.php` (432 LOC) + simplificar `marketing-campaigns/module.php`. Si user quiere mantener admin UI custom → solo el render de campañas + push a Brevo via API. Ahorra ~1000+ LOC.
- **Esfuerzo:** L
- **Sprint sugerido:** S3-S4
- **Robustez ganada:** Single source of truth (Brevo) para email automation
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** medio (re-test welcome flow)

### F-02-016: `inventory/module.php` 744 LOC — feature flag check inconsistent
- **Severidad:** P2
- **Categoría:** [REDUNDANT]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/inventory/module.php`
- **Descripción:** El módulo inventory NO tiene `akb_is_module_enabled()` guard al inicio (todos los demás módulos sí). Solo descuentos/popup/cart-abandoned/back-in-stock/review-request usan el guard (5 de 28 módulos). El registry en `akibara.php` SÍ pasa `enabled` al `Akibara_Module_Registry::register()`, pero los módulos sin guard interno se cargan igual aunque el registry decida `enabled => false` (la registry no aborta el require basado en enabled — solo lo guarda como metadata).
- **Evidencia:** `grep -l "akb_is_module_enabled" modules/*/module.php` → solo 5 módulos. Otros como inventory, ga4, finance-dashboard, mercadolibre no lo verifican.
- **Propuesta:** O bien (a) agregar guard `akb_is_module_enabled()` consistente en TODOS los módulos al inicio (~28 archivos modificados, 1 línea cada uno), O (b) hacer que `Akibara_Module_Registry::boot()` SKIP el require si `enabled === false`. Opción B es más DRY. Ya está parcialmente — verificar si el registry skipea o solo loggea.
- **Esfuerzo:** S (opt B)
- **Sprint sugerido:** S2
- **Robustez ganada:** Feature flags realmente desactivan módulos cuando se necesite kill switch
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-017: `welcome-discount` carga `class-wd-db.php` siempre + tablas DB siempre, módulo OFF
- **Severidad:** P2
- **Categoría:** [DEAD-CODE-conditional]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/welcome-discount/module.php:34-65`
- **Descripción:** El módulo welcome-discount tiene en línea 63: `if ( ! get_option( 'akibara_wd_enabled', 0 ) ) { return; }` — feature flag OFF por default. PERO líneas 37-53 cargan `class-wd-db.php`, `class-wd-settings.php`, `class-wd-log.php` ANTES del guard, ejecutan `dbDelta` para crear tablas (`wp_akb_wd_log`, `wp_akb_wd_subscriptions`) y montan admin UI siempre. Comment justifica: "(a) DB tables are created on first deploy (b) Settings and metrics are accessible from admin even when module is off". OK pero genera 2 tablas vacías + 4 hooks admin siempre activos para una feature OFF.
- **Evidencia:** Líneas 37, 44, 56, 63 muestran el patrón: load infra → dbDelta → admin UI → feature flag check.
- **Propuesta:** Si la decisión es mantener la feature OFF mucho tiempo (probable, dado que solo tiene 1 sub), defer la creación de tablas hasta el primer enable. Pattern: guard la dbDelta también. Ahorra 2 tablas vacías + memory.
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Robustez ganada:** Schema DB minimal hasta que se necesite
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo (dbDelta es idempotente)

### F-02-018: `cart-abandoned` usa `flock()` + `sys_get_temp_dir()` para race condition guard
- **Severidad:** P3
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/cart-abandoned/module.php:165-194`
- **Descripción:** El locking del index update usa `fopen(sys_get_temp_dir() + '/akb_ca_index.lock', 'c')` + `flock(LOCK_EX)`. En LiteSpeed con `open_basedir` restrictivo, sys_get_temp_dir() puede ser `/tmp` que no esté en el path permitido. Y en hosting compartido el lock file persiste entre requests pero no entre PHP processes en distintos servers (si Hostinger escala horizontalmente algún día). Funciona hoy pero fragile bajo cambio infrastructura.
- **Evidencia:** Líneas 166-194.
- **Propuesta:** YAGNI — esta función es CLEAN-002 candidate (going away cuando se confirme Brevo upstream cubre). Lower priority. Si se mantiene, migrar a transient con random delay + retry pattern como el resto del codebase.
- **Esfuerzo:** S | N/A si CLEAN-002 borra
- **Sprint sugerido:** S1 (si CLEAN-002 ejecuta) | S3 (si se mantiene)
- **Robustez ganada:** Portabilidad
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-019: `Akibara_Reserva_Stock::maybe_enable_auto()` — cualquier producto OOS se vuelve preventa
- **Severidad:** P1
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-stock.php:37-62`
- **Descripción:** Si el flag `akb_reservas_auto_oos_enabled` está ON (no verificado en prod, pero código lo checkea), CUALQUIER producto que pase a OOS se vuelve preventa automáticamente sin fecha (`fecha_modo => 'sin_fecha'`). El cliente verá "Reservar ahora" sin saber cuándo llegará. Los productos test 24261/24262/24263 verifican que esto FUNCIONA en prod hoy. Riesgo: cliente reserva, paga, jamás llega el manga (porque era OOS por discontinuación, no por restock pending).
- **Evidencia:** Líneas 37-57. Línea 53 explícito: `'fecha_modo' => 'sin_fecha'` por default. Memoria `feedback_robust_default.md`: "edge cases: out-of-stock + concurrent purchase + email bounce + payment timeout cubiertos" → este NO está cubierto.
- **Propuesta:** (1) Por default, NO auto-enable preventa al pasar OOS. Requerir admin explicit flag para que cada producto pueda auto-preventar. (2) Si auto-enable se mantiene, exigir fecha_modo `estimada` con 4-6 weeks default (no `sin_fecha`). (3) Notificar admin via email al activar auto-preventa para que confirme manual. (4) Validar en checkout que el cliente vea claramente la incertidumbre cuando es `sin_fecha`.
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Robustez ganada:** Evita commitments de fulfillment imposibles
- **Requiere mockup:** SÍ (mesa-13 — copy en cart/checkout para preventa "fecha por confirmar" debe ser claro y opt-in)
- **Riesgo de regresión si se actúa:** bajo

### F-02-020: `Akibara_Reserva_Orders::on_payment_complete` — `_akb_reserva_emails_sent` no idempotente cross-gateway
- **Severidad:** P2
- **Categoría:** [FRAGILE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara-reservas/includes/class-reserva-orders.php:108-118`
- **Descripción:** El handler `on_payment_complete` checkea `if ( $order->get_meta( '_akb_reserva_emails_sent' ) ) return;`. PERO el meta lo seta en `on_order_created` (línea 95) si el status era ya paid. Entre `on_order_created` y `on_payment_complete` hay una race window: si MercadoPago dispara payment_complete después que woocommerce_checkout_order_created se ejecutó pero ANTES que `$order->save()` persistió el meta, ambos disparan email. Brevo Free tier tiene 300 emails/día compartidos (F-PRE-007) — duplicados son costosos. Además, si payment falla y luego se completa, el meta marcado como 'yes' antes en `on_order_created` impide enviar el email post-payment legitimo.
- **Evidencia:** Líneas 88-103 vs 108-118. La protección es vía meta DB, no transactional.
- **Propuesta:** Idempotencia más fuerte: usar `add_meta_data` con `unique=true` flag del WP API (atómico DB-level). O setear el meta DESPUÉS del envío exitoso del email (no antes) y guardar timestamp para deduplicar window 5min.
- **Esfuerzo:** S
- **Sprint sugerido:** S2
- **Robustez ganada:** Email duplicates eliminados
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-021: `series-autofill/class-migration.php` 241 LOC — migration histórica probably done
- **Severidad:** P3
- **Categoría:** [DEAD-CODE-candidate]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/series-autofill/class-migration.php`
- **Descripción:** Sistema de migración histórica via Action Scheduler para auto-fill `_akibara_serie` en backlog products. Si los 1371 productos ya fueron procesados (probable, dado que home muestra 1368 con editoriales), esta lógica solo corre una vez en el futuro si entran 50+ productos nuevos.
- **Evidencia:** N/A (sin verificar wp_options de migration progress)
- **Propuesta:** S3 verificar `bin/wp-ssh option get akb_series_autofill_migration_progress`. Si todos los productos están procesados, considerar mover Migration class a `modules/series-autofill/legacy-migration.php` cargado solo via WP-CLI (para correr backfill ad-hoc), no en cada plugins_loaded.
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Robustez ganada:** Reduce parsing on every request
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-022: `mu-plugins/akibara-bootstrap-legal-pages.php` 273 LOC — one-shot init persiste cargado siempre
- **Severidad:** P3
- **Categoría:** [LEGACY] [OVER-ENGINEERED?]
- **Archivo(s):** `server-snapshot/public_html/wp-content/mu-plugins/akibara-bootstrap-legal-pages.php`
- **Descripción:** Crea las páginas /politica-de-privacidad/ y /devoluciones/ una vez (idempotente vía flag `akibara_legal_pages_bootstrapped >= 2`). Una vez ejecutado, el archivo solo hace ese check + 200 LOC de HEREDOC content que nunca se ejecuta. Está cargado en cada request por ser mu-plugin.
- **Evidencia:** Línea 28-31 del archivo: si flag >= 2, return inmediato. Pero el resto del archivo se parsea siempre (PHP opcode cache mitiga, pero el require/include cuesta).
- **Propuesta:** Después de confirmar páginas creadas en prod, mover archivo a `docs/setup-scripts/` y borrar de mu-plugins. O mantener pero strip los HEREDOCs a ~30 LOC de bootstrap functions.
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Robustez ganada:** Menor parse time en cada request
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** ninguno (idempotente, ya ejecutado)

### F-02-023: `popup` module y `welcome-discount` ambos suprimen al otro — opacity flags duros
- **Severidad:** P3
- **Categoría:** [REDUNDANT]
- **Archivo(s):** `modules/welcome-discount/module.php:69` `add_filter( 'akibara_popup_should_render', '__return_false', 5 )` + `modules/popup/module.php:44,65` filter usage
- **Descripción:** welcome-discount cuando ON suprime el popup regular. Pero ambos popups capturan email + dan cupón. Si welcome-discount está OFF (caso actual), popup regular corre. Cuando welcome-discount se prenda → silencia el otro. Pero ambos tienen 600+ LOC de mismo flujo subscribe → coupon.
- **Evidencia:** Ver código.
- **Propuesta:** F-PRE-003 ya flagged "popup hardcoded a 1 step". Cuando se haga refactor "2 popups in same sprint", consolidar el flujo subscribe duplicado entre popup + welcome-discount. NO sprint actual.
- **Esfuerzo:** N/A (parte de refactor futuro)
- **Sprint sugerido:** S4+
- **Robustez ganada:** N/A
- **Requiere mockup:** SÍ (cuando ocurra)
- **Riesgo de regresión si se actúa:** N/A

### F-02-024: `descuentos` usa formato v11 con wrapper `_v: 11` — migración v10→v11 dead code visible en `get_reglas`
- **Severidad:** P3
- **Categoría:** [LEGACY]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/modules/descuentos/module.php:159-185`
- **Descripción:** El método `get_reglas()` mantiene compat path para datos v10 (formato plano sin wrapper `_v`). Si todos los datos en prod ya están en v11, ese path es dead code. Línea 175 invoca `migrar_regla_v10` por cada regla siempre — posible NO-OP si v11.
- **Evidencia:** Líneas 167-173: detecta v11 vs v10 path.
- **Propuesta:** S3: verificar que opción `akibara_descuento_reglas` en prod es formato v11. Si confirmado, simplificar `get_reglas()` y deprecar `migrar_regla_v10`.
- **Esfuerzo:** S
- **Sprint sugerido:** S3
- **Robustez ganada:** Cleaner code
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

### F-02-025: `phpstan-baseline.neon` 7.7K — deuda técnica trackeada pero deployada
- **Severidad:** P3
- **Categoría:** [DEAD-CODE]
- **Archivo(s):** `server-snapshot/public_html/wp-content/plugins/akibara/phpstan-baseline.neon` 7774 bytes, `phpstan.neon`, `.phpcs-baseline`, `.pcp-baseline`
- **Descripción:** Configs de baseline tooling en plugin root. Visible públicamente, expone qué warnings PHPStan/PHPCS tiene Akibara (information disclosure soft). Se incluye en F-02-001 (general dev tooling cleanup).
- **Evidencia:** `ls plugins/akibara/.phpcs-baseline phpstan-baseline.neon` → 2 archivos
- **Propuesta:** Incluido en F-02-001. Excluir del deploy.
- **Esfuerzo:** S (cubierto por F-02-001)
- **Sprint sugerido:** S1
- **Robustez ganada:** Cubierto por F-02-001
- **Requiere mockup:** NO
- **Riesgo de regresión si se actúa:** bajo

## Cross-cutting flags para mesa

### XC-FLAG-1: Lead-01 debe re-evaluar CLEAN-003 + CLEAN-004 con usuario ANTES de R2

**Owner:** Lead Mesa Técnica + Mesa-10 (security)

El usuario clasificó `akibara-brevo-smtp.php` y la constante `AKB_BREVO_API_KEY` como "legacy iteración antigua". Mi análisis muestra que SON el path activo de email transaccional (72 emails ya enviados via este código). Si Lead-01 ejecuta CLEAN-003+004 sin clarificar, prod pierde:
- Order confirmation emails
- Customer password resets
- Admin notifications
- Cualquier email WC nativo

Recomendación: en R2 plenaria, Lead pregunta al usuario explícitamente: "¿Quisiste decir que NO usás un plugin third-party como WP Mail SMTP, o que querés borrar el mu-plugin custom que rutea wp_mail vía Brevo API?". Probable que sea lo primero, en cuyo caso CLEAN-003 + CLEAN-004 se REVIERTEN del backlog.

### XC-FLAG-2: Mesa-10 confirma si akibara-sentry-customizations.php es realmente "no useful"

**Owner:** Mesa-10 security

Similar al XC-FLAG-1: el mu-plugin `akibara-sentry-customizations.php` (CLEAN-001) hace 3 cosas no triviales:
1. PII scrubbing (RUT chileno, teléfono +56) — NO en upstream wp-sentry-integration
2. Constant mapping `SENTRY_DSN → WP_SENTRY_PHP_DSN` — REQUERIDO para que upstream funcione
3. WC breadcrumbs — NO en upstream

CLEAN-001 dice "solo ocupamos el plugin no el custom". Mesa-10 valida si:
- El usuario quería decir "no usamos el plugin custom akibara-sentry-bridge OLD" (que ya está borrado, su sucesor es este mu-plugin)
- O si genuinamente quiere borrar este mu-plugin tambien

Si es lo segundo, deja Sentry sin PII scrubbing (RUT clientes podrían quedar en error logs) y posiblemente no funcione (sin WP_SENTRY_PHP_DSN). 

### XC-FLAG-3: PM (Mesa-23) decide cleanup de growth-modules

**Owner:** Mesa-23 PM

F-02-007 detalla 5 growth modules con 0–1 usuarios reales (~5500 LOC + 5 tablas DB). Decisión PM no técnica:
- Activar todos en S1-S2 con marketing real → riesgo de mantener todo sin saber qué funciona
- Mantener solo welcome-discount + back-in-stock (más manga-aligned) → opción más alineada con `feedback_no_over_engineering`
- Mantener todos en `modules-disabled/` y activar uno por vez con métricas → robusto pero más esfuerzo

Mi recomendación basada en `feedback_robust_default`: opción C (gradual con métricas) es la más robusta, pero opción B es buen balance.

### XC-FLAG-4: Coordinar SEC-P0-001 (delete admin backdoors) con cleanup wp_akb_referrals row 4

**Owner:** Mesa-10 + lead

Si user 18 (`nightmarekinggrimm26@gmail.com`) se confirma comprometido (F-PRE-010), debe limpiarse también `wp_akb_referrals.id=4` (referrer_email='nightmarekinggrimm26@gmail.com'). NO basta con `bin/wp-ssh user delete 18 --reassign=1` — la tabla custom no es manejada por WP user delete.

## Áreas que NO cubrí (out of scope)

- **Tema PHP templates** (`templates/`, `template-parts/`, `header.php`, `footer.php`, etc.) — solo cubrí `inc/` que es el código PHP de plugin-style. Los templates de Twig/PHP no fueron auditados. Mesa-15 / mesa-7 ven design.
- **JavaScript files** — solo conté LOC de assets/css/js que cargan los modules; el contenido JS no fue auditado. Mesa-19 frontend-developer.
- **CSS files** — fuera de scope tech-debt PHP. Mesa-08 design-tokens.
- **Hostinger mu-plugins** (`hostinger-auto-updates.php`, `hostinger-preview-domain.php`) — vendor managed, no custom Akibara.
- **WPML/MailPoet/Smush/Revslider PHP files en uploads/** — flagged en F-02-006 pero el contenido específico de cada PHP NO fue auditado por contenido (solo presencia). Mesa-10 security debe validar si son backdoors antes de cleanup.
- **El plugin `akibara/admin/`** — solo conté LOC, no audité los 1018 LOC de `class-akibara-admin.php`. Mesa-15 architect ve admin UX.
- **Templates email** (`emails/` en akibara-reservas) — no cubrí los 5 archivos `class-email-*.php`. Mesa-09 email-qa.
- **Datos: `data/normalize.php`, `data/sinonimos.php`** — read header solo. Mesa-22 wp-master valida search.
- **El plugin `akibara/search.php`** (16 KB, 478 LOC) y `includes/akibara-search.php` (1369 LOC) — código de búsqueda FULLTEXT con SHORTINIT, no audité por tiempo. Mesa-22 wp-master.
- **Registry pattern de Akibara_Module_Registry::boot()** — leí la firma + comments, NO audití si efectivamente abort load cuando enabled=false. F-02-016 es hipótesis a confirmar en R2.
- **Archivos large no abiertos**: `themes/akibara/inc/woocommerce.php` 1336 LOC, `themes/akibara/inc/serie-landing.php` 1275 LOC, `themes/akibara/inc/filters.php` 1181 LOC. Solo header skim. Pueden tener dead code adicional. Mesa-15 / mesa-22.
- **Validación que las DB tables son actually used vs. just created via dbDelta** — algunas tablas (`wp_akb_referrals`, `wp_akb_bis_subs`) están vacías. ¿Se invocan los INSERTS pero fallan, o nunca llegaron a invocarse? Mesa-22.
