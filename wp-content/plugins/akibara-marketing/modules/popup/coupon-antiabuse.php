<?php
/**
 * Akibara – Protección Anti-Abuso Cupón PRIMERACOMPRA10 (PRO)
 *
 * Valida múltiples vectores para prevenir uso fraudulento del cupón
 * de primera compra: usuario, email, RUT, teléfono, dirección, IP,
 * cookie fingerprint.
 *
 * @package    Akibara
 * @subpackage Popup
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

// ══════════════════════════════════════════════════════════════════
// CONSTANTES
// ══════════════════════════════════════════════════════════════════
define( 'AKB_FIRST_COUPON_CODE', 'PRIMERACOMPRA10' );
define( 'AKB_COUPON_COOKIE_NAME', 'akb_fp' );
define( 'AKB_COUPON_COOKIE_DAYS', 365 );
define( 'AKB_COUPON_IP_MAX_MONTHLY', 5 );

// ══════════════════════════════════════════════════════════════════
// SET FINGERPRINT COOKIE ON CART/CHECKOUT/ACCOUNT PAGES ONLY
// ══════════════════════════════════════════════════════════════════
// Historial: antes se seteaba en `init` para cualquier pageview. Eso
// ponía Set-Cookie en cada respuesta HTML, impidiendo que Cloudflare
// cacheara home/catálogo/productos al edge (ver ADR 009 §Seguimiento).
//
// Cambio 2026-04-20: solo seteamos la cookie cuando el usuario llega
// a cart/checkout/my-account — los únicos lugares donde el cupón
// PRIMERACOMPRA10 puede aplicarse. La fortaleza anti-abuso no cambia:
// cualquier abusador que haya creado una orden tendrá el fingerprint
// guardado en `_akb_device_fingerprint`, y su segunda visita a /cart/
// o /checkout/ le asigna (o reutiliza) la cookie que lo matchea.
// Escenario perdido: usuario que navega sin nunca entrar a cart — pero
// ese usuario no puede crear orden, así que no puede abusar.
add_action(
	'init',
	function (): void {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}

		$path = $_SERVER['REQUEST_URI'] ?? '';
		// Match cualquier URL que contenga cart|carrito|checkout|my-account|mi-cuenta
		// (usamos path raw para evitar depender de conditionals WC que no existen en init).
		$is_coupon_touchpoint = (
		strpos( $path, '/cart' ) !== false ||
		strpos( $path, '/carrito' ) !== false ||
		strpos( $path, '/checkout' ) !== false ||
		strpos( $path, '/my-account' ) !== false ||
		strpos( $path, '/mi-cuenta' ) !== false
		);
		if ( ! $is_coupon_touchpoint ) {
			return;
		}

		if ( empty( $_COOKIE[ AKB_COUPON_COOKIE_NAME ] ) ) {
			$fingerprint = wp_generate_password( 32, false );
			setcookie(
				AKB_COUPON_COOKIE_NAME,
				$fingerprint,
				time() + ( AKB_COUPON_COOKIE_DAYS * DAY_IN_SECONDS ),
				'/',
				'',
				is_ssl(),
				true // httponly
			);
			$_COOKIE[ AKB_COUPON_COOKIE_NAME ] = $fingerprint;
		}
	}
);

// ══════════════════════════════════════════════════════════════════
// VALIDACIÓN ANTI-ABUSO
// ══════════════════════════════════════════════════════════════════
remove_filter( 'woocommerce_coupon_is_valid', 'akibara_cro_validate_first_purchase_coupon', 10 );
add_filter( 'woocommerce_coupon_is_valid', 'akibara_coupon_antiabuse', 10, 3 );

function akibara_coupon_antiabuse( bool $valid, WC_Coupon $coupon, WC_Discounts $discounts ): bool {
	if ( strtoupper( $coupon->get_code() ) !== AKB_FIRST_COUPON_CODE ) {
		return $valid;
	}

	// ── Check 1: Usuario logueado con órdenes ──
	if ( is_user_logged_in() ) {
		$user_id = get_current_user_id();
		$orders  = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'completed', 'processing' ),
				'limit'       => 1,
			)
		);
		if ( ! empty( $orders ) ) {
			throw new Exception( 'Este cupón es solo para tu primera compra.' );
		}
	}

	// ── Check 2: Email con órdenes previas ──
	$billing_email = '';
	if ( WC()->checkout ) {
		$billing_email = WC()->checkout->get_value( 'billing_email' );
	}
	if ( empty( $billing_email ) && is_user_logged_in() ) {
		$billing_email = wp_get_current_user()->user_email;
	}

	if ( ! empty( $billing_email ) ) {
		$orders = wc_get_orders(
			array(
				'billing_email' => $billing_email,
				'status'        => array( 'completed', 'processing' ),
				'limit'         => 1,
			)
		);
		if ( ! empty( $orders ) ) {
			throw new Exception( 'Este cupón es solo para primera compra. Tu email ya tiene pedidos.' );
		}
	}

	// ── Check 3: Cupón ya usado con este email ──
	if ( ! empty( $billing_email ) ) {
		$used = wc_get_orders(
			array(
				'billing_email' => $billing_email,
				'status'        => array( 'completed', 'processing', 'on-hold' ),
				'limit'         => 1,
				'coupon'        => AKB_FIRST_COUPON_CODE,
			)
		);
		if ( ! empty( $used ) ) {
			throw new Exception( 'Este cupón ya fue utilizado.' );
		}
	}

	// ── Check 4: RUT obligatorio y ya usado ──
	$billing_rut = '';
	if ( isset( $_POST['billing_rut'] ) ) {
		$billing_rut = sanitize_text_field( $_POST['billing_rut'] );
	} elseif ( is_user_logged_in() ) {
		$billing_rut = get_user_meta( get_current_user_id(), 'billing_rut', true );
	}

	// RUT obligatorio cuando se usa este cupón
	if ( empty( $billing_rut ) || strlen( preg_replace( '/[^0-9kK]/', '', $billing_rut ) ) < 8 ) {
		throw new Exception( 'Debes ingresar tu RUT para usar este cupón de primera compra.' );
	}

	$rut_clean = strtoupper( preg_replace( '/[^0-9kK]/', '', $billing_rut ) );

	global $wpdb;

	// Check RUT in HPOS tables first, fallback to post meta
	$rut_used = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta wom
         INNER JOIN {$wpdb->prefix}wc_orders wo ON wom.order_id = wo.id
         WHERE wom.meta_key = '_billing_rut'
         AND REPLACE(REPLACE(UPPER(wom.meta_value), '.', ''), '-', '') = %s
         AND wo.status IN ('wc-completed', 'wc-processing')
         LIMIT 1",
			$rut_clean
		)
	);

	if ( $rut_used === null ) {
		$rut_used = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = '_billing_rut'
             AND REPLACE(REPLACE(UPPER(pm.meta_value), '.', ''), '-', '') = %s
             AND p.post_type = 'shop_order'
             AND p.post_status IN ('wc-completed', 'wc-processing')
             LIMIT 1",
				$rut_clean
			)
		);
	}

	if ( (int) $rut_used > 0 ) {
		throw new Exception( 'Este cupón es solo para primera compra. Tu RUT ya tiene pedidos anteriores.' );
	}

	// ── Check 5: Teléfono ya usado con el cupón ──
	$billing_phone = '';
	if ( WC()->checkout ) {
		$billing_phone = WC()->checkout->get_value( 'billing_phone' );
	}
	if ( empty( $billing_phone ) && is_user_logged_in() ) {
		$billing_phone = get_user_meta( get_current_user_id(), 'billing_phone', true );
	}

	if ( ! empty( $billing_phone ) ) {
		// Normalize: keep only digits, last 8
		$phone_clean = preg_replace( '/[^0-9]/', '', $billing_phone );
		$phone_clean = substr( $phone_clean, -8 );

		if ( strlen( $phone_clean ) >= 8 ) {
			// Fix 2026-04-22: WC HPOS no expone billing_phone en wc_orders — vive en
			// wc_order_addresses con address_type='billing'. El query previo buscaba
			// `wo.billing_phone` (columna inexistente) y tiraba "Unknown column" SQL
			// error, fallando silenciosamente el Check 5.
			$phone_used = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders wo
                 INNER JOIN {$wpdb->prefix}wc_orders_meta wom ON wo.id = wom.order_id
                 INNER JOIN {$wpdb->prefix}wc_order_addresses woa ON wo.id = woa.order_id AND woa.address_type = 'billing'
                 WHERE wom.meta_key = '_akb_used_first_coupon'
                 AND wom.meta_value = '1'
                 AND wo.status IN ('wc-completed', 'wc-processing')
                 AND woa.phone LIKE %s
                 LIMIT 1",
					'%' . $wpdb->esc_like( $phone_clean )
				)
			);

			if ( (int) $phone_used > 0 ) {
				throw new Exception( 'Este cupón ya fue utilizado con este número de teléfono.' );
			}
		}
	}

	// ── Check 6: Dirección hash ──
	$billing_address = '';
	if ( WC()->checkout ) {
		$billing_address = strtolower(
			trim(
				WC()->checkout->get_value( 'billing_address_1' ) . ' ' .
				WC()->checkout->get_value( 'billing_city' )
			)
		);
	}

	if ( strlen( $billing_address ) > 10 ) {
		$address_hash = md5( $billing_address );
		$address_used = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta wom
             INNER JOIN {$wpdb->prefix}wc_orders wo ON wom.order_id = wo.id
             INNER JOIN {$wpdb->prefix}wc_orders_meta wom2 ON wo.id = wom2.order_id
             WHERE wom.meta_key = '_akb_address_hash'
             AND wom.meta_value = %s
             AND wom2.meta_key = '_akb_used_first_coupon'
             AND wom2.meta_value = '1'
             AND wo.status IN ('wc-completed', 'wc-processing')
             LIMIT 1",
				$address_hash
			)
		);
		if ( (int) $address_used > 0 ) {
			throw new Exception( 'Este cupón ya fue utilizado en esta dirección.' );
		}
	}

	// ── Check 7: Cookie fingerprint ──
	$fingerprint = $_COOKIE[ AKB_COUPON_COOKIE_NAME ] ?? '';
	if ( ! empty( $fingerprint ) ) {
		$fp_used = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta wom
             INNER JOIN {$wpdb->prefix}wc_orders wo ON wom.order_id = wo.id
             WHERE wom.meta_key = '_akb_device_fingerprint'
             AND wom.meta_value = %s
             AND wo.status IN ('wc-completed', 'wc-processing')
             LIMIT 1",
				$fingerprint
			)
		);
		if ( (int) $fp_used > 0 ) {
			throw new Exception( 'Este cupón ya fue utilizado desde este dispositivo.' );
		}
	}

	// ── Check 9: Expiración 72h desde suscripción popup ──
	// El welcome-series E3 dice "tu cupón expira en 24h" pero WC nunca lo expiraba.
	// Ahora sí: si el email se suscribió hace más de 72h, rechazar con mensaje claro.
	// Si no hay registro de suscripción (cupón creado manual por admin), permitir —
	// los otros checks siguen validando.
	if ( ! empty( $billing_email ) ) {
		$subs_ts = get_option( 'akb_popup_subs_ts', array() );
		if ( is_array( $subs_ts ) && isset( $subs_ts[ strtolower( $billing_email ) ] ) ) {
			$subscribed_at = (int) $subs_ts[ strtolower( $billing_email ) ];
			$age_hours     = ( time() - $subscribed_at ) / HOUR_IN_SECONDS;
			if ( $age_hours > 72 ) {
				throw new Exception( 'Tu cupón de bienvenida expiró. Es válido 72 horas desde que te suscribiste. Escríbenos si necesitas ayuda.' );
			}
		}
	}

	// ── Check 8: Rate limit por IP (mensual) ──
	$ip = akb_get_client_ip();
	if ( ! empty( $ip ) ) {
		$rate_key = 'akb_coupon_rate_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= AKB_COUPON_IP_MAX_MONTHLY ) {
			throw new Exception( 'Demasiados intentos con este cupón. Intenta más tarde.' );
		}
	}

	return $valid;
}

// ══════════════════════════════════════════════════════════════════
// GUARDAR DATOS ANTI-ABUSO AL CREAR ORDEN
// ══════════════════════════════════════════════════════════════════
add_action( 'woocommerce_checkout_order_created', 'akibara_save_coupon_abuse_data' );

function akibara_save_coupon_abuse_data( $order ): void {
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$coupons = $order->get_coupon_codes();
	if ( ! in_array( 'primeracompra10', array_map( 'strtolower', $coupons ), true ) ) {
		return;
	}

	// Flag de uso
	$order->update_meta_data( '_akb_used_first_coupon', '1' );

	// Address hash
	$address = strtolower(
		trim(
			$order->get_billing_address_1() . ' ' . $order->get_billing_city()
		)
	);
	if ( strlen( $address ) > 10 ) {
		$order->update_meta_data( '_akb_address_hash', md5( $address ) );
	}

	// Device fingerprint
	$fingerprint = $_COOKIE[ AKB_COUPON_COOKIE_NAME ] ?? '';
	if ( ! empty( $fingerprint ) ) {
		$order->update_meta_data( '_akb_device_fingerprint', $fingerprint );
	}

	// Phone normalized
	$phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );
	$phone = substr( $phone, -8 );
	if ( strlen( $phone ) >= 8 ) {
		$order->update_meta_data( '_akb_phone_hash', md5( $phone ) );
	}

	$order->save();

	// IP rate limit (30 days)
	$ip = akb_get_client_ip();
	if ( ! empty( $ip ) ) {
		$rate_key = 'akb_coupon_rate_' . md5( $ip );
		$attempts = (int) get_transient( $rate_key );
		set_transient( $rate_key, $attempts + 1, 30 * DAY_IN_SECONDS );
	}
}

// ══════════════════════════════════════════════════════════════════
// MÉTRICAS — stacking attempts + success de cupones de bienvenida.
// Instrumentación para decidir con data en 60 días si permitir stacking
// entre PRIMERACOMPRA10 y REFREFERIDO-XXXXX (hoy bloqueado por
// individual_use=true). Ver mesa de agentes 2026-04-22.
// ══════════════════════════════════════════════════════════════════

/**
 * Detecta si un código es de los cupones de bienvenida a medir.
 */
function akb_is_welcome_coupon_code( string $code ): bool {
	$code = strtoupper( $code );
	if ( $code === AKB_FIRST_COUPON_CODE ) {
		return true;
	}
	if ( strpos( $code, 'REFREFERIDO-' ) === 0 ) {
		return true;
	}
	return false;
}

/**
 * Registra un evento de cupón en el contador diario.
 * Stored en option `akb_coupon_metrics` = [ 'YYYY-MM-DD' => [ evento => count ] ].
 * GC: mantiene últimos 90 días.
 */
function akb_coupon_metrics_log( string $event ): void {
	$metrics = get_option( 'akb_coupon_metrics', array() );
	if ( ! is_array( $metrics ) ) {
		$metrics = array();
	}

	$today = wp_date( 'Y-m-d' );
	if ( ! isset( $metrics[ $today ] ) ) {
		$metrics[ $today ] = array();
	}
	$metrics[ $today ][ $event ] = ( $metrics[ $today ][ $event ] ?? 0 ) + 1;

	// GC: eliminar entradas más viejas que 90 días.
	$cutoff = wp_date( 'Y-m-d', strtotime( '-90 days' ) );
	foreach ( array_keys( $metrics ) as $date ) {
		if ( $date < $cutoff ) {
			unset( $metrics[ $date ] );
		}
	}

	update_option( 'akb_coupon_metrics', $metrics, false );
}

/**
 * Hook al APLICAR un cupón: detecta si es stacking attempt.
 * Stacking attempt = cliente ya tiene otro cupón de bienvenida aplicado
 * y ahora intenta agregar un segundo cupón de bienvenida.
 * Dado individual_use=true, WC lo bloqueará — pero YA aplicamos el primero,
 * así que aquí sólo detectamos "el segundo" antes que WC lo rechace.
 */
add_action(
	'woocommerce_applied_coupon',
	function ( string $applied_code ): void {
		if ( ! akb_is_welcome_coupon_code( $applied_code ) ) {
			return;
		}
		akb_coupon_metrics_log( 'welcome_applied' );

		if ( ! WC()->cart ) {
			return;
		}
		$existing       = WC()->cart->get_applied_coupons();
		$others_welcome = array_filter(
			$existing,
			static function ( $c ) use ( $applied_code ) {
				return strtoupper( $c ) !== strtoupper( $applied_code ) && akb_is_welcome_coupon_code( $c );
			}
		);
		if ( ! empty( $others_welcome ) ) {
			akb_coupon_metrics_log( 'stacking_attempt' );
		}
	}
);

/**
 * Hook al RECHAZAR un cupón por individual_use: detecta intento de stacking fallido.
 * WC dispara woocommerce_coupon_error con code=110 cuando individual_use impide stack.
 */
// IMPORTANTE: woocommerce_coupon_error es un FILTER, no action — debe retornar $err
// intacto para no pisar el mensaje. Antes usábamos add_action con : void lo que
// devolvía null implícitamente, rompiendo el filter UX de abajo que transforma
// el mensaje default en uno accionable.
add_filter(
	'woocommerce_coupon_error',
	function ( $err, $err_code = 0, $coupon = null ) {
		if ( ! ( $coupon instanceof WC_Coupon ) ) {
			return $err;
		}
		if ( ! akb_is_welcome_coupon_code( $coupon->get_code() ) ) {
			return $err;
		}
		// Códigos de error WC: 110 = individual_use blocked by other coupon
		// 111 = this coupon requires individual_use
		if ( in_array( (int) $err_code, array( 110, 111 ), true ) ) {
			akb_coupon_metrics_log( 'stacking_blocked' );
		}
		return $err;
	},
	10,
	3
);

/**
 * UX: mensaje custom cuando WC rechaza un cupón de bienvenida por individual_use.
 * Default de WC es "Lo sentimos, este cupón no es válido combinado con otros cupones"
 * — genérico y confuso. Lo reemplazamos con copy accionable que explica qué hacer.
 *
 * Códigos de error WC (woocommerce/includes/class-wc-coupon.php):
 *   E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY = 110
 *   E_WC_COUPON_INDIV_USE_ONLY                  = 111
 */
add_filter(
	'woocommerce_coupon_error',
	function ( $err, $err_code = 0, $coupon = null ) {
		// Defensive: el filtro se puede llamar con shapes raras en WC (ej: excepciones custom
		// lanzadas por validators que ponen $err_code como string o $coupon null).
		if ( ! is_string( $err ) ) {
			return $err;
		}
		if ( ! ( $coupon instanceof WC_Coupon ) ) {
			return $err;
		}
		$err_code_int = is_numeric( $err_code ) ? (int) $err_code : 0;
		if ( ! in_array( $err_code_int, array( 110, 111 ), true ) ) {
			return $err;
		}

		$is_welcome = akb_is_welcome_coupon_code( $coupon->get_code() );
		if ( ! $is_welcome ) {
			return $err;
		}

		// Detectar el otro cupón de bienvenida que está en cart para mejor mensaje
		$other = '';
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_applied_coupons() as $applied ) {
				if ( strtoupper( $applied ) !== strtoupper( $coupon->get_code() ) && akb_is_welcome_coupon_code( $applied ) ) {
					$other = strtoupper( $applied );
					break;
				}
			}
		}

		if ( $other ) {
			return sprintf(
				'Solo puedes usar un cupón de bienvenida por pedido. Ya tienes <strong>%s</strong> aplicado. Si prefieres este otro, quita primero el actual.',
				esc_html( $other )
			);
		}

		return 'Este cupón es individual — no se combina con otros descuentos. Quita los otros cupones del carrito para aplicarlo.';
	},
	20,
	3
);

/**
 * Admin panel de métricas — integra al dashboard Akibara (group `analytics`).
 * Propósito: instrumentación para decidir stacking PRIMERACOMPRA10 + REFREFERIDO-* (revisión 2026-06-21).
 */
add_filter(
	'akibara_admin_tabs',
	function ( array $tabs ): array {
		$tabs['coupon_metrics'] = array(
			'label'       => 'Cupones Bienvenida',
			'short_label' => 'Cupones',
			'icon'        => 'dashicons-tickets-alt',
			'group'       => 'analytics',
			'callback'    => 'akb_coupon_metrics_render_admin',
		);
		return $tabs;
	}
);

add_action(
	'admin_menu',
	function (): void {
		if ( ! defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			add_submenu_page(
				'woocommerce',
				'Métricas Cupones Bienvenida',
				'📊 Cupones Bienvenida',
				'manage_woocommerce',
				'akibara-coupon-metrics',
				'akb_coupon_metrics_render_admin'
			);
		}
	}
);

function akb_coupon_metrics_render_admin(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos' );
	}

	$metrics = get_option( 'akb_coupon_metrics', array() );
	if ( ! is_array( $metrics ) ) {
		$metrics = array();
	}

	$windows = array(
		'7 días'  => 7,
		'30 días' => 30,
		'60 días' => 60,
		'90 días' => 90,
	);
	$stats   = array();
	foreach ( $windows as $label => $days ) {
		$cutoff = wp_date( 'Y-m-d', strtotime( "-{$days} days" ) );
		$agg    = array(
			'welcome_applied'  => 0,
			'stacking_attempt' => 0,
			'stacking_blocked' => 0,
		);
		foreach ( $metrics as $date => $events ) {
			if ( $date < $cutoff ) {
				continue;
			}
			foreach ( $agg as $k => $_ ) {
				$agg[ $k ] += (int) ( $events[ $k ] ?? 0 );
			}
		}
		$stats[ $label ] = $agg;
	}

	// Ventana de decisión (60d) — la clave para levantar/mantener individual_use
	$decision = $stats['60 días'];
	$pct_60   = $decision['welcome_applied'] > 0
		? round( 100 * $decision['stacking_attempt'] / $decision['welcome_applied'], 1 )
		: 0;

	// Color semántico: <10% = ok (mantener bloqueo); 10-15% warning; ≥15% re-evaluar
	$pct_class = $pct_60 >= 15 ? 'akb-stat__value--error'
		: ( $pct_60 >= 10 ? 'akb-stat__value--warning' : 'akb-stat__value--success' );
	?>
	<div class="akb-page-header">
		<h2 class="akb-page-header__title">Métricas Cupones de Bienvenida</h2>
		<p class="akb-page-header__desc">Instrumentación para decidir si permitir stacking entre PRIMERACOMPRA10 y REFREFERIDO-* (revisión 2026-06-21).</p>
	</div>

	<!-- Stats ventana decisión (60d) -->
	<div class="akb-stats">
		<div class="akb-stat">
			<div class="akb-stat__value akb-stat__value--info"><?php echo (int) $decision['welcome_applied']; ?></div>
			<div class="akb-stat__label">Cupones aplicados (60d)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo (int) $decision['stacking_attempt']; ?></div>
			<div class="akb-stat__label">Intentos de stacking (60d)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value"><?php echo (int) $decision['stacking_blocked']; ?></div>
			<div class="akb-stat__label">Stacking bloqueado (60d)</div>
		</div>
		<div class="akb-stat">
			<div class="akb-stat__value <?php echo esc_attr( $pct_class ); ?>"><?php echo esc_html( $pct_60 ); ?>%</div>
			<div class="akb-stat__label">% intento stacking (60d)</div>
		</div>
	</div>

	<!-- Desglose por ventana -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Desglose por ventana</h3>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th>Ventana</th>
					<th>Cupones aplicados</th>
					<th>Intentos de stacking</th>
					<th>Stacking bloqueado</th>
					<th>% stacking attempt</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $stats as $label => $agg ) :
					$pct = $agg['welcome_applied'] > 0
						? round( 100 * $agg['stacking_attempt'] / $agg['welcome_applied'], 1 ) . '%'
						: '—';
					?>
				<tr>
					<td><strong><?php echo esc_html( $label ); ?></strong></td>
					<td><?php echo (int) $agg['welcome_applied']; ?></td>
					<td><?php echo (int) $agg['stacking_attempt']; ?></td>
					<td><?php echo (int) $agg['stacking_blocked']; ?></td>
					<td><?php echo esc_html( $pct ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<!-- Cómo leer -->
	<div class="akb-card akb-card--section">
		<h3 class="akb-section-title">Cómo leer esto</h3>
		<ul style="list-style:disc;margin-left:22px">
			<li><strong>Cupones aplicados</strong>: alguien aplicó PRIMERACOMPRA10 o REFREFERIDO-XXXXX al carrito.</li>
			<li><strong>Intentos de stacking</strong>: cliente ya tenía un cupón de bienvenida aplicado e intentó agregar el otro. Señal de demanda.</li>
			<li><strong>Stacking bloqueado</strong>: WooCommerce rechazó el segundo cupón (individual_use=true). Stacking attempt fallido.</li>
			<li><strong>% stacking attempt</strong>: si supera <strong>15%</strong> en 60 días → demanda real, re-evaluar permitirlo (ver project memory).</li>
		</ul>
	</div>

	<!-- Siguiente revisión -->
	<div class="akb-notice akb-notice--info">
		<strong>Siguiente revisión:</strong> <?php echo esc_html( wp_date( 'Y-m-d', strtotime( '2026-06-21' ) ) ); ?>.
		Si stacking attempts &lt; 10% en 60d → mantener bloqueo. Si ≥ 15% → levantar <code>individual_use</code> y medir impacto en margen por otros 30d.
	</div>
	<?php
}

// ══════════════════════════════════════════════════════════════════
// HELPER: Get client IP (Cloudflare aware)
// ══════════════════════════════════════════════════════════════════
function akb_get_client_ip(): string {
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		return sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$ips = explode( ',', sanitize_text_field( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		return trim( $ips[0] );
	}
	return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
}
