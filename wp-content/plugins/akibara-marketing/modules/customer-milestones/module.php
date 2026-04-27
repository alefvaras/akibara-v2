<?php
/**
 * Akibara Marketing — Customer Milestones (Cumpleaños / Aniversario)
 *
 * NOTE: This module has NO legacy source in the server-snapshot
 * (server-snapshot/public_html/wp-content/plugins/akibara/modules/customer-milestones/
 * is empty). This is a scaffold implementation based on the Sprint 3 plan scope.
 *
 * Scope:
 *  - Birthday emails: sent on the customer's birthday (opt-in at checkout)
 *  - Customer anniversary: sent 1 year after first purchase
 *  - Both use Action Scheduler (WooCommerce) + Brevo transactional
 *
 * Status: SCAFFOLD — admin UI + email templates need Cell H mockup approval.
 * See: audit/sprint-3/cell-b/STUBS.md
 *
 * @package    Akibara\Marketing
 * @subpackage CustomerMilestones
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_MILESTONES_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_MILESTONES_LOADED', '1.0.0' );

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_milestones_sentinel' ) ) {

	function akb_marketing_milestones_sentinel(): bool {
		return defined( 'AKB_MARKETING_MILESTONES_LOADED' );
	}

	// ── BIRTHDAY: collect opt-in at checkout ──────────────────────────────────

	// Add birthday field to checkout
	add_filter( 'woocommerce_checkout_fields', 'akb_milestones_add_birthday_field' );

	function akb_milestones_add_birthday_field( array $fields ): array {
		$fields['billing']['billing_birthday'] = array(
			'label'       => 'Fecha de cumpleaños (opcional)',
			'placeholder' => 'DD/MM',
			'required'    => false,
			'class'       => array( 'form-row-wide' ),
			'type'        => 'text',
			'priority'    => 110,
		);
		return $fields;
	}

	// Save birthday to user meta
	add_action( 'woocommerce_checkout_update_user_meta', 'akb_milestones_save_birthday', 10, 2 );

	function akb_milestones_save_birthday( int $customer_id, array $data ): void {
		if ( empty( $data['billing_birthday'] ) ) {
			return;
		}
		$raw = sanitize_text_field( $data['billing_birthday'] );
		// Validate DD/MM format
		if ( ! preg_match( '/^\d{2}\/\d{2}$/', $raw ) ) {
			return;
		}
		update_user_meta( $customer_id, '_akb_birthday', $raw );
	}

	// ── DAILY CRON: check birthdays + anniversaries ───────────────────────────

	add_action( 'init', function (): void {
		if ( ! wp_next_scheduled( 'akb_milestones_daily_check' ) ) {
			wp_schedule_event( strtotime( 'today 09:00:00' ), 'daily', 'akb_milestones_daily_check' );
		}
	} );

	add_action( 'akb_milestones_daily_check', 'akb_milestones_run_daily_check' );

	function akb_milestones_run_daily_check(): void {
		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			return;
		}
		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			return;
		}

		$today_dm = gmdate( 'd/m' );

		// Birthday check
		$birthday_enabled = (bool) get_option( 'akb_milestones_birthday_enabled', true );
		if ( $birthday_enabled ) {
			$birthday_tpl = (int) get_option( 'akb_milestones_birthday_tpl', 0 );
			if ( $birthday_tpl > 0 ) {
				$users_with_birthday = get_users( array(
					'meta_key'   => '_akb_birthday',
					'meta_value' => $today_dm,
				) );
				foreach ( $users_with_birthday as $user ) {
					// Dedup: only send once per year
					$last_sent = get_user_meta( $user->ID, '_akb_birthday_sent_year', true );
					if ( $last_sent === gmdate( 'Y' ) ) {
						continue;
					}
					akb_milestones_send_birthday( $user, $api_key, $birthday_tpl );
					update_user_meta( $user->ID, '_akb_birthday_sent_year', gmdate( 'Y' ) );
				}
			}
		}

		// Anniversary check (1 year since first completed order)
		$anniversary_enabled = (bool) get_option( 'akb_milestones_anniversary_enabled', true );
		if ( $anniversary_enabled ) {
			$anniversary_tpl = (int) get_option( 'akb_milestones_anniversary_tpl', 0 );
			if ( $anniversary_tpl > 0 ) {
				akb_milestones_check_anniversaries( $api_key, $anniversary_tpl );
			}
		}
	}

	function akb_milestones_send_birthday( $user, string $api_key, int $tpl_id ): void {
		$email      = $user->user_email;
		$first_name = $user->first_name ?: $user->display_name;
		if ( ! is_email( $email ) ) {
			return;
		}
		wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'timeout' => 10,
				'headers' => array( 'api-key' => $api_key, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'templateId' => $tpl_id,
					'to'         => array( array( 'email' => \AkibaraBrevo::test_recipient( $email ), 'name' => $first_name ) ),
					'params'     => array( 'NOMBRE' => $first_name ),
				) ),
			)
		);
	}

	function akb_milestones_check_anniversaries( string $api_key, int $tpl_id ): void {
		$one_year_ago = gmdate( 'Y-m-d', strtotime( '-1 year' ) );
		$orders       = wc_get_orders( array(
			'date_created' => $one_year_ago,
			'status'       => array( 'completed', 'processing' ),
			'limit'        => 50,
		) );
		foreach ( $orders as $order ) {
			$customer_id = $order->get_customer_id();
			if ( ! $customer_id ) {
				continue;
			}
			$last_sent = get_user_meta( $customer_id, '_akb_anniversary_sent_year', true );
			if ( $last_sent === gmdate( 'Y' ) ) {
				continue;
			}
			// Confirm it's really the first order
			if ( wc_get_customer_order_count( $customer_id ) < 1 ) {
				continue;
			}
			$email      = $order->get_billing_email();
			$first_name = $order->get_billing_first_name();
			if ( ! is_email( $email ) ) {
				continue;
			}
			wp_remote_post(
				'https://api.brevo.com/v3/smtp/email',
				array(
					'timeout' => 10,
					'headers' => array( 'api-key' => $api_key, 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'templateId' => $tpl_id,
						'to'         => array( array( 'email' => \AkibaraBrevo::test_recipient( $email ), 'name' => $first_name ) ),
						'params'     => array( 'NOMBRE' => $first_name ),
					) ),
				)
			);
			update_user_meta( $customer_id, '_akb_anniversary_sent_year', gmdate( 'Y' ) );
		}
	}

	// ── ADMIN ─────────────────────────────────────────────────────────────────

	add_filter( 'akibara_admin_tabs', function ( array $tabs ): array {
		$tabs['milestones'] = array(
			'label'       => 'Milestones',
			'short_label' => 'Milestones',
			'icon'        => 'dashicons-calendar-alt',
			'group'       => 'marketing',
			'callback'    => 'akb_milestones_render_admin',
		);
		return $tabs;
	} );

	function akb_milestones_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}
		if ( isset( $_POST['akb_milestones_save'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'akb_milestones_save' ) ) {
			update_option( 'akb_milestones_birthday_enabled', ! empty( $_POST['birthday_enabled'] ) );
			update_option( 'akb_milestones_birthday_tpl', (int) ( $_POST['birthday_tpl'] ?? 0 ) );
			update_option( 'akb_milestones_anniversary_enabled', ! empty( $_POST['anniversary_enabled'] ) );
			update_option( 'akb_milestones_anniversary_tpl', (int) ( $_POST['anniversary_tpl'] ?? 0 ) );
			echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
		}
		$b_enabled = (bool) get_option( 'akb_milestones_birthday_enabled', true );
		$b_tpl     = (int) get_option( 'akb_milestones_birthday_tpl', 0 );
		$a_enabled = (bool) get_option( 'akb_milestones_anniversary_enabled', true );
		$a_tpl     = (int) get_option( 'akb_milestones_anniversary_tpl', 0 );
		?>
		<div class="akb-page-header">
			<h2 class="akb-page-header__title">Customer Milestones</h2>
			<p class="akb-page-header__desc">Emails automáticos de cumpleaños y aniversario de cliente. v<?php echo esc_html( AKB_MARKETING_MILESTONES_LOADED ); ?></p>
		</div>
		<div class="notice notice-info inline"><p><strong>STUB:</strong> UI pendiente de mockup Cell H. Ver <code>audit/sprint-3/cell-b/STUBS.md</code>. La lógica de envío está implementada — solo falta la aprobación de templates Brevo.</p></div>
		<div class="akb-card akb-card--section">
			<h3 class="akb-section-title">Configuración</h3>
			<form method="post">
				<?php wp_nonce_field( 'akb_milestones_save' ); ?>
				<div class="akb-field">
					<label><input type="checkbox" name="birthday_enabled" value="1" <?php checked( $b_enabled ); ?>> Cumpleaños activo</label>
					<label class="akb-field__label" style="margin-top:8px">Template ID Brevo (cumpleaños)</label>
					<input type="number" name="birthday_tpl" value="<?php echo esc_attr( (string) $b_tpl ); ?>" min="0" class="akb-field__input" style="max-width:120px">
					<p class="akb-field__hint">0 = desactivado. Obtén el ID en Brevo → Email Campaigns → Templates.</p>
				</div>
				<div class="akb-field">
					<label><input type="checkbox" name="anniversary_enabled" value="1" <?php checked( $a_enabled ); ?>> Aniversario activo</label>
					<label class="akb-field__label" style="margin-top:8px">Template ID Brevo (aniversario)</label>
					<input type="number" name="anniversary_tpl" value="<?php echo esc_attr( (string) $a_tpl ); ?>" min="0" class="akb-field__input" style="max-width:120px">
				</div>
				<button type="submit" name="akb_milestones_save" value="1" class="akb-btn akb-btn--primary">Guardar</button>
			</form>
		</div>
		<?php
	}

} // end group wrap
