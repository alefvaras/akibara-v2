<?php
/**
 * Customer New Account Email — Akibara Override
 *
 * Bienvenida de marca: saludo cálido, usuario y link a Mi Cuenta.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package Akibara
 * @version 10.4.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

add_filter(
	'akibara_email_preheader',
	static function () use ( $user_login ): string {
		return '¡Bienvenido a Akibara, ' . $user_login . '! Tu cuenta ya está lista.';
	}
);

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>¡Hola, <strong><?php echo esc_html( $user_login ); ?></strong>!</p>

<p>Tu cuenta en <strong>Akibara</strong> ya está activa — bienvenido a tu distrito del manga y cómics.</p>

<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<p><strong>Usuario:</strong> <?php echo esc_html( $user_login ); ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p><a href="<?php echo esc_url( $set_password_url ); ?>">Configura tu contraseña</a> para completar tu cuenta.</p>
	<?php endif; ?>
	<div class="hr hr-bottom"></div>
	<p>Desde Mi Cuenta puedes revisar tus pedidos, gestionar tus direcciones y mucho más:</p>
	<p><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">Ir a Mi Cuenta</a></p>
<?php else : ?>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p><a href="<?php echo esc_url( $set_password_url ); ?>">Configura tu contraseña acá.</a></p>
	<?php endif; ?>
	<p>Accede a tu cuenta para ver pedidos, cambiar contraseña y más: <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"><?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?></a></p>
<?php endif; ?>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<!-- CTA — acceso rápido a la tienda -->
<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin:20px 0;">
	<tr>
		<td style="padding:0 0 8px; font-family:'Helvetica Neue',Arial,sans-serif; font-size:13px; color:#888888; text-align:center;">
			¿Ves algo que te gusta? Empieza a explorar:
		</td>
	</tr>
	<tr>
		<td align="center">
			<!--[if mso]>
			<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
			  style="height:48px;v-text-anchor:middle;width:240px" arcsize="8%"
			  strokecolor="#D90010" strokeweight="2px" filled="f">
			  <center style="color:#D90010;font-family:Arial;font-size:16px;font-weight:700">Ver la tienda</center>
			</v:roundrect>
			<![endif]-->
			<!--[if !mso]><!-->
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
			   style="display:inline-block;background:transparent;color:#D90010;text-decoration:none;
			          padding:14px 36px;font-family:Impact,'Arial Black',sans-serif;font-size:16px;
			          text-transform:uppercase;letter-spacing:0.1em;border:2px solid #D90010;
			          border-radius:6px;font-weight:700;box-shadow:0 0 16px rgba(217,0,16,0.3);">
				Ver la tienda
			</a>
			<!--<![endif]-->
		</td>
	</tr>
</table>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
