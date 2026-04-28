<?php
/**
 * Akibara Preventas — Módulo Encargos
 *
 * Formulario de encargos especiales (productos out-of-stock que el cliente
 * quiere que Akibara consiga). Refactored from wp-content/themes/akibara/inc/encargos.php.
 *
 * ARQUITECTURA:
 * - Este módulo REEMPLAZA la lógica en inc/encargos.php (tema).
 * - El archivo del tema queda como shim con do_action('akb_encargos_ajax_init')
 *   para mantener compatibilidad durante migración. Ver RFC-THEME-CHANGE-01.
 * - Shortcode [akb_encargos_form] disponible para usar en cualquier página.
 * - Los datos se persisten en wp_akb_special_orders (tabla propia) Y en
 *   akibara_encargos_log (wp_options legacy) para retrocompatibilidad con
 *   los 2 encargos activos de Jujutsu kaisen 24/26.
 *
 * REGLA DURA: NO romper los 2 encargos activos de Jujutsu kaisen 24/26.
 * La escritura en akibara_encargos_log se preserva mientras existan entradas
 * con status != 'completada'. Ver akb_encargos_migrate_legacy_log().
 *
 * @package    Akibara\Preventas
 * @subpackage Encargos
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_PREVENTAS_LOADED' ) ) {
    return;
}

if ( defined( 'AKB_PREVENTAS_ENCARGOS_LOADED' ) ) {
    return;
}
define( 'AKB_PREVENTAS_ENCARGOS_LOADED', '1.0.0' );

// ══════════════════════════════════════════════════════════════════
// AJAX HANDLER (lifted from themes/akibara/inc/encargos.php)
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_encargo_submit_handler' ) ) {

    /**
     * AJAX handler for encargo form submission.
     *
     * Persists to wp_akb_special_orders (new table) AND akibara_encargos_log
     * (wp_options legacy format) for backward compatibility.
     *
     * Rate limits (preserved from original B-S1-SEC-07):
     * - 3 encargos / hora por IP
     * - 2 encargos / día por email
     */
    function akb_encargo_submit_handler(): void {
        check_ajax_referer( 'akibara_encargo', 'encargo_nonce' );

        $nombre    = sanitize_text_field( wp_unslash( $_POST['nombre'] ?? '' ) );
        $email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $titulo    = sanitize_text_field( wp_unslash( $_POST['titulo'] ?? '' ) );
        $editorial = sanitize_text_field( wp_unslash( $_POST['editorial'] ?? '' ) );
        $volumenes = sanitize_text_field( wp_unslash( $_POST['volumenes'] ?? '' ) );
        $notas     = sanitize_textarea_field( wp_unslash( $_POST['notas'] ?? '' ) );

        if ( empty( $nombre ) || ! is_email( $email ) || empty( $titulo ) ) {
            wp_send_json_error( array( 'message' => 'Completa los campos obligatorios.' ) );
            return;
        }

        // B-S1-SEC-07: rate limit anti-abuse (preserved from original).
        if ( function_exists( 'akb_rate_limit' ) ) {
            $ip = isset( $_SERVER['REMOTE_ADDR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
                : 'unknown';

            if ( ! akb_rate_limit( 'encargo_ip:' . md5( $ip ), 3, HOUR_IN_SECONDS ) ) {
                wp_send_json_error( array( 'message' => 'Demasiadas solicitudes. Intenta en una hora.', 429 ) );
                return;
            }
            if ( ! akb_rate_limit( 'encargo_email:' . md5( strtolower( $email ) ), 2, DAY_IN_SECONDS ) ) {
                wp_send_json_error( array( 'message' => 'Ya enviaste demasiados encargos hoy con este email. Intenta mañana.', 429 ) );
                return;
            }
        }

        // 1. Persist to wp_akb_special_orders (new table).
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'akb_special_orders',
            array(
                'nombre'    => $nombre,
                'email'     => $email,
                'titulo'    => $titulo,
                'editorial' => $editorial,
                'volumenes' => $volumenes,
                'notas'     => $notas,
                'status'    => 'pendiente',
                'fecha'     => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        // 2. Also write to legacy akibara_encargos_log for backward compat.
        // This preserves read access from any code still querying the option
        // (e.g. admin panels checking the 2 Jujutsu kaisen active encargos).
        $encargos = get_option( 'akibara_encargos_log', array() );
        if ( ! is_array( $encargos ) ) {
            $encargos = array();
        }

        $encargos[] = array(
            'nombre'    => $nombre,
            'email'     => $email,
            'titulo'    => $titulo,
            'editorial' => $editorial,
            'volumenes' => $volumenes,
            'notas'     => $notas,
            'fecha'     => current_time( 'mysql' ),
            'status'    => 'pendiente',
        );

        // Keep last 200 (same limit as original).
        if ( count( $encargos ) > 200 ) {
            $encargos = array_slice( $encargos, -200 );
        }

        update_option( 'akibara_encargos_log', $encargos, false );

        // 3. Send admin notification email.
        $admin_email = get_option( 'admin_email' );
        $subject     = 'Nuevo encargo: ' . $titulo;

        $body  = "Nuevo encargo recibido desde akibara.cl/encargos/\n\n";
        $body .= "Nombre: {$nombre}\n";
        $body .= "Email: {$email}\n";
        $body .= "Titulo: {$titulo}\n";
        $body .= "Editorial: " . ( $editorial ?: 'No especificada' ) . "\n";
        $body .= "Volumenes: " . ( $volumenes ?: 'No especificados' ) . "\n";
        $body .= "Notas: " . ( $notas ?: 'Sin notas' ) . "\n\n";
        $body .= "---\n";
        $body .= "Responder directamente a: {$email}\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $email,
        );

        $sent = wp_mail( $admin_email, $subject, $body, $headers );

        // 4. Subscribe to Brevo list "Encargos" if key available.
        $api_key = function_exists( 'akb_brevo_get_api_key' )
            ? akb_brevo_get_api_key()
            : (string) get_option( 'akibara_brevo_api_key', '' );

        if ( ! empty( $api_key ) ) {
            wp_remote_post(
                'https://api.brevo.com/v3/contacts',
                array(
                    'headers' => array(
                        'api-key'      => $api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body'    => wp_json_encode( array(
                        'email'         => $email,
                        'listIds'       => array( 2 ),
                        'updateEnabled' => true,
                        'attributes'    => array(
                            'NOMBRE'         => $nombre,
                            'CONTACT_SOURCE' => 'encargo_form',
                            'LAST_ENCARGO'   => $titulo,
                        ),
                    ) ),
                    'timeout' => 5,
                )
            );
        }

        if ( $sent ) {
            wp_send_json_success( array( 'message' => 'Encargo enviado' ) );
        } else {
            // Log saved regardless; email failure is non-blocking.
            wp_send_json_success( array( 'message' => 'Encargo registrado' ) );
        }
    }

    add_action( 'wp_ajax_akibara_encargo_submit',        'akb_encargo_submit_handler' );
    add_action( 'wp_ajax_nopriv_akibara_encargo_submit', 'akb_encargo_submit_handler' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// ADMIN LIST — VIEW ALL ENCARGOS
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_encargos_admin_page' ) ) {

    function akb_encargos_admin_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos.' );
        }

        // Handle status update.
        if (
            isset( $_POST['akb_encargo_id'], $_POST['akb_encargo_status'], $_POST['_wpnonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'akb_encargo_update' )
            && current_user_can( 'manage_woocommerce' )
        ) {
            $id     = (int) $_POST['akb_encargo_id'];
            $status = sanitize_key( wp_unslash( $_POST['akb_encargo_status'] ) );

            $allowed = array( 'pendiente', 'en_gestion', 'lista', 'cancelada' );
            if ( in_array( $status, $allowed, true ) ) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'akb_special_orders',
                    array( 'status' => $status ),
                    array( 'id' => $id ),
                    array( '%s' ),
                    array( '%d' )
                );

                // Mirror status update to legacy log if matching email+titulo.
                akb_encargos_sync_legacy_status( $id, $status );

                echo '<div class="notice notice-success"><p>Estado actualizado.</p></div>';
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'akb_special_orders';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $encargos = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY fecha DESC LIMIT 200"
        );

        $status_labels = array(
            'pendiente'  => 'Pendiente',
            'en_gestion' => 'En gestión',
            'lista'      => 'Lista para retiro',
            'cancelada'  => 'Cancelada',
        );
        ?>
        <div class="akb-page-header">
            <h2 class="akb-page-header__title">Encargos Especiales</h2>
            <p class="akb-page-header__desc">Solicitudes de productos out-of-stock de clientes.</p>
        </div>
        <?php if ( empty( $encargos ) ) : ?>
            <p>Sin encargos aún.</p>
        <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Titulo</th>
                    <th>Editorial</th>
                    <th>Volúmenes</th>
                    <th>Estado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $encargos as $row ) : ?>
                <tr>
                    <td><?php echo (int) $row->id; ?></td>
                    <td><?php echo esc_html( $row->fecha ); ?></td>
                    <td>
                        <?php echo esc_html( $row->nombre ); ?><br>
                        <a href="mailto:<?php echo esc_attr( $row->email ); ?>"><?php echo esc_html( $row->email ); ?></a>
                    </td>
                    <td><?php echo esc_html( $row->titulo ); ?></td>
                    <td><?php echo esc_html( $row->editorial ?: '—' ); ?></td>
                    <td><?php echo esc_html( $row->volumenes ?: '—' ); ?></td>
                    <td><?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?></td>
                    <td>
                        <form method="post" style="display:inline">
                            <?php wp_nonce_field( 'akb_encargo_update' ); ?>
                            <input type="hidden" name="akb_encargo_id" value="<?php echo (int) $row->id; ?>">
                            <select name="akb_encargo_status">
                                <?php foreach ( $status_labels as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $row->status, $val ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-small">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    add_action(
        'admin_menu',
        static function (): void {
            if ( ! defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
                add_submenu_page(
                    'akibara',
                    'Encargos Especiales',
                    '📥 Encargos',
                    'manage_woocommerce',
                    'akibara-encargos',
                    'akb_encargos_admin_page'
                );
            }
        }
    );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// LEGACY SYNC — backward compat for Jujutsu kaisen 24/26
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_encargos_sync_legacy_status' ) ) {

    /**
     * When an encargo in wp_akb_special_orders changes status,
     * mirror the update into akibara_encargos_log (wp_options) so that
     * any admin code still reading the legacy option reflects the new state.
     *
     * Matching heuristic: same email + same titulo (case-insensitive).
     *
     * @param int    $new_id      ID in wp_akb_special_orders.
     * @param string $new_status  New status.
     */
    function akb_encargos_sync_legacy_status( int $new_id, string $new_status ): void {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT email, titulo FROM {$wpdb->prefix}akb_special_orders WHERE id = %d",
                $new_id
            )
        );

        if ( ! $row ) {
            return;
        }

        $log = get_option( 'akibara_encargos_log', array() );
        if ( ! is_array( $log ) ) {
            return;
        }

        $email_lower  = strtolower( $row->email );
        $titulo_lower = strtolower( $row->titulo );
        $updated      = false;

        foreach ( $log as &$entry ) {
            if (
                isset( $entry['email'], $entry['titulo'] )
                && strtolower( $entry['email'] ) === $email_lower
                && strtolower( $entry['titulo'] ) === $titulo_lower
                && ( $entry['status'] ?? '' ) !== $new_status
            ) {
                $entry['status'] = $new_status;
                $updated = true;
            }
        }
        unset( $entry );

        if ( $updated ) {
            update_option( 'akibara_encargos_log', $log, false );
        }
    }

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// SHORTCODE [akb_encargos_form]
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_encargos_shortcode' ) ) {

    /**
     * Shortcode [akb_encargos_form] — renders the encargo form.
     * Handles the markup previously in the theme template page-encargos.php.
     *
     * TODO Cell H: replace inline styles with design-token classes once
     * UI-SPECS-preventas.md delivers the encargos checkbox styling spec.
     * Stub documented in audit/sprint-3/cell-a/STUBS.md item ENC-01.
     *
     * @return string HTML output.
     */
    function akb_encargos_shortcode(): string {
        ob_start();
        $nonce = wp_create_nonce( 'akibara_encargo' );
        $ajax  = admin_url( 'admin-ajax.php' );
        ?>
        <div class="akb-encargos-form-wrap" data-ajaxurl="<?php echo esc_url( $ajax ); ?>">
            <form id="akb-encargo-form" class="akb-encargos-form" novalidate>
                <input type="hidden" name="action" value="akibara_encargo_submit">
                <input type="hidden" name="encargo_nonce" value="<?php echo esc_attr( $nonce ); ?>">

                <div class="akb-field">
                    <label for="akb-enc-nombre">Nombre <span aria-hidden="true">*</span></label>
                    <input type="text" id="akb-enc-nombre" name="nombre" required autocomplete="given-name">
                </div>

                <div class="akb-field">
                    <label for="akb-enc-email">Correo <span aria-hidden="true">*</span></label>
                    <input type="email" id="akb-enc-email" name="email" required autocomplete="email">
                </div>

                <div class="akb-field">
                    <label for="akb-enc-titulo">Titulo del manga <span aria-hidden="true">*</span></label>
                    <input type="text" id="akb-enc-titulo" name="titulo" required placeholder="Ej: Jujutsu Kaisen">
                </div>

                <div class="akb-field">
                    <label for="akb-enc-editorial">Editorial</label>
                    <input type="text" id="akb-enc-editorial" name="editorial" placeholder="Ej: Ivrea, Panini">
                </div>

                <div class="akb-field">
                    <label for="akb-enc-volumenes">Volúmenes</label>
                    <input type="text" id="akb-enc-volumenes" name="volumenes" placeholder="Ej: 24, 25">
                </div>

                <div class="akb-field">
                    <label for="akb-enc-notas">Notas adicionales</label>
                    <textarea id="akb-enc-notas" name="notas" rows="3" placeholder="Cualquier detalle extra"></textarea>
                </div>

                <button type="submit" class="akb-btn akb-btn--primary">Enviar encargo</button>

                <div class="akb-encargos-form__feedback" aria-live="polite" hidden></div>
            </form>
        </div>
        <script>
        (function() {
            var form = document.getElementById('akb-encargo-form');
            if (!form) return;
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn      = form.querySelector('[type=submit]');
                var feedback = form.querySelector('.akb-encargos-form__feedback');
                btn.disabled = true;
                var data = new FormData(form);
                fetch(form.closest('[data-ajaxurl]').dataset.ajaxurl, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    feedback.hidden = false;
                    feedback.textContent = res.data && res.data.message ? res.data.message : (res.success ? 'Encargo enviado.' : 'Error. Intenta de nuevo.');
                    if (res.success) form.reset();
                })
                .catch(function() {
                    feedback.hidden = false;
                    feedback.textContent = 'Error de red. Intenta de nuevo.';
                })
                .finally(function() { btn.disabled = false; });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    add_shortcode( 'akb_encargos_form', 'akb_encargos_shortcode' );

} // end group wrap

// ══════════════════════════════════════════════════════════════════
// MIGRATION: ONE-TIME IMPORT FROM LEGACY akibara_encargos_log
// ══════════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_encargos_migrate_legacy_log' ) ) {

    /**
     * One-time migration: copies entries from akibara_encargos_log (wp_options)
     * into wp_akb_special_orders if they don't already exist.
     *
     * Idempotent: guarded by option 'akb_encargos_migrated_v1'.
     * Called on admin_init to avoid frontend overhead.
     *
     * CRITICAL: preserves Jujutsu kaisen 24/26 records with their current status.
     */
    function akb_encargos_migrate_legacy_log(): void {
        if ( get_option( 'akb_encargos_migrated_v1' ) ) {
            return;
        }

        $log = get_option( 'akibara_encargos_log', array() );
        if ( empty( $log ) || ! is_array( $log ) ) {
            update_option( 'akb_encargos_migrated_v1', '1', false );
            return;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'akb_special_orders';
        $migrated  = 0;
        $skipped   = 0;

        foreach ( $log as $entry ) {
            if ( empty( $entry['email'] ) || empty( $entry['titulo'] ) ) {
                ++$skipped;
                continue;
            }

            // Check if already exists (idempotent).
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE email = %s AND titulo = %s LIMIT 1",
                    $entry['email'],
                    $entry['titulo']
                )
            );

            if ( $exists ) {
                ++$skipped;
                continue;
            }

            $wpdb->insert(
                $table,
                array(
                    'nombre'    => $entry['nombre']    ?? '',
                    'email'     => $entry['email'],
                    'titulo'    => $entry['titulo'],
                    'editorial' => $entry['editorial'] ?? '',
                    'volumenes' => $entry['volumenes'] ?? '',
                    'notas'     => $entry['notas']     ?? '',
                    'status'    => $entry['status']    ?? 'pendiente',
                    'fecha'     => $entry['fecha']     ?? current_time( 'mysql' ),
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            ++$migrated;
        }

        update_option( 'akb_encargos_migrated_v1', '1', false );

        if ( $migrated > 0 ) {
            error_log( "[akibara-preventas] encargos migration: {$migrated} records imported, {$skipped} skipped." );
        }
    }

    add_action( 'admin_init', 'akb_encargos_migrate_legacy_log' );

} // end group wrap
