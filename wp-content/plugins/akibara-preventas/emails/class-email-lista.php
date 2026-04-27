<?php
/**
 * Email: Reserva lista / producto disponible (al cliente).
 * Este es el email MAS IMPORTANTE del sistema.
 */

defined( 'ABSPATH' ) || exit;

class AKB_Preventas_Email_Lista extends WC_Email {

	public function __construct() {
		$this->id             = 'akb_reserva_lista';
		$this->customer_email = true;
		$this->title          = 'Akibara: Tu manga ya llego';
		$this->description    = 'Se envia al cliente cuando su reserva ha sido completada (el producto ya esta disponible).';
		$this->heading        = 'Tu manga ya esta disponible';
		$this->subject        = 'Tu reserva esta lista - Pedido #{order_number}';
		$this->template_html  = 'emails/reserva-lista.php';
		$this->template_base  = AKB_PREVENTAS_DIR . 'templates/';

		add_action( 'akb_reserva_lista_email', [ $this, 'trigger' ], 10, 2 );

		parent::__construct();
		$this->email_type = 'html';
	}

	public function trigger( $order_id, $order = false ) {
		if ( ! $order ) $order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) return;

		$this->object    = $order;
		$this->recipient = $order->get_billing_email();

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
			'enabled' => [ 'title' => 'Activar', 'type' => 'checkbox', 'label' => 'Activar este email', 'default' => 'yes' ],
			'subject' => [ 'title' => 'Asunto', 'type' => 'text', 'default' => $this->subject ],
			'heading' => [ 'title' => 'Encabezado', 'type' => 'text', 'default' => $this->heading ],
		];
	}
}

return new AKB_Preventas_Email_Lista();
