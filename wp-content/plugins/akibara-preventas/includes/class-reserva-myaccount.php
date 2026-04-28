<?php
/**
 * Seccion "Mis Reservas" en Mi Cuenta de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_MyAccount {

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_endpoint' ] );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'menu_items' ] );
		add_action( 'woocommerce_account_mis-reservas_endpoint', [ __CLASS__, 'render' ] );
		add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'query_vars' ] );
	}

	public static function register_endpoint(): void {
		add_rewrite_endpoint( 'mis-reservas', EP_ROOT | EP_PAGES );
	}

	public static function query_vars( array $vars ): array {
		$vars['mis-reservas'] = 'mis-reservas';
		return $vars;
	}

	public static function menu_items( array $items ): array {
		$new = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new['mis-reservas'] = 'Mis Reservas';
			}
		}
		return $new;
	}

	public static function render(): void {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) return;

		$reservas = self::get_customer_reservas( $customer_id );

		wc_get_template(
			'myaccount/mis-reservas.php',
			[ 'reservas' => $reservas ],
			'',
			AKB_PREVENTAS_DIR . 'templates/'
		);
	}

	/**
	 * Obtiene las reservas del cliente.
	 * Retorna array de arrays con: order_id, order_number, order_date, items[name, tipo, estado, fecha]
	 */
	public static function get_customer_reservas( int $customer_id ): array {
		$orders = wc_get_orders( [
			'customer_id' => $customer_id,
			'limit'       => 50,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'meta_query'  => [
				[ 'key' => '_akb_tiene_reserva', 'value' => 'yes' ],
			],
		] );

		$result = [];

		foreach ( $orders as $order ) {
			$items = [];
			foreach ( $order->get_items() as $item ) {
				if ( 'yes' !== $item->get_meta( '_akb_item_reserva' ) ) continue;
				$items[] = [
					'name'   => $item->get_name(),
					'qty'    => $item->get_quantity(),
					'tipo'   => $item->get_meta( '_akb_item_tipo' ),
					'estado' => $item->get_meta( '_akb_item_estado' ),
					'fecha'  => (int) $item->get_meta( '_akb_item_fecha_estimada' ),
				];
			}

			if ( ! empty( $items ) ) {
				$result[] = [
					'order_id'     => $order->get_id(),
					'order_number' => $order->get_order_number(),
					'order_date'   => ( $_c = $order->get_date_created() ) ? $_c->date_i18n( 'd/m/Y' ) : '',
					'order_url'    => $order->get_view_order_url(),
					'items'        => $items,
				];
			}
		}

		return $result;
	}
}
