<?php
/**
 * Akibara — Customer Edit Address
 *
 * Permite al cliente editar la dirección de envío de su pedido en
 * Mi Cuenta → Ver pedido, MIENTRAS el pedido esté en un estado
 * seguro (pending / on-hold / processing). Al pasar a "Listo para
 * enviar" (o cualquier otro estado), el botón desaparece y la
 * edición queda bloqueada automáticamente.
 *
 * Objetivo: evitar tener que reenviar a Blue Express después de
 * corregir una dirección. El cliente corrige antes del despacho.
 *
 * @package Akibara
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Estados en los que el cliente puede editar la dirección.
 */
function akb_cea_editable_statuses(): array {
	return apply_filters( 'akb_cea_editable_statuses', array( 'pending', 'processing', 'on-hold' ) );
}

/**
 * Máximo de ediciones permitidas por pedido.
 */
function akb_cea_max_edits(): int {
	return (int) apply_filters( 'akb_cea_max_edits', 3 );
}

/**
 * Métodos de envío que NO requieren dirección (retiro, PUDO, Metro).
 */
function akb_cea_non_address_methods(): array {
	return apply_filters(
		'akb_cea_non_address_methods',
		array(
			'local_pickup',
			'akibara_metro',
			'metro_san_miguel',
			'pudoShipping',
		)
	);
}

/**
 * ¿El pedido es elegible para edición de dirección por el cliente?
 */
function akb_cea_can_edit( \WC_Order $order ): bool {
	if ( ! in_array( $order->get_status(), akb_cea_editable_statuses(), true ) ) {
		return false;
	}

	if ( ! $order->needs_shipping_address() ) {
		return false;
	}

	foreach ( $order->get_shipping_methods() as $method ) {
		if ( in_array( $method->get_method_id(), akb_cea_non_address_methods(), true ) ) {
			return false;
		}
	}

	if ( $order->get_meta( 'agencyId' ) ) {
		return false;
	}

	if ( (int) $order->get_meta( '_akb_cea_edit_count' ) >= akb_cea_max_edits() ) {
		return false;
	}

	return true;
}

/**
 * Verifica ownership / acceso al pedido.
 */
function akb_cea_user_can_access( \WC_Order $order ): bool {
	if ( current_user_can( 'manage_woocommerce' ) ) {
		return true;
	}

	$user_id = get_current_user_id();
	if ( $user_id && (int) $order->get_customer_id() === $user_id ) {
		return true;
	}

	// Guest via order key
	if ( isset( $_GET['key'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		if ( hash_equals( $order->get_order_key(), $key ) ) {
			return true;
		}
	}

	return false;
}

/**
 * URL base de retorno para una orden. Guest con key → order-received;
 * logged-in → view-order en Mi Cuenta.
 */
function akb_cea_base_url( \WC_Order $order ): string {
	$user_id         = get_current_user_id();
	$is_owner_logged = $user_id && (int) $order->get_customer_id() === $user_id;

	if ( $is_owner_logged ) {
		return wc_get_endpoint_url( 'view-order', (string) $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
	}

	// Guest → order-received con key (el link del email)
	return add_query_arg(
		'key',
		$order->get_order_key(),
		wc_get_endpoint_url( 'order-received', (string) $order->get_id(), wc_get_checkout_url() )
	);
}

/**
 * URL de edición (abrir formulario).
 */
function akb_cea_form_url( \WC_Order $order ): string {
	return add_query_arg( 'akb_cea_edit', '1', akb_cea_base_url( $order ) );
}

/**
 * URL después de guardar (mensaje de éxito).
 */
function akb_cea_saved_url( \WC_Order $order ): string {
	return add_query_arg( 'akb_cea_saved', '1', akb_cea_base_url( $order ) );
}

/**
 * Render de la UI. Se engancha a `woocommerce_order_details_after_order_table`
 * que dispara en AMBOS endpoints:
 *   - Mi Cuenta → Ver pedido (clientes logged-in)
 *   - Order-received / Thank you (guests con key en URL)
 *
 * Así el link del email del cliente funciona aunque sea guest.
 */
add_action(
	'woocommerce_order_details_after_order_table',
	function ( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! akb_cea_user_can_access( $order ) ) {
			return;
		}

		$order_id         = $order->get_id();
		$can_edit         = akb_cea_can_edit( $order );
		$is_edit          = ! empty( $_GET['akb_cea_edit'] );
		$is_saved         = ! empty( $_GET['akb_cea_saved'] );
		$is_guest         = ! is_user_logged_in();
		$force_guest_form = $is_guest && $can_edit;

		echo '<section class="akb-cea" aria-labelledby="akb-cea-title" style="margin:28px 0;padding:20px;background:#161618;border:1px solid #2A2A2E;border-radius:12px;color:#F5F5F5;">';
		echo '<h3 id="akb-cea-title" style="margin:0 0 12px;font-size:16px;color:#F5F5F5;">Dirección de envío</h3>';

		if ( $is_saved ) {
			echo '<div style="margin:0 0 14px;padding:10px 14px;background:rgba(16,185,129,0.12);border:1px solid #10B981;border-radius:8px;color:#10B981;font-size:14px;">✓ Dirección actualizada correctamente. Revisa los datos abajo.</div>';
		}

		// Render dirección actual
		echo '<div style="margin:0 0 14px;padding:14px;background:#0D0D0F;border:1px solid #2A2A2E;border-radius:8px;font-size:14px;line-height:1.6;">';
		echo wp_kses_post( $order->get_formatted_shipping_address( 'Sin dirección registrada.' ) );
		echo '</div>';

		// Estado + CTA o aviso
		if ( ( $is_edit && $can_edit ) || $force_guest_form ) {
			akb_cea_render_form( $order );
		} elseif ( $can_edit ) {
			echo '<p style="margin:0 0 12px;color:#A0A0A0;font-size:13px;">Puedes modificar tu dirección mientras tu pedido esté en preparación. Una vez que pase a «Listo para enviar», la edición queda bloqueada automáticamente.</p>';
			echo '<a href="' . esc_url( akb_cea_form_url( $order ) ) . '" style="display:inline-block;padding:10px 18px;background:#D90010;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;font-size:14px;">Editar dirección</a>';

			$edits_left = akb_cea_max_edits() - (int) $order->get_meta( '_akb_cea_edit_count' );
			if ( $edits_left < akb_cea_max_edits() ) {
				echo '<span style="margin-left:12px;color:#666;font-size:12px;">Te quedan ' . (int) $edits_left . ' edición(es)</span>';
			}
		} else {
			// Motivo específico del bloqueo
			$status      = $order->get_status();
			$status_name = wc_get_order_status_name( $status );

			if ( ! in_array( $status, akb_cea_editable_statuses(), true ) ) {
				echo '<p style="margin:0;color:#F59E0B;font-size:13px;">🔒 Tu pedido está en estado «' . esc_html( $status_name ) . '». La dirección ya no se puede modificar. Si necesitas un cambio urgente, <a href="' . esc_url( home_url( '/contacto' ) ) . '" style="color:#D90010;">contáctanos</a>.</p>';
			} elseif ( (int) $order->get_meta( '_akb_cea_edit_count' ) >= akb_cea_max_edits() ) {
				echo '<p style="margin:0;color:#F59E0B;font-size:13px;">🔒 Has alcanzado el límite de ediciones para este pedido. <a href="' . esc_url( home_url( '/contacto' ) ) . '" style="color:#D90010;">Contáctanos</a> si necesitas otro cambio.</p>';
			} else {
				echo '<p style="margin:0;color:#A0A0A0;font-size:13px;">Esta dirección no se puede modificar (retiro en punto o método de envío sin dirección).</p>';
			}
		}

		echo '</section>';
	}
);

/**
 * Render del formulario de edición.
 */
function akb_cea_render_form( \WC_Order $order ): void {
	$states     = WC()->countries->get_states( 'CL' ) ?: array();
	$shipping   = $order->get_address( 'shipping' );
	$cancel_url = akb_cea_base_url( $order );

	$field = function ( string $label, string $name, string $value, bool $required = true, string $placeholder = '' ) {
		printf(
			'<p style="margin:0 0 12px;"><label style="display:block;margin:0 0 4px;font-size:13px;color:#A0A0A0;">%s%s</label><input type="text" name="%s" value="%s" placeholder="%s" %s style="width:100%%;padding:10px 12px;background:#0D0D0F;border:1px solid #2A2A2E;border-radius:6px;color:#F5F5F5;font-size:14px;"></p>',
			esc_html( $label ),
			$required ? ' <span style="color:#D90010;">*</span>' : '',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			$required ? 'required' : ''
		);
	};

	echo '<form method="post" action="" class="akb-cea-form" style="margin-top:14px;">';
	wp_nonce_field( 'akb_cea_save_' . $order->get_id(), 'akb_cea_nonce' );
	echo '<input type="hidden" name="akb_cea_action" value="save">';
	echo '<input type="hidden" name="akb_cea_order_id" value="' . (int) $order->get_id() . '">';
	if ( isset( $_GET['key'] ) ) {
		echo '<input type="hidden" name="key" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET['key'] ) ) ) . '">';
	}

	echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
	$field( 'Nombre', 'akb_cea_shipping_first_name', $shipping['first_name'] ?? '', true );
	$field( 'Apellido', 'akb_cea_shipping_last_name', $shipping['last_name'] ?? '', true );
	echo '</div>';

	$field( 'Calle y número', 'akb_cea_shipping_address_1', $shipping['address_1'] ?? '', true, 'Ej: Freire 1595' );
	$field( 'Depto, casa o referencia (opcional)', 'akb_cea_shipping_address_2', $shipping['address_2'] ?? '', false, 'Ej: Depto 1406' );

	echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
	$field( 'Ciudad / Comuna', 'akb_cea_shipping_city', $shipping['city'] ?? '', true );

	// Estado (región) como select
	echo '<p style="margin:0 0 12px;"><label style="display:block;margin:0 0 4px;font-size:13px;color:#A0A0A0;">Región <span style="color:#D90010;">*</span></label>';
	echo '<select name="akb_cea_shipping_state" required style="width:100%;padding:10px 12px;background:#0D0D0F;border:1px solid #2A2A2E;border-radius:6px;color:#F5F5F5;font-size:14px;">';
	echo '<option value="">Selecciona región</option>';
	$current_state = $shipping['state'] ?? '';
	foreach ( $states as $code => $name ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $code ),
			selected( $current_state, $code, false ),
			esc_html( $name )
		);
	}
	echo '</select></p>';
	echo '</div>';

	$field( 'Teléfono de contacto', 'akb_cea_shipping_phone', $shipping['phone'] ?? $order->get_billing_phone(), true, '+56 9 ...' );

	echo '<div style="display:flex;gap:10px;margin-top:16px;">';
	echo '<button type="submit" style="padding:10px 20px;background:#D90010;color:#fff;border:0;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">Guardar cambios</button>';
	echo '<a href="' . esc_url( $cancel_url ) . '" style="padding:10px 20px;background:transparent;color:#A0A0A0;border:1px solid #2A2A2E;border-radius:8px;text-decoration:none;font-size:14px;">Cancelar</a>';
	echo '</div>';

	echo '<p style="margin:14px 0 0;color:#666;font-size:12px;">Los cambios quedarán registrados en el pedido. Si tu pedido ya fue preparado para envío, no podremos aplicar la edición.</p>';

	echo '</form>';
}

/**
 * Handler del submit. Se engancha temprano en el ciclo para procesar
 * antes de que WooCommerce renderice la página de Mi Cuenta.
 */
add_action(
	'template_redirect',
	function (): void {
		if ( empty( $_POST['akb_cea_action'] ) || 'save' !== $_POST['akb_cea_action'] ) {
			return;
		}

		$order_id = absint( $_POST['akb_cea_order_id'] ?? 0 );
		if ( ! $order_id ) {
			wc_add_notice( 'Pedido no válido.', 'error' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wc_add_notice( 'Pedido no encontrado.', 'error' );
			return;
		}

		// Ownership
		if ( ! akb_cea_user_can_access( $order ) ) {
			wc_add_notice( 'No tienes permiso para modificar este pedido.', 'error' );
			return;
		}

		// Nonce
		$nonce = $_POST['akb_cea_nonce'] ?? '';
		if ( ! wp_verify_nonce( $nonce, 'akb_cea_save_' . $order_id ) ) {
			wc_add_notice( 'La verificación de seguridad falló. Intenta de nuevo.', 'error' );
			return;
		}

		// Elegibilidad (status, método, rate limit)
		if ( ! akb_cea_can_edit( $order ) ) {
			wc_add_notice( 'Este pedido ya no se puede editar.', 'error' );
			return;
		}

		// Sanitizar
		$fields = array(
			'first_name' => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_last_name'] ?? '' ) ),
			'address_1'  => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_address_1'] ?? '' ) ),
			'address_2'  => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_address_2'] ?? '' ) ),
			'city'       => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_city'] ?? '' ) ),
			'state'      => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_state'] ?? '' ) ),
			'phone'      => sanitize_text_field( wp_unslash( $_POST['akb_cea_shipping_phone'] ?? '' ) ),
		);

		// Validación mínima
		$errors = array();
		foreach ( array( 'first_name', 'last_name', 'address_1', 'city', 'state', 'phone' ) as $req ) {
			if ( $fields[ $req ] === '' ) {
				$errors[] = "El campo « $req » es obligatorio.";
			}
		}

		// Regex simple dirección: debe contener al menos una letra y un número
		if ( $fields['address_1'] !== '' && ( ! preg_match( '/[a-zA-Z]/u', $fields['address_1'] ) || ! preg_match( '/\d/', $fields['address_1'] ) ) ) {
			$errors[] = 'La dirección debe incluir calle y número (ej: «Freire 1595»).';
		}

		// Estado válido para CL
		$valid_states = WC()->countries->get_states( 'CL' ) ?: array();
		if ( $fields['state'] !== '' && ! isset( $valid_states[ $fields['state'] ] ) ) {
			$errors[] = 'Región no válida.';
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $err ) {
				wc_add_notice( $err, 'error' );
			}
			return;
		}

		// Snapshot antes/después para el note
		$before = $order->get_address( 'shipping' );

		// Aplicar cambios
		$order->set_shipping_first_name( $fields['first_name'] );
		$order->set_shipping_last_name( $fields['last_name'] );
		$order->set_shipping_address_1( $fields['address_1'] );
		$order->set_shipping_address_2( $fields['address_2'] );
		$order->set_shipping_city( $fields['city'] );
		$order->set_shipping_state( $fields['state'] );
		$order->set_shipping_country( 'CL' );

		if ( method_exists( $order, 'set_shipping_phone' ) ) {
			$order->set_shipping_phone( $fields['phone'] );
		}
		// Mantener teléfono de billing actualizado para llamadas del courier
		if ( $order->get_billing_phone() !== $fields['phone'] ) {
			$order->set_billing_phone( $fields['phone'] );
		}

		// Incrementar contador
		$new_count = (int) $order->get_meta( '_akb_cea_edit_count' ) + 1;
		$order->update_meta_data( '_akb_cea_edit_count', $new_count );
		$order->update_meta_data( '_akb_cea_last_edit', current_time( 'mysql' ) );

		// Order note con diff
		$note  = "📍 Cliente editó dirección de envío (edit #{$new_count}):\n";
		$note .= sprintf(
			"Antes: %s %s, %s, %s (%s)\n",
			$before['address_1'] ?? '',
			$before['address_2'] ?? '',
			$before['city'] ?? '',
			$before['state'] ?? '',
			$before['first_name'] . ' ' . $before['last_name']
		);
		$note .= sprintf(
			'Ahora: %s %s, %s, %s (%s)',
			$fields['address_1'],
			$fields['address_2'],
			$fields['city'],
			$fields['state'],
			$fields['first_name'] . ' ' . $fields['last_name']
		);
		$order->add_order_note( $note, 0, true );

		$order->save();

		// Email al staff (order note ya dispara notificación a admin via WC)
		do_action( 'akb_cea_address_updated', $order, $before, $fields );

		// Redirect con mensaje de éxito (PRG pattern)
		wp_safe_redirect( akb_cea_saved_url( $order ) );
		exit;
	},
	5
);
