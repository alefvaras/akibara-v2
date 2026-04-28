# mesa-22-wordpress-master — Feasibility validation
**Fecha:** 2026-04-27 | **Lead:** mesa-12-seo-tech (audit) | **Validating:** F-01..F-11

## Executive summary

7 findings viables como propuestos (F-01, F-02, F-03, F-08, F-09, F-10, F-11). 3 requieren ajuste técnico antes de implementar (F-04, F-05, F-06). 1 bloqueado por dependencia editorial que no es código (F-07). Ningún finding requiere Rank Math Premium. El stack Rank Math Free + theme custom + LiteSpeed soporta todas las propuestas. Riesgo principal transversal: LiteSpeed cachea sitemaps y puede requerir purge manual post-cambio.

---

## Validation por finding

### F-01: URLs redirect persistentes — agregar RewriteRule product-brand
- **Viable como propuesto?** Si, con un ajuste de posicionamiento
- **Mejor path técnico:** La regla propuesta `RewriteRule ^product-brand/(.+)$ /marca/$1 [R=301,L]` es correcta. El ajuste necesario es de ubicación: debe ir DENTRO del bloque `<IfModule mod_rewrite.c>` existente en líneas 133-136 del .htaccess, NO en un bloque nuevo separado. Agregar un bloque `<IfModule mod_rewrite.c>` adicional para una sola regla es legal pero innecesario y añade confusión. Insertar la línea a continuación de `RewriteRule ^categoria-producto/(.+)$ /$1 [R=301,L]` en el bloque existente es lo correcto.
- **Dependencias técnicas:** La taxonomía `product_brand` tiene rewrite slug `marca` configurado en WooCommerce (confirmado: `/marca/ivrea-argentina/` es la URL canonical que aparece en GSC y es referenciada por `is_tax('product_brand')` en todos los archivos SEO del tema). El .htaccess ya maneja `product-category/` con la misma lógica — este es el patrón exacto a replicar. No hay dependencia de Rank Math Redirections (ese feature es Premium). LiteSpeed puede tener cacheadas las respuestas 200/redirect de `/product-brand/` — necesita purge después del deploy.
- **Riesgos WP:** Bajo. La regla con `[L]` detiene el procesamiento antes de llegar al bloque WordPress. El orden de las reglas en .htaccess es: LSCACHE → SECURITY → custom rules → BEGIN WordPress. La nueva regla en el bloque custom (líneas 133-136) se ejecuta antes que el rewrite WP, que es el comportamiento correcto. Sin conflicto con Rank Math (Free no emite redirects via .htaccess). Sin conflicto con LiteSpeed Cache (LSCACHE block no interfiere con 301s de rutas no cacheadas). Riesgo adicional a validar: confirmar con `curl -I --max-redirs 0 https://akibara.cl/product-brand/ivrea-argentina/` que la cadena de redirects no tenga más de 1 hop. Si WordPress ya emitía un 301 interno vía rewrite WP y ahora el .htaccess agrega otro 301 al mismo path, se generaría una cadena de 2 redirects (301→301). No es un error técnico grave pero puede demorar la consolidación de señal. Verificar pre y post deploy.
- **Effort revisado:** 30 min (15 min edición + 15 min verificación curl + GSC Request Indexing). Consistente con estimado mesa-12.
- **Acceptance criteria técnico:** `curl -I https://akibara.cl/product-brand/ivrea-argentina/` retorna `HTTP/1.1 301` con `Location: https://akibara.cl/marca/ivrea-argentina/` en un solo hop.
- **Test plan:** curl local → deploy → curl prod → GSC URL Inspection sobre `/product-brand/ivrea-argentina/` cambia de "Page with redirect" a que el redirect apunte correctamente. LiteSpeed purge de `/product-brand/*` post-deploy. No requiere LambdaTest (no visual).

---

### F-02: Home sin rich results — Organization schema suprimida
- **Viable como propuesto?** Si, con ajuste en el filter signature
- **Mejor path técnico:** La propuesta de mesa-12 es correcta en concepto pero tiene un detalle en la firma del filtro. El filter `rank_math/json_ld` en Rank Math Free acepta `($data, $jsonld)` donde `$jsonld` es el objeto `RankMath\JsonLD\JsonLD`. La implementación propuesta usa `2` como accepted args — correcto. Sin embargo, el nodo se agrega como `$data['AkibaraOrg']` (string key), mientras que el `rank_math/json_ld` filter en Rank Math Free itera con `foreach ($data as $key => $entity)` donde las keys son strings. Esto es compatible. Confirmado por el código existente en `schema-organization.php:36` que ya usa `add_filter('rank_math/json_ld', ..., 99, 2)` con el mismo patrón — el filter ya existe y funciona en prod. La adición del nodo Organization al `$data` array dentro del mismo filtro (extendiendo el existente) es el path más limpio, ya que evita registrar dos callbacks al mismo filtro con diferente priority.

  Path recomendado: extender el filtro existente en `schema-organization.php` líneas 36-88 para agregar la lógica de Organization cuando no existe, en vez de registrar un segundo `add_filter('rank_math/json_ld', ...)`. Dos callbacks al mismo hook con priority 99 pueden causar confusión en debug futuro. Fusionar en un solo callback.

- **Dependencias técnicas:** El guard `if (defined('RANK_MATH_VERSION')) return;` en línea 9 de `schema-organization.php` se aplica solo al `wp_head` action de líneas 7-31. El filtro `rank_math/json_ld` en líneas 36-88 NO tiene ese guard — ya corre en producción con Rank Math activo. La adición del nodo Organization debe ir dentro del filtro existente (líneas 36-88), en el bloque `if (is_front_page())` que ya existe (líneas 77-85). El `@id` `home_url('/#organization')` ya es referenciado en `schema-product.php:39` — el link entre nodos funcionará automáticamente.
- **Riesgos WP:** Medio. Este filtro ya hace manipulaciones de string con `json_decode`/`json_encode` (líneas 39-70) que son frágiles si el schema de Rank Math cambia. Agregar el nodo Organization debe hacerse ANTES del `json_decode` (es decir, trabajar sobre `$data` array directamente, no sobre el JSON string). La propuesta de mesa-12 trabaja sobre `$data` directamente — correcto. LiteSpeed cachea el HTML de la home incluyendo el `<head>` — necesita purge full de la home post-deploy para que Google lo vea.
- **Effort revisado:** 1.5h (30 min editar el filtro existente en schema-organization.php + 30 min test curl grep JSON-LD + 30 min LiteSpeed purge + verificación). Ligeramente menos que el estimado de 2h de mesa-12.
- **Acceptance criteria técnico:** `curl https://akibara.cl/ | python3 -m json.tool` sobre los bloques `application/ld+json` muestra al menos un nodo con `"@type": "Organization"` y `"@id": "https://akibara.cl/#organization"`. GSC Rich Results Test sobre `/` pasa de `None` a `Organization` detectado.
- **Test plan:** Editar → docker lint → deploy → LiteSpeed full purge home → curl grep → GSC Rich Results Test URL. No requiere LambdaTest.

---

### F-03: page-sitemap.xml — 22 warnings, páginas noindex en sitemap
- **Viable como propuesto?** Si
- **Mejor path técnico:** El filter `rank_math/sitemap/exclude_post` con signature `($exclude, $post_id)` es correcto para Rank Math Free. Confirmado: `noindex.php:79` ya usa `rank_math/sitemap/exclude_taxonomy` con el mismo patrón de 2 args — la API es consistente. La lista de slugs propuesta (`encargos`, `cart`, `checkout`, `mi-cuenta`, `order-received`) es razonable. Agregar el filter a `noindex.php` junto al filtro de taxonomías existente (línea 79) es el lugar semánticamente correcto.

  Un ajuste recomendado: antes de hardcodear slugs, verificar cuáles páginas realmente aparecen en `page-sitemap.xml` con `curl https://akibara.cl/page-sitemap.xml`. El audit de mesa-12 infiere los slugs por lógica — si hay páginas con noindex en Rank Math meta box que no están en el array hardcodeado, el warning persistirá. Alternativamente, el filter puede verificar el meta de Rank Math directamente: `get_post_meta($post_id, 'rank_math_robots', true)` contiene `['noindex']` cuando está marcado como noindex en Rank Math. Este approach es más robusto que mantener un array de slugs.

- **Dependencias técnicas:** Después de agregar el filter, Rank Math requiere regenerar el sitemap manualmente (Rank Math > Sitemap > Regenerate o `wp rank-math sitemap regenerate`). LiteSpeed cachea sitemaps — necesita purge de `/page-sitemap.xml` después de regenerar. Sin dependencias de plugins adicionales.
- **Riesgos WP:** Bajo. El filter solo afecta generación del sitemap XML, no el comportamiento del sitio. Riesgo de remoción excesiva si se agregan páginas indexables al array hardcodeado por error — mitigado usando la verificación via `rank_math_robots` meta en vez de slug array.
- **Effort revisado:** 1.5h (30 min curl sitemap para identificar qué páginas tienen warnings + 30 min código filter + 30 min regenerar + verificar en GSC). Estimado mesa-12 de 2h es conservador pero razonable.
- **Acceptance criteria técnico:** `curl https://akibara.cl/page-sitemap.xml | grep -c '<loc>'` retorna el número esperado de páginas indexables (sin encargos, cart, checkout, mi-cuenta). GSC Sitemaps > page-sitemap.xml muestra 0 warnings en el siguiente crawl.
- **Test plan:** curl del sitemap pre-cambio → agregar filter → regenerar sitemap → curl post → submit en GSC → monitorear 48-72h.

---

### F-04: Brand pages — title optimization + redirect (mismo fix F-01)
- **Viable como propuesto?** Con ajuste
- **Mejor path técnico:** La parte del redirect está cubierta por F-01 (misma línea .htaccess). Para el title, la propuesta de mesa-12 sugiere cambiar en `meta.php` el filter `document_title_parts` (líneas 193-202). Este filter tiene `add_filter('document_title_parts', ...)` sin priority explícita (default 10). Sin embargo, Rank Math también filtra el title via `rank_math/frontend/title` y `wp_title`. En producción con Rank Math activo, el `document_title_parts` filter del tema puede no ser respetado si Rank Math genera el `<title>` tag directamente via su propio hook.

  Verificar cuál filter aplica: si el title actual en SERP es "Ivrea Argentina — Manga y Cómics en Chile" y ese string aparece en `meta.php:197`, entonces `document_title_parts` sí funciona (Rank Math lo respeta para la tag `<title>`). En ese caso la propuesta es viable.

  El cambio de title propuesto `'Comprar ' . $term->name . ' en Chile | Akibara — ' . $term->count . ' títulos'` puede superar 60 chars para editoriales con nombres largos. Ejemplo: "Comprar Milky Way Ediciones en Chile | Akibara — 47 títulos" = 61 chars (OK). "Comprar Planeta Cómic (España) en Chile | Akibara — 12 títulos" = 62 chars (borderline). Propuesta alternativa más segura: `$term->name . ' en Chile — ' . $term->count . ' títulos | Akibara'` que para "Ivrea Argentina" queda en 48 chars y no empieza con "Comprar" (que puede sonar spam en títulos de categoría).

- **Dependencias técnicas:** F-01 es prerequisito. El fix del title en `meta.php` es independiente. El filter `document_title_parts` convive con Rank Math Free sin conflicto documentado.
- **Riesgos WP:** Bajo para el redirect (cubierto en F-01). Para el title: riesgo de que el cambio afecte a todas las páginas `product_brand`, no solo ivrea. Hay que verificar que ninguna brand tenga title personalizado en Rank Math meta box que este override pueda invalidar. Si una brand tiene title hardcodeado en Rank Math post meta (`rank_math_title`), el `document_title_parts` filter podría sobreescribirlo — depende del order de execution. Rank Math aplica su title antes de `document_title_parts`.
- **Effort revisado:** 2h total (30 min para confirmar cuál filter aplica en prod + 30 min ajustar el formato de title + 30 min QA en staging + 30 min verificar longitudes para todos los brands activos).
- **Acceptance criteria técnico:** `curl https://akibara.cl/marca/ivrea-argentina/ | grep '<title>'` retorna el nuevo formato. Longitud <60 chars para los 5 brands con más productos.
- **Test plan:** Verificar en staging antes de prod. No requiere LambdaTest.

---

### F-05: Desktop CTR 1.77% — sitelinks y meta optimización
- **Viable como propuesto?** Con ajuste — es un finding de análisis, no de código directo
- **Mejor path técnico:** La propuesta de mesa-12 tiene tres vectores: (a) auditar meta description lengths en GSC, (b) ajustar title de home, (c) verificar si Organization schema activa sitelinks. El vector (c) depende de F-02 — correcto, son prerequisitos. Para (a) y (b): la meta description de home ya está hardcodeada en `meta.php:390` con 128 chars — no es el problema de truncado. El title de home no es configurable en theme code directamente sin un filter `rank_math/frontend/title` para `is_front_page()`.

  Sobre sitelinks search box: Rank Math Free emite `WebSite` schema con `SearchAction` automáticamente en la home — esto es lo que habilita el Sitelinks Search Box en SERP, no Organization. El F-02 (Organization) habilita Knowledge Panel y brand recognition, no sitelinks. La propuesta mezcla dos cosas distintas.

  Acción concreta viable: (1) Confirmar que F-02 está implementado (prerequisito). (2) Para el title de home, verificar qué emite Rank Math actualmente — puede configurarse en WP Admin > Rank Math > Titles & Meta > Home. Si el title actual no incluye "+1.300 títulos", se puede agregar directamente desde admin sin tocar código.

- **Dependencias técnicas:** F-02 es prerequisito para el vector Knowledge Panel/sitelinks. La meta description de home ya está optimizada en código. El title de home es editable vía admin Rank Math sin deploy de código.
- **Riesgos WP:** Bajo. Cambios en admin Rank Math no requieren deploy. El WebSite schema con SearchAction ya existe — no hay código nuevo que agregar para sitelinks.
- **Effort revisado:** 1h (30 min confirmar Rank Math admin title + ajuste + 30 min verificar GSC). Estimado de 4h de mesa-12 es excesivo para lo que queda después de resolver F-02.
- **Acceptance criteria técnico:** F-02 implementado. Title de home en SERP incluye propuesta de valor ("+1.300 títulos" o similar). Monitorear CTR desktop en GSC a 30 días.
- **Test plan:** Admin-only — no requiere deploy de código.

---

### F-06: /manhwa/ — meta optimización
- **Viable como propuesto?** Con ajuste importante — la página `/manhwa/` es `product_cat`, no page custom
- **Mejor path técnico:** Confirmado por el código: `header.php:235` usa `is_product_category('manhwa')` — la URL `/manhwa/` es un archivo de taxonomía `product_cat`. Esto significa que: (1) `meta.php:24-30` ya tiene handler `is_product_category()` que emite description desde `$term->description`. (2) `category-intro.php:61` ya tiene texto SEO específico para el slug `manhwa` con las palabras clave "manhwa", "tienda manhwa", "leer manhwa online en español". (3) El title viene del filter `document_title_parts` de Rank Math (nombre del término).

  La propuesta de mesa-12 de agregar a `$fallbacks` en `meta.php` está equivocada — ese array es para páginas (`is_page()`), no para categorías. Para categorías, la description viene de `$term->description` (configurable en WC admin) o del filter `rank_math/frontend/description`. Si `/manhwa/` no tiene description en Rank Math meta box de término, la description que emite es la de `$term->description` de WooCommerce (probablemente vacía).

  Fix correcto: verificar si la categoría `manhwa` tiene description configurada en WC Admin > Products > Categories > manhwa. Si está vacía, agregar ahí. Si Rank Math está activo, la description también puede configurarse en Rank Math > Títulos & Meta > WooCommerce. Esto no requiere deploy de código. Si se quiere asegurar vía código: agregar `elseif (is_product_category('manhwa'))` en el handler existente de `meta.php:24` como caso especial.

- **Dependencias técnicas:** Determinar si `/manhwa/` tiene description en WC term meta. Si no, la fix es en WC Admin (0 código). Si se prefiere código, es una línea adicional en `meta.php`.
- **Riesgos WP:** Bajo. El handler `is_product_category()` está bajo el guard `if (defined('RANK_MATH_VERSION')) return;` en línea 9 de `meta.php` — lo que significa que este handler NUNCA corre en producción con Rank Math activo. El fix correcto es via el filter `rank_math/frontend/description` que sí corre (ya existe en meta.php líneas 256-293 para pages, el pattern puede extenderse para `is_product_category('manhwa')`).
- **Effort revisado:** 30 min (verificar WC term description → si vacío, agregar via admin o 3 líneas de código). Estimado mesa-12 de 1h es suficiente pero la mayoría es investigación ya resuelta aquí.
- **Acceptance criteria técnico:** `curl https://akibara.cl/manhwa/ | grep 'name="description"'` retorna una meta description con "manhwa" prominente y <160 chars.
- **Test plan:** Curl pre y post cambio. No requiere LambdaTest.

---

### F-07: Blog posts FAQ schema — H2 interrogativos
- **Viable como propuesto?** Bloqueado por dependencia editorial
- **Mejor path técnico:** El código en `schema-article.php:317-401` ya existe y funciona correctamente — extrae H2 interrogativos automáticamente. La function `akibara_blog_build_faq_node()` detecta H2 con "?" o interrogativas españolas ("qué", "cómo", "cuándo", etc.). El código es sólido y no requiere cambios. El único prerequisito es que los posts tengan H2 con formato de pregunta. Esta es una acción editorial 100%, no de código.
- **Dependencias técnicas:** REQUIERE mesa-06 (editorial/copy) para editar los posts existentes. No hay código que implementar.
- **Riesgos WP:** Ninguno en el código. Riesgo editorial: agregar H2 interrogativos a posts existentes puede cambiar la estructura de heading para SEO (H2 es señal de relevancia). Si el post tiene H2 de sección ("Personajes principales") y se añade "¿Cuántos tomos tiene Atelier of Witch Hat?" como primer H2, cambia la jerarquía de contenido. Esto es decisión del editor, no del developer.
- **Effort revisado:** 0h desarrollo. 2-4h editorial (mesa-06).
- **Acceptance criteria técnico:** `curl https://akibara.cl/atelier-of-witch-hat-la-obra-maestra-de-kamome-shirahama/ | python3 -m json.tool` (sobre los bloques ld+json) muestra un nodo `@type: FAQPage` con al menos 2 pares Q&A.
- **Test plan:** Post-edición editorial, verificar schema con curl + GSC Rich Results Test.

---

### F-08: /nosotros/ — agregar a fallbacks meta description
- **Viable como propuesto?** Si, exactamente como propuesto
- **Mejor path técnico:** La propuesta es agregar `'nosotros'` al array `$fallbacks` en `meta.php:262`. El array existe, el pattern es idéntico a las otras 5 entradas. Literalmente 2 líneas de cambio. El filter `rank_math/frontend/description` (líneas 256-269) solo aplica cuando `$desc` está vacío — si `/nosotros/` ya tiene description en Rank Math meta box, este fallback no sobreescribe nada. Sin riesgo de regresión.
- **Dependencias técnicas:** Ninguna. El filter ya existe y funciona correctamente en producción.
- **Riesgos WP:** Mínimo. El único escenario de problema es si en el futuro `/nosotros/` obtiene una description manual en Rank Math — en ese caso el fallback queda inerte (correcto, por la condición `if (!empty($desc)) return $desc`).
- **Effort revisado:** 15 min. Estimado de 1h de mesa-12 incluye tiempo de análisis ya hecho aquí.
- **Acceptance criteria técnico:** `curl https://akibara.cl/nosotros/ | grep 'name="description"'` retorna la description con "tienda chilena de manga" y diferencia de la homepage.
- **Test plan:** Curl pre/post. No requiere deploy especial.

---

### F-09: IndexNow — key hardcodeada en mu-plugin
- **Viable como propuesto?** Si
- **Mejor path técnico:** La propuesta de mover a `wp-config-private.php` es correcta y consistente con la política del proyecto (`project_no_key_rotation_policy`). Confirmar que `wp-config-private.php` ya existe (fue creado en Sprint 1 para BlueX y otras keys). Si ya existe: agregar `define('AKB_INDEXNOW_KEY', 'REDACTED-INDEXNOW-KEY');` y en el mu-plugin reemplazar `const AKIBARA_INDEXNOW_KEY = 'REDACTED-INDEXNOW-KEY';` por `define('AKIBARA_INDEXNOW_KEY', defined('AKB_INDEXNOW_KEY') ? AKB_INDEXNOW_KEY : 'REDACTED-INDEXNOW-KEY');`. El fallback hardcodeado garantiza que si `wp-config-private.php` no carga, el mu-plugin no rompe.

  Nota: IndexNow keys son públicas por diseño (Google las fetcha via `/{key}.txt`). La migración es por consistencia arquitectural y buenas prácticas, no por riesgo real de seguridad. Esto alinea con el análisis de mesa-12.

- **Dependencias técnicas:** `wp-config-private.php` debe existir y ser incluido en `wp-config.php`. Verificar antes de implementar.
- **Riesgos WP:** Mínimo. El fallback en el define evita rotura si hay orden de carga incorrecto. La constante `AKIBARA_INDEXNOW_KEY` ya es usada en 3 lugares del mu-plugin (líneas 33, 58, 83) — cambiar a `define` en vez de `const` hace que esté disponible globalmente sin necesidad de namespace.
- **Effort revisado:** 30 min. Consistente con estimado mesa-12.
- **Acceptance criteria técnico:** `curl https://akibara.cl/REDACTED-INDEXNOW-KEY.txt` retorna `REDACTED-INDEXNOW-KEY` (la key sigue funcionando). `grep INDEXNOW_KEY wp-config-private.php` retorna la define.
- **Test plan:** Deploy → curl key verification endpoint → confirm IndexNow submissions siguen funcionando con WP-CLI o editando un post.

---

### F-10: Autor pages — meta description para pa_autor
- **Viable como propuesto?** Si, con confirmación del tipo de taxonomía
- **Mejor path técnico:** La propuesta es agregar `elseif (is_tax('pa_autor'))` en `meta.php`. Confirmado: `pa_autor` es una taxonomía WooCommerce product attribute (prefijo `pa_`). El handler de product_brand ya existente en `meta.php:31-34` es el template exacto a replicar. Para schema: `schema-collection.php:129-150` ya tiene CollectionPage para `is_tax('product_brand')` — como señala mesa-12, la lógica es idéntica para `pa_autor`.

  Sin embargo, hay un problema: los handlers en `meta.php` para `is_shop()`, `is_product_category()`, `is_tax('product_brand')` están dentro del bloque `wp_head` action con guard `if (defined('RANK_MATH_VERSION')) return;` en línea 9 — lo que significa que NUNCA corren en producción. El fix real debe usar el filter `rank_math/frontend/description`, igual que el pattern en líneas 256-269 (description fallback para pages). Para pa_autor, agregar un nuevo filter o extender el existente.

- **Dependencias técnicas:** La meta description debe ir en `rank_math/frontend/description` filter, no en el wp_head action (que tiene el Rank Math guard). El schema CollectionPage puede ir en `schema-collection.php` extendiendo el handler de product_brand existente.
- **Riesgos WP:** Bajo. `is_tax('pa_autor')` solo aplica en archivos del atributo autor — sin riesgo de afectar otros contextos.
- **Effort revisado:** 1.5h (30 min para el filter de description + 30 min para schema CollectionPage + 30 min curl QA). Consistente con estimado de 2h de mesa-12.
- **Acceptance criteria técnico:** `curl https://akibara.cl/autor/tatsuya-endo/ | grep 'name="description"'` retorna description con "manga de Tatsuya Endō". Schema CollectionPage en ld+json.
- **Test plan:** Curl pre/post en staging. Verificar que no rompe otras páginas de atributos (`pa_serie`, `pa_genero`).

---

### F-11: Hreflang con query string en páginas filtradas
- **Viable como propuesto?** Si, con aclaración de alcance real
- **Mejor path técnico:** El análisis de mesa-12 identifica correctamente el problema. El código actual en `canonical.php:70-76` usa `$_SERVER['REQUEST_URI']` directamente y limpia solo `?` params via `strtok`. Para paths de filtros WC como `/tienda/attribute/genero/accion/`, no hay `?` pero sí path segments que no son parte del canonical base.

  La propuesta de usar `apply_filters('rank_math/frontend/canonical', ...)` como base es correcta. Sin embargo, hay un riesgo adicional: el filter `rank_math/frontend/canonical` puede retornar un string vacío en algunos contexts (404, search) o puede diferir de `get_permalink()`. El fallback `get_permalink() ?: home_url('/')` en la propuesta es adecuado.

  Verificar previamente: `canonical.php:7-16` tiene un `wp_head` action que emite canonical propio con guard `RANK_MATH_VERSION` — ya está desactivado en producción. El hreflang en líneas 70-76 NO tiene ese guard. Confirmar con `curl https://akibara.cl/ | grep hreflang` que no hay duplicados (Rank Math Free no emite hreflang por default — hipótesis de mesa-12 corroborada por el código comentado en `functions.php:322`).

- **Dependencias técnicas:** El filter `rank_math/frontend/canonical` debe estar disponible en el contexto de wp_head action a priority 1 (donde corre el hreflang actual). Rank Math registra sus canonicals antes de wp_head por lo que el filter debe retornar el valor correcto. Cambiar priority de `wp_head` hreflang de `1` a `25` (como propone mesa-12) es compatible con el resto del SEO stack.
- **Riesgos WP:** Bajo. El hreflang es `es-CL` + `x-default` apuntando al mismo URL — sitio monolingue, así que el riesgo de hreflang incorrecto es menor que en sitios multilingues. El cambio mejora consistencia con canonical pero no tiene impacto dramático para sitios monolingues.
- **Effort revisado:** 1.5h (30 min editar función + 30 min verificar curl en URLs con filtros WC + 30 min staging). Consistente con estimado de 2h de mesa-12.
- **Acceptance criteria técnico:** `curl "https://akibara.cl/tienda/attribute/pa_genero/accion/" | grep hreflang` retorna URL canónica de `/tienda/` (sin path de filtro). `curl https://akibara.cl/ | grep -c hreflang` retorna exactamente 2 (es-CL y x-default, sin duplicados).
- **Test plan:** curl en URLs con filtros WC activos. Verificar que URLs normales (home, productos, categorías) siguen retornando hreflang correcto.

---

## Riesgos cross-cutting

**LiteSpeed cache invalidation:** LiteSpeed 7.8.1 cachea HTML completo incluyendo `<head>` con ld+json. Cualquier cambio a Organization schema (F-02), hreflang (F-11), meta descriptions (F-08, F-10), o sitemaps (F-03) requiere purge de LiteSpeed post-deploy. Para F-02 (homepage) el purge es crítico — sin purge Google sigue viendo el HTML cacheado sin Organization schema. Procedimiento: WP Admin > LiteSpeed Cache > Manage > Purge All, o `bin/wp-ssh lscache-purge --all`.

**Guard `if (defined('RANK_MATH_VERSION')) return;` en meta.php línea 9:** Este guard afecta los handlers de meta description para `is_shop()`, `is_product_category()`, `is_tax('product_brand')`, `is_product()` y `is_singular('post')` definidos en el primer bloque `wp_head` de meta.php (líneas 7-56). NINGUNO de esos handlers corre en producción. Los fixes de F-06, F-10 y similares deben ir en los filters `rank_math/frontend/description` (que ya existen en meta.php y SÍ corren), no en el bloque wp_head con guard. Mesa-12 no parece haber detectado este guard — es la corrección técnica más importante de esta validación.

**Hostinger shared hosting:** No hay limitaciones que afecten ninguno de los 11 findings. Las RewriteRules propuestas son Apache estándar soportadas por Hostinger. Los filters PHP son livianos. El sitemap regeneration es operación normal de Rank Math. Sin necesidad de root access para ningún item.

**Hooks priority conflicts:** El filter `rank_math/json_ld` ya usa priority 99 en `schema-organization.php`. Agregar la lógica de Organization dentro de ese mismo callback es correcto. Registrar un segundo callback al mismo filter con priority 99 (como propone mesa-12) es técnicamente válido pero confuso — consolidar en un callback.

**WP_DEBUG y staging:** Ninguno de los 11 findings genera PHP warnings/notices visibles en producción si se implementan correctamente. Los is_tax(), is_front_page(), is_product_category() son funciones WP estándar con return type bool en todos los contexts.

---

## Items que requieren NO-código

- **F-07 (FAQ schema blog):** 100% editorial. Requiere mesa-06 para editar H2 de posts existentes. El código de `schema-article.php` ya está listo y funcionando.
- **F-05 (Desktop CTR — title home):** El title de la home puede configurarse directamente en Rank Math Admin > Titles & Meta > Home sin ningún deploy de código.
- **F-06 (/manhwa/ meta):** Si la categoría manhwa tiene description vacía en WC Admin, la fix es en WC Admin > Products > Categories > Manhwa (campo Description). 0 código. Solo requiere código si se prefiere mantener el control en theme.

Ningún finding requiere upgrade a Rank Math Premium. Todos son implementables con Rank Math Free + theme custom hooks.

---

## Effort estimate sumado

| Finding | Effort Dev | Effort QA | Sprint |
|---|---|---|---|
| F-01 | 30 min | 15 min | S4 |
| F-02 | 60 min | 30 min | S4 |
| F-03 | 60 min | 30 min | S4 |
| F-04 | 60 min | 30 min | S4 |
| F-05 | 15 min (admin) | 15 min | S4 |
| F-06 | 15 min (admin o 15 min código) | 15 min | S4 |
| F-07 | 0 min (código listo) | 30 min post-editorial | S4+ (mesa-06) |
| F-08 | 15 min | 15 min | S4 |
| F-09 | 30 min | 15 min | parking-lot |
| F-10 | 90 min | 30 min | S4+ |
| F-11 | 90 min | 30 min | S4+ |

- **Total hours dev estimado:** ~7.5h
- **Total hours QA/testing:** ~3.5h
- **Total combinado:** ~11h

**Sprint 4 fit estimate:** F-01, F-02, F-03, F-04, F-05, F-06, F-08 caben en un bloque SEO de ~5-6h dev efectivas. F-07 depende de mesa-06 (no bloquea Sprint 4). F-10, F-11 pueden ir en S4 o como rolling items. F-09 es parking-lot (impacto SEO nulo, solo limpieza arquitectural).

**Prerequisito crítico antes de cualquier deploy:** Purge LiteSpeed post-cambios en schema/meta. Agregar como paso obligatorio en el checklist de deploy para items SEO.
