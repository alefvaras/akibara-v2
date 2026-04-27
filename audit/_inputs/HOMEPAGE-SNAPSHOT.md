# Homepage akibara.cl snapshot — captured 2026-04-26 via Chrome MCP

Capturado via Claude in Chrome MCP en sesión browser real. Input para mesa-07 (responsive), mesa-08 (design tokens), mesa-09 (email-qa context), mesa-15 (architect customer-facing), mesa-23 (PM growth context).

## URL + título

- URL: `https://akibara.cl/`
- Title: `Akibara | Tu Distrito del Manga y Cómics en Chile`
- Viewport tested: desktop 1440x708 + mobile 390x844 (iPhone 14 Pro)

## Schema.org markup detectado

```json
{
  "@context": "https://schema.org",
  "@type": "Store",
  "name": "Akibara",
  "description": "Tu Distrito del Manga y Cómics en Chile. Las mejores series de manga japonés y cómics americanos.",
  "url": "https://akibara.cl",
  "image": "https://akibara.cl/wp-content/uploads/2026/04/akibara-hero-manga-comics-chile-desktop.jpeg",
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Manga y Cómics",
    "itemListElement": [
      { "@type": "Offer", "itemOffered": { "@type": "Product", "name": "Manga" } },
      { "@type": "Offer", "itemOffered": { "@type": "Product", "name": "Cómics" } }
    ]
  }
}
```

✅ **Bueno:** Schema.org Store + OfferCatalog + Product (mesa-12 SEO si estuviera en mesa, pero mesa-22 wp lo cubre).

## Estructura visible (desktop)

### Hero
- "Manga y Cómics en Chile — Explora nuestra tienda"
- CTAs: Manga / Cómics / Ver todo

### Trust badges (4)
- Envío a todo Chile (Mismo día en RM)
- 3 cuotas sin interés (Con Mercado Pago)
- Manga 100% original (Importación directa)
- Atención por WhatsApp (Respuesta rápida)

### Tagline
"Akibara | Tu Distrito de Cómics y Manga · El Akihabara chileno · envío a todo Chile"

### Secciones home
1. **Últimas llegadas** — 8 productos
2. **Preventas** — 7 productos con badges "Ahorra 5%", "📦 Fecha por confirmar", "~30 días est."
3. **Mas Vendidos** — 6 productos (mix regular + preorders)
4. **Empieza una Nueva Serie** — 6 starter manga (vol #1)
5. **De dónde viene tu manga** — editoriales con conteos:
   - Ivrea Argentina: 787 títulos
   - Panini Argentina: 305 títulos
   - Planeta España: 91 títulos
   - Milky Way: 63 títulos
   - Ivrea España: 34 títulos
   - Ovni Press: 33 títulos
   - Panini España: 32 títulos
   - Arechi Manga: 23 títulos
   - **Total visible: 1.368 títulos** (cuadra con 1.371 productos verificados via wp-cli)

## Navegación

- **Top:** Inicio, Manga, Cómics, Series, Preventas, Blog
- **User:** Mi cuenta, Favoritos, Carrito, Buscar
- ✅ Standard ecommerce nav, mobile responsive ya está pensado

## CTAs por tipo de producto

- En stock: "Agregar al carrito"
- Preventa: "Reservar ahora" (CTA distinto, bien UX)
- Out of stock: "Solicitar encargo" (custom encargos flow)

## Reviews

- Solo 1 producto con reviews visible: "El verano que Hikaru murió 1 – Milky Way ★★★★★ (1)"
- Confirma que el flujo review-incentive + review-request tiene 1 reseña real
- Path forward: cuando crezca, este sistema escala bien

## 🚨 ISSUES VISIBLES DESDE LA HOME (para R1 audit)

### F-PRE-011 — Productos TEST E2E visibles en home pública (P1)
- **Severity:** P1 (UX/marketing leak)
- **Owner:** mesa-22 wp-master + mesa-15 architect
- **Evidencia visible en home:**
  - "Últimas llegadas" muestra: `Disponible | Uncategorized | [TEST E2E] Producto Disponible – NO COMPRAR | $8.990 | Agregar al carrito`
  - "Preventas" muestra: `Ahorra 5% | Preventa | Uncategorized | [TEST E2E] Producto Agotado – NO COMPRAR | $11.391`
  - "Preventas" muestra: `Ahorra 5% | Preventa | Uncategorized | [TEST E2E] Producto Preventa – NO COMPRAR | $14.241`
- **Causa:** Productos test 24261/24262/24263 en categoría `Uncategorized` y `Preventas` aparecen en queries de home (últimas llegadas + preventas)
- **Riesgo:** Cliente real ve "TEST E2E - NO COMPRAR" en home → desconfianza, brand damage
- **Propuesta:** Excluir productos con SKU `TEST-AKB-*` o categoría test de queries de home (ya hay categoría Uncategorized para esto), O mover a categoría `_test` oculta, O cambiar status a `private`
- **Esfuerzo:** S (15-30 min)
- **Sprint:** S1 inmediato

### F-PRE-012 — Producto AGOTADO con etiqueta "Preventa" mismo tiempo
- **Severity:** P2 (UX confusion)
- **Owner:** mesa-15 architect + mesa-22 wp
- **Evidencia:** El producto test 24263 muestra "Ahorra 5% | Preventa | [TEST E2E] Producto Agotado" con CTA "Reservar ahora" pero sin fecha estimada (📦 Fecha por confirmar). Si está agotado, no debería ser preventa.
- **Probablemente:** combinación accidental de meta keys en el producto test después de los fixes
- **Propuesta:** Verificar lógica de display preventa vs agotado (mutuamente excluyentes). mesa-22 audita
- **Esfuerzo:** S
- **Sprint:** S1

### F-PRE-013 — Sale price duplicado en display ("$14.000 El precio original era: $14.000.$13.500El precio actual es: $13.500")
- **Severity:** P2 (UX, layout broken)
- **Owner:** mesa-07 responsive + mesa-08 design-tokens
- **Evidencia:** Producto "The Climber 15": display de precio repetido "El precio original era: $14.000.$13.500El precio actual es: $13.500." sin separación, layout roto
- **Propuesta:** Theme override de `templates/loop/price.php` o WC template — fix display de sale prices con separación visual clara
- **Esfuerzo:** S
- **Sprint:** S1

### F-PRE-014 — Categoría `Uncategorized` visible públicamente como label de productos
- **Severity:** P3 (polish)
- **Owner:** mesa-22 wp + mesa-15 architect
- **Evidencia:** Productos test muestran `Uncategorized` como brand label (donde otros muestran `Milky Way`, `Panini Argentina`, etc.)
- **Propuesta:** Trasladar productos test a categoría/brand custom o filtrar `Uncategorized` del display
- **Esfuerzo:** S

## Categorías editoriales — mapping

Las 8 editoriales visibles + conteo:

| Editorial | País | Títulos |
|---|---|---|
| Ivrea | AR | 787 |
| Panini | AR | 305 |
| Planeta | ES | 91 |
| Milky Way | ES | 63 |
| Ivrea | ES | 34 |
| Ovni Press | AR | 33 |
| Panini | ES | 32 |
| Arechi | ES | 23 |
| **Total** | | **1,368** |

(Difiere de 1.371 productos publicados — ~3 productos sin editorial asignada, probablemente los 3 test E2E)

## Navegación interactive — refs encontrados

```
link "Akibara — Inicio" → /
button "Buscar productos" (custom search)
link "Mi cuenta" → /mi-cuenta/
link "Favoritos" → /wishlist/
button "Carrito vacío"
link "Inicio" → /
link "Manga" → /manga/
link "Cómics" → /comics/
link "Series" → /serie/
link "Preventas" → /preventas/
link "Blog" → /blog/
```

## Observaciones para mesa-13 branding observador

- Logo "Akibara" texto en top (sin imagen logo visible en este snapshot — verificar si hay logo image)
- Color scheme parece usar Manga Crimson v3 (verificar en design-tokens)
- Voz: "El Akihabara chileno" — referencia cultural Akihabara (Tokio) bien usada
- Tono mixto: profesional ("Importación directa", "Las mejores series") + cercano ("Tu Distrito")
- **Inconsistencia:** Algunos productos muestran rating estrellas (1 producto), otros no — el sistema no es uniforme

## Para mesa-07 responsive

- Trust badges 4 columnas en desktop — verificar stack en mobile (probable 2x2 o stack)
- Producto cards en grid — breakpoints a verificar
- Precios sale display roto layout (F-PRE-013)
- Mobile menu: dropdown vs hamburger? — mesa-07 navega y verifica

## Para mesa-09 email-qa (growth context)

- Sin newsletter signup visible en home (popup posiblemente disparándose por scroll/timeout)
- Sin "Suscribite a nuestra newsletter" CTA explícito
- Sin formulario de "te aviso cuando llegue X"
- **Growth opportunity:** Newsletter signup + back-in-stock signup en single product visibles
