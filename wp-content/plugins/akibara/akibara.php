<?php
/**
 * Plugin Name:  Akibara
 * Plugin URI:   https://akibara.com
 * Description:  Búsqueda AJAX ultrarrápida (FULLTEXT + SHORTINIT) + Ordenamiento por Tomo + Descuentos por Taxonomía para WooCommerce.
 * Version:      10.0.0
 * Author:       Akibara
 * Text Domain:  akibara
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:      9.9
 */

defined( 'ABSPATH' ) || exit;

// ─── Protección contra doble carga (sin bloquear plugins antiguos) ─
// Se usa el nombre de la clase como guardia, no una constante global.
// Esto permite que plugins de descuentos separados (akibara-descuento-*)
// sigan funcionando mientras se hace la migración.
if ( defined( 'AKIBARA_V10_LOADED' ) ) {
	return;
}
define( 'AKIBARA_V10_LOADED', true );

define( 'AKIBARA_VERSION', '10.0.0' );
define( 'AKIBARA_DIR', plugin_dir_path( __FILE__ ) );
define( 'AKIBARA_URL', plugin_dir_url( __FILE__ ) );
define( 'AKIBARA_FILE', __FILE__ );

// ─── Compatibilidad WooCommerce HPOS ──────────────────────────────
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// ─── Autoloader PSR-4 (Akibara\...) — debe cargar antes de cualquier módulo ──
require_once AKIBARA_DIR . 'includes/autoload.php';

// ─── Cargar helpers compartidos (deben estar disponibles antes de los módulos) ──
require_once AKIBARA_DIR . 'includes/helpers/ajax.php';

// ─── Cargar módulos core ──────────────────────────────────────────
// Estos son dependencias duras del resto del plugin: se cargan
// directamente (no van por registry) para garantizar disponibilidad.
//
// Sprint 2 Cell Core Phase 1 (2026-04-27): si akibara-core plugin loaded,
// estos 4 modules ya cargaron desde plugins/akibara-core/. Skip duplicate require.
require_once AKIBARA_DIR . 'includes/akibara-core.php'; // internal file, NO related a plugin akibara-core/
if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	// Migrated to plugin akibara-core/ Phase 1 — load aquí solo si akibara-core no active.
	require_once AKIBARA_DIR . 'includes/akibara-category-urls.php';
	require_once AKIBARA_DIR . 'includes/akibara-search.php';
	require_once AKIBARA_DIR . 'includes/akibara-order.php';
}
require_once AKIBARA_DIR . 'includes/class-akibara-email-template.php';
if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	// Migrated to plugin akibara-core/ Phase 1.
	// Testing mode: redirige wp_mail a alejandro.fvaras@gmail.com si AKIBARA_EMAIL_TESTING_MODE=true.
	// Noop en prod (constante no definida). Ver includes/akibara-email-safety.php.
	require_once AKIBARA_DIR . 'includes/akibara-email-safety.php';
}

// ─── Module Registry (ADR-002 R2) ─────────────────────────────────
// Catálogo único de módulos con toggles + metadata. Preserva orden
// de carga (insertion order) — crítico para dependencias entre módulos.
require_once AKIBARA_DIR . 'includes/class-akibara-module-registry.php';

if ( class_exists( 'Akibara_Module_Registry' ) ) {

	/**
	 * Helper local: resuelve el estado `enabled` respetando ADR-005.
	 * Si `akb_is_module_enabled()` existe (mu-plugin), delegamos en él;
	 * si no, default true para preservar comportamiento legacy.
	 */
	$akb_enabled = static function ( string $key ): bool {
		if ( function_exists( 'akb_is_module_enabled' ) ) {
			return (bool) akb_is_module_enabled( $key );
		}
		return true;
	};

	// --- descuentos (condicional: sólo si no hay plugin externo) ---
	if ( ! class_exists( 'Akibara_Descuento_Taxonomia' ) ) {
		Akibara_Module_Registry::register(
			'descuentos',
			array(
				'label'   => 'Descuentos por Taxonomía',
				'version' => '1.0.0',
				'enabled' => $akb_enabled( 'descuentos' ),
				'path'    => 'modules/descuentos/module.php',
				'group'   => 'general',
			)
		);
		Akibara_Module_Registry::register(
			'descuentos-tramos',
			array(
				'label'   => 'Tramos de volumen por serie',
				'version' => '1.1.0',
				'enabled' => $akb_enabled( 'descuentos' ),
				'path'    => 'modules/descuentos/tramos-setup.php',
				'since'   => '11.1.0',
				'group'   => 'general',
			)
		);
	}

	Akibara_Module_Registry::register(
		'banner',
		array(
			'label'   => 'Banner rotativo',
			'enabled' => $akb_enabled( 'banner' ),
			'path'    => 'modules/banner/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'popup',
		array(
			'label'   => 'Popup de bienvenida',
			'enabled' => $akb_enabled( 'popup' ),
			'path'    => 'modules/popup/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'brevo',
		array(
			'label'   => 'Segmentación Brevo',
			'enabled' => $akb_enabled( 'brevo' ),
			'path'    => 'modules/brevo/module.php',
			'group'   => 'integration',
		)
	);

	Akibara_Module_Registry::register(
		'review-request',
		array(
			'label'   => 'Solicitud de reseñas',
			'enabled' => $akb_enabled( 'review-request' ),
			'path'    => 'modules/review-request/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'next-volume',
		array(
			'label'   => 'Siguiente tomo',
			'enabled' => $akb_enabled( 'next-volume' ),
			'path'    => 'modules/next-volume/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'cart-abandoned',
		array(
			'label'   => 'Carritos abandonados',
			'enabled' => $akb_enabled( 'cart-abandoned' ),
			'path'    => 'modules/cart-abandoned/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'installments',
		array(
			'label'   => 'Cuotas sin interés',
			'enabled' => $akb_enabled( 'installments' ),
			'path'    => 'modules/installments/module.php',
			'group'   => 'general',
		)
	);

	Akibara_Module_Registry::register(
		'rut',
		array(
			'label'   => 'RUT Chile',
			'enabled' => $akb_enabled( 'rut' ),
			'path'    => 'modules/rut/module.php',
			'group'   => 'general',
		)
	);

	Akibara_Module_Registry::register(
		'phone',
		array(
			'label'   => 'Teléfono Chile',
			'enabled' => $akb_enabled( 'phone' ),
			'path'    => 'modules/phone/module.php',
			'group'   => 'general',
		)
	);

	// checkout-validation: el archivo puede no existir aún. El registry
	// tolera esto vía file_exists() + warn en akb_log sin romper el plugin.
	Akibara_Module_Registry::register(
		'checkout-validation',
		array(
			'label'   => 'Checkout Validation',
			'enabled' => $akb_enabled( 'checkout-validation' ),
			'path'    => 'modules/checkout-validation/module.php',
			'group'   => 'ops',
		)
	);

	Akibara_Module_Registry::register(
		'series-notify',
		array(
			'label'   => 'Notificación de series',
			'enabled' => $akb_enabled( 'series-notify' ),
			'path'    => 'modules/series-notify/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'back-in-stock',
		array(
			'label'   => 'Back in Stock (avísame)',
			'enabled' => $akb_enabled( 'back-in-stock' ),
			'path'    => 'modules/back-in-stock/module.php',
			'group'   => 'marketing',
		)
	);

	// Test Stock Restore — REGISTRO CONDICIONAL solo si entorno local docker.
	// En prod (Hostinger) AKIBARA_LOCAL_REPLICA no está definida → este bloque
	// no se ejecuta y el módulo nunca se inscribe en el registry. Defensa
	// adicional al quintuple guard interno del módulo + el exclude en deploy.sh.
	if (
		file_exists( AKIBARA_DIR . 'modules/test-stock-restore/module.php' )
		&& defined( 'AKIBARA_LOCAL_REPLICA' ) && AKIBARA_LOCAL_REPLICA
	) {
		Akibara_Module_Registry::register(
			'test-stock-restore',
			array(
				'label'   => 'Test Stock Restore (LOCAL ONLY)',
				'enabled' => $akb_enabled( 'test-stock-restore' ),
				'path'    => 'modules/test-stock-restore/module.php',
				'group'   => 'ops',
			)
		);
	}

	// Módulos registrados solo si el archivo está deployado — evita warnings
	// en el error_log cuando el código local adelanta al deploy a producción.
	if ( file_exists( AKIBARA_DIR . 'modules/editorial-notify/module.php' ) ) {
		Akibara_Module_Registry::register(
			'editorial-notify',
			array(
				'label'   => 'Alertas de reposición por editorial',
				'enabled' => $akb_enabled( 'editorial-notify' ),
				'path'    => 'modules/editorial-notify/module.php',
				'group'   => 'marketing',
			)
		);
	}

	if ( file_exists( AKIBARA_DIR . 'modules/customer-milestones/module.php' ) ) {
		Akibara_Module_Registry::register(
			'customer-milestones',
			array(
				'label'   => 'Cumpleaños + Aniversario del cliente',
				'enabled' => $akb_enabled( 'customer-milestones' ),
				'path'    => 'modules/customer-milestones/module.php',
				'group'   => 'marketing',
			)
		);
	}

	Akibara_Module_Registry::register(
		'review-incentive',
		array(
			'label'   => 'Incentivo de reseñas',
			'enabled' => $akb_enabled( 'review-incentive' ),
			'path'    => 'modules/review-incentive/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'referrals',
		array(
			'label'   => 'Referidos',
			'enabled' => $akb_enabled( 'referrals' ),
			'path'    => 'modules/referrals/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'ga4',
		array(
			'label'   => 'GA4 E-commerce Tracking',
			'enabled' => $akb_enabled( 'ga4' ),
			'path'    => 'modules/ga4/module.php',
			'group'   => 'analytics',
		)
	);

	Akibara_Module_Registry::register(
		'marketing-campaigns',
		array(
			'label'   => 'Marketing Campaigns',
			'enabled' => $akb_enabled( 'marketing-campaigns' ),
			'path'    => 'modules/marketing-campaigns/module.php',
			'group'   => 'marketing',
		)
	);

	Akibara_Module_Registry::register(
		'health-check',
		array(
			'label'   => 'Health Check',
			'enabled' => $akb_enabled( 'health-check' ),
			'path'    => 'modules/health-check/module.php',
			'group'   => 'ops',
		)
	);

	Akibara_Module_Registry::register(
		'inventory',
		array(
			'label'   => 'Inventario',
			'enabled' => $akb_enabled( 'inventory' ),
			'path'    => 'modules/inventory/module.php',
			'group'   => 'ops',
		)
	);

	Akibara_Module_Registry::register(
		'mercadolibre',
		array(
			'label'   => 'Mercado Libre',
			'enabled' => $akb_enabled( 'mercadolibre' ),
			'path'    => 'modules/mercadolibre/module.php',
			'group'   => 'integration',
		)
	);

	Akibara_Module_Registry::register(
		'finance-dashboard',
		array(
			'label'   => 'Finance Dashboard',
			'enabled' => $akb_enabled( 'finance-dashboard' ),
			'path'    => 'modules/finance-dashboard/module.php',
			'group'   => 'analytics',
		)
	);

	Akibara_Module_Registry::register(
		'shipping',
		array(
			'label'   => 'Shipping (BlueX + 12 Horas)',
			'enabled' => $akb_enabled( 'shipping' ),
			'path'    => 'modules/shipping/module.php',
			'since'   => '10.9.0',
			'group'   => 'integration',
		)
	);

	// Módulo image-normalize REMOVIDO 2026-04-20 por decisión del dueño.
	// Se eliminaron: módulo completo, metadata (_akb_img_*), subsizes físicos,
	// helper akibara_picture_tag del theme. WordPress vuelve a usar sizes default.

	Akibara_Module_Registry::register(
		'product-badges',
		array(
			'label'   => 'Product Badges',
			'enabled' => $akb_enabled( 'product-badges' ),
			'path'    => 'modules/product-badges/module.php',
			'since'   => '10.0.0',
			'group'   => 'marketing',
		)
	);

	// Sprint 2 Cell Core Phase 1 — customer-edit-address + address-autocomplete migrados a plugin akibara-core/.
	// Si akibara-core loaded, skip registry registration (modules already loaded desde plugin akibara-core/).
	if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
		Akibara_Module_Registry::register(
			'customer-edit-address',
			array(
				'label'   => 'Customer Edit Address',
				'enabled' => $akb_enabled( 'customer-edit-address' ),
				'path'    => 'modules/customer-edit-address/module.php',
				'group'   => 'general',
			)
		);

		Akibara_Module_Registry::register(
			'address-autocomplete',
			array(
				'label'   => 'Address Autocomplete (Google Places)',
				'enabled' => $akb_enabled( 'address-autocomplete' ),
				'path'    => 'modules/address-autocomplete/module.php',
				'group'   => 'integration',
			)
		);
	}

	Akibara_Module_Registry::register(
		'series-autofill',
		array(
			'label'   => 'Series Autofill (schema Book JSON-LD)',
			'enabled' => $akb_enabled( 'series-autofill' ),
			'path'    => 'modules/series-autofill/module.php',
			'group'   => 'analytics',
		)
	);

	Akibara_Module_Registry::register(
		'welcome-discount',
		array(
			'label'   => 'Descuento de Bienvenida',
			'enabled' => $akb_enabled( 'welcome-discount' ),
			'path'    => 'modules/welcome-discount/module.php',
			'since'   => '10.11.0',
			'group'   => 'marketing',
		)
	);

	// Módulo `mangadex-images` ELIMINADO 2026-04-26.
	// Razón: scanlation ES en MangaDex no agrupa chapters por volumen
	// (todos chapters en `volume: none`), causando que TODOS los volúmenes
	// de una serie mostraran la MISMA imagen mid-page. Para Kingdom 1-10+
	// el log mostró `chapter_method=fallback-vol-none-ch-831-es` para todos.
	// Cleanup completo aplicado: 284 productos sync removed, 1696 postmeta
	// deleted, tabla wp_akibara_mangadex_log dropped, 716 archivos físicos
	// (284 originales + 432 thumbnails) eliminados de uploads/.
	// Backup pre-deletion: docs/backups/mangadex-deletion-2026-04-26/.
	// Si en el futuro se quisiera re-implementar: usar opción A multi-language
	// con fallback heurístico — ver análisis en docs/SESSION-MANGADEX-IMAGES-V1-2026-04-25.md.

	unset( $akb_enabled );

	Akibara_Module_Registry::boot();

} else {
	// ─── Fallback defensivo ─────────────────────────────────────
	// Si por cualquier motivo el registry no está disponible,
	// caemos al esquema legacy de require_once directo. Esto
	// garantiza que la web no se rompe ante un fallo del registry.
	if ( ! class_exists( 'Akibara_Descuento_Taxonomia' ) ) {
		require_once AKIBARA_DIR . 'modules/descuentos/module.php';
		require_once AKIBARA_DIR . 'modules/descuentos/tramos-setup.php';
	}
	require_once AKIBARA_DIR . 'modules/banner/module.php';
	require_once AKIBARA_DIR . 'modules/popup/module.php';
	require_once AKIBARA_DIR . 'modules/brevo/module.php';
	require_once AKIBARA_DIR . 'modules/review-request/module.php';
	require_once AKIBARA_DIR . 'modules/next-volume/module.php';
	require_once AKIBARA_DIR . 'modules/cart-abandoned/module.php';
	require_once AKIBARA_DIR . 'modules/installments/module.php';
	require_once AKIBARA_DIR . 'modules/rut/module.php';
	require_once AKIBARA_DIR . 'modules/phone/module.php';
	if ( file_exists( AKIBARA_DIR . 'modules/checkout-validation/module.php' ) ) {
		require_once AKIBARA_DIR . 'modules/checkout-validation/module.php';
	}
	require_once AKIBARA_DIR . 'modules/series-notify/module.php';
	if ( file_exists( AKIBARA_DIR . 'modules/back-in-stock/module.php' ) ) {
		require_once AKIBARA_DIR . 'modules/back-in-stock/module.php';
	}
	require_once AKIBARA_DIR . 'modules/review-incentive/module.php';
	require_once AKIBARA_DIR . 'modules/referrals/module.php';
	require_once AKIBARA_DIR . 'modules/ga4/module.php';
	require_once AKIBARA_DIR . 'modules/marketing-campaigns/module.php';
	require_once AKIBARA_DIR . 'modules/health-check/module.php';
	require_once AKIBARA_DIR . 'modules/inventory/module.php';
	require_once AKIBARA_DIR . 'modules/mercadolibre/module.php';
	require_once AKIBARA_DIR . 'modules/finance-dashboard/module.php';
	// image-normalize removido 2026-04-20
	require_once AKIBARA_DIR . 'modules/product-badges/module.php';
	// Sprint 2 Cell Core Phase 1 — customer-edit-address + address-autocomplete migrados a plugin akibara-core/.
	if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
		require_once AKIBARA_DIR . 'modules/customer-edit-address/module.php';
		require_once AKIBARA_DIR . 'modules/address-autocomplete/module.php';
	}
	require_once AKIBARA_DIR . 'modules/series-autofill/module.php';
	if ( file_exists( AKIBARA_DIR . 'modules/welcome-discount/module.php' ) ) {
		require_once AKIBARA_DIR . 'modules/welcome-discount/module.php';
	}
}

// ─── Cargar dashboard admin unificado ─────────────────────
// file_exists() guards: un clone fresh o rollback sin estos archivos no
// debería causar un fatal — degrada silenciosamente (sin panel admin).
$_akb_admin_main = AKIBARA_DIR . 'admin/class-akibara-admin.php';
if ( file_exists( $_akb_admin_main ) ) {
	require_once $_akb_admin_main;
} else {
	error_log( '[Akibara] Missing admin/class-akibara-admin.php — admin dashboard disabled.' );
}
unset( $_akb_admin_main );

if ( is_admin() ) {
	$akb_admin_files = array(
		AKIBARA_DIR . 'admin/class-akibara-modules-admin.php',
		AKIBARA_DIR . 'admin/admin-notices.php',
	);
	foreach ( $akb_admin_files as $_f ) {
		if ( file_exists( $_f ) ) {
			require_once $_f;
		} else {
			error_log( '[Akibara] Missing admin file: ' . basename( $_f ) . ' — feature disabled.' );
		}
	}
	unset( $akb_admin_files, $_f );
}

// ─── Activación ───────────────────────────────────────────────────
register_activation_hook( __FILE__, 'akibara_v9_activate' );
function akibara_v9_activate(): void {
	akb_create_index_table();
	update_option( 'akibara_needs_rebuild', 1, false );
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'akibara_v9_deactivate' );
function akibara_v9_deactivate(): void {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'akibara_check_abandoned_carts' );
	wp_clear_scheduled_hook( 'akb_series_notify_cron' );
	wp_clear_scheduled_hook( 'akibara_next_volume_check' );
	wp_clear_scheduled_hook( 'akibara_brevo_weekly_sync' );
	wp_clear_scheduled_hook( 'akb_ml_health_sync' );
	wp_clear_scheduled_hook( 'akb_ml_stale_sync' );
}

// ─── Aviso de rebuild pendiente ───────────────────────────────────
add_action(
	'admin_notices',
	static function (): void {
		if ( ! get_option( 'akibara_needs_rebuild' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$url = admin_url( 'admin.php?page=akibara&tab=orden' );
		echo '<div class="notice notice-warning is-dismissible"><p>'
		. '📚 <strong>Akibara v10:</strong> Primera activación — '
		. '<a href="' . esc_url( $url ) . '"><strong>construye el índice de búsqueda</strong></a> '
		. 'para que la búsqueda sea instantánea.'
		. '</p></div>';
	}
);
