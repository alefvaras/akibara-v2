<?php
/**
 * Email: Fecha de reserva cambiada (al cliente).
 */

defined( 'ABSPATH' ) || exit;

class AKB_Preventas_Email_Fecha_Cambiada extends WC_Email {

	public $old_fecha = 0;
	public $new_fecha = 0;
	public $product_id = 0;

	public function __construct() {
		$this->id             = 'akb_reserva_fecha_cambiada';
		$this->customer_email = true;
		$this->title          = 'Akibara: Fecha de reserva cambiada';
		$this->description    = 'Se envia al cliente cuando la fecha estimada de su reserva cambia.';
		$this->heading        = 'La fecha de tu reserva ha cambiado';
		$this->subject        = 'Cambio de fecha en tu reserva - Pedido #{order_number}';
		$this->template_html  = 'emails/reserva-fecha-cambiada.php';
		$this->template_base  = AKB_PREVENTAS_DIR . 'templates/';

		add_action( 'akb_reserva_fecha_cambiada_email', [ $this, 'trigger' ], 10, 5 );

		parent::__construct();
		$this->email_type = 'html';
	}

	public function trigger( $order_id, $order = false, $product_id = 0, $old_fecha = 0, $new_fecha = 0 ) {
		if ( ! $order ) $order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) return;

		$this->object     = $order;
		$this->recipient  = $order->get_billing_email();
		$this->old_fecha  = (int) $old_fecha;
		$this->new_fecha  = (int) $new_fecha;
		$this->product_id = (int) $product_id;

		$this->placeholders['{order_number}']  = $order->get_order_number();
		$this->placeholders['{customer_name}'] = $order->get_formatted_billing_full_name();
		$this->placeholders['{old_date}']      = akb_reserva_fecha( $this->old_fecha );
		$this->placeholders['{new_date}']      = akb_reserva_fecha( $this->new_fecha );

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, [
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'email'         => $this,
			'old_fecha'     => $this->old_fecha,
			'new_fecha'     => $this->new_fecha,
			'product_id'    => $this->product_id,
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

return new AKB_Preventas_Email_Fecha_Cambiada();
