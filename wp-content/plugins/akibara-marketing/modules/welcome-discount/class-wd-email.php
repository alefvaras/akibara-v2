<?php
/**
 * Akibara Welcome Discount — Email Templates
 *
 * Two transactional emails:
 *   1. Confirmation (double opt-in) — link to confirm subscription
 *   2. Coupon delivery — code + CTA to shop
 *
 * Transport: AkibaraBrevo (if available) → wp_mail fallback.
 * Uses AkibaraEmailTemplate for consistent dark branding.
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/class-wd-email.php
 * Adaptations:
 *   - Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 *   - Removed `use Akibara\Infra\EmailTemplate as ET;` (was namespaced in legacy)
 *   - Dispatch method: \Akibara\Infra\Brevo → class_exists('AkibaraBrevo')
 *   - ET:: → AkibaraEmailTemplate:: (global class registered by akibara-core)
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}
if ( ! defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

class Akibara_WD_Email {

	public static function build_confirmation_url( string $raw_token ): string {
		return add_query_arg( 'akb_wd_confirm', rawurlencode( $raw_token ), home_url( '/' ) );
	}

	// ─── Confirmation email ──────────────────────────────────────────

	public static function send_confirmation( string $email, string $raw_token ): bool {
		if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
			return self::dispatch_wp_mail(
				$email,
				'Confirma tu suscripcion para recibir tu descuento — Akibara',
				'<p>Confirma en: ' . esc_url( self::build_confirmation_url( $raw_token ) ) . '</p>',
				Akibara_WD_Settings::all()
			);
		}

		$confirm_url = self::build_confirmation_url( $raw_token );

		$html = AkibaraEmailTemplate::build(
			'Falta un paso — confirma tu email para recibir tu descuento.',
			static function () use ( $confirm_url ): string {
				$out  = AkibaraEmailTemplate::headline( '¡Ya casi!', 'un paso mas' );
				$out .= AkibaraEmailTemplate::paragraph( 'Haz clic para confirmar tu email y te enviamos tu descuento al instante:' );
				$out .= AkibaraEmailTemplate::cta( 'Confirmar y recibir mi descuento', $confirm_url, 'wd-confirm' );
				$out .= '<p style="color:#666;font-size:13px;text-align:center;margin:0">El enlace es valido por 48 horas.</p>';
				return $out;
			},
			$email,
			'akb_wd_unsub'
		);

		return self::dispatch(
			$email,
			'¡Confirma tu suscripcion y recibe tu descuento! — Akibara',
			$html,
			Akibara_WD_Settings::all()
		);
	}

	// ─── Coupon delivery email ───────────────────────────────────────

	public static function send_coupon( string $email, string $coupon_code, array $settings ): bool {
		if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
			$shop_url = wc_get_page_permalink( 'shop' );
			return self::dispatch_wp_mail(
				$email,
				'Tu cupon de bienvenida te espera — Akibara',
				'<p>Tu cupon: <strong>' . esc_html( $coupon_code ) . '</strong>. <a href="' . esc_url( $shop_url ) . '">Ir a la tienda</a></p>',
				$settings
			);
		}

		$shop_url   = wc_get_page_permalink( 'shop' );
		$amount_str = $settings['discount_type'] === 'percent'
			? "{$settings['amount']}% de descuento"
			: '$' . number_format( (int) $settings['amount'], 0, ',', '.' ) . ' de descuento';

		$html = AkibaraEmailTemplate::build(
			"Tu descuento de {$settings['amount']}% esta esperandote — usalo en tu primera compra.",
			static function () use ( $coupon_code, $amount_str, $shop_url, $settings ): string {
				$out  = AkibaraEmailTemplate::headline( '¡Bienvenido a Akibara!', 'tu distrito del manga y comics' );
				$out .= AkibaraEmailTemplate::paragraph( 'Tu suscripcion fue confirmada. Aqui esta tu cupon de bienvenida:' );
				$out .= AkibaraEmailTemplate::coupon_box( $coupon_code, $amount_str );
				if ( (int) $settings['min_order'] > 0 ) {
					$out .= '<p style="color:#666;font-size:13px;text-align:center;margin:0 0 8px">Valido en compras sobre $'
						. number_format( (int) $settings['min_order'], 0, ',', '.' )
						. ' CLP.</p>';
				}
				$out .= '<p style="color:#666;font-size:12px;text-align:center;margin:0 0 24px">Valido por '
					. (int) $settings['validity_days']
					. ' dias · Solo primer pedido · Uso unico</p>';
				$out .= AkibaraEmailTemplate::cta( 'Ir a la tienda', $shop_url, 'wd-coupon' );
				return $out;
			},
			$email,
			'akb_wd_unsub'
		);

		return self::dispatch(
			$email,
			'¡Tu cupon de bienvenida te espera! — Akibara',
			$html,
			$settings
		);
	}

	// ─── Internals ───────────────────────────────────────────────────

	private static function dispatch( string $email, string $subject, string $html, array $settings ): bool {
		if ( class_exists( 'AkibaraBrevo' ) ) {
			$api_key = \AkibaraBrevo::get_api_key();
			if ( $api_key ) {
				$to = \AkibaraBrevo::test_recipient( $email );
				wp_remote_post(
					'https://api.brevo.com/v3/smtp/email',
					array(
						'timeout' => 15,
						'headers' => array(
							'api-key'      => $api_key,
							'Content-Type' => 'application/json',
						),
						'body'    => wp_json_encode(
							array(
								'sender'     => array(
									'name'  => $settings['from_name'],
									'email' => $settings['from_email'],
								),
								'to'         => array( array( 'email' => $to ) ),
								'subject'    => $subject,
								'htmlContent' => $html,
							)
						),
					)
				);
				return true;
			}
		}

		return self::dispatch_wp_mail( $email, $subject, $html, $settings );
	}

	private static function dispatch_wp_mail( string $email, string $subject, string $html, array $settings ): bool {
		$set_html = static function () {
			return 'text/html';
		};
		add_filter( 'wp_mail_content_type', $set_html );
		$result = wp_mail(
			$email,
			$subject,
			$html,
			array( 'From: ' . $settings['from_name'] . ' <' . $settings['from_email'] . '>' )
		);
		remove_filter( 'wp_mail_content_type', $set_html );
		return (bool) $result;
	}
}
