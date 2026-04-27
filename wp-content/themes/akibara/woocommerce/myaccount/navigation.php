<?php
/**
 * My Account Navigation — Akibara Override
 *
 * Agrega íconos SVG por sección y aria-current="page" en el enlace activo.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 9.3.0
 */

defined( 'ABSPATH' ) || exit;

$aki_nav_icons = [
	'dashboard'       => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M1.5 6.5L8 1.5l6.5 5V14a.5.5 0 01-.5.5H2a.5.5 0 01-.5-.5V6.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
	'orders'          => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="2.5" y="1.5" width="11" height="13" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M5 6h6M5 9h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
	'mis-reservas'    => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 1.5h10a.5.5 0 01.5.5v11.5L8 11.5l-5.5 2V2a.5.5 0 01.5-.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>',
	'downloads'       => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 2v8M5 7.5l3 3 3-3M2 13h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
	'edit-address'    => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1.5a4 4 0 00-4 4c0 3 4 9 4 9s4-6 4-9a4 4 0 00-4-4z" stroke="currentColor" stroke-width="1.5"/><circle cx="8" cy="5.5" r="1.5" stroke="currentColor" stroke-width="1.3"/></svg>',
	'edit-account'    => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5" r="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M2.5 14c0-2.8 2.5-5 5.5-5s5.5 2.2 5.5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
	'referidos'       => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 1l1.8 3.6 4 .6-2.9 2.8.7 4L8 10l-3.6 1.9.7-4L2.2 5.2l4-.6L8 1z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>',
	'customer-logout' => '<svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 2.5H3a1 1 0 00-1 1v9a1 1 0 001 1h3M10.5 11l3-3-3-3M13.5 8H6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
];

do_action( 'woocommerce_before_account_navigation' );
?>

<nav class="woocommerce-MyAccount-navigation aki-account-nav" aria-label="<?php esc_attr_e( 'Navegación de cuenta', 'akibara' ); ?>">
	<ul role="list">
		<?php foreach ( wc_get_account_menu_items() as $endpoint => $label ) :
			$is_active = wc_is_current_account_menu_item( $endpoint );
			$icon      = $aki_nav_icons[ $endpoint ] ?? '';
		?>
			<li class="<?php echo esc_attr( wc_get_account_menu_item_classes( $endpoint ) ); ?>">
				<a href="<?php echo esc_url( wc_get_account_endpoint_url( $endpoint ) ); ?>"
				   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
					<?php if ( $icon ) : ?>
						<span class="aki-nav-icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php endif; ?>
					<span class="aki-nav-label"><?php echo esc_html( $label ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>

<?php do_action( 'woocommerce_after_account_navigation' ); ?>
