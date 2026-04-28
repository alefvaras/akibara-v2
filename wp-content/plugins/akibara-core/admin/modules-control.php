<?php
/**
 * Akibara Core — Módulo de Control (Rank Math style toggles dashboard).
 *
 * Sprint 5.5+ — usuario explícito: "implementa un dashboard administrativo que
 * permita activar y desactivar funcionalidades del plugin mediante toggles.
 * Debe ser modular, escalable y persistente en la base de datos."
 *
 * Lee de option `akibara_module_{slug}_enabled` (default 1).
 * Hook akb_is_module_enabled() lee esta misma option (mu-plugin).
 *
 * @package Akibara\Core\Admin
 * @since   1.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry: todos los modules conocidos agrupados por plugin/feature.
 *
 * NOTA: Cada módulo aquí DEBE tener un guard `akb_is_module_enabled()` en
 * su archivo módule.php. Lista actualizada Sprint 5.5+.
 */
function akibara_core_modules_registry(): array {
	return array(
		'core'        => array(
			'label'   => __( 'Akibara Core', 'akibara' ),
			'icon'    => '🎛️',
			'modules' => array(
				array( 'slug' => 'search',                'label' => 'Búsqueda Akibara',           'desc' => 'Motor búsqueda multi-campo + FULLTEXT.', 'critical' => true ),
				array( 'slug' => 'order-reorder',         'label' => 'Ordenar por Tomo',           'desc' => 'Reordena productos por número de tomo manga.' ),
				array( 'slug' => 'installments',          'label' => 'Cuotas sin Interés',         'desc' => 'Badge informativo de cuotas MP en producto.' ),
				array( 'slug' => 'series-autofill',       'label' => 'Auto-Series',                 'desc' => 'Auto-completa atributo serie desde título.' ),
				array( 'slug' => 'rut',                   'label' => 'Validación RUT Chile',        'desc' => 'Campo RUT en checkout + validación dígito verificador.', 'critical' => true ),
				array( 'slug' => 'phone',                 'label' => 'Validación Teléfono Chile',   'desc' => 'Campo +569 + validación 9 dígitos.', 'critical' => true ),
				array( 'slug' => 'product-badges',        'label' => 'Badges de Producto',          'desc' => 'Preventa / Agotado / Disponible badges.' ),
				array( 'slug' => 'checkout-validation',   'label' => 'Validación Checkout',         'desc' => 'Nombres + dirección + typo email.' ),
				array( 'slug' => 'health-check',          'label' => 'Health Check Endpoint',       'desc' => '/wp-json/akibara/v1/health para monitoring.' ),
				array( 'slug' => 'address-autocomplete',  'label' => 'Autocompletar Dirección',     'desc' => 'Google Maps autocomplete checkout.' ),
				array( 'slug' => 'customer-edit-address', 'label' => 'Editar Dirección',            'desc' => 'Cliente edita dirección post-checkout.' ),
				array( 'slug' => 'email-safety',          'label' => 'Email Safety Mode',           'desc' => 'Modo testing redirige emails a admin.', 'critical' => true ),
			),
		),
		'preventas'   => array(
			'label'   => __( 'Preventas', 'akibara' ),
			'icon'    => '🎌',
			'modules' => array(
				array( 'slug' => 'reservas',           'label' => 'Reservas',              'desc' => 'Sistema de reservas/preventas manga.' ),
				array( 'slug' => 'next-volume',        'label' => 'Siguiente Tomo',        'desc' => 'Widget producto: notifica próximo volumen.' ),
				array( 'slug' => 'editorial-notify',   'label' => 'Notif. Editoriales',    'desc' => 'Notifica clientes de nueva editorial publicada.' ),
				array( 'slug' => 'encargos',           'label' => 'Encargos Especiales',   'desc' => 'Form pedidos out-of-stock customers.' ),
			),
		),
		'marketing'   => array(
			'label'   => __( 'Marketing', 'akibara' ),
			'icon'    => '📣',
			'modules' => array(
				array( 'slug' => 'descuentos',          'label' => 'Descuentos',                'desc' => 'Sistema descuentos por taxonomía + tramos.' ),
				array( 'slug' => 'descuentos-tramos',   'label' => 'Descuentos por Tramos',     'desc' => 'Quantity discounts (compra 2+ obtén X%).' ),
				array( 'slug' => 'banner',              'label' => 'Topbar Banner',             'desc' => 'Mensajes rotatorios en top del sitio.' ),
				array( 'slug' => 'popup',               'label' => 'Popup Bienvenida',          'desc' => 'Modal email capture nuevos visitantes.' ),
				array( 'slug' => 'brevo',               'label' => 'Brevo Sync',                'desc' => 'Sincronización clientes con Brevo lists.' ),
				array( 'slug' => 'review-request',      'label' => 'Review Request',            'desc' => 'Email automático post-compra solicitar review.' ),
				array( 'slug' => 'review-incentive',    'label' => 'Review Incentive',          'desc' => 'Cupón al dejar review.' ),
				array( 'slug' => 'referrals',           'label' => 'Programa Referidos',        'desc' => 'Cupón al referir amigo.' ),
				array( 'slug' => 'marketing-campaigns', 'label' => 'Campañas Marketing',        'desc' => 'Sistema email campaigns con templates.' ),
				array( 'slug' => 'finance-dashboard',   'label' => 'Finance Dashboard Manga',   'desc' => '5 widgets KPI manga-specific.' ),
				array( 'slug' => 'cart-abandoned',      'label' => 'Carrito Abandonado',        'desc' => 'Recovery emails (Brevo upstream).' ),
				array( 'slug' => 'customer-milestones', 'label' => 'Customer Milestones',       'desc' => 'Cumpleaños + aniversario emails.' ),
				array( 'slug' => 'welcome-discount',    'label' => 'Welcome Discount',          'desc' => 'Cupón bienvenida primera compra.' ),
			),
		),
		'inventario'  => array(
			'label'   => __( 'Inventario', 'akibara' ),
			'icon'    => '📦',
			'modules' => array(
				array( 'slug' => 'inventory',     'label' => 'Inventario Manager',  'desc' => 'Gestión stock + bulk operations.' ),
				array( 'slug' => 'shipping',      'label' => '12hrs Shipping',      'desc' => 'Envío express badge.' ),
				array( 'slug' => 'back-in-stock', 'label' => 'Back in Stock',       'desc' => 'Notificaciones cuando vuelve stock.' ),
			),
		),
		'mercadolibre' => array(
			'label'   => __( 'MercadoLibre', 'akibara' ),
			'icon'    => '🛒',
			'modules' => array(
				array( 'slug' => 'mercadolibre', 'label' => 'ML Publisher', 'desc' => 'Publica productos a MercadoLibre Chile.' ),
			),
		),
		'whatsapp'    => array(
			'label'   => __( 'WhatsApp', 'akibara' ),
			'icon'    => '💬',
			'modules' => array(
				array( 'slug' => 'whatsapp', 'label' => 'WhatsApp Float Button', 'desc' => 'Botón flotante WhatsApp + config.' ),
			),
		),
	);
}

/**
 * Registrar página "Módulos" en submenu Akibara.
 */
add_action(
	'admin_menu',
	function (): void {
		add_submenu_page(
			'akibara',
			__( 'Control de Módulos', 'akibara' ),
			'🎛️ Módulos',
			'manage_woocommerce',
			'akibara-modules',
			'akibara_core_modules_render'
		);
	},
	11 // priority 11 — después del dashboard pero antes que addons
);

/**
 * AJAX: toggle module enabled state.
 */
add_action(
	'wp_ajax_akibara_toggle_module',
	function (): void {
		check_ajax_referer( 'akibara_modules_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos', 403 );
		}

		$module  = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$enabled = isset( $_POST['enabled'] ) ? (int) $_POST['enabled'] : 0;

		if ( empty( $module ) ) {
			wp_send_json_error( 'Módulo inválido', 400 );
		}

		// Validar que el módulo existe en el registry.
		$registry      = akibara_core_modules_registry();
		$valid_modules = array();
		foreach ( $registry as $group ) {
			foreach ( $group['modules'] as $mod ) {
				$valid_modules[] = $mod['slug'];
			}
		}
		if ( ! in_array( $module, $valid_modules, true ) ) {
			wp_send_json_error( 'Módulo desconocido: ' . $module, 400 );
		}

		$option_key = 'akibara_module_' . $module . '_enabled';
		update_option( $option_key, $enabled ? 1 : 0, false );

		wp_send_json_success(
			array(
				'module'  => $module,
				'enabled' => (bool) $enabled,
				'message' => $enabled ? 'Módulo activado' : 'Módulo desactivado',
			)
		);
	}
);

/**
 * Render página "Módulos".
 */
function akibara_core_modules_render(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos.' );
	}

	$registry = akibara_core_modules_registry();
	$nonce    = wp_create_nonce( 'akibara_modules_nonce' );

	// Stats: cuántos módulos activos vs total.
	$total_modules   = 0;
	$enabled_modules = 0;
	foreach ( $registry as $group ) {
		foreach ( $group['modules'] as $mod ) {
			$total_modules++;
			$option_key = 'akibara_module_' . $mod['slug'] . '_enabled';
			if ( (int) get_option( $option_key, 1 ) === 1 ) {
				$enabled_modules++;
			}
		}
	}
	$disabled_modules = $total_modules - $enabled_modules;
	?>
	<div class="wrap akb-admin-page akibara-modules-page">
		<div class="akb-page-header">
			<h1 class="akb-page-header__title">🎛️ Control de Módulos</h1>
			<p class="akb-page-header__desc">Activa o desactiva funcionalidades de Akibara. Cambios se aplican inmediatamente en frontend.</p>
		</div>

		<!-- KPIs -->
		<div class="akb-stats">
			<div class="akb-stat">
				<div class="akb-stat__value"><?php echo number_format( $total_modules ); ?></div>
				<div class="akb-stat__label">Total Módulos</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--success"><?php echo number_format( $enabled_modules ); ?></div>
				<div class="akb-stat__label">Activos</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value <?php echo $disabled_modules > 0 ? 'akb-stat__value--warning' : ''; ?>"><?php echo number_format( $disabled_modules ); ?></div>
				<div class="akb-stat__label">Desactivados</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--info"><?php echo count( $registry ); ?></div>
				<div class="akb-stat__label">Grupos</div>
			</div>
		</div>

		<!-- Notice info -->
		<div class="akb-notice akb-notice--info">
			<p>
				<strong>💡 Tip:</strong> Módulos marcados con <span class="akb-badge akb-badge--error">crítico</span> impactan
				directamente compras o pagos — desactivar solo si sabes lo que estás haciendo. Cambios usan el helper
				<code>akb_is_module_enabled()</code> compartido por todos los plugins.
			</p>
		</div>

		<!-- Groups -->
		<?php foreach ( $registry as $group_key => $group ) : ?>
			<div class="akb-card akb-card--section akb-modules-group" data-group="<?php echo esc_attr( $group_key ); ?>">
				<h2 class="akb-section-title">
					<span class="akb-modules-group__icon" aria-hidden="true"><?php echo esc_html( $group['icon'] ); ?></span>
					<?php echo esc_html( $group['label'] ); ?>
				</h2>

				<div class="akb-modules-grid">
					<?php foreach ( $group['modules'] as $mod ) :
						$option_key = 'akibara_module_' . $mod['slug'] . '_enabled';
						$enabled    = (int) get_option( $option_key, 1 ) === 1;
						$is_critical = ! empty( $mod['critical'] );
						?>
						<div class="akb-module-row <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>"
							data-module="<?php echo esc_attr( $mod['slug'] ); ?>">
							<div class="akb-module-row__info">
								<div class="akb-module-row__title">
									<?php echo esc_html( $mod['label'] ); ?>
									<?php if ( $is_critical ) : ?>
										<span class="akb-badge akb-badge--error" title="Módulo crítico — desactivar puede romper checkout/pagos">crítico</span>
									<?php endif; ?>
								</div>
								<div class="akb-module-row__desc"><?php echo esc_html( $mod['desc'] ); ?></div>
								<div class="akb-module-row__slug"><code><?php echo esc_html( $mod['slug'] ); ?></code></div>
							</div>
							<div class="akb-module-row__toggle">
								<label class="akb-toggle">
									<input type="checkbox"
										class="akb-toggle__input"
										data-module="<?php echo esc_attr( $mod['slug'] ); ?>"
										<?php checked( $enabled ); ?>
										<?php echo $is_critical ? 'data-critical="1"' : ''; ?>>
									<span class="akb-toggle__slider" aria-hidden="true"></span>
									<span class="akb-toggle__label"><?php echo $enabled ? 'Activo' : 'Inactivo'; ?></span>
								</label>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>

		<!-- Hidden nonce + ajax url for JS -->
		<script type="text/javascript">
			window.akibaraModules = {
				ajaxUrl: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
				nonce: '<?php echo esc_js( $nonce ); ?>',
				strings: {
					enabled: 'Activo',
					disabled: 'Inactivo',
					confirmCritical: '⚠️ Este es un módulo crítico. Desactivar puede romper checkout o pagos. ¿Continuar?',
					errorSave: 'Error al guardar. Intenta de nuevo.',
					savedOn: '✓ Activado',
					savedOff: '✓ Desactivado',
				}
			};
		</script>
	</div>
	<?php
}

/**
 * Enqueue JS toggle handler solo en la página de módulos.
 */
add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		if ( 'akibara_page_akibara-modules' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'akibara-modules-control',
			plugins_url( 'admin/modules-control.js', AKIBARA_CORE_FILE ),
			array(),
			defined( 'AKIBARA_CORE_VERSION' ) ? AKIBARA_CORE_VERSION : '1.2.0',
			true
		);
	}
);
