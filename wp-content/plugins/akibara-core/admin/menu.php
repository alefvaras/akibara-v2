<?php
/**
 * Akibara Core — Top-level admin menu (Sprint 5.5 admin reorg 2026-04-27).
 *
 * Registra el menu top-level "Akibara" en posición 56 (entre Comments=25
 * y WooCommerce=58). Todos los addons usan `add_submenu_page('akibara', ...)`
 * en lugar de parent_slug 'woocommerce' para consolidar UX.
 *
 * Decisiones mesa técnica (mesa-22 + mesa-15 + mesa-23):
 *  - Single source of truth: parent menu solo aquí, en admin_menu:9.
 *  - Capability: manage_woocommerce (YAGNI hasta M2 con roles granulares).
 *  - Position 56: junto a WC, ergonomía proximidad.
 *  - Failure mode: addons hacen `if (!function_exists('akibara_core_admin_menu_slug')) return;`
 *  - Naming chileno (mesa-06): Panel/Preventas/Marketing/Inventario/MercadoLibre/
 *    WhatsApp/Reportes/Ajustes (NO voseo, NO Dashboard, sí Ajustes).
 *
 * @package Akibara\Core\Admin
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slug constante del top-level menu — usado por addons.
 */
if ( ! function_exists( 'akibara_core_admin_menu_slug' ) ) {
	function akibara_core_admin_menu_slug(): string {
		return 'akibara';
	}
}

/**
 * Registrar top-level menu Akibara en admin_menu:9 (antes que addons en :10).
 */
add_action(
	'admin_menu',
	function (): void {
		// SVG icon (24x24 minimal Manga emblem) — base64 data URL.
		// currentColor para heredar color sidebar (mesa-05: WP usa background-image
		// con aria-hidden div, NO necesita ARIA manual).
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 2.18L19.5 8 12 11.82 4.5 8 12 4.18zM4 9.43l7 3.62v7.51l-7-3.5V9.43zm9 11.13v-7.51l7-3.62v7.63l-7 3.5z"/></svg>';

		add_menu_page(
			__( 'Akibara', 'akibara' ),       // page_title (HTML title de la página)
			__( 'Akibara', 'akibara' ),       // menu_title (sidebar — solo brand, 7 chars OK)
			'manage_woocommerce',              // capability — YAGNI: WC consistency, no custom cap aún
			akibara_core_admin_menu_slug(),    // slug 'akibara'
			'akibara_core_admin_dashboard',    // callback — render dashboard
			'data:image/svg+xml;base64,' . base64_encode( $svg ),
			56                                 // position — entre Comments(25) y WC(58)
		);

		// Submenu Panel (= replace default "Akibara" submenu auto-creado por WP).
		// Patrón: cuando add_menu_page() registra parent, WP crea auto-submenu
		// con label === parent menu label. Lo renombramos a "Panel".
		add_submenu_page(
			akibara_core_admin_menu_slug(),
			__( 'Panel — Akibara', 'akibara' ),
			__( 'Panel', 'akibara' ),
			'manage_woocommerce',
			akibara_core_admin_menu_slug(),    // mismo slug = override del default
			'akibara_core_admin_dashboard'
		);
	},
	9 // priority 9 — antes que addons en priority 10
);

/**
 * Render dashboard landing page (Sprint 5.5 minimum viable).
 *
 * Sprint 5.5 alcance: cards con KPIs básicos + quick links a sub-pages.
 * Sprint 6+ podrá expandirse con widgets configurables.
 */
function akibara_core_admin_dashboard(): void {
	require_once AKIBARA_CORE_DIR . 'admin/views/dashboard.php';
}

/**
 * Enqueue admin CSS solo en pages bajo Akibara menu.
 *
 * mesa-22: usar $screen->base con prefix check (parent_base es null en top-level).
 * mesa-05: aria-current="page" ya inyectado por WP — solo styling visual.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		// Match cualquier admin page del menu Akibara.
		// Top-level page hook = "toplevel_page_akibara".
		// Sub-pages hook = "akibara_page_{$slug}".
		if (
			'toplevel_page_akibara' !== $hook
			&& ! str_starts_with( $hook, 'akibara_page_' )
		) {
			return;
		}

		wp_enqueue_style(
			'akibara-admin',
			plugins_url( 'assets/css/admin.css', AKIBARA_CORE_FILE ),
			array(),
			defined( 'AKIBARA_CORE_VERSION' ) ? AKIBARA_CORE_VERSION : '1.1.0'
		);
	}
);

/**
 * Migration admin notice (one-shot, dismissable per user).
 *
 * mesa-05: role="note" + aria-live="polite" (NO assertive — informativo).
 * Estado dismiss en user_meta para persistir entre page loads.
 */
add_action(
	'admin_notices',
	function (): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		// Solo mostrar a usuarios con manage_woocommerce (admins/shop_managers).
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Skip si ya fue descartado por este usuario.
		if ( get_user_meta( $user_id, 'akibara_admin_reorg_notice_dismissed', true ) ) {
			return;
		}
		// No mostrar EN el panel Akibara mismo (redundante).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ( 'toplevel_page_akibara' === $screen->base || str_starts_with( $screen->base, 'akibara_page_' ) ) ) {
			return;
		}

		$dashboard_url = admin_url( 'admin.php?page=' . akibara_core_admin_menu_slug() );
		$dismiss_url   = wp_nonce_url(
			add_query_arg( 'akibara_dismiss_reorg_notice', '1', admin_url() ),
			'akibara_dismiss_reorg_notice',
			'_akb_nonce'
		);
		?>
		<div class="notice notice-info is-dismissible akibara-reorg-notice"
			role="note"
			aria-live="polite">
			<p>
				<strong>Akibara:</strong>
				Las opciones de Akibara se movieron al menú lateral
				<a href="<?php echo esc_url( $dashboard_url ); ?>">Akibara →</a>.
				Buscá Preventas, Marketing, Inventario, MercadoLibre, WhatsApp, Reportes y Ajustes ahí.
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;">Entendido</a>
			</p>
		</div>
		<?php
	}
);

/**
 * Handler para dismiss permanente del notice de migración.
 */
add_action(
	'admin_init',
	function (): void {
		if ( empty( $_GET['akibara_dismiss_reorg_notice'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_akb_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_akb_nonce'] ), 'akibara_dismiss_reorg_notice' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, 'akibara_admin_reorg_notice_dismissed', '1' );
			wp_safe_redirect( remove_query_arg( array( 'akibara_dismiss_reorg_notice', '_akb_nonce' ) ) );
			exit;
		}
	}
);
