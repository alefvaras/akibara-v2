<?php
/**
 * Akibara Core — Admin dashboard view (Sprint 5.5 minimum viable).
 *
 * Cards con quick links a sub-pages + KPIs futuros.
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

$cards = array(
	array(
		'icon'  => '🎌',
		'title' => __( 'Preventas', 'akibara' ),
		'desc'  => __( 'Reservas, Notificaciones Editoriales, Próximo Tomo, Encargos.', 'akibara' ),
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
		'desc'  => __( 'Campañas, Topbar, Brevo, Descuentos, Welcome Discount.', 'akibara' ),
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
		'desc'  => __( 'Back in Stock subscriptions.', 'akibara' ),
		'links' => array(
			array( 'akibara-back-in-stock', __( 'Back in Stock', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-inventario/akibara-inventario.php' ),
	),
	array(
		'icon'  => '🛒',
		'title' => __( 'MercadoLibre', 'akibara' ),
		'desc'  => __( 'Publisher de productos a MercadoLibre Chile.', 'akibara' ),
		'links' => array(
			array( 'akibara-ml-auth', __( 'Configuración ML', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-mercadolibre/akibara-mercadolibre.php' ),
	),
	array(
		'icon'  => '💬',
		'title' => __( 'WhatsApp', 'akibara' ),
		'desc'  => __( 'Float button + configuración WhatsApp Business.', 'akibara' ),
		'links' => array(
			array( 'akibara-whatsapp', __( 'WhatsApp', 'akibara' ) ),
		),
		'show'  => $akb_active( 'akibara-whatsapp/akibara-whatsapp.php' ),
	),
	array(
		'icon'  => '⚙️',
		'title' => __( 'Ajustes', 'akibara' ),
		'desc'  => __( 'Búsqueda, Cuotas, Auto-Series, Ordenar Tomos.', 'akibara' ),
		'links' => array(
			array( 'akibara-search', __( 'Búsqueda Index', 'akibara' ) ),
			array( 'akibara-installments', __( 'Cuotas', 'akibara' ) ),
			array( 'akibara-series-autofill', __( 'Auto-Series', 'akibara' ) ),
			array( 'akibara-ordenar-tomos', __( 'Ordenar Tomos', 'akibara' ) ),
		),
		'show'  => true,
	),
);
?>

<div class="wrap akibara-admin-dashboard">
	<h1 class="wp-heading-inline">
		<span class="akibara-brand-mark" aria-hidden="true">🎌</span>
		<?php esc_html_e( 'Panel Akibara', 'akibara' ); ?>
	</h1>

	<p class="akibara-admin-subtitle">
		<?php esc_html_e( 'Acceso rápido a las funciones de la tienda. Las opciones se reagruparon por feature en Sprint 5.5.', 'akibara' ); ?>
	</p>

	<div class="akibara-cards-grid" role="list">
		<?php foreach ( $cards as $card ) : ?>
			<?php if ( ! $card['show'] ) {
				continue;
			} ?>
			<div class="akibara-card" role="listitem">
				<h2 class="akibara-card__title">
					<span class="akibara-card__icon" aria-hidden="true"><?php echo esc_html( $card['icon'] ); ?></span>
					<?php echo esc_html( $card['title'] ); ?>
				</h2>
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
