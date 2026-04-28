<?php
/**
 * Akibara Inventario — Back-in-Stock module.
 *
 * Migrated from plugins/akibara/modules/back-in-stock/module.php.
 * Key changes from legacy:
 * - Guard uses AKB_INV_ADDON_LOADED (not AKIBARA_V10_LOADED).
 * - Table: wp_akb_back_in_stock_subs (renamed from wp_akb_bis_subs).
 *   Schema::install() handles migration of existing rows.
 * - DB operations delegated to BackInStockRepository (injected via ServiceLocator).
 * - register_activation_hook updated to reference this plugin file.
 * - Cell H fixes:
 *   - mesa-08 F-04: CTA color --aki-red → --aki-red-bright via CSS class (no inline style).
 *   - mesa-05 F-03: min-height 44px via CSS class (not inline style).
 *   - Mockup dependency: back-in-stock form UI requires Cell H mockup (PENDING — stub active).
 *     Full branding styling deferred until Cell H delivers mock-10 (back-in-stock form).
 *
 * @package Akibara\Inventario
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_INV_ADDON_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_INVENTARIO_BIS_LOADED' ) ) {
	return;
}

// Feature flag guard — allows disabling from admin without code change.
if ( function_exists( 'akb_is_module_enabled' ) && ! akb_is_module_enabled( 'back-in-stock' ) ) {
	return;
}

define( 'AKB_INVENTARIO_BIS_LOADED', '1.0.0' );

// Constants.
if ( ! defined( 'AKB_BIS_MAX_SUBS_PER_EMAIL' ) ) {
	define( 'AKB_BIS_MAX_SUBS_PER_EMAIL', 20 );
}
if ( ! defined( 'AKB_BIS_NOTIFY_DELAY' ) ) {
	define( 'AKB_BIS_NOTIFY_DELAY', 5 * MINUTE_IN_SECONDS );
}

// ══════════════════════════════════════════════════════════════════
// GROUP WRAP (Sprint 2 postmortem — prevents function redeclare on double-include)
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_inventario_bis_subscribe_handler' ) ) {

	// ──── AJAX: Subscribe ─────────────────────────────────────────────────────────
	if ( function_exists( 'akb_ajax_endpoint' ) ) {
		akb_ajax_endpoint( 'akb_bis_subscribe', array(
			'nonce'      => 'akb-bis-notify',
			'capability' => null,
			'public'     => true,
			'handler'    => 'akb_inventario_bis_subscribe_handler',
		) );
	}

	function akb_inventario_bis_subscribe_handler( array $post ): void {
		$email      = sanitize_email( $post['email'] ?? '' );
		$product_id = (int) ( $post['product_id'] ?? 0 );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Correo no válido' ) );
		}
		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Producto no detectado' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_stock_status() !== 'outofstock' ) {
			wp_send_json_error( array( 'message' => 'Este producto ya tiene stock disponible' ) );
		}

		/** @var \Akibara\Inventario\Repository\BackInStockRepository $repo */
		$repo = akb_inventario_bis_get_repo();

		// Rate limit: max 20 active subs per email.
		if ( $repo->count_active_by_email( $email ) >= AKB_BIS_MAX_SUBS_PER_EMAIL ) {
			wp_send_json_error( array( 'message' => 'Límite de avisos alcanzado (máx. 20 productos)' ) );
		}

		$token  = wp_generate_password( 32, false );
		$result = $repo->upsert( $email, $product_id, $token );

		if ( $result === 'already_active' ) {
			wp_send_json_success( array( 'message' => 'Ya estás en la lista — te avisaremos cuando vuelva' ) );
		}

		wp_send_json_success( array( 'message' => 'Listo, te avisamos cuando vuelva al stock' ) );
	}

	// ──── Repository accessor ─────────────────────────────────────────────────────
	function akb_inventario_bis_get_repo(): \Akibara\Inventario\Repository\BackInStockRepository {
		static $repo = null;
		if ( null === $repo ) {
			// Try ServiceLocator first (preferred — DI path).
			if ( class_exists( '\Akibara\Core\Bootstrap' ) ) {
				try {
					$bootstrap = \Akibara\Core\Bootstrap::instance();
					$repo = $bootstrap->services()->get( 'inventario.bis_repo' );
				} catch ( \Throwable $e ) {
					$repo = null;
				}
			}
			// Fallback: direct instantiation (still works if ServiceLocator unavailable).
			if ( null === $repo ) {
				$repo = new \Akibara\Inventario\Repository\BackInStockRepository();
			}
		}
		return $repo;
	}

	// ──── Unsubscribe (via ?akb_unsub_bis=TOKEN) ─────────────────────────────────
	add_action( 'init', static function (): void {
		if ( ! isset( $_GET['akb_unsub_bis'] ) ) {
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['akb_unsub_bis'] ) );
		if ( strlen( $token ) !== 32 ) {
			return;
		}
		$repo = akb_inventario_bis_get_repo();
		$row  = $repo->find_by_token( $token );
		if ( ! $row ) {
			return;
		}
		$repo->unsubscribe( (int) $row->id );
		$product = wc_get_product( (int) $row->product_id );
		$name    = $product ? $product->get_name() : 'este producto';
		add_action( 'wp_footer', static function () use ( $name ) {
			echo '<script>document.addEventListener("DOMContentLoaded",function(){var m=document.createElement("div");m.innerHTML=\'<div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#161618;color:#fff;text-align:center;padding:16px;border-bottom:2px solid #D90010">Aviso de <strong>' . esc_js( $name ) . '</strong> cancelado.</div>\';document.body.appendChild(m);setTimeout(function(){m.remove()},5000)});</script>';
		} );
	} );

	// ──── Form "Avísame" en PDP agotado ──────────────────────────────────────────
	// IMPORTANTE: theme akibara no dispara woocommerce_single_product_summary.
	// Usamos woocommerce_after_single_product prio 8 (mismo que legacy).
	add_action( 'woocommerce_after_single_product', 'akb_inventario_bis_render_form', 8 );

	function akb_inventario_bis_render_form(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( $product->get_stock_status() !== 'outofstock' ) {
			return;
		}
		$nonce      = wp_create_nonce( 'akb-bis-notify' );
		$pid        = (int) $product->get_id();
		$user_email = is_user_logged_in() ? ( wp_get_current_user()->user_email ?? '' ) : '';
		// NOTE: Full branding (inline styles removed, CSS classes applied) pending Cell H mock-10.
		// REQUIRES MOCKUP: back-in-stock form visual spec from Cell H before removing stub.
		?>
		<div class="aki-bis-widget" data-product-id="<?php echo esc_attr( (string) $pid ); ?>" style="margin:20px 0;padding:18px;background:#161618;border:1px solid #2A2A2E;border-radius:8px">
			<p style="margin:0 0 10px;color:#F5F5F5;font-size:14px;font-weight:600">¿Se agotó? Te avisamos cuando vuelva</p>
			<p style="margin:0 0 12px;color:#A0A0A0;font-size:12px;line-height:1.5">Deja tu correo y te escribimos apenas tengamos stock de nuevo.</p>
			<div class="aki-bis-row" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
				<input type="email" class="aki-bis-email"
					placeholder="tu@correo.cl"
					value="<?php echo esc_attr( $user_email ); ?>"
					style="flex:1;min-width:180px;padding:10px 12px;background:#0D0D0F;border:1px solid #2A2A2E;border-radius:4px;color:#F5F5F5;font-size:14px">
				<?php
				// mesa-08 F-04: aki-bis-btn class applies --aki-red-bright + 44px via CSS.
				// mesa-05 F-03: min-height enforced via .aki-bis-btn CSS class.
				// See inventory-admin.css for .aki-bis-widget .aki-bis-btn rules.
				?>
				<button type="button" class="aki-bis-btn">Avísame</button>
			</div>
			<p class="aki-bis-status" style="margin:8px 0 0;font-size:12px;line-height:1.5;min-height:16px"></p>
		</div>
		<script>
		(function(){
			var widget = document.querySelector('.aki-bis-widget[data-product-id="<?php echo esc_js( (string) $pid ); ?>"]');
			if (!widget || widget.dataset.akbBound) return;
			widget.dataset.akbBound = '1';
			var emailInput = widget.querySelector('.aki-bis-email');
			var btn        = widget.querySelector('.aki-bis-btn');
			var status     = widget.querySelector('.aki-bis-status');
			var ajaxUrl    = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var nonce      = '<?php echo esc_js( $nonce ); ?>';
			var pid        = '<?php echo esc_js( (string) $pid ); ?>';

			btn.addEventListener('click', function(){
				var email = (emailInput.value || '').trim();
				if (!email || !/@/.test(email)) {
					status.textContent = 'Ingresa un correo válido';
					status.style.color = 'var(--aki-red-bright, #FF2233)';
					return;
				}
				btn.disabled = true; btn.style.opacity = '0.6';
				status.textContent = 'Guardando…';
				status.style.color = '#A0A0A0';

				var fd = new FormData();
				fd.append('action', 'akb_bis_subscribe');
				fd.append('nonce', nonce);
				fd.append('email', email);
				fd.append('product_id', pid);

				fetch(ajaxUrl, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled = false; btn.style.opacity = '1';
						if (j && j.success) {
							status.textContent = (j.data && j.data.message) || 'Listo, te avisamos cuando vuelva';
							status.style.color = '#00c853';
							btn.textContent = 'En la lista ✓';
							btn.disabled = true;
						} else {
							status.textContent = (j && j.data && j.data.message) || 'No pudimos guardar, prueba de nuevo';
							status.style.color = 'var(--aki-red-bright, #FF2233)';
						}
					})
					.catch(function(){
						btn.disabled = false; btn.style.opacity = '1';
						status.textContent = 'Error de red — intenta de nuevo';
						status.style.color = 'var(--aki-red-bright, #FF2233)';
					});
			});
		})();
		</script>
		<?php
	}

	// ──── Trigger: producto vuelve a stock ────────────────────────────────────────
	add_action( 'woocommerce_product_set_stock_status', 'akb_inventario_bis_on_stock_change', 10, 3 );

	function akb_inventario_bis_on_stock_change( $product_id, $new_status, $product ): void {
		if ( $new_status !== 'instock' ) {
			return;
		}
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return;
		}
		$repo    = akb_inventario_bis_get_repo();
		$pending = $repo->count_active( $product_id );
		if ( $pending === 0 ) {
			return;
		}
		if ( wp_next_scheduled( 'akb_bis_notify_product', array( $product_id ) ) ) {
			return;
		}
		wp_schedule_single_event( time() + AKB_BIS_NOTIFY_DELAY, 'akb_bis_notify_product', array( $product_id ) );
	}

	// ──── Handler: enviar emails ──────────────────────────────────────────────────
	add_action( 'akb_bis_notify_product', 'akb_inventario_bis_process_product' );

	function akb_inventario_bis_process_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_in_stock() ) {
			return;
		}
		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}
		$api_key = AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}
		$repo = akb_inventario_bis_get_repo();
		$subs = $repo->get_active_subs( $product_id, 500 );
		if ( empty( $subs ) ) {
			return;
		}
		$sent = 0;
		foreach ( $subs as $row ) {
			$ok = akb_inventario_bis_send_email( (string) $row->email, $product, (string) $row->token, $api_key );
			if ( $ok ) {
				$repo->mark_notified( (int) $row->id );
				++$sent;
				usleep( 150000 ); // Rate limit Brevo: ~10 req/s.
			}
		}
		if ( function_exists( 'akb_log' ) ) {
			akb_log( 'back-in-stock', 'info', 'Notificados', array( 'product_id' => $product_id, 'sent' => $sent, 'pending' => count( $subs ) ) );
		}
	}

	// ──── Email send via Brevo ────────────────────────────────────────────────────
	function akb_inventario_bis_send_email( string $email, WC_Product $product, string $token, string $api_key ): bool {
		if ( ! class_exists( '\Akibara\Infra\EmailTemplate' ) ) {
			return false;
		}
		$T   = '\Akibara\Infra\EmailTemplate';
		$name      = $product->get_name();
		$price     = '$' . number_format( (float) $product->get_price(), 0, ',', '.' );
		$img_id    = $product->get_image_id();
		$img       = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
		$stock_qty = $product->get_stock_quantity();
		$stock_text = $stock_qty && $stock_qty > 0
			? sprintf( 'Quedan %d %s · envío inmediato', $stock_qty, $stock_qty === 1 ? 'unidad' : 'unidades' )
			: 'Disponible — envío inmediato';
		$product_url = add_query_arg( array( 'utm_source' => 'email', 'utm_medium' => 'transactional', 'utm_campaign' => 'back-in-stock' ), get_permalink( $product->get_id() ) );
		$unsub_url   = add_query_arg( 'akb_unsub_bis', $token, home_url( '/' ) );

		$html  = $T::open();
		$html .= $T::header( '¡Volvió el stock! ' . $name );
		$html .= $T::content_open();
		$html .= '<p style="text-align:center;font-size:12px;color:' . $T::HOT . ';text-transform:uppercase;letter-spacing:0.14em;margin:0 0 8px;font-weight:700;font-family:' . $T::FONT_HEADING . '">¡Volvió el stock!</p>';
		$html .= $T::headline( 'Lo esperabas — ya llegó' );
		$html .= $T::intro( 'El tomo que pediste que te avisáramos <strong>ya está disponible</strong> en el distrito. Reserva el tuyo antes que se vuelva a agotar.' );
		$html .= $T::product_card( array( 'name' => $name, 'image' => $img, 'url' => $product_url, 'price' => $price, 'qty' => 1 ), 'cart' );
		$html .= '<p style="text-align:center;font-size:13px;color:' . $T::HOT . ';font-weight:700;margin:8px 0 16px;letter-spacing:0.04em;font-family:' . $T::FONT_HEADING . '">' . esc_html( $stock_text ) . '</p>';
		$html .= $T::cta( 'Comprarlo ahora', $product_url, 'back-in-stock' );
		$html .= $T::paragraph( 'Este aviso es único para este tomo · si se vuelve a agotar te avisamos de nuevo.', 'center' );
		$html .= $T::signature();
		$html .= $T::content_close();
		$html .= '<tr><td style="padding:28px;border-top:1px solid ' . $T::BORDER . ';text-align:center">';
		$html .= '<p style="color:' . $T::TEXT_MUTED . ';font-size:11px;line-height:1.6;margin:0 0 14px">Recibes este email porque pediste que te avisáramos cuando volviera el stock de <strong style="color:' . $T::TEXT_SECONDARY . '">' . esc_html( $name ) . '</strong>.</p>';
		$html .= '<p style="margin:8px 0 0"><a href="' . esc_url( $unsub_url ) . '" style="color:' . $T::TEXT_MUTED . ';font-size:11px;text-decoration:underline">Cancelar este aviso</a></p>';
		$html .= '</td></tr>';
		$html .= $T::close();

		return AkibaraBrevo::send_transactional( $api_key, $email, '', sprintf( 'Volvió a la venta: %s', $name ), $html );
	}

	// ──── Conversión: marcar converted si cliente compra post-notify ──────────────
	add_action( 'woocommerce_thankyou', 'akb_inventario_bis_track_conversion' );

	function akb_inventario_bis_track_conversion( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			return;
		}
		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			$product_ids[] = (int) $item->get_product_id();
		}
		if ( empty( $product_ids ) ) {
			return;
		}
		akb_inventario_bis_get_repo()->mark_converted( $email, $product_ids );
	}

	// ──── Admin panel ─────────────────────────────────────────────────────────────
	add_filter( 'akibara_admin_tabs', static function ( array $tabs ): array {
		$tabs['back_in_stock'] = array(
			'label'       => 'Back in Stock',
			'short_label' => 'BIS',
			'icon'        => 'dashicons-bell',
			'group'       => 'marketing',
			'callback'    => 'akb_inventario_bis_render_admin',
		);
		return $tabs;
	} );

	add_action( 'admin_menu', static function (): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		}
		add_submenu_page( 'akibara', 'Avisos Back in Stock', 'Back in Stock', 'manage_woocommerce', 'akibara-back-in-stock', 'akb_inventario_bis_render_admin' );
	} );

	function akb_inventario_bis_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}
		$repo        = akb_inventario_bis_get_repo();
		$stats       = $repo->get_stats();
		$active_rows = $repo->get_active_rows( 100 );
		?>
		<div class="akb-page-header">
			<h2 class="akb-page-header__title">Avisos Back in Stock</h2>
			<p class="akb-page-header__desc">Suscripciones a productos agotados. Email automático al volver el stock.</p>
		</div>
		<div class="akb-stats">
			<div class="akb-stat"><div class="akb-stat__value"><?php echo (int) ( $stats->total ?? 0 ); ?></div><div class="akb-stat__label">Total registros</div></div>
			<div class="akb-stat"><div class="akb-stat__value akb-stat__value--error"><?php echo (int) ( $stats->active ?? 0 ); ?></div><div class="akb-stat__label">Esperando</div></div>
			<div class="akb-stat"><div class="akb-stat__value akb-stat__value--success"><?php echo (int) ( $stats->notified ?? 0 ); ?></div><div class="akb-stat__label">Notificados</div></div>
			<div class="akb-stat"><div class="akb-stat__value akb-stat__value--success"><?php echo (int) ( $stats->converted ?? 0 ); ?></div><div class="akb-stat__label">Convertidos</div></div>
		</div>
		<?php if ( ! empty( $active_rows ) ) : ?>
		<div class="akb-card">
			<h3 style="margin:0 0 12px">Esperando aviso (top 100)</h3>
			<table class="akb-table">
				<thead><tr><th>Email</th><th>Producto</th><th>Desde</th></tr></thead>
				<tbody>
					<?php foreach ( $active_rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->email ); ?></td>
						<td><?php echo esc_html( $r->post_title ?: '(producto eliminado #' . (int) $r->product_id . ')' ); ?></td>
						<td><?php echo esc_html( $r->created_at ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php else : ?>
		<div class="akb-notice akb-notice--info">Sin suscripciones activas por ahora.</div>
		<?php endif; ?>
		<div class="akb-notice akb-notice--info">
			<strong>Cómo funciona:</strong><br>
			1. PDP con producto agotado muestra form "Avísame"<br>
			2. Cliente deja correo → registro en <code>wp_akb_back_in_stock_subs</code><br>
			3. Admin repone stock → hook <code>woocommerce_product_set_stock_status</code> agenda envío en 5 min<br>
			4. Cron dispara email via Brevo con CTA directo a comprar<br>
			5. Si el cliente compra, se marca como <code>converted</code>
		</div>
		<?php
	}

} // end group wrap
