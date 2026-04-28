<?php
/**
 * Akibara — Sistema unificado de email templates
 *
 * Provee header, footer, product cards y CTAs compartidos
 * para todos los emails transaccionales (carrito abandonado,
 * reseñas, marketing campaigns, notificaciones).
 *
 * Branding v3 (2026-04 · Manga Crimson unificado):
 *   Dark theme (#0D0D0F base) + paleta Manga Crimson sincronizada
 *   con el sitio v3 (ver themes/akibara/assets/css/design-system.css).
 *
 *   ACCENT (Manga Crimson #D90010) → CTAs primarios y headers (5.32:1 WCAG AA)
 *   HOT    (Red Bright    #FF2020) → urgency / sale / flash
 *   LINK   (Red Light     #FF4D4D) → links sobre fondo oscuro (5.52:1 WCAG AA)
 *
 *   Fonts: Archivo Black (headings/logo/CTA) + Inter (body).
 *
 * @package    Akibara
 * @version    2.0.0
 */

namespace Akibara\Infra;

defined( 'ABSPATH' ) || exit;

class EmailTemplate {

	// BG_DARK #0D0D0F restaurado (Opción B con #1A1A1E falló test iPhone Gmail 2026-04-22
	// — Gmail iOS invierte bg <50% brillo sin importar el valor). Conclusión: branding
	// dark se ve perfecto en desktop + Apple Mail; Gmail iOS/Android SIEMPRE invertirá
	// a versión clara. Comportamiento esperado de Google, no configurable.
	const BG_DARK     = '#0D0D0F';
	const BG_CARD     = '#161618';
	const BG_CARD_ALT = '#1A1A1E';
	// ─── v3 Palette (Manga Crimson — branding unificado 2026-04) ──
	const ACCENT        = '#D90010'; // Manga Crimson — acento principal
	const ACCENT_HOVER  = '#BB000D'; // Manga Crimson -10% L
	const ACCENT_ACTIVE = '#8B0000'; // Dark Blood — pressed state
	const HOT           = '#FF2020'; // Red Bright — urgency / sale / flash
	const HOT_HOVER     = '#E50000'; // Red Bright -10% L
	const LINK          = '#FF4D4D'; // Red Light — links sobre fondo oscuro (5.52:1)
	const LINK_HOVER    = '#FF6666'; // Red Light hover
	// ─── Text / neutrals ────────────────────────────────────────
	const TEXT_PRIMARY   = '#F5F5F5';
	const TEXT_SECONDARY = '#A0A0A0';
	const TEXT_MUTED     = '#666666';
	const BORDER         = '#2A2A2E';
	const GOLD           = '#FFD600';
	const GREEN          = '#00C853';
	// ─── Font stacks (v2) ───────────────────────────────────────
	const FONT_HEADING = "'Archivo Black','Bebas Neue',Impact,'Arial Black',sans-serif";
	const FONT_BODY    = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif";

	/**
	 * Logo URL — configurable via admin option.
	 *
	 * Default apunta al logo full-size (sin thumbnail regenerable). Si quieres
	 * cambiarlo: wp option update akibara_email_logo_url https://url...
	 */
	public static function logo_url(): string {
		// NOTA (2026-04-20): WordPress renombra el archivo cuando el admin hace crop
		// desde Media Library (sufijo `-eNNNN.png`). El default anterior (scaled.png
		// sin sufijo) quedó 404 tras un crop. URL verificada 200 OK.
		return get_option(
			'akibara_email_logo_url',
			'https://akibara.cl/wp-content/uploads/2022/02/1000000826-2-scaled-e1758692190673.png'
		);
	}

	/**
	 * Tagline institucional de la marca — usado en signature y footer.
	 * Centralizado para que cambios futuros sean 1 edit.
	 *
	 * NOTA (2026-04-19): "Akibara" es alusión al barrio de Akihabara (Tokio),
	 * distrito otaku multi-categoría. Hoy el catálogo es manga + cómics; cuando
	 * entren figuras/merch, actualizar este string a "tu distrito del coleccionismo"
	 * (o variante decidida). Ver docs/skills/branding.md > "Evolución del tagline".
	 */
	public static function tagline(): string {
		return 'tu distrito del manga y cómics';
	}

	/**
	 * Añade tracking UTM a una URL para medición GA4.
	 * Si la URL ya tiene parámetros utm_*, respeta los existentes.
	 *
	 * @param string $url            URL destino.
	 * @param string $utm_campaign   Nombre de campaña (ej: 'review-request', 'cart-abandoned-1h').
	 * @param string $utm_source     Default 'email'.
	 * @param string $utm_medium     Default 'transactional'.
	 * @return string URL con UTM appended.
	 */
	public static function utm( string $url, string $utm_campaign = '', string $utm_source = 'email', string $utm_medium = 'transactional' ): string {
		if ( empty( $url ) || str_starts_with( $url, '#' ) || str_starts_with( $url, 'mailto:' ) || str_starts_with( $url, 'tel:' ) ) {
			return $url;
		}
		// Solo URLs internas (akibara.cl). Respetar externos sin UTM.
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && false === strpos( $host, 'akibara.cl' ) ) {
			return $url;
		}
		$args = array(
			'utm_source' => $utm_source,
			'utm_medium' => $utm_medium,
		);
		if ( $utm_campaign ) {
			$args['utm_campaign'] = sanitize_key( $utm_campaign );
		}
		return add_query_arg( $args, $url );
	}

	/**
	 * ─── DOCTYPE + HEAD ─────────────────────────────────────────
	 * Inline styles for maximum email client compatibility.
	 */
	public static function open(): string {
		return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
<title>Akibara</title>
<!--[if mso]><style>body,table,td{font-family:Arial,sans-serif!important}</style><![endif]-->
<style>
/* Desktop: headline un punto más grande para emails en clientes que respetan media queries */
@media only screen and (min-width: 480px) {
  .akb-headline { font-size: 28px !important; }
  .akb-content-wrap { padding: 32px 32px !important; }
}
/* Mobile: padding cómodo y links grandes para tap */
@media only screen and (max-width: 479px) {
  .akb-content-wrap { padding: 24px 20px !important; }
  .akb-cta-btn { padding: 14px 28px !important; font-size: 17px !important; }
  .akb-social-link { display: inline-block; margin: 4px 6px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:' . self::BG_DARK . ';color:' . self::TEXT_PRIMARY . ';font-family:Inter,-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . self::BG_DARK . '">
<tr><td align="center" style="padding:0">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%">';
	}

	/**
	 * ─── HEADER ─────────────────────────────────────────────────
	 * Logo + accent border.
	 */
	public static function header( string $preheader = '' ): string {
		$html = '';

		// Preheader text (hidden but shows in inbox preview)
		if ( $preheader ) {
			$html .= '<tr><td style="display:none!important;visibility:hidden;mso-hide:all;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden">' . esc_html( $preheader ) . '</td></tr>';
		}

		$html .= '<tr><td style="padding:32px 28px 20px;text-align:center;border-bottom:3px solid ' . self::ACCENT . '">';
		$html .= '<a href="https://akibara.cl" style="text-decoration:none">';
		$html .= '<img src="' . esc_url( self::logo_url() ) . '" alt="' . esc_attr( 'akibara · ' . self::tagline() ) . '" width="160" style="max-width:160px;width:160px;height:auto;display:inline-block;border:0;outline:none;text-decoration:none" />';
		$html .= '</a>';
		$html .= '</td></tr>';

		return $html;
	}

	/**
	 * ─── CONTENT WRAPPER ────────────────────────────────────────
	 * Wraps the main email body.
	 */
	public static function content_open(): string {
		return '<tr><td class="akb-content-wrap" style="padding:32px 28px">';
	}

	public static function content_close(): string {
		return '</td></tr>';
	}

	/**
	 * ─── HEADLINE ───────────────────────────────────────────────
	 * Título principal + subtítulo opcional (tagline editorial).
	 *
	 * @param string $text     Título (emojis deben venir inline — no hay prefix).
	 * @param string $subtitle Línea secundaria (opcional, upper + spaced).
	 *
	 * Tamaño mobile-first (24px) con media query inline para desktop (28px).
	 * Algunos clients ignoran style tags; por eso ambos inline.
	 */
	public static function headline( string $text, string $subtitle = '' ): string {
		$html = '<h1 style="font-family:' . self::FONT_HEADING . ';font-size:24px;text-transform:uppercase;letter-spacing:0.04em;color:' . self::TEXT_PRIMARY . ';text-align:center;margin:0 0 8px;line-height:1.2" class="akb-headline">' . esc_html( $text ) . '</h1>';
		if ( $subtitle ) {
			$html .= '<p style="text-align:center;color:' . self::TEXT_SECONDARY . ';font-size:13px;text-transform:uppercase;letter-spacing:0.12em;margin:0 0 20px;font-family:' . self::FONT_HEADING . ';font-weight:400">' . esc_html( $subtitle ) . '</p>';
		}
		return $html;
	}

	/**
	 * ─── SUBHEADLINE / INTRO TEXT ───────────────────────────────
	 */
	public static function intro( string $text ): string {
		return '<p style="text-align:center;color:' . self::TEXT_SECONDARY . ';font-size:15px;line-height:1.6;margin:0 0 24px;max-width:480px;margin-left:auto;margin-right:auto">' . $text . '</p>';
	}

	/**
	 * ─── PRODUCT CARD ───────────────────────────────────────────
	 * Shows product image, name, price, and optional quantity.
	 * Used por abandoned cart (variant 'cart'), review request (variant 'review'),
	 * series-notify / next-volume (variant 'cart'). El default 'simple' renderiza
	 * solo image + name (sin precio ni stars).
	 *
	 * @param array  $product  { name, image, url, price?, qty? }
	 * @param string $variant  'cart' | 'review' | 'simple' (default).
	 */
	public static function product_card( array $product, string $variant = 'simple' ): string {
		$name  = esc_html( $product['name'] ?? '' );
		$image = esc_url( $product['image'] ?? '' );
		$url   = esc_url( $product['url'] ?? '#' );
		$price = $product['price'] ?? '';
		$qty   = (int) ( $product['qty'] ?? 1 );

		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . self::BG_CARD . ';border:1px solid ' . self::BORDER . ';border-radius:8px;margin:12px 0;overflow:hidden">';
		$html .= '<tr>';

		// Image column
		if ( $image ) {
			$html .= '<td width="120" style="padding:16px;vertical-align:top">';
			$html .= '<a href="' . $url . '" style="text-decoration:none">';
			$html .= '<img src="' . $image . '" alt="' . $name . '" width="100" style="width:100px;height:auto;border-radius:4px;display:block;border:0" />';
			$html .= '</a>';
			$html .= '</td>';
		}

		// Info column
		$html .= '<td style="padding:16px 16px 16px 0;vertical-align:top">';
		$html .= '<a href="' . $url . '" style="text-decoration:none;color:' . self::TEXT_PRIMARY . '">';
		$html .= '<strong style="font-size:15px;display:block;margin:0 0 6px;line-height:1.3">' . $name . '</strong>';
		$html .= '</a>';

		if ( $variant === 'cart' ) {
			// Cart variant: show price and quantity
			if ( $price ) {
				$html .= '<span style="font-size:16px;color:' . self::ACCENT . ';font-weight:700">' . esc_html( $price ) . '</span>';
			}
			if ( $qty > 1 ) {
				$html .= '<span style="color:' . self::TEXT_MUTED . ';font-size:13px;margin-left:8px">×' . $qty . '</span>';
			}
		} elseif ( $variant === 'review' ) {
			// Review variant: show clickable stars
			$html .= '<div style="margin:8px 0 0">';
			for ( $i = 1; $i <= 5; $i++ ) {
				$star_url = esc_url( ( $product['url'] ?? '' ) . '?akb_rating=' . $i . '#reviews' );
				$html    .= '<a href="' . $star_url . '" style="text-decoration:none;font-size:26px;color:' . self::GOLD . ';padding:0 1px" title="' . $i . ' estrellas">★</a>';
			}
			$html .= '</div>';
			$html .= '<span style="color:' . self::TEXT_MUTED . ';font-size:12px">Haz clic en una estrella para calificar</span>';
		}

		$html .= '</td>';
		$html .= '</tr></table>';

		return $html;
	}

	/**
	 * ─── CART SUMMARY ───────────────────────────────────────────
	 * Shows cart total and item count.
	 */
	public static function cart_summary( string $total, int $count ): string {
		$html  = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:20px 0;border-top:1px solid ' . self::BORDER . ';padding-top:16px">';
		$html .= '<tr>';
		$html .= '<td style="padding:8px 0;color:' . self::TEXT_SECONDARY . ';font-size:14px">' . $count . ' producto' . ( $count !== 1 ? 's' : '' ) . ' en tu carrito</td>';
		$html .= '<td style="padding:8px 0;text-align:right;font-size:20px;font-weight:700;color:' . self::TEXT_PRIMARY . '">' . esc_html( $total ) . '</td>';
		$html .= '</tr></table>';
		return $html;
	}

	/**
	 * ─── CTA BUTTON — Neon Akihabara ────────────────────────────
	 * Botón centrado, estilo único de marca: outline Manga Crimson (#D90010)
	 * + glow neon. DNA manga / otaku — cartel de letrero nocturno.
	 *
	 *  - UTM tracking automático (si `utm_campaign` se pasa y URL es de akibara.cl).
	 *  - VML fallback para Outlook desktop (outline plano sin glow, pero accionable).
	 *  - Responsive via clase `akb-cta-btn` (padding/font-size se achican en mobile).
	 *
	 * @param string $text          Texto del botón.
	 * @param string $url           URL destino.
	 * @param string $utm_campaign  Campaña GA4 (ej: 'review-request', 'cart-abandoned-1h').
	 */
	public static function cta( string $text, string $url, string $utm_campaign = '' ): string {
		// UTM tracking para medición GA4 (solo si URL es interna).
		$url = self::utm( $url, $utm_campaign );

		$html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0"><tr><td align="center">';
		// Outlook desktop VML fallback — transparent fill + stroke Manga Crimson.
		// `filled="f"` omite el fill (box-shadow/text-shadow no existen en MSO).
		$html .= '<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . esc_url( $url ) . '" style="height:52px;v-text-anchor:middle;width:260px" arcsize="8%" strokecolor="' . self::HOT . '" strokeweight="2px" filled="f">
<w:anchorlock/>
<center style="color:' . self::HOT . ';font-family:Arial,sans-serif;font-size:18px;font-weight:700">' . esc_html( $text ) . '</center>
</v:roundrect>
<![endif]-->';
		// Non-Outlook (Gmail, Apple Mail, Outlook.com web, Yahoo, etc).
		// Box-shadow + text-shadow = glow neon. Inner shadow sutil para volumen.
		$html .= '<!--[if !mso]><!-- -->';
		$html .= '<a class="akb-cta-btn" href="' . esc_url( $url ) . '" style="'
			. 'display:inline-block;background:transparent;color:' . self::HOT . '!important;'
			. 'text-decoration:none;padding:16px 40px;'
			. 'font-family:' . self::FONT_HEADING . ';font-size:18px;'
			. 'text-transform:uppercase;letter-spacing:0.1em;'
			. 'border:2px solid ' . self::HOT . ';border-radius:8px;'
			. 'font-weight:700;mso-padding-alt:0;text-align:center;'
			. 'box-shadow:0 0 0 1px rgba(217,0,16,0.3),0 0 20px rgba(217,0,16,0.4),inset 0 0 14px rgba(217,0,16,0.08);'
			. 'text-shadow:0 0 8px rgba(217,0,16,0.6);'
			. '">' . esc_html( $text ) . '</a>';
		$html .= '<!--<![endif]-->';
		$html .= '</td></tr></table>';

		return $html;
	}

	/**
	 * ─── COUPON BOX ─────────────────────────────────────────────
	 * Highlighted coupon code display.
	 */
	public static function coupon_box( string $code, string $label = 'Tu código de descuento' ): string {
		if ( empty( $code ) ) {
			return '';
		}

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0"><tr><td>
<div style="background:' . self::BG_CARD . ';border:2px dashed ' . self::GOLD . ';border-radius:8px;padding:20px;text-align:center">
<p style="color:' . self::TEXT_MUTED . ';font-size:12px;text-transform:uppercase;letter-spacing:0.1em;margin:0 0 8px">' . esc_html( $label ) . '</p>
<div style="font-size:32px;font-weight:700;color:' . self::GOLD . ';letter-spacing:3px;font-family:' . self::FONT_HEADING . '">' . esc_html( $code ) . '</div>
</div>
</td></tr></table>';
	}

	/**
	 * ─── INCENTIVE BOX ──────────────────────────────────────────
	 * For review incentive, referral bonus, etc.
	 */
	public static function incentive_box( string $title, string $description, string $icon = '🎁' ): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0"><tr><td>
<div style="background:linear-gradient(135deg,' . self::BG_CARD . ',' . self::BG_DARK . ');border:1px dashed ' . self::GOLD . ';border-radius:8px;padding:20px;text-align:center">
<p style="font-size:13px;color:' . self::GOLD . ';text-transform:uppercase;letter-spacing:0.12em;margin:0 0 8px;font-weight:600">' . $icon . ' ' . esc_html( $title ) . '</p>
<p style="font-size:16px;color:' . self::TEXT_PRIMARY . ';margin:0 0 6px;font-weight:600">' . esc_html( $description ) . '</p>
</div>
</td></tr></table>';
	}

	/**
	 * ─── DIVIDER ────────────────────────────────────────────────
	 */
	public static function divider(): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0"><tr><td style="border-top:1px solid ' . self::BORDER . '">&nbsp;</td></tr></table>';
	}

	/**
	 * ─── URGENCY TEXT ───────────────────────────────────────────
	 * "Items selling fast" type messaging.
	 */
	public static function urgency( string $text ): string {
		// v3: HOT (Red Bright) para urgencia, ACCENT (Manga Crimson) para CTAs.
		// Separación semántica clara entre "actúa" (ACCENT) y "urge" (HOT).
		return '<p style="text-align:center;color:' . self::HOT . ';font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin:16px 0;font-family:' . self::FONT_HEADING . '">' . esc_html( $text ) . '</p>';
	}

	/**
	 * ─── COUNTDOWN BANNER ───────────────────────────────────────
	 * Server-side rendered "ends on {date}" banner. Email clients
	 * don't reliably support JS countdowns; this is the robust variant
	 * — high-contrast urgency strip that resolves `{END_DATE}` at send-time.
	 *
	 * Usage in a template:
	 *   AkibaraEmailTemplate::countdown_banner( 'Termina', '{END_DATE}' );
	 *
	 * The marketing-campaigns pipeline replaces `{END_DATE}` with the
	 * formatted end date for the campaign when sending.
	 */
	public static function countdown_banner( string $label = 'Termina', string $deadline_text = '{END_DATE}' ): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:20px 0"><tr><td>
<div style="background:#000;border-radius:8px;padding:18px 16px;text-align:center">
  <p style="font-size:12px;color:' . self::ACCENT . ';text-transform:uppercase;letter-spacing:0.14em;margin:0 0 4px;font-weight:700;font-family:' . self::FONT_HEADING . '">⏰ ' . esc_html( $label ) . '</p>
  <p style="font-size:22px;color:#ffffff;margin:0;font-weight:800;letter-spacing:0.02em;font-family:' . self::FONT_HEADING . '">' . esc_html( $deadline_text ) . '</p>
</div>
</td></tr></table>';
	}

	/**
	 * ─── PARAGRAPH ──────────────────────────────────────────────
	 */
	public static function paragraph( string $text, string $align = 'left' ): string {
		return '<p style="color:' . self::TEXT_SECONDARY . ';font-size:15px;line-height:1.7;margin:0 0 16px;text-align:' . $align . '">' . $text . '</p>';
	}

	/**
	 * ─── SIGNATURE ──────────────────────────────────────────────
	 * Sign-off institucional de los emails transaccionales.
	 * Default: "Equipo Akibara · tu distrito del manga y cómics"
	 * El title default usa el tagline oficial (ver `self::tagline()`).
	 */
	public static function signature( string $name = 'Equipo Akibara', ?string $title = null ): string {
		if ( $title === null ) {
			$title = self::tagline();
		}
		return '<p style="color:' . self::TEXT_MUTED . ';font-size:14px;margin:24px 0 0;line-height:1.5">— ' . esc_html( $name ) . '<br><span style="font-size:12px">' . esc_html( $title ) . '</span></p>';
	}

	/**
	 * ─── FOOTER ─────────────────────────────────────────────────
	 * Shared footer with links and unsubscribe.
	 *
	 * @param string $email       Recipient email for unsubscribe link.
	 * @param string $unsub_salt  Salt for unsubscribe hash (module-specific).
	 */
	public static function footer( string $email = '', string $unsub_salt = 'akb_email_unsub' ): string {
		$html = '<tr><td style="padding:28px 28px;border-top:1px solid ' . self::BORDER . ';text-align:center">';

		// ── Links: Instagram · Tienda · WhatsApp · Contacto ──
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 16px"><tr>';
		$html .= '<td style="padding:0 10px"><a class="akb-social-link" href="https://www.instagram.com/akibara.cl/" style="text-decoration:none;color:' . self::TEXT_SECONDARY . ';font-size:13px;font-family:Inter,sans-serif">Instagram</a></td>';
		$html .= '<td style="color:' . self::BORDER . ';font-size:13px">·</td>';
		$html .= '<td style="padding:0 10px"><a class="akb-social-link" href="' . esc_url( self::utm( 'https://akibara.cl/tienda/', 'footer-shop' ) ) . '" style="text-decoration:none;color:' . self::TEXT_SECONDARY . ';font-size:13px;font-family:Inter,sans-serif">Ir a la tienda</a></td>';
		$html .= '<td style="color:' . self::BORDER . ';font-size:13px">·</td>';
		$html .= '<td style="padding:0 10px"><a class="akb-social-link" href="mailto:contacto@akibara.cl" style="text-decoration:none;color:' . self::TEXT_SECONDARY . ';font-size:13px;font-family:Inter,sans-serif">Contacto</a></td>';
		$html .= '</tr></table>';

		// ── Wordmark "akibara" + tagline oficial (usa self::tagline para single source of truth) ──
		$html .= '<p style="color:' . self::ACCENT . ';font-family:\'Bebas Neue\',Impact,sans-serif;font-size:18px;letter-spacing:0.12em;text-transform:lowercase;margin:0 0 4px;font-weight:700">akibara</p>';
		$html .= '<p style="color:' . self::TEXT_SECONDARY . ';font-size:12px;margin:0 0 14px;letter-spacing:0.02em">' . esc_html( self::tagline() ) . '</p>';

		// ── Trust signals (promesas de marca Akibara) ──
		$html .= '<p style="color:' . self::TEXT_MUTED . ';font-size:11px;line-height:1.6;margin:0 0 14px">';
		$html .= 'Envío a todo Chile · Retiro gratis en Metro San Miguel · Soporte real por WhatsApp';
		$html .= '</p>';

		// ── Unsubscribe ──
		if ( $email ) {
			if ( $email === '{EMAIL}' ) {
				$unsub_url = '{UNSUBSCRIBE_URL}';
			} else {
				$unsub_token = wp_hash( $email . $unsub_salt );
				$unsub_url   = add_query_arg(
					array(
						'akb_unsub' => '1',
						'email'     => rawurlencode( $email ),
						'token'     => $unsub_token,
						'type'      => $unsub_salt,
					),
					home_url( '/' )
				);
			}
			$html .= '<p style="margin:8px 0 0">';
			if ( $email === '{EMAIL}' ) {
				$html .= '<a href="{UNSUBSCRIBE_URL}" style="color:' . self::TEXT_MUTED . ';font-size:11px;text-decoration:underline">No quiero recibir estos emails</a>';
			} else {
				$html .= '<a href="' . esc_url( $unsub_url ) . '" style="color:' . self::TEXT_MUTED . ';font-size:11px;text-decoration:underline">No quiero recibir estos emails</a>';
			}
			$html .= '</p>';
		}

		$html .= '</td></tr>';
		return $html;
	}

	/**
	 * ─── CLOSE ──────────────────────────────────────────────────
	 */
	public static function close(): string {
		return '</table></td></tr></table></body></html>';
	}

	/**
	 * ─── FULL EMAIL ─────────────────────────────────────────────
	 * Convenience method to build a complete email.
	 */
	public static function build( string $preheader, callable $body_fn, string $email = '', string $unsub_salt = 'akb_email_unsub' ): string {
		$html  = self::open();
		$html .= self::header( $preheader );
		$html .= self::content_open();
		$html .= $body_fn();
		$html .= self::content_close();
		$html .= self::footer( $email, $unsub_salt );
		$html .= self::close();
		return $html;
	}
}
