<?php
/**
 * Akibara Core — Admin dashboard view.
 *
 * Dashboard real con KPIs live (no estático): orders del día, revenue,
 * preventas pendientes, productos low-stock, ML listings, etc. + quick
 * links agrupados por feature.
 *
 * Branding: dark theme matching customer-facing (var(--aki-*) tokens).
 *
 * @package Akibara\Core\Admin
 */

defined( 'ABSPATH' ) || exit;

$akb_slug = akibara_core_admin_menu_slug();

// Detectar qué addons están activos para mostrar/ocultar cards.
$akb_active = function ( string $plugin_file ): bool {
	$active = (array) get_option( 'active_plugins', array() );
	return in_array( $plugin_file, $active, true );
};

// ── KPIs LIVE ───────────────────────────────────────────────────────────────
global $wpdb;

// Today orders + revenue (HPOS aware via wc_get_orders).
$today_start = strtotime( 'today midnight' );
$kpi_orders_today  = 0;
$kpi_revenue_today = 0.0;
if ( function_exists( 'wc_get_orders' ) ) {
	$orders_today = wc_get_orders(
		array(
			'date_created' => '>=' . gmdate( 'Y-m-d', $today_start ),
			'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			'limit'        => -1,
			'return'       => 'objects',
		)
	);
	$kpi_orders_today = is_array( $orders_today ) ? count( $orders_today ) : 0;
	foreach ( (array) $orders_today as $o ) {
		if ( method_exists( $o, 'get_total' ) ) {
			$kpi_revenue_today += (float) $o->get_total();
		}
	}
}

// Preventas pendientes (akibara-preventas).
$kpi_preventas_pending = 0;
$preventas_table       = $wpdb->prefix . 'akb_preorders';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $preventas_table ) ) === $preventas_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $preventas_table es nombre constante.
	$kpi_preventas_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$preventas_table} WHERE status = 'pending'" );
}

// Encargos pendientes.
$kpi_encargos_pending = 0;
$encargos_table       = $wpdb->prefix . 'akb_special_orders';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $encargos_table ) ) === $encargos_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kpi_encargos_pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$encargos_table} WHERE status = 'pending'" );
}

// Back-in-Stock subscriptions.
$kpi_bis_subs = 0;
$bis_table    = $wpdb->prefix . 'akb_back_in_stock_subs';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bis_table ) ) === $bis_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kpi_bis_subs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$bis_table} WHERE status = 'pending'" );
}

// Low stock (< 5 units).
$kpi_low_stock = 0;
if ( function_exists( 'wc_get_products' ) ) {
	$low_stock_products = $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
		INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = '_stock' AND CAST(pm.meta_value AS SIGNED) BETWEEN 1 AND 4
		AND p.post_type = 'product' AND p.post_status = 'publish'"
	);
	$kpi_low_stock = (int) $low_stock_products;
}

// ML listings active.
$kpi_ml_listings = 0;
$ml_table        = $wpdb->prefix . 'akb_ml_listings';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ml_table ) ) === $ml_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kpi_ml_listings = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ml_table} WHERE status = 'active'" );
}

// Brevo configured?
$brevo_configured = ! empty( get_option( 'akibara_brevo_api_key' ) ) || defined( 'AKB_BREVO_API_KEY' );

$cards = array(
	array(
		'icon'  => '🎌',
		'title' => __( 'Preventas', 'akibara' ),
		'desc'  => __( 'Reservas manga + notificaciones editoriales.', 'akibara' ),
		'links' => array(
			array( 'akb-reservas', __( 'Reservas', 'akibara' ) ),
			array( 'akibara-editorial-notify', __( 'Notif. Editoriales', 'akibara' ) ),
			array( 'akibara-next-volume', __( 'Próximo Tomo', 'akibara' ) ),
			array( 'akibara-encargos', __( 'Encargos', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-preventas/akibara-preventas.php' ),
	),
	array(
		'icon'  => '📣',
		'title' => __( 'Marketing', 'akibara' ),
		'desc'  => __( 'Campañas email + topbar + descuentos.', 'akibara' ),
		'links' => array(
			array( 'akibara-marketing-campaigns', __( 'Campañas', 'akibara' ) ),
			array( 'akibara-topbar', __( 'Topbar', 'akibara' ) ),
			array( 'akibara-brevo', __( 'Brevo', 'akibara' ) ),
			array( 'akibara-descuentos', __( 'Descuentos', 'akibara' ) ),
			array( 'akibara-welcome-discount', __( 'Welcome Discount', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-marketing/akibara-marketing.php' ),
	),
	array(
		'icon'  => '📦',
		'title' => __( 'Inventario', 'akibara' ),
		'desc'  => __( 'Avisos cuando vuelve stock.', 'akibara' ),
		'links' => array(
			array( 'akibara-back-in-stock', __( 'Back in Stock', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-inventario/akibara-inventario.php' ),
	),
	array(
		'icon'  => '🛒',
		'title' => __( 'MercadoLibre', 'akibara' ),
		'desc'  => __( 'Publica productos a MercadoLibre Chile.', 'akibara' ),
		'links' => array(
			array( 'akibara-ml-auth', __( 'Configuración ML', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-mercadolibre/akibara-mercadolibre.php' ),
	),
	array(
		'icon'  => '💬',
		'title' => __( 'WhatsApp', 'akibara' ),
		'desc'  => __( 'Botón flotante WhatsApp Business.', 'akibara' ),
		'links' => array(
			array( 'akibara-whatsapp', __( 'WhatsApp', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-whatsapp/akibara-whatsapp.php' ),
	),
	array(
		'icon'  => '⚙️',
		'title' => __( 'Ajustes', 'akibara' ),
		'desc'  => __( 'Búsqueda, Cuotas, Auto-Series, Tomos.', 'akibara' ),
		'links' => array(
			array( 'akibara-modules', __( 'Módulos', 'akibara' ) ),
			array( 'akibara-search', __( 'Búsqueda', 'akibara' ) ),
			array( 'akibara-installments', __( 'Cuotas', 'akibara' ) ),
			array( 'akibara-series-autofill', __( 'Auto-Series', 'akibara' ) ),
			array( 'akibara-ordenar-tomos', __( 'Ordenar Tomos', 'akibara' ) ),
		),
		'show'  => true,
	),
);

$current_user = wp_get_current_user();
$saludo       = current_time( 'H' ) < 12 ? 'Buenos días' : ( current_time( 'H' ) < 19 ? 'Buenas tardes' : 'Buenas noches' );
?>

<div class="wrap akb-admin-page akibara-admin-dashboard">
	<div class="akb-page-header">
		<h1 class="akb-page-header__title">
			<span class="akibara-brand-mark" aria-hidden="true">🎌</span>
			<?php echo esc_html( $saludo ) . ', ' . esc_html( $current_user->display_name ); ?>
		</h1>
		<p class="akb-page-header__desc">
			Vista general de tu tienda manga — pedidos, preventas, stock y campañas de hoy.
		</p>
	</div>

	<!-- KPIs LIVE -->
	<div class="akb-stats">
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $kpi_orders_today ); ?></div>
			<div class="akb-stat__label">Pedidos Hoy</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--success">
				$<?php echo number_format( $kpi_revenue_today, 0, ',', '.' ); ?>
			</div>
			<div class="akb-stat__label">Ingresos Hoy (CLP)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $kpi_preventas_pending > 0 ? 'akb-stat__value--warning' : ''; ?>">
				<?php echo number_format( $kpi_preventas_pending ); ?>
			</div>
			<div class="akb-stat__label">Preventas Pendientes</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $kpi_encargos_pending > 0 ? 'akb-stat__value--warning' : ''; ?>">
				<?php echo number_format( $kpi_encargos_pending ); ?>
			</div>
			<div class="akb-stat__label">Encargos Pendientes</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $kpi_low_stock > 0 ? 'akb-stat__value--error' : 'akb-stat__value--success'; ?>">
				<?php echo number_format( $kpi_low_stock ); ?>
			</div>
			<div class="akb-stat__label">Stock Crítico (&lt;5u)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $kpi_bis_subs ); ?></div>
			<div class="akb-stat__label">Avisos Stock</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $kpi_ml_listings ); ?></div>
			<div class="akb-stat__label">Listings ML</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo $brevo_configured ? 'akb-stat__value--success' : 'akb-stat__value--warning'; ?>">
				<?php echo $brevo_configured ? '✓' : '⚠'; ?>
			</div>
			<div class="akb-stat__label">Brevo</div>
		</div>
	</div>

	<!-- Quick Actions -->
	<div class="akb-card akb-card--section" style="margin-top:8px">
		<h2 class="akb-section-title">⚡ Acciones rápidas</h2>
		<div style="display:flex;gap:10px;flex-wrap:wrap">
			<a class="akb-btn akb-btn--primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">
				📦 Ver pedidos
			</a>
			<a class="akb-btn" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">
				📚 Productos
			</a>
			<a class="akb-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-marketing-campaigns' ) ); ?>">
				📣 Nueva campaña
			</a>
			<a class="akb-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-finance-manga' ) ); ?>">
				💹 Finanzas Manga
			</a>
			<a class="akb-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-modules' ) ); ?>">
				🎛️ Módulos
			</a>
		</div>
	</div>

	<!-- Feature cards grid -->
	<h2 class="akb-section-title" style="margin-top:24px">🗂️ Funcionalidades</h2>
	<div class="akibara-cards-grid" role="list">
		<?php foreach ( $cards as $card ) : ?>
			<?php if ( ! $card['show'] ) {
				continue;
			} ?>
			<div class="akibara-card" role="listitem">
				<h3 class="akibara-card__title">
					<span class="akibara-card__icon" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span>
					<?php echo esc_html( $card['title'] ); ?>
				</h3>
				<p class="akibara-card__desc"><?php echo esc_html( $card['desc'] ); ?></p>
				<ul class="akibara-card__links">
					<?php foreach ( $card['links'] as $link ) : ?>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $link[0] ) ); ?>">
								<?php echo esc_html( $link[1] ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
	</div>
</div>
