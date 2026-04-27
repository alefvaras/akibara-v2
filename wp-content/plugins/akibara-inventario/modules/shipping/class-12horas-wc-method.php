<?php
/**
 * 12 Horas Envíos — WooCommerce Shipping Method
 *
 * Aplica cutoff 13:30 hora Chile y ventana L-S (sin domingos): el rate
 * desaparece del checkout fuera de horario de retiro para no prometer
 * "mismo día" cuando 12 Horas ya no puede retirar.
 *
 * @package Akibara\Shipping\TwelveHoras
 * @since   10.10.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	return;
}

class AKB_TwelveHoras_Shipping_Method extends WC_Shipping_Method {

	const CUTOFF_HOUR_CHILE   = 13;  // 13:30 hora Chile (hasta las 13:30 se garantiza retiro same-day)
	const CUTOFF_MINUTE_CHILE = 30;

	/**
	 * Códigos válidos para la Región Metropolitana en WC Chile.
	 * Failsafe geográfico: si el admin olvida restringir la zona, este método
	 * rechaza rates fuera de RM independiente de la configuración de zonas.
	 */
	const RM_STATE_CODES = array( 'RM', 'CL-RM', 'Región Metropolitana', 'Region Metropolitana' );

	public function __construct( $instance_id = 0 ) {
		$this->id                 = '12horas';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = '12 Horas Envíos (Same Day RM)';
		$this->method_description = 'Despacho same-day en Región Metropolitana, L-S hasta las 13:30 hora Chile.';
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', '12 Horas Envíos' );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title'                => array(
				'title'       => 'Título visible',
				'type'        => 'text',
				'description' => 'Nombre que ve el cliente en el checkout.',
				'default'     => '12 Horas Envíos',
				'desc_tip'    => true,
			),
			'cost'                 => array(
				'title'       => 'Costo (CLP)',
				'type'        => 'number',
				'description' => 'Costo del despacho same-day. Tarifa flex actual 12 Horas: 3.290.',
				'default'     => '3290',
				'desc_tip'    => true,
			),
			'free_above'           => array(
				'title'       => 'Gratis sobre (CLP)',
				'type'        => 'number',
				'description' => 'Si el subtotal iguala o supera este monto, el despacho es gratis. Deja 0 para desactivar.',
				'default'     => '0',
				'desc_tip'    => true,
			),
			'next_day_post_cutoff' => array(
				'title'       => 'Post-cutoff: mostrar como "Llega mañana"',
				'type'        => 'checkbox',
				'label'       => 'Seguir ofreciendo el método después de 13:30 con ETA al día siguiente hábil',
				'default'     => 'yes',
				'description' => 'Alineado con MercadoLibre / Falabella: nunca se esconde, solo cambia la promesa. Si se desactiva el método desaparece post-cutoff (comportamiento antiguo).',
			),
			'enforce_workdays'     => array(
				'title'       => 'Solo lunes a sábado',
				'type'        => 'checkbox',
				'label'       => 'Ocultar los domingos (12 Horas no opera)',
				'default'     => 'yes',
				'description' => '12 Horas no realiza retiros ni entregas los domingos.',
			),
			'enforce_rm_only'      => array(
				'title'       => 'Restringir a Región Metropolitana',
				'type'        => 'checkbox',
				'label'       => 'Rechazar este método fuera de RM (failsafe)',
				'default'     => 'yes',
				'description' => 'Defensa extra aunque la zona esté mal configurada. 12 Horas solo cubre RM.',
			),
		);
	}

	/**
	 * Genera el rate que ve el checkout. Aplica cutoff y failsafe RM si corresponde.
	 *
	 * Nunca se esconde post-cutoff mientras `next_day_post_cutoff` esté activo
	 * (default): alineado con MercadoLibre / Falabella, cambia la promesa
	 * ("hoy" → "mañana") en vez de desaparecer. Esconder el método después
	 * del cutoff hace que el cliente crea que no tenemos same-day y migre a
	 * Blue Express con ETA 2 días.
	 */
	public function calculate_shipping( $package = array() ): void {
		if ( ! $this->is_destination_rm( $package ) ) {
			return;
		}

		$show_next_day = 'yes' === $this->get_option( 'next_day_post_cutoff', 'yes' );
		$past_cutoff   = $this->is_past_cutoff();
		$is_sunday     = $this->is_sunday();

		// Domingo: 12 Horas no opera hoy, pero igual podemos vender para el lunes.
		// Post-cutoff L-S: vende para el próximo día hábil.
		if ( $is_sunday || $past_cutoff ) {
			if ( ! $show_next_day ) {
				return;
			}
		}

		$cost       = (float) $this->get_option( 'cost', 3290 );
		$free_above = (float) $this->get_option( 'free_above', 0 );

		if ( $free_above > 0 ) {
			$subtotal = 0.0;
			foreach ( $package['contents'] ?? array() as $item ) {
				$subtotal += (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
			}
			if ( $subtotal >= $free_above ) {
				$cost = 0.0;
			}
		}

		$is_same_day = ! $past_cutoff && ! $is_sunday;
		$eta         = $this->calculate_delivery_date( $is_same_day );

		$label = $this->title;
		if ( ! $is_same_day ) {
			$eta_label = $this->format_eta_label( $eta );
			$label    .= ' — Llega ' . $eta_label;
		}

		$this->add_rate(
			array(
				'id'        => $this->get_rate_id(),
				'label'     => $label,
				'cost'      => $cost,
				'package'   => $package,
				'meta_data' => array(
					'courier'     => '12horas',
					'cutoff'      => self::CUTOFF_HOUR_CHILE,
					'is_same_day' => $is_same_day ? 'yes' : 'no',
					'eta_iso'     => $eta->format( 'Y-m-d' ),
					'eta_label'   => $is_same_day ? 'hoy' : $this->format_eta_label( $eta ),
				),
			)
		);
	}

	/**
	 * Calcula fecha de entrega: hoy si same-day, próximo día hábil si no.
	 * Skipea domingos automáticamente.
	 */
	private function calculate_delivery_date( bool $is_same_day ): DateTimeImmutable {
		try {
			$now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Santiago' ) );
		} catch ( \Exception $e ) {
			return new DateTimeImmutable();
		}

		if ( $is_same_day ) {
			return $now;
		}

		// Next business day: +1 día, skipear domingos.
		$d = $now->modify( '+1 day' );
		while ( (int) $d->format( 'N' ) === 7 ) {
			$d = $d->modify( '+1 day' );
		}
		return $d;
	}

	/**
	 * "mañana" | "el lunes" según offset vs. hoy.
	 */
	private function format_eta_label( DateTimeImmutable $target ): string {
		try {
			$now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Santiago' ) );
		} catch ( \Exception $e ) {
			return 'mañana';
		}
		$today_key    = $now->format( 'Y-m-d' );
		$target_key   = $target->format( 'Y-m-d' );
		$tomorrow_key = $now->modify( '+1 day' )->format( 'Y-m-d' );

		if ( $target_key === $today_key ) {
			return 'hoy';
		}
		if ( $target_key === $tomorrow_key ) {
			return 'mañana';
		}

		$days = array(
			1 => 'lunes',
			2 => 'martes',
			3 => 'miércoles',
			4 => 'jueves',
			5 => 'viernes',
			6 => 'sábado',
			7 => 'domingo',
		);
		$dow  = (int) $target->format( 'N' );
		return 'el ' . ( $days[ $dow ] ?? 'pronto' );
	}

	/**
	 * ¿Ya pasaron las 13:30 hora Chile?
	 */
	private function is_past_cutoff(): bool {
		if ( 'yes' !== $this->get_option( 'enforce_cutoff', 'yes' ) ) {
			return false;
		}
		try {
			$now    = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Santiago' ) );
			$hour   = (int) $now->format( 'G' );
			$minute = (int) $now->format( 'i' );
			$now_m  = $hour * 60 + $minute;
			$cut_m  = self::CUTOFF_HOUR_CHILE * 60 + self::CUTOFF_MINUTE_CHILE;
			return $now_m >= $cut_m;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * ¿Hoy es domingo en hora Chile?
	 */
	private function is_sunday(): bool {
		if ( 'yes' !== $this->get_option( 'enforce_workdays', 'yes' ) ) {
			return false;
		}
		try {
			$now = new DateTimeImmutable( 'now', new DateTimeZone( 'America/Santiago' ) );
			return (int) $now->format( 'N' ) === 7; // ISO: 7 = domingo
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * ¿El destino está en Región Metropolitana?
	 *
	 * Failsafe independiente de la configuración de zonas. Revisa el código
	 * de estado del package (WC Chile puede usar 'RM' o 'CL-RM' según plugin).
	 * Si el package no trae destination (carts vacíos, admin preview), permite
	 * para no romper UI de admin.
	 */
	private function is_destination_rm( array $package ): bool {
		if ( 'yes' !== $this->get_option( 'enforce_rm_only', 'yes' ) ) {
			return true;
		}

		$dest    = $package['destination'] ?? array();
		$state   = isset( $dest['state'] ) ? (string) $dest['state'] : '';
		$country = isset( $dest['country'] ) ? (string) $dest['country'] : '';

		// Sin destino resuelto todavía → permitir (evita falsos negativos en preview).
		if ( $state === '' && $country === '' ) {
			return true;
		}

		// Chile únicamente.
		if ( $country !== '' && $country !== 'CL' ) {
			return false;
		}

		// Match case-insensitive contra lista blanca RM.
		// mb_* asegura UTF-8 safe cuando el state viene con tilde ("Región Metropolitana").
		$state_u = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $state, 'UTF-8' ) : strtoupper( $state );
		foreach ( self::RM_STATE_CODES as $rm ) {
			$rm_u = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $rm, 'UTF-8' ) : strtoupper( $rm );
			if ( $rm_u === $state_u ) {
				return true;
			}
		}
		return false;
	}
}
