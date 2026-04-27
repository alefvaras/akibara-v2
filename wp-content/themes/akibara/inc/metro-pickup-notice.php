<?php
/**
 * Metro San Miguel — aviso post-pago.
 *
 * Cuando el cliente eligió "Retiro gratis metro San Miguel" (local_pickup:70),
 * inyectamos un bloque con instrucciones + CTA WhatsApp:
 *
 *   1. Dentro del email transaccional de WooCommerce (antes de la tabla).
 *   2. En la página "Gracias por tu compra".
 *
 * No crea emails adicionales: aprovecha los que Woo ya envía.
 *
 * @package Akibara
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instance ID del shipping method de retiro Metro San Miguel.
 * (local_pickup:70).
 */
define( 'AKIBARA_METRO_PICKUP_INSTANCE_ID', 70 );

/**
 * Teléfono WhatsApp de coordinación (sin +).
 */
define( 'AKIBARA_METRO_PICKUP_WA', function_exists( 'akibara_whatsapp_get_business_number' ) ? akibara_whatsapp_get_business_number() : '' );

/**
 * Dirección visible del punto de retiro.
 */
define( 'AKIBARA_METRO_PICKUP_ADDRESS', 'Estación de Metro San Miguel · Línea 2' );

/**
 * Horario visible del punto de retiro.
 */
define( 'AKIBARA_METRO_PICKUP_HOURS', 'Lunes a Viernes · 10:00 – 19:00 (coordinado)' );

/**
 * Detectar si una orden es retiro Metro San Miguel.
 *
 * @param WC_Order|int|null $order Orden a inspeccionar (ID u objeto).
 */
function akibara_order_is_metro_pickup( $order ): bool {
	if ( ! $order ) {
		return false;
	}
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( (int) $order );
	}
	if ( ! $order instanceof WC_Order ) {
		return false;
	}

	foreach ( $order->get_shipping_methods() as $item ) {
		$method_id   = (string) $item->get_method_id();
		$instance_id = (int) $item->get_instance_id();
		if ( 'local_pickup' === $method_id && AKIBARA_METRO_PICKUP_INSTANCE_ID === $instance_id ) {
			return true;
		}
		// Fallback: algunas configs de Woo guardan label como "Retiro gratis metro San Miguel".
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $item->get_name() ) : strtolower( (string) $item->get_name() );
		if ( 'local_pickup' === $method_id && false !== strpos( $name, 'san miguel' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Construir URL de WhatsApp con la orden pre-cargada.
 */
function akibara_metro_pickup_wa_url( WC_Order $order ): string {
	$order_id = $order->get_order_number();
	$customer = trim( $order->get_billing_first_name() );
	$text     = sprintf(
		'Hola, soy %s. Quiero coordinar el retiro en metro San Miguel de mi orden #%s.',
		$customer ? $customer : 'cliente',
		$order_id
	);
	return 'https://wa.me/' . AKIBARA_METRO_PICKUP_WA . '?text=' . rawurlencode( $text );
}

/**
 * Render HTML del aviso Metro para contexto checkout/web (thank-you page).
 */
function akibara_render_metro_pickup_notice( WC_Order $order ): void {
	$wa_url = akibara_metro_pickup_wa_url( $order );
	?>
	<section class="akb-metro-notice" aria-label="Instrucciones de retiro en metro San Miguel">
		<div class="akb-metro-notice__icon" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
		</div>
		<div class="akb-metro-notice__body">
			<h3 class="akb-metro-notice__title">Tu retiro gratis está confirmado</h3>
			<p class="akb-metro-notice__text">Nos pondremos en contacto contigo para coordinar día y hora del retiro. También puedes escribirnos tú ahora por WhatsApp — tenemos tu orden a la vista.</p>
			<dl class="akb-metro-notice__meta">
				<div>
					<dt>Punto de retiro</dt>
					<dd><?php echo esc_html( AKIBARA_METRO_PICKUP_ADDRESS ); ?></dd>
				</div>
				<div>
					<dt>Horario</dt>
					<dd><?php echo esc_html( AKIBARA_METRO_PICKUP_HOURS ); ?></dd>
				</div>
			</dl>
			<a class="akb-metro-notice__cta" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.52 3.449A12 12 0 0 0 3.45 20.52L2 22l1.48-1.449A12 12 0 1 0 20.52 3.45zM12 20.04a8.04 8.04 0 0 1-4.093-1.119l-.293-.175-3.051.797.816-2.972-.192-.306A8.03 8.03 0 1 1 12 20.04zm4.43-6.01c-.244-.122-1.441-.712-1.664-.793-.223-.082-.385-.122-.548.122-.162.244-.629.793-.772.955-.142.163-.284.183-.528.061-.244-.122-1.032-.38-1.965-1.213-.726-.648-1.217-1.448-1.359-1.692-.142-.244-.015-.376.107-.497.11-.11.244-.285.366-.427.122-.142.162-.244.244-.406.082-.163.041-.305-.02-.427-.061-.122-.548-1.323-.751-1.814-.198-.476-.399-.411-.548-.419l-.467-.009c-.163 0-.427.061-.651.305-.223.244-.854.835-.854 2.036 0 1.2.874 2.361.996 2.523.122.163 1.72 2.627 4.166 3.684.583.252 1.038.403 1.392.515.585.186 1.118.16 1.54.097.47-.07 1.441-.589 1.645-1.158.204-.569.204-1.057.143-1.158-.061-.102-.223-.163-.467-.285z"/></svg>
				<span>Coordinar retiro por WhatsApp</span>
			</a>
		</div>
	</section>
	<?php
}

/**
 * Render del bloque en EMAILS transaccionales.
 * Usamos HTML inline-friendly (tablas, estilos en línea) para clients de correo.
 */
function akibara_render_metro_pickup_notice_email( WC_Order $order, bool $sent_to_admin, bool $plain_text ): void {
	if ( $sent_to_admin ) {
		return;
	}
	if ( ! akibara_order_is_metro_pickup( $order ) ) {
		return;
	}

	$wa_url  = akibara_metro_pickup_wa_url( $order );
	$address = AKIBARA_METRO_PICKUP_ADDRESS;
	$hours   = AKIBARA_METRO_PICKUP_HOURS;

	if ( $plain_text ) {
		echo "\n====================\n";
		echo "RETIRO GRATIS CONFIRMADO - Metro San Miguel\n";
		echo "Punto: {$address}\n";
		echo "Horario: {$hours}\n";
		echo "Coordinar por WhatsApp: {$wa_url}\n";
		echo "====================\n\n";
		return;
	}
	?>
	<div style="margin:0 0 24px;padding:20px;background:#f8f8fa;border-left:4px solid #D90010;border-radius:4px;font-family:Arial,Helvetica,sans-serif;color:#1f1f1f;">
		<h3 style="margin:0 0 8px;font-size:16px;color:#111;font-weight:700;">Tu retiro gratis está confirmado</h3>
		<p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#444;">Nos pondremos en contacto contigo para coordinar día y hora del retiro. También puedes escribirnos tú ahora por WhatsApp — tenemos tu orden a la vista.</p>
		<table role="presentation" style="margin:0 0 14px;font-size:13px;color:#555;" cellpadding="0" cellspacing="0">
			<tr>
				<td style="padding:2px 12px 2px 0;color:#888;">Punto de retiro:</td>
				<td style="padding:2px 0;color:#222;font-weight:600;"><?php echo esc_html( $address ); ?></td>
			</tr>
			<tr>
				<td style="padding:2px 12px 2px 0;color:#888;">Horario:</td>
				<td style="padding:2px 0;color:#222;font-weight:600;"><?php echo esc_html( $hours ); ?></td>
			</tr>
		</table>
		<a href="<?php echo esc_url( $wa_url ); ?>" style="display:inline-block;padding:10px 18px;background:#25D366;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:4px;">Coordinar retiro por WhatsApp</a>
	</div>
	<?php
}

// Hook email (antes de la tabla de la orden) — prioridad 15 para salir antes del "items table".
add_action(
	'woocommerce_email_before_order_table',
	'akibara_render_metro_pickup_notice_email',
	15,
	3
);

// Hook thank-you page — insertamos antes de los detalles de la orden.
add_action(
	'woocommerce_thankyou',
	function ( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order || ! akibara_order_is_metro_pickup( $order ) ) {
			return;
		}
		akibara_render_metro_pickup_notice( $order );
	},
	5
);

/**
 * Estilos CSS del aviso en la thank-you page.
 * Inyectados inline para evitar cargar el bundle completo de checkout.
 */
add_action(
	'wp_head',
	function (): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		?>
		<style id="akb-metro-notice-css">
			.akb-metro-notice {
				display: flex;
				align-items: flex-start;
				gap: 16px;
				margin: 0 0 24px;
				padding: 18px 20px;
				background: var(--aki-surface-2, #161618);
				border: 1px solid var(--aki-border, #2A2A2E);
				border-left: 4px solid var(--aki-red, #D90010);
				border-radius: 4px;
				color: var(--aki-text, #F5F5F5);
			}
			.akb-metro-notice__icon {
				flex-shrink: 0;
				width: 36px;
				height: 36px;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				background: color-mix(in srgb, var(--aki-red, #D90010) 14%, transparent);
				color: var(--aki-red, #D90010);
				border-radius: 50%;
			}
			.akb-metro-notice__body { flex: 1; min-width: 0; }
			.akb-metro-notice__title {
				margin: 0 0 6px;
				font-family: var(--font-heading, 'Bebas Neue', sans-serif);
				font-size: 18px;
				letter-spacing: 0.02em;
				color: var(--aki-white, #fff);
			}
			.akb-metro-notice__text {
				margin: 0 0 14px;
				font-size: 14px;
				line-height: 1.55;
				color: var(--aki-text-muted, #A0A0A0);
			}
			.akb-metro-notice__meta {
				display: grid;
				grid-template-columns: 1fr;
				gap: 6px 18px;
				margin: 0 0 16px;
				font-size: 13px;
			}
			@media (min-width: 640px) {
				.akb-metro-notice__meta { grid-template-columns: auto 1fr; }
			}
			.akb-metro-notice__meta dt {
				color: var(--aki-text-dim, #666);
				text-transform: uppercase;
				letter-spacing: 0.04em;
				font-size: 11px;
				margin: 0;
			}
			.akb-metro-notice__meta dd {
				margin: 0;
				color: var(--aki-white, #fff);
				font-weight: 600;
			}
			.akb-metro-notice__cta {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 10px 18px;
				background: #25D366;
				color: #0a0a0a !important;
				border-radius: 4px;
				font-size: 14px;
				font-weight: 700;
				text-decoration: none;
				transition: background 0.15s ease;
			}
			.akb-metro-notice__cta:hover { background: #1eb558; }
			.akb-metro-notice__cta svg { flex-shrink: 0; }
		</style>
		<?php
	}
);
