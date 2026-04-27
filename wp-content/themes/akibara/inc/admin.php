<?php
/**
 * Akibara Admin — Login branding + admin footer
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

// ─── Login screen ────────────────────────────────────────────────────

add_action( 'login_enqueue_scripts', function (): void {
	$logo = AKIBARA_THEME_URI . '/assets/img/logo-akibara.webp';
	?>
	<style>
	body.login { background: #0d0d0d; }
	#login h1 a {
		background-image: url('<?php echo esc_url( $logo ); ?>');
		background-size: contain;
		background-repeat: no-repeat;
		background-position: center;
		width: 220px; height: 91px;
	}
	.login .button-primary,
	.wp-core-ui .button-primary {
		background: #D90010;
		border-color: #b5000d;
		box-shadow: none;
		text-shadow: none;
	}
	.login .button-primary:hover,
	.wp-core-ui .button-primary:hover {
		background: #b5000d;
		border-color: #900009;
	}
	.login input[type="text"]:focus,
	.login input[type="password"]:focus,
	.login input[type="checkbox"]:focus {
		border-color: #D90010;
		box-shadow: 0 0 0 1px #D90010;
	}
	.login #nav a,
	.login #backtoblog a { color: #D90010; }
	</style>
	<?php
} );

add_filter( 'login_headerurl', fn() => home_url( '/' ) );
add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );

// ─── Admin footer ────────────────────────────────────────────────────

add_filter( 'admin_footer_text', fn() =>
	'<strong>Akibara</strong> · <a href="' . esc_url( admin_url( 'admin.php?page=akibara' ) ) . '">Panel operaciones</a>'
);
