<?php
/**
 * Magic Link Login para Akibara
 * Rate limit por DB: 3/h por email, 10/h por IP. Token 64 chars, TTL 15 min.
 *
 * Flujo en dos pasos para sobrevivir scanners (Gmail preview, Safe Browsing,
 * antivirus corporativos, click-tracking de Brevo): el enlace del email abre
 * una página de confirmación vía GET; solo el POST de esa página consume
 * el token. Los scanners hacen GET, no ejecutan JS ni POSTean, por lo que
 * el token permanece intacto hasta que el usuario real lo confirma.
 */

const AKIBARA_MAGIC_DB_VERSION = '1.1';

function akibara_magic_link_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'akibara_magic_tokens';
}

function akibara_magic_link_migrate(): void {
    if ((string) get_option('akibara_magic_link_db_version', '0') === AKIBARA_MAGIC_DB_VERSION) {
        return;
    }
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table           = akibara_magic_link_table();
    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(200) NOT NULL,
        token varchar(64) NOT NULL,
        expires_at datetime NOT NULL,
        used tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        ip varchar(45) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY token (token),
        KEY email (email),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('akibara_magic_link_db_version', AKIBARA_MAGIC_DB_VERSION, false);
}
add_action('admin_init', 'akibara_magic_link_migrate');
add_action('after_switch_theme', 'akibara_magic_link_migrate');

function akibara_magic_link_client_ip(): string {
    // B-S1-SEC-07 (2026-04-27): trust HTTP_CF_CONNECTING_IP solo si la request
    // realmente vino por Cloudflare (CF-Ray header presente). Si no, usar
    // REMOTE_ADDR. Mitiga spoofing del header CF-Connecting-IP en accesos
    // directos al origin server (bypass Cloudflare).
    $cf_ray = $_SERVER['HTTP_CF_RAY'] ?? '';
    if ( ! empty( $cf_ray ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $raw = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } else {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    $first = trim(explode(',', $raw)[0]);
    return (string) preg_replace('/[^0-9a-f.:]/i', '', $first);
}

/* ══════════════════════════════════════════════════════════════════════
   ENVÍO DEL LINK (AJAX)
   ══════════════════════════════════════════════════════════════════════ */

add_action('wp_ajax_akibara_magic_link_send', 'akibara_magic_link_send');
add_action('wp_ajax_nopriv_akibara_magic_link_send', 'akibara_magic_link_send');

function akibara_magic_link_send(): void {
    check_ajax_referer('akibara-magic-link', 'nonce');

    $email = sanitize_email($_POST['email'] ?? '');
    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Correo inválido']);
    }

    akibara_magic_link_migrate();

    global $wpdb;
    $table = akibara_magic_link_table();
    $ip    = akibara_magic_link_client_ip();

    $email_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE email = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
        $email
    ));
    if ($email_count >= 3) {
        wp_send_json_error(['message' => 'Ya enviamos varios enlaces a este correo. Revisa tu bandeja o espera 1 hora.']);
    }

    if ($ip !== '') {
        $ip_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE ip = %s AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip
        ));
        if ($ip_count >= 10) {
            wp_send_json_error(['message' => 'Demasiados intentos desde tu IP. Espera 1 hora.']);
        }
    }

    $user    = get_user_by('email', $email);
    $name    = $user ? $user->display_name : $email;
    $token   = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS);

    $inserted = $wpdb->insert($table, [
        'email'      => $email,
        'token'      => $token,
        'expires_at' => $expires,
        'ip'         => $ip,
        'created_at' => current_time('mysql', true),
    ]);
    if (!$inserted) {
        wp_send_json_error(['message' => 'No pudimos registrar tu solicitud. Intenta de nuevo.']);
    }

    $magic_url = add_query_arg('akibara_magic', $token, wc_get_page_permalink('myaccount'));
    $subject   = 'Tu enlace de acceso a ' . get_bloginfo('name');
    $body      = akibara_magic_link_email_html($name, $magic_url, !$user);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'X-Auto-Response-Suppress: All',
        'Precedence: bulk',
    ];
    $sent = wp_mail($email, $subject, $body, $headers);

    if (!$sent) {
        $wpdb->delete($table, ['token' => $token]);
        wp_send_json_error(['message' => 'No se pudo enviar el correo.']);
    }
    wp_send_json_success(['message' => 'Revisa tu correo. El enlace expira en 15 minutos.']);
}

/* ══════════════════════════════════════════════════════════════════════
   VERIFICACIÓN (GET = página intermedia, POST = consumir token)
   ══════════════════════════════════════════════════════════════════════ */

// template_redirect corre después de que WP resolvió la query pero antes de
// renderizar el tema, y es el hook correcto para tomar control de la respuesta
// completa. En `init` algunos plugins (LiteSpeed/Cloudflare Worker) aún no
// terminaron de configurar headers de cache.
add_action('template_redirect', 'akibara_magic_link_route');
function akibara_magic_link_route(): void {
    $is_get_link     = !empty($_GET['akibara_magic']);
    $is_post_confirm = isset($_POST['akibara_magic_confirm'], $_POST['akibara_magic_token'])
                       && $_SERVER['REQUEST_METHOD'] === 'POST';

    if (!$is_get_link && !$is_post_confirm) return;

    // Matar cualquier buffer abierto por WP/plugins para poder escribir la
    // página intermedia limpia. Sin esto, ob_start() de LiteSpeed puede
    // tragarse el output o servir un blanco cacheado.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Señales anti-cache a Cloudflare, LiteSpeed y WP Super Cache.
    if (!headers_sent()) {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('X-LiteSpeed-Cache-Control: no-cache');
        header('X-Accel-Expires: 0');
        header('CDN-Cache-Control: no-store');
    }
    if (defined('DONOTCACHEPAGE') === false) {
        define('DONOTCACHEPAGE', true);
    }

    $myaccount = wc_get_page_permalink('myaccount');

    if (is_user_logged_in()) {
        wp_safe_redirect($myaccount);
        exit;
    }

    if ($is_post_confirm) {
        akibara_magic_link_consume(
            sanitize_text_field(wp_unslash($_POST['akibara_magic_token'])),
            $myaccount
        );
        return;
    }

    akibara_magic_link_show_confirm(
        sanitize_text_field(wp_unslash($_GET['akibara_magic'])),
        $myaccount
    );
}

function akibara_magic_link_token_is_valid(string $token): bool {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return false;
    global $wpdb;
    $table = akibara_magic_link_table();
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE token = %s AND expires_at > UTC_TIMESTAMP() AND used = 0",
        $token
    ));
    return $count === 1;
}

function akibara_magic_link_show_confirm(string $token, string $myaccount): void {
    // Respuesta idempotente al GET: no toca la DB más allá de una lectura.
    // Scanners que siguen el link ven la página pero no consumen nada.
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    $valid     = akibara_magic_link_token_is_valid($token);
    $site_name = get_bloginfo('name');
    $post_url  = esc_url_raw(home_url('/'));

    ?><!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Confirma tu acceso — <?php echo esc_html($site_name); ?></title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:'Helvetica Neue',Arial,sans-serif;background:#0D0D0F;color:#F5F5F5;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .card{background:#161618;border:1px solid #2A2A2E;border-radius:8px;max-width:440px;width:100%;padding:40px 32px;text-align:center}
  .accent{height:3px;background:#D90010;margin:-40px -32px 28px;border-radius:8px 8px 0 0}
  h1{margin:0 0 12px;font-size:22px;font-weight:700;letter-spacing:-.3px}
  p{margin:0 0 24px;color:#A0A0A0;font-size:15px;line-height:1.6}
  .btn{display:inline-block;background:#D90010;color:#fff;padding:14px 32px;border:0;border-radius:6px;font-size:15px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
  .btn:hover{background:#BB000D}
  .btn[disabled]{background:#2A2A2E;color:#666;cursor:not-allowed}
  .err{background:#2A1515;border:1px solid #5A2020;color:#FF6B6B;padding:12px;border-radius:6px;margin-bottom:20px;font-size:14px}
  .foot{margin-top:24px;font-size:12px;color:#525252}
  .foot a{color:#8A8A8A;text-decoration:none}
</style></head>
<body>
  <div class="card">
    <div class="accent"></div>
    <?php if (!$valid): ?>
      <h1>Enlace inválido o expirado</h1>
      <p>Este enlace ya fue usado, expiró o no es válido. Pide uno nuevo desde la página de acceso.</p>
      <a class="btn" href="<?php echo esc_url($myaccount); ?>">Volver al login</a>
    <?php else: ?>
      <h1>Confirma tu inicio de sesión</h1>
      <p>Haz clic en el botón para acceder a tu cuenta de <?php echo esc_html($site_name); ?>. El enlace expira en 15 minutos.</p>
      <form method="post" action="<?php echo esc_attr($post_url); ?>" id="f">
        <input type="hidden" name="akibara_magic_confirm" value="1">
        <input type="hidden" name="akibara_magic_token" value="<?php echo esc_attr($token); ?>">
        <button type="submit" class="btn" id="b">Entrar a mi cuenta</button>
      </form>
    <?php endif; ?>
    <div class="foot">
      <?php echo esc_html($site_name); ?> &middot; <a href="<?php echo esc_url(home_url('/')); ?>">akibara.cl</a>
    </div>
  </div>
</body></html><?php
    exit;
}

function akibara_magic_link_consume(string $token, string $myaccount): void {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        wp_safe_redirect(add_query_arg('magic_error', 'invalid', $myaccount));
        exit;
    }

    global $wpdb;
    $table = akibara_magic_link_table();

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, email FROM $table WHERE token = %s AND expires_at > UTC_TIMESTAMP() AND used = 0",
        $token
    ));
    if (!$row) {
        wp_safe_redirect(add_query_arg('magic_error', 'invalid', $myaccount));
        exit;
    }

    $claimed = $wpdb->update($table, ['used' => 1], ['id' => $row->id, 'used' => 0]);
    if ($claimed !== 1) {
        wp_safe_redirect(add_query_arg('magic_error', 'invalid', $myaccount));
        exit;
    }

    $user = get_user_by('email', $row->email);
    if (!$user) {
        $local    = current(explode('@', $row->email));
        $username = sanitize_user($local, true);
        if ($username === '') {
            $username = 'user_' . substr(md5($row->email), 0, 8);
        }
        $base = $username; $i = 1;
        while (username_exists($username)) {
            $username = $base . $i++;
        }
        $user_id = wp_create_user($username, wp_generate_password(20, true, true), $row->email);
        if (is_wp_error($user_id)) {
            wp_safe_redirect(add_query_arg('magic_error', 'create_fail', $myaccount));
            exit;
        }
        update_user_meta($user_id, 'akibara_magic_link_user', 1);
        $user = get_user_by('id', $user_id);
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    wp_safe_redirect(add_query_arg('magic_success', '1', $myaccount));
    exit;
}

/* ══════════════════════════════════════════════════════════════════════
   EMAIL (usa header/footer de WooCommerce → branding unificado)
   ══════════════════════════════════════════════════════════════════════ */

function akibara_magic_link_email_html(string $name, string $url, bool $is_new): string {
    if (!function_exists('wc_get_template')) {
        return akibara_magic_link_email_fallback($name, $url, $is_new);
    }

    $heading = $is_new ? '¡Bienvenido/a!' : 'Tu enlace de acceso';

    ob_start();
    wc_get_template('emails/email-header.php', ['email_heading' => $heading]);
    ?>
    <p style="margin:0 0 16px;color:#F5F5F5;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;">Hola <strong style="color:#FFFFFF;"><?php echo esc_html($name); ?></strong>,</p>

    <?php if ($is_new): ?>
      <p style="margin:0 0 16px;color:#B0B0B0;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.6;">
        Creamos una cuenta para ti en <?php echo esc_html(get_bloginfo('name')); ?>. Haz clic en el botón para acceder por primera vez:
      </p>
    <?php else: ?>
      <p style="margin:0 0 16px;color:#B0B0B0;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;line-height:1.6;">
        Recibimos una solicitud para acceder a tu cuenta. Haz clic en el botón para ingresar sin contraseña:
      </p>
    <?php endif; ?>

    <!-- CTA -->
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;">
      <tr><td align="center">
        <!--[if mso]>
        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="<?php echo esc_url($url); ?>" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="13%" stroke="f" fillcolor="#D90010"><w:anchorlock/><center style="color:#ffffff;font-family:Helvetica,Arial,sans-serif;font-size:15px;font-weight:bold;">Entrar a mi cuenta</center></v:roundrect>
        <![endif]-->
        <!--[if !mso]><!-->
        <a href="<?php echo esc_url($url); ?>" style="display:inline-block;background:#D90010;color:#FFFFFF;padding:14px 32px;border-radius:6px;font-family:'Helvetica Neue',Arial,sans-serif;font-size:15px;font-weight:700;text-decoration:none;letter-spacing:.2px;">Entrar a mi cuenta</a>
        <!--<![endif]-->
      </td></tr>
    </table>

    <p style="margin:24px 0 8px;color:#8A8A8A;font-family:'Helvetica Neue',Arial,sans-serif;font-size:13px;line-height:1.5;">
      Si el botón no funciona, copia y pega este enlace en tu navegador:
    </p>
    <p style="margin:0 0 24px;word-break:break-all;">
      <a href="<?php echo esc_url($url); ?>" style="color:#FF4D4D;font-family:'Helvetica Neue',Arial,sans-serif;font-size:12px;text-decoration:none;"><?php echo esc_html($url); ?></a>
    </p>

    <div style="border-top:1px solid #2A2A2E;margin:24px 0 16px;"></div>

    <p style="margin:0;color:#525252;font-family:'Helvetica Neue',Arial,sans-serif;font-size:12px;line-height:1.5;">
      Este enlace expira en <strong style="color:#8A8A8A;">15 minutos</strong> y solo puede usarse una vez. Si no solicitaste este acceso, puedes ignorar este correo — nadie podrá entrar a tu cuenta sin hacer clic.
    </p>
    <?php
    wc_get_template('emails/email-footer.php');
    return ob_get_clean();
}

function akibara_magic_link_email_fallback(string $name, string $url, bool $is_new): string {
    $site = get_bloginfo('name');
    $msg  = $is_new ? "Creamos una cuenta para ti en $site." : 'Solicitaste acceder a tu cuenta.';
    ob_start(); ?>
    <!DOCTYPE html><html><body style="font-family:sans-serif;background:#0D0D0F;color:#F5F5F5;padding:20px;">
    <div style="max-width:560px;margin:0 auto;background:#161618;border-radius:8px;padding:32px;">
      <p>Hola <?php echo esc_html($name); ?>,</p>
      <p><?php echo esc_html($msg); ?></p>
      <p><a href="<?php echo esc_url($url); ?>" style="background:#D90010;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;">Entrar a mi cuenta</a></p>
      <p style="font-size:12px;color:#8A8A8A;">El enlace expira en 15 minutos.</p>
    </div></body></html>
    <?php return ob_get_clean();
}
