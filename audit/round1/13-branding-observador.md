---
agent: mesa-13-branding-observador
round: 1
date: 2026-04-26
scope: Observación de inconsistencias visuales/voz/branding actual en theme akibara, headers/footers/templates, emails WC, popup/banner/badges. NO propongo cambios visuales — solo observo y marco REQUIERE MOCKUP.
files_examined: 23
findings_count: { observaciones_técnicas: 4, REQUIERE_MOCKUP: 11, voseo: 0 }
---

## Resumen ejecutivo

1. **Logo Akibara consistente y unificado.** Una sola fuente: `assets/img/logo-akibara.webp` ("Manga Crimson" estilo manga rojo brush stroke), reusada en header, footer, login, emails. NO hay drift de versiones. Punto fuerte.
2. **Color crimson 5 tokens activos** (`--aki-red #D90010`, `--aki-red-hover #BB000D`, `--aki-red-bright #FF2020`, `--aki-red-dark #8B0000`, `--aki-topbar-bg #8B0000`) más colores hardcoded (`#b5000d`, `#900009`, `#D90010` literal en login). Convive paleta documentada con literales en aux files.
3. **Inconsistencia copy "Cómics y Manga" vs "Manga y Cómics"** — title tag dice "Distrito del Manga y Cómics", schema.org "Manga y Cómics", footer + homepage tagline H1 "Cómics y Manga". Decisión owner 2026-04-25 (footer.php:21) fue "cómics→manga" pero aplicada solo parcialmente.
4. **Theme description en style.css referencia "Persona 5 aesthetics"** — concepto inicial; hoy branding canónico es "Akihabara chileno + Manga Crimson v3". Texto interno desactualizado.
5. **Cero voseo detectado.** Tono mixto (tuteo + neutro + formal). NO violación dura. Mesa-06 valida resto.

## Issues técnicos observados (acción posible sin mockup)

### O-13-001: Email header fallback URL hardcoded externa antigua
- **Categoría:** branding-leak / email
- **Locación:** `themes/akibara/woocommerce/emails/email-header.php:35`
- **Inconsistencia:** Cascada fallback de $logo_url termina en `https://akibara.cl/wp-content/uploads/2022/02/1000000826-2-scaled-e1758692190673.png` (logo legacy 2022).
- **REQUIERE MOCKUP:** NO
- **Propuesta técnica:** Reemplazar último fallback por `AKIBARA_THEME_URI . '/assets/img/logo-akibara.webp'`. Riesgo bajo. Esfuerzo S.

### O-13-002: Theme style.css description menciona "Persona 5 aesthetics"
- **Categoría:** branding-leak / metadata
- **Locación:** `themes/akibara/style.css:6`
- **Inconsistencia:** Description: `"Premium manga & comics store theme inspired by Akihabara culture and Persona 5 aesthetics"`. Hoy branding documentado es "Manga Crimson v3" + "Akihabara chileno".
- **REQUIERE MOCKUP:** NO
- **Propuesta técnica:** Update metadata a algo alineado con branding canónico actual. NO toco copy específico.

### O-13-003: Login screen hex hardcoded fuera tokens design system
- **Categoría:** color
- **Locación:** `themes/akibara/inc/admin.php:16-44`
- **Inconsistencia:** CSS inline login usa `#D90010`, `#b5000d` (lowercase NO en tokens), `#900009` (NO en tokens). Design system tiene `--aki-red-hover: #BB000D` (no #b5000d) y `--aki-red-dark: #8B0000` (no #900009).
- **REQUIERE MOCKUP:** NO
- **Propuesta técnica:** Reemplazar literales por tokens canónicos, o si los hex login son intencionales, declararlos como tokens.

### O-13-004: ANULADA — copyright footer está bien escrito (no había typo confirmado)

## Issues observados que requieren propuesta del diseñador (REQUIERE MOCKUP)

### O-13-101: Tagline orden "Manga vs Cómics" inconsistente
- **Categoría:** voz / branding
- **Locación múltiple:**
  - `front-page.php:38` → "Tu Distrito de Cómics y Manga"
  - `footer.php:22` → "Tu Distrito de Cómics y Manga — el Akihabara chileno"
  - Title tag → "Akibara | Tu Distrito del Manga y Cómics en Chile"
  - Schema.org JSON-LD → "Tu Distrito del Manga y Cómics en Chile"
- **Comentario:** Comment footer.php:20 menciona "Branding canónico 2026-04-25 (owner): orden cómics→manga". Aplicación parcial.
- **REQUIERE MOCKUP:** SÍ — diseñador decide orden canónico final + lo aplica consistente

### O-13-102: Trust badges home sin treatment branded — SVG outline genéricos
- **Categoría:** iconos / branding
- **Locación:** `template-parts/front-page/trust-badges.php:14-52`
- **Inconsistencia:** 4 trust badges (Envío, 3 cuotas, Manga 100% original, WhatsApp) usan SVG outline genéricos (Feather/Lucide-style). Sin branding visual diferencial vs ecommerce genérico chileno. "Manga 100% original" usa estrella, no algo manga-related.
- **REQUIERE MOCKUP:** SÍ

### O-13-103: Logo header condicional cargado vía 3 paths distintos
- **Categoría:** logo / branding
- **Locación:** `themes/akibara/header.php:72-91`
- **Inconsistencia:** 3 paths carga logo: (a) si existe logo-akibara.webp dimensions 181x72; (b) Customizer custom_logo SVG vs raster; (c) fallback assets/img/akibara-logo.svg (existencia NO confirmada). Si Customizer tiene logo seteado, NUNCA se usa. Drift entre source que código intenta priorizar y la que sirve.
- **REQUIERE MOCKUP:** SÍ — diseñador decide canonical logo source y elimina fallbacks no usados
- **Nota técnica:** header.php hardcoded 181x72 + ?v=5 cache buster. Footer.php 201x80. Logo mismo archivo, dimensions diferentes → ratio similar (2.512 vs 2.514) pero tamaño efectivo +11%.

### O-13-104: .product-card__editorial colorea editoriales con colores Tailwind (fuera paleta brand)
- **Categoría:** color
- **Locación:** `assets/css/design-system.css:493-501`
- **Inconsistencia:** 9 colores hardcoded fuera paleta Akibara:
  - ivrea: #60a5fa (azul Tailwind)
  - panini: #f87171 (rojo coral)
  - planeta: #4ade80 (verde)
  - norma: #34d399 (esmeralda)
  - ovni: #c084fc (púrpura)
  - kamite: #fb923c (naranja)
  - utop: #38bdf8 (sky)
  - milky: #a78bfa (lavanda)
  - tmvn: #fbbf24 (amarillo)
- **REQUIERE MOCKUP:** SÍ — diseñador decide si editorial color coding está justificado

### O-13-105: Banner topbar usa emojis nativos (🚚🚇💳🛡️) — convive con SVG icons
- **Categoría:** iconos / branding
- **Locación:** `plugins/akibara/modules/banner/module.php:45-71`
- **Inconsistencia:** Topbar usa emojis nativos como "iconos". Trust-badges (justo debajo en home) usa SVG outline custom. 2 sistemas iconográficos en misma vista.
- **REQUIERE MOCKUP:** SÍ — diseñador decide emoji vs SVG, o mezcla intencional

### O-13-106: Background topbar usa --aki-topbar-bg #8B0000 (granate) — NO Manga Crimson #D90010
- **Categoría:** color / branding
- **Locación:** `themes/akibara/assets/css/header-v2.css:16` + `design-system.css:61`
- **Inconsistencia:** Topbar (1° elemento visible cliente) usa color granate, NO el rojo brand canónico. Puede ser intencional para que CTA destaque, pero requiere decisión declarada.
- **REQUIERE MOCKUP:** SÍ

### O-13-107: Email header crimson accent bar #D90010 hardcoded — NO sigue tokens email
- **Categoría:** color / email branding
- **Locación:** `themes/akibara/woocommerce/emails/email-header.php:92`
- **Inconsistencia:** Email tiene `--aki-red: #D90010` declarado pero HTML inline usa literal directo. Razonable técnicamente (emails NO soportan CSS vars en muchos clients) PERO archivo no documenta este "downgrade" deliberado.
- **REQUIERE MOCKUP:** SÍ — diseñador valida color final + declara tabla equivalencias

### O-13-108: Tono voz mezcla tuteo neutro + "te"/"tu" + formal
- **Categoría:** voz
- **Locación múltiple:**
  - `customer-on-hold-order.php:44` → "¿Tienes alguna duda?"
  - `single-product/info.php:215` → "¿Prefieres esperar?"
  - `single-product/info.php:247` → "¿Dudas sobre este título? Escríbenos"
  - `popup/module.php:117` → "Suscríbete y recibe un 10% de descuento"
  - `front-page.php:40` → "el Akihabara chileno · envío a todo Chile" (impersonal)
- **Inconsistencia:** Voz mixta — tuteo cercano, imperativo neutro, interrogativo formal, impersonal. NO hay voseo (regla Chile cumplida ✅) PERO no es uniforme.
- **REQUIERE MOCKUP/CONTENT-DESIGN:** SÍ — handoff a mesa-06 (content-voz) que defina guía editorial

### O-13-109: Logo footer (201x80) y header (181x72) — proporciones distintas
- **Categoría:** logo / branding
- **Locación:** header.php:74 + footer.php:11
- **Inconsistencia:** Mismo asset, dimensions distintas. Ratios similares (2.514 vs 2.512) pero footer ~11% más grande. Delta subliminal — no decisión visual deliberada.
- **REQUIERE MOCKUP:** SÍ

### O-13-110: theme-color browser #0A0A0A — diferente de topbar #8B0000
- **Categoría:** color / branding
- **Locación:** `themes/akibara/header.php:6`
- **Inconsistencia:** Mobile (Android Chrome / iOS Safari) `theme-color` colorea barra navegador. Akibara declara `#0A0A0A` (negro). Topbar es granate `#8B0000`, header_main negro. Transición visual barra navegador negra → topbar granate → header negro.
- **REQUIERE MOCKUP:** SÍ — diseñador decide si theme-color matchea topbar o background

### O-13-111: Producto preventa puede mostrar simultáneamente badge "Preventa" yellow + "Ahorra X%" red
- **Categoría:** branding / iconos
- **Locación:** `plugins/akibara/modules/product-badges/module.php:35-71`
- **Inconsistencia:** 2 axes badges: estado (Preventa/Agotado/Disponible) top-left + comercial (Ahorra X%) top-right. Combinación Preventa yellow + sale red = fuerte contraste cromático rojo-amarillo (semáforo). HOMEPAGE-SNAPSHOT confirma activamente.
- **REQUIERE MOCKUP:** SÍ — diseñador decide tratamiento visual cuando ambas coexisten

## Voseo / voz mixta (handoff mesa-06)

**Voseo:** Cero matches en archivos auditados. Regla Chile cumplida ✅.

**Voz mixta (NO voseo, tono inconsistente):** ver tabla en sección O-13-108. mesa-06 decide guía editorial uniforme.

## Inventario de assets de marca

### Logos
| Path | Tipo | Uso | Notas |
|---|---|---|---|
| `assets/img/logo-akibara.webp` | WebP | Header, footer, login, emails | **Único logo "Manga Crimson" canónico activo** |
| `assets/img/akibara-logo.svg` | SVG | Fallback header.php:90 | NO confirmé existencia filesystem |
| `https://akibara.cl/...2022/02/1000000826-2-scaled-e1758692190673.png` | PNG remoto | Email last-resort | Logo viejo 2022 |

**Cache buster:** `?v=5` hardcoded header.php:74 + footer.php:11.

### Colores brand activos
27 colores nombrados + 9 hardcoded para editoriales. Hardcoded fuera de design-system.css (sample): `inc/admin.php`, `email-header.php`, `inc/enqueue.php:201`, `header-v2.css:8`, `woocommerce.css:112`.

### Fonts
| Family | Source | Weights | Notas |
|---|---|---|---|
| Bebas Neue | self-hosted woff2 | 400 | Heading |
| Russo One | self-hosted woff2 | 400 | Display H1 |
| Inter | self-hosted woff2 (4 archivos) | 400/500/600/700 | Body |
| JetBrains Mono / Fira Code | system fallback | — | Mono token declarado pero NO cargado |

**Email font fallback:** Helvetica Neue / Arial (correcto técnicamente — emails NO soportan webfonts en Outlook/Yahoo).

### Iconography
- **Set principal:** SVG inline custom (Feather/Lucide-style) en `inc/setup.php:103-131` función `akibara_icon($name)` — 17 iconos
- **SVG ad-hoc inline en templates:** múltiples ocurrencias header.php, footer.php, template-parts
- **Emojis nativos:** banner topbar (🚚🚇💳🛡️), product-card preventa (📅📦), preventa fecha-badge (✓ ⚠ via HTML entities), notify (✉)
- **3 sistemas iconográficos coexisten:** SVG outlines + emojis + HTML entities

## Hipótesis para Iter 2

1. Drift entre logo-akibara.webp y SVG vector original (si existe en filesystem)
2. Inconsistencia adicional en emails NO auditados (customer-new-account, customer-reset-password, etc.)
3. Inconsistencia product-card archive vs search results (templates WC default no overrideados)
4. Inconsistencia branding wishlist, account, cart, checkout pages no audit fondo
5. Inconsistencia mobile bottom-nav SVG ad-hoc vs header.php akibara_icon() helper

## Áreas que NO cubrí

- Páginas internas: /preguntas-frecuentes, /nosotros, /devoluciones, /terminos-y-condiciones, /politica-de-privacidad, /encargos, /rastrear, /contacto
- Templates blog: home.php, single.php, blog-card styling
- Wishlist + account páginas
- 404.php
- Imágenes producto reales (aspect ratio, treatment)
- Emails third-party (MercadoPago, BlueX, Brevo upstream)
- Brevo workflow emails (templates editables en Brevo dashboard, NO código)
- Admin / wp-admin styling más allá de login + footer
- Dimensiones físicas logo en assets/img/ (solo verifiqué render)
- Listado completo archivos en assets/img/
- Verificación visual en vivo via Chrome MCP

## Cross-cutting flags

- mesa-06 content-voz: voz mixta documentada en O-13-108
- mesa-08 design-tokens: token --aki-success #00c853 lowercase format inconsistente. 9 colores editoriales hardcoded fuera tokens
- mesa-07 responsive: F-PRE-013 sale price layout broken confirmado (puede ser false positive accessibility tree extraction según mesa-07)
- mesa-22 wp-master: F-PRE-011 productos test E2E + Uncategorized badge — confirma branding leak (re-clasificado como pre-launch por usuario)
- mesa-15 architect: `function akibara_icon()` y SVG ad-hoc inline divergen — refactor candidate
