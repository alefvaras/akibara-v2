---
agent: mesa-12-seo-tech
round: 1
date: 2026-04-27
scope: Audit SEO técnico data-driven akibara.cl — GSC 90d + código fuente inc/seo/ + .htaccess + mu-plugins
files_examined: 13
findings_count: { P0: 4, P1: 4, P2: 3 }
---

# mesa-12-seo-tech — Audit data-driven akibara.cl
**Fecha:** 2026-04-27 | **Período data:** 2026-01-28..2026-04-27 (90d)

## Executive summary

- **Signal split en redirects es el problema SEO #1:** 7+ URLs `/product/`, `/product-brand/`, `/product-category/` siguen apareciendo en SERP con 50+ clicks en 90d y 600+ impressions perdidas. Google no consolida PageRank al canonical porque las URLs redirect generan "Page with redirect" en vez de 301 permanente estable. Esto compite directamente con las URLs canónicas.
- **Home sin rich results** a pesar de que Organization schema está definida en código: el guard `if (defined('RANK_MATH_VERSION')) return;` en `schema-organization.php:9` suprime el JSON-LD custom cuando Rank Math está activo — y Rank Math Free no emite Organization schema en home. Resultado neto: Google lee cero JSON-LD organization en homepage.
- **page-sitemap.xml con 22 warnings** apunta a páginas en sitemap que Google considera noindex o redirect — probable contaminación de URLs de páginas WP que deberían estar excluidas.
- **Brand pages (editorials)** tienen inversión de señales: `/product-brand/ivrea-argentina/` (redirect) tiene 192 impressions mientras que `/marca/ivrea-argentina/` (canonical) tiene solo 37. El redirect .htaccess para `product-category/` existe pero NO para `product-brand/`.
- **Estimate recuperable:** ~40-60 clicks/mes adicionales al resolver F-01 (redirects) + F-02 (organization schema) + F-04 (brand signal), basado en CTR esperado de páginas que ya tienen impressions pero señal dispersa.

---

## Hallazgos P0 (Critical)

### F-01: URLs redirect persistentes en SERP — signal split activo
- **Evidencia GSC:** `/product-brand/ivrea-argentina/` 192 imp, 2 clicks, 1.04% CTR (verdict: "Page with redirect"). `/product-category/manga/manhwa/` 74 imp (redirect). `/product/jujutsu-kaisen-26-panini-argentina/` 80 imp, 9 clicks (redirect). `/product/el-verano-que-hikaru-murio-1-milky-way/` 74 imp (redirect). `/product/el-chico-que-me-gusta-no-es-un-chico-2-panini-espana/` 56 imp (redirect). Total: ~600+ impressions en URLs que son redirects.
- **Root cause técnico:** El `.htaccess` tiene redirect 301 para `product-category/` → `/$1` (línea 134: `RewriteRule ^product-category/(.+)$ /$1 [R=301,L]`) pero **no tiene regla equivalente para `product-brand/`**. Las URLs `/product-brand/{slug}/` no tienen redirect en .htaccess — el redirect viene de la configuración de taxonomía en WordPress (slug `marca` en vez de `product-brand`). Ese redirect es manejado por WordPress/WooCommerce internamente (302 temporal si mal configurado, o 301 si el permalink está bien guardado). La ausencia de regla .htaccess explícita para `product-brand/` significa que el redirect puede no ser permanente o puede generar hops adicionales.
- **Setup actual:** `.htaccess` líneas 133-136 solo cubre `product-category/` y `categoria-producto/`. No hay bloque para `product-brand/` → `marca/`.
- **Cubierto por código existente?** Parcial — `product-category/` sí tiene 301 en .htaccess. `product-brand/` no.
- **Acción propuesta:** Agregar al bloque de redirects de .htaccess (después de línea 136):
  ```
  RewriteRule ^product-brand/(.+)$ /marca/$1 [R=301,L]
  ```
  Adicionalmente, verificar si las URLs `/product/{slug}/` (producto sin prefijo WC nativo) tienen 301 permanente hacia `/{slug}/` — esto depende de cómo está configurado el permalink de `product` en WC (Settings > Permalinks > Product permalink base). Si el base es vacío (`/`), WC emite 301 automático. Confirmar con `curl -I https://akibara.cl/product/jujutsu-kaisen-26-panini-argentina/` que devuelve 301 y no 302.
  Después de fix .htaccess: hacer GSC "Request Indexing" manual sobre `/marca/ivrea-argentina/` para acelerar consolidación.
- **Impact estimado:** `/product-brand/ivrea-argentina/` tiene 192 imp a pos 32. Al consolidar señal en `/marca/ivrea-argentina/` (37 imp, pos 31), el aumento de autoridad debería mejorar posición en 5-8 puntos en 60-90 días. Estimado: +3-5 clicks/mes para ivrea sola. Para el conjunto de redirects product/*: ~15-25 clicks/mes adicionales al eliminar hop y consolidar señal.
- **Verificación post-fix:** GSC URL Inspection sobre `/product-brand/ivrea-argentina/` debe pasar de "Page with redirect" a "Redirect (permanent)" con destino correcto. `curl -I https://akibara.cl/product-brand/ivrea-argentina/` debe retornar `301` con `Location: https://akibara.cl/marca/ivrea-argentina/`.
- **Effort:** 1h (30 min .htaccess + 30 min verification curl + GSC submit)
- **Sprint:** S4 o continuo (cambio mínimo, bajo riesgo)

---

### F-02: Home sin rich results — Organization schema suprimida por guard Rank Math
- **Evidencia GSC:** URL Inspection `/` → `rich_results: None`. Todas las otras páginas indexadas tienen al menos Breadcrumbs. La home tiene 316 impressions y 52 clicks en 90d — es la página más visible del sitio.
- **Root cause técnico:** `server-snapshot/.../inc/seo/schema-organization.php:9`: `if (defined('RANK_MATH_VERSION')) return;`. Este guard suprime el JSON-LD custom cuando Rank Math está activo. Rank Math Free no emite `Organization` schema en la homepage — solo emite `WebSite` (con SearchAction) y en algunos casos `WebPage`. Resultado: cero JSON-LD organization en el `<head>` de la home.
  El filtro `rank_math/json_ld` en el mismo archivo (línea 36) maneja correcciones de schema pero no inyecta un nodo Organization nuevo — solo limpia datos incorrectos que Rank Math emite.
- **Setup actual:** Guard en línea 9 hace que el bloque `wp_head` que emite el JSON-LD Organization nunca se ejecute en producción (porque RANK_MATH_VERSION siempre está definido). El filtro `rank_math/json_ld` existe pero no agrega `Organization` al `$data` cuando no existe.
- **Cubierto por código existente?** No — el código está escrito correctamente pero el guard lo desactiva para el caso Rank Math activo, y no hay fallback que inyecte Organization via el filtro.
- **Acción propuesta:** En `schema-organization.php`, reemplazar el bloque `wp_head` existente por un filtro `rank_math/json_ld` que inyecte el nodo Organization al @graph de Rank Math cuando no existe:
  ```php
  add_filter('rank_math/json_ld', function($data, $jsonld) {
      if (!is_front_page()) return $data;
      // Solo inyectar si Rank Math no emitió Organization
      $has_org = false;
      foreach ($data as $entity) {
          if (isset($entity['@type']) && $entity['@type'] === 'Organization') {
              $has_org = true; break;
          }
      }
      if (!$has_org) {
          $data['AkibaraOrg'] = [
              '@type' => 'Organization',
              '@id'   => home_url('/#organization'),
              'name'  => 'Akibara',
              'url'   => home_url('/'),
              'sameAs' => [
                  'https://www.instagram.com/akibara.cl/',
                  'https://www.tiktok.com/@akibara.cl',
                  'https://www.facebook.com/akibara.cl/',
              ],
          ];
      }
      return $data;
  }, 99, 2);
  ```
  El nodo existente en `schema-product.php` ya referencia `home_url('/#organization')` en `offers.seller.@id` — agregar `@id` al nodo Organization crea la conexión correcta en el @graph.
- **Impact estimado:** Organization schema puede habilitar Knowledge Panel + sitelinks en brand queries ("akibara" 34 clicks, 104 imp, pos 5.7). Impact directo difícil de medir, pero es prerequisito para Google Merchant Center rich results y Knowledge Panel.
- **Verificación post-fix:** `curl https://akibara.cl/ | grep -A5 'application/ld+json'` debe mostrar nodo `@type: Organization`. GSC Rich Results Test sobre `/` debe pasar de `None` a `Organization`.
- **Effort:** 2h (1h código + 30 min test curl + 30 min GSC validation)
- **Sprint:** S4

---

### F-03: page-sitemap.xml con 22 warnings — páginas potencialmente noindex en sitemap
- **Evidencia GSC:** `page-sitemap.xml`: 11 URLs, 0 errores, **22 warnings**. Ratio anómalo: más warnings que URLs (22 warnings / 11 páginas = 2 warnings por página promedio). `sitemap_index.xml` tiene 1 warning adicional.
- **Root cause técnico:** Los warnings en GSC para sitemaps típicamente son: (1) URL es noindex pero está en sitemap, (2) URL tiene canonical diferente al URL del sitemap, (3) URL retorna redirect. `noindex.php` define noindex para feed, search, author, tag, date, attachment, query params junk — ninguno de estos debería aparecer en page-sitemap. Sin embargo, hay páginas como `/encargos/` que GSC URL Inspection retorna `Excluded by 'noindex' tag`. Si Rank Math genera `page-sitemap.xml` e incluye `/encargos/` (que tiene noindex custom), esa página generaría warning. También puede ser que páginas con `?` params estén siendo añadidas.
  Rank Math Free genera sitemaps sin respetar 100% los filtros `rank_math/sitemap/exclude_taxonomy` (esos filtros son para taxonomías, no para páginas). Para excluir páginas individuales en Rank Math, se necesita configurar "Advanced" > "Robots" en cada página.
- **Setup actual:** `noindex.php` tiene filtro `rank_math/sitemap/exclude_taxonomy` para `pa_serie`, `pa_encuadernacion`, `pa_pais` — pero no excluye páginas individuales del sitemap via código. La página `/encargos/` tiene noindex por GSC pero puede estar en page-sitemap.
- **Cubierto por código existente?** No — no hay filtro `rank_math/sitemap/exclude_post` para páginas noindex.
- **Acción propuesta:** Agregar a `noindex.php`:
  ```php
  add_filter('rank_math/sitemap/exclude_post', function($exclude, $post_id) {
      // Excluir del sitemap páginas que tenemos como noindex
      $noindex_slugs = ['encargos', 'cart', 'checkout', 'mi-cuenta', 'order-received'];
      $slug = get_post_field('post_name', $post_id);
      if (in_array($slug, $noindex_slugs, true)) return true;
      return $exclude;
  }, 10, 2);
  ```
  Después regenerar sitemap: en Rank Math > Sitemap > Regenerate sitemap. Resubmit en GSC.
- **Impact estimado:** Reducir warnings de 22 a 0 mejora crawl budget efficiency. Señal de confianza para Googlebot. No impacta clicks directamente pero mejora health del sitio.
- **Verificación post-fix:** GSC Sitemaps > page-sitemap.xml debe mostrar 0 warnings. `curl https://akibara.cl/page-sitemap.xml` no debe incluir `/encargos/`.
- **Effort:** 2h (1h investigación wget del sitemap para confirmar qué páginas están, 1h fix + regenerar)
- **Sprint:** S4

---

### F-04: Brand pages — signal split editorial por falta de redirect product-brand
- **Evidencia GSC:** Query "ivrea chile" 61 imp, pos 24.0 — landing page es `/product-brand/ivrea-argentina/` (redirect), no `/marca/ivrea-argentina/` (canonical). El canonical solo tiene 37 imp vs 192 del redirect. Meta title actual de `/marca/ivrea-argentina/`: "Ivrea Argentina — Manga y Cómics en Chile" (según `meta.php:194` filter `document_title_parts`).
- **Root cause técnico:** Mismo que F-01 — ausencia de `RewriteRule ^product-brand/(.+)$ /marca/$1 [R=301,L]` en .htaccess. Cuando Google crawlea la URL `/product-brand/ivrea-argentina/`, recibe redirect (via WordPress) pero no un 301 explícito en .htaccess. Google mantiene ambas URLs en su índice como candidatos y sirve la que tiene más señal histórica (la vieja `/product-brand/`) en vez de la nueva canonical.
  Adicionalmente, el title de la página brand (`meta.php:194`) es genérico: "Ivrea Argentina — Manga y Cómics en Chile". Para "ivrea chile" pos 24, el title debería incluir "Chile" más prominentemente y el meta description debería mencionar la cantidad de títulos disponibles.
- **Setup actual:** `meta.php:193-202` define title via `document_title_parts` filter: `$term->name . ' — Manga y Cómics en Chile'`. `meta.php:31-34` define meta description: `'Compra manga y cómics de ' . $term->name . ' en Chile. Envío a todo el país. ' . $term->count . ' títulos disponibles en Akibara.'`. La description es razonablemente buena pero el title no incluye señal de "comprar" o "chile" al inicio.
- **Cubierto por código existente?** Parcial — description es correcta, title mejorable, redirect es el bloqueante principal.
- **Acción propuesta:** (1) Fix redirect .htaccess (ver F-01 — mismo cambio resuelve ambos). (2) Optimizar title brand: cambiar filter en `meta.php` de `$term->name . ' — Manga y Cómics en Chile'` a `'Comprar ' . $term->name . ' en Chile | Akibara — ' . $term->count . ' títulos'` (max ~60 chars). REQUIERE revisar que queda dentro de los 60 chars para todos los brands activos.
- **Impact estimado:** "ivrea chile" pos 24 → si consolida señal en canonical + mejora title, posición esperada pos 15-18 en 60 días. A pos 15 con 61 imp: ~3-5 clicks/mes adicionales solo para ivrea. Extrapolando otros brands activos: ~8-12 clicks/mes totales.
- **Verificación post-fix:** GSC URL Inspection `/marca/ivrea-argentina/` debe subir en impressions en 30-60 días. CTR para "ivrea chile" debe mejorar con title más descriptivo.
- **Effort:** 2h (1h .htaccess + 30 min title optimization `meta.php` + 30 min QA)
- **Sprint:** S4
- **Requiere mockup:** NO (cambio de title text, no visual)

---

## Hallazgos P1 (High)

### F-05: DESKTOP CTR 1.77% — menos de la mitad del mobile (3.79%)
- **Evidencia GSC:** Mobile: 167 clicks / 4406 imp / 3.79% CTR / pos 8.0. Desktop: 63 clicks / 3566 imp / 1.77% CTR / pos 10.4. La brecha CTR es 2.14x. Para posición similar (~9-10) el benchmark de tiendas ecommerce es 3-5% desktop.
- **Root cause técnico:** Dos vectores probables: (a) Meta descriptions truncadas en desktop SERP. Desktop SERP muestra ~158-160 chars mientras mobile trunca antes. Si las descriptions tienen las keywords al final, desktop ve el texto completo pero menos accionable. (b) Títulos de productos con "Comprar X en Chile | Akibara" — en desktop este patrón puede verse como demasiado transaccional sin credibilidad suficiente para clicks orgánicos. `meta.php:427-432` define: `'Comprar ' . $product_title . ' en Chile | Akibara'` — en desktop este título compite con otros sellers que puedan tener precio en title.
  No hay evidencia de sitelinks en el SERP para "akibara" (brand query 34 clicks) — si Google mostrara sitelinks, el CTR desktop para brand queries subiría sustancialmente.
- **Setup actual:** `meta.php` emite titles y descriptions vía filtros Rank Math. El patrón "Comprar X en Chile | Akibara" es fijo para todos los productos.
- **Cubierto por código existente?** No analizado anteriormente como finding específico.
- **Acción propuesta:** (a) Auditar lengths reales de meta descriptions en GSC: Performance > Pages > filtrar por Desktop > ordenar por CTR asc — identificar si hay páginas con description >160 chars que podrían truncarse diferente. (b) Para la homepage, el title actual genera brand queries pero sin propuesta de valor — considerar añadir "— +1.300 títulos" al title de home para diferenciar en desktop SERP. (c) Verificar si hay structured data de sitio que habilite sitelinks: Organization + SearchAction schema son prerrequisitos. El fix de F-02 (Organization schema) puede activar sitelinks para brand queries.
- **Impact estimado:** Si CTR desktop sube de 1.77% a 2.5% (alcanzable), con 3566 imp: +26 clicks/mes adicionales.
- **Verificación post-fix:** Monitorear GSC Performance > Devices en 30-45 días post-cambios. CTR desktop objetivo: >2.5%.
- **Effort:** 4h (2h análisis GSC + 2h ajustes titles/descriptions)
- **Sprint:** S4

---

### F-06: /manhwa/ — query "manhwas online" 11 imp pos 9.4 con 0 clicks
- **Evidencia GSC:** Query "manhwas online" 11 imp, pos 9.4, 0 clicks. Query "manhwa" similarmente posicionado. La página `/manhwa/` está indexada (GSC: PASS, Breadcrumbs en rich results).
- **Root cause técnico:** La URL `/manhwa/` probablemente es una página custom o un permalink de categoría. Si el title es genérico ("Manhwa — Akibara"), no diferencia del intent de búsqueda "manhwas online" que busca un catálogo/listado. A posición 9.4 el CTR esperado para ecommerce es 3-5% — que sea 0 con 11 impressions sugiere que el snippet no responde la intent.
- **Setup actual:** No pude examinar el template directo de `/manhwa/` (no está en los paths del audit). Pero `meta.php` solo cubre `is_product_category()` para descriptions dinámicas — si `/manhwa/` es una página custom (no una product category), puede no tener description optimizada.
- **Cubierto por código existente?** Desconocido — necesita verificación en Iter 2.
- **Acción propuesta:** Verificar title y meta description de `/manhwa/` con `curl https://akibara.cl/manhwa/ | grep -i 'title\|description'`. Si es una página custom, agregar al array `$fallbacks` en `meta.php` (línea 262): `'manhwa' => 'Los mejores manhwa coreanos en Chile. Catálogo de webtoons y manhwa con envío a todo Chile en Akibara.'`. Si es product category, asegurarse que tiene description configurada en WC.
- **Impact estimado:** Pasar de 0 a 3% CTR con 11 imp: ~0.3 clicks/mes. Parece poco, pero la query va a escalar con crecimiento del catálogo manhwa. Fix preventivo.
- **Effort:** 1h
- **Sprint:** S4 o continuo

---

### F-07: Blog posts posición 9-11 con CTR ~2% — snippet no responde intent conversacional
- **Evidencia GSC:** "manga vs comics" 2 imp pos 11, 0 clicks. "cuantos tomos tiene atelier of witch hat" 5 imp pos 10.8, 0 clicks. "my hero academia cuantos tomos tiene" 1 imp pos 10, 0 clicks. "mejores mangas 2026" 1 imp pos 6.1, 1 click. `/atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/` 55 imp pos 9.3, 1 click (CTR 1.82%).
- **Root cause técnico:** Las queries "cuantos tomos tiene X" son conversacionales — esperan una respuesta directa en el snippet (featured snippet candidatos si la página responde "X tiene N tomos"). El blog post `/atelier-of-witch-hat...` tiene title que indica "obra maestra" pero no responde "cuántos tomos" en el title — Google no puede generar un snippet posicional. `schema-article.php` tiene un FAQPage builder que extrae preguntas de H2 con "?" — si estos posts tienen H2 como "¿Cuántos tomos tiene Atelier of Witch Hat?" el FAQ schema se emite automáticamente. Verificar si está ocurriendo.
- **Setup actual:** `schema-article.php:317-401` extrae FAQPage de H2 con "?" o interrogativas españolas. `schema-article.php:440-535` extrae ItemList de listicles con "Los N mejores...". Ambas se emiten como JSON-LD standalone. El ItemList schema en `/los-10-mejores-manga-para-empezar-a-leer-en-2026/` probablemente funciona (4 clicks, 52 imp, CTR 7.69% — mejor performer del blog).
- **Cubierto por código existente?** Parcial — el code está bien diseñado pero requiere que los posts tengan H2 con "?" — no garantizado para posts existentes.
- **Acción propuesta:** (a) En post `/atelier-of-witch-hat...`, agregar/editar H2 "¿Cuántos tomos tiene Atelier of Witch Hat?" con respuesta directa en el párrafo siguiente (ej: "Atelier of Witch Hat tiene X tomos publicados en Chile por editorial Y hasta la fecha."). Esto activa el FAQPage schema builder automático. (b) Para "manga vs comics", verificar si el post tiene H2 "¿Qué diferencia hay entre manga y cómics?" — el schema article builder lo detectaría. Acción editorial, no código. REQUIERE mesa-06 (copy/contenido) para ejecutar.
- **Impact estimado:** FAQ schema en posts con preguntas puede generar featured snippet candidatos. Un featured snippet en "cuantos tomos tiene atelier" (5 imp, pos 10.8) puede triplicar CTR. Estimado conservador: +2-4 clicks/mes combinado blog posts.
- **Effort:** 4h (2h auditoría posts + 2h edición content — requiere mesa-06)
- **Sprint:** S4+
- **Requiere:** REQUIERE mesa-06 (editorial/copy)

---

### F-08: /nosotros/ capturando brand query "akibara" — split intent
- **Evidencia GSC:** `/nosotros/` 86 imp, pos 4.5, 1 click, CTR 1.16%. Query "akibara" muestra esta página en 38 imp con 0 clicks. La homepage `/` también aparece para "akibara" (34 clicks, 104 imp, 32.69% CTR, pos 5.7). Google está sirviendo ambas páginas para el mismo brand query.
- **Root cause técnico:** Cuando Google ve múltiples páginas para la misma query, puede elegir cualquiera como resultado principal. Si `/nosotros/` rankea para "akibara" pero no convierte (0 clicks de 38 imp), está diluyendo las impressions que deberían ir a home. El título de `/nosotros/` probablemente incluye "Akibara" prominentemente sin diferenciar suficiente que es una página About y no la tienda.
- **Setup actual:** `meta.php` no tiene una descripción específica para `/nosotros/` en el array `$fallbacks` (línea 262) — solo cubre encargos, rastrear, mi-cuenta, contacto, bienvenida. Si `/nosotros/` no tiene meta description manual en Rank Math, puede usar el excerpt de la página o quedar sin description. El canonical de `/nosotros/` es correcto (PASS en GSC).
- **Cubierto por código existente?** No — `/nosotros/` no está en el array fallbacks de meta.php.
- **Acción propuesta:** Agregar al array `$fallbacks` en `meta.php:262`:
  `'nosotros' => 'Akibara es una tienda chilena de manga y cómics originales. Conoce nuestra historia, valores y cómo trabajamos para traerte los mejores títulos con envío a todo Chile.'`
  Esto da contexto que es "About" y no landing de compra — diferencia el SERP snippet de la home. La home debería mantenerse como resultado principal para "akibara".
- **Impact estimado:** Reducir competencia interna entre `/` y `/nosotros/` para brand query. Si home consolida impressions de "akibara": +2-3 clicks/mes.
- **Effort:** 1h
- **Sprint:** S4 o continuo

---

## Hallazgos P2 (Medium)

### F-09: IndexNow — key constant hardcodeada en mu-plugin (exposure menor)
- **Evidencia:** `mu-plugins/akibara-indexnow.php:27`: `const AKIBARA_INDEXNOW_KEY = 'REDACTED-INDEXNOW-KEY';`. La key está en texto plano en código versionado.
- **Root cause técnico:** IndexNow keys son de ownership-verification (no secretas en el sentido estricto — están disponibles públicamente en `/{key}.txt`), pero tenerlas en código hace difícil rotarlas si el sitio cambia de dominio o se quiere revalidar ownership. El patrón consistente del proyecto (memoria `project_no_key_rotation_policy`) es mover secrets a `wp-config-private.php`.
- **Setup actual:** `akibara-indexnow.php:27` define `const AKIBARA_INDEXNOW_KEY` hardcodeado.
- **Cubierto por código existente?** No.
- **Acción propuesta:** Mover a `wp-config-private.php`: `define('AKB_INDEXNOW_KEY', 'REDACTED-INDEXNOW-KEY');` y en el mu-plugin: `const AKIBARA_INDEXNOW_KEY = AKB_INDEXNOW_KEY;` (o usar la constante directamente). Severidad baja porque IndexNow keys no dan acceso — solo ownership de indexing submissions.
- **Effort:** 1h
- **Sprint:** Parking-lot (bajo impacto SEO directo)

---

### F-10: Autor pages thin content — "tatsuya endo" pos 25.5, 10 imp, 0 clicks
- **Evidencia GSC:** Query "tatsuya endō" 10 imp, pos 25.5, 0 clicks. Landing page: `/autor/tatsuya-endo/`. Similarmente para otros autores.
- **Root cause técnico:** Las páginas `/autor/{slug}/` son archivos de taxonomía `pa_autor`. No hay schema específico para Author archives en los módulos actuales. `noindex.php` no bloquea estas páginas (solo bloquea `is_author()` que son author archives de WordPress, no taxonomías custom). `meta.php` no tiene handler específico para `is_tax('pa_autor')`.
- **Setup actual:** No hay `is_tax('pa_autor')` en `meta.php` — las páginas de autor pueden quedar sin meta description específica (Rank Math generaría una genérica o quedaría vacía). No hay schema de tipo Person o ProfilePage para estas páginas.
- **Cubierto por código existente?** No.
- **Acción propuesta:** (1) Agregar en `meta.php` handler para `is_tax('pa_autor')`:
  ```php
  } elseif (is_tax('pa_autor')) {
      $term = get_queried_object();
      if ($term) {
          $desc = 'Compra manga de ' . $term->name . ' en Chile. Todos los títulos disponibles con envío a todo el país en Akibara.';
      }
  }
  ```
  (2) Considerar agregar CollectionPage schema para autor archives similar al que existe para product_brand en `schema-collection.php:129-150`. La lógica es idéntica — solo cambiar el taxonomy check.
- **Impact estimado:** Queries de autor son long-tail de alta conversión ("tatsuya endo manga", "autor jujutsu kaisen"). Mejorar snippet puede mover de pos 25 a pos 15-20 en 90 días. Estimado: +1-3 clicks/mes por autor activo.
- **Effort:** 2h
- **Sprint:** S4+

---

### F-11: Hreflang emite URL con query string en páginas filtradas
- **Evidencia:** `canonical.php:71-76` — el hreflang usa `$_SERVER['REQUEST_URI']` directamente:
  ```php
  $url = esc_url( home_url( add_query_arg( [], $_SERVER['REQUEST_URI'] ?? '/' ) ) );
  $clean = trailingslashit( strtok( $url, '?' ) );
  ```
  El `strtok($url, '?')` limpia query strings, pero `add_query_arg([], $_SERVER['REQUEST_URI'])` puede preservar path segments con parámetros en algunos edge cases de URL encoding.
- **Root cause técnico:** El limpiador de query string en línea 73-74 depende de `strtok` que funciona bien para `?param=val` pero puede no manejar correctamente fragmentos `#` o paths con caracteres especiales encoded. En la práctica, para URLs de productos y categorías normales esto funciona, pero en URLs de filtros WC (que usan `/attribute/value/` en el path, no solo `?`), `strtok` no limpia porque no hay `?`.
  Adicionalmente, hreflang apunta a la URL de la *página filtrada* (`/tienda/attribute/genero/accion/`) en vez del canonical de la categoría (`/tienda/`). Esto no es un error crítico pero dilute la señal hreflang.
- **Setup actual:** `canonical.php:70-76` emite hreflang para todas las páginas, usando REQUEST_URI con cleanup de `?`.
- **Cubierto por código existente?** Parcial — el cleanup es correcto para query params pero no para filter path segments de WC.
- **Acción propuesta:** Usar el mismo canonical URL que Rank Math emite (ya filtrado en `canonical.php:28-64`) como base para hreflang:
  ```php
  add_action('wp_head', function(): void {
      if (is_admin() || wp_doing_cron()) return;
      $canonical = apply_filters('rank_math/frontend/canonical', get_permalink() ?: home_url('/'));
      $clean = trailingslashit(strtok($canonical, '?'));
      echo '<link rel="alternate" hreflang="es-CL" href="' . esc_url($clean) . '" />' . "\n";
      echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($clean) . '" />' . "\n";
  }, 25);
  ```
  Esto asegura que hreflang y canonical siempre apuntan al mismo URL.
- **Effort:** 2h
- **Sprint:** S4+

---

## Items YA cubiertos (no duplicar)

- **B-S1-SEO-01** — Duplicate robots meta conflict + JSON-LD breadcrumb position int: ya implementado. `noindex.php` tiene el filtro de paginación >5 y los junk params. `mu-plugins/akibara-seo-breadcrumb-fix.php` resuelve el BreadcrumbList position int.
- **B-S1-SEO-02** — Sitemap Rank Math validation post-Decisión #17: ya implementado. Productos test eliminados de prod.
- **B-S3-SEO-01..05** — Schema Organization + LocalBusiness, OG/Twitter, noindex >5, breadcrumb editorial: todos marcados DONE en BACKLOG.
- **F-12-005 IndexNow** — backlog low (mantener mientras gratis) — ya documentado como low priority.
- **og:locale es_CL** — `meta.php:211-218` ya tiene filtros para Rank Math. Resuelto.
- **blog index meta description/title/og:image** — `rank-math-filters.php` ya cubre F-03/F-04/F-08 del audit anterior.

---

## Cross-check con BACKLOG existente

**B-S3-SEO-05 — schema-organization priority fix:** El fix aplicado en Sprint 3 agregó el filtro `rank_math/json_ld` para corregir datos incorrectos (precios, editoriales hardcodeadas) pero NO resuelve la ausencia de nodo Organization (F-02 de este audit). Son dos problemas distintos. Regression candidate: el guard en línea 9 de `schema-organization.php` nunca fue eliminado, lo que significa que el Schema Organization custom nunca se emite en producción con Rank Math activo. Sprint 3 SEO "done" puede ser incompleto para este punto específico.

**Verificar:** `curl https://akibara.cl/ | python3 -c "import sys,re; [print(m) for m in re.findall(r'<script type=\"application/ld\+json\">.*?</script>', sys.stdin.read(), re.DOTALL)]"` — si el output no incluye un nodo `@type: Organization` o `@type: Store`, F-02 sigue activo en producción.

---

## Hipótesis para Iter 2

1. **Redirect product/* es 302 no 301:** Las URLs `/product/jujutsu-kaisen-26-panini-argentina/` pueden estar emitiendo redirect temporal (302) en vez de permanente (301) si el permalink base de WooCommerce no está configurado correctamente o si hay algún redirect catch-all de WordPress que precede al .htaccess. Validar con `curl -I --max-redirs 0 https://akibara.cl/product/jujutsu-kaisen-26-panini-argentina/` en producción — si retorna 302, toda la cadena de señal consolidation falla aunque se corrija .htaccess.

2. **page-sitemap.xml warnings son pages con noindex por Rank Math post-meta:** Rank Math permite configurar "noindex" por post en el meta box. Si 22 de las páginas del sitio tienen noindex configurado manualmente en Rank Math Y están siendo incluidas en el sitemap automático, el warning persiste. La solución no sería agregar un filtro sino revisar la configuración Rank Math de cada página.

3. **ItemList schema de blog no está siendo reconocido:** `/los-10-mejores-manga-para-empezar-a-leer-en-2026/` tiene CTR 7.69% (bueno) pero si Google está sirviendo el ItemList como carousel en móvil, el CTR podría ser aún mayor. Verificar en GSC "Search appearance" si hay impressions en "Shopping" o "Image" — señal de que el schema funciona como rich result.

4. **hreflang duplicado potencial:** Si hay un plugin (Rank Math Free) emitiendo hreflang Y el theme también emite hreflang (canonical.php línea 70-76), puede haber duplicados en el `<head>`. Rank Math Free documentación indica que NO emite hreflang por default — pero confirmar via `curl https://akibara.cl/ | grep hreflang` para contar cuántas líneas hreflang aparecen.

5. **Google Merchant Center feed desactualizado:** `schema-product.php` emite `shippingDetails` y `hasMerchantReturnPolicy` en el schema — prerrequisitos para Google Merchant Center. Si el feed de Google Listings and Ads (plugin `google-listings-and-ads` activo) no está sincronizado con los datos de schema, puede haber discrepancias que causen "disapproved" en GMC, afectando los rich results de Shopping.

---

## Áreas que NO cubrí (out of scope)

- **robots.txt** — no fue incluido en los paths del audit. Verificación necesaria en Iter 2: confirmar que `/product/`, `/product-brand/`, `/product-category/` no están bloqueados por robots.txt (lo que explicaría parcialmente el problema de señal).
- **Core Web Vitals reales (LCP/INP/CLS)** — solo análisis estático de código. Sin datos de CrUX/PageSpeed API reales. GSC Performance no tiene CWV breakdown disponible en los inputs.
- **Hreflang validation completa** — delegado a `seo-hreflang` sub-skill según instrucciones del rol.
- **Rank Math configuración admin** — no tengo acceso al panel de Rank Math para ver qué tiene configurado (robots por post, sitemap settings, schema templates). Algunos findings de este audit pueden estar ya resueltos en la UI de Rank Math sin verse en el código del tema.
- **Google Business Profile / LocalBusiness schema** — B-S3-SEO-01 cubre LocalBusiness pero no verifiqué si hay un nodo LocalBusiness en el JSON-LD de home vs solo Organization.
- **PageSpeed/Lighthouse data real** — análisis CWV desde source inspection es limitado. Necesita herramienta de medición real para LCP/INP/CLS actuales.
- **Rank Math premium features** — ninguna propuesta requiere Rank Math Premium. Todo factible con Rank Math Free + código custom del tema.

---

## Cross-cutting flags para mesa

- **mesa-06 (copy):** F-07 requiere edición de H2 en posts de blog para activar FAQPage schema automático. El agente de copy debería auditar los posts con queries posicionadas y agregar H2 interrogativos donde el content lo permite.
- **mesa-15 (architect):** El guard `if (defined('RANK_MATH_VERSION')) return;` en `schema-organization.php:9` es un patrón que puede repetirse en otros archivos de `inc/seo/`. Verificar que ningún otro bloque `wp_head` con schema quede silenciado por este guard cuando debería estar emitiendo vía `rank_math/json_ld` filter.
