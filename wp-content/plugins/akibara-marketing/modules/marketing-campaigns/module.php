<?php
/**
 * Akibara Marketing Campaigns v2
 *
 * Campañas programadas con templates HTML, segmentación avanzada y personalización vía Brevo.
 * Usa Action Scheduler (WooCommerce) o wp_schedule_single_event como fallback.
 *
 * @package Akibara
 * @since   10.3.0
 */

/**
 * Lifted from server-snapshot plugins/akibara/modules/marketing-campaigns/module.php.
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * `const AKB_MKT_OPTION` converted to `define()` guard to prevent redeclare.
 * AKIBARA_URL/AKIBARA_VERSION references replaced with AKB_MARKETING_URL/VERSION.
 */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( ! defined( 'AKB_MKT_OPTION' ) ) {
	define( 'AKB_MKT_OPTION', 'akb_marketing_campaigns' );
}

// Load tracking & recurrence addon
require_once __DIR__ . '/tracking.php';

// Load welcome email series (post-popup subscription automation)
if ( ! defined( 'AKIBARA_WS_LOADED' ) ) {
	require_once __DIR__ . '/welcome-series.php';
}

// ─── Admin menu ─────────────────────────────────────────────────
add_filter(
	'akibara_admin_tabs',
	function ( array $tabs ): array {
		$tabs['campaigns'] = array(
			'label'       => 'Campañas',
			'short_label' => 'Campañas',
			'icon'        => 'dashicons-megaphone',
			'group'       => 'marketing',
			'callback'    => 'akb_marketing_render_admin',
		);
		return $tabs;
	}
);

add_action( 'admin_menu', 'akb_marketing_admin_menu' );

function akb_marketing_admin_menu(): void {
	if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
		return;
	}

	add_submenu_page(
		'akibara',
		'Campañas Marketing',
		'📣 Campañas',
		'manage_woocommerce',
		'akibara-marketing-campaigns',
		'akb_marketing_render_admin'
	);
}

add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		if ( strpos( $hook, 'akibara' ) === false ) {
			return;
		}

		// CSS asset: if dedicated file exists in the marketing plugin, use it; otherwise skip gracefully.
		$css_file = AKB_MARKETING_DIR . 'assets/css/marketing-campaigns-admin.css';
		$css_url  = AKB_MARKETING_URL . 'assets/css/marketing-campaigns-admin.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style( 'akb-mkt-admin', $css_url, array( 'akibara-admin' ), AKB_MARKETING_VERSION );
		}
	}
);

// ─── Helpers ────────────────────────────────────────────────────
function akb_marketing_get_campaigns(): array {
	$data = get_option( AKB_MKT_OPTION, array() );
	return is_array( $data ) ? $data : array();
}

function akb_marketing_save_campaigns( array $campaigns ): void {
	update_option( AKB_MKT_OPTION, $campaigns, false );
}

function akb_marketing_segments(): array {
	return array(
		'all_buyers'                 => 'Todos los compradores',
		'vip_3plus'                  => 'VIP (3+ órdenes)',
		'preorder'                   => 'Clientes de preventa/reserva',
		'manga_buyers'               => 'Compradores de manga',
		'comics_buyers'              => 'Compradores de cómics',
		'inactive_30d'               => 'Sin compra en 30 días',
		'inactive_60d'               => 'Sin compra en 60 días',
		'inactive_90d'               => 'Sin compra en 90 días',
		'birthday_today'             => '🎂 Cumpleaños HOY (opt-in en checkout)',
		'customer_anniversary_today' => '🎈 Aniversario cliente HOY (1+ año desde 1ra compra)',
	);
}

// ─── Incentivo configurable ─────────────────────────────────────
/**
 * Render del incentive_box según la config de la campaña.
 * Reemplaza el placeholder {INCENTIVO} en el body.
 *
 * Config esperada en $c (pueden no existir — entonces devuelve ''):
 *   - incentive_type: 'none' | 'discount_pct' | 'discount_fixed'
 *                   | 'free_shipping' | 'custom'
 *   - incentive_value: int (% si pct, CLP si fixed)
 *   - incentive_min: int (umbral mínimo de compra, 0 = sin umbral)
 *   - incentive_title / incentive_text / incentive_icon: si type='custom'
 */
function akb_marketing_render_incentive( array $c ): string {
	$type = (string) ( $c['incentive_type'] ?? 'none' );
	if ( $type === 'none' || $type === '' ) {
		return '';
	}
	if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
		return '';
	}

	$value   = (int) ( $c['incentive_value'] ?? 0 );
	$min     = (int) ( $c['incentive_min'] ?? 0 );
	$min_str = $min > 0
		? ' en compras sobre $' . number_format( $min, 0, ',', '.' )
		: '';

	switch ( $type ) {
		case 'custom':
			$title = (string) ( $c['incentive_title'] ?? '' );
			$text  = (string) ( $c['incentive_text'] ?? '' );
			$icon  = (string) ( $c['incentive_icon'] ?? '🎁' );
			if ( $title === '' && $text === '' ) {
				return '';
			}
			return AkibaraEmailTemplate::incentive_box( $title, $text, $icon );

		case 'discount_pct':
			if ( $value <= 0 ) {
				return '';
			}
			return AkibaraEmailTemplate::incentive_box(
				$value . '% OFF',
				'Descuento aplicable al checkout con tu cupón' . $min_str,
				'🔥'
			);

		case 'discount_fixed':
			if ( $value <= 0 ) {
				return '';
			}
			return AkibaraEmailTemplate::incentive_box(
				'$' . number_format( $value, 0, ',', '.' ) . ' OFF',
				'Descuento en efectivo aplicable al checkout' . $min_str,
				'💰'
			);

		case 'free_shipping':
			$threshold = $min > 0
				? $min
				: ( function_exists( 'akibara_get_free_shipping_threshold' )
					? (int) akibara_get_free_shipping_threshold()
					: 55000 );
			return AkibaraEmailTemplate::incentive_box(
				'ENVÍO GRATIS',
				'En compras sobre $' . number_format( $threshold, 0, ',', '.' ),
				'🚚'
			);
	}
	return '';
}

// ─── Email Templates ────────────────────────────────────────────
function akb_marketing_templates(): array {
	if ( ! class_exists( 'AkibaraEmailTemplate' ) ) {
		return array(
			'custom' => array(
				'name'    => '✏️ Personalizado',
				'subject' => '',
				'html'    => '',
			),
		);
	}

	return array(
		'promo'               => array(
			'name'    => '🔥 Promoción / Descuento',
			'subject' => '{NOMBRE}, una oferta pensada para ti',
			'html'    => AkibaraEmailTemplate::build(
				'Tu oferta en el distrito',
				function () {
					$html  = AkibaraEmailTemplate::headline( 'Hola {NOMBRE} 👋' );
					$html .= AkibaraEmailTemplate::paragraph( 'Tenemos una oferta especial pensada para ti, por ser parte del distrito.' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu código de descuento:' );
					$html .= AkibaraEmailTemplate::paragraph( 'Válido hasta <strong>{END_DATE}</strong> · aplícalo al checkout.', 'center' );
					$html .= AkibaraEmailTemplate::cta( 'Ir a la tienda', 'https://akibara.cl/tienda/', 'mkt-promo' );
					$html .= AkibaraEmailTemplate::paragraph( 'Gracias por bancarnos — seguimos subiendo tomos. 🤙', 'center' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'nuevo_stock'         => array(
			'name'    => '📦 Nuevo Stock / Llegadas',
			'subject' => '¡Nuevas llegadas en Akibara, {NOMBRE}!',
			'html'    => AkibaraEmailTemplate::build(
				'Llegó stock nuevo',
				function () {
					$html  = AkibaraEmailTemplate::headline( '📦 ¡Llegó stock nuevo!' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, acabamos de recibir títulos nuevos que sabemos te van a encantar.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Revisa nuestro catálogo actualizado antes de que se agoten — ya sabes que los más populares vuelan. 🚀' );
					$html .= AkibaraEmailTemplate::cta( 'Ver novedades →', 'https://akibara.cl/tienda/?orderby=date', 'mkt-new-stock' );
					$html .= AkibaraEmailTemplate::paragraph( 'Tip: Si tienes un cupón, no olvides aplicarlo al checkout. 😉' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'preventa'            => array(
			'name'    => '🔮 Preventa / Reserva',
			'subject' => '{NOMBRE}, ¡reserva antes que se agoten!',
			'html'    => AkibaraEmailTemplate::build(
				'Preventas abiertas',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🔮 Preventas abiertas' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, tenemos nuevas preventas disponibles. Asegura tu copia antes que nadie.' );
					$html .= AkibaraEmailTemplate::incentive_box( 'PREVENTA', 'Los títulos más esperados ya están disponibles para reserva. El stock es limitado y se asigna por orden de llegada.', '🔮' );
					$html .= AkibaraEmailTemplate::paragraph( '<strong>¿Cómo funciona?</strong><br>1️⃣ Reserva tu producto pagando el precio completo<br>2️⃣ Te notificamos cuando llega a Chile<br>3️⃣ Hacemos el envío prioritario a tu dirección' );
					$html .= AkibaraEmailTemplate::cta( 'Ver preventas disponibles →', 'https://akibara.cl/preventas/', 'mkt-preventa' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'newsletter'          => array(
			'name'    => '📰 Newsletter',
			'subject' => 'Novedades y noticias de Akibara',
			'html'    => AkibaraEmailTemplate::build(
				'Noticias manga y cómics',
				function () {
					$html  = AkibaraEmailTemplate::headline( '📰 Newsletter Semanal' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, aquí tienes las últimas novedades del mundo del manga y los cómics.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Esta semana destacamos: [Escribe aquí las noticias]' );
					$html .= AkibaraEmailTemplate::cta( 'Leer más en el blog →', 'https://akibara.cl/blog/', 'mkt-newsletter' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'reengage'            => array(
			'name'    => '👋 Re-engagement',
			'subject' => '¡Te echamos de menos, {NOMBRE}!',
			'html'    => AkibaraEmailTemplate::build(
				'Vuelve a Akibara',
				function () {
					$html  = AkibaraEmailTemplate::headline( '👋 ¡Te echamos de menos!' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, ha pasado un tiempo desde tu última visita a Akibara.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Han llegado muchísimos mangas nuevos desde entonces. Para animarte a volver, te dejamos este regalito:' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Vuelve con descuento:' );
					$html .= AkibaraEmailTemplate::cta( '🛒 Usar mi cupón', 'https://akibara.cl/tienda/', 'mkt-reengage' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		// ─── PRESETS ESTACIONALES Q4 (altísimo CVR) ─────────────────
		'black_friday'        => array(
			'name'    => '🖤 Black Friday',
			'subject' => '🖤 Black Friday en Akibara — hasta 40% OFF, {NOMBRE}',
			'html'    => AkibaraEmailTemplate::build(
				'Black Friday — los mejores descuentos del año',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🖤 BLACK FRIDAY', 'Los descuentos más fuertes del año' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, lo que esperabas llegó: hasta <strong>40% OFF</strong> en manga, cómics y novelas gráficas seleccionados.' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Termina', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Black Friday:' );
					$html .= AkibaraEmailTemplate::urgency( '⚡ Stock limitado · Los títulos populares vuelan' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::cta( '🛒 Ver ofertas Black Friday', 'https://akibara.cl/tienda/?black-friday=1', 'mkt-black-friday' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'cyber_monday'        => array(
			'name'    => '💻 Cyber Monday',
			'subject' => '💻 Cyber Monday — 24h extra de descuentos, {NOMBRE}',
			'html'    => AkibaraEmailTemplate::build(
				'Cyber Monday — última oportunidad',
				function () {
					$html  = AkibaraEmailTemplate::headline( '💻 CYBER MONDAY', '24 horas. Solo online.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, ¿te perdiste Black Friday? Hoy es tu revancha: descuentos digitales exclusivos por <strong>solo 24 horas</strong>.' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Termina', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Cyber Monday:' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::cta( '💻 Comprar ahora', 'https://akibara.cl/tienda/?cyber-monday=1', 'mkt-cyber-monday' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'black_weekend'       => array(
			'name'    => '🔥 Black Weekend (Vie-Lun)',
			'subject' => '🔥 4 días de ofertas — Black Weekend en Akibara',
			'html'    => AkibaraEmailTemplate::build(
				'Black Weekend — 4 días de descuentos',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🔥 BLACK WEEKEND', 'De viernes a lunes · 4 días de ofertas' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, combinamos Black Friday y Cyber Monday en un mega fin de semana. Ofertas progresivas — cada día mejor:' );
					$html .= AkibaraEmailTemplate::paragraph( '🖤 <strong>Viernes</strong>: 30% OFF selección manga shonen · 🛍 <strong>Sábado</strong>: 2×1 en novelas gráficas · 📚 <strong>Domingo</strong>: bundle cómics marvel/dc · 💻 <strong>Lunes</strong>: envío gratis + 35% OFF final' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'El fin de semana termina', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Cupón válido todo el fin de semana:' );
					$html .= AkibaraEmailTemplate::urgency( '⚠️ Cada día baja el stock · Títulos top no vuelven' );
					$html .= AkibaraEmailTemplate::cta( '🔥 Ver ofertas del día', 'https://akibara.cl/tienda/?black-weekend=1', 'mkt-black-weekend' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'otaku_no_hi'         => array(
			'name'    => '🎌 Otaku no Hi / Día del Manga',
			'subject' => '🎌 Feliz Día del Manga, {NOMBRE} — regalo dentro',
			'html'    => AkibaraEmailTemplate::build(
				'Día del Manga · 15 de diciembre',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎌 OTAKU NO HI', '15 de diciembre · Día Internacional del Manga' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, hoy celebramos la fecha favorita de la comunidad manga. Desde Akibara queremos agradecerte ser parte de este distrito.' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Otaku no Hi:' );
					$html .= AkibaraEmailTemplate::paragraph( 'Celebremos juntos: elige tu próximo título desde la selección curada por nuestro equipo.' );
					$html .= AkibaraEmailTemplate::cta( '🎌 Ver selección otaku', 'https://akibara.cl/manga/', 'mkt-otaku-no-hi' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'san_valentin'        => array(
			'name'    => '💕 San Valentín (Romance)',
			'subject' => '💕 {NOMBRE}, regala una historia de amor esta San Valentín',
			'html'    => AkibaraEmailTemplate::build(
				'San Valentín · El mejor regalo es una historia',
				function () {
					$html  = AkibaraEmailTemplate::headline( '💕 SAN VALENTÍN', 'El mejor regalo es una historia' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, ¿buscas un regalo que dure más que el chocolate? Una historia permanece para siempre.' );
					$html .= AkibaraEmailTemplate::paragraph( '💕 <strong>Selección romance</strong>: Kimi ni Todoke · Ao Haru Ride · Horimiya · Fruits Basket · Your Lie in April · +30 títulos curados.' );
					$html .= AkibaraEmailTemplate::incentive_box( 'ENVOLTORIO GRATIS', 'Envoltorio de regalo + tarjeta personalizada sin costo · Escribe tu mensaje al checkout', '🎀' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón San Valentín:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Pedidos con entrega asegurada hasta', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '💕 Ver selección romance', 'https://akibara.cl/shojo/', 'mkt-san-valentin' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'halloween'           => array(
			'name'    => '🎃 Halloween (Horror/Gore)',
			'subject' => '🎃 {NOMBRE}, los mangas más oscuros de Akibara',
			'html'    => AkibaraEmailTemplate::build(
				'Halloween · Selección horror y gore',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎃 HALLOWEEN OTAKU', 'Los mangas más oscuros del catálogo' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, se acerca la noche más larga del año. Si te gusta el terror psicológico, el gore y lo sobrenatural, preparamos una selección para ti:' );
					$html .= AkibaraEmailTemplate::paragraph( '🎃 <strong>Junji Ito</strong> (Uzumaki · Tomie · Gyo) · <strong>Tokyo Ghoul</strong> · <strong>Chainsaw Man</strong> · <strong>Berserk</strong> · <strong>Jujutsu Kaisen</strong> · <strong>Parasyte</strong>' );
					$html .= AkibaraEmailTemplate::urgency( '🩸 Stock limitado — ediciones especiales de colección' );
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Halloween:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Promoción termina', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '🎃 Explorar selección horror', 'https://akibara.cl/seinen/', 'mkt-halloween' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'navidad_otaku'       => array(
			'name'    => '🎄 Navidad Otaku (Gift Guide)',
			'subject' => '🎄 {NOMBRE}, la guía de regalos otaku 2026',
			'html'    => AkibaraEmailTemplate::build(
				'Navidad · Gift Guide Otaku',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎄 NAVIDAD OTAKU', 'Guía de regalos para la comunidad' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, preparamos una selección curada por tipo de lector para que encuentres el regalo perfecto:' );
					$html .= AkibaraEmailTemplate::paragraph( '🎄 <strong>Principiante</strong> · <strong>Coleccionista</strong> · <strong>Shonen fan</strong> · <strong>Para niñxs</strong> (selección all-ages) · <strong>Mangaka aspirante</strong>' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Navidad Otaku:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Entrega antes del 24 · último despacho', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '🎄 Ver Gift Guide completa', 'https://akibara.cl/tienda/?navidad=1', 'mkt-navidad' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'aniversario'         => array(
			'name'    => '🎊 Aniversario Akibara',
			'subject' => '🎊 {NOMBRE}, Akibara está de aniversario — regalo para ti',
			'html'    => AkibaraEmailTemplate::build(
				'Aniversario Akibara · Gracias por ser parte',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎊 ANIVERSARIO AKIBARA', 'Gracias por ser parte del distrito' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, hoy celebramos un año más construyendo el distrito del manga y cómics en Chile. Llevas <strong>{TOTAL_ORDERS} compras</strong> con nosotros — gracias por apoyar la comunidad.' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón Aniversario:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'La celebración termina', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '🎊 Celebrar con un manga', 'https://akibara.cl/tienda/', 'mkt-aniversario' );
					$html .= AkibaraEmailTemplate::paragraph( 'Un año más no es solo un número — es cada cliente que confía en nosotros. Gracias.', 'center' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		// ─── ANIVERSARIO DEL CLIENTE (desde su 1ra compra) ──────────
		// Zero-friction, 100% cobertura, CVR ~2.8%. Disparado por cron diario
		// en customer-milestones al matchear MM-DD de la primera compra.
		'aniversario_cliente' => array(
			'name'    => '🎈 Aniversario del cliente (1ra compra)',
			'subject' => '🎈 {NOMBRE}, hace 1 año llegaste a Akibara — gracias',
			'html'    => AkibaraEmailTemplate::build(
				'Tu aniversario en Akibara',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎈 HACE 1 AÑO', 'Empezaste tu historia en Akibara' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, hoy se cumple <strong>un año</strong> desde tu primera compra con nosotros. Llevas <strong>{TOTAL_ORDERS} compras</strong> en Akibara — gracias por confiar.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Para celebrar este aniversario juntos, te dejamos un regalo personal:' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón aniversario:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Expira', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '🎈 Seguir coleccionando', 'https://akibara.cl/tienda/', 'mkt-aniversario-cliente' );
					$html .= AkibaraEmailTemplate::paragraph( '¿Qué historia vas a empezar este año?', 'center' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		// ─── CUMPLEAÑOS DEL CLIENTE (mayor CVR del stack) ───────────
		'cumpleanos'          => array(
			'name'    => '🎂 Cumpleaños del cliente',
			'subject' => '🎂 ¡Feliz cumpleaños, {NOMBRE}! Regalo adentro',
			'html'    => AkibaraEmailTemplate::build(
				'Feliz cumpleaños · Regalo personal de Akibara',
				function () {
					$html  = AkibaraEmailTemplate::headline( '🎂 ¡FELIZ CUMPLEAÑOS!', 'Hoy es tu día · desde Akibara' );
					$html .= AkibaraEmailTemplate::paragraph( 'Hola <strong>{NOMBRE}</strong>, desde Akibara queremos desearte un cumpleaños lleno de aventuras, páginas nuevas y mucho manga.' );
					$html .= AkibaraEmailTemplate::paragraph( 'Como agradecimiento por ser parte de nuestra comunidad, te dejamos un regalo especial solo por hoy y los próximos 7 días:' );
					$html .= '{INCENTIVO}';
					$html .= AkibaraEmailTemplate::coupon_box( '{COUPON}', 'Tu cupón de cumpleaños:' );
					$html .= AkibaraEmailTemplate::countdown_banner( 'Tu regalo expira', '{END_DATE}' );
					$html .= AkibaraEmailTemplate::cta( '🎂 Elegir mi regalo', 'https://akibara.cl/tienda/', 'mkt-cumpleanos' );
					$html .= AkibaraEmailTemplate::paragraph( 'Que este año esté lleno de grandes historias. 🎉', 'center' );
					$html .= AkibaraEmailTemplate::signature();
					return $html;
				},
				'{EMAIL}',
				'marketing'
			),
		),

		'custom'              => array(
			'name'    => '✏️ Personalizado (sin template)',
			'subject' => '',
			'html'    => '',
		),
	);
}

// ─── Admin UI ───────────────────────────────────────────────────
function akb_marketing_render_admin(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Sin permisos' );
	}

	// ─ Cancelar campaña
	if ( isset( $_GET['akb_mkt_cancel'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'akb_mkt_cancel' ) ) {
		$cid       = sanitize_text_field( $_GET['akb_mkt_cancel'] );
		$campaigns = akb_marketing_get_campaigns();
		if ( ! empty( $campaigns[ $cid ] ) && $campaigns[ $cid ]['status'] === 'scheduled' ) {
			$campaigns[ $cid ]['status'] = 'cancelled';
			akb_marketing_save_campaigns( $campaigns );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'akb_marketing_execute_campaign', array( 'campaign_id' => $cid ), 'akibara-marketing' );
			}
			echo '<div class="notice notice-warning"><p>Campaña cancelada.</p></div>';
		}
	}

	// ─ Duplicar campaña
	if ( isset( $_GET['akb_mkt_duplicate'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'akb_mkt_duplicate' ) ) {
		$cid       = sanitize_text_field( $_GET['akb_mkt_duplicate'] );
		$campaigns = akb_marketing_get_campaigns();
		if ( ! empty( $campaigns[ $cid ] ) ) {
			$src                  = $campaigns[ $cid ];
			$new_id               = 'cmp_' . wp_generate_password( 10, false, false );
			$campaigns[ $new_id ] = array(
				'id'         => $new_id,
				'name'       => $src['name'] . ' (copia)',
				'segment'    => $src['segment'],
				'subject'    => $src['subject'],
				'body'       => $src['body'],
				'send_at'    => time() + 86400,
				'coupon'     => $src['coupon'] ?? '',
				'status'     => 'draft',
				'created_at' => time(),
				'sent'       => 0,
				'failed'     => 0,
			);
			akb_marketing_save_campaigns( $campaigns );
			echo '<div class="notice notice-success"><p>Campaña duplicada como borrador.</p></div>';
		}
	}

	// ─ Crear campaña
	if ( isset( $_POST['akb_mkt_create'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'akb_mkt_create' ) ) {
		$name         = sanitize_text_field( $_POST['name'] ?? '' );
		$segment      = sanitize_text_field( $_POST['segment'] ?? 'all_buyers' );
		$subject      = sanitize_text_field( $_POST['subject'] ?? '' );
		$body         = wp_kses_post( $_POST['body'] ?? '' );
		$send_at      = sanitize_text_field( $_POST['send_at'] ?? '' );
		$end_date_raw = sanitize_text_field( $_POST['end_date'] ?? '' );
		$coupon       = sanitize_text_field( $_POST['coupon'] ?? '' );
		$template     = sanitize_text_field( $_POST['template'] ?? 'custom' );
		$recurrence   = sanitize_text_field( $_POST['recurrence'] ?? 'none' );

		// Incentivo configurable
		$inc_type          = sanitize_text_field( $_POST['incentive_type'] ?? 'none' );
		$inc_value         = (int) ( $_POST['incentive_value'] ?? 0 );
		$inc_min           = (int) ( $_POST['incentive_min'] ?? 0 );
		$inc_title         = sanitize_text_field( $_POST['incentive_title'] ?? '' );
		$inc_text          = sanitize_text_field( $_POST['incentive_text'] ?? '' );
		$inc_icon          = sanitize_text_field( $_POST['incentive_icon'] ?? '🎁' );
		$allowed_inc_types = array( 'none', 'discount_pct', 'discount_fixed', 'free_shipping', 'custom' );
		if ( ! in_array( $inc_type, $allowed_inc_types, true ) ) {
			$inc_type = 'none';
		}

		// Si se eligió un template, usar su HTML y subject
		if ( $template !== 'custom' ) {
			$templates = akb_marketing_templates();
			if ( isset( $templates[ $template ] ) ) {
				$tpl = $templates[ $template ];
				if ( empty( $body ) && ! empty( $tpl['html'] ) ) {
					$body = $tpl['html'];
				}
				if ( empty( $subject ) && ! empty( $tpl['subject'] ) ) {
					$subject = $tpl['subject'];
				}
			}
		}

		if ( $name && $subject && $body && $send_at ) {
			$ts = strtotime( $send_at );
			if ( $ts && $ts > time() ) {
				$campaigns = akb_marketing_get_campaigns();
				$id        = 'cmp_' . wp_generate_password( 10, false, false );
				// Validación y normalización del end_date (placeholder {END_DATE} en templates).
				$end_date_ts = 0;
				if ( $end_date_raw !== '' ) {
					$end_date_ts = (int) strtotime( $end_date_raw );
					// end_date debe ser posterior a send_at (si no, se ignora).
					if ( $end_date_ts > 0 && $end_date_ts <= $ts ) {
						$end_date_ts = 0;
					}
				}

				$campaigns[ $id ] = array(
					'id'              => $id,
					'name'            => $name,
					'segment'         => $segment,
					'subject'         => $subject,
					'body'            => $body,
					'send_at'         => $ts,
					'end_date'        => $end_date_ts ? wp_date( 'Y-m-d H:i:s', $end_date_ts ) : '',
					'coupon'          => $coupon,
					'template'        => $template,
					'recurrence'      => $recurrence,
					'incentive_type'  => $inc_type,
					'incentive_value' => $inc_value,
					'incentive_min'   => $inc_min,
					'incentive_title' => $inc_title,
					'incentive_text'  => $inc_text,
					'incentive_icon'  => $inc_icon,
					'status'          => 'scheduled',
					'created_at'      => time(),
					'sent'            => 0,
					'failed'          => 0,
				);
				akb_marketing_save_campaigns( $campaigns );

				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( $ts, 'akb_marketing_execute_campaign', array( 'campaign_id' => $id ), 'akibara-marketing' );
				} else {
					wp_schedule_single_event( $ts, 'akb_marketing_execute_campaign_wpcron', array( $id ) );
				}

				echo '<div class="notice notice-success"><p>✅ Campaña <strong>' . esc_html( $name ) . '</strong> programada para ' . esc_html( wp_date( 'd/m/Y H:i', $ts ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>La fecha debe ser futura.</p></div>';
			}
		} else {
			echo '<div class="notice notice-error"><p>Completa todos los campos obligatorios.</p></div>';
		}
	}

	// ─ Envío de prueba
	if ( isset( $_POST['akb_mkt_test'] ) && wp_verify_nonce( $_POST['_wpnonce_test'] ?? '', 'akb_mkt_test' ) ) {
		$test_email   = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
		$test_subject = sanitize_text_field( $_POST['test_subject'] ?? 'Test campaña Akibara' );
		$test_body    = wp_kses_post( $_POST['test_body'] ?? '' );

		if ( $test_email && $test_body && class_exists( 'AkibaraBrevo' ) ) {
			$api_key   = AkibaraBrevo::get_api_key();
			$unsub_url = home_url( '/?akb_unsub=1&email=test@example.com&token=test' );
			// En test usamos una config de incentivo fake para que el admin vea el preview.
			$test_incentive_cfg  = array(
				'incentive_type'  => sanitize_text_field( $_POST['test_incentive_type'] ?? 'none' ),
				'incentive_value' => (int) ( $_POST['test_incentive_value'] ?? 0 ),
				'incentive_min'   => (int) ( $_POST['test_incentive_min'] ?? 0 ),
				'incentive_title' => sanitize_text_field( $_POST['test_incentive_title'] ?? '' ),
				'incentive_text'  => sanitize_text_field( $_POST['test_incentive_text'] ?? '' ),
				'incentive_icon'  => sanitize_text_field( $_POST['test_incentive_icon'] ?? '🎁' ),
			);
			$test_incentive_html = akb_marketing_render_incentive( $test_incentive_cfg );
			$html                = str_replace(
				array( '{INCENTIVO}', '{NOMBRE}', '{EMAIL}', '{TOTAL_ORDERS}', '{TOTAL_SPENT}', '{COUPON}', '{UNSUBSCRIBE_URL}' ),
				array( $test_incentive_html, 'Test User', $test_email, '5', '$50.000', 'TEST10', esc_url( $unsub_url ) ),
				$test_body
			);
			$ok                  = AkibaraBrevo::send_transactional( $api_key, $test_email, 'Test', $test_subject, $html );
			echo $ok
				? '<div class="notice notice-success"><p>✅ Email de prueba enviado a ' . esc_html( $test_email ) . '</p></div>'
				: '<div class="notice notice-error"><p>❌ Error al enviar. Verifica API key de Brevo.</p></div>';
		}
	}

	$campaigns = akb_marketing_get_campaigns();
	$segments  = akb_marketing_segments();
	$templates = akb_marketing_templates();
	$brevo_ok  = class_exists( 'AkibaraBrevo' ) && ! empty( AkibaraBrevo::get_api_key() );
	?>
	<div class="wrap akb-mkt-wrap">
		<div class="akb-page-header">
			<h1 class="akb-page-header__title">Campañas Marketing</h1>
		</div>

		<?php if ( ! $brevo_ok ) : ?>
			<div class="akb-notice akb-notice--warning"><p><strong>Brevo API Key no configurada.</strong> Ve a Akibara &rarr; Brevo para configurarla.</p></div>
		<?php endif; ?>

		<!-- STATS -->
		<div class="akb-stats">
			<div class="akb-stat">
				<div class="akb-stat__value"><?php echo count( $campaigns ); ?></div>
				<div class="akb-stat__label">Total campañas</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--info"><?php echo count( array_filter( $campaigns, fn( $c ) => $c['status'] === 'scheduled' ) ); ?></div>
				<div class="akb-stat__label">Programadas</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--success"><?php echo array_sum( array_column( $campaigns, 'sent' ) ); ?></div>
				<div class="akb-stat__label">Emails enviados</div>
			</div>
			<div class="akb-stat">
				<div class="akb-stat__value akb-stat__value--error"><?php echo array_sum( array_column( $campaigns, 'failed' ) ); ?></div>
				<div class="akb-stat__label">Fallidos</div>
			</div>
		</div>

		<!-- NUEVA CAMPAÑA -->
		<div class="akb-card akb-card--section">
			<h2>Nueva campaña</h2>

			<!-- Templates -->
			<h3>1. Elige un template</h3>
			<div class="akb-mkt-templates" id="akb-tpl-grid">
				<?php foreach ( $templates as $key => $tpl ) : ?>
					<div class="akb-mkt-tpl<?php echo $key === 'custom' ? ' active' : ''; ?>"
						data-tpl="<?php echo esc_attr( $key ); ?>"
						data-subject="<?php echo esc_attr( $tpl['subject'] ); ?>"
						data-html="<?php echo esc_attr( $tpl['html'] ); ?>">
						<span class="icon"><?php echo explode( ' ', $tpl['name'] )[0]; ?></span>
						<span class="name"><?php echo esc_html( substr( $tpl['name'], strpos( $tpl['name'], ' ' ) + 1 ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Preview -->
			<div class="akb-mkt-preview" id="akb-preview">
				<h4 class="akb-mkt-preview__title">👁️ Vista previa del template</h4>
				<iframe id="akb-preview-frame" sandbox="allow-same-origin"></iframe>
				<p class="akb-mkt-preview__actions"><button type="button" class="button" id="akb-preview-close">Cerrar preview</button></p>
			</div>

			<h3>2. Configura la campaña</h3>
			<form method="post">
				<?php wp_nonce_field( 'akb_mkt_create' ); ?>
				<input type="hidden" name="template" id="akb-tpl-input" value="custom">
				<table class="form-table">
					<tr>
						<th><label for="mkt-name">Nombre</label></th>
						<td><input type="text" id="mkt-name" name="name" class="regular-text" required placeholder="Ej: Promo Invierno 2026"></td>
					</tr>
					<tr>
						<th><label>Segmento</label></th>
						<td>
							<select name="segment">
								<?php foreach ( $segments as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<strong>Nuevos:</strong> "Sin compra en X días" ideal para re-engagement con cupón de descuento.
							</p>
						</td>
					</tr>
					<tr>
						<th><label>Asunto del email</label></th>
						<td><input type="text" name="subject" id="akb-subject" class="regular-text" required placeholder="Ej: ¡Solo hoy! 20% en manga"></td>
					</tr>
					<tr>
						<th><label>📅 Fecha y hora de envío</label></th>
						<td>
							<input type="datetime-local" name="send_at" required>
							<p class="description">Hora Chile (UTC-4). La campaña se enviará automáticamente.</p>
						</td>
					</tr>
					<tr>
						<th><label>⏰ Fecha de término (opcional)</label></th>
						<td>
							<input type="datetime-local" name="end_date">
							<p class="description">
								Para campañas time-sensitive (Black Friday, Cyber Monday, etc).
								Usa <code>{END_DATE}</code> o <code>AkibaraEmailTemplate::countdown_banner()</code> en el template.
								Si se deja vacío, el placeholder usa un fallback de 72h después del envío.
							</p>
						</td>
					</tr>
					<tr>
						<th><label>Cupón (opcional)</label></th>
						<td><input type="text" name="coupon" class="regular-text" placeholder="WELCOME10"></td>
					</tr>
					<tr>
						<th><label>🎁 Incentivo destacado</label></th>
						<td>
							<select name="incentive_type" id="akb-inc-type" style="min-width:220px">
								<option value="none">— Sin incentivo destacado —</option>
								<option value="discount_pct">🔥 Descuento %</option>
								<option value="discount_fixed">💰 Descuento $ fijo</option>
								<option value="free_shipping">🚚 Envío gratis</option>
								<option value="custom">✏️ Personalizado (texto libre)</option>
							</select>
							<p class="description">
								Se renderiza como "caja destacada" en el email donde el template tenga el marcador <code>{INCENTIVO}</code>.
								Si tu template no lo tiene, puedes agregarlo manualmente en el HTML.
							</p>
							<table class="akb-inc-fields" style="margin-top:8px">
								<tr class="akb-inc-row akb-inc-row--value" style="display:none">
									<td style="padding:4px 12px 4px 0"><label>Valor</label></td>
									<td><input type="number" name="incentive_value" min="0" step="1" value="0" class="small-text"> <span class="akb-inc-unit"></span></td>
								</tr>
								<tr class="akb-inc-row akb-inc-row--min" style="display:none">
									<td style="padding:4px 12px 4px 0"><label>Compra mínima</label></td>
									<td><input type="number" name="incentive_min" min="0" step="1000" value="0" class="regular-text"> CLP <small>(0 = sin mínimo)</small></td>
								</tr>
								<tr class="akb-inc-row akb-inc-row--custom" style="display:none">
									<td style="padding:4px 12px 4px 0"><label>Título</label></td>
									<td><input type="text" name="incentive_title" class="regular-text" placeholder="Ej: ENVOLTORIO GRATIS"></td>
								</tr>
								<tr class="akb-inc-row akb-inc-row--custom" style="display:none">
									<td style="padding:4px 12px 4px 0"><label>Descripción</label></td>
									<td><input type="text" name="incentive_text" class="regular-text" placeholder="Ej: Envoltorio + tarjeta personalizada sin costo"></td>
								</tr>
								<tr class="akb-inc-row akb-inc-row--custom" style="display:none">
									<td style="padding:4px 12px 4px 0"><label>Ícono</label></td>
									<td><input type="text" name="incentive_icon" class="small-text" value="🎁" maxlength="4"></td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<th><label>🔄 Recurrencia</label></th>
						<td>
							<select name="recurrence">
								<option value="none">Sin repetición</option>
								<option value="weekly">Semanal (cada 7 días)</option>
								<option value="monthly">Mensual (cada 30 días)</option>
							</select>
							<p class="description">Si es recurrente, se creará automáticamente la siguiente campaña después de cada envío.</p>
						</td>
					</tr>
					<tr>
						<th><label>Contenido HTML</label></th>
						<td>
							<textarea name="body" id="akb-body" rows="12" class="akb-mkt-editor" required placeholder="Selecciona un template arriba o escribe HTML personalizado"></textarea>
							<p class="description">
								<strong>Variables:</strong>
								<code>{NOMBRE}</code> <code>{EMAIL}</code> <code>{TOTAL_ORDERS}</code> <code>{TOTAL_SPENT}</code> <code>{COUPON}</code> <code>{END_DATE}</code> <code>{INCENTIVO}</code>
							</p>
						</td>
					</tr>
				</table>
				<p><button class="akb-btn akb-btn--primary akb-btn--lg" name="akb_mkt_create" value="1">Programar campaña</button></p>
			</form>
		</div>

		<!-- TEST EMAIL -->
		<div class="akb-card akb-card--section">
			<h3>Envío de prueba</h3>
			<form method="post" class="akb-mkt-test-form">
				<?php wp_nonce_field( 'akb_mkt_test', '_wpnonce_test' ); ?>
				<div>
					<label>Email destino</label><br>
					<input type="email" name="test_email" class="akb-mkt-test__input" required placeholder="tu@email.com">
				</div>
				<div>
					<label>Asunto</label><br>
					<input type="text" name="test_subject" class="akb-mkt-test__input" value="Test campaña Akibara">
				</div>
				<div class="akb-mkt-test__field--grow">
					<label>HTML (usa variables)</label><br>
					<textarea name="test_body" rows="2" class="akb-mkt-editor" placeholder="<p>Hola {NOMBRE}, prueba!</p>"></textarea>
				</div>
				<button class="akb-btn akb-btn--primary akb-btn--sm" name="akb_mkt_test" value="1">Enviar test</button>
			</form>
		</div>

		<!-- LISTADO -->
		<h2>Historial de campañas</h2>
		<table class="akb-table">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Template</th>
					<th>Segmento</th>
					<th>Programada</th>
					<th>Estado</th>
					<th>Enviados</th>
					<th>Aperturas</th>
					<th>Clicks</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $campaigns ) ) : ?>
				<tr><td colspan="9" class="akb-mkt-empty">Sin campañas creadas aún.</td></tr>
				<?php
			else :
				$status_labels = array(
					'scheduled'     => array( 'Programada', 'akb-badge akb-badge--info' ),
					'sending'       => array( 'Enviando…', 'akb-badge akb-badge--warning' ),
					'sent'          => array( 'Enviada', 'akb-badge akb-badge--active' ),
					'cancelled'     => array( 'Cancelada', 'akb-badge akb-badge--inactive' ),
					'draft'         => array( 'Borrador', 'akb-badge akb-badge--inactive' ),
					'failed_no_api' => array( 'Sin API', 'akb-badge akb-badge--error' ),
					'sent_empty'    => array( 'Sin destinatarios', 'akb-badge akb-badge--warning' ),
				);
				foreach ( array_reverse( $campaigns ) as $c ) :
					$sl         = $status_labels[ $c['status'] ] ?? array( ucfirst( $c['status'] ), 'akb-badge akb-badge--inactive' );
					$base_url   = admin_url(
						defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' )
						? 'admin.php?page=akibara&tab=campaigns'
						: 'admin.php?page=akibara-marketing-campaigns'
					);
					$cancel_url = wp_nonce_url( add_query_arg( 'akb_mkt_cancel', $c['id'], $base_url ), 'akb_mkt_cancel' );
					$dup_url    = wp_nonce_url( add_query_arg( 'akb_mkt_duplicate', $c['id'], $base_url ), 'akb_mkt_duplicate' );
					?>
				<tr>
					<td><strong><?php echo esc_html( $c['name'] ); ?></strong></td>
					<td>
					<?php
						$tpl_key = $c['template'] ?? 'custom';
						echo esc_html( isset( $templates[ $tpl_key ] ) ? explode( ' ', $templates[ $tpl_key ]['name'] )[0] . ' ' . substr( $templates[ $tpl_key ]['name'], strpos( $templates[ $tpl_key ]['name'], ' ' ) + 1 ) : $tpl_key );
					?>
					</td>
					<td><?php echo esc_html( $segments[ $c['segment'] ] ?? $c['segment'] ); ?></td>
					<td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $c['send_at'] ) ); ?></td>
					<td><span class="<?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span></td>
					<td><?php echo (int) ( $c['sent'] ?? 0 ); ?></td>
					<td>
					<?php
						$tr        = function_exists( 'akb_mkt_get_tracking_stats' ) ? akb_mkt_get_tracking_stats( $c['id'] ) : array(
							'unique_opens' => 0,
							'total_clicks' => 0,
						);
						$open_rate = ( $c['sent'] ?? 0 ) > 0 ? round( $tr['unique_opens'] / (int) $c['sent'] * 100 ) : 0;
						echo (int) $tr['unique_opens'];
						if ( $open_rate > 0 ) {
							echo ' <small class="akb-mkt-open-rate">(' . $open_rate . '%)</small>';
						}
						?>
					</td>
					<td><?php echo (int) $tr['total_clicks']; ?></td>
					<td>
						<?php if ( $c['status'] === 'scheduled' ) : ?>
							<a href="<?php echo esc_url( $cancel_url ); ?>" class="akb-btn akb-btn--sm akb-btn--danger akb-mkt-cancel">Cancelar</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( $dup_url ); ?>" class="akb-btn akb-btn--sm" title="Duplicar">Duplicar</a>
					</td>
				</tr>
					<?php
			endforeach;
endif;
			?>
			</tbody>
		</table>
	</div>

	<script>
	(function(){
		const grid = document.getElementById('akb-tpl-grid');
		const input = document.getElementById('akb-tpl-input');
		const subjectEl = document.getElementById('akb-subject');
		const bodyEl = document.getElementById('akb-body');
		const preview = document.getElementById('akb-preview');
		const frame = document.getElementById('akb-preview-frame');
		const closePreviewBtn = document.getElementById('akb-preview-close');
		const adminConfirm = (window.AkibaraAdmin && typeof window.AkibaraAdmin.confirm === 'function')
			? window.AkibaraAdmin.confirm
			: function (msg, cb) { if (window.confirm(msg)) cb(); };

		// Toggle campos de incentivo según tipo seleccionado
		const incType = document.getElementById('akb-inc-type');
		if (incType) {
			const rows = document.querySelectorAll('.akb-inc-row');
			const unitEl = document.querySelector('.akb-inc-unit');
			const refresh = () => {
				const t = incType.value;
				rows.forEach(r => r.style.display = 'none');
				if (t === 'discount_pct') {
					document.querySelector('.akb-inc-row--value').style.display = '';
					document.querySelector('.akb-inc-row--min').style.display = '';
					if (unitEl) unitEl.textContent = '%';
				} else if (t === 'discount_fixed') {
					document.querySelector('.akb-inc-row--value').style.display = '';
					document.querySelector('.akb-inc-row--min').style.display = '';
					if (unitEl) unitEl.textContent = 'CLP';
				} else if (t === 'free_shipping') {
					document.querySelector('.akb-inc-row--min').style.display = '';
				} else if (t === 'custom') {
					document.querySelectorAll('.akb-inc-row--custom').forEach(r => r.style.display = '');
				}
			};
			incType.addEventListener('change', refresh);
			refresh();
		}

		if (!grid) return;

		if (closePreviewBtn && preview) {
			closePreviewBtn.addEventListener('click', function () {
				preview.style.display = 'none';
			});
		}

		document.querySelectorAll('.akb-mkt-cancel').forEach(function (link) {
			link.addEventListener('click', function (e) {
				e.preventDefault();
				const href = link.getAttribute('href');
				adminConfirm('¿Cancelar esta campaña?', function () {
					if (href) window.location.href = href;
				});
			});
		});

		grid.addEventListener('click', function(e){
			const card = e.target.closest('.akb-mkt-tpl');
			if (!card) return;

			grid.querySelectorAll('.akb-mkt-tpl').forEach(c => c.classList.remove('active'));
			card.classList.add('active');

			const tpl = card.dataset.tpl;
			const subject = card.dataset.subject;
			const html = card.dataset.html;

			input.value = tpl;

			if (tpl !== 'custom') {
				if (subject) subjectEl.value = subject;
				if (html) {
					bodyEl.value = html;
					// Show preview
					preview.style.display = 'block';
					const doc = frame.contentDocument || frame.contentWindow.document;
					doc.open();
					doc.write(html.replace(/{NOMBRE}/g,'María').replace(/{EMAIL}/g,'maria@test.com').replace(/{TOTAL_ORDERS}/g,'7').replace(/{TOTAL_SPENT}/g,'$89.990').replace(/{COUPON}/g,'PROMO20'));
					doc.close();
				}
			} else {
				subjectEl.value = '';
				bodyEl.value = '';
				preview.style.display = 'none';
			}
		});
	})();
	</script>
	<?php
}

// ─── Segment builder ────────────────────────────────────────────
function akb_marketing_recipients_by_segment( string $segment ): array {
	$orders = wc_get_orders(
		array(
			'status'  => array( 'completed', 'processing' ),
			'limit'   => 1500,
			'orderby' => 'date',
			'order'   => 'DESC',
		)
	);

	// Pass 1: collect all unique product IDs for batch prefetch.
	$all_pids = array();
	foreach ( $orders as $order ) {
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( $pid > 0 ) {
				$all_pids[ $pid ] = true;
			}
		}
	}
	$all_pids = array_keys( $all_pids );

	// Batch query 1: product categories (1 query vs N×M individual wp_get_post_terms calls).
	$pid_cats = array();
	if ( ! empty( $all_pids ) ) {
		$terms = wp_get_object_terms( $all_pids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$pid_cats[ (int) $term->object_id ][] = $term->slug;
			}
		}
	}

	// Batch query 2: reserva meta (1 query vs N×M get_post_meta calls).
	$reserva_pids = array();
	if ( ! empty( $all_pids ) ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $all_pids ), '%d' ) );
		$rows         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_akb_reserva' AND meta_value = 'yes' AND post_id IN ({$placeholders})",
				...$all_pids
			)
		);
		$reserva_pids = array_flip( array_map( 'intval', $rows ) );
	}

	// Pass 2: build contacts using prefetched maps — no per-item DB hits.
	$contacts = array();
	foreach ( $orders as $order ) {
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( ! is_email( $email ) ) {
			continue;
		}

		$order_date = $order->get_date_completed() ? $order->get_date_completed()->getTimestamp() : $order->get_date_created()->getTimestamp();

		if ( ! isset( $contacts[ $email ] ) ) {
			$contacts[ $email ] = array(
				'email'         => $email,
				'name'          => $order->get_billing_first_name() ?: 'Akibara Fan',
				'total_orders'  => 0,
				'total_spent'   => 0.0,
				'has_preorder'  => false,
				'cats'          => array(),
				'last_order'    => 0,
				'first_order'   => 0,
				'user_id'       => (int) $order->get_user_id(),
				'birthday_mmdd' => '', // llenado abajo si existe en user/order meta
			);
		}

		++$contacts[ $email ]['total_orders'];
		$contacts[ $email ]['total_spent'] += (float) $order->get_total();
		if ( $order_date > $contacts[ $email ]['last_order'] ) {
			$contacts[ $email ]['last_order'] = $order_date;
		}
		if ( $contacts[ $email ]['first_order'] === 0 || $order_date < $contacts[ $email ]['first_order'] ) {
			$contacts[ $email ]['first_order'] = $order_date;
		}

		// Birthday opt-in: prefer user meta (canónico), fallback a order meta.
		if ( $contacts[ $email ]['birthday_mmdd'] === '' ) {
			$mmdd = '';
			if ( $contacts[ $email ]['user_id'] > 0 ) {
				$mmdd = (string) get_user_meta( $contacts[ $email ]['user_id'], '_akb_birthday_mmdd', true );
			}
			if ( $mmdd === '' ) {
				$mmdd = (string) $order->get_meta( '_akb_birthday_mmdd', true );
			}
			if ( preg_match( '/^\d{2}-\d{2}$/', $mmdd ) ) {
				$contacts[ $email ]['birthday_mmdd'] = $mmdd;
			}
		}

		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( isset( $pid_cats[ $pid ] ) ) {
				$contacts[ $email ]['cats'] = array_merge( $contacts[ $email ]['cats'], $pid_cats[ $pid ] );
			}
			if ( isset( $reserva_pids[ $pid ] ) ) {
				$contacts[ $email ]['has_preorder'] = true;
			}
		}
	}

	$now      = time();
	$filtered = array();
	foreach ( $contacts as $c ) {
		$days_since_last = ( $now - $c['last_order'] ) / DAY_IN_SECONDS;

		switch ( $segment ) {
			case 'vip_3plus':
				if ( $c['total_orders'] < 3 ) {
					continue 2;
				}
				break;
			case 'preorder':
				if ( ! $c['has_preorder'] ) {
					continue 2;
				}
				break;
			case 'manga_buyers':
				if ( ! in_array( 'manga', $c['cats'], true ) ) {
					continue 2;
				}
				break;
			case 'comics_buyers':
				if ( ! in_array( 'comics', $c['cats'], true ) ) {
					continue 2;
				}
				break;
			case 'inactive_30d':
				if ( $days_since_last < 30 ) {
					continue 2;
				}
				break;
			case 'inactive_60d':
				if ( $days_since_last < 60 ) {
					continue 2;
				}
				break;
			case 'inactive_90d':
				if ( $days_since_last < 90 ) {
					continue 2;
				}
				break;
			case 'birthday_today':
				// Matchea MM-DD de HOY contra el opt-in capturado en checkout.
				$today_mmdd = wp_date( 'm-d' );
				if ( $c['birthday_mmdd'] !== $today_mmdd ) {
					continue 2;
				}
				break;
			case 'customer_anniversary_today':
				// Aniversario cliente: MM-DD de 1ra compra = hoy, y al menos 1 año completo.
				if ( $c['first_order'] === 0 ) {
					continue 2;
				}
				$first_mmdd = wp_date( 'm-d', (int) $c['first_order'] );
				if ( $first_mmdd !== wp_date( 'm-d' ) ) {
					continue 2;
				}
				if ( ( $now - $c['first_order'] ) < ( 365 * DAY_IN_SECONDS ) ) {
					continue 2;
				}
				break;
		}
		$filtered[] = $c;
	}

	return $filtered;
}

// ─── Campaign executor (Action Scheduler batches) ───────────────
const AKB_MKT_BATCH_SIZE = 50;

/**
 * Phase 1: Gather recipients, store in option, schedule batches via Action Scheduler.
 * Runs once per campaign — no timeout risk.
 */
add_action(
	'akb_marketing_execute_campaign',
	function ( array $args ): void {
		$campaign_id = $args['campaign_id'] ?? '';
		if ( ! $campaign_id ) {
			return;
		}

		$campaigns = akb_marketing_get_campaigns();
		if ( empty( $campaigns[ $campaign_id ] ) ) {
			return;
		}

		$c = $campaigns[ $campaign_id ];
		if ( ! in_array( $c['status'] ?? '', array( 'scheduled', 'draft' ), true ) ) {
			return;
		}

		$api_key = class_exists( 'AkibaraBrevo' ) ? AkibaraBrevo::get_api_key() : '';
		if ( empty( $api_key ) ) {
			$campaigns[ $campaign_id ]['status'] = 'failed_no_api';
			akb_marketing_save_campaigns( $campaigns );
			return;
		}

		$recipients = akb_marketing_recipients_by_segment( (string) $c['segment'] );
		if ( empty( $recipients ) ) {
			$campaigns[ $campaign_id ]['status'] = 'sent_empty';
			akb_marketing_save_campaigns( $campaigns );
			return;
		}

		// Store recipients indexed list + init counters
		$recipients = array_values( $recipients );
		update_option( "akb_mkt_queue_{$campaign_id}", $recipients, false );
		$campaigns[ $campaign_id ]['status']            = 'sending';
		$campaigns[ $campaign_id ]['recipients_count']  = count( $recipients );
		$campaigns[ $campaign_id ]['sent']              = 0;
		$campaigns[ $campaign_id ]['failed']            = 0;
		$campaigns[ $campaign_id ]['batches_total']     = (int) ceil( count( $recipients ) / AKB_MKT_BATCH_SIZE );
		$campaigns[ $campaign_id ]['batches_completed'] = 0;
		akb_marketing_save_campaigns( $campaigns );

		// Schedule batches with 5s gap between each
		$total_batches = (int) ceil( count( $recipients ) / AKB_MKT_BATCH_SIZE );
		for ( $i = 0; $i < $total_batches; $i++ ) {
			$batch_args = array(
				'campaign_id' => $campaign_id,
				'offset'      => $i * AKB_MKT_BATCH_SIZE,
				'limit'       => AKB_MKT_BATCH_SIZE,
			);

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time() + ( $i * 5 ),
					'akb_marketing_send_batch',
					array( $batch_args ),
					'akibara-marketing'
				);
			} else {
				// Fallback: WP-Cron (less reliable but functional)
				wp_schedule_single_event( time() + ( $i * 5 ), 'akb_marketing_send_batch_wpcron', array( $batch_args ) );
			}
		}

		// Schedule finalization check after all batches should be done
		$finalize_delay = ( $total_batches * 5 ) + 30;
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $finalize_delay,
				'akb_marketing_finalize_campaign',
				array( array( 'campaign_id' => $campaign_id ) ),
				'akibara-marketing'
			);
		} else {
			wp_schedule_single_event( time() + $finalize_delay, 'akb_marketing_finalize_wpcron', array( $campaign_id ) );
		}
	},
	10,
	1
);

/**
 * Phase 2: Send one batch of emails (max AKB_MKT_BATCH_SIZE).
 * Each batch runs in its own Action Scheduler task — no timeout.
 */
add_action( 'akb_marketing_send_batch', 'akb_marketing_process_batch' );
add_action( 'akb_marketing_send_batch_wpcron', 'akb_marketing_process_batch' );

function akb_marketing_process_batch( array $args ): void {
	$campaign_id = $args['campaign_id'] ?? '';
	$offset      = (int) ( $args['offset'] ?? 0 );
	$limit       = (int) ( $args['limit'] ?? AKB_MKT_BATCH_SIZE );
	if ( ! $campaign_id ) {
		return;
	}

	$campaigns = akb_marketing_get_campaigns();
	if ( empty( $campaigns[ $campaign_id ] ) ) {
		return;
	}
	$c = $campaigns[ $campaign_id ];

	// Skip if campaign was cancelled
	if ( ( $c['status'] ?? '' ) !== 'sending' ) {
		return;
	}

	$api_key = class_exists( 'AkibaraBrevo' ) ? AkibaraBrevo::get_api_key() : '';
	if ( empty( $api_key ) ) {
		return;
	}

	$queue = get_option( "akb_mkt_queue_{$campaign_id}", array() );
	if ( empty( $queue ) ) {
		return;
	}

	$batch = array_slice( $queue, $offset, $limit );
	if ( empty( $batch ) ) {
		return;
	}

	$sent   = 0;
	$failed = 0;

	// Render del incentive_box una sola vez (no depende del destinatario)
	$incentivo_html = akb_marketing_render_incentive( $c );

	foreach ( $batch as $r ) {
		$html = (string) $c['body'];
		$html = str_replace( '{INCENTIVO}', $incentivo_html, $html );
		$html = str_replace( '{NOMBRE}', esc_html( (string) $r['name'] ), $html );
		$html = str_replace( '{EMAIL}', esc_html( (string) $r['email'] ), $html );
		$html = str_replace( '{TOTAL_ORDERS}', (string) (int) $r['total_orders'], $html );
		$html = str_replace( '{TOTAL_SPENT}', strip_tags( wc_price( (float) $r['total_spent'] ) ), $html );
		$html = str_replace( '{COUPON}', esc_html( (string) ( $c['coupon'] ?? '' ) ), $html );

		// End-date placeholder for time-sensitive campaigns (Black Friday, Cyber Monday, etc).
		// Resolved from $c['end_date'] (Y-m-d H:i:s) if set, else fallback to campaign send date +72h.
		$end_date_str = '';
		if ( ! empty( $c['end_date'] ) ) {
			$ts = strtotime( (string) $c['end_date'] );
			if ( $ts ) {
				$end_date_str = wp_date( 'l j \d\e F \a \l\a\s H:i', $ts );
			}
		}
		if ( $end_date_str === '' ) {
			// Fallback: 72h from now (safe default, avoids broken-looking emails).
			$end_date_str = wp_date( 'l j \d\e F \a \l\a\s H:i', time() + ( 72 * HOUR_IN_SECONDS ) );
		}
		$html = str_replace( '{END_DATE}', esc_html( $end_date_str ), $html );

		$unsub_token = wp_hash( $r['email'] . 'marketing' );
		$unsub_url   = add_query_arg(
			array(
				'akb_unsub' => '1',
				'email'     => rawurlencode( $r['email'] ),
				'token'     => $unsub_token,
				'type'      => 'marketing',
			),
			home_url( '/' )
		);
		$html        = str_replace( '{UNSUBSCRIBE_URL}', esc_url( $unsub_url ), $html );

		// Inject open pixel + click tracking
		if ( function_exists( 'akb_mkt_inject_tracking' ) ) {
			$html = akb_mkt_inject_tracking( $html, $campaign_id, (string) $r['email'] );
		}

		$ok = AkibaraBrevo::send_transactional(
			$api_key,
			(string) $r['email'],
			(string) $r['name'],
			(string) $c['subject'],
			$html
		);

		if ( $ok ) {
			++$sent;
		} else {
			++$failed;
		}
	}

	// Update counters atomically
	$campaigns = akb_marketing_get_campaigns();
	if ( ! empty( $campaigns[ $campaign_id ] ) ) {
		$campaigns[ $campaign_id ]['sent']              = ( (int) ( $campaigns[ $campaign_id ]['sent'] ?? 0 ) ) + $sent;
		$campaigns[ $campaign_id ]['failed']            = ( (int) ( $campaigns[ $campaign_id ]['failed'] ?? 0 ) ) + $failed;
		$campaigns[ $campaign_id ]['batches_completed'] = ( (int) ( $campaigns[ $campaign_id ]['batches_completed'] ?? 0 ) ) + 1;
		akb_marketing_save_campaigns( $campaigns );
	}
}

/**
 * Phase 3: Finalize campaign — mark as sent, clean up queue, trigger recurrence.
 */
add_action( 'akb_marketing_finalize_campaign', 'akb_marketing_do_finalize' );
add_action(
	'akb_marketing_finalize_wpcron',
	function ( string $campaign_id ): void {
		akb_marketing_do_finalize( array( 'campaign_id' => $campaign_id ) );
	}
);

function akb_marketing_do_finalize( array $args ): void {
	$campaign_id = $args['campaign_id'] ?? '';
	if ( ! $campaign_id ) {
		return;
	}

	$campaigns = akb_marketing_get_campaigns();
	if ( empty( $campaigns[ $campaign_id ] ) ) {
		return;
	}

	// Only finalize if still in 'sending' state
	if ( ( $campaigns[ $campaign_id ]['status'] ?? '' ) !== 'sending' ) {
		return;
	}

	$campaigns[ $campaign_id ]['status']  = 'sent';
	$campaigns[ $campaign_id ]['sent_at'] = time();
	akb_marketing_save_campaigns( $campaigns );

	// Clean up recipient queue
	delete_option( "akb_mkt_queue_{$campaign_id}" );

	// Trigger recurrence scheduling
	do_action( 'akb_marketing_after_send', $campaign_id );
}

// WP-Cron fallback for initial dispatch
add_action(
	'akb_marketing_execute_campaign_wpcron',
	function ( string $campaign_id ): void {
		do_action( 'akb_marketing_execute_campaign', array( 'campaign_id' => $campaign_id ) );
	}
);

// ─── AJAX: Preview count ────────────────────────────────────────
// Migrado al helper akb_ajax_endpoint() con `nonce_field => '_wpnonce'`
// para preservar compatibilidad con wp_nonce_field() del form de creación.
akb_ajax_endpoint(
	'akb_mkt_preview_count',
	array(
		'nonce'       => 'akb_mkt_create',
		'nonce_field' => '_wpnonce',
		'capability'  => 'manage_woocommerce',
		'handler'     => static function ( array $post ): array {
			$segment    = sanitize_text_field( $post['segment'] ?? 'all_buyers' );
			$recipients = akb_marketing_recipients_by_segment( $segment );
			return array( 'count' => count( $recipients ) );
		},
	)
);
