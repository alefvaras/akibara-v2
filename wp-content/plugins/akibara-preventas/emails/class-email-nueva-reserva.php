<?php
/**
 * Email: Nueva reserva recibida (al admin).
 */

defined( 'ABSPATH' ) || exit;

class Akibara_Email_Nueva_Reserva extends WC_Email {

	public function __construct() {
		$this->id             = 'akb_nueva_reserva';
		$this->customer_email = false;
		$this->title          = 'Akibara: Nueva reserva recibida';
		$this->description    = 'Se envia al administrador cuando se recibe una nueva reserva.';
		$this->heading        = 'Nueva reserva recibida';
		$this->subject        = 'Nueva reserva - Pedido #{order_number}';
		$this->template_html  = 'emails/nueva-reserva.php';
		$this->template_base  = AKB_PREVENTAS_DIR . 'templates/';

		add_action( 'akb_nueva_reserva_email', [ $this, 'trigger' ], 10, 2 );

		parent::__construct();
		$this->email_type = 'html';
		$this->recipient  = get_option( 'akb_reservas_email_admin', get_option( 'admin_email' ) );
	}

	public function trigger( $order_id, $order = false ) {
		if ( ! $order ) $order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) return;

		$this->object = $order;

		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{customer_name}'] = $order->get_formatted_billing_full_name();

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, [
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'email'         => $this,
		], '', $this->template_base );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'   => [ 'title' => 'Activar', 'type' => 'checkbox', 'label' => 'Activar este email', 'default' => 'yes' ],
			'recipient' => [ 'title' => 'Destinatario', 'type' => 'text', 'default' => get_option( 'admin_email' ) ],
			'subject'   => [ 'title' => 'Asunto', 'type' => 'text', 'default' => $this->subject ],
			'heading'   => [ 'title' => 'Encabezado', 'type' => 'text', 'default' => $this->heading ],
		];
	}
}

return new Akibara_Email_Nueva_Reserva();
