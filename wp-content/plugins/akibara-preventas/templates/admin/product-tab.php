<?php
/**
 * Template del tab "Preventa" en el editor de producto.
 * Variables disponibles: $product, $enabled, $fecha_modo, $fecha_str, $descuento_raw, $descuento_efectivo, $editorial, $max_qty, $estado_proveedor, $fecha_pedido
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="akb_reserva_product_data" class="panel woocommerce_options_panel">

	<?php
	woocommerce_wp_checkbox( [
		'id'          => '_akb_reserva',
		'label'       => 'Activar preventa',
		'description' => 'Marca este producto como preventa: el cliente lo reserva/encarga y tú lo traes desde la editorial.',
		'value'       => $enabled,
	] );
	?>

	<div class="akb-reserva-fields" style="<?php echo 'yes' !== $enabled ? 'display:none;' : ''; ?>">

		<?php
		woocommerce_wp_select( [
			'id'      => '_akb_reserva_fecha_modo',
			'label'   => 'Modo de fecha',
			'value'   => $fecha_modo,
			'options' => [
				'fija'      => 'Fecha fija (se sabe exactamente)',
				'estimada'  => 'Fecha estimada (aproximada)',
				'sin_fecha' => 'Sin fecha (no se sabe aun)',
			],
		] );

		woocommerce_wp_text_input( [
			'id'    => '_akb_reserva_fecha',
			'label' => 'Fecha de disponibilidad',
			'type'  => 'date',
			'value' => $fecha_str,
		] );

		$descuento_help  = 'Descuento sobre el precio regular. 0 = usar descuento de categoría, o default global si la categoría no tiene.';
		$descuento_help .= ' Efectivo actual: ' . (int) $descuento_efectivo . '%.';

		woocommerce_wp_text_input( [
			'id'                => '_akb_reserva_descuento',
			'label'             => 'Descuento preventa (%)',
			'type'              => 'number',
			'value'             => (int) $descuento_raw,
			'description'       => $descuento_help,
			'desc_tip'          => true,
			'custom_attributes' => [ 'min' => '0', 'max' => '99', 'step' => '1' ],
		] );

		// Editorial
		$editorial_options = [ '' => '-- Seleccionar --' ];
		foreach ( akb_reserva_editoriales() as $ed ) {
			$editorial_options[ $ed ] = $ed;
		}
		woocommerce_wp_select( [
			'id'      => '_akb_reserva_editorial',
			'label'   => 'Editorial',
			'value'   => $editorial,
			'options' => $editorial_options,
		] );

		woocommerce_wp_text_input( [
			'id'                => '_akb_reserva_max_qty',
			'label'             => 'Cantidad maxima por cliente',
			'type'              => 'number',
			'value'             => $max_qty,
			'description'       => '0 = sin limite',
			'desc_tip'          => true,
			'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
		] );
		?>

		<hr style="margin:15px 12px;">
		<h4 style="padding:0 12px;margin:0 0 10px;color:#23282d;">Estado proveedor</h4>

		<?php
		$estado_proveedor_labels = [
			'sin_pedir'   => 'Sin pedir',
			'pedido'      => 'Pedido al proveedor',
			'en_transito' => 'En transito',
			'recibido'    => 'Recibido',
		];

		woocommerce_wp_select( [
			'id'      => '_akb_reserva_estado_proveedor',
			'label'   => 'Estado',
			'value'   => $estado_proveedor,
			'options' => $estado_proveedor_labels,
		] );

		// Fecha pedido a proveedor
		$fecha_pedido_str = $fecha_pedido > 0 ? gmdate( 'Y-m-d', $fecha_pedido ) : '';
		woocommerce_wp_text_input( [
			'id'                => '_akb_reserva_fecha_pedido',
			'label'             => 'Fecha pedido a proveedor',
			'type'              => 'date',
			'value'             => $fecha_pedido_str,
			'description'       => 'Se establece automaticamente al cambiar estado a "Pedido"',
			'desc_tip'          => true,
		] );

		// Mostrar fecha estimada de llegada (read-only)
		$brand_days = Akibara_Reserva_Product::get_brand_shipping_days( $product );
		$llegada = Akibara_Reserva_Product::get_fecha_estimada_llegada( $product );
		?>
		<p class="form-field" style="padding:0 12px;">
			<label>Fecha estimada llegada</label>
			<span style="display:inline-block;padding:4px 8px;background:#f0f0f0;border-radius:3px;font-size:13px;">
				<?php
				if ( $llegada > 0 ) {
					$remaining = (int) ceil( ( $llegada - time() ) / DAY_IN_SECONDS );
					echo esc_html( akb_reserva_fecha( $llegada ) );
					if ( $remaining > 0 ) {
						echo ' (~' . esc_html( $remaining ) . ' dias restantes)';
					} else {
						echo ' (llegada inminente)';
					}
				} elseif ( $brand_days > 0 ) {
					echo '~' . esc_html( $brand_days ) . ' dias desde que se pida';
				} else {
					echo 'Configurar tiempos de envio en Ajustes';
				}
				?>
			</span>
		</p>

	</div>

</div>
