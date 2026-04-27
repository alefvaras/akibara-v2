<?php
/**
 * Cron: verificacion automatica de fechas y digest diario.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Cron {

	public static function init(): void {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_interval' ] );
		add_action( 'init', [ __CLASS__, 'ensure_scheduled' ], 20 );
		add_action( 'akb_reservas_check_dates', [ __CLASS__, 'check_dates' ] );
		add_action( 'akb_reservas_daily_digest', [ __CLASS__, 'daily_digest' ] );
	}

	public static function add_interval( array $schedules ): array {
		$schedules['akb_fifteen_minutes'] = [
			'interval' => 900,
			'display'  => 'Cada 15 minutos',
		];
		return $schedules;
	}

	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( 'akb_reservas_check_dates' ) ) {
			wp_schedule_event( time(), 'akb_fifteen_minutes', 'akb_reservas_check_dates' );
		}
		if ( ! wp_next_scheduled( 'akb_reservas_daily_digest' ) ) {
			wp_schedule_event( time(), 'daily', 'akb_reservas_daily_digest' );
		}
	}

	/**
	 * Cada 15 minutos: verificar ordenes con reservas cuya fecha ya paso.
	 */
	public static function check_dates(): void {
		$order_ids = Akibara_Reserva_Orders::get_pending_orders( 100 );
		if ( empty( $order_ids ) ) return;

		$now = time();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			foreach ( $order->get_items() as $item ) {
				if ( 'yes' !== $item->get_meta( Akibara_Reserva_Orders::ITEM_RESERVA ) ) continue;
				if ( 'esperando' !== $item->get_meta( Akibara_Reserva_Orders::ITEM_ESTADO ) ) continue;

				$fecha = (int) $item->get_meta( Akibara_Reserva_Orders::ITEM_FECHA );
				if ( $fecha <= 0 ) continue; // Sin fecha, no auto-completar

				if ( $now >= $fecha ) {
					Akibara_Reserva_Orders::complete_item( $order, $item->get_id() );

					// Tambien resetear el producto si la fecha ya paso
					$product_id = $item->get_variation_id() ?: $item->get_product_id();
					$product    = wc_get_product( $product_id );
					if ( $product ) {
						Akibara_Reserva_Product::maybe_expire( $product );
					}
				}
			}
		}
	}

	/**
	 * Diariamente: contar pendientes y avisar si hay fechas proximas.
	 */
	public static function daily_digest(): void {
		$order_ids = Akibara_Reserva_Orders::get_pending_orders();
		if ( empty( $order_ids ) ) return;

		$now       = time();
		$three_days = $now + ( 3 * DAY_IN_SECONDS );
		$proximas   = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) continue;

			foreach ( $order->get_items() as $item ) {
				if ( 'yes' !== $item->get_meta( Akibara_Reserva_Orders::ITEM_RESERVA ) ) continue;
				if ( 'esperando' !== $item->get_meta( Akibara_Reserva_Orders::ITEM_ESTADO ) ) continue;

				$fecha = (int) $item->get_meta( Akibara_Reserva_Orders::ITEM_FECHA );
				if ( $fecha > 0 && $fecha <= $three_days ) {
					$proximas[] = sprintf(
						'Pedido #%s - %s - Fecha: %s',
						$order->get_order_number(),
						$item->get_name(),
						akb_reserva_fecha( $fecha )
					);
				}
			}
		}

		if ( empty( $proximas ) ) return;

		$admin_email = get_option( 'akb_reservas_email_admin', get_option( 'admin_email' ) );
		$subject     = sprintf( 'Akibara: %d reservas con fecha proxima', count( $proximas ) );
		$body        = "Las siguientes reservas tienen fecha de disponibilidad en los proximos 3 dias:\n\n";
		$body       .= implode( "\n", $proximas );
		$body       .= "\n\nRevisa el panel de reservas: " . admin_url( 'admin.php?page=akb-reservas' );

		wp_mail( $admin_email, $subject, $body );
	}
}
