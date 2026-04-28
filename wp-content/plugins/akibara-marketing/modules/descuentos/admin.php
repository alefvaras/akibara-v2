<?php
/**
 * Akibara Descuentos — Panel de Administración
 *
 * Wizard de 3 pasos: Tipo → Configuración → Avanzado
 * AJAX handlers con MySQL GET_LOCK para concurrencia.
 *
 * @package Akibara\Descuentos
 * @version 11.0.0
 */

defined( 'ABSPATH' ) || exit;

class Akibara_Descuento_Admin {

	private $main;

	public function __construct( Akibara_Descuento_Taxonomia $main ) {
		$this->main = $main;

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

		// 3 endpoints comparten capability 'manage_woocommerce' + nonce 'akibara_descuento'
		// vía akb_ajax_endpoint(). Mutex GET_LOCK vive dentro del método. Fallback al
		// patrón add_action() clásico si el helper no está disponible.
		if ( function_exists( 'akb_ajax_endpoint' ) ) {
			$self = $this;
			akb_ajax_endpoint(
				'akibara_desc_save',
				array(
					'nonce'      => 'akibara_descuento',
					'capability' => 'manage_woocommerce',
					'handler'    => static function ( array $post ) use ( $self ): void {
						$self->ajax_save();
					},
				)
			);
			akb_ajax_endpoint(
				'akibara_desc_delete',
				array(
					'nonce'      => 'akibara_descuento',
					'capability' => 'manage_woocommerce',
					'handler'    => static function ( array $post ) use ( $self ): void {
						$self->ajax_delete();
					},
				)
			);
			akb_ajax_endpoint(
				'akibara_desc_toggle',
				array(
					'nonce'      => 'akibara_descuento',
					'capability' => 'manage_woocommerce',
					'handler'    => static function ( array $post ) use ( $self ): void {
						$self->ajax_toggle();
					},
				)
			);
		} else {
			add_action( 'wp_ajax_akibara_desc_save', array( $this, 'ajax_save' ) );
			add_action( 'wp_ajax_akibara_desc_delete', array( $this, 'ajax_delete' ) );
			add_action( 'wp_ajax_akibara_desc_toggle', array( $this, 'ajax_toggle' ) );
		}
	}

	public function add_admin_menu(): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		}
		add_submenu_page(
			'akibara',
			'Descuentos Akibara',
			'🏷️ Descuentos',
			'manage_woocommerce',
			'akibara-descuentos',
			array( $this, 'render_admin' )
		);
	}

	public function admin_scripts( string $hook ): void {
		if ( $hook !== 'woocommerce_page_akibara-descuentos' ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'select2' );
		wp_enqueue_style( 'select2' );
	}

	// ══════════════════════════════════════════════════════════════
	// MUTEX
	// ══════════════════════════════════════════════════════════════

	private function acquire_lock(): bool {
		global $wpdb;
		$result = $wpdb->get_var( "SELECT GET_LOCK('akibara_desc_save', 10)" );
		return $result === '1';
	}

	private function release_lock(): void {
		global $wpdb;
		$wpdb->get_var( "SELECT RELEASE_LOCK('akibara_desc_save')" );
	}

	private static function validate_date( string $input ): string {
		$date = sanitize_text_field( $input );
		if ( $date === '' ) {
			return '';
		}
		$dt = \DateTime::createFromFormat( 'Y-m-d', $date );
		return ( $dt && $dt->format( 'Y-m-d' ) === $date ) ? $date : '';
	}

	// ══════════════════════════════════════════════════════════════
	// AJAX HANDLERS
	// ══════════════════════════════════════════════════════════════

	public function ajax_save(): void {
		check_ajax_referer( 'akibara_descuento', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( 'Otro usuario está guardando. Intenta en un momento.' );
		}

		try {
			$rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
			$reglas  = $this->main->get_reglas();

			// Determinar tipo descuento y valor
			$tipo_descuento = in_array( $_POST['tipo_descuento'] ?? '', array( 'porcentaje', 'fijo' ), true )
				? $_POST['tipo_descuento'] : 'porcentaje';

			$valor_raw = (float) ( $_POST['valor'] ?? 10 );
			if ( $tipo_descuento === 'porcentaje' ) {
				$valor = min( 99, max( 1, $valor_raw ) );
			} else {
				$valor = max( 1, $valor_raw );
			}

			$regla = array(
				'id'                  => $rule_id ?: ( 'rule_' . bin2hex( random_bytes( 8 ) ) ),
				'nombre'              => sanitize_text_field( $_POST['nombre'] ?? '' ),
				'tipo_descuento'      => $tipo_descuento,
				'valor'               => $valor,
				'tope_descuento'      => max( 0, (int) ( $_POST['tope_descuento'] ?? 0 ) ),
				'alcance'             => in_array( $_POST['alcance'] ?? '', array( 'producto', 'carrito' ), true )
					? $_POST['alcance'] : 'producto',
				'apilable'            => ! empty( $_POST['apilable'] ),
				'fecha_inicio'        => self::validate_date( $_POST['fecha_inicio'] ?? '' ),
				'fecha_fin'           => self::validate_date( $_POST['fecha_fin'] ?? '' ),
				'productos_excluidos' => implode( ',', array_filter( array_map( 'absint', explode( ',', $_POST['productos_excluidos'] ?? '' ) ) ) ),
				'productos_incluidos' => implode( ',', array_filter( array_map( 'absint', explode( ',', $_POST['productos_incluidos'] ?? '' ) ) ) ),
				'excluir_en_oferta'   => ! empty( $_POST['excluir_en_oferta'] ),
				'activo'              => ! empty( $_POST['activo'] ),
				'banner_text'         => sanitize_text_field( $_POST['banner_text'] ?? '' ),
				'taxonomias'          => array(),
				'tramos'              => array(),
				'carrito_condiciones' => array(),
			);

			// Procesar taxonomías (solo para reglas de producto)
			if ( $regla['alcance'] === 'producto' ) {
				$tax_taxonomy = is_array( $_POST['tax_taxonomy'] ?? array() ) ? $_POST['tax_taxonomy'] : array();
				$tax_term     = is_array( $_POST['tax_term'] ?? array() ) ? $_POST['tax_term'] : array();
				$tax_tipo     = is_array( $_POST['tax_tipo'] ?? array() ) ? $_POST['tax_tipo'] : array();
				$tax_hereda   = is_array( $_POST['tax_hereda'] ?? array() ) ? $_POST['tax_hereda'] : array();

				for ( $i = 0; $i < count( $tax_taxonomy ); $i++ ) {
					if ( empty( $tax_taxonomy[ $i ] ) ) {
						continue;
					}
					$regla['taxonomias'][] = array(
						'taxonomy' => sanitize_key( $tax_taxonomy[ $i ] ),
						'term_id'  => (int) ( $tax_term[ $i ] ?? 0 ),
						'tipo'     => ( ( $tax_tipo[ $i ] ?? 'incluir' ) === 'excluir' ) ? 'excluir' : 'incluir',
						'hereda'   => ! empty( $tax_hereda[ $i ] ),
					);
				}
			}

			// Procesar condiciones de carrito
			if ( $regla['alcance'] === 'carrito' ) {
				$cond_tipo  = is_array( $_POST['cond_tipo'] ?? array() ) ? $_POST['cond_tipo'] : array();
				$cond_valor = is_array( $_POST['cond_valor'] ?? array() ) ? $_POST['cond_valor'] : array();

				for ( $i = 0; $i < count( $cond_tipo ); $i++ ) {
					if ( empty( $cond_tipo[ $i ] ) ) {
						continue;
					}
					$regla['carrito_condiciones'][] = array(
						'tipo'  => in_array( $cond_tipo[ $i ], array( 'monto_min', 'cantidad_min' ), true )
							? $cond_tipo[ $i ] : 'monto_min',
						'valor' => max( 0, (int) ( $cond_valor[ $i ] ?? 0 ) ),
					);
				}
			}

			// Encontrar y reemplazar por ID, o agregar nueva
			$found = false;
			if ( $rule_id ) {
				foreach ( $reglas as $idx => $existing ) {
					if ( ( $existing['id'] ?? '' ) === $rule_id ) {
						$reglas[ $idx ] = $regla;
						$found          = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				$reglas[] = $regla;
			}

			$this->main->save_reglas( $reglas );
			$this->rebuild_search_index();
			wp_send_json_success();

		} finally {
			$this->release_lock();
		}
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'akibara_descuento', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( 'Otro usuario está guardando.' );
		}

		try {
			$rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
			$reglas  = $this->main->get_reglas();

			$reglas = array_values(
				array_filter(
					$reglas,
					function ( $r ) use ( $rule_id ) {
						return ( $r['id'] ?? '' ) !== $rule_id;
					}
				)
			);

			$this->main->save_reglas( $reglas );
			$this->rebuild_search_index();
			wp_send_json_success();

		} finally {
			$this->release_lock();
		}
	}

	public function ajax_toggle(): void {
		check_ajax_referer( 'akibara_descuento', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( 'Otro usuario está guardando.' );
		}

		try {
			$rule_id = sanitize_text_field( $_POST['rule_id'] ?? '' );
			$reglas  = $this->main->get_reglas();

			foreach ( $reglas as &$regla ) {
				if ( ( $regla['id'] ?? '' ) === $rule_id ) {
					$regla['activo'] = empty( $regla['activo'] );
					break;
				}
			}
			unset( $regla );

			$this->main->save_reglas( $reglas );
			$this->rebuild_search_index();
			wp_send_json_success();

		} finally {
			$this->release_lock();
		}
	}

	private function rebuild_search_index(): void {
		if ( function_exists( 'akb_rebuild_full_index' ) ) {
			akb_rebuild_full_index();
		}
	}

	// ══════════════════════════════════════════════════════════════
	// RENDER ADMIN
	// ══════════════════════════════════════════════════════════════

	public function render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}

		$reglas     = $this->main->get_reglas();
		$taxonomias = $this->main->engine->get_taxonomias_disponibles();
		?>
		<div class="akb-page-header" style="display:flex;justify-content:space-between;align-items:center">
			<div>
				<h2 class="akb-page-header__title">Descuentos</h2>
				<p class="akb-page-header__desc"><?php echo count( $reglas ); ?> regla(s) configurada(s) &mdash; v<?php echo esc_html( Akibara_Descuento_Taxonomia::VERSION ); ?></p>
			</div>
			<button class="akb-btn akb-btn--primary" onclick="akdAbrirModal()">+ Nueva Regla</button>
		</div>

		<?php if ( function_exists( 'akibara_descuento_presets' ) ) : ?>
		<div class="akb-desc-presets" style="margin:0 0 1rem 0;padding:12px 14px;background:#fafafa;border:1px solid #e2e2e2;border-radius:6px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
			<label for="akb-preset-select"><strong>Crear desde preset:</strong></label>
			<select id="akb-preset-select" style="min-width:260px">
				<option value="">— Selecciona una campaña —</option>
				<?php foreach ( akibara_descuento_presets() as $key => $p ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" data-desc="<?php echo esc_attr( $p['descripcion'] ); ?>">
						<?php echo esc_html( $p['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" id="akb-preset-apply" class="button">Aplicar preset</button>
			<p class="description" id="akb-preset-desc" style="flex-basis:100%;margin:4px 0 0 0;color:#666;"></p>
		</div>
		<?php endif; ?>

		<?php if ( empty( $reglas ) ) : ?>
			<div class="akb-empty">
				<p>No hay reglas de descuento configuradas.</p>
				<p style="font-size:13px;color:var(--akb-text-muted)">Crea reglas para aplicar descuentos por categoria, monto fijo, o condiciones de carrito.</p>
				<button class="akb-btn akb-btn--primary" onclick="akdAbrirModal()">Crear primera regla</button>
			</div>
		<?php else : ?>
			<?php
			foreach ( $reglas as $regla ) :
				$estado        = $this->main->engine->get_estado_regla( $regla );
				$tipo          = $regla['tipo_descuento'] ?? 'porcentaje';
				$alcance       = $regla['alcance'] ?? 'producto';
				$valor_display = $tipo === 'fijo'
					? '$' . number_format( $regla['valor'] ?? 0, 0, ',', '.' )
					: ( $regla['valor'] ?? 0 ) . '%';

				$badge_class = match ( $estado['class'] ) {
					'active'    => 'akb-badge--active',
					'inactive'  => 'akb-badge--inactive',
					'scheduled' => 'akb-badge--warning',
					'expired'   => 'akb-badge--error',
					default     => 'akb-badge--inactive',
				};
				?>
			<div class="akb-card <?php echo ! empty( $regla['activo'] ) ? '' : 'akb-card--inactive'; ?>">
				<div class="akb-card__header">
					<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
						<span class="akb-card__title"><?php echo esc_html( $regla['nombre'] ?? 'Sin nombre' ); ?></span>
						<span class="akb-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $estado['text'] ); ?></span>
						<?php if ( $alcance === 'carrito' ) : ?>
							<span class="akb-badge akb-badge--info">Carrito</span>
						<?php endif; ?>
						<span class="akb-badge akb-badge--discount"><?php echo $tipo === 'fijo' ? '-' : ''; ?><?php echo esc_html( $valor_display ); ?></span>
					</div>
					<div class="akb-card__actions">
						<button class="akb-btn akb-btn--sm" onclick="akdToggle('<?php echo esc_js( $regla['id'] ); ?>')">
							<?php echo ! empty( $regla['activo'] ) ? 'Desactivar' : 'Activar'; ?>
						</button>
						<button class="akb-btn akb-btn--sm" onclick="akdEditar('<?php echo esc_js( $regla['id'] ); ?>')">Editar</button>
						<button class="akb-btn akb-btn--sm akb-btn--danger" onclick="akdEliminar('<?php echo esc_js( $regla['id'] ); ?>')">Eliminar</button>
					</div>
				</div>
				<div class="akb-rule-desc"><?php echo wp_kses_post( $this->main->engine->render_resumen_regla( $regla, $taxonomias ) ); ?></div>
				<?php if ( ! empty( $regla['fecha_inicio'] ) || ! empty( $regla['fecha_fin'] ) ) : ?>
				<div class="akb-rule-meta">
					<?php
					if ( ! empty( $regla['fecha_inicio'] ) ) {
						echo 'Desde ' . esc_html( date_i18n( 'd/m/Y', strtotime( $regla['fecha_inicio'] ) ) );}
					?>
					<?php
					if ( ! empty( $regla['fecha_fin'] ) ) {
						echo ' hasta ' . esc_html( date_i18n( 'd/m/Y', strtotime( $regla['fecha_fin'] ) ) );}
					?>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<!-- Modal Wizard -->
		<div id="akd-modal" class="akb-modal-overlay">
			<div class="akb-modal" style="max-width:760px">
				<div class="akb-modal__header">
					<h2 class="akb-modal__title" id="akd-modal-title">Nueva Regla de Descuento</h2>
					<button class="akb-modal__close" onclick="akdCerrarModal()">&times;</button>
				</div>

				<!-- Step navigation -->
				<div class="akb-step-nav">
					<span class="akb-step-btn akb-step-btn--current" data-step="1">1. Tipo</span>
					<span class="akb-step-btn" data-step="2">2. Configuracion</span>
					<span class="akb-step-btn" data-step="3">3. Avanzado</span>
				</div>

				<form id="akd-form">
					<input type="hidden" name="rule_id" id="akd-rule-id" value="">

					<!-- STEP 1: Tipo de regla -->
					<div class="akb-wizard-step akb-wizard-step--active" data-step="1">
						<div class="akb-type-cards">
							<div class="akb-type-card" data-alcance="producto" onclick="akdSelectTipo(this)">
								<h4>Descuento Simple</h4>
								<p>Porcentaje o monto fijo a categorias, etiquetas o productos especificos</p>
							</div>
							<div class="akb-type-card" data-alcance="carrito" onclick="akdSelectTipo(this)">
								<h4>Descuento Carrito</h4>
								<p>Descuento automatico cuando el carrito cumple condiciones de monto o cantidad</p>
							</div>
							<div class="akb-type-card akb-type-card--disabled" title="Disponible en v11.1">
								<h4>Por Cantidad</h4>
								<p>Tramos: compra 3+ &rarr; 7%, 5+ &rarr; 10%<br><em style="color:var(--akb-text-muted)">Proximamente v11.1</em></p>
							</div>
						</div>
						<input type="hidden" name="alcance" id="akd-alcance" value="producto">
						<div style="text-align:right;margin-top:var(--akb-s4)">
							<button type="button" class="akb-btn akb-btn--primary" onclick="akdNextStep(2)" id="akd-btn-step2" disabled>Siguiente &rarr;</button>
						</div>
					</div>

					<!-- STEP 2: Configuración -->
					<div class="akb-wizard-step" data-step="2">
						<div class="akb-field__row">
							<div class="akb-field" style="flex:2">
								<label class="akb-field__label">Nombre de la regla</label>
								<input type="text" name="nombre" id="akd-nombre" class="akb-field__input" required placeholder="Ej: Descuento Manga" style="max-width:100%">
							</div>
							<div class="akb-field" style="max-width:120px">
								<label class="akb-field__label">Tipo</label>
								<select name="tipo_descuento" id="akd-tipo-descuento" class="akb-field__input" onchange="akdToggleTipo()" style="max-width:120px">
									<option value="porcentaje">%</option>
									<option value="fijo">$ CLP</option>
								</select>
							</div>
							<div class="akb-field" style="max-width:130px">
								<label class="akb-field__label" id="akd-valor-label">Descuento %</label>
								<input type="number" name="valor" id="akd-valor" class="akb-field__input" min="1" max="99" value="10" required style="max-width:130px">
							</div>
						</div>

						<div class="akb-field__row">
							<div class="akb-field">
								<label class="akb-field__label">Fecha inicio (opcional)</label>
								<input type="date" name="fecha_inicio" id="akd-fecha-inicio" class="akb-field__input">
							</div>
							<div class="akb-field">
								<label class="akb-field__label">Fecha fin (opcional)</label>
								<input type="date" name="fecha_fin" id="akd-fecha-fin" class="akb-field__input">
							</div>
						</div>

						<!-- Taxonomías (solo producto) -->
						<div class="akb-field" id="akd-tax-section">
							<label class="akb-field__label">Condiciones de taxonomia</label>
							<div class="akb-condition-list" id="akd-tax-container"></div>
							<button type="button" class="akb-btn akb-btn--sm" onclick="akdAgregarTax()" style="margin-top:8px">+ Agregar condicion</button>
							<div class="akb-notice akb-notice--info" style="margin-top:10px">
								Sin condiciones &rarr; aplica a todos los productos.<br>
								Usa <strong>Incluir</strong> para aplicar solo a esa taxonomia. Usa <strong>Excluir</strong> para saltarse esa taxonomia.<br>
								<strong>+Sub</strong> hereda tambien a las subcategorias.
							</div>
						</div>

						<!-- Condiciones carrito (solo carrito) -->
						<div class="akb-field" id="akd-cond-section" style="display:none">
							<label class="akb-field__label">Condiciones del carrito</label>
							<div id="akd-cond-container"></div>
							<button type="button" class="akb-btn akb-btn--sm" onclick="akdAgregarCond()" style="margin-top:8px">+ Agregar condicion</button>
							<div class="akb-notice akb-notice--info" style="margin-top:10px">
								Define cuando se activa el descuento: monto minimo del carrito o cantidad minima de items.
							</div>
						</div>

						<div style="display:flex;justify-content:space-between;margin-top:var(--akb-s4)">
							<button type="button" class="akb-btn" onclick="akdNextStep(1)">&larr; Anterior</button>
							<button type="button" class="akb-btn akb-btn--primary" onclick="akdNextStep(3)">Siguiente &rarr;</button>
						</div>
					</div>

					<!-- STEP 3: Avanzado -->
					<div class="akb-wizard-step" data-step="3">
						<div class="akb-field">
							<label class="akb-field__label">Tope de descuento (CLP)</label>
							<input type="number" name="tope_descuento" id="akd-tope" class="akb-field__input" min="0" value="0" placeholder="0 = sin tope" style="max-width:200px">
							<p class="akb-field__hint">Limita el descuento maximo en pesos. 0 = sin limite.</p>
						</div>

						<div class="akb-field" id="akd-excluidos-section">
							<label class="akb-field__label">Excluir productos por ID (separados por coma)</label>
							<input type="text" name="productos_excluidos" id="akd-excluidos" class="akb-field__input" placeholder="123, 456, 789" style="max-width:100%">
						</div>

						<div class="akb-field">
							<label>
								<input type="checkbox" name="excluir_en_oferta" id="akd-excluir-oferta">
								No aplicar a productos que ya tienen precio de oferta propio
							</label>
						</div>

						<div class="akb-field">
							<label>
								<input type="checkbox" name="apilable" id="akd-apilable">
								Regla apilable (se suma con otros descuentos standalone)
							</label>
							<p class="akb-field__hint">Las reglas no apilables (standalone) compiten entre si: gana la mayor. Las apilables se suman sobre la standalone ganadora.</p>
						</div>

						<div class="akb-field">
							<label>
								<input type="checkbox" name="activo" id="akd-activo" checked>
								Regla activa
							</label>
						</div>

						<div class="akb-modal__footer" style="justify-content:space-between">
							<button type="button" class="akb-btn" onclick="akdNextStep(2)">&larr; Anterior</button>
							<div style="display:flex;gap:8px">
								<button type="button" class="akb-btn" onclick="akdCerrarModal()">Cancelar</button>
								<button type="submit" class="akb-btn akb-btn--primary">Guardar regla</button>
							</div>
						</div>
					</div>
				</form>
			</div>
		</div>

		<script>
		var akdTaxData  = <?php echo wp_json_encode( $this->main->engine->get_taxonomias_con_terms() ); ?>;
		var akdReglas   = <?php echo wp_json_encode( $reglas ); ?>;
	function akdEsc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
		var akdAjaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var akdNonce    = '<?php echo esc_js( wp_create_nonce( 'akibara_descuento' ) ); ?>';

		// ─── Wizard navigation ───────────────────────────────────
		function akdNextStep(step) {
			document.querySelectorAll('.akb-wizard-step').forEach(function(s) { s.classList.remove('akb-wizard-step--active'); });
			document.querySelector('.akb-wizard-step[data-step="'+step+'"]').classList.add('akb-wizard-step--active');
			document.querySelectorAll('.akb-step-btn').forEach(function(b) {
				var bs = parseInt(b.dataset.step);
				b.classList.remove('akb-step-btn--current','akb-step-btn--done');
				if (bs === step) b.classList.add('akb-step-btn--current');
				else if (bs < step) b.classList.add('akb-step-btn--done');
			});
		}

		// ─── Type selection (Step 1) ─────────────────────────────
		function akdSelectTipo(card) {
			if (card.classList.contains('akb-type-card--disabled')) return;
			document.querySelectorAll('.akb-type-card').forEach(function(c) { c.classList.remove('akb-type-card--selected'); });
			card.classList.add('akb-type-card--selected');
			var alcance = card.dataset.alcance;
			document.getElementById('akd-alcance').value = alcance;
			document.getElementById('akd-btn-step2').disabled = false;

			document.getElementById('akd-tax-section').style.display = alcance === 'producto' ? '' : 'none';
			document.getElementById('akd-cond-section').style.display = alcance === 'carrito' ? '' : 'none';
			document.getElementById('akd-excluidos-section').style.display = alcance === 'producto' ? '' : 'none';
		}

		// ─── Toggle tipo descuento ───────────────────────────────
		function akdToggleTipo() {
			var tipo = document.getElementById('akd-tipo-descuento').value;
			var input = document.getElementById('akd-valor');
			var label = document.getElementById('akd-valor-label');
			if (tipo === 'fijo') {
				input.removeAttribute('max');
				input.min = 1;
				label.textContent = 'Monto CLP';
			} else {
				input.max = 99;
				input.min = 1;
				label.textContent = 'Descuento %';
			}
		}

		// ─── Modal open/close ────────────────────────────────────
		function akdAbrirModal(ruleId) {
			document.getElementById('akd-form').reset();
			document.getElementById('akd-rule-id').value = '';
			document.getElementById('akd-tax-container').innerHTML = '';
			document.getElementById('akd-cond-container').innerHTML = '';
			document.getElementById('akd-activo').checked = true;
			document.getElementById('akd-btn-step2').disabled = true;
			document.querySelectorAll('.akb-type-card').forEach(function(c) { c.classList.remove('akb-type-card--selected'); });

			akdNextStep(1);
			document.getElementById('akd-modal-title').textContent = 'Nueva Regla de Descuento';

			if (ruleId) {
				var r = akdReglas.find(function(x) { return x.id === ruleId; });
				if (r) {
					document.getElementById('akd-modal-title').textContent = 'Editar Regla';
					document.getElementById('akd-rule-id').value = r.id;
					document.getElementById('akd-nombre').value = r.nombre || '';
					document.getElementById('akd-tipo-descuento').value = r.tipo_descuento || 'porcentaje';
					document.getElementById('akd-valor').value = r.valor || 10;
					document.getElementById('akd-fecha-inicio').value = r.fecha_inicio || '';
					document.getElementById('akd-fecha-fin').value = r.fecha_fin || '';
					document.getElementById('akd-excluidos').value = r.productos_excluidos || '';
					document.getElementById('akd-excluir-oferta').checked = !!r.excluir_en_oferta;
					document.getElementById('akd-apilable').checked = !!r.apilable;
					document.getElementById('akd-activo').checked = !!r.activo;
					document.getElementById('akd-tope').value = r.tope_descuento || 0;
					document.getElementById('akd-alcance').value = r.alcance || 'producto';

					var alcance = r.alcance || 'producto';
					var typeCard = document.querySelector('.akb-type-card[data-alcance="'+alcance+'"]');
					if (typeCard) typeCard.classList.add('akb-type-card--selected');
					document.getElementById('akd-btn-step2').disabled = false;
					document.getElementById('akd-tax-section').style.display = alcance === 'producto' ? '' : 'none';
					document.getElementById('akd-cond-section').style.display = alcance === 'carrito' ? '' : 'none';
					document.getElementById('akd-excluidos-section').style.display = alcance === 'producto' ? '' : 'none';

					akdToggleTipo();

					if (r.taxonomias && r.taxonomias.length) {
						r.taxonomias.forEach(function(t) {
							akdAgregarTax(t.taxonomy, t.term_id, t.tipo, t.hereda);
						});
					}

					if (r.carrito_condiciones && r.carrito_condiciones.length) {
						r.carrito_condiciones.forEach(function(c) {
							akdAgregarCond(c.tipo, c.valor);
						});
					}

					akdNextStep(2);
				}
			}

			document.getElementById('akd-modal').classList.add('akb-modal-overlay--open');
			document.body.style.overflow = 'hidden';
		}

		function akdCerrarModal() {
			document.getElementById('akd-modal').classList.remove('akb-modal-overlay--open');
			document.body.style.overflow = '';
		}

		function akdEditar(ruleId) { akdAbrirModal(ruleId); }

		// ─── Taxonomy conditions ─────────────────────────────────
		function akdAgregarTax(tax, term, tipo, hereda) {
			tax = tax || ''; term = term || ''; tipo = tipo || 'incluir'; hereda = hereda || false;
			var container = document.getElementById('akd-tax-container');
			var div = document.createElement('div');
			div.className = 'akb-condition-item';

			var sTax = '<select name="tax_taxonomy[]" onchange="akdCargarTerms(this)" style="min-width:140px">';
			sTax += '<option value="">-- Taxonomia --</option>';
			for (var key in akdTaxData) {
				sTax += '<option value="'+key+'"'+(key===tax?' selected':'')+'>'+akdEsc(akdTaxData[key].label)+'</option>';
			}
			sTax += '</select>';

			var sTerm = '<select name="tax_term[]" class="akd-term-select" style="min-width:160px">';
			sTerm += '<option value="">-- Todos --</option>';
			if (tax && akdTaxData[tax]) {
				akdTaxData[tax].terms.forEach(function(t) {
					sTerm += '<option value="'+t.id+'"'+(t.id==term?' selected':'')+'>'+akdEsc(t.name)+'</option>';
				});
			}
			sTerm += '</select>';

			var sTipo = '<select name="tax_tipo[]" style="width:90px">';
			sTipo += '<option value="incluir"'+(tipo==='incluir'?' selected':'')+'>Incluir</option>';
			sTipo += '<option value="excluir"'+(tipo==='excluir'?' selected':'')+'>Excluir</option>';
			sTipo += '</select>';

			var taxIdx = container.querySelectorAll('.akb-condition-item').length;
			var sHereda = '<input type="hidden" name="tax_hereda['+taxIdx+']" value="0"><label style="white-space:nowrap;font-size:12px"><input type="checkbox" name="tax_hereda['+taxIdx+']" value="1"'+(hereda?' checked':'')+' > +Sub</label>';

			div.innerHTML = sTax + sTerm + sTipo + sHereda + '<button type="button" class="akb-btn akb-btn--sm akb-btn--danger" onclick="this.parentElement.remove()" style="flex-shrink:0">&times;</button>';
			container.appendChild(div);
		}

		function akdCargarTerms(select) {
			var tax = select.value;
			var termSelect = select.parentElement.querySelector('.akd-term-select');
			termSelect.innerHTML = '<option value="">-- Todos --</option>';
			if (tax && akdTaxData[tax]) {
				akdTaxData[tax].terms.forEach(function(t) {
					termSelect.innerHTML += '<option value="'+t.id+'">'+t.name+'</option>';
				});
			}
		}

		// ─── Cart conditions ─────────────────────────────────────
		function akdAgregarCond(tipo, valor) {
			tipo = tipo || 'monto_min'; valor = valor || '';
			var container = document.getElementById('akd-cond-container');
			var div = document.createElement('div');
			div.className = 'akb-condition-item';

			var sTipo = '<select name="cond_tipo[]" style="min-width:140px">';
			sTipo += '<option value="monto_min"'+(tipo==='monto_min'?' selected':'')+'>Monto minimo (CLP)</option>';
			sTipo += '<option value="cantidad_min"'+(tipo==='cantidad_min'?' selected':'')+'>Cantidad minima items</option>';
			sTipo += '</select>';

			var sValor = '<input type="number" name="cond_valor[]" value="'+valor+'" min="0" placeholder="Valor" style="width:120px">';

			div.innerHTML = sTipo + sValor + '<button type="button" class="akb-btn akb-btn--sm akb-btn--danger" onclick="this.parentElement.remove()">&times;</button>';
			container.appendChild(div);
		}

		// ─── AJAX helpers ────────────────────────────────────────
		function akdPost(body, callback) {
			fetch(akdAjaxUrl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: body + '&nonce=' + akdNonce
			}).then(function(r) { return r.json(); }).then(function(data) {
				if (data.success) location.reload();
				else alert(data.data || 'Error');
			}).catch(function() { alert('Error de conexión'); });
		}

		function akdToggle(ruleId) {
			akdPost('action=akibara_desc_toggle&rule_id=' + encodeURIComponent(ruleId));
		}

		function akdEliminar(ruleId) {
			if (!confirm('¿Eliminar esta regla? No se puede deshacer.')) return;
			akdPost('action=akibara_desc_delete&rule_id=' + encodeURIComponent(ruleId));
		}

		// ─── Form submit ─────────────────────────────────────────
		document.getElementById('akd-form').addEventListener('submit', function(e) {
			e.preventDefault();
			var items = document.querySelectorAll('#akd-tax-container .akb-condition-item');
			items.forEach(function(item, idx) {
				var inputs = item.querySelectorAll('input[name^="tax_hereda"]');
				inputs.forEach(function(inp) { inp.name = 'tax_hereda[' + idx + ']'; });
			});
			var fd = new FormData(this);
			fd.append('action', 'akibara_desc_save');
			fd.append('nonce', akdNonce);
			fetch(akdAjaxUrl, { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.success) location.reload();
					else alert(data.data || 'Error al guardar');
				}).catch(function() { alert('Error de conexión'); });
		});

		document.getElementById('akd-modal').addEventListener('click', function(e) {
			if (e.target === this) akdCerrarModal();
		});

		// ─── Presets de campaña ─────────────────────────────────
		(function(){
			var sel   = document.getElementById('akb-preset-select');
			var btn   = document.getElementById('akb-preset-apply');
			var descP = document.getElementById('akb-preset-desc');
			if (!sel || !btn) return;

			sel.addEventListener('change', function(){
				var opt = sel.options[sel.selectedIndex];
				descP.textContent = opt ? (opt.getAttribute('data-desc') || '') : '';
			});

			btn.addEventListener('click', function(){
				var key = sel.value;
				if (!key) { alert('Selecciona un preset primero.'); return; }
				btn.disabled = true;
				var body = 'action=akb_desc_get_preset'
						+ '&preset=' + encodeURIComponent(key)
						+ '&nonce='  + akdNonce;
				fetch(akdAjaxUrl, {
					method: 'POST',
					headers: {'Content-Type':'application/x-www-form-urlencoded'},
					body: body
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					btn.disabled = false;
					if (!data || !data.success || !data.data || !data.data.rule) {
						alert((data && data.data && data.data.error) || 'No se pudo cargar el preset');
						return;
					}
					var rule = data.data.rule;
					akdAbrirModal();
					// Llenar inputs del wizard con el preset.
					document.getElementById('akd-nombre').value         = rule.nombre || '';
					document.getElementById('akd-tipo-descuento').value = rule.tipo_descuento || 'porcentaje';
					document.getElementById('akd-valor').value          = rule.valor || 10;
					document.getElementById('akd-fecha-inicio').value   = rule.fecha_inicio || '';
					document.getElementById('akd-fecha-fin').value      = rule.fecha_fin || '';
					document.getElementById('akd-tope').value           = rule.tope_descuento || 0;
					document.getElementById('akd-excluir-oferta').checked = !!rule.excluir_en_oferta;
					document.getElementById('akd-apilable').checked       = !!rule.apilable;
					document.getElementById('akd-activo').checked         = !!rule.activo;
					// Alcance default producto (el campo 'alcance' del preset editorial_x guarda
					// una taxonomia sugerida, no un alcance; se ignora aqui y se deja 'producto').
					var card = document.querySelector('.akb-type-card[data-alcance="producto"]');
					if (card) akdSelectTipo(card);
					akdToggleTipo();
					// Persistir banner_text en un hidden para el save (ver A1.1 abajo).
					var bt = document.getElementById('akd-banner-text');
					if (!bt) {
						bt = document.createElement('input');
						bt.type = 'hidden';
						bt.name = 'banner_text';
						bt.id   = 'akd-banner-text';
						document.getElementById('akd-form').appendChild(bt);
					}
					bt.value = rule.banner_text || '';
					akdNextStep(2);
				})
				.catch(function(){
					btn.disabled = false;
					alert('Error de conexión al cargar el preset');
				});
			});
		})();
		</script>
		<?php
	}
}
