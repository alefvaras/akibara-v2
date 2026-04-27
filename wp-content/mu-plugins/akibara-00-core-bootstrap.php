<?php
/**
 * Plugin Name: Akibara 00 Core Bootstrap (mu-plugin loader)
 * Description: Loads akibara-core foundation plugin BEFORE regular plugins.
 *              Mesa-01 arbitration 2026-04-27 — option C (mu-plugin loader)
 *              elegida sobre option A (rename constant) por robustness vs
 *              suerte alphabetical + activación de wraps `if (!defined(...))`
 *              en plugin akibara legacy durante migración Sprint 2→4.
 * Version:     1.0.0
 * Author:      Akibara
 *
 * Naming convention: `akibara-00-` prefix forces alphabetical priority entre
 * los mu-plugins (load primero antes que akibara-brevo-smtp, akibara-sentry-
 * customizations, akibara-email-testing-guard, etc.). Esto matters porque
 * core puede definir constants/helpers que sentry/brevo mu-plugins consumen.
 *
 * Failsafe: si plugin akibara-core/ NO existe (deleted o not yet deployed),
 * NO fatal. Solo error_log + WP boot continúa normal. Admin verá plugin
 * missing en /wp-admin/plugins.php para reinstall.
 *
 * Trade-off documentado: plugin akibara-core no se puede "desactivar" via
 * UI admin (este mu-plugin lo carga incluso si admin desactivó). Esto es
 * INTENCIONAL — akibara-core es FOUNDATION para 5 addons Sprint 3+, no
 * debe ser opcional. Para "desactivar" realmente, admin debe borrar el
 * directorio plugins/akibara-core/.
 *
 * @package Akibara\Core\Bootstrap
 * @since   1.0.0 (Sprint 2 Cell Core Phase 1, 2026-04-27)
 */

defined( 'ABSPATH' ) || exit;

$akibara_core_main = WP_PLUGIN_DIR . '/akibara-core/akibara-core.php';

if ( file_exists( $akibara_core_main ) ) {
	require_once $akibara_core_main;
} else {
	// Failsafe: log warning + continúa boot. NO fatal.
	if ( function_exists( 'error_log' ) ) {
		error_log(
			sprintf(
				'[akibara-00-core-bootstrap] %s no encontrado. Plugin akibara-core required as foundation. Reinstall via /wp-admin/plugins.php.',
				$akibara_core_main
			)
		);
	}

	// Sprint 3+ TODO: cuando Sentry breadcrumbs helper esté disponible (post
	// `akibara-sentry-customizations.php` integration), agregar breadcrumb aquí.
	// Por ahora error_log es suficiente para Sentry PHP integration capture.
}
