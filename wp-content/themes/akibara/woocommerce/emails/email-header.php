<?php
/**
 * Email Header — Akibara Brand Identity
 * Override of WooCommerce email-header.php
 *
 * Dark theme aligned with akibara.cl design system v3 (Manga Crimson):
 * - Background: #0A0A0A (--aki-black)
 * - Surface: #161618 (--aki-email-surface)
 * - Accent: #D90010 (--aki-red, Manga Crimson)
 * - Text: #F5F5F5 / #A0A0A0 / #666
 *
 * @version 11.0.0
 * @package Akibara
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$store_name = get_bloginfo( 'name', 'display' );

// EM-3: resolver logo con fallback en cascada (prev hardcoded a URL obtusa).
// Prioridad: (1) option akibara_email_logo_url, (2) WC email header_image (si configurado),
// (3) theme custom-logo (Site Identity), (4) URL original como último recurso.
$logo_url = get_option( 'akibara_email_logo_url', '' );
if ( ! $logo_url ) {
	$wc_logo_id = get_option( 'woocommerce_email_header_image', '' );
	if ( $wc_logo_id ) {
		$logo_url = is_numeric( $wc_logo_id ) ? wp_get_attachment_url( (int) $wc_logo_id ) : $wc_logo_id;
	}
}
if ( ! $logo_url && has_custom_logo() ) {
	$custom_logo_id = get_theme_mod( 'custom_logo' );
	$logo_url       = wp_get_attachment_image_url( $custom_logo_id, 'medium' );
}
if ( ! $logo_url ) {
	$logo_url = 'https://akibara.cl/wp-content/uploads/2022/02/1000000826-2-scaled-e1758692190673.png';
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="es">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="dark light" />
    <meta name="supported-color-schemes" content="dark light" />
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:AllowPNG/>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <title><?php echo esc_html( $store_name ); ?></title>
    <style type="text/css">
        :root { color-scheme: dark light; supported-color-schemes: dark light; }
        body, table, td, p, a, li { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#0A0A0A; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
    <?php
    // Preheader — primer texto visible en la bandeja (inbox preview), oculto en el email.
    // Cada template lo registra via: add_filter('akibara_email_preheader', fn() => '...').
    //
    // FIX 2026-04-26: removido segundo arg `$email` del apply_filters. Sentry issue
    // "Object of class WC_Email_New_Order could not be converted to string" reportaba
    // crashes cuando un listener third-party (probable Brevo SMTP / MP / akibara-sentry-bridge
    // legacy ya removido) registraba con firma `function($text, $email)` que concatenaba el
    // objeto WC_Email — PHP intentaba __toString() y crasheaba (no implementado en WC_Email).
    // Ningún listener actual usa el segundo arg → safe to drop. Defensivo.
    $preheader_text = (string) apply_filters( 'akibara_email_preheader', '' );
    if ( $preheader_text ) :
    ?>
    <div style="display:none;font-size:1px;color:#0A0A0A;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;visibility:hidden;"><?php echo esc_html( $preheader_text ); ?>&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;</div>
    <?php endif; ?>
    <!-- Outer wrapper -->
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="outer_wrapper" style="background-color:#0A0A0A;">
        <tr>
            <td align="center" style="padding:24px 16px;">
                <!--[if mso]>
                <table border="0" cellpadding="0" cellspacing="0" width="600" align="center"><tr><td>
                <![endif]-->
                <div style="width:100%; max-width:600px; margin:0 auto;">
                    <!-- Inner container -->
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="inner_wrapper" style="background-color:#161618; border:1px solid #2A2A2E; border-radius:4px;">
                        <!-- Manga Crimson accent top bar (v3) -->
                        <tr>
                            <td style="background-color:#D90010; height:3px; font-size:1px; line-height:1px;">&nbsp;</td>
                        </tr>
                        <!-- Logo -->
                        <tr>
                            <td id="template_header_image" align="center" style="padding:28px 32px 16px;">
                                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" width="160" style="display:block; width:160px; height:auto; max-width:100%;" />
                            </td>
                        </tr>
                        <!-- Heading -->
                        <tr>
                            <td id="header_wrapper" style="padding:0 32px 8px;">
                                <h1 style="margin:0; color:#FFFFFF; font-family:'Helvetica Neue', Arial, sans-serif; font-size:24px; font-weight:700; line-height:1.3; text-align:center; letter-spacing:-0.3px;"><?php echo esc_html( $email_heading ); ?></h1>
                            </td>
                        </tr>
                        <!-- Thin separator -->
                        <tr>
                            <td style="padding:0 32px;">
                                <div style="border-bottom:1px solid #2A2A2E;">&nbsp;</div>
                            </td>
                        </tr>
                        <!-- Body content start -->
                        <tr>
                            <td id="body_content" style="padding:24px 32px 32px;">
                                <div id="body_content_inner" style="color:#B0B0B0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:15px; line-height:1.6; text-align:left;">
