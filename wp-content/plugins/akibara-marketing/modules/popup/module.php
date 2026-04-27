<?php
/**
 * Akibara Marketing — Módulo Popup Bienvenida
 *
 * Lifted from server-snapshot plugins/akibara/modules/popup/module.php (v3.1.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * coupon-antiabuse.php adapted similarly.
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * Popup de captura de email para nuevos visitantes:
 *  - Redirige a /bienvenida/ tras suscripción exitosa
 *  - Suscribe a Brevo lista configurable
 *  - Envía template de bienvenida via Brevo API
 *  - Cookie de 30 días para no repetir
 *  - Rate limiting + honeypot anti-bot
 *
 * @package    Akibara\Marketing
 * @subpackage Popup
 * @version    3.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

require_once __DIR__ . '/coupon-antiabuse.php';

if ( defined( 'AKB_MARKETING_POPUP_LOADED' ) ) {
	return;
}

// Feature flag — kill switch per ADR-005.
if ( function_exists( 'akb_is_module_enabled' ) && ! akb_is_module_enabled( 'popup' ) ) {
	return;
}

define( 'AKB_MARKETING_POPUP_LOADED', '3.1.0' );

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_popup_sentinel' ) ) {

	function akb_marketing_popup_sentinel(): bool {
		return defined( 'AKB_MARKETING_POPUP_LOADED' );
	}

	// ── ASSETS ────────────────────────────────────────────────────────────────

	add_action( 'wp_enqueue_scripts', 'akibara_popup_enqueue_assets' );

	function akibara_popup_enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! apply_filters( 'akibara_popup_should_render', get_option( 'akibara_popup_enabled', true ) ) ) {
			return;
		}

		$css_file = AKB_MARKETING_DIR . 'modules/popup/popup.css';
		$css_url  = AKB_MARKETING_URL . 'modules/popup/popup.css';
		$version  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : AKB_MARKETING_VERSION;

		wp_enqueue_style( 'akibara-popup', $css_url, array( 'akibara-layout' ), $version );
	}

	// ── FRONTEND — Renderizar popup ───────────────────────────────────────────

	add_action( 'wp_footer', 'akibara_popup_render', 50 );

	function akibara_popup_render(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! apply_filters( 'akibara_popup_should_render', get_option( 'akibara_popup_enabled', true ) ) ) {
			return;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return;
		}
		if ( isset( $_COOKIE['akibara_popup_seen'] ) || isset( $_COOKIE['akibara_popup_shown'] ) ) {
			return;
		}
		if ( ! empty( $_COOKIE['akb_ref'] ) ) {
			return;
		}
		if ( is_user_logged_in() && function_exists( 'wc_get_customer_order_count' ) ) {
			$user = wp_get_current_user();
			if ( wc_get_customer_order_count( $user->ID ) > 0 ) {
				return;
			}
			$subs_ts = get_option( 'akb_popup_subs_ts', array() );
			if ( is_array( $subs_ts ) && ! empty( $user->user_email ) && isset( $subs_ts[ strtolower( $user->user_email ) ] ) ) {
				return;
			}
		}

		/** @var array{badge:string,title:string,desc:string,cta:string,legal:string} $popup_copy */
		$popup_copy = apply_filters(
			'akibara_popup_variants',
			array(
				'badge' => '10% OFF',
				'title' => 'Tu primera compra con descuento',
				'desc'  => 'Suscríbete y recibe un <strong>10% de descuento</strong> en tu primer pedido.',
				'cta'   => 'Quiero mi 10%',
				'legal' => 'Cero spam. Drops, preventas y restocks.',
			)
		);

		$delay_ms = (int) get_option( 'akibara_popup_delay', 15 ) * 1000;
		$nonce    = wp_create_nonce( 'akibara_popup' );
		$is_https = is_ssl();
		?>
		<div id="aki-popup-announcer" class="aki-popup__sr-only" aria-live="polite" aria-atomic="true"></div>
		<div id="aki-popup" class="aki-popup" role="dialog" aria-modal="true" aria-labelledby="aki-popup-title" style="display:none">
			<div class="aki-popup__overlay"></div>
			<div class="aki-popup__card" tabindex="-1">
				<button class="aki-popup__close" aria-label="Cerrar">&times;</button>
				<div class="aki-popup__form-state" id="aki-popup-form">
					<div class="aki-popup__badge"><?php echo esc_html( $popup_copy['badge'] ); ?></div>
					<h2 class="aki-popup__title" id="aki-popup-title"><?php echo esc_html( $popup_copy['title'] ); ?></h2>
					<p class="aki-popup__desc"><?php echo wp_kses( $popup_copy['desc'], array( 'strong' => array() ) ); ?></p>
					<form class="aki-popup__form" id="aki-popup-subscribe">
						<input type="email" name="email" placeholder="tu@email.com" required class="aki-popup__input" autocomplete="email">
						<input type="text" name="website_url" value="" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
						<button type="submit" class="aki-popup__btn">
							<span class="aki-popup__btn-text"><?php echo esc_html( $popup_copy['cta'] ); ?></span>
							<span class="aki-popup__btn-loading" style="display:none">Enviando...</span>
						</button>
					</form>
					<p class="aki-popup__legal"><?php echo esc_html( $popup_copy['legal'] ); ?></p>
					<button type="button" class="aki-popup__no-thanks" aria-label="Cerrar sin suscribirme">No gracias</button>
				</div>
			</div>
		</div>

		<script id="akb-popup-inline" data-no-optimize="1">
		(function(){
			var popup=document.getElementById('aki-popup');
			if(!popup)return;
			function shouldSuppressPopup(){
				try{
					if(navigator.doNotTrack==='1'||window.doNotTrack==='1') return true;
					if(localStorage.getItem('akibara_newsletter_subscribed')==='1') return true;
					if(localStorage.getItem('akibara_popup_subscribed')==='1') return true;
					if(document.cookie.indexOf('akibara_popup_seen')!==-1) return true;
					if(document.cookie.indexOf('akibara_popup_shown')!==-1) return true;
					if(document.cookie.indexOf('akb_ref=')!==-1) return true;
					var seen=localStorage.getItem('akibara_popup_seen');
					if(seen){var elapsed=Date.now()-parseInt(seen,10);if(elapsed<7*86400000)return true;localStorage.removeItem('akibara_popup_seen');}
					var shownAt=localStorage.getItem('akibara_popup_shown_at');
					if(shownAt){var age=Date.now()-parseInt(shownAt,10);if(age<7*86400000)return true;localStorage.removeItem('akibara_popup_shown_at');}
				}catch(e){}
				return false;
			}
			if(shouldSuppressPopup()){popup.parentNode.removeChild(popup);return;}

			var isMobile=window.matchMedia('(max-width: 640px)').matches;
			var deviceCat=isMobile?'mobile':'desktop';
			function track(eventName,params,onSent){
				var payload=Object.assign({event_category:'popup_welcome',device_category:deviceCat},params||{});
				var done=false;
				function finish(){if(done)return;done=true;if(onSent)onSent();}
				try{if(typeof gtag==='function'){payload.event_callback=finish;payload.event_timeout=600;gtag('event',eventName,payload);setTimeout(finish,700);return;}}catch(e){}
				finish();
			}

			var shown=false,triggerType=null,prevFocus=null,inertedEls=[];
			function setBackgroundInert(on){var main=document.querySelector('main,#main-content'),header=document.querySelector('header.site-header,#site-header'),footer=document.querySelector('footer,.site-footer,.bottom-nav'),targets=[main,header,footer].filter(Boolean);if(on){inertedEls=targets;targets.forEach(function(el){el.setAttribute('aria-hidden','true');if('inert' in el){el.inert=true;}});}else{inertedEls.forEach(function(el){el.removeAttribute('aria-hidden');if('inert' in el){el.inert=false;}});inertedEls=[];}}
			function focusables(){return popup.querySelectorAll('button:not([disabled]),[href],input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])');}
			function trapTab(e){if(e.key!=='Tab'||!shown)return;var list=focusables();if(!list.length)return;var first=list[0],last=list[list.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}

			function showPopup(trigger){
				if(shown)return;shown=true;triggerType=trigger;prevFocus=document.activeElement;
				popup.style.display='flex';
				requestAnimationFrame(function(){popup.classList.add('show');setBackgroundInert(true);var input=popup.querySelector('.aki-popup__input');if(input){input.focus();}});
				document.addEventListener('keydown',trapTab);clearTimeout(timer);window.removeEventListener('scroll',scrollCheck);
				var shownCookie='akibara_popup_shown=1;path=/;max-age='+60*60*24*7+';SameSite=Lax<?php echo $is_https ? ';Secure' : ''; ?>';
				document.cookie=shownCookie;try{localStorage.setItem('akibara_popup_shown_at',''+Date.now());}catch(e){}
				track('popup_shown',{trigger_type:trigger});
				var ann=document.getElementById('aki-popup-announcer');if(ann){ann.textContent='';setTimeout(function(){ann.textContent='Oferta especial: 10% de descuento en tu primera compra.';},100);}
			}

			var timer=setTimeout(function(){showPopup('timer');},<?php echo (int) $delay_ms; ?>);
			var scrollThreshold=isMobile?0.6:0.3;
			var scrollCheck=function(){var scrollable=document.documentElement.scrollHeight-window.innerHeight;if(scrollable>0&&document.documentElement.scrollTop/scrollable>scrollThreshold){showPopup('scroll');}};
			window.addEventListener('scroll',scrollCheck,{passive:true});
			if(!isMobile){var exitFired=false;document.addEventListener('mouseleave',function(e){if(exitFired||shown)return;if(e.clientY<=0){exitFired=true;showPopup('exit_intent');}});}

			var cookieStr='akibara_popup_seen=1;path=/;max-age='+60*60*24*30+';SameSite=Lax<?php echo $is_https ? ';Secure' : ''; ?>';
			function closePopup(dismissMethod){popup.classList.remove('show');setTimeout(function(){popup.style.display='none';},300);setBackgroundInert(false);document.removeEventListener('keydown',trapTab);if(prevFocus&&typeof prevFocus.focus==='function'){prevFocus.focus();}document.cookie=cookieStr;try{localStorage.setItem('akibara_popup_seen',''+Date.now());}catch(e){}track('popup_dismissed',{trigger_type:triggerType,dismiss_method:dismissMethod||'unknown'});}

			popup.querySelector('.aki-popup__overlay').addEventListener('click',function(){closePopup('overlay');});
			popup.querySelector('.aki-popup__close').addEventListener('click',function(){closePopup('close_button');});
			document.addEventListener('keydown',function(e){if(e.key==='Escape'&&shown)closePopup('escape');});
			var noThanks=popup.querySelector('.aki-popup__no-thanks');if(noThanks)noThanks.addEventListener('click',function(){closePopup('no_thanks');});

			var form=document.getElementById('aki-popup-subscribe'),submitting=false;
			form.addEventListener('submit',function(e){
				e.preventDefault();if(submitting)return;submitting=true;
				var email=form.querySelector('input[name="email"]').value.trim();
				var honeypot=form.querySelector('input[name="website_url"]').value;
				if(honeypot){submitting=false;return;}
				var btn=form.querySelector('.aki-popup__btn-text'),loading=form.querySelector('.aki-popup__btn-loading');
				btn.style.display='none';loading.style.display='inline';
				var fd=new FormData();fd.append('action','akibara_popup_subscribe');fd.append('email',email);fd.append('website_url',honeypot);fd.append('nonce','<?php echo esc_js( $nonce ); ?>');
				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',{method:'POST',body:fd})
				.then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
				.then(function(data){
					if(data.success){document.cookie=cookieStr;try{localStorage.setItem('akibara_popup_subscribed','1');}catch(e){}try{localStorage.setItem('akibara_auto_coupon',data.data.coupon||'PRIMERACOMPRA10');}catch(e){}track('popup_subscribed',{trigger_type:triggerType,coupon_code:data.data.coupon||'PRIMERACOMPRA10'},function(){window.location.href='/bienvenida/';});}
					else{showError(data.data||'Error al suscribir. Intenta de nuevo.');}
				})
				.catch(function(){showError('Error de conexión. Intenta de nuevo.');})
				.finally(function(){btn.style.display='inline';loading.style.display='none';submitting=false;});
			});
			function showError(msg){var err=form.querySelector('.aki-popup__error');if(!err){err=document.createElement('p');err.className='aki-popup__error';form.appendChild(err);}err.textContent=msg;}
		})();
		</script>
		<?php
	}

	// ── AJAX — Suscribir email ────────────────────────────────────────────────

	if ( function_exists( 'akb_ajax_endpoint' ) ) {
		akb_ajax_endpoint(
			'akibara_popup_subscribe',
			array(
				'nonce'      => 'akibara_popup',
				'capability' => null,
				'public'     => true,
				'rate_limit' => array(
					'window' => HOUR_IN_SECONDS,
					'max'    => 5,
				),
				'handler'    => 'akibara_popup_ajax_subscribe',
			)
		);
	}

	function akibara_popup_ajax_subscribe( array $post ): void {
		if ( ! empty( $post['website_url'] ?? '' ) ) {
			wp_send_json_error( 'Solicitud inválida.' );
			return;
		}

		$email = sanitize_email( $post['email'] ?? '' );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( 'Email inválido.' );
			return;
		}

		if ( ! class_exists( 'AkibaraBrevo' ) ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'popup', 'error', 'AkibaraBrevo class not found' );
			}
			wp_send_json_error( 'Configuración incompleta.' );
			return;
		}

		$api_key = \AkibaraBrevo::get_api_key();
		if ( empty( $api_key ) ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'popup', 'error', 'Brevo API key no configurada' );
			}
			wp_send_json_error( 'Configuración incompleta.' );
			return;
		}

		$brevo_list    = (int) get_option( 'akibara_popup_brevo_list', 2 );
		$brevo_tpl     = (int) get_option( 'akibara_popup_brevo_template', 1 );
		$coupon        = get_option( 'akibara_popup_coupon', 'PRIMERACOMPRA10' );
		$brevo_timeout = 10;

		// 1. Crear/actualizar contacto en Brevo
		$response = wp_remote_post(
			'https://api.brevo.com/v3/contacts',
			array(
				'timeout' => $brevo_timeout,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'         => $email,
						'listIds'       => array( $brevo_list ),
						'updateEnabled' => true,
						'attributes'    => array( 'CONTACT_SOURCE' => 'popup_bienvenida' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( function_exists( 'akb_log' ) ) {
				akb_log( 'popup', 'error', 'Brevo contacts API error', array( 'err' => $response->get_error_message() ) );
			}
			wp_send_json_error( 'Error de conexión. Intenta de nuevo.' );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 201 && $code !== 204 ) {
			$fallback = wp_remote_post(
				"https://api.brevo.com/v3/contacts/lists/{$brevo_list}/contacts/add",
				array(
					'timeout' => $brevo_timeout,
					'headers' => array(
						'api-key'      => $api_key,
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode( array( 'emails' => array( $email ) ) ),
				)
			);
			if ( is_wp_error( $fallback ) ) {
				wp_send_json_error( 'No pudimos registrar tu email. Intenta de nuevo.' );
				return;
			}
		}

		// 2. Enviar template de bienvenida
		$email_response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'timeout' => $brevo_timeout,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'templateId' => $brevo_tpl,
						'to'         => array( array( 'email' => \AkibaraBrevo::test_recipient( $email ) ) ),
						'params'     => array( 'COUPON_CODE' => $coupon ),
					)
				),
			)
		);

		if ( is_wp_error( $email_response ) && function_exists( 'akb_log' ) ) {
			akb_log( 'popup', 'error', 'Welcome email WP_Error', array( 'err' => $email_response->get_error_message() ) );
		}

		// 3. Contadores y timestamps
		$count = (int) get_option( 'akibara_popup_sub_count', 0 );
		update_option( 'akibara_popup_sub_count', $count + 1, false );

		$subs_ts = get_option( 'akb_popup_subs_ts', array() );
		if ( ! is_array( $subs_ts ) ) {
			$subs_ts = array();
		}
		$subs_ts[ strtolower( $email ) ] = time();
		$cutoff = time() - ( 30 * DAY_IN_SECONDS );
		foreach ( $subs_ts as $e => $ts ) {
			if ( $ts < $cutoff ) {
				unset( $subs_ts[ $e ] );
			}
		}
		update_option( 'akb_popup_subs_ts', $subs_ts, false );

		// 4. Trigger Welcome Series
		do_action( 'akibara_popup_subscribed', $email, time() );

		wp_send_json_success( array( 'coupon' => $coupon ) );
	}

	// ── AUTO-APPLY COUPON ─────────────────────────────────────────────────────

	add_action( 'wp_footer', 'akibara_auto_apply_coupon_js', 60 );

	function akibara_auto_apply_coupon_js(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<script>
		(function(){
			try{var coupon=localStorage.getItem('akibara_auto_coupon');if(!coupon)return;coupon=coupon.toLowerCase();}catch(e){return;}
			jQuery(document).ready(function($){
				if($('.woocommerce-remove-coupon[data-coupon="'+coupon+'"]').length){localStorage.removeItem('akibara_auto_coupon');return;}
				if(typeof wc_checkout_params==='undefined'||!wc_checkout_params.apply_coupon_nonce)return;
				$.post('/?wc-ajax=apply_coupon',{coupon_code:coupon,security:wc_checkout_params.apply_coupon_nonce},function(){
					$(document.body).trigger('update_checkout');localStorage.removeItem('akibara_auto_coupon');
				});
			});
		})();
		</script>
		<?php
	}

} // end group wrap
