<?php
/**
 * Akibara Descuentos — Presets de Campaña
 *
 * Plantillas pre-configuradas para fechas comerciales chilenas.
 * El admin selecciona un preset → el form de regla se llena automaticamente.
 *
 * Lifted from server-snapshot/.../modules/descuentos/presets.php v11.1.0
 * Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 * Logic unchanged.
 *
 * @package Akibara\Marketing\Descuentos
 * @since   11.1.0
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKIBARA_DESCUENTOS_PRESETS_LOADED' ) ) {
	return;
}
define( 'AKIBARA_DESCUENTOS_PRESETS_LOADED', '11.1.0' );

/**
 * Devuelve el listado completo de presets disponibles.
 *
 * Cada preset contiene los campos de una regla + metadatos UI
 * (label, descripcion, banner_text) que se filtran al crear la regla.
 *
 * @return array<string, array<string, mixed>>
 */
function akibara_descuento_presets(): array {
	$year = (int) gmdate( 'Y' );
	$bf   = strtotime( "last friday of november {$year}" );

	return array(
		'cyberday_chile'    => array(
			'label'             => 'CyberDay Chile',
			'descripcion'       => 'CyberDay oficial Chile (ultimo lunes mayo + martes + miercoles). -30% sugerido, excluye productos ya en oferta.',
			'nombre'            => "CyberDay {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 30,
			'fecha_inicio'      => gmdate( 'Y-m-d', strtotime( "last monday of may {$year}" ) ),
			'fecha_fin'         => gmdate( 'Y-m-d', strtotime( "last monday of may {$year} +2 days" ) ),
			'excluir_en_oferta' => 1,
			'apilable'          => 0,
			'tope_descuento'    => 15000,
			'banner_text'       => 'CYBERDAY -30% OFF | Termina en {COUNTDOWN}',
		),
		'hot_sale'          => array(
			'label'             => 'Hot Sale Chile',
			'descripcion'       => 'Hot Sale Chile (junio). 3 dias, -25% sugerido.',
			'nombre'            => "Hot Sale {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 25,
			'fecha_inicio'      => "{$year}-06-17",
			'fecha_fin'         => "{$year}-06-19",
			'excluir_en_oferta' => 1,
			'apilable'          => 0,
			'tope_descuento'    => 10000,
			'banner_text'       => 'HOT SALE -25% OFF | Termina en {COUNTDOWN}',
		),
		'black_friday'      => array(
			'label'             => 'Black Friday',
			'descripcion'       => 'Black Friday (ultimo viernes noviembre). 1 dia, -35% agresivo.',
			'nombre'            => "Black Friday {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 35,
			'fecha_inicio'      => gmdate( 'Y-m-d', $bf ),
			'fecha_fin'         => gmdate( 'Y-m-d', $bf ),
			'excluir_en_oferta' => 1,
			'apilable'          => 0,
			'tope_descuento'    => 20000,
			'banner_text'       => 'BLACK FRIDAY -35% | HOY SOLO',
		),
		'black_weekend'     => array(
			'label'             => 'Black Weekend',
			'descripcion'       => 'Black Weekend (viernes-sabado-domingo post Black Friday). 3 dias, -30%.',
			'nombre'            => "Black Weekend {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 30,
			'fecha_inicio'      => gmdate( 'Y-m-d', $bf ),
			'fecha_fin'         => gmdate( 'Y-m-d', $bf + 2 * DAY_IN_SECONDS ),
			'excluir_en_oferta' => 1,
			'apilable'          => 0,
			'tope_descuento'    => 20000,
			'banner_text'       => 'BLACK WEEKEND -30% | Termina {COUNTDOWN}',
		),
		'cyber_monday'      => array(
			'label'             => 'Cyber Monday',
			'descripcion'       => 'Cyber Monday (lunes post Black Friday). 1 dia final con el descuento maximo.',
			'nombre'            => "Cyber Monday {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 40,
			'fecha_inicio'      => gmdate( 'Y-m-d', $bf + 3 * DAY_IN_SECONDS ),
			'fecha_fin'         => gmdate( 'Y-m-d', $bf + 3 * DAY_IN_SECONDS ),
			'excluir_en_oferta' => 1,
			'apilable'          => 0,
			'tope_descuento'    => 25000,
			'banner_text'       => 'CYBER MONDAY -40% | HOY SOLO',
		),
		'navidad'           => array(
			'label'             => 'Navidad',
			'descripcion'       => 'Campaña navideña (15-25 diciembre). -20% general.',
			'nombre'            => "Navidad {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 20,
			'fecha_inicio'      => "{$year}-12-15",
			'fecha_fin'         => "{$year}-12-25",
			'excluir_en_oferta' => 0,
			'apilable'          => 1,
			'tope_descuento'    => 10000,
			'banner_text'       => 'Regalos Akibara -20% hasta Navidad',
		),
		'editorial_x'       => array(
			'label'             => 'Promocion por Editorial',
			'descripcion'       => '3 dias de -20% en una editorial especifica (el admin elige cual post-preset). Ideal para impulsar stock muerto.',
			'nombre'            => "Promo Editorial {$year}",
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 20,
			'fecha_inicio'      => gmdate( 'Y-m-d' ),
			'fecha_fin'         => gmdate( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ),
			'excluir_en_oferta' => 0,
			'apilable'          => 0,
			'tope_descuento'    => 8000,
			'alcance'           => 'product_brand',
			'banner_text'       => '-20% en {EDITORIAL} | Termina {COUNTDOWN}',
		),
		'liquidacion_stock' => array(
			'label'             => 'Liquidacion Stock',
			'descripcion'       => '-50% agresivo en productos especificos (el admin elige). 7 dias.',
			'nombre'            => 'Liquidacion Stock',
			'tipo_descuento'    => 'porcentaje',
			'valor'             => 50,
			'fecha_inicio'      => gmdate( 'Y-m-d' ),
			'fecha_fin'         => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
			'excluir_en_oferta' => 0,
			'apilable'          => 0,
			'tope_descuento'    => 0,
			'banner_text'       => 'LIQUIDACION -50% | Ultimos dias',
		),
	);
}

/**
 * Crea una regla desde un preset. Retorna el array de regla listo para guardar.
 * El admin puede editarla antes de activarla.
 *
 * @param string $preset_key Clave del preset (e.g. 'cyberday_chile').
 * @return array<string, mixed>|null  Regla lista para persistir, o null si no existe.
 */
function akibara_descuento_create_from_preset( string $preset_key ): ?array {
	$presets = akibara_descuento_presets();
	if ( ! isset( $presets[ $preset_key ] ) ) {
		return null;
	}

	$preset = $presets[ $preset_key ];

	// Preservar el banner_text (la regla lo usa en frontend).
	// Descartar solo los metadatos puramente UI (label + descripcion).
	unset( $preset['label'], $preset['descripcion'] );

	$preset['id']     = 'rule_' . bin2hex( random_bytes( 8 ) );
	$preset['activo'] = 0; // Inactivo hasta que el admin lo active manualmente.

	return $preset;
}
