<?php
/**
 * Akibara Marketing — Welcome Discount v1.0.0
 *
 * Descuento de bienvenida para suscriptores de newsletter con anti-abuso robusto:
 *   - Double opt-in obligatorio (configurable)
 *   - Blacklist de ~40 dominios de email temporales + lista custom
 *   - Rate limit por IP (3/dia por defecto)
 *   - Captcha matematico server-side (sin dependencias externas)
 *   - Validacion RUT unico en checkout (solo clientes nuevos — Modulo 11)
 *   - Email uniqueness (un cupon por direccion de email, para siempre)
 *   - Delivery fingerprint (soft check, log + notificacion admin, no bloquea)
 *   - Audit log completo en wp_akb_wd_log
 *
 * OFF POR DEFECTO. Activar desde WooCommerce → Bienvenida una vez
 * que el formulario de newsletter este listo en el frontend.
 *
 * Lifted from server-snapshot/.../modules/welcome-discount/module.php v1.1.0
 * Adaptations:
 *   - Load guard changed: AKIBARA_V10_LOADED → AKB_MARKETING_LOADED
 *   - AKIBARA_WD_DIR uses plugin dir path (not monolithic plugin path)
 *   - CSS URL uses AKB_MARKETING_URL constant
 *   - DB management: akb_wd_create_tables() still called locally here
 *     (wp_akb_wd_subscriptions and wp_akb_wd_log are WD-only tables,
 *      not included in the central akibara-marketing.php dbDelta which
 *      only owns: wp_akb_campaigns, wp_akb_email_log, wp_akb_referrals)
 *
 * @package    Akibara\Marketing
 * @subpackage WelcomeDiscount
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;
if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKIBARA_WD_LOADED' ) ) {
	return;
}

define( 'AKIBARA_WD_LOADED', '1.1.0' );
define( 'AKIBARA_WD_DIR', __DIR__ . '/' );

// Infrastructure classes — always loaded so:
// (a) DB tables are created on first deploy
// (b) Settings and metrics are accessible from admin even when module is off
require_once __DIR__ . '/class-wd-db.php';
require_once __DIR__ . '/class-wd-settings.php';
require_once __DIR__ . '/class-wd-log.php';

// DB setup — idempotent. Runs dbDelta only when AKB_WD_DB_VERSION bumps.
// wp_akb_wd_subscriptions and wp_akb_wd_log are WD-specific tables — not
// part of the central akibara-marketing.php dbDelta (which owns campaigns,
// email_log, referrals). Managed here to keep concerns separated.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( get_option( 'akb_wd_db_version', '0' ) !== AKB_WD_DB_VERSION ) {
			akb_wd_create_tables();
			update_option( 'akb_wd_db_version', AKB_WD_DB_VERSION );
		}
	},
	5
);

// Admin page — always available so the admin can toggle the module on/off
if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
	new Akibara_WD_Admin();
}

// ─── Feature flag — disabled by default ──────────────────────────
// Deploy safely; activate from WC → Bienvenida settings page.
if ( ! get_option( 'akibara_wd_enabled', 0 ) ) {
	return;
}
// ─────────────────────────────────────────────────────────────────

// Suppress the simpler regular popup when WD popup is active — avoid two competing overlays.
add_filter( 'akibara_popup_should_render', '__return_false', 5 );

// Load business logic classes
require_once __DIR__ . '/class-wd-token.php';
require_once __DIR__ . '/class-wd-coupon.php';
require_once __DIR__ . '/class-wd-email.php';
require_once __DIR__ . '/class-wd-subscribe.php';
require_once __DIR__ . '/class-wd-validator.php';

// ══════════════════════════════════════════════════════════════════
// Main class — Bootstrap
// ══════════════════════════════════════════════════════════════════

if ( ! class_exists( 'Akibara_Welcome_Discount' ) ) {

	final class Akibara_Welcome_Discount {

		const VERSION = '1.0.0';

		private static $instance = null;

		/** @var Akibara_WD_Validator */
		public $validator = null;

		public static function instance(): self {
			if ( self::$instance === null ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'init' ), 25 );
		}

		public function init(): void {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			$this->validator = new Akibara_WD_Validator();
			$this->validator->register_hooks();

			// Confirmation link: /?akb_wd_confirm={token}
			add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
			add_action( 'template_redirect', array( $this, 'handle_confirmation' ) );

			// Frontend popup
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_popup_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_popup' ), 50 );

			// AJAX endpoints
			add_action( 'wp_ajax_nopriv_akb_wd_captcha', array( $this, 'ajax_captcha' ) );
			add_action( 'wp_ajax_akb_wd_captcha', array( $this, 'ajax_captcha' ) );
			add_action( 'wp_ajax_nopriv_akb_wd_subscribe', array( $this, 'ajax_subscribe' ) );
			add_action( 'wp_ajax_akb_wd_subscribe', array( $this, 'ajax_subscribe' ) );
		}

		// ─── Query vars ──────────────────────────────────────────────────

		public function add_query_vars( array $vars ): array {
			$vars[] = 'akb_wd_confirm';
			return $vars;
		}

		// ─── Confirmation handler (email click) ─────────────────────────

		public function handle_confirmation(): void {
			$token = get_query_var( 'akb_wd_confirm', '' );
			if ( ! $token ) {
				return;
			}

			$result = Akibara_WD_Subscribe::confirm( rawurldecode( (string) $token ) );

			if ( is_wp_error( $result ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'akb_wd_status' => 'error',
							'reason'        => $result->get_error_code(),
						),
						home_url( '/' )
					)
				);
			} else {
				wp_safe_redirect( add_query_arg( 'akb_wd_status', 'confirmed', home_url( '/' ) ) );
			}
			exit;
		}

		// ─── Status notice (minimal frontend) ───────────────────────────

		public function render_status_notice(): void {
			$status = sanitize_key( $_GET['akb_wd_status'] ?? '' );
			if ( ! $status ) {
				return;
			}

			if ( $status === 'confirmed' ) {
				echo '<div id="akb-wd-notice" style="position:fixed;bottom:24px;right:24px;'
					. 'background:#fff;border-left:4px solid #7c3aed;padding:16px 20px;'
					. 'box-shadow:0 4px 20px rgba(0,0,0,.12);border-radius:6px;z-index:9999;'
					. 'max-width:340px;font-family:sans-serif">'
					. '<strong style="display:block;margin-bottom:6px">¡Bienvenido/a!</strong>'
					. '<span style="font-size:14px;color:#444">Tu cupon fue enviado a tu email. ¡Revisalo!</span>'
					. '</div>'
					. '<script>setTimeout(function(){var n=document.getElementById("akb-wd-notice");'
					. 'if(n)n.remove();},6000)</script>';
			} elseif ( $status === 'error' ) {
				$reason = sanitize_key( $_GET['reason'] ?? '' );
				$msg    = $reason === 'expired_token'
					? 'El enlace expiro (valido 48 h). Suscribete de nuevo.'
					: 'El enlace no es valido.';
				echo '<div id="akb-wd-notice" style="position:fixed;bottom:24px;right:24px;'
					. 'background:#fff;border-left:4px solid #dc2626;padding:16px 20px;'
					. 'box-shadow:0 4px 20px rgba(0,0,0,.12);border-radius:6px;z-index:9999;'
					. 'max-width:340px;font-family:sans-serif">'
					. '<strong style="display:block;margin-bottom:6px">Enlace invalido</strong>'
					. '<span style="font-size:14px;color:#444">' . esc_html( $msg ) . '</span>'
					. '</div>'
					. '<script>setTimeout(function(){var n=document.getElementById("akb-wd-notice");'
					. 'if(n)n.remove();},8000)</script>';
			}
		}

		// ─── AJAX: get captcha challenge ─────────────────────────────────

		public function ajax_captcha(): void {
			wp_send_json_success( Akibara_WD_Subscribe::generate_captcha() );
		}

		// ─── AJAX: subscribe ─────────────────────────────────────────────

		public function ajax_subscribe(): void {
			$email      = sanitize_email( $_POST['email'] ?? '' );
			$captcha_id = sanitize_text_field( $_POST['captcha_id'] ?? '' );
			$captcha_an = sanitize_text_field( $_POST['captcha_answer'] ?? '' );
			$ip         = $this->get_client_ip();

			$result = Akibara_WD_Subscribe::subscribe( $email, $ip, $captcha_id, $captcha_an );

			if ( is_wp_error( $result ) ) {
				wp_send_json(
					array(
						'success' => false,
						'message' => $result->get_error_message(),
					)
				);
				return;
			}

			$double_optin = (int) Akibara_WD_Settings::get( 'double_optin', 1 );
			wp_send_json(
				array(
					'success' => true,
					'message' => $double_optin
						? 'Revisa tu email y confirma tu suscripcion para recibir el cupon.'
						: '¡Listo! Tu cupon fue enviado a tu email.',
				)
			);
		}

		// ─── Frontend popup assets ──────────────────────────────────────

		public function enqueue_popup_assets(): void {
			if ( is_admin() ) {
				return;
			}
			if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
				return;
			}

			$css_file = AKIBARA_WD_DIR . 'popup.css';
			$css_url  = defined( 'AKB_MARKETING_URL' )
				? AKB_MARKETING_URL . 'modules/welcome-discount/popup.css'
				: plugin_dir_url( __FILE__ ) . 'popup.css';
			$version  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : self::VERSION;

			wp_enqueue_style( 'akibara-wd-popup', $css_url, array(), $version );
		}

		// ─── Frontend popup render ───────────────────────────────────────

		public function render_popup(): void {
			// Always render status notices (confirmation/error from email click).
			$this->render_status_notice();

			if ( is_admin() ) {
				return;
			}

			// ── WooCommerce page suppression ──────────────────────────────
			if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
				return;
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
				return;
			}

			// ── Cookie: already subscribed (permanent) ────────────────────
			if ( isset( $_COOKIE['akibara_wd_subscribed'] ) ) {
				return;
			}
			// Already subscribed via regular popup (has PRIMERACOMPRA10)
			if ( isset( $_COOKIE['akibara_popup_seen'] ) ) {
				return;
			}

			// ── Cookie: dismissed within last 7 days ──────────────────────
			$dismissed = (int) ( $_COOKIE['akibara_wd_dismissed_at'] ?? 0 );
			if ( $dismissed && ( time() - $dismissed ) < 7 * DAY_IN_SECONDS ) {
				return;
			}

			// ── Referral link — user already has a referral coupon ────────
			if ( ! empty( $_COOKIE['akb_ref'] ) ) {
				return;
			}

			// ── Logged-in customer with orders or existing WD subscription ─
			if ( is_user_logged_in() && function_exists( 'wc_get_customer_order_count' ) ) {
				$user = wp_get_current_user();
				if ( wc_get_customer_order_count( $user->ID ) > 0 ) {
					return;
				}
				if ( class_exists( 'Akibara_WD_Coupon' ) && Akibara_WD_Coupon::get_for_email( $user->user_email ) ) {
					return;
				}
			}

			$is_https = is_ssl();
			$ajaxurl  = esc_url( admin_url( 'admin-ajax.php' ) );
			?>
			<div id="akb-wd-announcer" class="akb-wd-popup__sr-only" aria-live="polite" aria-atomic="true"></div>
			<div id="akb-wd-popup" class="akb-wd-popup" role="dialog" aria-modal="true" aria-labelledby="akb-wd-title" style="display:none">
				<div class="akb-wd-popup__overlay"></div>
				<div class="akb-wd-popup__card" tabindex="-1">
					<button class="akb-wd-popup__close" aria-label="Cerrar">&times;</button>

					<!-- Step 1: Email -->
					<div id="akb-wd-s1" class="akb-wd-popup__step">
						<div class="akb-wd-popup__badge">10% OFF</div>
						<h2 class="akb-wd-popup__title" id="akb-wd-title">Tu primera compra con descuento</h2>
						<p class="akb-wd-popup__desc">Cupon unico para tu email. <strong>Solo clientes nuevos.</strong></p>
						<form id="akb-wd-email-form" class="akb-wd-popup__form" novalidate>
							<input type="email" id="akb-wd-email" name="email" placeholder="tu@email.com" required
								class="akb-wd-popup__input" autocomplete="email" aria-label="Tu email">
							<!-- Honeypot anti-bot -->
							<input type="text" name="website_url" value="" autocomplete="off" tabindex="-1" aria-hidden="true"
								style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
							<button type="submit" class="akb-wd-popup__btn">
								<span class="akb-wd-popup__btn-text">Obtener mi descuento</span>
								<span class="akb-wd-popup__btn-loading" aria-hidden="true" style="display:none">Un momento…</span>
							</button>
						</form>
						<div id="akb-wd-email-error" class="akb-wd-popup__error" role="alert" aria-live="assertive"></div>
						<p class="akb-wd-popup__legal">Cupon unico · 30 dias de validez · Cero spam.</p>
						<button type="button" class="akb-wd-popup__no-thanks">No gracias</button>
					</div>

					<!-- Step 2: Captcha -->
					<div id="akb-wd-s2" class="akb-wd-popup__step" style="display:none">
						<h2 class="akb-wd-popup__title">Un paso mas</h2>
						<p class="akb-wd-popup__desc">Confirmemos que eres humano:</p>
						<p class="akb-wd-popup__captcha-q" id="akb-wd-cap-q" aria-live="polite"></p>
						<form id="akb-wd-cap-form" class="akb-wd-popup__form" novalidate>
							<input type="number" name="captcha_answer" id="akb-wd-cap-ans" placeholder="Respuesta"
								required class="akb-wd-popup__input" autocomplete="off" inputmode="numeric"
								aria-label="Respuesta matematica" aria-describedby="akb-wd-cap-q">
							<button type="submit" class="akb-wd-popup__btn">
								<span class="akb-wd-popup__btn-text">Confirmar</span>
								<span class="akb-wd-popup__btn-loading" aria-hidden="true" style="display:none">Enviando…</span>
							</button>
						</form>
						<div id="akb-wd-cap-error" class="akb-wd-popup__error" role="alert" aria-live="assertive"></div>
					</div>

					<!-- Step 3: Success -->
					<div id="akb-wd-s3" class="akb-wd-popup__step" style="display:none">
						<h2 class="akb-wd-popup__title">¡Revisa tu email!</h2>
						<p class="akb-wd-popup__desc" id="akb-wd-success-msg">
							Enviamos un enlace de confirmacion. Haz clic en el para recibir tu cupon al instante.
						</p>
					</div>
				</div>
			</div>

			<script id="akb-wd-inline" data-no-optimize="1">
			(function(){
				var popup=document.getElementById('akb-wd-popup');
				if(!popup)return;

				function shouldSuppress(){
					try{
						if(navigator.doNotTrack==='1'||window.doNotTrack==='1') return true;
						if(localStorage.getItem('akibara_wd_subscribed')==='1') return true;
						if(localStorage.getItem('akibara_popup_subscribed')==='1') return true;
						if(localStorage.getItem('akibara_newsletter_subscribed')==='1') return true;
						var d=localStorage.getItem('akibara_wd_dismissed_at');
						if(d){var age=Date.now()-parseInt(d,10);if(age<7*86400000)return true;localStorage.removeItem('akibara_wd_dismissed_at');}
						var s=localStorage.getItem('akibara_wd_shown_at');
						if(s){var age2=Date.now()-parseInt(s,10);if(age2<7*86400000)return true;localStorage.removeItem('akibara_wd_shown_at');}
					}catch(e){}
					return false;
				}

				if(shouldSuppress()){popup.parentNode&&popup.parentNode.removeChild(popup);return;}

				var shown=false,triggerType=null,prevFocus=null,inertedEls=[];
				var isMobile=window.matchMedia('(max-width:640px)').matches;
				var ajaxurl='<?php echo esc_js( $ajaxurl ); ?>';
				var captchaId='',emailVal='';
				var isHttps=<?php echo $is_https ? 'true' : 'false'; ?>;

				function setInert(on){
					var els=[document.querySelector('main,#main-content'),document.querySelector('header.site-header,#site-header'),document.querySelector('footer,.site-footer')].filter(Boolean);
					if(on){inertedEls=els;els.forEach(function(e){e.setAttribute('aria-hidden','true');if('inert' in e)e.inert=true;});}
					else{inertedEls.forEach(function(e){e.removeAttribute('aria-hidden');if('inert' in e)e.inert=false;});inertedEls=[];}
				}

				function focusables(){return popup.querySelectorAll('button:not([disabled]),[href],input:not([disabled]):not([type="hidden"]),[tabindex]:not([tabindex="-1"])');}
				function trapTab(e){
					if(e.key!=='Tab'||!shown)return;
					var f=focusables();if(!f.length)return;
					var first=f[0],last=f[f.length-1];
					if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
					else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
				}

				function announce(msg){
					var a=document.getElementById('akb-wd-announcer');
					if(!a)return;
					a.textContent='';
					setTimeout(function(){a.textContent=msg;},100);
				}

				function showPopup(trigger){
					if(shown)return;
					shown=true;triggerType=trigger;prevFocus=document.activeElement;
					popup.style.display='flex';
					requestAnimationFrame(function(){
						popup.classList.add('show');
						setInert(true);
						var inp=document.getElementById('akb-wd-email');
						if(inp)inp.focus();
					});
					document.addEventListener('keydown',trapTab);
					clearTimeout(timer);
					window.removeEventListener('scroll',scrollCheck);
					var maxAge=7*86400;
					var base=';path=/;max-age='+maxAge+';SameSite=Lax'+(isHttps?';Secure':'');
					document.cookie='akibara_wd_shown=1'+base;
					try{localStorage.setItem('akibara_wd_shown_at',''+Date.now());}catch(e){}
					announce('Oferta especial: 10% de descuento en tu primera compra.');
				}

				function closePopup(method){
					popup.classList.remove('show');
					setTimeout(function(){popup.style.display='none';},300);
					setInert(false);
					document.removeEventListener('keydown',trapTab);
					if(prevFocus&&prevFocus.focus)prevFocus.focus();
					var ts=Math.floor(Date.now()/1000);
					var base=';path=/;max-age='+(7*86400)+';SameSite=Lax'+(isHttps?';Secure':'');
					document.cookie='akibara_wd_dismissed_at='+ts+base;
					try{localStorage.setItem('akibara_wd_dismissed_at',''+Date.now());}catch(e){}
				}

				var timer=setTimeout(function(){showPopup('timer');},30000);
				var scrollCheck=function(){
					var sc=document.documentElement.scrollHeight-window.innerHeight;
					if(sc>0&&document.documentElement.scrollTop/sc>0.5)showPopup('scroll');
				};
				window.addEventListener('scroll',scrollCheck,{passive:true});

				if(!isMobile){
					var ef=false;
					document.addEventListener('mouseleave',function(e){
						if(ef||shown)return;
						if(e.clientY<=0){ef=true;showPopup('exit_intent');}
					});
				}

				popup.querySelector('.akb-wd-popup__overlay').addEventListener('click',function(){closePopup('overlay');});
				popup.querySelector('.akb-wd-popup__close').addEventListener('click',function(){closePopup('close_button');});
				document.addEventListener('keydown',function(e){if(e.key==='Escape'&&shown)closePopup('escape');});
				var nt=popup.querySelector('.akb-wd-popup__no-thanks');
				if(nt)nt.addEventListener('click',function(){closePopup('no_thanks');});

				function showStep(id){
					['akb-wd-s1','akb-wd-s2','akb-wd-s3'].forEach(function(sid){
						var el=document.getElementById(sid);
						if(el)el.style.display=(sid===id)?'block':'none';
					});
					var inp=document.querySelector('#'+id+' input, #'+id+' .akb-wd-popup__btn');
					if(inp)setTimeout(function(){inp.focus();},60);
				}

				var emailForm=document.getElementById('akb-wd-email-form');
				var emailError=document.getElementById('akb-wd-email-error');
				var submittingEmail=false;

				emailForm&&emailForm.addEventListener('submit',function(e){
					e.preventDefault();
					if(submittingEmail)return;
					if(emailForm.querySelector('[name="website_url"]').value)return;

					emailVal=document.getElementById('akb-wd-email').value.trim();
					if(!emailVal||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)){
						emailError.textContent='Ingresa un email valido.';
						return;
					}
					emailError.textContent='';

					var btn=emailForm.querySelector('.akb-wd-popup__btn');
					var bt=btn.querySelector('.akb-wd-popup__btn-text');
					var bl=btn.querySelector('.akb-wd-popup__btn-loading');
					submittingEmail=true;bt.style.display='none';bl.style.display='inline';

					fetch(ajaxurl+'?action=akb_wd_captcha')
					.then(function(r){return r.json();})
					.then(function(data){
						if(data.success&&data.data){
							captchaId=data.data.id;
							var q=document.getElementById('akb-wd-cap-q');
							if(q)q.textContent=data.data.question;
							showStep('akb-wd-s2');
						} else {
							emailError.textContent='Error al obtener el desafio. Intenta de nuevo.';
						}
					})
					.catch(function(){emailError.textContent='Error de conexion. Intenta de nuevo.';})
					.finally(function(){bt.style.display='inline';bl.style.display='none';submittingEmail=false;});
				});

				var capForm=document.getElementById('akb-wd-cap-form');
				var capError=document.getElementById('akb-wd-cap-error');
				var submittingCap=false;

				capForm&&capForm.addEventListener('submit',function(e){
					e.preventDefault();
					if(submittingCap)return;

					var ans=document.getElementById('akb-wd-cap-ans').value.trim();
					if(!ans){capError.textContent='Ingresa la respuesta.';return;}
					capError.textContent='';

					var btn=capForm.querySelector('.akb-wd-popup__btn');
					var bt=btn.querySelector('.akb-wd-popup__btn-text');
					var bl=btn.querySelector('.akb-wd-popup__btn-loading');
					submittingCap=true;bt.style.display='none';bl.style.display='inline';

					var fd=new FormData();
					fd.append('action','akb_wd_subscribe');
					fd.append('email',emailVal);
					fd.append('captcha_id',captchaId);
					fd.append('captcha_answer',ans);

					fetch(ajaxurl,{method:'POST',body:fd})
					.then(function(r){return r.json();})
					.then(function(data){
						if(data.success){
							var base=';path=/;max-age='+(365*86400)+';SameSite=Lax'+(isHttps?';Secure':'');
							document.cookie='akibara_wd_subscribed=1'+base;
							try{localStorage.setItem('akibara_wd_subscribed','1');}catch(e){}
							var sm=document.getElementById('akb-wd-success-msg');
							if(sm&&data.message)sm.textContent=data.message;
							showStep('akb-wd-s3');
							announce('¡Suscripcion exitosa! Revisa tu email para recibir tu cupon.');
						} else {
							var msg=data.message||'Error al suscribir. Intenta de nuevo.';
							capError.textContent=msg;
							if(msg.indexOf('captcha')!==-1||msg.indexOf('Respuesta')!==-1||msg.indexOf('incorrect')!==-1){
								setTimeout(function(){
									fetch(ajaxurl+'?action=akb_wd_captcha')
									.then(function(r){return r.json();})
									.then(function(nd){
										if(nd.success&&nd.data){
											captchaId=nd.data.id;
											var q=document.getElementById('akb-wd-cap-q');
											if(q)q.textContent=nd.data.question;
											capError.textContent='Respuesta incorrecta. Nueva pregunta:';
											var i=document.getElementById('akb-wd-cap-ans');
											if(i){i.value='';i.focus();}
										}
									});
								},500);
							}
						}
					})
					.catch(function(){capError.textContent='Error de conexion. Intenta de nuevo.';})
					.finally(function(){bt.style.display='inline';bl.style.display='none';submittingCap=false;});
				});

			})();
			</script>
			<?php
		}

		// ─── Helpers ────────────────────────────────────────────────────

		private function get_client_ip(): string {
			$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
			foreach ( $keys as $key ) {
				if ( ! empty( $_SERVER[ $key ] ) ) {
					$ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
					if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
						return $ip;
					}
				}
			}
			return '0.0.0.0';
		}
	}

	Akibara_Welcome_Discount::instance();

} // end class_exists guard
