<?php
/**
 * Tab "Reserva" en el editor de producto de WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Editor {

	public static function init(): void {
		add_filter( 'woocommerce_product_data_tabs', [ __CLASS__, 'add_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'render_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_meta' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ __CLASS__, 'render_variation' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ __CLASS__, 'save_variation_meta' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	// ─── Tab ─────────────────────────────────────────────────────

	public static function add_tab( array $tabs ): array {
		$tabs['akb_reserva'] = [
			'label'    => 'Reserva',
			'target'   => 'akb_reserva_product_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 65,
		];
		return $tabs;
	}

	// ─── Panel ───────────────────────────────────────────────────

	public static function render_panel(): void {
		global $thepostid;
		$product = wc_get_product( $thepostid );
		if ( ! $product ) return;

		$enabled           = Akibara_Reserva_Product::is_reserva( $product ) ? 'yes' : 'no';
		$fecha_modo        = Akibara_Reserva_Product::get_fecha_modo( $product );
		$fecha             = Akibara_Reserva_Product::get_fecha( $product );
		// Leer raw del meta (no el resuelto), para que el admin vea su override real.
		$descuento_raw     = (int) $product->get_meta( Akibara_Reserva_Product::META_DESCUENTO, true );
		$descuento_efectivo= Akibara_Reserva_Product::get_descuento( $product );
		$editorial         = Akibara_Reserva_Product::get_editorial( $product );
		$max_qty           = Akibara_Reserva_Product::get_max_qty( $product );
		$estado_proveedor  = Akibara_Reserva_Product::get_estado_proveedor( $product );
		$fecha_pedido      = Akibara_Reserva_Product::get_fecha_pedido( $product );

		// Convertir timestamp a Y-m-d para input type=date
		$fecha_str = $fecha > 0 ? gmdate( 'Y-m-d', $fecha ) : '';

		include AKIBARA_RESERVAS_DIR . 'templates/admin/product-tab.php';
	}

	// ─── Variaciones ─────────────────────────────────────────────

	public static function render_variation( int $loop, array $variation_data, \WP_Post $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) return;

		$enabled    = Akibara_Reserva_Product::is_reserva( $product ) ? 'yes' : 'no';
		$fecha_modo = Akibara_Reserva_Product::get_fecha_modo( $product );
		$fecha      = Akibara_Reserva_Product::get_fecha( $product );
		$descuento  = (int) $product->get_meta( Akibara_Reserva_Product::META_DESCUENTO, true );
		$fecha_str  = $fecha > 0 ? gmdate( 'Y-m-d', $fecha ) : '';

		echo '<div class="akb-reserva-variation">';
		echo '<h4 style="margin:10px 0 5px;font-weight:600;">Preventa</h4>';

		woocommerce_wp_checkbox( [
			'id'            => "_akb_reserva_{$loop}",
			'name'          => "_akb_reserva[{$loop}]",
			'label'         => 'Activar preventa',
			'value'         => $enabled,
			'wrapper_class' => 'form-row form-row-full',
		] );

		woocommerce_wp_select( [
			'id'            => "_akb_reserva_fecha_modo_{$loop}",
			'name'          => "_akb_reserva_fecha_modo[{$loop}]",
			'label'         => 'Modo de fecha',
			'value'         => $fecha_modo,
			'options'       => [ 'fija' => 'Fecha fija', 'estimada' => 'Fecha estimada', 'sin_fecha' => 'Sin fecha' ],
			'wrapper_class' => 'form-row form-row-first',
		] );

		woocommerce_wp_text_input( [
			'id'            => "_akb_reserva_fecha_{$loop}",
			'name'          => "_akb_reserva_fecha[{$loop}]",
			'label'         => 'Fecha',
			'type'          => 'date',
			'value'         => $fecha_str,
			'wrapper_class' => 'form-row form-row-last',
		] );

		woocommerce_wp_text_input( [
			'id'            => "_akb_reserva_descuento_{$loop}",
			'name'          => "_akb_reserva_descuento[{$loop}]",
			'label'         => 'Descuento % (0 = usar categoría/global)',
			'type'          => 'number',
			'value'         => $descuento,
			'custom_attributes' => [ 'min' => '0', 'max' => '99', 'step' => '1' ],
			'wrapper_class' => 'form-row form-row-full',
		] );

		echo '</div>';
	}

	// ─── Guardar meta (producto simple) ──────────────────────────

	public static function save_meta( int $post_id ): void {
		if ( empty( $_POST['woocommerce_meta_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_key( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' ) ) return;

		$product = wc_get_product( $post_id );
		if ( ! $product || $product->is_type( 'variable' ) ) return;

		$enabled = isset( $_POST['_akb_reserva'] ) ? 'yes' : 'no';

		if ( 'no' === $enabled ) {
			$product->update_meta_data( Akibara_Reserva_Product::META_ENABLED, 'no' );
			$product->save_meta_data();
			akb_reserva_sync_category( $post_id, '', false );
			return;
		}

		$fecha_modo = isset( $_POST['_akb_reserva_fecha_modo'] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_fecha_modo'] ) ) : 'sin_fecha';
		$fecha_str  = isset( $_POST['_akb_reserva_fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_fecha'] ) ) : '';
		$descuento  = isset( $_POST['_akb_reserva_descuento'] ) ? absint( $_POST['_akb_reserva_descuento'] ) : 0;
		$editorial  = isset( $_POST['_akb_reserva_editorial'] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_editorial'] ) ) : '';
		$max_qty    = isset( $_POST['_akb_reserva_max_qty'] ) ? absint( $_POST['_akb_reserva_max_qty'] ) : 0;

		// Estado proveedor
		$new_estado = isset( $_POST['_akb_reserva_estado_proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_estado_proveedor'] ) ) : 'sin_pedir';
		$old_estado = Akibara_Reserva_Product::get_estado_proveedor( $product );

		// Fecha pedido: auto-set si cambio a "pedido", o manual si se proporciona
		$fecha_pedido_str = isset( $_POST['_akb_reserva_fecha_pedido'] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_fecha_pedido'] ) ) : '';
		$fecha_pedido_ts  = akb_reserva_fecha_to_timestamp( $fecha_pedido_str );

		if ( 'pedido' === $new_estado && 'pedido' !== $old_estado && $fecha_pedido_ts <= 0 ) {
			$fecha_pedido_ts = time();
		}

		$fecha_ts = akb_reserva_fecha_to_timestamp( $fecha_str );

		// Detectar cambio de fecha para notificacion
		$old_fecha = Akibara_Reserva_Product::get_fecha( $product );
		$fecha_changed = $old_fecha > 0 && $fecha_ts > 0 && $old_fecha !== $fecha_ts;

		Akibara_Reserva_Product::set_meta( $product, [
			'enabled'          => 'yes',
			'tipo'             => 'preventa',
			'fecha'            => $fecha_ts,
			'fecha_modo'       => $fecha_modo,
			'descuento'        => $descuento,
			'editorial'        => $editorial,
			'max_qty'          => $max_qty,
			'estado_proveedor' => $new_estado,
			'fecha_pedido'     => $fecha_pedido_ts,
		] );

		// Si cambio la fecha, notificar a los clientes con reservas pendientes
		if ( $fecha_changed ) {
			do_action( 'akb_reserva_fecha_cambiada', $post_id, $old_fecha, $fecha_ts );
		}

		// Auto-categorizar
		akb_reserva_sync_category( $post_id, 'preventa', true );
	}

	// ─── Guardar meta (variacion) ────────────────────────────────

	public static function save_variation_meta( int $variation_id, int $i ): void {
		$product = wc_get_product( $variation_id );
		if ( ! $product ) return;

		$enabled = isset( $_POST['_akb_reserva'][ $i ] ) ? 'yes' : 'no';

		if ( 'no' === $enabled ) {
			$product->update_meta_data( Akibara_Reserva_Product::META_ENABLED, 'no' );
			$product->save_meta_data();
			$parent_id = $product->get_parent_id();
			akb_reserva_sync_category( $parent_id, '', false );
			return;
		}

		$fecha_modo = isset( $_POST['_akb_reserva_fecha_modo'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_fecha_modo'][ $i ] ) ) : 'sin_fecha';
		$fecha_str  = isset( $_POST['_akb_reserva_fecha'][ $i ] ) ? sanitize_text_field( wp_unslash( $_POST['_akb_reserva_fecha'][ $i ] ) ) : '';
		$descuento  = isset( $_POST['_akb_reserva_descuento'][ $i ] ) ? absint( $_POST['_akb_reserva_descuento'][ $i ] ) : 0;

		Akibara_Reserva_Product::set_meta( $product, [
			'enabled'    => 'yes',
			'tipo'       => 'preventa',
			'fecha'      => akb_reserva_fecha_to_timestamp( $fecha_str ),
			'fecha_modo' => $fecha_modo,
			'descuento'  => $descuento,
		] );

		// Sync parent product category
		$parent_id = $product->get_parent_id();
		akb_reserva_sync_category( $parent_id, 'preventa', true );
	}

	// ─── Scripts ─────────────────────────────────────────────────

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) return;

		wp_enqueue_script(
			'akb-reserva-editor',
			AKIBARA_RESERVAS_URL . 'assets/js/editor.js',
			[ 'jquery' ],
			AKIBARA_RESERVAS_VERSION,
			true
		);
	}
}
