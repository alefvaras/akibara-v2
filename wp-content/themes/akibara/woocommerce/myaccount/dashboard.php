<?php
/**
 * My Account Dashboard — Akibara Override
 *
 * Muestra: saludo personalizado, nudge cupón bienvenida, pedido reciente con
 * progress bar, resumen reservas y accesos rápidos.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 4.4.0
 */

defined( 'ABSPATH' ) || exit;

$user       = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;
$user_id    = get_current_user_id();
$user_email = $user->user_email;

// Recent order.
$recent_orders = wc_get_orders( [
	'customer' => $user_id,
	'limit'    => 1,
	'orderby'  => 'date',
	'order'    => 'DESC',
] );
$recent_order = ! empty( $recent_orders ) ? $recent_orders[0] : null;

// Welcome discount coupon nudge.
$show_coupon  = false;
$coupon_code  = null;
$coupon_obj   = null;
$coupon_days  = null;
if ( class_exists( 'Akibara_WD_Coupon' ) ) {
	$coupon_code = Akibara_WD_Coupon::build_code( $user_email );
	$coupon_obj  = new WC_Coupon( $coupon_code );
	if ( $coupon_obj->get_id() && ! $coupon_obj->get_usage_count() && $coupon_obj->get_date_expires() ) {
		$expires_ts = $coupon_obj->get_date_expires()->getTimestamp();
		if ( $expires_ts > time() ) {
			$show_coupon = true;
			$coupon_days = (int) ceil( ( $expires_ts - time() ) / DAY_IN_SECONDS );
		}
	}
}

// Reservas summary (akibara-reservas plugin).
$reservas_list   = [];
$has_reservas    = false;
$pending_count   = 0;
if ( class_exists( 'Akibara_Reserva_MyAccount' ) ) {
	$reservas_list = Akibara_Reserva_MyAccount::get_customer_reservas( $user_id );
	$has_reservas  = ! empty( $reservas_list );
	if ( $has_reservas ) {
		foreach ( $reservas_list as $reserva ) {
			foreach ( $reserva['items'] as $item ) {
				if ( in_array( $item['estado'], [ 'pendiente', 'en_camino' ], true ) ) {
					++$pending_count;
				}
			}
		}
	}
}

// Order progress step map: status slug → step (1-4).
$progress_map = [
	'pending'    => 1,
	'on-hold'    => 1,
	'processing' => 2,
	'completed'  => 4,
];
$progress_labels = [
	1 => __( 'Pagado', 'akibara' ),
	2 => __( 'Preparando', 'akibara' ),
	3 => __( 'Despachado', 'akibara' ),
	4 => __( 'Entregado', 'akibara' ),
];
?>

<div class="aki-dashboard">

	<div class="aki-dashboard__greeting">
		<p class="aki-dashboard__hello">
			<?php if ( $first_name ) : ?>
				<?php
				printf(
					/* translators: %s: customer first name */
					wp_kses( __( '¡Hola, <strong>%s</strong>!', 'akibara' ), [ 'strong' => [] ] ),
					esc_html( $first_name )
				);
				?>
			<?php else : ?>
				<?php esc_html_e( '¡Bienvenido a tu cuenta!', 'akibara' ); ?>
			<?php endif; ?>
		</p>
		<p class="aki-dashboard__subtitle"><?php esc_html_e( 'tu distrito del manga y cómics', 'akibara' ); ?></p>
	</div>

	<?php if ( $show_coupon && $coupon_obj ) :
		$discount_type   = $coupon_obj->get_discount_type();
		$discount_amount = $coupon_obj->get_amount();
		$discount_str    = ( 'percent' === $discount_type )
			? esc_html( $discount_amount ) . '%'
			: wc_price( $discount_amount );
	?>
	<div class="aki-nudge aki-nudge--coupon" role="region" aria-label="<?php esc_attr_e( 'Cupón de bienvenida disponible', 'akibara' ); ?>">
		<div class="aki-nudge__body">
			<p class="aki-nudge__title">
				<?php
				printf(
					/* translators: %s: discount value */
					wp_kses( __( 'Tu descuento de <strong>%s</strong> está esperándote', 'akibara' ), [ 'strong' => [] ] ),
					$discount_str // already escaped above
				);
				?>
			</p>
			<p class="aki-nudge__sub">
				<?php
				printf(
					/* translators: %d: days remaining */
					esc_html__( 'Válido por %d día(s) más — úsalo en tu primera compra.', 'akibara' ),
					(int) $coupon_days
				);
				?>
				<br>
				<span class="aki-nudge__code-label"><?php esc_html_e( 'Código:', 'akibara' ); ?></span>
				<code class="aki-nudge__code-value"><?php echo esc_html( strtoupper( $coupon_code ) ); ?></code>
			</p>
		</div>
		<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="aki-nudge__cta">
			<?php esc_html_e( 'Ir a la tienda', 'akibara' ); ?>
		</a>
	</div>
	<?php endif; ?>

	<?php if ( $recent_order ) :
		$order_status  = $recent_order->get_status();
		$progress_step = $progress_map[ $order_status ] ?? 0;
		$is_terminal   = in_array( $order_status, [ 'cancelled', 'refunded', 'failed' ], true );
	?>
	<div class="aki-panel aki-dashboard__recent-order" role="region" aria-label="<?php esc_attr_e( 'Pedido más reciente', 'akibara' ); ?>">
		<div class="aki-panel__header">
			<span class="aki-panel__title"><?php esc_html_e( 'Pedido reciente', 'akibara' ); ?></span>
			<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="aki-panel__link">
				<?php esc_html_e( 'Ver todos', 'akibara' ); ?> &rarr;
			</a>
		</div>
		<div class="aki-panel__body">
			<div class="aki-order-meta">
				<a href="<?php echo esc_url( $recent_order->get_view_order_url() ); ?>" class="aki-order-meta__number">
					<?php echo esc_html( '#' . $recent_order->get_order_number() ); ?>
				</a>
				<span class="aki-order-meta__date">
					<?php echo esc_html( wc_format_datetime( $recent_order->get_date_created() ) ); ?>
				</span>
				<span class="aki-order-meta__total">
					<?php echo wp_kses_post( $recent_order->get_formatted_order_total() ); ?>
				</span>
				<span class="aki-order-status aki-order-status--<?php echo esc_attr( $order_status ); ?>">
					<?php echo esc_html( wc_get_order_status_name( $order_status ) ); ?>
				</span>
			</div>

			<?php if ( $progress_step > 0 && ! $is_terminal ) : ?>
			<div class="aki-order-progress"
			     role="progressbar"
			     aria-valuenow="<?php echo esc_attr( $progress_step ); ?>"
			     aria-valuemin="1"
			     aria-valuemax="4"
			     aria-label="<?php esc_attr_e( 'Estado del pedido', 'akibara' ); ?>">
				<div class="aki-order-progress__track" aria-hidden="true">
					<div class="aki-order-progress__fill"
					     style="width:<?php echo esc_attr( round( ( ( $progress_step - 1 ) / 3 ) * 100 ) ); ?>%">
					</div>
				</div>
				<div class="aki-order-progress__steps" aria-hidden="true">
					<?php foreach ( $progress_labels as $step => $label ) : ?>
						<div class="aki-order-progress__step <?php echo $step < $progress_step ? 'is-done' : ''; ?> <?php echo $step === $progress_step ? 'is-current' : ''; ?>">
							<div class="aki-order-progress__dot"></div>
							<span class="aki-order-progress__label"><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $has_reservas ) : ?>
	<div class="aki-panel aki-dashboard__reservas" role="region" aria-label="<?php esc_attr_e( 'Tus reservas', 'akibara' ); ?>">
		<div class="aki-panel__header">
			<span class="aki-panel__title"><?php esc_html_e( 'Preventa / Reservas', 'akibara' ); ?></span>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'mis-reservas' ) ); ?>" class="aki-panel__link">
				<?php esc_html_e( 'Ver todas', 'akibara' ); ?> &rarr;
			</a>
		</div>
		<div class="aki-panel__body">
			<p class="aki-dashboard__reservas-msg">
				<?php if ( $pending_count > 0 ) : ?>
					<?php
					printf(
						/* translators: %d: number of active reservations */
						esc_html( _n(
							'Tienes %d reserva activa esperándote.',
							'Tienes %d reservas activas esperándote.',
							$pending_count,
							'akibara'
						) ),
						(int) $pending_count
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Todas tus reservas están al día.', 'akibara' ); ?>
				<?php endif; ?>
			</p>
		</div>
	</div>
	<?php endif; ?>

	<nav class="aki-dashboard__quick-links" aria-label="<?php esc_attr_e( 'Accesos rápidos de cuenta', 'akibara' ); ?>">
		<a href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>" class="aki-quick-link">
			<span class="aki-quick-link__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="3" y="2" width="14" height="16" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M6 7h8M6 11h5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
			</span>
			<span class="aki-quick-link__label"><?php esc_html_e( 'Mis pedidos', 'akibara' ); ?></span>
		</a>
		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>" class="aki-quick-link">
			<span class="aki-quick-link__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 2a5 5 0 00-5 5c0 3.5 5 11 5 11s5-7.5 5-11a5 5 0 00-5-5z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="7" r="2" stroke="currentColor" stroke-width="1.3"/></svg>
			</span>
			<span class="aki-quick-link__label"><?php esc_html_e( 'Mis direcciones', 'akibara' ); ?></span>
		</a>
		<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="aki-quick-link">
			<span class="aki-quick-link__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M2 3h2l2.5 10H15l2-7H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="16" r="1.5" stroke="currentColor" stroke-width="1.3"/><circle cx="14" cy="16" r="1.5" stroke="currentColor" stroke-width="1.3"/></svg>
			</span>
			<span class="aki-quick-link__label"><?php esc_html_e( 'Ir a la tienda', 'akibara' ); ?></span>
		</a>
		<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>" class="aki-quick-link">
			<span class="aki-quick-link__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="6" r="3.5" stroke="currentColor" stroke-width="1.5"/><path d="M3 18c0-3.5 3.1-6 7-6s7 2.5 7 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
			</span>
			<span class="aki-quick-link__label"><?php esc_html_e( 'Mi cuenta', 'akibara' ); ?></span>
		</a>
	</nav>

</div>

<?php
do_action( 'woocommerce_account_dashboard' );
// phpcs:disable WordPress.NamingConventions.ValidHookName
do_action( 'woocommerce_before_my_account' );
do_action( 'woocommerce_after_my_account' );
// phpcs:enable
