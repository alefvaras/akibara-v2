<?php
/**
 * Microsoft Clarity — session recordings + heatmaps.
 *
 * Activación: definir `AKIBARA_CLARITY_ID` en wp-config.php con el project ID
 * (10-char alphanumeric, ej. 'abc123xyz9') obtenido en https://clarity.microsoft.com
 *
 * Si la constante no existe o está vacía → el script no se imprime (silencio seguro).
 * No se carga en el admin ni en páginas AJAX.
 *
 * Privacidad: los inputs de datos sensibles (email, RUT, teléfono, address)
 * se marcan con `data-clarity-mask="true"` para que Clarity los oculte
 * automáticamente en los recordings. Cumplimiento GDPR/Ley Chilena 19.628.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

/**
 * ¿Está Clarity configurado y debe emitirse en esta request?
 */
function akibara_clarity_enabled(): bool {
    if ( ! defined( 'AKIBARA_CLARITY_ID' ) ) return false;
    $id = trim( (string) AKIBARA_CLARITY_ID );
    if ( $id === '' ) return false;
    if ( is_admin() ) return false;
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) return false;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return false;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return false;
    return true;
}

/**
 * Inyectar el snippet oficial de Microsoft Clarity en el <head>.
 */
add_action( 'wp_head', 'akibara_clarity_snippet', 1 );
function akibara_clarity_snippet(): void {
    if ( ! akibara_clarity_enabled() ) return;
    $id = trim( (string) AKIBARA_CLARITY_ID );
    ?>
<!-- Microsoft Clarity -->
<script type="text/javascript">
(function(c,l,a,r,i,t,y){
    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
    t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
})(window, document, "clarity", "script", "<?php echo esc_js( $id ); ?>");
</script>
    <?php
}

/**
 * Agregar `data-clarity-mask="true"` a inputs sensibles de checkout.
 *
 * Clarity tiene 3 niveles de privacidad: strict (todo masked), balanced (solo
 * inputs sensibles masked, default) y relaxed. El atributo HTML fuerza masking
 * incluso en modo relaxed — capa extra de defensa si alguien cambia el setting.
 *
 * Campos cubiertos: email, RUT, teléfono, dirección, ciudad, postcode.
 */
add_filter( 'woocommerce_checkout_fields', 'akibara_clarity_mask_checkout_fields', 999 );
function akibara_clarity_mask_checkout_fields( array $fields ): array {
    $sensitive_keys = [
        'billing_email', 'billing_rut', 'billing_phone',
        'billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode',
        'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_postcode',
        'billing_first_name', 'billing_last_name',
        'shipping_first_name', 'shipping_last_name',
    ];
    foreach ( $fields as $group => $group_fields ) {
        foreach ( $group_fields as $key => $conf ) {
            if ( ! in_array( $key, $sensitive_keys, true ) ) continue;
            $attrs = $conf['custom_attributes'] ?? [];
            $attrs['data-clarity-mask'] = 'true';
            $fields[ $group ][ $key ]['custom_attributes'] = $attrs;
        }
    }
    return $fields;
}

/**
 * Admin notice: recordatorio si Clarity no está configurado (una sola vez).
 */
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( akibara_clarity_enabled() ) return;
    if ( defined( 'AKIBARA_CLARITY_ID' ) ) return; // definido pero vacío → user decision
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) ) return;

    $dismissed = (int) get_user_meta( get_current_user_id(), 'akb_clarity_notice_dismissed', true );
    if ( $dismissed ) return;
    ?>
    <div class="notice notice-info is-dismissible">
        <p>
            <strong>Akibara — Microsoft Clarity listo para activar.</strong>
            Agrega <code>define( 'AKIBARA_CLARITY_ID', 'tu_project_id' );</code> en
            <code>wp-config.php</code> con el ID de <a href="https://clarity.microsoft.com/" target="_blank" rel="noopener">clarity.microsoft.com</a>
            (gratis, session recordings ilimitados).
        </p>
    </div>
    <?php
} );
