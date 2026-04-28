<?php
defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function (): void {
		// Visible bajo Akibara menu — Sprint 5.5+ admin reorg.
		// Slug `akibara-ml-auth` mantenido para backward-compat con OAuth redirect_uri.
		add_submenu_page(
			'akibara',
			'MercadoLibre — Configuración',
			'🛒 MercadoLibre',
			'manage_woocommerce',
			'akibara-ml-auth',
			'akb_ml_render_admin_page'
		);
	}
);

/**
 * Render página configuración MercadoLibre.
 *
 * Muestra estado de conexión OAuth + acciones (connect/disconnect/refresh) +
 * info seller si conectado + counts de listings activos.
 */
function akb_ml_render_admin_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos.' );
	}

	$client_id     = akb_ml_opt( 'client_id' );
	$client_secret = akb_ml_opt( 'client_secret' );
	$access_token  = akb_ml_opt( 'access_token' );
	$refresh_token = akb_ml_opt( 'refresh_token' );
	$is_configured = ! empty( $client_id ) && ! empty( $client_secret );
	$is_connected  = ! empty( $access_token );
	$redirect_uri  = admin_url( 'admin.php?page=akibara-ml-auth' );
	$connect_url   = add_query_arg( array( 'page' => 'akibara-ml-auth', 'action' => 'start' ), admin_url( 'admin.php' ) );

	// Counts de listings desde tabla wp_akb_ml_listings si existe.
	global $wpdb;
	$table = $wpdb->prefix . 'akb_ml_listings';
	$listings_total  = 0;
	$listings_active = 0;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table es nombre constante.
		$listings_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table es nombre constante.
		$listings_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" );
	}

	// Mensaje OAuth (si lo hay).
	$oauth_msg = get_transient( 'akb_ml_oauth_msg' );
	delete_transient( 'akb_ml_oauth_msg' );
	?>
	<div class="wrap akb-admin-page akb-ml-settings">
		<div class="akb-page-header">
			<h1 class="akb-page-header__title">🛒 MercadoLibre Chile</h1>
			<p class="akb-page-header__desc">Publica productos a MercadoLibre + sincroniza órdenes + responde preguntas.</p>
		</div>

		<?php if ( $oauth_msg ) :
			[ $type, $text ] = explode( ':', $oauth_msg, 2 );
			?>
			<div class="akb-notice akb-notice--<?php echo esc_attr( $type === 'success' ? 'success' : 'error' ); ?>">
				<p><?php echo esc_html( $text ); ?></p>
			</div>
		<?php endif; ?>

		<!-- KPIs -->
		<div class="akb-stats">
			<div class="akb-stat">
				<div class="akb-stat__value <?php echo $is_connected ? 'akb-stat__value--success' : 'akb-stat__value--error'; ?>">
					<?php echo $is_connected ? '✅' : '⚠️'; ?>
				</div>
				<div class="akb-stat__label">Estado OAuth</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--info"><?php echo number_format( $listings_total ); ?></div>
				<div class="akb-stat__label">Listings Total</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--success"><?php echo number_format( $listings_active ); ?></div>
				<div class="akb-stat__label">Listings Activos</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value <?php echo $is_configured ? 'akb-stat__value--success' : 'akb-stat__value--warning'; ?>">
					<?php echo $is_configured ? 'OK' : 'Falta'; ?>
				</div>
				<div class="akb-stat__label">App ID Config</div>
			</div>
		</div>

		<!-- Connection Card -->
		<div class="akb-card akb-card--section">
			<h2 class="akb-section-title">🔐 Conexión OAuth</h2>

			<?php if ( ! $is_configured ) : ?>
				<div class="akb-notice akb-notice--warning">
					<p>
						<strong>Configurá App ID + Secret antes de conectar.</strong>
						Crea una app en
						<a href="https://developers.mercadolibre.cl/devcenter" target="_blank" rel="noopener">developers.mercadolibre.cl</a>
						y completa los campos de abajo. Redirect URI:
						<code class="akb-ml-code-inline"><?php echo esc_url( $redirect_uri ); ?></code>
					</p>
				</div>
			<?php elseif ( $is_connected ) : ?>
				<div class="akb-notice akb-notice--success">
					<p>
						<strong>✅ Conectado a MercadoLibre.</strong>
						Token activo. Refresh token: <code><?php echo $refresh_token ? '✓ presente' : '✗ falta'; ?></code>
					</p>
				</div>
				<p>
					<a class="akb-btn akb-btn--primary" href="<?php echo esc_url( $connect_url ); ?>">
						🔄 Reconectar (renovar tokens)
					</a>
					<a class="akb-btn akb-btn--danger" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'akb_ml_disconnect', '1' ), 'akb_ml_disconnect' ) ); ?>"
						onclick="return confirm('¿Desconectar de MercadoLibre? Los listings dejarán de sincronizarse.');">
						❌ Desconectar
					</a>
				</p>
			<?php else : ?>
				<div class="akb-notice akb-notice--info">
					<p>App configurada pero sin token. Conectá tu cuenta MercadoLibre:</p>
				</div>
				<p>
					<a class="akb-btn akb-btn--primary akb-btn--lg" href="<?php echo esc_url( $connect_url ); ?>">
						🔗 Conectar con MercadoLibre
					</a>
				</p>
			<?php endif; ?>
		</div>

		<!-- App Credentials Card -->
		<div class="akb-card akb-card--section">
			<h2 class="akb-section-title">⚙️ App Credentials</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=akibara-ml-auth' ) ); ?>">
				<?php wp_nonce_field( 'akb_ml_save_creds' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="akb-ml-client-id">App ID</label></th>
						<td>
							<input type="text" id="akb-ml-client-id" name="akb_ml_client_id" class="regular-text"
								value="<?php echo esc_attr( $client_id ); ?>" placeholder="123456789">
						</td>
					</tr>
					<tr>
						<th><label for="akb-ml-client-secret">App Secret</label></th>
						<td>
							<input type="password" id="akb-ml-client-secret" name="akb_ml_client_secret" class="regular-text"
								value="<?php echo esc_attr( $client_secret ); ?>" placeholder="••••••••••••••••">
							<p class="description">No mostrar — guardado encriptado en options.</p>
						</td>
					</tr>
					<tr>
						<th>Redirect URI</th>
						<td>
							<code class="akb-ml-code-inline"><?php echo esc_url( $redirect_uri ); ?></code>
							<p class="description">Copiar este URL exacto en la config de tu App ML.</p>
						</td>
					</tr>
				</table>
				<button type="submit" class="akb-btn akb-btn--primary">Guardar Credentials</button>
			</form>
		</div>

		<!-- Quick Links -->
		<div class="akb-card akb-card--section">
			<h2 class="akb-section-title">🔗 Quick Links</h2>
			<ul style="margin:0;padding-left:20px;line-height:1.8">
				<li><a href="https://www.mercadolibre.cl/perfil" target="_blank" rel="noopener">Mi perfil MercadoLibre</a></li>
				<li><a href="https://www.mercadolibre.cl/ventas" target="_blank" rel="noopener">Mis ventas</a></li>
				<li><a href="https://developers.mercadolibre.cl/devcenter" target="_blank" rel="noopener">Developer Center (App management)</a></li>
				<li><a href="https://api.mercadolibre.com/sites/MLC/categories" target="_blank" rel="noopener">Categorías MLC (API)</a></li>
			</ul>
		</div>
	</div>
	<style>
		.akb-ml-code-inline {
			display: inline-block;
			background: #f0f0f1;
			padding: 4px 8px;
			border-radius: 3px;
			font-family: monospace;
			font-size: 12px;
			color: #1d2327;
		}
	</style>
	<?php
}

/**
 * Handler save credentials + disconnect actions.
 */
add_action(
	'admin_init',
	static function (): void {
		// Save credentials.
		if ( isset( $_POST['akb_ml_client_id'], $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'akb_ml_save_creds' )
			&& current_user_can( 'manage_woocommerce' )
		) {
			akb_ml_save_opts(
				array(
					'client_id'     => sanitize_text_field( wp_unslash( $_POST['akb_ml_client_id'] ) ),
					'client_secret' => sanitize_text_field( wp_unslash( $_POST['akb_ml_client_secret'] ?? '' ) ),
				)
			);
			set_transient( 'akb_ml_oauth_msg', 'success:Credentials guardadas. Ahora conecta tu cuenta ML.', 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=akibara-ml-auth' ) );
			exit;
		}

		// Disconnect.
		if ( isset( $_GET['akb_ml_disconnect'], $_GET['_wpnonce'] )
			&& $_GET['akb_ml_disconnect'] === '1'
			&& wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'akb_ml_disconnect' )
			&& current_user_can( 'manage_woocommerce' )
		) {
			akb_ml_save_opts(
				array(
					'access_token'  => '',
					'refresh_token' => '',
				)
			);
			delete_transient( 'akb_ml_seller_id' );
			set_transient( 'akb_ml_oauth_msg', 'success:Desconectado de MercadoLibre.', 60 );
			wp_safe_redirect( admin_url( 'admin.php?page=akibara-ml-auth' ) );
			exit;
		}
	},
	5 // antes que OAuth handler (priority 10 default)
);

// Migración DB en hook 'init' (no 'admin_init') — admin_init NO dispara en
// REST (webhooks ML) ni en Action Scheduler (jobs async), y el primer webhook
// podía llegar antes de que un admin visitara el panel → INSERT fallaba.
add_action(
	'init',
	static function (): void {
		if ( get_option( 'akb_ml_db_version', '0' ) !== AKB_ML_DB_VER ) {
			akb_ml_create_table();
			akb_ml_migrate_db();
			update_option( 'akb_ml_db_version', AKB_ML_DB_VER );
		}
	},
	5
);

add_action(
	'admin_init',
	static function (): void {
		// ── OAuth Start: ?page=akibara-ml-auth&action=start → genera PKCE y redirige a ML ──
		if ( isset( $_GET['page'], $_GET['action'] ) && $_GET['page'] === 'akibara-ml-auth' && $_GET['action'] === 'start' ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Sin permisos' );
			}

			$client_id    = akb_ml_opt( 'client_id' );
			$redirect_uri = admin_url( 'admin.php?page=akibara-ml-auth' );

			// PKCE: generar code_verifier (43-128 chars) y code_challenge (S256)
			$verifier  = rtrim( strtr( base64_encode( random_bytes( 64 ) ), '+/', '-_' ), '=' );
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			$state     = wp_generate_password( 32, false );
			set_transient( 'akb_ml_pkce_verifier', $verifier, 600 );
			set_transient( 'akb_ml_oauth_state', $state, 600 );

			$auth_url = add_query_arg(
				array(
					'response_type'         => 'code',
					'client_id'             => $client_id,
					'redirect_uri'          => $redirect_uri,
					'code_challenge'        => $challenge,
					'code_challenge_method' => 'S256',
					'state'                 => $state,
				),
				'https://auth.mercadolibre.cl/authorization'
			);

			wp_redirect( $auth_url );
			exit;
		}

		// ── OAuth Callback: ?page=akibara-ml-auth&code=xxx → intercambia code por tokens ──
		if ( isset( $_GET['page'], $_GET['code'] ) && $_GET['page'] === 'akibara-ml-auth' ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Sin permisos' );
			}

			// Validar state contra CSRF: ML devuelve el mismo state que enviamos en /authorization.
			$expected_state = get_transient( 'akb_ml_oauth_state' );
			$got_state      = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			delete_transient( 'akb_ml_oauth_state' );
			if ( ! $expected_state || ! hash_equals( (string) $expected_state, $got_state ) ) {
				set_transient( 'akb_ml_oauth_msg', 'error:Verificación OAuth state falló (posible CSRF). Reintentá la conexión.', 60 );
				wp_safe_redirect( admin_url( 'admin.php?page=akibara&tab=mercadolibre' ) );
				exit;
			}

			$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
			$client_id     = akb_ml_opt( 'client_id' );
			$client_secret = akb_ml_opt( 'client_secret' );
			$redirect_uri  = admin_url( 'admin.php?page=akibara-ml-auth' );
			$verifier      = get_transient( 'akb_ml_pkce_verifier' );
			delete_transient( 'akb_ml_pkce_verifier' );

			$body = array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
			);
			if ( $verifier ) {
				$body['code_verifier'] = $verifier;
			}

			$resp = wp_remote_post(
				AKB_ML_API_URL . '/oauth/token',
				array(
					'timeout' => 20,
					'headers' => array(
						'accept'       => 'application/json',
						'content-type' => 'application/x-www-form-urlencoded',
					),
					'body'    => $body,
				)
			);

			if ( ! is_wp_error( $resp ) ) {
				$data = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( ! empty( $data['access_token'] ) ) {
					akb_ml_save_opts(
						array(
							'access_token'  => $data['access_token'],
							'refresh_token' => $data['refresh_token'] ?? '',
						)
					);
					delete_transient( 'akb_ml_seller_id' );
					set_transient( 'akb_ml_oauth_msg', 'success:¡Cuenta vinculada con MercadoLibre correctamente!', 60 );
				} else {
					$msg = $data['message'] ?? ( $data['error'] ?? 'Código inválido o expirado' );
					set_transient( 'akb_ml_oauth_msg', 'error:' . $msg, 60 );
				}
			} else {
				set_transient( 'akb_ml_oauth_msg', 'error:Error de red: ' . $resp->get_error_message(), 60 );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=akibara&tab=mercadolibre' ) );
			exit;
		}
	}
);

// ── Mostrar ID de orden ML en el panel WC ─────────────────────────────────
add_action(
	'woocommerce_admin_order_data_after_order_details',
	static function ( WC_Order $order ): void {
		$ml_id = $order->get_meta( '_akb_ml_order_id' );
		if ( ! $ml_id ) {
			return;
		}
		$nickname = $order->get_meta( '_akb_ml_buyer_nickname' );
		$url      = 'https://www.mercadolibre.cl/ventas/' . $ml_id . '/detalle';
		echo '<p class="form-field form-field-wide">'
		. '<strong>🛒 MercadoLibre:</strong> '
		. '<a href="' . esc_url( $url ) . '" target="_blank">#' . esc_html( $ml_id ) . '</a>'
		. ( $nickname ? ' — <em>' . esc_html( $nickname ) . '</em>' : '' )
		. '</p>';
	}
);

// ══════════════════════════════════════════════════════════════════
// AJAX — Preguntas ML
// ══════════════════════════════════════════════════════════════════

// 7 endpoints akb_ml_* comparten capability 'manage_woocommerce' + nonce 'akb_ml_nonce'
// vía akb_ajax_endpoint().
akb_ajax_endpoint(
	'akb_ml_get_questions',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			$seller_id = akb_ml_get_seller_id();
			if ( ! $seller_id ) {
				wp_send_json_error( array( 'message' => 'No se pudo obtener el Seller ID. Verifica el token.' ) );
			}

			// Nonce/capability ya validados por akb_ajax_endpoint().
			$status = sanitize_text_field( $post['status'] ?? 'UNANSWERED' );
			$limit  = min( 50, max( 1, (int) ( $post['limit'] ?? 25 ) ) );

			$resp = akb_ml_request( 'GET', "/questions/search?seller_id={$seller_id}&status={$status}&limit={$limit}&sort_fields=date_created&sort_types=DESC" );

			if ( isset( $resp['error'] ) ) {
				wp_send_json_error( array( 'message' => $resp['message'] ?? 'Error al obtener preguntas' ) );
			}

			$questions = $resp['questions'] ?? array();

			// Obtener títulos de items en lote (máx 20 IDs únicos)
			$item_ids = array_unique( array_column( $questions, 'item_id' ) );
			$titles   = array();
			if ( ! empty( $item_ids ) ) {
				$ids_str = implode( ',', array_slice( $item_ids, 0, 20 ) );
				$items   = akb_ml_request( 'GET', "/items?ids={$ids_str}&attributes=id,title" );
				if ( is_array( $items ) ) {
					foreach ( $items as $it ) {
						if ( isset( $it['body']['id'] ) ) {
							$titles[ $it['body']['id'] ] = $it['body']['title'] ?? $it['body']['id'];
						}
					}
				}
			}

			// Normalizar respuesta
			$out = array();
			foreach ( $questions as $q ) {
				$out[] = array(
					'id'          => $q['id'],
					'text'        => $q['text'],
					'status'      => $q['status'],
					'date'        => substr( $q['date_created'] ?? '', 0, 16 ),
					'buyer'       => $q['from']['nickname'] ?? 'Comprador',
					'item_id'     => $q['item_id'],
					'item_title'  => $titles[ $q['item_id'] ] ?? $q['item_id'],
					'answer_text' => $q['answer']['text'] ?? null,
					'answer_date' => isset( $q['answer']['date_created'] ) ? substr( $q['answer']['date_created'], 0, 16 ) : null,
				);
			}

			wp_send_json_success(
				array(
					'questions' => $out,
					'total'     => $resp['total'] ?? count( $out ),
					'status'    => $status,
				)
			);
		},
	)
);

akb_ajax_endpoint(
	'akb_ml_answer_question',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			// Nonce/capability ya validados por akb_ajax_endpoint().
			$question_id = (int) ( $post['question_id'] ?? 0 );
			$text        = sanitize_textarea_field( $post['text'] ?? '' );

			if ( ! $question_id || strlen( trim( $text ) ) < 2 ) {
				wp_send_json_error( array( 'message' => 'Datos incompletos' ) );
			}

			$resp = akb_ml_request(
				'POST',
				'/answers',
				array(
					'question_id' => $question_id,
					'text'        => $text,
				)
			);

			if ( isset( $resp['error'] ) ) {
				wp_send_json_error( array( 'message' => $resp['message'] ?? 'Error al responder' ) );
			}

			wp_send_json_success( array( 'message' => 'Respuesta enviada correctamente' ) );
		},
	)
);

// Dashboard: stats de ventas ML (usa órdenes WC con meta _akb_ml_order_id)
akb_ajax_endpoint(
	'akb_ml_get_stats',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			global $wpdb;
			$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_ml_items WHERE ml_status='active'" );
			$errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}akb_ml_items WHERE ml_status='error'" );

			// Órdenes ML este mes desde WC (no requiere llamada ML API)
			$month_start = gmdate( 'Y-m-01' );
			$ml_orders   = wc_get_orders(
				array(
					'meta_key'   => '_akb_ml_order_id',
					'date_after' => $month_start,
					'limit'      => 500,
					'status'     => array( 'wc-processing', 'wc-completed' ),
				)
			);
			$order_count = count( $ml_orders );
			$revenue     = 0.0;
			foreach ( $ml_orders as $o ) {
				$revenue += (float) $o->get_total();
			}

			wp_send_json_success(
				array(
					'active'        => $active,
					'errors'        => $errors,
					'orders_month'  => $order_count,
					'revenue_month' => $revenue,
				)
			);
		},
	)
);

// Opciones de filtros: editoriales únicas + series top 60
akb_ajax_endpoint(
	'akb_ml_get_filter_options',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			$editorial_terms = get_terms(
				array(
					'taxonomy'   => 'product_brand',
					'hide_empty' => true,
					'orderby'    => 'name',
					'order'      => 'ASC',
				)
			);
			$editoriales     = ( ! is_wp_error( $editorial_terms ) ) ? wp_list_pluck( $editorial_terms, 'name' ) : array();

			$series_terms = get_terms(
				array(
					'taxonomy'   => 'pa_serie',
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 60,
					'hide_empty' => true,
				)
			);
			$series       = array();
			if ( ! is_wp_error( $series_terms ) ) {
				foreach ( $series_terms as $t ) {
					$series[] = array(
						'id'   => $t->term_id,
						'name' => $t->name,
					);
				}
			}

			wp_send_json_success(
				array(
					'editoriales' => $editoriales,
					'series'      => $series,
				)
			);
		},
	)
);

akb_ajax_endpoint(
	'akb_ml_save_settings',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			// Nonce/capability ya validados por akb_ajax_endpoint().
			$to_save = array(
				'listing_type'           => sanitize_text_field( $post['listing_type'] ?? 'gold_special' ),
				'commission_pct'         => max( 0.0, min( 60.0, (float) ( $post['commission_pct'] ?? 13 ) ) ),
				'extra_margin_pct'       => max( 0.0, min( 60.0, (float) ( $post['extra_margin_pct'] ?? 3 ) ) ),
				'shipping_cost_estimate' => max( 0, (int) ( $post['shipping_cost_estimate'] ?? 0 ) ),
				'price_rounding'         => in_array( $post['price_rounding'] ?? 'none', array( 'none', '990', '900' ), true ) ? sanitize_text_field( $post['price_rounding'] ) : 'none',
				'default_category'       => sanitize_text_field( $post['default_category'] ?? 'MLC174679' ),
				'auto_sync_stock'        => ! empty( $post['auto_sync_stock'] ),
				'auto_publish_available' => ! empty( $post['auto_publish_available'] ),
				'disabled'               => ! empty( $post['disabled'] ),
			);

			// Credenciales: solo guardar si vienen rellenas para evitar borrar accidentalmente
			// tokens válidos (el campo access_token es readonly en el formulario).
			$client_id     = sanitize_text_field( $post['client_id'] ?? '' );
			$client_secret = sanitize_text_field( $post['client_secret'] ?? '' );
			$access_token  = sanitize_text_field( $post['access_token'] ?? '' );
			if ( $client_id !== '' ) {
				$to_save['client_id'] = $client_id;
			}
			if ( $client_secret !== '' ) {
				$to_save['client_secret'] = $client_secret;
			}
			if ( $access_token !== '' ) {
				$to_save['access_token'] = $access_token;
			}

			akb_ml_save_opts( $to_save );
			delete_transient( 'akb_ml_seller_id' );
			wp_send_json_success( array( 'message' => 'Configuración guardada' ) );
		},
	)
);

akb_ajax_endpoint(
	'akb_ml_test_connection',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			$resp = akb_ml_request( 'GET', '/users/me' );
			if ( isset( $resp['error'] ) ) {
				wp_send_json_error( array( 'message' => $resp['error'] ) );
			}
			wp_send_json_success(
				array(
					'nickname'  => $resp['nickname'] ?? '',
					'email'     => $resp['email'] ?? '',
					'seller_id' => $resp['id'] ?? '',
					'site_id'   => $resp['site_id'] ?? '',
					'country'   => $resp['country_id'] ?? '',
				)
			);
		},
	)
);

// ══════════════════════════════════════════════════════════════════
// ADMIN TAB — vía filtro akibara_admin_tabs
// ══════════════════════════════════════════════════════════════════

add_filter(
	'akibara_admin_tabs',
	static function ( array $tabs ): array {
		$tabs['mercadolibre'] = array(
			'label'       => 'MercadoLibre',
			'short_label' => 'MercadoLibre',
			'icon'        => 'dashicons-cart',
			'group'       => 'ventas',
			'callback'    => 'akb_ml_admin_tab',
		);
		return $tabs;
	}
);

// ── Enqueue condicional de CSS/JS del admin panel ML (DT-10 + DT-11) ──
// Solo se encolan cuando estamos en admin.php?page=akibara&tab=mercadolibre.
// Esto permite cacheo HTTP, minificación LiteSpeed y debugging con source maps.
add_action(
	'admin_enqueue_scripts',
	static function (): void {
		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}
		if ( $_GET['page'] !== 'akibara' || $_GET['tab'] !== 'mercadolibre' ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// AKB_ML_URL is defined in the plugin entry file pointing to plugin root.
		$base_url = defined( 'AKB_ML_URL' ) ? AKB_ML_URL : plugin_dir_url( __DIR__ );
		$version  = defined( 'AKB_ML_LOADED' ) ? AKB_ML_LOADED : ( defined( 'AKIBARA_ML_LOADED' ) ? AKIBARA_ML_LOADED : '1.0.0' );

		wp_enqueue_style(
			'akibara-ml-admin',
			$base_url . 'assets/admin.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'akibara-ml-admin',
			$base_url . 'assets/admin.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'akibara-ml-admin',
			'AkibaraMlData',
			array(
				'nonce'   => wp_create_nonce( 'akb_ml_nonce' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}
);

// ── Price override per product ──
akb_ajax_endpoint(
	'akb_ml_set_price_override',
	array(
		'nonce'      => 'akb_ml_nonce',
		'capability' => 'manage_woocommerce',
		'handler'    => static function ( array $post ): void {
			// Nonce/capability ya validados por akb_ajax_endpoint().
			$product_id = (int) ( $post['product_id'] ?? 0 );
			$override   = max( 0, (int) ( $post['override'] ?? 0 ) );

			if ( $product_id <= 0 ) {
				wp_send_json_error( array( 'message' => 'ID inválido' ) );
			}

			// Usar el helper atómico (INSERT ON DUPLICATE KEY UPDATE) para evitar race
			// conditions entre múltiples admins editando overrides simultáneamente.
			akb_ml_db_upsert( $product_id, array( 'ml_price_override' => $override ) );

			// Recalculate
			$product  = wc_get_product( $product_id );
			$wc_price = $product ? (float) $product->get_price() : 0;
			$new_calc = $wc_price > 0 ? akb_ml_calculate_price( $wc_price, $override ) : 0;

			wp_send_json_success(
				array(
					'message'  => $override > 0 ? 'Precio fijado: $' . number_format( $override, 0, ',', '.' ) : 'Override removido (vuelve a fórmula)',
					'ml_calc'  => $new_calc,
					'override' => $override,
				)
			);
		},
	)
);

function akb_ml_admin_tab(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$nonce         = wp_create_nonce( 'akb_ml_nonce' );
	$token         = akb_ml_opt( 'access_token' );
	$client_id     = akb_ml_opt( 'client_id' );
	$client_secret = akb_ml_opt( 'client_secret' );
	$seller_cached = (int) get_transient( 'akb_ml_seller_id' );
	$token_status  = empty( $token ) ? 'missing' : ( ! $seller_cached ? 'unverified' : 'ok' );

	$oauth_msg = get_transient( 'akb_ml_oauth_msg' );
	if ( $oauth_msg ) {
		delete_transient( 'akb_ml_oauth_msg' );
		[ $oauth_type, $oauth_text ] = explode( ':', $oauth_msg, 2 );
		$oauth_class                 = $oauth_type === 'success' ? 'akb-notice--success' : 'akb-notice--error';
		echo '<div class="akb-notice akb-ml-notice-gap ' . esc_attr( $oauth_class ) . '">' . esc_html( $oauth_text ) . '</div>';
	}
	$listing      = akb_ml_opt( 'listing_type', 'gold_special' );
	$commission   = (float) akb_ml_opt( 'commission_pct', 13.0 );
	$extra        = (float) akb_ml_opt( 'extra_margin_pct', 3.0 );
	$shipping_est = (int) akb_ml_opt( 'shipping_cost_estimate', 0 );
	$price_round  = akb_ml_opt( 'price_rounding', 'none' );
	$category     = akb_ml_opt( 'default_category', 'MLC1196' );
	$auto_stock   = (bool) akb_ml_opt( 'auto_sync_stock', true );
	$auto_pub     = (bool) akb_ml_opt( 'auto_publish_available', false );
	$disabled     = (bool) akb_ml_opt( 'disabled', false );
	$webhook_url  = get_rest_url( null, 'akibara/v1/ml/notify' );
	$total_pct    = $commission + $extra;
	// Ejemplo bajo umbral: WC=$10.000 NO cruza $19.990 → no absorbe envío
	$example_low = $total_pct < 100 ? akb_ml_apply_psychological_rounding( (int) ceil( 10000 / ( 1 - $total_pct / 100 ) ) ) : 0;
	// Ejemplo sobre umbral: WC=$20.000 cruza $19.990 → absorbe envío
	$example_high = $total_pct < 100 ? akb_ml_apply_psychological_rounding( (int) ceil( ( 20000 + $shipping_est ) / ( 1 - $total_pct / 100 ) ) ) : 0;

	// Estadísticas rápidas
	global $wpdb;
	$stats = $wpdb->get_row(
		"SELECT
            COUNT(*) as total,
            COALESCE(SUM(ml_status='active'),0) as active,
            COALESCE(SUM(ml_status='paused'),0) as paused,
            COALESCE(SUM(ml_status='error'),0)  as errors
         FROM {$wpdb->prefix}akb_ml_items",
		ARRAY_A
	) ?: array(
		'total'  => 0,
		'active' => 0,
		'paused' => 0,
		'errors' => 0,
	);
	?>
	<?php // CSS movido a assets/admin.css — se encola via admin_enqueue_scripts (DT-10) ?>

	<?php if ( $token_status === 'missing' ) : ?>
	<div class="akb-notice akb-notice--warning akb-ml-notice-gap">⚠️ <strong>Token no configurado.</strong> Agrega tu Access Token en Configuración API para empezar a usar el módulo.</div>
	<?php elseif ( $token_status === 'unverified' ) : ?>
	<div class="akb-notice akb-notice--info akb-ml-notice-gap">🔑 Token configurado pero no verificado. Haz clic en <strong>Probar conexión</strong> para validarlo — si expiró, deberás renovarlo en <a href="https://developers.mercadolibre.cl" target="_blank">developers.mercadolibre.cl</a>.</div>
	<?php endif; ?>

	<div class="akb-page-header">
		<h2 class="akb-page-header__title">MercadoLibre Sync</h2>
		<p class="akb-page-header__desc">Sincronización bidireccional WooCommerce ↔ MercadoLibre Chile (MLC). Stock en tiempo real, precios con markup de comisión automático.</p>
	</div>

	<div class="akb-stats">
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--brand"><?php echo (int) $stats['active']; ?></div>
			<div class="akb-stat__label">Publicados activos</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo (int) $stats['paused']; ?></div>
			<div class="akb-stat__label">Pausados</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--error"><?php echo (int) $stats['errors']; ?></div>
			<div class="akb-stat__label">Errores</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo number_format( $total_pct, 1 ); ?>%</div>
			<div class="akb-stat__label">Markup total</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value" id="akb-ml-stat-orders">–</div>
			<div class="akb-stat__label">Ventas ML (mes)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value" id="akb-ml-stat-revenue">–</div>
			<div class="akb-stat__label">Revenue ML (mes)</div>
		</div>
	</div>

	<div class="akb-ml-layout">

		<!-- CONFIG -->
		<div class="akb-card akb-card--section akb-ml-config">
			<h3 class="akb-section-title">⚙️ Configuración API</h3>

			<div class="akb-ml-grid-2">
				<div class="akb-field">
					<label class="akb-field__label">App ID (Client ID)</label>
					<input type="text" id="akb-ml-client-id" class="akb-field__input"
						value="<?php echo esc_attr( $client_id ); ?>" placeholder="Ej: 5979774958971247">
				</div>
				<div class="akb-field">
					<label class="akb-field__label">Secret Key (Client Secret)</label>
					<input type="password" id="akb-ml-client-secret" class="akb-field__input"
						value="<?php echo esc_attr( $client_secret ); ?>" placeholder="Ej: WYK02...">
				</div>
			</div>

			<div class="akb-field">
				<label class="akb-field__label">Access Token (se renueva automáticamente)</label>
				<div class="akb-ml-token-row">
					<input type="password" id="akb-ml-token" class="akb-field__input akb-ml-token-input"
						value="<?php echo esc_attr( $token ); ?>" placeholder="Automático tras vincular…" readonly>
					<button type="button" id="akb-ml-token-toggle"
						title="Mostrar/ocultar token"
						class="akb-ml-token-toggle">👁</button>
				</div>
				<p class="akb-field__hint">Redirect URI para tu App ML: <code class="akb-ml-code-inline"><?php echo esc_url( admin_url( 'admin.php?page=akibara-ml-auth' ) ); ?></code></p>
			</div>

			<div class="akb-field">
				<label class="akb-field__label">Tipo de publicación</label>
				<select id="akb-ml-listing-type" class="akb-field__input">
					<option value="gold_special" <?php selected( $listing, 'gold_special' ); ?>>Clásica (gold_special) — comisión ~13-15%</option>
					<option value="gold_pro" <?php selected( $listing, 'gold_pro' ); ?>>Premium (gold_pro) — comisión ~15-18%, más visibilidad</option>
					<option value="free" <?php selected( $listing, 'free' ); ?>>Gratuita (free) — sin comisión, baja exposición</option>
				</select>
			</div>

			<div class="akb-ml-grid-3">
				<div class="akb-field">
					<label class="akb-field__label">Comisión ML (%)</label>
					<input type="number" id="akb-ml-commission" class="akb-field__input"
						value="<?php echo esc_attr( $commission ); ?>" min="0" max="60" step="0.5">
					<p class="akb-field__hint">Clásica ≈ 13%, Premium ≈ 15%</p>
				</div>
				<div class="akb-field">
					<label class="akb-field__label">Margen extra (%)</label>
					<input type="number" id="akb-ml-extra" class="akb-field__input"
						value="<?php echo esc_attr( $extra ); ?>" min="0" max="60" step="0.5">
					<p class="akb-field__hint">Colchón adicional de ganancia</p>
				</div>
				<div class="akb-field">
					<label class="akb-field__label">Costo envío estimado ($)</label>
					<input type="number" id="akb-ml-shipping" class="akb-field__input"
						value="<?php echo esc_attr( $shipping_est ); ?>" min="0" max="20000" step="100">
					<p class="akb-field__hint">Se absorbe <strong>solo</strong> en productos ≥$19.990 (envío gratis obligatorio). Manga ~300g: <strong>$3.100</strong> recomendado.</p>
					<?php if ( (int) $shipping_est === 0 ) : ?>
						<div class="akb-notice akb-notice--warning" style="margin-top:6px;padding:8px 10px;font-size:12px;">
							🚨 <strong>Costo envío en $0</strong> — en productos ≥$19.990 CLP ML descontará el envío de tu pago y <strong>perderás ~$3.050 por venta</strong>. Sugerido: <strong>$3.100 CLP</strong> (manga 300g).
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="akb-field">
				<label class="akb-field__label">Redondeo psicológico del precio ML</label>
				<select id="akb-ml-price-rounding" class="akb-field__input">
					<option value="none" <?php selected( $price_round, 'none' ); ?>>Ninguno — precio exacto de la fórmula (ej: $11.905)</option>
					<option value="990"  <?php selected( $price_round, '990' ); ?>>Redondear a .990 — conversión LATAM óptima (ej: $11.905 → $11.990)</option>
					<option value="900"  <?php selected( $price_round, '900' ); ?>>Redondear a .900 (ej: $11.905 → $11.900)</option>
				</select>
				<p class="akb-field__hint">Precios terminados en <strong>.990 / .900</strong> convierten 5-8% más en marketplaces LATAM. Siempre redondea hacia arriba (nunca pierdes margen).</p>
			</div>

			<div class="akb-notice akb-notice--info akb-ml-formula-note">
				<strong>Fórmula:</strong> Precio ML = ⌈ (Precio WC [+ Envío si ≥$19.990]) ÷ (1 − <span id="akb-ml-total-pct"><?php echo esc_html( (string) $total_pct ); ?></span>%) ⌉<br>
				<small>📉 <strong>Bajo umbral:</strong> $10.000 WC → <strong>$<span id="akb-ml-example-low"><?php echo number_format( $example_low, 0, ',', '.' ); ?></span></strong> CLP (sin envío)</small><br>
				<small>📦 <strong>Sobre umbral:</strong> $20.000 WC + $<span id="akb-ml-ship-preview"><?php echo number_format( $shipping_est, 0, ',', '.' ); ?></span> envío → <strong>$<span id="akb-ml-example-high"><?php echo number_format( $example_high, 0, ',', '.' ); ?></span></strong> CLP (absorbe envío gratis obligatorio)</small><br>
				<small class="akb-ml-formula-tip">💡 Puedes fijar un precio manual por producto haciendo clic en el precio ML calculado de la tabla.</small>
			</div>

			<div class="akb-field">
				<label class="akb-field__label">Categoría ML por defecto (ID)</label>
				<input type="text" id="akb-ml-category" class="akb-field__input"
					value="<?php echo esc_attr( $category ); ?>" placeholder="MLC1196">
				<p class="akb-field__hint">
					<strong>MLC1196</strong> = Libros Físicos (manga, comics, novelas gráficas).
					Verifica en <a href="https://api.mercadolibre.com/categories/MLC1196" target="_blank">API ML</a>
				</p>
			</div>

			<div class="akb-field">
				<label class="akb-field__label">
					<input type="checkbox" id="akb-ml-auto-stock" <?php checked( $auto_stock ); ?>>
					Sincronizar stock automáticamente al cambiar en WC
				</label>
			</div>
			<div class="akb-field">
				<label class="akb-field__label">
					<input type="checkbox" id="akb-ml-auto-pub" <?php checked( $auto_pub ); ?>>
					Auto-publicar cuando llega stock (preventas → disponible)
				</label>
				<p class="akb-field__hint">Cuando una preventa pasa a <em>instock</em> en WooCommerce (stock &gt; 0), se publica automáticamente en MercadoLibre.</p>
			</div>

			<div class="akb-field akb-ml-kill-switch" style="border-top:1px solid #2A2A2E; padding-top:12px; margin-top:12px;">
				<label class="akb-field__label" style="color:<?php echo $disabled ? '#D90010' : 'inherit'; ?>;">
					<input type="checkbox" id="akb-ml-disabled" <?php checked( $disabled ); ?>>
					<strong>🚨 Deshabilitar integración ML (kill-switch)</strong>
				</label>
				<p class="akb-field__hint">Bloquea <em>todas</em> las llamadas al API ML (publish, sync, webhooks). Útil si el módulo empieza a fallar o para mantenimiento. Los cron jobs siguen registrados pero retornan error inmediato sin tocar ML.</p>
			</div>

			<div class="akb-ml-actions">
				<button class="akb-btn akb-btn--primary" id="akb-ml-save-btn">Guardar configuración</button>
				<?php if ( $client_id && $client_secret ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=akibara-ml-auth&action=start' ) ); ?>"
					class="akb-btn akb-btn--secondary akb-ml-link-btn">
					🔗 Vincular con MercadoLibre
				</a>
				<?php endif; ?>
				<button class="akb-btn akb-btn--secondary" id="akb-ml-test-btn">🔌 Probar conexión</button>
			</div>
			<div id="akb-ml-test-result" class="akb-ml-test-result"></div>
		</div>

		<!-- WEBHOOK + INFO -->
		<div class="akb-ml-side">
			<div class="akb-card akb-card--section">
				<h3 class="akb-section-title">🔔 Webhook para órdenes ML</h3>
				<p class="akb-field__hint">Configura esta URL en tu app ML para recibir notificaciones de pedidos en tiempo real:</p>
				<div class="akb-ml-webhook-box">
					<?php echo esc_html( $webhook_url ); ?>
				</div>
				<p class="akb-field__hint akb-ml-webhook-help">
					1. <a href="https://www.mercadolibre.cl/developers/panel/app" target="_blank">Panel ML → tu app → Notificaciones</a><br>
					2. Pega la URL y activa el tópico <code>orders_v2</code>
				</p>
			</div>

			<div class="akb-notice akb-notice--info">
				<strong>📋 Reglas ML Chile (MLC)</strong><br>
				• Precio mínimo: <strong>$1.100 CLP</strong><br>
				• Envío gratis obligatorio: productos ≥ <strong>$19.990 CLP</strong><br>
				• Comisión Clásica (Libros MLC1196): <strong>13%</strong><br>
				• Comisión Premium (Libros): <strong>~16%</strong><br>
				• Costo envío manga (~300g): <strong>$2.500-$3.500</strong> con reputación verde<br>
				• El margen extra + envío estimado cubre IVA comisión + envío gratis
			</div>
		</div>
	</div>

	<!-- PRODUCTOS TABLE -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">📦 Productos del catálogo</h3>

		<div class="akb-ml-filters">
		<div class="akb-ml-filters__left">
			<input type="text" id="akb-ml-search" class="akb-field__input akb-ml-input-search"
				placeholder="Buscar producto...">
			<select id="akb-ml-editorial" class="akb-field__input akb-ml-input-editorial">
				<option value="">Todas las editoriales</option>
			</select>
			<select id="akb-ml-serie" class="akb-field__input akb-ml-input-serie">
				<option value="">Todas las series</option>
			</select>
			<select id="akb-ml-filter" class="akb-field__input akb-ml-input-filter">
				<option value="all">Todos los productos</option>
				<option value="available" selected>✅ Con stock disponible (no publicados)</option>
				<option value="published">Publicados en ML</option>
				<option value="not_published">No publicados</option>
				<option value="error">Con error</option>
			</select>
			<button class="akb-btn akb-btn--secondary" id="akb-ml-load-btn">🔍 Buscar</button>
			<button class="akb-btn akb-btn--secondary" id="akb-ml-select-all-btn">☑ Sel. todos</button>
			<button class="akb-btn akb-btn--primary akb-ml-btn-bulk" id="akb-ml-bulk-btn">📤 Publicar seleccionados</button>
			<button class="akb-btn akb-btn--primary akb-ml-btn-publish-all" id="akb-ml-publish-available-btn">🚀 Publicar TODOS con stock</button>
		</div>
		<div class="akb-ml-filters__right">
			<span id="akb-ml-results-info" class="akb-ml-results-info"></span>
			<select id="akb-ml-per-page" class="akb-field__input akb-ml-per-page">
				<option value="25">25 / pág</option>
				<option value="50">50 / pág</option>
				<option value="100">100 / pág</option>
			</select>
		</div>
		</div>

		<div class="akb-ml-table-wrap">
			<table class="akb-ml-table">
				<thead>
					<tr>
						<th><input type="checkbox" id="akb-ml-chk-all"></th>
						<th>Producto</th>
						<th>Stock WC</th>
						<th>Precio WC</th>
						<th>Precio ML calculado</th>
						<th>Estado ML</th>
						<th>Sincronizado</th>
						<th>Acciones</th>
					</tr>
				</thead>
				<tbody id="akb-ml-tbody">
					<tr><td colspan="8" class="akb-ml-table-empty">Cargando productos...</td></tr>
				</tbody>
			</table>
		</div>

		<div id="akb-ml-pagination" class="akb-ml-pagination"></div>
	</div>

	<!-- PREGUNTAS ML -->
	<div class="akb-card akb-card--section akb-ml-card-gap">
		<div class="akb-ml-q-head">
			<h3 class="akb-section-title akb-ml-q-title">
				💬 Preguntas de compradores
				<span id="akb-ml-q-badge" class="akb-ml-q-badge"></span>
			</h3>
			<div class="akb-ml-q-actions">
				<select id="akb-ml-q-filter" class="akb-field__input akb-ml-q-filter">
					<option value="UNANSWERED">Sin responder</option>
					<option value="ANSWERED">Respondidas</option>
					<option value="CLOSED_UNANSWERED">Cerradas sin resp.</option>
				</select>
				<button class="akb-btn akb-btn--secondary" id="akb-ml-q-refresh">↻ Actualizar</button>
			</div>
		</div>

		<div id="akb-ml-q-list">
			<div class="akb-ml-q-placeholder">Haz clic en Actualizar para cargar preguntas.</div>
		</div>

		<div id="akb-ml-q-empty" class="akb-ml-q-empty">
			✅ No hay preguntas sin responder.
		</div>
	</div>

	<?php // JS movido a assets/admin.js — se encola via admin_enqueue_scripts (DT-11) ?>
	<?php
}
