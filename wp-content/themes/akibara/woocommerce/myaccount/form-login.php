<?php
/**
 * Login / Register — Akibara
 * Custom override: tabs, Google OAuth, Spanish copy, inline validation
 *
 * @package Akibara
 */
defined('ABSPATH') || exit;
do_action('woocommerce_before_customer_login_form');

$google_enabled = !empty(get_option('akibara_google_client_id'));
$active_tab     = (isset($_GET['tab']) && $_GET['tab'] === 'register') ? 'register' : 'login';
$SVG_EYE        = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
$SVG_EYE_OFF    = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
$SVG_LOCK       = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
$SVG_GOOGLE     = '<svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>';
?>
<div class="aki-auth" id="aki-auth">

  <!-- Tabs -->
  <div class="aki-auth__tabs" role="tablist" aria-label="Acceso a tu cuenta">
    <button class="aki-auth__tab <?php echo $active_tab==='login'?'is-active':''; ?>"
            role="tab" data-tab="login" id="aki-tab-login"
            aria-selected="<?php echo $active_tab==='login'?'true':'false'; ?>"
            aria-controls="aki-panel-login">Ingresar</button>
    <button class="aki-auth__tab <?php echo $active_tab==='register'?'is-active':''; ?>"
            role="tab" data-tab="register" id="aki-tab-register"
            aria-selected="<?php echo $active_tab==='register'?'true':'false'; ?>"
            aria-controls="aki-panel-register">Crear cuenta</button>
  </div>

  <!-- ══ LOGIN PANEL ══════════════════════════════════════════════════════ -->
  <div class="aki-auth__panel <?php echo $active_tab==='login'?'is-active':''; ?>"
       id="aki-panel-login" role="tabpanel" aria-labelledby="aki-tab-login">

    <?php if ($google_enabled && function_exists('akibara_google_auth_url')) : ?>
    <a href="<?php echo esc_url(akibara_google_auth_url('login')); ?>" class="aki-auth__google-btn">
      <?php echo $SVG_GOOGLE; ?>
      <span>Continuar con Google</span>
    </a>
    <div class="aki-auth__divider"><span>o ingresa con tu correo</span></div>
    <?php endif; ?>

    <form class="aki-auth__form js-aki-login-form" method="post" novalidate>
      <?php do_action('woocommerce_login_form_start'); ?>

      <div class="aki-auth__field">
        <label class="aki-auth__label" for="username">Correo electrónico</label>
        <input class="aki-auth__input" type="email" name="username" id="username"
               autocomplete="email" placeholder="tu@correo.cl"
               value="<?php echo !empty($_POST['username'])?esc_attr(wp_unslash($_POST['username'])):''; ?>"
               required aria-required="true">
        <span class="aki-auth__err" role="alert"></span>
      </div>

      <div class="aki-auth__field">
        <label class="aki-auth__label" for="password">Contraseña</label>
        <div class="aki-auth__pw-wrap">
          <input class="aki-auth__input" type="password" name="password" id="password"
                 autocomplete="current-password" placeholder="Tu contraseña"
                 required aria-required="true">
          <button type="button" class="aki-auth__pw-eye js-pw-eye"
                  aria-label="Mostrar contraseña" data-target="password">
            <span class="eye-show"><?php echo $SVG_EYE; ?></span>
            <span class="eye-hide" style="display:none"><?php echo $SVG_EYE_OFF; ?></span>
          </button>
        </div>
        <span class="aki-auth__err" role="alert"></span>
      </div>

      <div class="aki-auth__row">
        <label class="aki-auth__check">
          <input type="checkbox" name="rememberme" value="forever">
          <span>Recordarme</span>
        </label>
        <a class="aki-auth__link-sm" href="<?php echo esc_url(wp_lostpassword_url()); ?>">
          ¿No recuerdas tu contraseña?
        </a>
      </div>

      <?php wp_nonce_field('woocommerce-login','woocommerce-login-nonce'); ?>
      <?php do_action('woocommerce_login_form'); ?>

      <button type="submit" name="login" class="btn btn--primary btn--full">
        <span>Ingresar</span>
      </button>

      <p class="aki-auth__trust"><?php echo $SVG_LOCK; ?> Tu información está segura con nosotros</p>
      <?php do_action('woocommerce_login_form_end'); ?>
    </form>

    <div class="aki-auth__divider"><span>o más rápido aún</span></div>

    <div class="aki-auth__magic" id="aki-magic-section">
      <button type="button" class="aki-auth__magic-trigger" id="aki-magic-trigger">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Recibir enlace por correo <small>(sin contraseña)</small>
      </button>
      <form class="aki-auth__magic-form" id="aki-magic-form" style="display:none">
        <div class="aki-auth__field">
          <input class="aki-auth__input" type="email" id="aki-magic-email"
                 autocomplete="email" placeholder="tu@correo.cl" required>
        </div>
        <button type="submit" class="btn btn--primary btn--full" id="aki-magic-btn">
          <span class="aki-magic-text">Enviar enlace mágico</span>
          <span class="aki-magic-loading" style="display:none">Enviando...</span>
        </button>
        <p class="aki-auth__magic-ok" id="aki-magic-ok" style="display:none"></p>
        <p class="aki-auth__magic-err" id="aki-magic-err" style="display:none"></p>
      </form>
    </div>

    <p class="aki-auth__switch">
      ¿No tienes cuenta?
      <button type="button" class="js-aki-tab-switch aki-auth__switch-btn" data-tab="register">Créala aquí →</button>
    </p>
  </div><!-- /login panel -->

  <!-- ══ REGISTER PANEL ════════════════════════════════════════════════ -->
  <div class="aki-auth__panel <?php echo $active_tab==='register'?'is-active':''; ?>"
       id="aki-panel-register" role="tabpanel" aria-labelledby="aki-tab-register">

    <p class="aki-auth__subtitle">Historial de pedidos, favoritos y notificaciones — todo en un lugar.</p>

    <?php if ($google_enabled && function_exists('akibara_google_auth_url')) : ?>
    <a href="<?php echo esc_url(akibara_google_auth_url('register')); ?>" class="aki-auth__google-btn">
      <?php echo $SVG_GOOGLE; ?>
      <span>Registrarse con Google</span>
    </a>
    <div class="aki-auth__divider"><span>o crea tu cuenta con correo</span></div>
    <?php endif; ?>

    <form method="post" class="aki-auth__form js-aki-register-form" novalidate
          <?php do_action('woocommerce_register_form_tag'); ?>>
      <?php do_action('woocommerce_register_form_start'); ?>

      <div class="aki-auth__field">
        <label class="aki-auth__label" for="reg_name">¿Cómo te llamamos?</label>
        <input class="aki-auth__input" type="text" name="billing_first_name" id="reg_name"
               autocomplete="given-name" placeholder="Tu nombre"
               value="<?php echo !empty($_POST['billing_first_name'])?esc_attr(wp_unslash($_POST['billing_first_name'])):''; ?>"
               required aria-required="true">
        <span class="aki-auth__err" role="alert"></span>
      </div>

      <div class="aki-auth__field">
        <label class="aki-auth__label" for="reg_email">Correo electrónico</label>
        <input class="aki-auth__input" type="email" name="email" id="reg_email"
               autocomplete="email" placeholder="tu@correo.cl"
               value="<?php echo !empty($_POST['email'])?esc_attr(wp_unslash($_POST['email'])):''; ?>"
               required aria-required="true">
        <span class="aki-auth__err" role="alert"></span>
      </div>

      <?php if ('no' === get_option('woocommerce_registration_generate_password')) : ?>
      <div class="aki-auth__field">
        <label class="aki-auth__label" for="reg_password">Contraseña <small>mínimo 8 caracteres</small></label>
        <div class="aki-auth__pw-wrap">
          <input class="aki-auth__input" type="password" name="password" id="reg_password"
                 autocomplete="new-password" placeholder="Elige una contraseña segura"
                 required aria-required="true">
          <button type="button" class="aki-auth__pw-eye js-pw-eye"
                  aria-label="Mostrar contraseña" data-target="reg_password">
            <span class="eye-show"><?php echo $SVG_EYE; ?></span>
            <span class="eye-hide" style="display:none"><?php echo $SVG_EYE_OFF; ?></span>
          </button>
        </div>
        <div class="aki-auth__strength" id="aki-pw-strength">
          <div class="aki-auth__strength-track"><div class="aki-auth__strength-bar" id="aki-pw-bar"></div></div>
          <span class="aki-auth__strength-txt" id="aki-pw-txt"></span>
        </div>
        <span class="aki-auth__err" role="alert"></span>
      </div>
      <?php else : ?>
      <p class="aki-auth__hint">
        &#9993; Te enviaremos un link por correo para establecer tu contraseña.
      </p>
      <?php endif; ?>

      <?php do_action('woocommerce_register_form'); ?>

      <label class="aki-auth__check aki-auth__check--terms">
        <input type="checkbox" name="akibara_terms" id="akibara_terms" required>
        <span>Acepto los <a href="/terminos-y-condiciones/" target="_blank" rel="noopener">Términos de uso</a>
          y la <a href="/privacidad/" target="_blank" rel="noopener">Política de privacidad</a></span>
      </label>

      <?php wp_nonce_field('woocommerce-register','woocommerce-register-nonce'); ?>

      <button type="submit" name="register" class="btn btn--primary btn--full">
        <span>Crear mi cuenta</span>
      </button>

      <p class="aki-auth__trust"><?php echo $SVG_LOCK; ?> Tu información está segura con nosotros</p>
      <?php do_action('woocommerce_register_form_end'); ?>
    </form>

    <p class="aki-auth__switch">
      ¿Ya tienes cuenta?
      <button type="button" class="js-aki-tab-switch aki-auth__switch-btn" data-tab="login">Ingresa aquí →</button>
    </p>
  </div><!-- /register panel -->

</div><!-- /aki-auth -->
<?php do_action('woocommerce_after_customer_login_form'); ?>
