<?php
/**
 * Akibara — Email Safety Filter
 *
 * Redirige TODOS los emails wp_mail a la dirección de test cuando
 * AKIBARA_EMAIL_TESTING_MODE está activo. Cubre emails WC + cualquier
 * wp_mail nativo (recuperación de contraseña, notificaciones admin, etc.)
 *
 * Para emails Brevo (wp_remote_post), el redirect vive en
 * Akibara\Infra\Brevo::test_recipient() — llamado en cada callsite.
 *
 * ACTIVAR solo en entornos locales/staging. NUNCA en prod.
 * En wp-config.php local:
 *   define('AKIBARA_EMAIL_TESTING_MODE', true);
 *   define('AKIBARA_TEST_EMAIL', 'alejandro.fvaras@gmail.com'); // opcional
 *
 * @package Akibara
 * @since   11.3.0
 */

defined( 'ABSPATH' ) || exit;
// Guard: cargar SOLO si plugin akibara legacy (V10) o akibara-core están active.
// Sprint 2 Cell Core Phase 1 — file relocated desde plugins/akibara/ a plugins/akibara-core/.
if ( ! defined( 'AKIBARA_V10_LOADED' ) && ! defined( 'AKIBARA_CORE_LOADED' ) ) {
	return;
}

/**
 * Aplica la transformación de testing a los args de wp_mail.
 * Función pura — testeable sin dependencias WP.
 *
 * @param array  $args      Array de args de wp_mail (to, subject, message, headers).
 * @param string $test_addr Dirección de testing que reemplaza al destinatario real.
 * @return array Args modificados.
 */
function akibara_email_safety_apply( array $args, string $test_addr ): array {
	$args['to']      = $test_addr;
	$args['cc']      = '';
	$args['bcc']     = '';
	$args['subject'] = '[TEST] ' . ( $args['subject'] ?? '' );
	$banner          = '<div style="background:#1a0000;border:2px solid #FF2020;padding:12px 16px;margin:0 0 20px;font-family:monospace;font-size:13px;color:#FF4D4D">⚠️ MODO TESTING — destinatario real reemplazado. Este email NO fue enviado a ningún cliente.</div>';
	$args['message'] = $banner . ( $args['message'] ?? '' );
	return $args;
}

if ( defined( 'AKIBARA_EMAIL_TESTING_MODE' ) && AKIBARA_EMAIL_TESTING_MODE ) {
	$test_addr = defined( 'AKIBARA_TEST_EMAIL' ) ? AKIBARA_TEST_EMAIL : 'alejandro.fvaras@gmail.com';
	add_filter( 'wp_mail', fn( array $args ) => akibara_email_safety_apply( $args, $test_addr ), 1 );
}
