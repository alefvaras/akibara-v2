<?php
/**
 * Akibara Marketing — Módulo Brevo Segmentación
 *
 * Lifted from server-snapshot plugins/akibara/modules/brevo/module.php (v1.1.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * Editorial list IDs delegated to EditorialLists class (canonical source).
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * Sincroniza clientes con listas de Brevo automáticamente.
 * Usa la clase compartida AkibaraBrevo para las llamadas a la API.
 *
 * @package    Akibara\Marketing
 * @subpackage Brevo
 * @version    1.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_BREVO_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_BREVO_LOADED', '1.1.0' );

// ── Constants (always defined) ──────────────────────────────────────────────
// Genre/category → Brevo list ID mapping
if ( ! defined( 'AKIBARA_BREVO_CAT_MAP' ) ) {
	define(
		'AKIBARA_BREVO_CAT_MAP',
		array(
			'shonen'        => 5,
			'seinen'        => 6,
			'shojo'         => 7,
			'josei'         => 8,
			'kodomo'        => 9,
			'marvel'        => 10,
			'dc'            => 11,
			'independiente' => 12,
			'manhwa'        => 13,
		)
	);
}

// Segment list IDs — override via wp_options for admin flexibility
if ( ! defined( 'AKIBARA_BREVO_LIST_COMPRADORES' ) ) {
	define( 'AKIBARA_BREVO_LIST_COMPRADORES', (int) ( get_option( 'akibara_brevo_list_post_purchase', 3 ) ?: 3 ) );
}
if ( ! defined( 'AKIBARA_BREVO_LIST_PREVENTAS' ) ) {
	define( 'AKIBARA_BREVO_LIST_PREVENTAS', (int) ( get_option( 'akibara_brevo_list_abandoned_cart', 14 ) ?: 14 ) );
}
if ( ! defined( 'AKIBARA_BREVO_LIST_VIP' ) ) {
	define( 'AKIBARA_BREVO_LIST_VIP', (int) ( get_option( 'akibara_brevo_list_referrals', 16 ) ?: 16 ) );
}

// Editorial list IDs — canonical values from EditorialLists class (IDs LOCKED per plan).
// These constants are exposed for backward-compat with any code that references them directly.
if ( ! defined( 'AKIBARA_BREVO_LIST_IVREA_AR' ) )    { define( 'AKIBARA_BREVO_LIST_IVREA_AR',    \Akibara\Marketing\Brevo\EditorialLists::IVREA_AR ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_PANINI_AR' ) )   { define( 'AKIBARA_BREVO_LIST_PANINI_AR',   \Akibara\Marketing\Brevo\EditorialLists::PANINI_AR ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_PLANETA_ES' ) )  { define( 'AKIBARA_BREVO_LIST_PLANETA_ES',  \Akibara\Marketing\Brevo\EditorialLists::PLANETA_ES ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_MILKY_WAY' ) )   { define( 'AKIBARA_BREVO_LIST_MILKY_WAY',   \Akibara\Marketing\Brevo\EditorialLists::MILKY_WAY ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_OVNI' ) )        { define( 'AKIBARA_BREVO_LIST_OVNI',        \Akibara\Marketing\Brevo\EditorialLists::OVNI_PRESS ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_IVREA_ES' ) )    { define( 'AKIBARA_BREVO_LIST_IVREA_ES',    \Akibara\Marketing\Brevo\EditorialLists::IVREA_ES ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_PANINI_ES' ) )   { define( 'AKIBARA_BREVO_LIST_PANINI_ES',   \Akibara\Marketing\Brevo\EditorialLists::PANINI_ES ); }
if ( ! defined( 'AKIBARA_BREVO_LIST_ARECHI' ) )      { define( 'AKIBARA_BREVO_LIST_ARECHI',      \Akibara\Marketing\Brevo\EditorialLists::ARECHI ); }

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_brevo_sentinel' ) ) {

	function akb_marketing_brevo_sentinel(): bool {
		return defined( 'AKB_MARKETING_BREVO_LOADED' );
	}

	/**
	 * Resolver de list_id por editorial name.
	 * Delegates to EditorialLists::id_for_name() with wp_option fallback.
	 */
	function akibara_brevo_editorial_list_id( string $editorial ): int {
		return \Akibara\Marketing\Brevo\EditorialLists::id_for_name( $editorial );
	}

	/**
	 * Resolver dinámico de list_id por propósito.
	 * Permite al admin cambiar IDs sin editar código (wp_options).
	 */
	function akb_brevo_get_list_id( string $purpose, int $default ): int {
		$opt = get_option( 'akibara_brevo_list_' . $purpose, null );
		if ( $opt === null || $opt === '' ) {
			return $default;
		}
		return (int) $opt;
	}

	// ── HOOKS — Al completar/procesar orden ───────────────────────────────────

	add_action( 'woocommerce_order_status_processing', 'akibara_brevo_sync_order', 20 );
	add_action( 'woocommerce_order_status_completed', 'akibara_brevo_sync_order', 20 );

	function akibara_brevo_sync_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$sync_status = $order->get_meta( '_akibara_brevo_synced' );
		if ( $sync_status === 'yes' || $sync_status === 'opted_out' ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}

		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		// GDPR: respetar blacklist Brevo
		if ( \AkibaraBrevo::is_blacklisted( $api_key, $email ) ) {
			$order->update_meta_data( '_akibara_brevo_synced', 'opted_out' );
			$order->save();
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'brevo', sprintf( 'Order %d skipped: %s is blacklisted in Brevo', $order_id, $email ) );
			}
			return;
		}

		$list_ids       = array( AKIBARA_BREVO_LIST_COMPRADORES );
		$has_preorder   = false;
		$preorder_count = 0;
		$genres         = array();
		$editorials     = array();
		$series         = array();

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$product    = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			// Category → genre list
			$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $cats ) ) {
				foreach ( $cats as $slug ) {
					if ( isset( AKIBARA_BREVO_CAT_MAP[ $slug ] ) ) {
						$list_ids[] = AKIBARA_BREVO_CAT_MAP[ $slug ];
					}
				}
			}

			// Genre attribute
			$product_genres = wp_get_post_terms( $product_id, 'pa_genero', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $product_genres ) ) {
				foreach ( $product_genres as $g ) {
					$genres[] = $g;
				}
			}

			// Serie attribute
			$product_series = wp_get_post_terms( $product_id, 'pa_serie', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $product_series ) && ! empty( $product_series ) ) {
				$series[] = $product_series[0];
			}

			// Editorial from product_brand taxonomy
			$editorial_terms = wp_get_post_terms( $product_id, 'product_brand', array( 'fields' => 'names' ) );
			$editorial       = ( ! is_wp_error( $editorial_terms ) && ! empty( $editorial_terms ) ) ? (string) $editorial_terms[0] : '';
			if ( ! empty( $editorial ) ) {
				$editorials[] = $editorial;
				$ed_list_id   = akibara_brevo_editorial_list_id( $editorial );
				if ( $ed_list_id > 0 ) {
					$list_ids[] = $ed_list_id;
				}
			}

			// Preorder detection
			if ( $product->get_meta( '_akb_reserva' ) === 'yes' ) {
				$has_preorder = true;
				++$preorder_count;
			}
		}

		if ( $has_preorder ) {
			$list_ids[] = AKIBARA_BREVO_LIST_PREVENTAS;
		}

		$customer_id  = $order->get_customer_id();
		$total_orders = 1;
		$total_spent  = (float) $order->get_total();

		if ( $customer_id > 0 ) {
			$total_orders = wc_get_customer_order_count( $customer_id );
			$customer     = new WC_Customer( $customer_id );
			$total_spent  = (float) $customer->get_total_spent();
		}

		if ( $total_orders >= 3 ) {
			$list_ids[] = AKIBARA_BREVO_LIST_VIP;
		}

		$genre_counts   = array_count_values( $genres );
		$favorite_genre = ! empty( $genre_counts ) ? array_keys( $genre_counts, max( $genre_counts ) )[0] : '';

		$list_ids = array_values( array_unique( $list_ids ) );

		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		$full_name  = trim( $first_name . ' ' . $last_name );

		$serie_counts   = array_count_values( $series );
		$favorite_serie = ! empty( $serie_counts ) ? array_keys( $serie_counts, max( $serie_counts ) )[0] : '';

		$ed_counts          = array_count_values( $editorials );
		$favorite_editorial = ! empty( $ed_counts ) ? array_keys( $ed_counts, max( $ed_counts ) )[0] : '';

		$avg_order_value  = $total_orders > 0 ? round( $total_spent / $total_orders ) : 0;
		$first_order_date = '';

		if ( $customer_id > 0 ) {
			$first_orders = wc_get_orders(
				array(
					'customer_id' => $customer_id,
					'status'      => array( 'completed', 'processing' ),
					'limit'       => 1,
					'orderby'     => 'date',
					'order'       => 'ASC',
				)
			);
			if ( ! empty( $first_orders ) ) {
				$first_order_date = $first_orders[0]->get_date_created()
					? $first_orders[0]->get_date_created()->format( 'Y-m-d' )
					: '';
			}
		}

		$attributes = array(
			'NOMBRE'             => $first_name,
			'APELLIDOS'          => $last_name,
			'TOTAL_ORDERS'       => $total_orders,
			'LAST_ORDER_DATE'    => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'FAVORITE_GENRE'     => $favorite_genre,
			'TOTAL_SPENT'        => $total_spent,
			'CONTACT_SOURCE'     => 'woocommerce_order',
			'FAVORITE_SERIE'     => $favorite_serie,
			'FAVORITE_EDITORIAL' => $favorite_editorial,
			'AVG_ORDER_VALUE'    => $avg_order_value,
			'CITY'               => $order->get_billing_city(),
			'REGION'             => $order->get_billing_state(),
			'PREORDER_COUNT'     => $preorder_count,
		);

		if ( ! empty( $first_order_date ) ) {
			$attributes['FIRST_ORDER_DATE'] = $first_order_date;
		}

		$synced = \AkibaraBrevo::sync_contact( $api_key, $email, $full_name, $attributes, $list_ids );

		$order->update_meta_data( '_akibara_brevo_synced', $synced ? 'yes' : 'failed' );
		if ( $synced ) {
			$order->update_meta_data( '_akibara_brevo_lists', implode( ',', $list_ids ) );
		}
		$order->save();
	}

	// ── ADMIN ─────────────────────────────────────────────────────────────────

	add_action( 'admin_menu', 'akibara_brevo_admin_menu' );

	function akibara_brevo_admin_menu(): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		}
		add_submenu_page(
			'akibara',
			'Brevo Segmentación',
			'📧 Brevo',
			'manage_woocommerce',
			'akibara-brevo',
			'akibara_brevo_render_admin'
		);
	}

	function akibara_brevo_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}

		if ( isset( $_POST['akibara_brevo_resync'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akibara_brevo_resync' ) ) {
			akibara_brevo_resync_all_customers();
			$ls = get_option( 'akibara_brevo_last_sync', array() );
			echo '<div class="notice notice-success"><p>Re-sync en cola: ' . (int) ( $ls['synced'] ?? 0 ) . ' contactos serán enviados a Brevo en background.</p></div>';
		}
		if ( isset( $_POST['akibara_brevo_backfill'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akibara_brevo_backfill' ) ) {
			$result = akibara_brevo_backfill();
			echo '<div class="notice notice-success"><p>Backfill completado: ' . esc_html( $result ) . '</p></div>';
		}

		if ( isset( $_POST['akibara_save_api_key'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_api'] ?? '' ) ), 'akibara_save_api_key' ) ) {
			$raw = trim( sanitize_text_field( wp_unslash( $_POST['akibara_brevo_api_key'] ?? '' ) ) );
			if ( $raw === '' ) {
				echo '<div class="notice notice-warning is-dismissible"><p>Input vacío — no se modificó la API Key guardada.</p></div>';
			} else {
				update_option( 'akibara_brevo_api_key', $raw, false );
				echo '<div class="notice notice-success is-dismissible"><p>API Key guardada.</p></div>';
			}
		}

		$api_key = class_exists( 'AkibaraBrevo' ) ? \AkibaraBrevo::get_api_key() : '';
		$has_key = ! empty( $api_key );
		$masked  = $has_key ? ( substr( $api_key, 0, 8 ) . '...' . substr( $api_key, -4 ) ) : '';

		global $wpdb;
		$use_hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
					&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $use_hpos ) {
			$synced       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s AND meta_value = %s", '_akibara_brevo_synced', 'yes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE status IN (%s,%s)", 'wc-completed', 'wc-processing' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$synced       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_akibara_brevo_synced', 'yes' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$total_orders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN (%s,%s)", 'shop_order', 'wc-completed', 'wc-processing' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		?>
		<div class="akb-page-header">
			<h2 class="akb-page-header__title">Brevo Segmentación</h2>
			<p class="akb-page-header__desc">Sincronización automática de clientes con listas de Brevo. v<?php echo esc_html( AKB_MARKETING_BREVO_LOADED ); ?></p>
		</div>

		<div class="akb-stats">
			<div class="akb-stat"><div class="akb-stat__value akb-stat__value--success"><?php echo esc_html( (string) $synced ); ?></div><div class="akb-stat__label">Sincronizadas</div></div>
			<div class="akb-stat"><div class="akb-stat__value"><?php echo esc_html( (string) $total_orders ); ?></div><div class="akb-stat__label">Total órdenes</div></div>
			<div class="akb-stat"><div class="akb-stat__value <?php echo $has_key ? 'akb-stat__value--success' : 'akb-stat__value--error'; ?>"><?php echo $has_key ? 'Sí' : 'No'; ?></div><div class="akb-stat__label">API Key</div></div>
		</div>

		<div class="akb-card akb-card--section">
			<h3 class="akb-section-title">Configuración</h3>
			<?php if ( $has_key ) : ?>
				<div class="notice notice-success inline" style="margin:0 0 16px"><p><strong>API Key activa:</strong> <code><?php echo esc_html( $masked ); ?></code></p></div>
			<?php else : ?>
				<div class="notice notice-warning inline" style="margin:0 0 16px"><p><strong>Sin API Key configurada.</strong> Define <code>AKB_BREVO_API_KEY</code> en wp-config.php o usa el formulario inferior.</p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'akibara_save_api_key', '_wpnonce_api' ); ?>
				<div class="akb-field">
					<label class="akb-field__label" for="akibara_brevo_api_key">Brevo API Key</label>
					<input type="password" name="akibara_brevo_api_key" id="akibara_brevo_api_key" value="" class="akb-field__input" placeholder="xkeysib-..." autocomplete="off">
					<p class="akb-field__hint">SMTP &amp; API &rarr; API Keys en tu panel Brevo</p>
				</div>
				<button type="submit" name="akibara_save_api_key" value="1" class="akb-btn akb-btn--primary">Guardar API Key</button>
			</form>
		</div>

		<div class="akb-card akb-card--section">
			<h3 class="akb-section-title">Backfill — Sincronizar órdenes existentes</h3>
			<p>Recorre todas las órdenes completadas/procesando no sincronizadas y las envía a Brevo.</p>
			<form method="post">
				<?php wp_nonce_field( 'akibara_brevo_backfill' ); ?>
				<button type="submit" name="akibara_brevo_backfill" value="1" class="akb-btn akb-btn--primary">Sincronizar ahora</button>
			</form>
		</div>

		<div class="akb-card akb-card--section">
			<h3 class="akb-section-title">Re-sync completo de atributos</h3>
			<p>Recalcula TOTAL_ORDERS, TOTAL_SPENT, FAVORITE_SERIE, FAVORITE_EDITORIAL, etc. para todos los clientes.</p>
			<form method="post">
				<?php wp_nonce_field( 'akibara_brevo_resync' ); ?>
				<button type="submit" name="akibara_brevo_resync" value="1" class="akb-btn akb-btn--primary">Re-sincronizar ahora</button>
			</form>
		</div>

		<div class="akb-notice akb-notice--info">
			<strong>Listas automáticas:</strong><br>
			<em>Género:</em> Shonen (5), Seinen (6), Shojo (7), Josei (8), Kodomo (9), Marvel (10), DC (11), Independiente (12), Manhwa (13)<br>
			<em>Segmento:</em> Compradores (3), Preventas (14), VIP 3+ (16)<br>
			<em>Editorial:</em> Ivrea AR (24), Panini AR (25), Planeta ES (26), Milky Way (27), Ovni (28), Ivrea ES (29), Panini ES (30), Arechi (31)
		</div>

		<?php do_action( 'akibara_brevo_admin_after' ); ?>
		<?php
	}

	// ── BACKFILL ──────────────────────────────────────────────────────────────

	function akibara_brevo_backfill(): string {
		$args = array(
			'status'     => array( 'completed', 'processing' ),
			'limit'      => 200,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => '_akibara_brevo_synced', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_akibara_brevo_synced', 'value' => 'failed', 'compare' => '=' ),
			),
		);

		$orders = wc_get_orders( $args );
		if ( empty( $orders ) ) {
			return '0 órdenes pendientes — todo sincronizado';
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			$scheduled = 0;
			foreach ( $orders as $order ) {
				if ( $scheduled >= 50 ) {
					break;
				}
				as_schedule_single_action(
					time() + ( $scheduled * 2 ),
					'akibara_brevo_backfill_single',
					array( 'order_id' => $order->get_id() ),
					'akibara-brevo'
				);
				++$scheduled;
			}
			$remaining = count( $orders ) - $scheduled;
			return "$scheduled órdenes programadas en Action Scheduler"
				. ( $remaining > 0 ? " ($remaining pendientes, vuelve a ejecutar)" : '' );
		}

		$count = 0;
		foreach ( $orders as $order ) {
			akibara_brevo_sync_order( $order->get_id() );
			++$count;
			if ( $count >= 10 ) {
				break;
			}
			usleep( 200000 );
		}

		$remaining = count( $orders ) - $count;
		return "$count órdenes sincronizadas" . ( $remaining > 0 ? " ($remaining pendientes, vuelve a ejecutar)" : ' (todas listas)' );
	}

	add_action(
		'akibara_brevo_backfill_single',
		function ( array $args ): void {
			$order_id = $args['order_id'] ?? 0;
			if ( $order_id ) {
				akibara_brevo_sync_order( $order_id );
			}
		}
	);

	// ── CRON — Weekly re-sync ─────────────────────────────────────────────────

	add_action(
		'init',
		function (): void {
			if ( ! wp_next_scheduled( 'akibara_brevo_weekly_sync' ) ) {
				wp_schedule_event( time(), 'weekly', 'akibara_brevo_weekly_sync' );
			}
		}
	);

	add_action( 'akibara_brevo_weekly_sync', 'akibara_brevo_resync_all_customers' );

	function akibara_brevo_resync_all_customers(): void {
		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}
		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		global $wpdb;
		$t  = $wpdb->prefix . 'wc_orders';
		$ta = $wpdb->prefix . 'wc_order_addresses';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$customers = $wpdb->get_results(
			"SELECT o.customer_id, o.billing_email,
			        MAX(a.first_name) as fname, MAX(a.last_name) as lname,
			        MAX(a.city) as city, MAX(a.state) as region,
			        COUNT(*) as total_orders,
			        ROUND(SUM(o.total_amount)) as total_spent,
			        ROUND(AVG(o.total_amount)) as avg_order,
			        MIN(o.date_created_gmt) as first_order,
			        MAX(o.date_created_gmt) as last_order
			 FROM {$t} o
			 LEFT JOIN {$ta} a ON a.order_id = o.id AND a.address_type = 'billing'
			 WHERE o.type = 'shop_order'
			 AND o.status IN ('wc-processing','wc-completed','wc-shipping-progress','wc-on-hold')
			 AND o.billing_email != ''
			 GROUP BY o.customer_id, o.billing_email
			 HAVING total_orders > 0
			 ORDER BY total_spent DESC
			 LIMIT 500"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $customers ) ) {
			return;
		}

		$customer_ids = array_filter( array_map( fn( $c ) => (int) $c->customer_id, $customers ) );

		$fav_series_map    = array();
		$fav_editorial_map = array();

		if ( ! empty( $customer_ids ) ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$serie_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT sub.customer_id, sub.serie_name
					 FROM (
					    SELECT o.customer_id, trm.name AS serie_name, COUNT(*) AS cnt,
					           ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY COUNT(*) DESC) AS rn
					    FROM {$wpdb->prefix}woocommerce_order_items oi
					    JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
					    JOIN {$t} o ON o.id = oi.order_id
					    JOIN {$wpdb->term_relationships} tr ON tr.object_id = CAST(oim.meta_value AS UNSIGNED)
					    JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'pa_serie'
					    JOIN {$wpdb->terms} trm ON trm.term_id = tt.term_id
					    WHERE o.customer_id IN ({$id_placeholders}) AND o.type = 'shop_order'
					    AND o.status IN ('wc-processing','wc-completed','wc-shipping-progress','wc-on-hold')
					    AND oi.order_item_type = 'line_item'
					    GROUP BY o.customer_id, trm.name
					 ) sub WHERE sub.rn = 1",
					...$customer_ids
				)
			);

			$ed_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT sub.customer_id, sub.editorial
					 FROM (
					    SELECT o.customer_id, ed_term.name AS editorial, COUNT(*) AS cnt,
					           ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY COUNT(*) DESC) AS rn
					    FROM {$wpdb->prefix}woocommerce_order_items oi
					    JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id AND oim.meta_key = '_product_id'
					    JOIN {$t} o ON o.id = oi.order_id
					    JOIN {$wpdb->posts} pp ON pp.ID = CAST(oim.meta_value AS UNSIGNED) AND pp.post_type = 'product'
					    JOIN {$wpdb->term_relationships} ed_tr ON ed_tr.object_id = pp.ID
					    JOIN {$wpdb->term_taxonomy} ed_tt ON ed_tt.term_taxonomy_id = ed_tr.term_taxonomy_id AND ed_tt.taxonomy = 'product_brand'
					    JOIN {$wpdb->terms} ed_term ON ed_term.term_id = ed_tt.term_id
					    WHERE o.customer_id IN ({$id_placeholders}) AND o.type = 'shop_order'
					    AND o.status IN ('wc-processing','wc-completed','wc-shipping-progress','wc-on-hold')
					    AND oi.order_item_type = 'line_item'
					    GROUP BY o.customer_id, ed_term.name
					 ) sub WHERE sub.rn = 1",
					...$customer_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $serie_rows as $row ) {
				$fav_series_map[ (int) $row->customer_id ] = $row->serie_name;
			}
			foreach ( $ed_rows as $row ) {
				$fav_editorial_map[ (int) $row->customer_id ] = $row->editorial;
			}
		}

		$payload = array();
		foreach ( $customers as $c ) {
			if ( empty( $c->billing_email ) || ! is_email( $c->billing_email ) ) {
				continue;
			}

			$list_ids = array( AKIBARA_BREVO_LIST_COMPRADORES );
			if ( (int) $c->total_orders >= 3 ) {
				$list_ids[] = AKIBARA_BREVO_LIST_VIP;
			}

			$cid           = (int) $c->customer_id;
			$fav_serie     = $fav_series_map[ $cid ] ?? '';
			$fav_editorial = $fav_editorial_map[ $cid ] ?? '';

			if ( $fav_editorial ) {
				$ed_list = akibara_brevo_editorial_list_id( $fav_editorial );
				if ( $ed_list > 0 ) {
					$list_ids[] = $ed_list;
				}
			}

			$attributes = array(
				'NOMBRE'             => $c->fname,
				'APELLIDOS'          => $c->lname,
				'TOTAL_ORDERS'       => (int) $c->total_orders,
				'TOTAL_SPENT'        => (float) $c->total_spent,
				'AVG_ORDER_VALUE'    => (float) $c->avg_order,
				'LAST_ORDER_DATE'    => substr( (string) $c->last_order, 0, 10 ),
				'FIRST_ORDER_DATE'   => substr( (string) $c->first_order, 0, 10 ),
				'CITY'               => $c->city,
				'REGION'             => $c->region,
				'FAVORITE_SERIE'     => $fav_serie,
				'FAVORITE_EDITORIAL' => $fav_editorial,
				'CONTACT_SOURCE'     => 'woocommerce_order',
			);

			if ( empty( $attributes['FIRST_ORDER_DATE'] ) ) {
				unset( $attributes['FIRST_ORDER_DATE'] );
			}
			if ( empty( $attributes['LAST_ORDER_DATE'] ) ) {
				unset( $attributes['LAST_ORDER_DATE'] );
			}

			$payload[] = array(
				'email'      => $c->billing_email,
				'name'       => trim( $c->fname . ' ' . $c->lname ),
				'attributes' => $attributes,
				'list_ids'   => array_values( array_unique( $list_ids ) ),
			);
		}

		if ( empty( $payload ) ) {
			return;
		}

		$run_id     = 'brs_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$batch_size = 20;
		$total      = count( $payload );
		set_transient( "akb_brevo_resync_{$run_id}", $payload, 2 * HOUR_IN_SECONDS );

		$num_batches = (int) ceil( $total / $batch_size );
		for ( $i = 0; $i < $num_batches; $i++ ) {
			$batch_args = array(
				'run_id' => $run_id,
				'offset' => $i * $batch_size,
				'limit'  => $batch_size,
				'total'  => $total,
			);
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + ( $i * 10 ),
					'akibara_brevo_resync_batch',
					array( $batch_args ),
					'akibara-brevo'
				);
			} else {
				wp_schedule_single_event( time() + ( $i * 10 ), 'akibara_brevo_resync_batch_wpcron', array( $batch_args ) );
			}
		}

		update_option(
			'akibara_brevo_last_sync',
			array( 'time' => gmdate( 'Y-m-d H:i:s' ), 'synced' => $total ),
			false
		);
	}

	add_action( 'akibara_brevo_resync_batch', 'akibara_brevo_process_resync_batch' );
	add_action( 'akibara_brevo_resync_batch_wpcron', fn( array $args ) => akibara_brevo_process_resync_batch( $args ) );

	function akibara_brevo_process_resync_batch( array $args ): void {
		$run_id = $args['run_id'] ?? '';
		$offset = (int) ( $args['offset'] ?? 0 );
		$limit  = (int) ( $args['limit'] ?? 20 );
		$total  = (int) ( $args['total'] ?? 0 );
		if ( ! $run_id ) {
			return;
		}
		$payload = get_transient( "akb_brevo_resync_{$run_id}" );
		if ( empty( $payload ) ) {
			return;
		}
		$api_key = class_exists( 'AkibaraBrevo' ) ? \AkibaraBrevo::get_api_key() : '';
		if ( empty( $api_key ) ) {
			return;
		}
		$batch = array_slice( $payload, $offset, $limit );
		if ( empty( $batch ) ) {
			return;
		}
		foreach ( $batch as $contact_data ) {
			\AkibaraBrevo::sync_contact(
				$api_key,
				$contact_data['email'],
				$contact_data['name'],
				$contact_data['attributes'],
				$contact_data['list_ids']
			);
			usleep( 200000 );
		}
		if ( ( $offset + $limit ) >= $total ) {
			delete_transient( "akb_brevo_resync_{$run_id}" );
		}
	}

	// ── UNSUBSCRIBE SYNC (Mi Cuenta) ──────────────────────────────────────────

	add_action( 'woocommerce_save_account_details', 'akibara_brevo_sync_unsubscribe', 10, 1 );

	function akibara_brevo_sync_unsubscribe( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce validated by WC core before this hook fires.
		$is_subscribed = isset( $_POST['account_newsletter_subscribed'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['account_newsletter_subscribed'] ) );
		if ( ! $is_subscribed ) {
			if ( ! class_exists( 'AkibaraBrevo' ) ) {
				return;
			}
			$api_key = \AkibaraBrevo::get_api_key();
			if ( empty( $api_key ) ) {
				return;
			}
			$email = $user->user_email;
			if ( ! empty( $email ) && is_email( $email ) ) {
				\AkibaraBrevo::unsubscribe_contact( $api_key, $email );
				if ( function_exists( 'akb_log' ) ) {
					akb_log( 'brevo', sprintf( 'User %d (%s) unsubscribed from Mi Cuenta.', $user_id, $email ) );
				}
			}
		}
	}

	// ── GDPR — Personal data erasure + export ────────────────────────────────

	add_filter( 'wp_privacy_personal_data_erasers', 'akibara_brevo_register_eraser' );

	function akibara_brevo_register_eraser( array $erasers ): array {
		$erasers['akibara-brevo'] = array(
			'eraser_friendly_name' => 'Akibara — Brevo Marketing',
			'callback'             => 'akibara_brevo_erase_personal_data',
		);
		return $erasers;
	}

	function akibara_brevo_erase_personal_data( string $email_address, int $page = 1 ): array {
		$removed  = false;
		$messages = array();
		if ( class_exists( 'AkibaraBrevo' ) && is_email( $email_address ) ) {
			$api_key = \AkibaraBrevo::get_api_key();
			if ( $api_key ) {
				$deleted = \AkibaraBrevo::delete_contact( $api_key, $email_address );
				if ( $deleted ) {
					$removed    = true;
					$messages[] = sprintf( 'Contacto %s eliminado de Brevo.', esc_html( $email_address ) );
				}
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	add_filter( 'wp_privacy_personal_data_exporters', 'akibara_brevo_register_exporter' );

	function akibara_brevo_register_exporter( array $exporters ): array {
		$exporters['akibara-brevo'] = array(
			'exporter_friendly_name' => 'Akibara — Brevo Segmentación',
			'callback'               => 'akibara_brevo_export_personal_data',
		);
		return $exporters;
	}

	function akibara_brevo_export_personal_data( string $email_address, int $page = 1 ): array {
		if ( ! is_email( $email_address ) ) {
			return array( 'data' => array(), 'done' => true );
		}

		global $wpdb;
		$use_hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
					&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $use_hpos ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$list_data = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT om.meta_value FROM {$wpdb->prefix}wc_orders o JOIN {$wpdb->prefix}wc_orders_meta om ON om.order_id = o.id AND om.meta_key = %s WHERE o.billing_email = %s ORDER BY o.date_created_gmt DESC LIMIT 1",
					'_akibara_brevo_lists',
					$email_address
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$list_data = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.meta_value FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s WHERE p.post_type = 'shop_order' AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm2 WHERE pm2.post_id = p.ID AND pm2.meta_key = '_billing_email' AND pm2.meta_value = %s) ORDER BY p.post_date DESC LIMIT 1",
					'_akibara_brevo_lists',
					$email_address
				)
			);
		}

		$items = array();
		if ( $list_data ) {
			$items[] = array(
				'group_id'    => 'akibara-brevo',
				'group_label' => 'Suscripciones de email (Brevo)',
				'item_id'     => 'brevo-lists-1',
				'data'        => array( array( 'name' => 'Listas de segmentación', 'value' => esc_html( $list_data ) ) ),
			);
		}

		return array( 'data' => $items, 'done' => true );
	}

} // end group wrap
