<?php
/**
 * Akibara Descuentos — Motor de Precios
 *
 * Calcula descuentos por porcentaje y monto fijo (CLP).
 * Hooks en filtros de precio de WooCommerce @ priority 999.
 *
 * @package Akibara\Descuentos
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

class Akibara_Descuento_Engine {

	private $main;
	private $exclusion_cache = array();

	public function __construct( Akibara_Descuento_Taxonomia $main ) {
		$this->main = $main;
	}

	// ══════════════════════════════════════════════════════════════
	// MIGRACION Y VALIDACION
	// ══════════════════════════════════════════════════════════════

	/**
	 * Migra una regla v10 a formato v11 rellenando campos faltantes.
	 */
	public function migrar_regla_v10( array $regla ): array {
		// ID inmutable
		if ( empty( $regla['id'] ) ) {
			$regla['id'] = 'rule_' . bin2hex( random_bytes( 8 ) );
		}

		// Campos v11 con defaults
		$defaults = array(
			'tipo_descuento'      => 'porcentaje',
			'tope_descuento'      => 0,
			'alcance'             => 'producto',
			'apilable'            => false,
			'tramos'              => array(),
			'carrito_condiciones' => array(),
		);

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $regla ) ) {
				$regla[ $key ] = $default;
			}
		}

		return $this->validate_regla( $regla );
	}

	/**
	 * Valida y sanitiza una regla.
	 */
	public function validate_regla( array $regla ): array {
		$regla['nombre']            = sanitize_text_field( $regla['nombre'] ?? '' );
		$regla['activo']            = ! empty( $regla['activo'] );
		$regla['apilable']          = ! empty( $regla['apilable'] );
		$regla['excluir_en_oferta'] = ! empty( $regla['excluir_en_oferta'] );

		// Tipo descuento
		if ( ! in_array( $regla['tipo_descuento'] ?? '', array( 'porcentaje', 'fijo' ), true ) ) {
			$regla['tipo_descuento'] = 'porcentaje';
		}

		// Valor
		$valor = (float) ( $regla['valor'] ?? 0 );
		if ( $regla['tipo_descuento'] === 'porcentaje' ) {
			$regla['valor'] = min( 99, max( 1, $valor ) );
		} else {
			$regla['valor'] = max( 1, $valor );
		}

		// Tope descuento
		$regla['tope_descuento'] = max( 0, (int) ( $regla['tope_descuento'] ?? 0 ) );

		// Alcance
		if ( ! in_array( $regla['alcance'] ?? '', array( 'producto', 'carrito' ), true ) ) {
			$regla['alcance'] = 'producto';
		}

		// Fechas
		$regla['fecha_inicio']        = sanitize_text_field( $regla['fecha_inicio'] ?? '' );
		$regla['fecha_fin']           = sanitize_text_field( $regla['fecha_fin'] ?? '' );
		$regla['productos_excluidos'] = sanitize_text_field( $regla['productos_excluidos'] ?? '' );
		$regla['productos_incluidos'] = sanitize_text_field( $regla['productos_incluidos'] ?? '' );

		// Taxonomias
		if ( ! is_array( $regla['taxonomias'] ?? null ) ) {
			$regla['taxonomias'] = array();
		}

		// Tramos
		if ( ! is_array( $regla['tramos'] ?? null ) ) {
			$regla['tramos'] = array();
		}

		// Condiciones carrito
		if ( ! is_array( $regla['carrito_condiciones'] ?? null ) ) {
			$regla['carrito_condiciones'] = array();
		}

		return $regla;
	}

	// ══════════════════════════════════════════════════════════════
	// REGLAS ACTIVAS
	// ══════════════════════════════════════════════════════════════

	/**
	 * Verifica si hay reglas activas de un alcance específico.
	 */
	public function hay_reglas_activas( string $alcance = 'producto' ): bool {
		foreach ( $this->main->get_reglas() as $regla ) {
			if ( ( $regla['alcance'] ?? 'producto' ) !== $alcance ) {
				continue;
			}
			if ( $this->regla_esta_activa( $regla ) ) {
				return true;
			}
		}
		return false;
	}

	public function regla_esta_activa( array $regla ): bool {
		if ( empty( $regla['activo'] ) ) {
			return false;
		}

		$now = time();
		if ( ! empty( $regla['fecha_inicio'] ) && $now < strtotime( $regla['fecha_inicio'] ) ) {
			return false;
		}
		if ( ! empty( $regla['fecha_fin'] ) && $now > strtotime( $regla['fecha_fin'] . ' 23:59:59' ) ) {
			return false;
		}

		return true;
	}

	public function get_estado_regla( array $regla ): array {
		if ( empty( $regla['activo'] ) ) {
			return array(
				'class' => 'inactive',
				'text'  => 'Inactivo',
			);
		}

		$now = time();
		if ( ! empty( $regla['fecha_inicio'] ) && $now < strtotime( $regla['fecha_inicio'] ) ) {
			return array(
				'class' => 'scheduled',
				'text'  => 'Programado',
			);
		}
		if ( ! empty( $regla['fecha_fin'] ) && $now > strtotime( $regla['fecha_fin'] . ' 23:59:59' ) ) {
			return array(
				'class' => 'expired',
				'text'  => 'Expirado',
			);
		}

		return array(
			'class' => 'active',
			'text'  => 'Activo',
		);
	}

	// ══════════════════════════════════════════════════════════════
	// FILTROS DE PRECIO
	// ══════════════════════════════════════════════════════════════

	public function register_price_filters(): void {
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 999, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 999, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 999, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 999, 2 );
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_prices' ), 999, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_variation_prices' ), 999, 3 );
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_is_on_sale' ), 999, 2 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_hash' ), 999, 1 );
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html_you_save' ), 20, 2 );
	}

	public function filter_price( $price, $product ) {
		$desc = $this->calcular_mejor_descuento( $product );

		if ( $desc === null ) {
			return $price;
		}

		// Si un filtro previo (ej: preventa priority 998) ya entregó un precio mejor, respetarlo.
		$incoming = (float) $price;
		if ( $incoming > 0 && $incoming < $desc ) {
			return $incoming;
		}

		return $desc;
	}

	public function filter_sale_price( $sale, $product ) {
		$desc = $this->calcular_mejor_descuento( $product );

		if ( $desc === null ) {
			return $sale;
		}

		$incoming = (float) $sale;
		if ( $incoming > 0 && $incoming < $desc ) {
			return $incoming;
		}

		return $desc;
	}

	public function filter_variation_prices( $price, $variation, $product ) {
		if ( $this->main->processing ) {
			return $price;
		}
		if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_id' ) ) {
			return $price;
		}

		$var = is_a( $variation, 'WC_Product' ) ? $variation : wc_get_product( $variation->get_id() );
		if ( ! $var ) {
			return $price;
		}

		$desc = $this->calcular_mejor_descuento( $var );
		return $desc !== null ? $desc : $price;
	}

	public function filter_is_on_sale( $on_sale, $product ) {
		return $this->calcular_mejor_descuento( $product ) !== null ? true : $on_sale;
	}

	public function filter_variation_hash( $hash ) {
		$reglas = $this->main->get_reglas();
		$hash[] = 'akibara_v11_' . md5( serialize( $reglas ) );
		return $hash;
	}

	/**
	 * Agrega "Ahorras $X" al HTML del precio.
	 * Calcula el ahorro real comparando regular con el precio final del cliente
	 * (que puede venir de este módulo O del módulo de reservas).
	 */
	public function filter_price_html_you_save( $html, $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return $html;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}

		// Evitar duplicar si ya tiene "Ahorras"
		if ( strpos( $html, 'akd-you-save' ) !== false ) {
			return $html;
		}

		$regular = (float) $product->get_regular_price( 'edit' );
		if ( $regular <= 0 ) {
			return $html;
		}

		// Usar el precio final que ve el cliente (ya filtrado por todos los módulos)
		$precio_final = (float) $product->get_price();
		if ( $precio_final <= 0 || $precio_final >= $regular ) {
			return $html;
		}

		$ahorro = (int) round( $regular - $precio_final );
		if ( $ahorro <= 0 ) {
			return $html;
		}

		$html .= ' <span class="akd-you-save">Ahorras ' . wc_price( $ahorro ) . '</span>';
		return $html;
	}

	// ══════════════════════════════════════════════════════════════
	// MOTOR PRINCIPAL DE DESCUENTOS
	// ══════════════════════════════════════════════════════════════

	/**
	 * Calcula el mejor precio con descuento para un producto.
	 * Devuelve null si no aplica ninguna regla.
	 */
	public function calcular_mejor_descuento( $product ): ?float {
		$product_id = $product->get_id();

		// Cache hit
		if ( array_key_exists( $product_id, $this->main->price_cache ) ) {
			return $this->main->price_cache[ $product_id ];
		}

		// Limitar tamaño del cache
		if ( count( $this->main->price_cache ) >= Akibara_Descuento_Taxonomia::CACHE_LIMIT ) {
			$this->main->price_cache = array_slice( $this->main->price_cache, (int) ( Akibara_Descuento_Taxonomia::CACHE_LIMIT / 2 ), null, true );
		}

		if ( $this->main->processing ) {
			return null;
		}

		try {
			$this->main->processing = true;
			$regular                = (float) $product->get_regular_price( 'edit' );
			$sale_raw               = $product->get_sale_price( 'edit' );
			$sale                   = ( $sale_raw !== '' && $sale_raw !== null ) ? (float) $sale_raw : null;
		} finally {
			$this->main->processing = false;
		}

		if ( $regular <= 0 ) {
			return $this->main->price_cache[ $product_id ] = null;
		}

		$parent_id = $product->get_parent_id();

		// Buscar mejor descuento entre reglas de producto
		$mejor_precio_standalone = null;
		$descuento_apilado       = 0;

		foreach ( $this->main->get_reglas() as $idx => $regla ) {
			if ( ( $regla['alcance'] ?? 'producto' ) !== 'producto' ) {
				continue;
			}
			if ( ! $this->regla_esta_activa( $regla ) ) {
				continue;
			}
			if ( ! $this->regla_aplica_a_producto( $regla, $product_id, $parent_id, $idx ) ) {
				continue;
			}

			// Excluir productos que ya tienen oferta manual
			if ( ! empty( $regla['excluir_en_oferta'] ) && $sale !== null && $sale < $regular ) {
				continue;
			}

			$precio_regla = $this->calcular_descuento_regla( $regla, $regular );

			if ( ! empty( $regla['apilable'] ) ) {
				// Reglas apilables: acumular descuento
				$descuento_apilado += ( $regular - $precio_regla );
			} else {
				// Reglas standalone: quedarse con el mejor precio (menor)
				if ( $mejor_precio_standalone === null || $precio_regla < $mejor_precio_standalone ) {
					$mejor_precio_standalone = $precio_regla;
				}
			}
		}

		// Combinar: mejor standalone + suma de apilables
		$mejor_precio = null;

		if ( $mejor_precio_standalone !== null ) {
			$mejor_precio = $mejor_precio_standalone - $descuento_apilado;
		} elseif ( $descuento_apilado > 0 ) {
			$mejor_precio = $regular - $descuento_apilado;
		}

		if ( $mejor_precio !== null ) {
			// Hard cap: nunca más de 25% de descuento total
			$floor_25pct  = (int) round( $regular * 0.75 );
			$mejor_precio = max( $mejor_precio, $floor_25pct );

			// Piso absoluto: nunca $0 o negativo
			$mejor_precio = (float) max( 1, (int) round( $mejor_precio ) );
		}

		// Si hay descuento manual existente igual o mejor, no reemplazar
		if ( $mejor_precio !== null && $sale !== null && $sale > 0 && $sale < $regular ) {
			if ( $sale <= $mejor_precio ) {
				return $this->main->price_cache[ $product_id ] = null;
			}
		}

		return $this->main->price_cache[ $product_id ] = $mejor_precio;
	}

	/**
	 * Calcula el precio con descuento para una regla específica.
	 */
	public function calcular_descuento_regla( array $regla, float $regular, int $qty = 1 ): float {
		$tipo  = $regla['tipo_descuento'] ?? 'porcentaje';
		$valor = (float) ( $regla['valor'] ?? 0 );
		$tope  = (int) ( $regla['tope_descuento'] ?? 0 );

		if ( $tipo === 'fijo' ) {
			$descuento = $valor;
		} else {
			$descuento = $regular * ( $valor / 100 );
		}

		// Aplicar tope
		if ( $tope > 0 ) {
			$descuento = min( $descuento, $tope );
		}

		$precio = (int) round( $regular - $descuento );
		return (float) max( 1, $precio );
	}

	// ══════════════════════════════════════════════════════════════
	// EVALUACION DE REGLAS
	// ══════════════════════════════════════════════════════════════

	/**
	 * Evalúa si una regla aplica a un producto específico.
	 * Usa rule hash como cache key en vez de serialize().
	 */
	public function regla_aplica_a_producto( array $regla, int $product_id, int $parent_id = 0, int $idx = 0 ): bool {
		$rule_hash = $this->main->rule_hashes[ $idx ] ?? $idx;
		$cache_key = $rule_hash . '_' . $product_id . '_' . $parent_id;

		if ( isset( $this->exclusion_cache[ $cache_key ] ) ) {
			return $this->exclusion_cache[ $cache_key ];
		}

		$check_id = $parent_id ?: $product_id;

		// Productos excluidos por ID
		$excluidos = array_filter( array_map( 'absint', explode( ',', $regla['productos_excluidos'] ?? '' ) ) );
		if ( in_array( $product_id, $excluidos, true ) || in_array( $check_id, $excluidos, true ) ) {
			return $this->exclusion_cache[ $cache_key ] = false;
		}

		// Productos incluidos por ID
		$incluidos = array_filter( array_map( 'absint', explode( ',', $regla['productos_incluidos'] ?? '' ) ) );
		if ( ! empty( $incluidos ) ) {
			if ( in_array( $product_id, $incluidos, true ) || in_array( $check_id, $incluidos, true ) ) {
				return $this->exclusion_cache[ $cache_key ] = true;
			}
			if ( empty( $regla['taxonomias'] ) ) {
				return $this->exclusion_cache[ $cache_key ] = false;
			}
		}

		// Verificar taxonomías
		$taxonomias = $regla['taxonomias'] ?? array();
		if ( empty( $taxonomias ) ) {
			return $this->exclusion_cache[ $cache_key ] = true;
		}

		$incluir = array_filter( $taxonomias, fn( $t ) => ( $t['tipo'] ?? 'incluir' ) === 'incluir' );
		$excluir = array_filter( $taxonomias, fn( $t ) => ( $t['tipo'] ?? 'incluir' ) === 'excluir' );

		// Primero evaluar exclusiones
		foreach ( $excluir as $tax ) {
			if ( $this->producto_tiene_term( $check_id, $tax['taxonomy'], $tax['term_id'], ! empty( $tax['hereda'] ) ) ) {
				return $this->exclusion_cache[ $cache_key ] = false;
			}
		}

		// Luego inclusiones
		if ( ! empty( $incluir ) ) {
			foreach ( $incluir as $tax ) {
				if ( $this->producto_tiene_term( $check_id, $tax['taxonomy'], $tax['term_id'], ! empty( $tax['hereda'] ) ) ) {
					return $this->exclusion_cache[ $cache_key ] = true;
				}
			}
			return $this->exclusion_cache[ $cache_key ] = false;
		}

		return $this->exclusion_cache[ $cache_key ] = true;
	}

	/**
	 * Verifica si un producto tiene un término específico.
	 * Usa WP core term cache (primed por WooCommerce en shop loops).
	 */
	private function producto_tiene_term( int $product_id, string $taxonomy, int $term_id, bool $hereda = false ): bool {
		$product_terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $product_terms ) ) {
			return false;
		}

		if ( in_array( (int) $term_id, $product_terms, true ) ) {
			return true;
		}

		if ( $hereda ) {
			$children = get_term_children( (int) $term_id, $taxonomy );
			if ( ! is_wp_error( $children ) ) {
				foreach ( $children as $child_id ) {
					if ( in_array( $child_id, $product_terms, true ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	// ══════════════════════════════════════════════════════════════
	// HELPERS DE ADMIN
	// ══════════════════════════════════════════════════════════════

	public function get_taxonomias_disponibles(): array {
		$taxonomias = array(
			'product_cat' => array(
				'label'        => 'Categorías',
				'hierarchical' => true,
			),
			'product_tag' => array(
				'label'        => 'Etiquetas',
				'hierarchical' => false,
			),
		);

		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomias['product_brand'] = array(
				'label'        => 'Marcas',
				'hierarchical' => true,
			);
		}

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attr ) {
				$name = 'pa_' . $attr->attribute_name;
				if ( taxonomy_exists( $name ) ) {
					$taxonomias[ $name ] = array(
						'label'        => $attr->attribute_label ?: ucfirst( $attr->attribute_name ),
						'hierarchical' => false,
					);
				}
			}
		}

		return $taxonomias;
	}

	public function get_taxonomias_con_terms(): array {
		$result = array();
		foreach ( $this->get_taxonomias_disponibles() as $tax => $data ) {
			$result[ $tax ] = array(
				'label' => $data['label'],
				'terms' => $this->get_terms_hierarchical( $tax ),
			);
		}
		return $result;
	}

	private function get_terms_hierarchical( string $taxonomy, int $parent = 0, int $level = 0 ): array {
		// Cache solo la entrada raíz (árbol completo por taxonomy).
		// Las llamadas recursivas con $parent>0 no se cachean para evitar fragmentación.
		$is_root   = ( 0 === $parent && 0 === $level );
		$cache_key = 'akb_terms_hier_' . $taxonomy;

		if ( $is_root ) {
			$cached = wp_cache_get( $cache_key, 'akibara_descuentos' );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'parent'     => $parent,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $result;
		}

		foreach ( $terms as $term ) {
			$prefix   = str_repeat( '— ', $level );
			$result[] = array(
				'id'   => $term->term_id,
				'name' => $prefix . $term->name,
			);
			$result   = array_merge( $result, $this->get_terms_hierarchical( $taxonomy, $term->term_id, $level + 1 ) );
		}

		if ( $is_root ) {
			// TTL 1 hora: balance entre frescura y reducción de queries.
			// Invalidación explícita vía hooks created/edited/deleted_term en flush_terms_cache().
			wp_cache_set( $cache_key, $result, 'akibara_descuentos', HOUR_IN_SECONDS );
		}

		return $result;
	}

	/**
	 * Invalida el cache de árboles de términos cuando se modifican términos.
	 * Llamado desde hooks created_term/edited_term/deleted_term registrados en taxonomia.php.
	 */
	public function flush_terms_cache( $term_id = 0, $tt_id = 0, $taxonomy = '' ): void {
		if ( ! empty( $taxonomy ) ) {
			wp_cache_delete( 'akb_terms_hier_' . $taxonomy, 'akibara_descuentos' );
			return;
		}

		// Sin taxonomy específica: flush conocidas.
		foreach ( array_keys( $this->get_taxonomias_disponibles() ) as $tax ) {
			wp_cache_delete( 'akb_terms_hier_' . $tax, 'akibara_descuentos' );
		}
	}

	public function render_resumen_regla( array $regla, array $taxonomias ): string {
		$partes = array();

		if ( ! empty( $regla['taxonomias'] ) ) {
			foreach ( $regla['taxonomias'] as $t ) {
				$tax_label = $taxonomias[ $t['taxonomy'] ]['label'] ?? $t['taxonomy'];
				$term      = get_term( $t['term_id'], $t['taxonomy'] );
				$term_name = $term && ! is_wp_error( $term ) ? $term->name : "ID:{$t['term_id']}";
				$tipo      = ( $t['tipo'] ?? 'incluir' ) === 'excluir' ? '<em>Excluir</em> ' : '';
				$hereda    = ! empty( $t['hereda'] ) ? ' <span style="color:#0073aa">(+sub)</span>' : '';
				$partes[]  = $tipo . esc_html( $tax_label ) . ': <strong>' . esc_html( $term_name ) . '</strong>' . $hereda;
			}
		}

		if ( empty( $partes ) ) {
			$partes[] = '<em>Todos los productos</em>';
		}

		$extras = array();
		if ( ! empty( $regla['excluir_en_oferta'] ) ) {
			$extras[] = 'No aplica a productos con oferta propia';
		}
		if ( ! empty( $regla['apilable'] ) ) {
			$extras[] = 'Apilable';
		}
		if ( (int) ( $regla['tope_descuento'] ?? 0 ) > 0 ) {
			$extras[] = 'Tope: ' . wc_price( $regla['tope_descuento'] );
		}
		$excluidos = array_filter( array_map( 'absint', explode( ',', $regla['productos_excluidos'] ?? '' ) ) );
		if ( ! empty( $excluidos ) ) {
			$extras[] = count( $excluidos ) . ' producto(s) excluido(s)';
		}

		// Condiciones carrito
		if ( ( $regla['alcance'] ?? 'producto' ) === 'carrito' && ! empty( $regla['carrito_condiciones'] ) ) {
			foreach ( $regla['carrito_condiciones'] as $cond ) {
				if ( $cond['tipo'] === 'monto_min' ) {
					$partes[] = 'Carrito min: ' . wc_price( $cond['valor'] );
				} elseif ( $cond['tipo'] === 'cantidad_min' ) {
					$partes[] = 'Min ' . $cond['valor'] . ' items en carrito';
				}
			}
		}

		$out = implode( ' · ', $partes );
		if ( $extras ) {
			$out .= '<br><span style="font-size:12px;color:#999">' . implode( ' · ', $extras ) . '</span>';
		}
		return $out;
	}
}
