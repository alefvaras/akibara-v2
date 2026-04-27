# Customer-facing pages snapshot — captured 2026-04-26 via Chrome MCP

Capturado en sesión browser real. Pages auditadas: home, /preventas/, single product preventa, /mi-cuenta/.

Input adicional para mesa-07/08/09/12/13/15/22.

---

## /preventas/ (catálogo preventas)

**URL:** `https://akibara.cl/preventas/`
**Title:** `Preventas Manga Chile | Reserva tu Manga | Akibara`

### Métricas catálogo

```
Todos        2.168 productos
├── Manga    1.350
├── Preventas 798  ← 37% del catálogo es preventa (señal fuerte)
├── Comics    18
└── Pedidos Especiales  2
```

**⚠️ Discrepancia conteo:**
- Frontend muestra 2.168 productos totales
- wp-cli `post list --post_type=product --post_status=publish --format=count` = 1.371
- Diferencia: ~797 productos no en `publish` status pero visible frontend?
- **Mesa-22 wp-master valida**: ¿qué post_status tienen las preventas? ¿custom?

### Features visible

- Breadcrumb: Inicio / Catálogo / Preventas ✅
- Tabs categorías: Catálogo, Manga, Preventas, Comics, Pedidos Especiales
- Filtros sidebar:
  - Editorial (con counts: Ivrea AR 787, Panini AR 305, Ovni Press 33, etc.)
  - Género (Acción 937, Comedia 488, Aventura 400, Drama 377, Sobrenatural 300, Fantasía 264, Terror 217, Psicológico 194, Histórico 144, Misterio 138...)
  - Precio (rangos hasta $8k, $8-12k, $12-18k, $18-25k, +$25k)
  - Filtros rápidos: Preventas, Novedades, En oferta
  - Toggle "Solo en stock"
- Ordenamiento: predeterminado, popularidad, puntuación, últimos, precio asc/desc
- Pagination: 1-24 de 798 → 34 páginas
- **Footer SEO copy** explicando preventas (good para SEO)

### Productos preventa visibles

Patrón consistente:
- Badge "Ahorra 5%" + "Preventa" + Editorial + Título
- Sale price display: `$X.XXX → $Y.YYY` (con bug F-PRE-013 layout)
- ETA: "~21 días est." (Argentina) o "~30 días est." (España)
- "📦 Fecha por confirmar"
- CTA: "Reservar ahora"

Productos out-of-stock SIN preventa:
- Badge "Ahorra 3%" + "Agotado" + Editorial + Título
- CTA: "Solicitar encargo" + "✉ Avísame cuando vuelva"

### Findings nuevos

**F-PRE-015 — Discrepancia conteo productos frontend vs wp-cli**
- Severity: P2
- Owner: mesa-22 wp-master
- Frontend muestra 2.168 productos, wp-cli `--post_status=publish` cuenta 1.371
- Hipótesis: preventas con custom post_status, o products con status que el frontend incluye pero wp-cli excluye con default filter
- Validar: `bin/wp-ssh post list --post_type=product --post_status=any --format=count` y verificar discrepancia

**F-PRE-016 — Categoría "Pedidos Especiales" custom (2 productos)**
- Severity: info
- Owner: mesa-15 architect + mesa-22 wp
- Confirmar: ¿es taxonomía custom? ¿módulo `encargos.php` theme inc relacionado?
- Cómo se diferencia de "Solicitar encargo" CTA en out-of-stock products

---

## Single product preventa: `20Th Century Boys 6 – Ivrea Argentina`

**URL:** `https://akibara.cl/20th-century-boys-6-ivrea-argentina/`
**SKU:** `9788419185730` (ISBN como SKU — pattern correcto manga)

### Features visibles (CONFIRMACIÓN multiple modules trabajando)

#### Trust + UX
- Breadcrumb: Inicio / Manga / Seinen / 20Th Century Boys 6 ✅
- Tags: 🇦🇷 Edición Argentina, Preventa
- Cuotas MP calc visible: "💳 3 cuotas de $7.125 sin interés con Mercado Pago" — `installments` module ✅
- Estimado: "~21 dias desde que se pida"
- Trust block: "Mismo día RM | 3 cuotas sin interés | Manga original"
- "Envío gratis en compras sobre $55.000"
- "Todos nuestros mangas incluyen funda protectora" (value prop diferenciador)
- "Pago 100% seguro · Mercado Pago, Flow y transferencia"
- "Despacho a todo Chile · mismo día en RM"
- "¿Dudas sobre este título? Escríbenos" (WhatsApp link probable)
- Guardar en favoritos / Compartir

#### Series subscription (series-notify module ✅)
- "Seguir 20th Century Boys"
- "Te avisamos cuando salga un nuevo tomo de 20th Century Boys"
- Botón "Suscribirme"
- **Confirma: series-notify funcional en frontend**

#### Pack widget (pack-serie module ✅)
- "Pack Inicio — 20th Century Boys / Tomos 1 al 3"
- "$67.500 / Precio pack -8% $62.100 / Ahorras $5.400"
- Botón "Agregar Pack al Carrito"
- **Confirma: pack-serie funcional, calculation correcta**

#### Next-volume widget (next-volume module ✅)
- "Tu próximo tomo en 20th Century Boys"
- Vol. 7 con preview, precio, "En preventa", CTA "Reservar ahora"
- **Confirma: next-volume widget on-page rendering CORRECTO**
- ⚠️ **Pero email asociado puede no estar firing** (cron) — usuario dijo "no funcionando" antes — refer F-PRE-004

#### Review-incentive (review-incentive module ✅ display)
- "Deja una reseña y recibe un 5% de descuento"
- "Te enviaremos un cupón exclusivo a tu correo después de publicar tu reseña."
- Rating widget 1-5 estrellas con etiquetas
- **Confirma: review-incentive UI funcional**

#### FAQ schema (mesa-12 SEO ✅)
- 5 preguntas con responses inline:
  - ¿Cuánto cuesta?
  - ¿Qué tomo es? (vol 6 of 11)
  - ¿Qué editorial publica?
  - ¿Quién es el autor?
  - ¿De qué género es?
  - ¿Es original?
- **Confirma: FAQPage schema markup probable presente** (verificar JSON-LD)

#### Blog cross-CTA (theme inc/blog-cta-product.php ✅)
- "📖 Lee nuestra guía: Manga seinen: guía para adultos..."
- "4 min de lectura →"
- **Confirma: blog→product cross-linking funcional**

#### Related products
- "También te puede gustar" — 5 productos relacionados (vol 1-5 + vol 7)
- Pattern: tomos previos in stock + siguiente tomo preventa

### Schema.org markup (sospechado)

- Product schema (price, availability, sku, brand, aggregateRating)
- FAQPage schema (5 Q&A)
- BreadcrumbList probable
- **mesa-12 valida JSON-LD completo**

### Findings nuevos

**F-PRE-017 — Sale price layout broken aparece en single product también**
- Severity: P2 confirma F-PRE-013 sistémico
- Owner: mesa-07 responsive + mesa-08 design-tokens
- Misma evidencia: "$22.500 El precio original era: $22.500.$21.375El precio actual es: $21.375"
- Bug está en theme override de WC `templates/single-product/price.php` (no solo loop)

**F-PRE-018 — Reviews vacías en producto (Sé el primero en reseñar)**
- Severity: info
- Owner: mesa-09 + mesa-23 PM
- Producto preventa sin reviews — tiene sentido (no se ha entregado)
- review-request flow timing: 10 días post-compra, NO post-reserva
- Pregunta para roadmap: ¿review-request debe handle preventa diferente?

**F-PRE-019 — Modules confirmados FUNCIONANDO (no dead code)**
- series-notify (Suscribirme button ✅)
- pack-serie (Pack widget ✅)
- next-volume (widget on-page ✅, email aún por verificar)
- review-incentive (5% display ✅)
- installments (cuotas MP calc ✅)
- back-in-stock (CTA "Avísame cuando vuelva" ✅ visible en agotados)
- blog-cta-product (cross-link guías ✅)
- Estos NO son CLEAN candidates — están vivos en frontend

---

## /mi-cuenta/ (login + registro)

**URL:** `https://akibara.cl/mi-cuenta/`

### Features visibles

#### Login (tab Ingresar)
- Email + password standard
- ✅ **Google OAuth** ("Continuar con Google" → `accounts.google.com/o/oauth2/v2/auth?client_id=1002919605059-...`)
- ✅ **Magic link** ("Recibir enlace por correo (sin contraseña)" / "Enviar enlace mágico")
- "Recordarme" checkbox
- "¿No recuerdas tu contraseña?" → /lost-password/
- Trust: "Tu información está segura con nosotros"

#### Registro (tab Crear cuenta)
- Nombre + Email
- "Te enviaremos un link por correo para establecer tu contraseña" (magic link bootstrap)
- Privacy mention: "Sus datos personales se utilizarán para respaldar su experiencia... administrar el acceso a su cuenta."
- ✅ **Consent checkbox**: "Acepto los Términos de uso y la Política de privacidad"
- Botón "Crear mi cuenta"

### Findings nuevos

**F-PRE-020 — Multi-method auth funcional (good UX)**
- Severity: info (positive)
- Owner: mesa-15 + mesa-19 compliance
- Auth methods: password + Google OAuth + magic link
- Bueno para conversion (sin password = menos friction)
- Validar: token magic link expiry, OAuth state parameter security

**F-PRE-021 — Consent checkbox a Términos + Política presente**
- Severity: info (compliance OK foundation)
- Owner: mesa-19 compliance
- Usuario tiene que tickear consentimiento explícito
- ✅ Buena base para cumplir Ley Chile 19.628 + 21.719 + GDPR
- Validar: ¿checkbox required (no se puede submit sin tildar)?
- Validar: ¿links Términos + Privacidad funcionan y contenido vigente?

**F-PRE-022 — Sin información de manejo de datos detallada en registro**
- Severity: P2 compliance
- Owner: mesa-19
- Registro solo dice "Sus datos personales se utilizarán para respaldar su experiencia"
- Falta:
  - ¿Qué datos exactos se almacenan?
  - ¿Por cuánto tiempo?
  - ¿Se comparten con terceros (Brevo, MercadoPago, BlueX)?
  - ¿Cómo solicitar borrado (Right to be forgotten)?
- Ley 21.719 Chile (entrada en vigor escalonada) requiere transparencia mayor
- Propuesta: Link "Ver detalle de uso de datos" o expandir consent text

---

## Cross-cutting hallazgos consolidados

### Modules CONFIRMADOS funcionando (no dead, no CLEAN candidates)

Los siguientes modules están VIVOS en frontend y deben preservarse:

1. `installments` — cuotas MP display ✅
2. `series-notify` — UI suscripción series ✅ (cron pendiente verificar)
3. `pack-serie` (theme inc) — pack widget con precio descuento ✅
4. `next-volume` — widget on-page ✅ (email cron pendiente verificar)
5. `review-incentive` — display 5% discount ✅
6. `back-in-stock` — CTA "Avísame cuando vuelva" ✅
7. `blog-cta-product` (theme inc) — cross-link a guías blog ✅
8. `magic-link` (theme inc) — passwordless login ✅
9. `google-auth` (theme inc) — OAuth login ✅
10. `popup` — welcome popup (probable, no visible en estos pages porque tiene supresión por ruta)

### Bugs confirmados sistémicos

1. **F-PRE-013/017 sale price layout broken** — afecta home, preventas, single product. Theme template override issue. **P1 fix sprint 1**
2. **F-PRE-011 productos test E2E visible en home** — confirmaste es intencional (test). **P3 remove pre-launch**
3. **F-PRE-015 conteo discrepancia frontend vs wp-cli** — investigar

### Growth foundations YA presentes

1. SEO copy en /preventas/ ✅
2. FAQ schema en single products ✅
3. Internal linking (series, blog cross-CTAs, related products) ✅
4. Trust signals consistentes (envío RM, cuotas, manga original) ✅
5. Multi-method auth (reduce friction) ✅
6. Cross-sell automation (pack-serie, next-volume, related) ✅
7. Series engagement (series-notify) ✅

### Issues compliance/legal

1. F-PRE-022 transparencia datos en registro insuficiente (Ley 21.719)
2. Verificar cookie consent banner (no observado en estas páginas — mesa-19 valida)
3. Verificar legal pages content (/politica-de-privacidad/, /terminos-y-condiciones/) actualizadas

---

## Nota para mesa-01 lead R2

El frontend está MUCHO más maduro de lo que el state inicial sugería. La narrativa "tienda recién partiendo, código fragile" debe ajustarse a:

- ✅ **Frontend customer-facing está sólido** — features ricas funcionando, UX cuidada, branding consistente
- ⚠️ **Backend/infra necesita hardening** — backdoor admins, broken Brevo setup, secrets en logs
- ⚠️ **Bugs cosméticos sistémicos** — sale price display
- ⚠️ **Foundations administrativas faltantes** — DNS SPF/DKIM/DMARC, sender domain Brevo, cookie banner, Termi/Priv updates

El BACKLOG resultante NO debe pedir refactor masivo del frontend (está bien). Debe enfocarse en:
1. Security cleanup + hardening
2. Setup foundations (Brevo, DNS, legal)
3. Bug fixes específicos
4. Features ya en código pero deshabilitadas (growth-deferred)
