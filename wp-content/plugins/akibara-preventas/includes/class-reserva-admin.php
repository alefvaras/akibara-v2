<?php
/**
 * Admin: dashboard de reservas, pagina de ajustes, lista de pendientes.
 */

defined( 'ABSPATH' ) || exit;

final class Akibara_Reserva_Admin {

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );

		// AJAX: edicion masiva — migrado a helper akb_ajax_endpoint()
		if ( function_exists( 'akb_ajax_endpoint' ) ) {
			akb_ajax_endpoint( 'akb_reserva_bulk_update', [
				'nonce'      => 'akb_reserva_bulk',
				'capability' => 'manage_woocommerce',
				'handler'    => [ __CLASS__, 'handle_bulk_update' ],
			] );
		} else {
			add_action( 'wp_ajax_akb_reserva_bulk_update', [ __CLASS__, 'ajax_bulk_update' ] );
		}

		// Aviso de migracion YITH
		add_action( 'admin_notices', [ __CLASS__, 'migration_notice' ] );
	}

	// ─── Menus ───────────────────────────────────────────────────

	public static function register_menus(): void {
		add_submenu_page(
			'akibara',
			'Reservas',
			'Reservas',
			'manage_woocommerce',
			'akb-reservas',
			[ __CLASS__, 'render_dashboard' ]
		);

		add_submenu_page(
			'akb-reservas',
			'Ajustes Reservas',
			'Ajustes Reservas',
			'manage_woocommerce',
			'akb-reservas-ajustes',
			[ __CLASS__, 'render_settings' ]
		);
	}

	// ─── Dashboard ───────────────────────────────────────────────

	public static function render_dashboard(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'pendientes';

		$pending_ids   = Akibara_Reserva_Orders::get_pending_orders();
		$pending_count = count( $pending_ids );

		?>
		<div class="wrap">
			<h1>Reservas Akibara</h1>

			<div style="display:flex;gap:15px;margin:15px 0;">
				<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:15px 25px;text-align:center;">
					<div style="font-size:28px;font-weight:700;color:#ff6b35;"><?php echo esc_html( $pending_count ); ?></div>
					<div style="font-size:13px;color:#666;">Pendientes</div>
				</div>
			</div>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=akb-reservas&tab=pendientes' ) ); ?>"
				   class="nav-tab <?php echo 'pendientes' === $tab ? 'nav-tab-active' : ''; ?>">
					Pendientes (<?php echo esc_html( $pending_count ); ?>)
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=akb-reservas&tab=completadas' ) ); ?>"
				   class="nav-tab <?php echo 'completadas' === $tab ? 'nav-tab-active' : ''; ?>">
					Completadas
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=akb-reservas&tab=masiva' ) ); ?>"
				   class="nav-tab <?php echo 'masiva' === $tab ? 'nav-tab-active' : ''; ?>">
					Edicion Masiva
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=akb-reservas-ajustes' ) ); ?>"
				   class="nav-tab">Ajustes</a>
			</nav>

			<div style="margin-top:15px;">
				<?php
				if ( 'masiva' === $tab ) {
					self::render_bulk_editor();
				} elseif ( 'completadas' === $tab ) {
					self::render_orders_table( 'completada' );
				} else {
					self::render_orders_table( 'esperando' );
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function render_orders_table( string $estado ): void {
		$orders = wc_get_orders( [
			'limit'      => 50,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => [
				'relation' => 'AND',
				[ 'key' => Akibara_Reserva_Orders::ORDER_HAS,    'value' => 'yes' ],
				[ 'key' => Akibara_Reserva_Orders::ORDER_ESTADO,  'value' => $estado ],
			],
		] );

		if ( empty( $orders ) ) {
			echo '<p>No hay reservas en este estado.</p>';
			return;
		}

		$nonce = wp_create_nonce( 'akb_reserva_action' );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:80px;">Pedido</th>
					<th>Cliente</th>
					<th>Productos</th>
					<th>Tipo</th>
					<th>Fecha Est.</th>
					<th>Fecha Pedido</th>
					<?php if ( 'esperando' === $estado ) : ?>
						<th style="width:180px;">Acciones</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<?php
					$items = [];
					$fecha = '';
					foreach ( $order->get_items() as $item ) {
						if ( 'yes' !== $item->get_meta( Akibara_Reserva_Orders::ITEM_RESERVA ) ) continue;
						$items[] = $item->get_name() . ' x' . $item->get_quantity();
						$f = (int) $item->get_meta( Akibara_Reserva_Orders::ITEM_FECHA );
						if ( $f > 0 && empty( $fecha ) ) $fecha = akb_reserva_fecha( $f );
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
								#<?php echo esc_html( $order->get_order_number() ); ?>
							</a>
						</td>
						<td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
						<td><?php echo esc_html( implode( ', ', $items ) ); ?></td>
						<td>
							<span class="akb-tipo-badge akb-tipo-preventa">PREVENTA</span>
						</td>
						<td><?php echo esc_html( $fecha ?: 'Sin fecha' ); ?></td>
						<?php
						// Null-safe: get_date_created() puede ser null en órdenes corruptas/legacy.
						$created      = $order->get_date_created();
						$created_text = $created ? $created->date_i18n( 'd/m/Y' ) : '—';
						?>
						<td><?php echo esc_html( $created_text ); ?></td>
						<?php if ( 'esperando' === $estado ) : ?>
							<td>
								<button type="button" class="button button-small akb-action"
									data-action="akb_reserva_completar"
									data-order="<?php echo esc_attr( $order->get_id() ); ?>"
									data-nonce="<?php echo esc_attr( $nonce ); ?>"
									style="color:#155724;">Completar</button>
								<button type="button" class="button button-small akb-action"
									data-action="akb_reserva_cancelar"
									data-order="<?php echo esc_attr( $order->get_id() ); ?>"
									data-nonce="<?php echo esc_attr( $nonce ); ?>"
									style="color:#721c24;">Cancelar</button>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( 'esperando' === $estado ) : ?>
		<script>
		jQuery(function($) {
			$( '.akb-action').on('click', function() {
				var $btn = $(this);
				if (!confirm('Confirmar accion?')) return;
				$.post(ajaxurl, {
					action: $btn.data('action'),
					order_id: $btn.data('order'),
					nonce: $btn.data('nonce')
				}, function(r) {
					if (r.success) location.reload();
					else alert(r.data || 'Error');
				});
			});
		});
		</script>
		<?php endif;
	}

	// ─── Settings ────────────────────────────────────────────────

	public static function register_settings(): void {
		register_setting( 'akb_reservas_settings', 'akb_reservas_descuento_preventa' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_whatsapp_numero' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_auto_oos_enabled' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_auto_oos_categories' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_texto_sin_fecha' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_email_admin' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_brand_shipping_times' );
		register_setting( 'akb_reservas_settings', 'akb_reservas_async_emails', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
		] );
		register_setting( 'akb_reservas_settings', 'akb_reservas_descuento_categorias', [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize_descuento_categorias' ],
			'default'           => [],
		] );
	}

	/**
	 * Sanitiza el mapa category_id => descuento%.
	 * Descarta entradas vacías o <= 0 para mantener la opción compacta.
	 */
	public static function sanitize_descuento_categorias( $input ): array {
		if ( ! is_array( $input ) ) return [];
		$out = [];
		foreach ( $input as $cat_id => $pct ) {
			$cid = absint( $cat_id );
			$val = (int) $pct;
			if ( $cid > 0 && $val > 0 && $val < 100 ) {
				$out[ $cid ] = min( 99, $val );
			}
		}
		return $out;
	}

	public static function render_settings(): void {
		$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$selected_cats = get_option( 'akb_reservas_auto_oos_categories', [] );
		if ( ! is_array( $selected_cats ) ) $selected_cats = [];

		// Brand shipping times
		$brands = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] );
		$shipping_times = get_option( 'akb_reservas_brand_shipping_times', [] );
		if ( ! is_array( $shipping_times ) ) $shipping_times = [];

		$default_times = [
			'ivrea-argentina'  => 21,
			'panini-argentina' => 21,
			'ovni-press'       => 21,
			'ivrea-espana'     => 30,
			'panini-espana'    => 30,
			'planeta-espana'   => 30,
			'arechi-manga'     => 30,
			'milky-way'        => 30,
		];
		?>
		<div class="wrap">
			<h1>Ajustes Reservas</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'akb_reservas_settings' ); ?>
				<table class="form-table">
					<tr>
						<th>Descuento preventa por defecto (%)</th>
						<td><input type="number" name="akb_reservas_descuento_preventa" min="0" max="99"
							value="<?php echo esc_attr( get_option( 'akb_reservas_descuento_preventa', 5 ) ); ?>" style="width:80px;">
							<p class="description">Descuento aplicado a toda preventa que no tenga uno propio ni uno por categoría. Recomendado: 5%.</p></td>
					</tr>
					<tr>
						<th>Numero WhatsApp (con codigo de pais)</th>
						<td><input type="text" name="akb_reservas_whatsapp_numero" placeholder="56912345678"
							value="<?php echo esc_attr( get_option( 'akb_reservas_whatsapp_numero', '' ) ); ?>" style="width:200px;">
							<p class="description">Ej: 56912345678 (Chile)</p></td>
					</tr>
					<tr>
						<th>Email admin para notificaciones</th>
						<td><input type="email" name="akb_reservas_email_admin"
							value="<?php echo esc_attr( get_option( 'akb_reservas_email_admin', get_option( 'admin_email' ) ) ); ?>" style="width:300px;"></td>
					</tr>
					<tr>
						<th>Emails async (Action Scheduler)</th>
						<td>
							<label>
								<input type="checkbox" name="akb_reservas_async_emails" value="1"
									<?php checked( (bool) get_option( 'akb_reservas_async_emails', false ) ); ?>>
								Enviar emails de reserva en background (recomendado)
							</label>
							<p class="description">
								Si está activo, los emails se encolan en Action Scheduler y no bloquean el checkout.<br>
								Desactivar solo si hay problemas con la queue. Rollback inmediato: <code>wp option update akb_reservas_async_emails 0</code>
							</p>
						</td>
					</tr>
					<tr>
						<th>Texto disponibilidad sin fecha</th>
						<td><input type="text" name="akb_reservas_texto_sin_fecha"
							value="<?php echo esc_attr( get_option( 'akb_reservas_texto_sin_fecha', get_option( 'akb_reservas_texto_pedido_especial', 'Estimado: 2-4 semanas' ) ) ); ?>" style="width:300px;">
							<p class="description">Se muestra cuando la preventa no tiene fecha, ni estado proveedor ni tiempos de editorial configurados.</p></td>
					</tr>
					<tr>
						<th>Auto preventa al quedar sin stock</th>
						<td><label><input type="checkbox" name="akb_reservas_auto_oos_enabled" value="1"
							<?php checked( get_option( 'akb_reservas_auto_oos_enabled' ) ); ?>>
							Convertir automaticamente a preventa cuando un producto quede sin stock</label></td>
					</tr>
					<tr>
						<th>Categorias para auto preventa</th>
						<td>
							<select name="akb_reservas_auto_oos_categories[]" multiple style="width:300px;height:120px;">
								<?php foreach ( $cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->term_id ); ?>"
										<?php echo in_array( (string) $cat->term_id, $selected_cats, true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Dejar vacio = todas las categorias</p>
						</td>
					</tr>
				</table>

				<h2 style="margin-top:30px;">Descuentos por categoría</h2>
				<p class="description" style="margin-bottom:15px;">Define un descuento específico para cada categoría. Se aplica a productos en preventa que no tengan un descuento propio. Si un producto pertenece a varias categorías con descuento, se usa el mayor. Dejar en 0 = sin descuento de categoría (usa el default global).</p>
				<?php $cat_descuentos = get_option( 'akb_reservas_descuento_categorias', [] ); if ( ! is_array( $cat_descuentos ) ) $cat_descuentos = []; ?>
				<table class="form-table" style="max-width:500px;">
					<?php if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) : ?>
						<?php foreach ( $cats as $cat ) :
							$cval = isset( $cat_descuentos[ $cat->term_id ] ) ? (int) $cat_descuentos[ $cat->term_id ] : 0;
						?>
						<tr>
							<th style="width:220px;"><?php echo esc_html( $cat->name ); ?> <span style="color:#999;font-weight:normal;">(<?php echo (int) $cat->count; ?>)</span></th>
							<td>
								<input type="number" name="akb_reservas_descuento_categorias[<?php echo (int) $cat->term_id; ?>]"
									value="<?php echo esc_attr( $cval ); ?>" min="0" max="99" style="width:80px;"> %
							</td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td>No hay categorías de producto todavía.</td></tr>
					<?php endif; ?>
				</table>

				<h2 style="margin-top:30px;">Tiempos de envio por editorial</h2>
				<p class="description" style="margin-bottom:15px;">Configura los dias estimados de envio desde cada editorial. Estos tiempos se usan para calcular fechas estimadas de llegada automaticamente.</p>
				<table class="form-table" style="max-width:500px;">
					<?php if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) : ?>
						<?php foreach ( $brands as $brand ) :
							$current = isset( $shipping_times[ $brand->slug ] ) ? (int) $shipping_times[ $brand->slug ] : ( isset( $default_times[ $brand->slug ] ) ? $default_times[ $brand->slug ] : 0 );
						?>
						<tr>
							<th style="width:200px;"><?php echo esc_html( $brand->name ); ?></th>
							<td>
								<input type="number" name="akb_reservas_brand_shipping_times[<?php echo esc_attr( $brand->slug ); ?>]"
									value="<?php echo esc_attr( $current ); ?>" min="0" max="365" style="width:80px;"> dias
							</td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="2">No se encontraron editoriales (taxonomia product_brand). <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=product_brand&post_type=product' ) ); ?>">Crear editoriales</a></td></tr>
					<?php endif; ?>
				</table>

				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>
		</div>
		<?php
	}

	// ─── Editor Masivo ───────────────────────────────────────────

	private static function render_bulk_editor(): void {
		$editoriales = akb_reserva_editoriales();
		$cats        = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
		$nonce       = wp_create_nonce( 'akb_reserva_bulk' );

		// Brands para filtro
		$brands = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] );

		// Contar productos por editorial
		global $wpdb;
		$editorial_counts = $wpdb->get_results(
			"SELECT meta_value as editorial, COUNT(*) as total
			 FROM {$wpdb->postmeta} pm1
			 JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
			 WHERE pm1.meta_key = '_akb_reserva' AND pm1.meta_value = 'yes'
			 AND pm2.meta_key = '_akb_reserva_editorial' AND pm2.meta_value != ''
			 GROUP BY pm2.meta_value",
			OBJECT_K
		);

		// Labels para estados proveedor
		$estado_labels = [
			'sin_pedir'   => 'Sin pedir',
			'pedido'      => 'Pedido',
			'en_transito' => 'En transito',
			'recibido'    => 'Recibido',
		];
		?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;max-width:700px;">
			<h2 style="margin-top:0;">Edicion masiva de reservas</h2>
			<p style="color:#666;">Cambia la fecha, tipo, descuento o estado proveedor de multiples productos a la vez.</p>

			<form id="akb-bulk-form">
				<table class="form-table" style="margin:0;">
					<tr>
						<th>Filtrar por</th>
						<td>
							<label style="display:block;margin-bottom:8px;">
								<strong>Editorial (meta):</strong><br>
								<select name="filter_editorial" style="width:250px;">
									<option value="">-- Todas --</option>
									<?php foreach ( $editoriales as $ed ) :
										$count = isset( $editorial_counts[ $ed ] ) ? $editorial_counts[ $ed ]->total : 0;
									?>
										<option value="<?php echo esc_attr( $ed ); ?>"><?php echo esc_html( $ed ); ?> (<?php echo esc_html( $count ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<strong>Editorial (brand):</strong><br>
								<select name="filter_brand" style="width:250px;">
									<option value="">-- Todas --</option>
									<?php if ( ! empty( $brands ) && ! is_wp_error( $brands ) ) :
										foreach ( $brands as $brand ) : ?>
										<option value="<?php echo esc_attr( $brand->slug ); ?>"><?php echo esc_html( $brand->name ); ?> (<?php echo esc_html( $brand->count ); ?>)</option>
									<?php endforeach; endif; ?>
								</select>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<strong>Categoria:</strong><br>
								<select name="filter_category" style="width:250px;">
									<option value="">-- Todas --</option>
									<?php foreach ( $cats as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?> (<?php echo esc_html( $cat->count ); ?>)</option>
									<?php endforeach; ?>
								</select>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<strong>Estado proveedor actual:</strong><br>
								<select name="filter_estado_proveedor" style="width:250px;">
									<option value="">-- Todos --</option>
									<?php foreach ( $estado_labels as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
						</td>
					</tr>
					<tr>
						<th>Accion</th>
						<td>
							<label style="display:block;margin-bottom:8px;">
								<strong>Nueva fecha:</strong><br>
								<input type="date" name="new_fecha" style="width:200px;">
								<span style="color:#999;font-size:12px;">Dejar vacio para no cambiar</span>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<strong>Modo fecha:</strong><br>
								<select name="new_fecha_modo" style="width:200px;">
									<option value="">-- No cambiar --</option>
									<option value="fija">Fecha fija</option>
									<option value="estimada">Fecha estimada</option>
									<option value="sin_fecha">Sin fecha</option>
								</select>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<strong>Descuento %:</strong><br>
								<input type="number" name="new_descuento" min="-1" max="99" value="-1" style="width:80px;">
								<span style="color:#999;font-size:12px;">-1 = no cambiar, 0 = sin descuento</span>
							</label>
							<label style="display:block;margin-bottom:12px;padding:10px;background:#f0f7ff;border:1px solid #c8ddf0;border-radius:4px;">
								<strong>Cambiar estado proveedor:</strong><br>
								<select name="new_estado_proveedor" style="width:200px;margin-top:4px;">
									<option value="">-- No cambiar --</option>
									<?php foreach ( $estado_labels as $val => $label ) : ?>
										<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<br><span style="color:#666;font-size:12px;">Al marcar como "Pedido", se registra la fecha automaticamente</span>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<input type="checkbox" name="notify_customers" value="1">
								Notificar clientes con reservas pendientes del cambio de fecha
							</label>
						</td>
					</tr>
				</table>

				<div id="akb-bulk-preview" style="margin:15px 0;padding:10px;background:#f8f8f8;border-radius:4px;display:none;">
					<strong>Vista previa:</strong> <span id="akb-bulk-count">0</span> productos seran actualizados.
				</div>

				<button type="button" id="akb-bulk-preview-btn" class="button">Ver productos afectados</button>
				<button type="button" id="akb-bulk-apply-btn" class="button button-primary" style="margin-left:8px;" disabled>Aplicar cambios</button>
				<span id="akb-bulk-status" style="margin-left:10px;"></span>
			</form>
		</div>

		<script>
		jQuery(function($) {
			var nonce = '<?php echo esc_js( $nonce ); ?>';

			$('#akb-bulk-preview-btn').on('click', function() {
				var data = $('#akb-bulk-form').serializeArray();
				data.push({name: 'action', value: 'akb_reserva_bulk_update'});
				data.push({name: 'nonce', value: nonce});
				data.push({name: 'mode', value: 'preview'});

				$('#akb-bulk-status').text('Calculando...');
				$.post(ajaxurl, $.param(data), function(r) {
					$('#akb-bulk-status').text('');
					if (r.success) {
						$('#akb-bulk-count').text(r.data.count);
						$('#akb-bulk-preview').show();
						$('#akb-bulk-apply-btn').prop('disabled', r.data.count === 0);
					} else {
						$('#akb-bulk-status').text('Error: ' + (r.data || 'desconocido'));
					}
				});
			});

			$('#akb-bulk-apply-btn').on('click', function() {
				if (!confirm('Se actualizaran los productos filtrados. Continuar?')) return;

				var data = $('#akb-bulk-form').serializeArray();
				data.push({name: 'action', value: 'akb_reserva_bulk_update'});
				data.push({name: 'nonce', value: nonce});
				data.push({name: 'mode', value: 'apply'});

				$('#akb-bulk-status').text('Aplicando...');
				$.post(ajaxurl, $.param(data), function(r) {
					if (r.success) {
						$('#akb-bulk-status').html('<strong style="color:green;">Listo: ' + r.data.updated + ' productos actualizados, ' + r.data.notified + ' clientes notificados.</strong>');
						$('#akb-bulk-apply-btn').prop('disabled', true);
					} else {
						$('#akb-bulk-status').text('Error: ' + (r.data || 'desconocido'));
					}
				});
			});
		});
		</script>
		<?php
	}

	// ─── AJAX: Edicion masiva ────────────────────────────────────

	/**
	 * Handler migrado al helper akb_ajax_endpoint().
	 * Retorna array — el helper normaliza a wp_send_json_success/error.
	 *
	 * @param array $post $_POST ya sanitizado por el helper.
	 * @return array
	 */
	public static function handle_bulk_update( array $post ): array {
		return self::do_bulk_update( $post );
	}

	/**
	 * Fallback legacy wrapper (solo se registra si el helper no está disponible).
	 */
	public static function ajax_bulk_update(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( "Sin permisos", 403 );
		check_ajax_referer( 'akb_reserva_bulk', 'nonce' );
		$result = self::do_bulk_update( $_POST );
		if ( isset( $result['error'] ) ) wp_send_json_error( $result ); else wp_send_json_success( $result );
	}

	/**
	 * Lógica central de bulk-update. Independiente del transporte AJAX.
	 */
	private static function do_bulk_update( array $post ): array {
		$mode = isset( $post['mode'] ) ? sanitize_text_field( $post['mode'] ) : '';

		// Construir query de filtro
		$meta_query = [
			[ 'key' => '_akb_reserva', 'value' => 'yes' ],
		];

		$filter_editorial = isset( $post['filter_editorial'] ) ? sanitize_text_field( $post['filter_editorial'] ) : '';
		$filter_category  = isset( $post['filter_category'] ) ? absint( $post['filter_category'] ) : 0;
		$filter_brand     = isset( $post['filter_brand'] ) ? sanitize_text_field( $post['filter_brand'] ) : '';
		$filter_estado    = isset( $post['filter_estado_proveedor'] ) ? sanitize_text_field( $post['filter_estado_proveedor'] ) : '';

		if ( $filter_editorial ) {
			$meta_query[] = [ 'key' => '_akb_reserva_editorial', 'value' => $filter_editorial ];
		}
		if ( $filter_estado ) {
			if ( 'sin_pedir' === $filter_estado ) {
				$meta_query[] = [
					'relation' => 'OR',
					[ 'key' => '_akb_reserva_estado_proveedor', 'value' => 'sin_pedir' ],
					[ 'key' => '_akb_reserva_estado_proveedor', 'compare' => 'NOT EXISTS' ],
				];
			} else {
				$meta_query[] = [ 'key' => '_akb_reserva_estado_proveedor', 'value' => $filter_estado ];
			}
		}

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
		];

		if ( $filter_category ) {
			$args['tax_query'] = [
				[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $filter_category ],
			];
		}
		if ( $filter_brand ) {
			$brand_tax = [ 'taxonomy' => 'product_brand', 'field' => 'slug', 'terms' => $filter_brand ];
			if ( isset( $args['tax_query'] ) ) {
				$args['tax_query']['relation'] = 'AND';
				$args['tax_query'][] = $brand_tax;
			} else {
				$args['tax_query'] = [ $brand_tax ];
			}
		}

		$product_ids = get_posts( $args );

		// Preview mode
		if ( 'preview' === $mode ) {
			return [ 'count' => count( $product_ids ) ];
		}

		// Apply mode
		$new_fecha      = isset( $post['new_fecha'] ) ? sanitize_text_field( $post['new_fecha'] ) : '';
		$new_fecha_modo = isset( $post['new_fecha_modo'] ) ? sanitize_text_field( $post['new_fecha_modo'] ) : '';
		$new_descuento  = isset( $post['new_descuento'] ) ? intval( $post['new_descuento'] ) : -1;
		$new_estado     = isset( $post['new_estado_proveedor'] ) ? sanitize_text_field( $post['new_estado_proveedor'] ) : '';
		$notify         = ! empty( $post['notify_customers'] );

		$fecha_ts = ! empty( $new_fecha ) ? akb_reserva_fecha_to_timestamp( $new_fecha ) : 0;

		$updated  = 0;
		$notified = 0;

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) continue;

			$data    = [];
			$changed = false;

			if ( $fecha_ts > 0 ) {
				$old_fecha = Akibara_Reserva_Product::get_fecha( $product );
				$data['fecha'] = $fecha_ts;
				if ( $old_fecha !== $fecha_ts ) $changed = true;
			}

			if ( $new_fecha_modo ) {
				$data['fecha_modo'] = $new_fecha_modo;
			}

			if ( $new_descuento >= 0 ) {
				$data['descuento'] = min( 99, $new_descuento );
			}

			// Estado proveedor
			if ( $new_estado && in_array( $new_estado, Akibara_Reserva_Product::ESTADOS_PROVEEDOR, true ) ) {
				$data['estado_proveedor'] = $new_estado;
				// Auto-set fecha_pedido cuando se marca como pedido
				if ( 'pedido' === $new_estado ) {
					$old_estado = Akibara_Reserva_Product::get_estado_proveedor( $product );
					if ( 'pedido' !== $old_estado ) {
						$data['fecha_pedido'] = time();
					}
				}
			}

			if ( ! empty( $data ) ) {
				Akibara_Reserva_Product::set_meta( $product, $data );
				$updated++;
			}

			// Notificar clientes si cambio la fecha
			if ( $notify && $changed && $fecha_ts > 0 ) {
				do_action( 'akb_reserva_fecha_cambiada', $pid, $old_fecha, $fecha_ts );
				$notified++;
			}
		}

		// Limpiar cache
		if ( $updated > 0 ) {
			wc_delete_product_transients();
		}

		return [
			'updated'  => $updated,
			'notified' => $notified,
		];
	}

	// ─── Aviso migracion YITH ────────────────────────────────────

	public static function migration_notice(): void {
		if ( get_option( 'akb_reservas_yith_migrated' ) ) return;
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;

		// Verificar si hay meta de YITH
		global $wpdb;
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ywpo_preorder' AND meta_value = 'yes'"
		);

		if ( $count <= 0 ) return;

		echo '<div class="notice notice-info is-dismissible"><p>';
		echo '<strong>Akibara Reservas:</strong> Se detectaron ' . esc_html( $count ) . ' productos con datos de YITH Pre-Order. ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=akb-reservas&migrate=yith' ) ) . '">Migrar ahora</a>';
		echo '</p></div>';
	}
}
