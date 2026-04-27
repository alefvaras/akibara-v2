<?php
/**
 * Email Footer — Akibara Brand Identity
 * Override of WooCommerce email-footer.php
 *
 * @version 10.4.0
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;
$email = $email ?? null;
$site_url = home_url( '/' );
$account_url = wc_get_page_permalink( 'myaccount' );
$shop_url = get_permalink( wc_get_page_id( 'shop' ) );
?>
                                </div>
                            </td>
                        </tr>
                        <!-- Editorial signature — voz Akibara consistente con emails custom -->
                        <tr>
                            <td style="padding:20px 32px 0;" align="center">
                                <p style="margin:0; color:#A0A0A0; font-family:'Helvetica Neue', Arial, sans-serif; font-size:14px; line-height:1.5;">
                                    &mdash; Equipo Akibara<br />
                                    <span style="color:#666666; font-size:12px;">tu distrito del manga y c&oacute;mics</span>
                                </p>
                            </td>
                        </tr>
                        <!-- Footer separator -->
                        <tr>
                            <td style="padding:16px 32px 0;">
                                <div style="border-bottom:1px solid #2A2A2E;">&nbsp;</div>
                            </td>
                        </tr>
                        <!-- Footer links -->
                        <tr>
                            <td style="padding:20px 32px;" align="center">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:0 12px;">
                                            <a href="<?php echo esc_url( $shop_url ); ?>" style="color:#8A8A8A; font-family:'Helvetica Neue', Arial, sans-serif; font-size:12px; text-decoration:none;">Tienda</a>
                                        </td>
                                        <td style="color:#333333; font-family:Arial, sans-serif; font-size:12px;">|</td>
                                        <td style="padding:0 12px;">
                                            <a href="<?php echo esc_url( $account_url ); ?>" style="color:#8A8A8A; font-family:'Helvetica Neue', Arial, sans-serif; font-size:12px; text-decoration:none;">Mi Cuenta</a>
                                        </td>
                                        <td style="color:#333333; font-family:Arial, sans-serif; font-size:12px;">|</td>
                                        <td style="padding:0 12px;">
                                            <a href="<?php echo esc_url(function_exists('akibara_wa_url') ? akibara_wa_url() : 'https://wa.me/' . ( function_exists('akibara_whatsapp_get_business_number') ? akibara_whatsapp_get_business_number() : '' )); ?>" style="color:#8A8A8A; font-family:'Helvetica Neue', Arial, sans-serif; font-size:12px; text-decoration:none;">WhatsApp</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <!-- Tagline -->
                        <tr>
                            <td style="padding:0 32px 12px;" align="center">
                                <p style="margin:0; color:#525252; font-family:'Helvetica Neue', Arial, sans-serif; font-size:11px; line-height:1.5;">
                                    Akibara.cl &mdash; Tu distrito del manga y c&oacute;mics<br />
                                    <a href="mailto:contacto@akibara.cl" style="color:#525252; text-decoration:none;">contacto@akibara.cl</a>
                                </p>
                            </td>
                        </tr>
                        <!-- Aviso legal: Ley 19.628 Chile + política Brevo.
                             Este email es transaccional (confirmación de compra, envío, etc.).
                             Los transaccionales son comunicaciones de servicio y no requieren
                             opt-out obligatorio, pero la buena práctica y la política de Brevo
                             exigen indicar cómo gestionar preferencias de marketing. -->
                        <tr>
                            <td style="padding:0 32px 24px;" align="center">
                                <p style="margin:0; color:#3D3D3D; font-family:'Helvetica Neue', Arial, sans-serif; font-size:10px; line-height:1.6;">
                                    Este es un correo de servicio asociado a tu compra en Akibara.cl.<br />
                                    Para dejar de recibir emails promocionales,
                                    <a href="<?php echo esc_url( trailingslashit( $account_url ) . 'suscripciones/' ); ?>"
                                       style="color:#666666; text-decoration:underline;">gestiona tus preferencias de correo</a>.
                                </p>
                            </td>
                        </tr>
                    </table>
                    <!-- /Inner container -->
                </div>
                <!--[if mso]>
                </td></tr></table>
                <![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
