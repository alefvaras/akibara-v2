<?php
/**
 * Header minimalista para el flujo de checkout.
 *
 * Suprime navegación principal, search, wishlist y topbar promocional para
 * reducir fugas durante la conversión (Baymard #2.3 "Minimize distractions").
 * Solo conserva: logo enlace a home, señal de seguridad (SSL) y canal de
 * soporte (WhatsApp). La thank-you page (order-received) usa header.php
 * normal vía `$akb_in_checkout_funnel` en template_include.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html class="no-js" <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0A0A0A">
    <meta name="robots" content="noindex,nofollow"><?php // checkout nunca debe indexarse ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'akb-checkout-minimal' ); ?>>
<?php wp_body_open(); ?>

<a href="#main-content" class="skip-link">Saltar al contenido</a>

<header class="akb-checkout-header" id="site-header" role="banner">
    <div class="akb-checkout-header__inner">
        <!-- Logo -->
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="akb-checkout-header__logo" aria-label="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
            <?php
            if ( file_exists( get_template_directory() . '/assets/img/logo-akibara.webp' ) ) {
                echo '<img src="' . esc_url( AKIBARA_THEME_URI . '/assets/img/logo-akibara.webp' ) . '?v=5" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" width="150" height="60" fetchpriority="high" loading="eager" decoding="async" data-no-lazy="1">';
            } else {
                echo '<img src="' . esc_url( AKIBARA_THEME_URI . '/assets/img/akibara-logo.svg' ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" width="150" height="60">';
            }
            ?>
        </a>

        <!-- Signals: SSL + pagos aceptados + soporte WhatsApp -->
        <div class="akb-checkout-header__signals">
            <span class="akb-checkout-header__ssl" title="Compra 100% segura · SSL cifrado">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Compra segura</span>
            </span>
            <span class="akb-checkout-header__pays" title="Métodos de pago aceptados">
                <span class="akb-checkout-header__pay-chip">Mercado&nbsp;Pago</span>
                <span class="akb-checkout-header__pay-chip">Flow</span>
                <span class="akb-checkout-header__pay-chip">Transferencia</span>
            </span>
            <?php
            $akb_wa_url = function_exists( 'akibara_wa_url' )
                ? akibara_wa_url( 'Hola, tengo una consulta sobre mi compra' )
                : 'https://wa.me/' . ( function_exists('akibara_whatsapp_get_business_number') ? akibara_whatsapp_get_business_number() : '' );
            ?>
            <a href="<?php echo esc_url( $akb_wa_url ); ?>" class="akb-checkout-header__support" target="_blank" rel="noopener" aria-label="Soporte por WhatsApp">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <span>Soporte</span>
            </a>
            <a href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/carrito/' ) ); ?>" class="akb-checkout-header__back" aria-label="Volver al carrito">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                <span>Volver al carrito</span>
            </a>
        </div>
    </div>
</header>
