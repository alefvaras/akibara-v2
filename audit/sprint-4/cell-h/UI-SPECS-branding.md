---
sprint: 4
cell: H
date: 2026-04-26
consumer: Cell H Design Ops / akibara-marketing / tema akibara
items: [14, 15, 16]
tokens_source: wp-content/themes/akibara/assets/css/tokens.css
---

# UI-SPECS Branding — Items 14, 15, 16

## Item 14 — Editorial Color Palette

### Descripcion
8 tokens canonicos de editorial en `tokens.css` seccion `EDITORIAL PUBLISHER PALETTE`.
Uso: badges en catalogo, filtros, grafico finance dashboard, tabla stock admin.

### Tokens
| Editorial | Token CSS | Hex | Contraste texto #000 | Uso recomendado |
|---|---|---|---|---|
| Ivrea | `--aki-editorial-ivrea` | `#60A5FA` | 3.94:1 | Solo badges bold + graficos (FAIL AA 4.5:1, PASS large 3:1) |
| Panini | `--aki-editorial-panini` | `#F87171` | 4.23:1 | Solo badges bold + graficos |
| Planeta | `--aki-editorial-planeta` | `#4ADE80` | 8.59:1 | Texto normal y grande OK (AAA) |
| Norma | `--aki-editorial-norma` | `#34D399` | 7.80:1 | Texto normal y grande OK (AAA) |
| Ovni | `--aki-editorial-ovni` | `#C084FC` | 5.46:1 | Texto normal y grande OK (AA) |
| Kamite | `--aki-editorial-kamite` | `#FB923C` | 5.10:1 | Texto normal y grande OK (AA) |
| UTOP | `--aki-editorial-utop` | `#38BDF8` | 5.46:1 | Texto normal y grande OK (AA) |
| Milky | `--aki-editorial-milky` | `#A78BFA` | 5.32:1 | Texto normal y grande OK (AA) |

**Notas criticas:**
- Ivrea y Panini FALLAN AA 4.5:1 para texto normal. PROHIBIDO usarlos como texto body.
- Texto sobre color editorial: SIEMPRE `#000000`.
- Dark theme badges: `rgba(color, 0.12)` background + `rgba(color, 0.4)` border + texto = color editorial (variant A). El texto editorial sobre `#0A0A0A` tiene contraste suficiente.
- Solid badges (variant B): `background: color` + `color: #000`.

### Uso en catalogo (filtros)
```html
<!-- Variant A — dark theme -->
<button class="akb-editorial-badge" style="background: rgba(96,165,250,0.12); border-color: rgba(96,165,250,0.4); color: #60A5FA;" aria-pressed="false">Ivrea</button>

<!-- Variant B — solid para hover/active state -->
<button class="akb-editorial-badge" style="background: #60A5FA; border-color: #60A5FA; color: #000;" aria-pressed="true">Ivrea</button>
```

### Como agregar nueva editorial
1. Token en `tokens.css` seccion `EDITORIAL PUBLISHER PALETTE`: `--aki-editorial-{slug}: #{hex};`
2. Slug = nombre editorial en minuscula sin tildes (ej: `viz` para VIZ Media)
3. Verificar contraste `#000` texto: minimo 3:1 (large text). Descartar si < 3:1.
4. No elegir colores que colisionen visualmente con los 8 existentes.

### Mockup
`audit/sprint-4/cell-h/mockups/14-editorial-color-palette.html`

---

## Item 15 — Customer Milestones Email

### Descripcion
3 templates de email transaccional para hitos del cliente. Enviados via Brevo SMTP.
Email-safe: table layout, hex hardcoded (NO CSS variables — compatibilidad email clients), inline styles.

**Guard activo:** `akibara-email-testing-guard` redirige TODO email saliente a `alejandro.fvaras@gmail.com`.
Verificar que el guard sigue activo antes de cualquier test real.

### Variantes y triggers
| Variante | Trigger | Subject |
|---|---|---|
| Primer pedido | `woocommerce_order_status_completed` — primer pedido (count == 1) | `Bienvenido a la tribu, {{first_name}} — tu primer manga ya viene` |
| 5 pedidos | `woocommerce_order_status_completed` — count llega a 5 | `{{first_name}}, llevas 5 pedidos — regalo para ti` |
| VIP | `woocommerce_order_status_completed` — count llega a 10 | `{{first_name}}, eres VIP Akibara` |

### Estructura de colores (email-safe hex)
```
#0D0D0F  /* --aki-email-bg: fondo principal */
#161618  /* --aki-email-surface: header + cards + footer */
#8B0000  /* --aki-topbar-bg: barra superior */
#D90010  /* --aki-red: CTA button */
#FF2020  /* --aki-red-bright: link email */
#FFD700  /* --aki-yellow: titulos destacados, cupon codigo */
#00C853  /* --aki-success: hero 5 pedidos */
#25D366  /* WA green: link WhatsApp en footer */
#E0E0E0  /* --aki-gray-200: body text */
#B0B0B0  /* --aki-gray-300: secondary text */
#8A8A8A  /* --aki-gray-400: muted / footer */
#6A6A6A  /* --aki-border-aa: footer links + footer text */
#2A2A2E  /* --aki-border: borders internos */
#FFFFFF  /* --aki-white: texto sobre fondos de color */
```

### Variables Merge (Brevo)
```
{{first_name}}      — nombre del cliente
{{order_number}}    — numero de orden (#XXXX)
{{order_date}}      — fecha del pedido
{{order_total}}     — total formateado (sin signo pesos — agregar $ en template)
{{order_url}}       — URL de la orden en Mi Cuenta
{{order_count}}     — conteo total de ordenes completadas
{{unsubscribe_url}} — URL de baja de Brevo
```

### Cupones
- **Variante 5 pedidos:** cupon `LECTOR5` — 10% descuento, valido 30 dias.
  Crear en WC: Cupones > Nuevo cupon > 10% descuento > expira en fecha.
  Cell B marketing debe gestionar la generacion de cupones unicos por cliente si se escala.
- **Variante VIP:** cupon `VIP15` — 15% descuento permanente.
  Crear en WC como cupon de uso multiple restringido al email del cliente.

### Layout email-safe
```html
<!-- Patron base: table anidadas, cellpadding=0, cellspacing=0 -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr><td align="center">
    <!-- Inner table max-width 600px -->
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;">
      <!-- rows por secciones -->
    </table>
  </td></tr>
</table>
```

### Mockup
`audit/sprint-4/cell-h/mockups/15-customer-milestones-email.html`

---

## Item 16 — Logo Canonical Reference

### Descripcion
Guia de uso del logo Akibara — Manga Crimson v3 system.
Aplica a: header del sitio, email templates, favicon, OG images, social.

### Variantes de superficie aprobadas
| Variante | Background | Wordmark color | Subtitulo color | Caso de uso |
|---|---|---|---|---|
| V1 Dark (canonical) | `#0A0A0A` | `#FFFFFF` | `#D90010` | Header principal, default |
| V2 Surface-2 | `#1A1A1A` | `#FFFFFF` | `#D90010` | Navegacion secundaria, cards |
| V3 Topbar | `#8B0000` | `#FFFFFF` | `rgba(255,215,0,0.9)` | Barra topbar existente |
| V4 Fondo claro | `#F5F5F5` | `#0A0A0A` | `#D90010` | OG images, emails, impresion |
| V5 Monocromo | Fondo rojo | `#FFFFFF` (mono) | `rgba(255,255,255,0.8)` | Merchandising, redes |
| V6 Sobre foto | Variable | `#FFFFFF` + text-shadow | `#FF2020` | Hero banners |

### Clearspace
- Zona de exclusion = **altura de la letra "M"** en el wordmark.
- Ningun elemento puede entrar en esa zona.
- Digital: minimo 8px. Impresion: minimo 5mm.

### Sizing por contexto
| Contexto | Ancho minimo | Variante recomendada |
|---|---|---|
| Header desktop | 140px | V1 con wordmark |
| Header mobile | 100px | V1 reducido |
| Favicon tab | 32px | Solo icono (V-icono) |
| Email header | 120px | V4 (fondo claro) o V1 en tabla oscura |
| OG / Social | 200px | V4 con tagline |
| Menor de 80px | — | Solo icono, sin wordmark |

### Estructura de archivos (target)
```
wp-content/themes/akibara/assets/images/
├── logo.svg          → Version principal oscura (wordmark horizontal)
├── logo-light.svg    → Para fondos claros (OG/emails)
├── logo-mono.svg     → Monocromo blanco (sobre fondos de color)
├── logo-icon.svg     → Solo marca grafica (sin wordmark)
└── favicon.svg       → Favicon moderno (browsers 2021+)
```

### Usos prohibidos
- Deformar (stretch, squish, skew)
- Cambiar colores por colores no aprobados
- Colocar sobre fondos con bajo contraste (< 3:1)
- Rotar el logo
- Agregar efectos (sombra, glow, gradiente sobre el logo mismo)
- Usar version desactualizada pre-Manga Crimson v3

### Implementacion en header (WordPress)
```php
// header.php / template
echo '<a href="' . esc_url(home_url('/')) . '" aria-label="' . get_bloginfo('name') . '">';
echo '<img src="' . esc_url(get_theme_file_uri('/assets/images/logo.svg')) . '"';
echo '     alt="' . esc_attr(get_bloginfo('name')) . '"';
echo '     width="140" height="40" loading="eager" decoding="sync">';
echo '</a>';
```

### Mockup
`audit/sprint-4/cell-h/mockups/16-logo-canonical.html`
