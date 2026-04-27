<?php
/**
 * Akibara Courier — 12 Horas Envíos Adapter
 *
 * Integración con la API REST de 12 Horas Envíos:
 *   - Crear envío desde orden WC (order action)
 *   - Cancelar envío (order action)
 *   - Recibir webhook de cambios de estado (POST /akibara/v1/12horas/webhook)
 *   - Tracking unificado en frontend (via módulo shipping)
 *
 * @package Akibara
 * @since   10.9.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-12horas-client.php';

class AKB_Courier_TwelveHoras implements AKB_Courier_Adapter, AKB_Courier_UI_Metadata {

	use AKB_Courier_UI_Defaults;

	const OPT_API_KEY                    = 'akb_12horas_api_key';
	const OPT_BASE_URL                   = 'akb_12horas_base_url';
	const OPT_DEFAULT_PICKUP_OFFSET_DAYS = 'akb_12horas_pickup_offset_days';
	const META_TRACKING_CODE             = '_12horas_tracking_code';
	const META_STATUS                    = '_12horas_status';
	const META_UPDATED_AT                = '_12horas_updated_at';
	const META_IS_CANCELED               = '_12horas_is_canceled';
	const META_ATTEMPTS                  = '_12horas_attempts';
	const META_EXTERNAL_ID               = '_12horas_external_id';

	// ── UI Metadata ───────────────────────────────────────────────
	public function get_color(): string {
		return '#FF6B00'; }
	public function get_tagline(): string {
		return 'Despacho mismo día en Santiago'; }
	public function get_badge(): ?string {
		return 'LLEGA HOY'; }
	public function get_pill(): ?string {
		return 'SOLO RM'; }
	public function get_priority(): int {
		return 2; }
	public function get_cutoff_hour(): ?int {
		return 14; }
	public function get_coverage_text(): ?string {
		return 'Región Metropolitana — 20 comunas con cobertura'; }
	public function get_icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
	}

	// ── Contract: identity ────────────────────────────────────────
	public function get_id(): string {
		return '12horas'; }
	public function get_label(): string {
		return '12 Horas Envíos'; }
	public function get_icon(): string {
		return '🕛'; }

	public function get_method_ids(): array {
		return array( '12horas', '12horas-express', '12horas-sameday' );
	}

	// ── Client lazy init ──────────────────────────────────────────
	private ?AKB_TwelveHoras_Client $client = null;
	private function client(): AKB_TwelveHoras_Client {
		if ( $this->client === null ) {
			$this->client = new AKB_TwelveHoras_Client(
				(string) get_option( self::OPT_API_KEY, '' ),
				(string) get_option( self::OPT_BASE_URL, 'https://api.12horasenvios.cl/v1' )
			);
		}
		return $this->client;
	}

	// ── Tracking ─────────────────────────────────────────────────
	public function get_tracking_info( WC_Order $order ): ?array {
		$code = (string) $order->get_meta( self::META_TRACKING_CODE );
		if ( $code === '' ) {
			return null;
		}

		// 12 Horas Envíos no expone URL pública de tracking (confirmado vs Franco 2026-04-21).
		// Opciones: (a) mostrar código sin link, (b) construir página interna /seguimiento/.
		// Por ahora (a): null ⇒ renderers deben omitir el enlace externo y mostrar solo código.
		// Los usuarios reciben notificación vía email cuando el webhook actualiza el estado.
		$public_url = apply_filters( 'akb_12horas_public_tracking_url', null, $code, $order );

		return array(
			'courier'       => $this->get_id(),
			'courier_label' => $this->get_label(),
			'code'          => $code,
			'codes'         => array( $code ),
			'url'           => $public_url,
			'data'          => array(
				'status'      => (string) $order->get_meta( self::META_STATUS ),
				'is_canceled' => $order->get_meta( self::META_IS_CANCELED ) === '1',
				'attempts'    => (int) $order->get_meta( self::META_ATTEMPTS ),
				'updated_at'  => (string) $order->get_meta( self::META_UPDATED_AT ),
			),
		);
	}

	public function get_status_display( ?string $courier_status, string $wc_status ): array {
		if ( $wc_status === 'cancelled' ) {
			return array(
				'icon'  => '🚫',
				'label' => 'Envío cancelado',
				'css'   => 'cancelled',
			);
		}
		// Normalizamos: API real devuelve "in-transit" (guión), docs oficial dice "in_transit".
		$key = is_string( $courier_status ) ? str_replace( '-', '_', $courier_status ) : $courier_status;
		switch ( $key ) {
			case 'approved':
				return array(
					'icon'  => '✅',
					'label' => 'Entregado',
					'css'   => 'completed',
				);
			case 'partial':
				return array(
					'icon'  => '📦',
					'label' => 'Entregado (parcial)',
					'css'   => 'completed',
				);
			case 'in_transit':
				return array(
					'icon'  => '🚚',
					'label' => 'En camino',
					'css'   => 'shipped',
				);
			case 'rejected':
				return array(
					'icon'  => '↩️',
					'label' => 'Entrega reagendada',
					'css'   => 'pending',
				);
			case 'cancelled':
			case 'canceled':
				return array(
					'icon'  => '🚫',
					'label' => 'Envío cancelado',
					'css'   => 'cancelled',
				);
			default:
				return array(
					'icon'  => '⏳',
					'label' => 'En preparación',
					'css'   => 'pending',
				);
		}
	}

	// ── Connection / Settings ─────────────────────────────────────
	public function test_connection(): bool {
		$key = (string) get_option( self::OPT_API_KEY, '' );
		if ( $key === '' ) {
			return false;
		}
		$r = $this->client()->test_connection();
		return ! empty( $r['ok'] ) && ! empty( $r['auth_ok'] );
	}

	public function has_admin_settings(): bool {
		return true; }

	public function render_admin_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$api_key       = (string) get_option( self::OPT_API_KEY, '' );
		$base_url      = (string) get_option( self::OPT_BASE_URL, 'https://api.12horasenvios.cl/v1' );
		$pickup_offset = (int) get_option( self::OPT_DEFAULT_PICKUP_OFFSET_DAYS, 1 );
		$webhook_url   = rest_url( 'akibara/v1/12horas/webhook' );
		?>
		<div class="akb-card akb-card--section akb-card--spaced">
			<h3 class="akb-section-title">12 Horas Envíos — Configuración</h3>
			<form method="post" action="">
				<?php wp_nonce_field( 'akb_12horas_save', 'akb_12horas_nonce' ); ?>
				<input type="hidden" name="akb_12horas_action" value="save">

				<p class="akb-field">
					<label for="akb_12horas_api_key">API Key</label>
					<input type="password" id="akb_12horas_api_key" name="akb_12horas_api_key"
							value="<?php echo esc_attr( $api_key ); ?>"
							autocomplete="new-password"
							style="width:100%;max-width:480px;">
					<span class="akb-field__hint">Cabecera <code>X-API-Key</code> entregada por 12 Horas.</span>
				</p>

				<p class="akb-field">
					<label for="akb_12horas_base_url">Base URL</label>
					<input type="url" id="akb_12horas_base_url" name="akb_12horas_base_url"
							value="<?php echo esc_attr( $base_url ); ?>"
							style="width:100%;max-width:480px;">
					<span class="akb-field__hint">Producción: <code>https://api.12horasenvios.cl/v1</code>. Para pruebas, apunta al simulador.</span>
				</p>

				<p class="akb-field">
					<label for="akb_12horas_pickup_offset">Días hasta retiro</label>
					<input type="number" id="akb_12horas_pickup_offset" name="akb_12horas_pickup_offset"
							value="<?php echo esc_attr( (string) $pickup_offset ); ?>"
							min="0" max="7" step="1"
							style="width:80px;">
					<span class="akb-field__hint">Por defecto pickupDate = hoy + N días (0 = mismo día).</span>
				</p>

				<p class="akb-field">
					<label>Webhook URL</label>
					<input type="text" readonly
							value="<?php echo esc_attr( $webhook_url ); ?>"
							onclick="this.select()"
							style="width:100%;max-width:480px;">
					<span class="akb-field__hint">Configura esta URL en el panel de 12 Horas → Integraciones → Webhooks.</span>
				</p>

				<p>
					<button type="submit" class="button button-primary">Guardar</button>
					<button type="submit" name="akb_12horas_action" value="test" class="button" style="margin-left:8px;">
						Probar conexión
					</button>
				</p>
			</form>

			<?php
			// Mostrar resultado de acciones previas (transient de una sola vida)
			$notice = get_transient( 'akb_12horas_notice' );
			if ( $notice ) {
				delete_transient( 'akb_12horas_notice' );
				printf(
					'<div class="notice notice-%s" style="margin-top:12px;"><p>%s</p></div>',
					esc_attr( $notice['type'] ?? 'info' ),
					esc_html( $notice['msg'] ?? '' )
				);
			}
			?>
		</div>
		<?php
	}

	public function save_admin_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['akb_12horas_action'] ) ? sanitize_text_field( wp_unslash( $_POST['akb_12horas_action'] ) ) : '';
		if ( $action === '' ) {
			return;
		}
		if ( ! isset( $_POST['akb_12horas_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['akb_12horas_nonce'] ) ), 'akb_12horas_save' ) ) {
			return;
		}

		if ( isset( $_POST['akb_12horas_api_key'] ) ) {
			update_option( self::OPT_API_KEY, sanitize_text_field( wp_unslash( $_POST['akb_12horas_api_key'] ) ) );
		}
		if ( isset( $_POST['akb_12horas_base_url'] ) ) {
			update_option( self::OPT_BASE_URL, esc_url_raw( wp_unslash( $_POST['akb_12horas_base_url'] ) ) );
		}
		if ( isset( $_POST['akb_12horas_pickup_offset'] ) ) {
			$offset = max( 0, min( 7, (int) $_POST['akb_12horas_pickup_offset'] ) );
			update_option( self::OPT_DEFAULT_PICKUP_OFFSET_DAYS, $offset );
		}
		// Reset client para que tome nuevos valores
		$this->client = null;

		if ( $action === 'test' ) {
			$r = $this->client()->test_connection();
			set_transient(
				'akb_12horas_notice',
				array(
					'type' => $r['ok'] ? 'success' : 'error',
					'msg'  => $r['ok']
						? "Conexión OK (HTTP {$r['status']}, auth válida)"
						: "Falló: HTTP {$r['status']} — {$r['message']}",
				),
				60
			);
		} else {
			set_transient(
				'akb_12horas_notice',
				array(
					'type' => 'success',
					'msg'  => 'Configuración guardada',
				),
				60
			);
		}
	}

	// ── Order Actions ─────────────────────────────────────────────
	public function has_order_actions(): bool {
		return true; }

	public function get_order_actions( WC_Order $order ): array {
		$code     = (string) $order->get_meta( self::META_TRACKING_CODE );
		$canceled = $order->get_meta( self::META_IS_CANCELED ) === '1';

		$actions = array();
		if ( $code === '' ) {
			$actions['akb_12horas_create'] = 'Crear envío en 12 Horas';
		} elseif ( ! $canceled ) {
			$actions['akb_12horas_refresh'] = 'Actualizar estado 12 Horas';
			$actions['akb_12horas_cancel']  = 'Cancelar envío 12 Horas';
		}
		return $actions;
	}

	public function execute_order_action( string $action_slug, WC_Order $order ): void {
		switch ( $action_slug ) {
			case 'akb_12horas_create':
				$this->action_create( $order );
				break;
			case 'akb_12horas_refresh':
				$this->action_refresh( $order );
				break;
			case 'akb_12horas_cancel':
				$this->action_cancel( $order );
				break;
		}
	}

	private function action_create( WC_Order $order ): void {
		if ( $order->get_meta( self::META_TRACKING_CODE ) !== '' ) {
			$order->add_order_note( '12 Horas: ya existe tracking; acción ignorada.' );
			return;
		}
		$payload = $this->build_create_payload( $order );
		$r       = $this->client()->create( $payload );
		if ( ! $r['ok'] ) {
			$path_suffix = ! empty( $r['errorPath'] ) ? ' [campo: ' . implode( ', ', $r['errorPath'] ) . ']' : '';
			$order->add_order_note(
				sprintf(
					'12 Horas: fallo al crear envío — %s (HTTP %d, code=%s)%s',
					$r['error'] ?? '?',
					$r['status'],
					$r['errorCode'] ?? '?',
					$path_suffix
				)
			);
			return;
		}
		$d = $r['data'];
		$order->update_meta_data( self::META_TRACKING_CODE, $d['trackingCode'] );
		$order->update_meta_data( self::META_EXTERNAL_ID, $d['externalId'] );
		$order->update_meta_data( self::META_STATUS, $d['status'] ?? '' );
		$order->update_meta_data( self::META_UPDATED_AT, $d['updatedAt'] ?? '' );
		$order->update_meta_data( self::META_IS_CANCELED, ! empty( $d['isCanceled'] ) ? '1' : '0' );
		$order->update_meta_data( self::META_ATTEMPTS, (int) ( $d['attemptsCount'] ?? 0 ) );
		$order->add_order_note(
			sprintf(
				'12 Horas: envío creado. Tracking: %s (ext=%s, retiro=%s)',
				$d['trackingCode'],
				$d['externalId'],
				$d['pickupDate'] ?? '?'
			)
		);
		$order->save();
	}

	private function action_refresh( WC_Order $order ): void {
		$code = (string) $order->get_meta( self::META_TRACKING_CODE );
		if ( $code === '' ) {
			return; }
		$r = $this->client()->get_by_tracking( $code );
		if ( ! $r['ok'] ) {
			$path_suffix = ! empty( $r['errorPath'] ) ? ' [campo: ' . implode( ', ', $r['errorPath'] ) . ']' : '';
			$order->add_order_note(
				sprintf(
					'12 Horas: fallo al consultar %s — %s (HTTP %d)%s',
					$code,
					$r['error'] ?? '?',
					$r['status'],
					$path_suffix
				)
			);
			return;
		}
		$this->apply_delivery_to_order( $order, $r['data'], false );
		$order->save();
		$order->add_order_note(
			sprintf(
				'12 Horas: estado actualizado desde API → status=%s, canceled=%s',
				$r['data']['status'] ?? 'null',
				! empty( $r['data']['isCanceled'] ) ? 'sí' : 'no'
			)
		);
	}

	private function action_cancel( WC_Order $order ): void {
		$code = (string) $order->get_meta( self::META_TRACKING_CODE );
		if ( $code === '' ) {
			return; }
		$r = $this->client()->cancel( $code );
		if ( ! $r['ok'] ) {
			$path_suffix = ! empty( $r['errorPath'] ) ? ' [campo: ' . implode( ', ', $r['errorPath'] ) . ']' : '';
			$order->add_order_note(
				sprintf(
					'12 Horas: fallo al cancelar %s — %s (HTTP %d, code=%s)%s',
					$code,
					$r['error'] ?? '?',
					$r['status'],
					$r['errorCode'] ?? '?',
					$path_suffix
				)
			);
			return;
		}
		$this->apply_delivery_to_order( $order, $r['data'], true );
		$order->save();
		$order->add_order_note( "12 Horas: envío cancelado ($code)" );
	}

	// ── Payload builder ───────────────────────────────────────────
	public function build_create_payload( WC_Order $order ): array {
		$address = trim(
			implode(
				' ',
				array_filter(
					array(
						$order->get_shipping_address_1(),
						$order->get_shipping_address_2(),
					)
				)
			)
		);
		if ( $address === '' ) {
			$address = trim(
				implode(
					' ',
					array_filter(
						array(
							$order->get_billing_address_1(),
							$order->get_billing_address_2(),
						)
					)
				)
			);
		}
		$commune = $order->get_shipping_city() ?: $order->get_billing_city();

		$offset     = (int) get_option( self::OPT_DEFAULT_PICKUP_OFFSET_DAYS, 1 );
		$pickupDate = gmdate( 'Y-m-d', time() + ( $offset * DAY_IN_SECONDS ) );

		return array(
			'contactName'          => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'contactPhone'         => self::normalize_phone( $order->get_billing_phone() ),
			'contactEmail'         => $order->get_billing_email(),
			'externalId'           => $this->build_external_id( $order ),
			'pickupDate'           => $pickupDate,
			'address'              => $address,
			'commune'              => $commune,
			'packageSize'          => $this->pick_package_size( $order ),
			'packagesCount'        => max( 1, $this->count_package_items( $order ) ),
			// tag: null = sameday | flex | exchange | return (confirmado Franco 12H 2026-04-21)
			'tag'                  => null,
			'shippingObservations' => (string) $order->get_customer_note(),
			'sourceSystem'         => 'akibara',
			'sourceSystemId'       => (string) $order->get_id(),
		);
	}

	/**
	 * 12 Horas exige /^9\d{8}$/ — móvil chileno sin prefijo país.
	 * Acepta "+56 9 1234 5678", "56912345678", "9 1234 5678", "912345678"…
	 * Devuelve "912345678" o el original si no se puede normalizar
	 * (dejamos que el API devuelva el 400 para no ocultar datos malos del checkout).
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( $digits === '' ) {
			return $raw;
		}
		if ( strlen( $digits ) === 11 && str_starts_with( $digits, '569' ) ) {
			return substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 9 && $digits[0] === '9' ) {
			return $digits;
		}
		if ( strlen( $digits ) === 8 ) {
			return '9' . $digits;
		}
		return $raw;
	}

	public function build_external_id( WC_Order $order ): string {
		return 'AKB-ORDER-' . $order->get_id();
	}

	private function pick_package_size( WC_Order $order ): string {
		// Heurística simple: si total_items > 3 → medium, sino small.
		// Se puede mejorar con dimensiones reales.
		return $this->count_package_items( $order ) > 3 ? 'medium' : 'small';
	}

	private function count_package_items( WC_Order $order ): int {
		$n = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$n += (int) $item->get_quantity();
			}
		}
		return max( 1, $n );
	}

	// ── Webhook ───────────────────────────────────────────────────
	const OPT_WEBHOOK_TOKEN = 'akb_12horas_webhook_token';

	public function has_webhook(): bool {
		return true; }
	public function get_webhook_path(): string {
		return '12horas/webhook'; }

	/**
	 * Auth del webhook vía shared secret.
	 *
	 * Acepta el token por header `X-Akibara-Token` o query param `token`.
	 * Compara con `hash_equals` para evitar timing attacks.
	 *
	 * Rollout: si la option existe y no está vacía, se exige match.
	 * Si está vacía (primer deploy), se acepta para no romper webhook
	 * pre-configurado. Admin debe setear la option vía:
	 *   wp option update akb_12horas_webhook_token "$(openssl rand -hex 32)" --autoload=no
	 * y actualizar la URL del webhook en 12 Horas con `?token=XXX`.
	 */
	public function verify_webhook_auth( WP_REST_Request $request ): bool {
		$expected = (string) get_option( self::OPT_WEBHOOK_TOKEN, '' );
		if ( $expected === '' ) {
			// B-S1-SEC-07 (2026-04-27): hard-fail (antes grace mode → bypass abierto).
			// Sin token configurado = misconfig severo. Logging escalado a critical.
			if ( function_exists( 'akb_log' ) ) {
				akb_log(
					'shipping',
					'critical',
					'12horas webhook HARD-FAIL: token no configurado',
					array(
						'endpoint' => '/12horas/webhook',
						'action'   => 'rejected',
					)
				);
			}
			return false;
		}
		$provided = (string) $request->get_header( 'X-Akibara-Token' );
		if ( $provided === '' ) {
			$provided = (string) $request->get_param( 'token' );
		}
		return $provided !== '' && hash_equals( $expected, $provided );
	}

	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'Payload inválido: se esperaba { data: [...] }',
				),
				400
			);
		}

		$results = array();
		foreach ( $body['data'] as $delivery ) {
			if ( ! is_array( $delivery ) ) {
				$results[] = array( 'skipped' => 'Item no es objeto' );
				continue;
			}
			$results[] = $this->process_webhook_delivery( $delivery );
		}

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'processed' => count( $results ),
				'results'   => $results,
			),
			200
		);
	}

	/**
	 * Procesa un delivery recibido vía webhook.
	 * Retorna resumen (para debug) y aplica cambios a la orden WC si la encuentra.
	 */
	private function process_webhook_delivery( array $delivery ): array {
		$tracking = sanitize_text_field( (string) ( $delivery['trackingCode'] ?? '' ) );
		$external = sanitize_text_field( (string) ( $delivery['externalId'] ?? '' ) );

		// Whitelist de status aceptados (evita que el webhook inyecte un status
		// arbitrario que luego pase a map_status_to_wc sin validación).
		$allowed_status = array( 'in_transit', 'in-transit', 'approved', 'partial', 'rejected', 'cancelled', 'canceled' );
		$status_raw     = isset( $delivery['status'] ) && is_string( $delivery['status'] ) ? $delivery['status'] : null;
		if ( $status_raw !== null && $status_raw !== '' && ! in_array( $status_raw, $allowed_status, true ) ) {
			return array(
				'trackingCode' => $tracking,
				'externalId'   => $external,
				'skipped'      => "status inválido: $status_raw",
			);
		}

		$order_id = $this->resolve_order_id( $external, $tracking );
		if ( ! $order_id ) {
			return array(
				'trackingCode' => $tracking,
				'externalId'   => $external,
				'skipped'      => 'Order no encontrada',
			);
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array(
				'trackingCode' => $tracking,
				'externalId'   => $external,
				'skipped'      => "Order $order_id no existe",
			);
		}

		// S2: exigir que la identidad del evento coincida con la orden.
		// Sin este check, un atacante (o un webhook mal configurado) podría
		// pasar un externalId válido + trackingCode ajeno y mutar la orden.
		$stored_tracking = (string) $order->get_meta( self::META_TRACKING_CODE );
		$stored_external = (string) $order->get_meta( self::META_EXTERNAL_ID );
		if ( $tracking !== '' && $stored_tracking !== '' && ! hash_equals( $stored_tracking, $tracking ) ) {
			return array(
				'trackingCode' => $tracking,
				'externalId'   => $external,
				'order_id'     => $order_id,
				'skipped'      => 'trackingCode del payload no coincide con el de la orden',
			);
		}
		if ( $external !== '' && $stored_external !== '' && ! hash_equals( $stored_external, $external ) ) {
			return array(
				'trackingCode' => $tracking,
				'externalId'   => $external,
				'order_id'     => $order_id,
				'skipped'      => 'externalId del payload no coincide con el de la orden',
			);
		}

		// Idempotencia: comparar updatedAt normalizado a timestamp (robusto contra
		// variaciones de formato ISO: con/sin ms, con/sin 'Z').
		$last_raw = (string) $order->get_meta( self::META_UPDATED_AT );
		$curr_raw = (string) ( $delivery['updatedAt'] ?? '' );
		if ( $last_raw !== '' && $curr_raw !== '' ) {
			$last_ts = strtotime( $last_raw );
			$curr_ts = strtotime( $curr_raw );
			if ( $last_ts !== false && $curr_ts !== false && $last_ts === $curr_ts ) {
				return array(
					'trackingCode' => $tracking,
					'order_id'     => $order_id,
					'skipped'      => 'updatedAt sin cambios (idempotente)',
				);
			}
		}

		$canceled = ! empty( $delivery['isCanceled'] );
		$this->apply_delivery_to_order( $order, $delivery, $canceled );

		// Status transitions + notas (single save al final)
		$status = $delivery['status'] ?? null;
		$note   = $this->format_webhook_note( $delivery );
		if ( $note !== '' ) {
			$order->add_order_note( $note );
		}
		$target = $this->map_status_to_wc( $status, $canceled );
		if ( $target && $order->get_status() !== $target ) {
			// update_status guarda internamente, incluyendo los meta ya pendientes.
			$order->update_status( $target, '[12 Horas webhook] ' );
		} else {
			// No hay transición de status → persistir meta acumuladas.
			$order->save();
		}

		return array(
			'trackingCode' => $tracking,
			'externalId'   => $external,
			'order_id'     => $order_id,
			'wc_status'    => $order->get_status(),
			'wh_status'    => $status ?? 'null',
			'canceled'     => $canceled,
			'note'         => $note,
		);
	}

	/**
	 * Persiste los datos del delivery en la orden. No sobrescribe valores
	 * existentes con vacío (evita perder tracking code ante webhook parcial).
	 *
	 * Nota: NO llamamos $order->save() aquí. El caller debe guardarlo (una
	 * sola vez en process_webhook_delivery, evitando doble write).
	 */
	private function apply_delivery_to_order( WC_Order $order, array $d, bool $canceled ): void {
		// Solo actualizar si viene valor (no perder meta existente).
		$preserve = static function ( WC_Order $o, string $key, $new ) {
			$new_str = is_scalar( $new ) ? (string) $new : '';
			if ( $new_str === '' ) {
				return; // mantener valor actual
			}
			$o->update_meta_data( $key, $new_str );
		};

		$preserve( $order, self::META_TRACKING_CODE, $d['trackingCode'] ?? null );
		$preserve( $order, self::META_EXTERNAL_ID, $d['externalId'] ?? null );

		// Status + updated_at siempre se actualizan (incluido string vacío para reset explícito).
		$order->update_meta_data( self::META_STATUS, (string) ( $d['status'] ?? '' ) );
		$order->update_meta_data( self::META_UPDATED_AT, (string) ( $d['updatedAt'] ?? '' ) );
		$order->update_meta_data( self::META_IS_CANCELED, $canceled ? '1' : '0' );
		$order->update_meta_data( self::META_ATTEMPTS, (int) ( $d['attemptsCount'] ?? 0 ) );
	}

	private function format_webhook_note( array $d ): string {
		$code   = $d['trackingCode'] ?? '';
		$status = $d['status'] ?? null;
		// API real puede devolver "in-transit" (guión) en vez del documentado "in_transit".
		$norm = is_string( $status ) ? str_replace( '-', '_', $status ) : $status;

		if ( ! empty( $d['isCanceled'] ) ) {
			return "12 Horas: envío cancelado ($code)";
		}
		switch ( $norm ) {
			case 'approved':
				$who = $d['receiverName'] ?? '';
				return sprintf( '12 Horas: entregado a %s (%s)', $who !== '' ? $who : 'destinatario', $code );
			case 'partial':
				return "12 Horas: entrega parcial ($code)";
			case 'rejected':
				return sprintf(
					'12 Horas: intento fallido #%d — %s',
					(int) ( $d['attemptsCount'] ?? 0 ),
					$d['deliveryComment'] ?? 'sin comentario'
				);
			case 'in_transit':
				return "12 Horas: envío en tránsito ($code)";
			case null:
				return "12 Horas: envío creado, retiro pendiente ($code)";
			default:
				return "12 Horas: cambio de estado → $status ($code)";
		}
	}

	/**
	 * Mapeo 12 Horas status + isCanceled → WooCommerce order status.
	 * Retornar null = no cambiar estado.
	 */
	private function map_status_to_wc( ?string $status, bool $canceled ): ?string {
		if ( $canceled ) {
			return 'cancelled'; }
		// API real devuelve "in-transit" (guión), docs oficial dice "in_transit". Normalizar.
		$norm = is_string( $status ) ? str_replace( '-', '_', $status ) : $status;
		if ( $norm === 'cancelled' || $norm === 'canceled' ) {
			return 'cancelled'; }
		if ( $norm === 'approved' ) {
			return 'completed'; }
		if ( $norm === 'partial' ) {
			return 'completed'; }
		if ( $norm === 'in_transit' ) {
			return null; } // dejar en processing; nota ya registrada
		if ( $norm === 'rejected' ) {
			return null; } // se reintentará, no cambiar
		return null;
	}

	private function resolve_order_id( string $externalId, string $trackingCode ): ?int {
		// 1. Parsear externalId → order_id.
		// S3: regex estricto que solo acepta el prefijo exacto que generamos
		// en build_external_id() ("AKB-ORDER-<id>"). Antes: /(\d+)$/ hacía
		// match con cualquier string terminado en dígitos (ej. "FOO-42"),
		// permitiendo a un payload con externalId ajeno apuntar a órdenes
		// arbitrarias que nunca se crearon con 12 Horas.
		if ( $externalId !== '' && preg_match( '/^AKB-ORDER-(\d+)$/', $externalId, $m ) ) {
			$id = (int) $m[1];
			if ( $id > 0 && wc_get_order( $id ) ) {
				return $id;
			}
		}
		// 2. Fallback: buscar por meta _12horas_tracking_code.
		// Validación estructural para evitar LIKE/wildcard injection en meta_query.
		if ( $trackingCode !== '' && preg_match( '/^[A-Za-z0-9_-]{4,64}$/', $trackingCode ) ) {
			$q   = new WC_Order_Query(
				array(
					'limit'      => 1,
					'return'     => 'ids',
					'meta_query' => array(
						array(
							'key'     => self::META_TRACKING_CODE,
							'value'   => $trackingCode,
							'compare' => '=',
						),
					),
				)
			);
			$ids = $q->get_orders();
			if ( ! empty( $ids ) ) {
				return (int) $ids[0];
			}
		}
		return null;
	}

	// ── Stats ─────────────────────────────────────────────────────
	public function get_30d_stats(): array {
		global $wpdb;
		$meta_table = $wpdb->prefix . 'wc_orders_meta';
		$orders     = $wpdb->prefix . 'wc_orders';
		$count      = 0;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$orders}'" ) ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT o.id)
				 FROM {$orders} o
				 JOIN {$meta_table} m ON m.order_id = o.id AND m.meta_key = %s
				 WHERE o.date_created_gmt >= %s",
					self::META_TRACKING_CODE,
					gmdate( 'Y-m-d', strtotime( '-30 days' ) )
				)
			);
		}
		return array(
			'count' => $count,
			'label' => 'Envíos 12 Horas (30d)',
		);
	}
}
