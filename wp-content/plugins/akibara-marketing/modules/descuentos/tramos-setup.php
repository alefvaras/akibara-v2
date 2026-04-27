<?php
/**
 * Akibara — Volume Discount Tramos Setup
 *
 * Auto-creates the volume discount rule using the descuentos engine tramos field.
 * Run once on plugin load, idempotent.
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_init',
	function (): void {
		if ( get_option( 'akb_tramos_serie_rule_created' ) ) {
			return;
		}

		// Wait for descuentos module
		$descuentos = Akibara_Descuento_Taxonomia::instance();
		$reglas     = $descuentos->get_reglas();

		// Check if a tramos rule already exists
		foreach ( $reglas as $regla ) {
			if ( ! empty( $regla['tramos'] ) ) {
				update_option( 'akb_tramos_serie_rule_created', 1 );
				return;
			}
		}

		// Create the volume discount rule
		$new_rule = array(
			'id'                  => 'rule_vol_serie',
			'nombre'              => 'Descuento por volumen de serie',
			'activo'              => true,
			'tipo_descuento'      => 'porcentaje',
			'valor'               => 5, // default/fallback
			'alcance'             => 'carrito',
			'tope_descuento'      => 0,
			'apilable'            => false,
			'excluir_en_oferta'   => false,
			'fecha_inicio'        => '',
			'fecha_fin'           => '',
			'taxonomias'          => array(),
			'productos_excluidos' => '',
			'productos_incluidos' => '',
			'carrito_condiciones' => array(),
			'tramos'              => array(
				array(
					'min'   => 3,
					'valor' => 5,
				),
				array(
					'min'   => 5,
					'valor' => 8,
				),
			),
		);

		$reglas[] = $new_rule;
		$descuentos->save_reglas( $reglas );

		update_option( 'akb_tramos_serie_rule_created', 1 );
	},
	30
);
