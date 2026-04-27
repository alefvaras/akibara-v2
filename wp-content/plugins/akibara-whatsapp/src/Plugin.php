<?php
/**
 * Akibara WhatsApp — Plugin entry class implementing AddonContract.
 *
 * Type-safe addon registration via Bootstrap::register_addon().
 * Contiene toda la lógica del botón flotante, admin UI, y helpers de tema.
 *
 * @package Akibara\WhatsApp
 * @since   1.4.0 (post-INCIDENT-01 AddonContract pattern)
 */

namespace Akibara\WhatsApp;

use Akibara\Core\Bootstrap;
use Akibara\Core\Contracts\AddonContract;
use Akibara\Core\Contracts\AddonManifest;

defined( 'ABSPATH' ) || exit;

final class Plugin implements AddonContract {

    public function manifest(): AddonManifest {
        return new AddonManifest(
            slug:         'akibara-whatsapp',
            version:      AKB_WHATSAPP_VERSION,
            type:         'addon',
            dependencies: array(
                'akibara-core' => '>=1.0',
            )
        );
    }

    public function init( Bootstrap $bootstrap ): void {
        // Registrar servicio 'whatsapp.number' en ServiceLocator para que otros
        // addons puedan acceder al número sin depender de la función global.
        $bootstrap->services()->register(
            'whatsapp.number',
            static fn() => akibara_whatsapp_get_business_number()
        );

        // Iniciar el singleton de la clase de presentación (frontend + admin).
        Akibara_WhatsApp_Controller::instance();
    }
}

// ─── Controller (ex-Akibara_WhatsApp) ───────────────────────────────────────
// Clase separada para alinear naming con plugin, but mantenida en este archivo
// porque el plugin es single-file en lógica (no necesita más archivos src/).

final class Akibara_WhatsApp_Controller {

    private static ?Akibara_WhatsApp_Controller $instance = null;

    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, mixed> */
    private array $defaults = [
        'phone'            => AKIBARA_WA_PHONE_DEFAULT,
        'message_default'  => 'Hola Akibara! 👋',
        'message_product'  => 'Hola! Me interesa *{product}* ({url}) ¿Está disponible?',
        'position'         => 'right',
        'delay_button'     => 1500,
        'show_on_mobile'   => true,
        'show_on_desktop'  => true,
        'whatsapp_web'     => true,
        'hide_on'          => [ '404', 'search', 'author', 'date' ],
        'hide_on_pages'    => [],
        'wc_hide_thankyou' => false,
        'wc_hide_account'  => true,
    ];

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        /** @var array<string, mixed> $saved */
        $saved          = get_option( 'akibara_whatsapp', [] );
        $this->settings = wp_parse_args( $saved, $this->defaults );

        if ( ! is_admin() ) {
            add_action( 'wp_footer', [ $this, 'render_button' ], 50 );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        }

        if ( is_admin() ) {
            add_action( 'admin_menu', [ $this, 'admin_menu' ] );
            add_action( 'admin_init', [ $this, 'register_settings' ] );
        }

        // Helpers globales para que el tema use el teléfono centralizado.
        add_action(
            'after_setup_theme',
            static function (): void {
                if ( ! function_exists( 'akibara_wa_phone' ) ) {
                    function akibara_wa_phone(): string {
                        return akibara_whatsapp_get_business_number();
                    }
                }
                if ( ! function_exists( 'akibara_wa_url' ) ) {
                    function akibara_wa_url( string $message = '' ): string {
                        $phone = akibara_wa_phone();
                        $url   = 'https://wa.me/' . $phone;
                        if ( $message ) {
                            $url .= '?text=' . rawurlencode( $message );
                        }
                        return $url;
                    }
                }
            }
        );

        // CTA WhatsApp en emails de confirmación de pedido.
        add_action(
            'woocommerce_email_before_order_table',
            [ $this, 'inject_order_email_cta' ],
            20,
            4
        );
    }

    // ─── Visibilidad ──────────────────────────────────────────────────────────

    private function should_show(): bool {
        if (
            isset( $_GET['elementor-preview'] ) || isset( $_GET['ct_builder'] ) ||
            isset( $_GET['fl_builder'] )         || isset( $_GET['brizy-edit'] )
        ) {
            return false;
        }

        /** @var array<int,string> $hide */
        $hide = (array) $this->settings['hide_on'];

        if ( is_404()    && in_array( '404', $hide, true ) )    { return false; }
        if ( is_search() && in_array( 'search', $hide, true ) ) { return false; }
        if ( is_author() && in_array( 'author', $hide, true ) ) { return false; }
        if ( is_date()   && in_array( 'date', $hide, true ) )   { return false; }

        if ( function_exists( 'is_wc_endpoint_url' ) ) {
            if ( $this->settings['wc_hide_thankyou'] && is_wc_endpoint_url( 'order-received' ) ) {
                return false;
            }
            if ( $this->settings['wc_hide_account'] && is_account_page() ) {
                return false;
            }
        }

        if ( is_page() ) {
            $hidden_pages = array_map( 'intval', (array) $this->settings['hide_on_pages'] );
            if ( in_array( (int) get_the_ID(), $hidden_pages, true ) ) {
                return false;
            }
        }

        return true;
    }

    // ─── URL de WhatsApp ──────────────────────────────────────────────────────

    private function get_wa_url( string $message = '' ): string {
        $phone = preg_replace( '/[^0-9]/', '', (string) $this->settings['phone'] ) ?? '';

        if ( $this->settings['whatsapp_web'] ) {
            $url = 'https://web.whatsapp.com/send?phone=' . $phone;
            if ( $message ) {
                $url .= '&text=' . rawurlencode( $message );
            }
        } else {
            $url = 'https://wa.me/' . $phone;
            if ( $message ) {
                $url .= '?text=' . rawurlencode( $message );
            }
        }

        return $url;
    }

    // ─── Mensaje contextual ───────────────────────────────────────────────────

    private function get_contextual_message(): string {
        if ( function_exists( 'is_product' ) && is_product() ) {
            global $product;
            if ( $product instanceof \WC_Product ) {
                $msg          = (string) $this->settings['message_product'];
                $replacements = [
                    '{product}' => $product->get_name(),
                    '{sku}'     => $product->get_sku() ?: '',
                    '{price}'   => wp_strip_all_tags( wc_price( $product->get_price() ) ),
                    '{url}'     => get_permalink( $product->get_id() ),
                ];
                return strtr( $msg, $replacements );
            }
        }

        if (
            function_exists( 'is_product_category' ) &&
            ( is_product_category() || is_product_tag() )
        ) {
            $term = get_queried_object();
            if ( $term instanceof \WP_Term ) {
                return 'Hola! Estoy viendo la categoría ' . $term->name . ' en Akibara.cl';
            }
        }

        return (string) $this->settings['message_default'];
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets(): void {
        $phone = preg_replace( '/[^0-9]/', '', (string) $this->settings['phone'] ) ?? '';
        if ( ! $phone || ! $this->should_show() ) {
            return;
        }

        wp_enqueue_style(
            'akibara-whatsapp',
            AKB_WHATSAPP_URL . 'akibara-whatsapp.css',
            [],
            AKB_WHATSAPP_VERSION
        );

        wp_enqueue_script(
            'akibara-whatsapp',
            AKB_WHATSAPP_URL . 'akibara-whatsapp.js',
            [],
            AKB_WHATSAPP_VERSION,
            [ 'in_footer' => true, 'strategy' => 'defer' ]
        );
    }

    // ─── Render botón flotante ────────────────────────────────────────────────

    public function render_button(): void {
        $phone = preg_replace( '/[^0-9]/', '', (string) $this->settings['phone'] ) ?? '';
        if ( ! $phone || ! $this->should_show() ) {
            return;
        }

        $message    = $this->get_contextual_message();
        $wa_url     = $this->get_wa_url( $message );
        $pos        = esc_attr( (string) $this->settings['position'] );
        $is_product = function_exists( 'is_product' ) && is_product();

        $data = [
            'url'        => $wa_url,
            'message'    => $message,
            'phone'      => $phone,
            'delayBtn'   => (int) $this->settings['delay_button'],
            'mobile'     => (bool) $this->settings['show_on_mobile'],
            'desktop'    => (bool) $this->settings['show_on_desktop'],
            'waWeb'      => (bool) $this->settings['whatsapp_web'],
            'isProduct'  => $is_product,
            'isCart'     => function_exists( 'is_cart' ) && is_cart(),
            'isCheckout' => function_exists( 'is_checkout' ) && is_checkout(),
        ];
        ?>
        <div id="akibara-wa"
             class="akibara-wa akibara-wa--<?php echo $pos; // already esc'd above ?>"
             data-settings="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"
             aria-hidden="true">

            <button class="akibara-wa__btn" type="button"
                    aria-label="Contactar por WhatsApp"
                    title="WhatsApp">
                <svg class="akibara-wa__icon" width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <span class="akibara-wa__pulse"></span>
            </button>
        </div>
        <?php
    }

    // ─── Admin ────────────────────────────────────────────────────────────────

    public function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            'WhatsApp',
            'WhatsApp',
            'manage_options',
            'akibara-whatsapp',
            [ $this, 'admin_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'akibara_whatsapp_group', 'akibara_whatsapp', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize_settings( array $input ): array {
        return [
            'phone'            => preg_replace( '/[^0-9+]/', '', (string) ( $input['phone'] ?? '' ) ),
            'message_default'  => sanitize_textarea_field( (string) ( $input['message_default'] ?? '' ) ),
            'message_product'  => sanitize_textarea_field( (string) ( $input['message_product'] ?? '' ) ),
            'position'         => in_array( $input['position'] ?? '', [ 'left', 'right' ], true )
                                    ? (string) $input['position']
                                    : 'right',
            'delay_button'     => absint( $input['delay_button'] ?? 1500 ),
            'show_on_mobile'   => ! empty( $input['show_on_mobile'] ),
            'show_on_desktop'  => ! empty( $input['show_on_desktop'] ),
            'whatsapp_web'     => ! empty( $input['whatsapp_web'] ),
            'hide_on'          => array_map( 'sanitize_text_field', (array) ( $input['hide_on'] ?? [] ) ),
            'hide_on_pages'    => array_map( 'absint', (array) ( $input['hide_on_pages'] ?? [] ) ),
            'wc_hide_thankyou' => ! empty( $input['wc_hide_thankyou'] ),
            'wc_hide_account'  => ! empty( $input['wc_hide_account'] ),
        ];
    }

    public function admin_page(): void {
        $s = $this->settings;
        ?>
        <div class="wrap">
            <h1>Akibara WhatsApp</h1>
            <p class="description">Configura el botón flotante de WhatsApp. Las funciones <code>akibara_wa_phone()</code> y <code>akibara_wa_url($mensaje)</code> están disponibles en el tema.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'akibara_whatsapp_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th>Teléfono</th>
                        <td>
                            <input type="text"
                                   name="akibara_whatsapp[phone]"
                                   value="<?php echo esc_attr( (string) $s['phone'] ); ?>"
                                   class="regular-text"
                                   placeholder="56912345678">
                            <p class="description">Sin +, sin espacios. Ej: 56912345678</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Mensaje por defecto</th>
                        <td><textarea name="akibara_whatsapp[message_default]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['message_default'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th>Mensaje en productos</th>
                        <td>
                            <textarea name="akibara_whatsapp[message_product]" rows="2" class="large-text"><?php echo esc_textarea( (string) $s['message_product'] ); ?></textarea>
                            <p class="description">Variables: <code>{product}</code> <code>{sku}</code> <code>{price}</code> <code>{url}</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th>Posición</th>
                        <td>
                            <select name="akibara_whatsapp[position]">
                                <option value="right" <?php selected( $s['position'], 'right' ); ?>>Derecha</option>
                                <option value="left"  <?php selected( $s['position'], 'left'  ); ?>>Izquierda</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Delay botón (ms)</th>
                        <td><input type="number" name="akibara_whatsapp[delay_button]" value="<?php echo esc_attr( (string) $s['delay_button'] ); ?>" min="0" step="500" class="small-text"></td>
                    </tr>
                    <tr>
                        <th>Visibilidad</th>
                        <td>
                            <label><input type="checkbox" name="akibara_whatsapp[show_on_mobile]"  value="1" <?php checked( $s['show_on_mobile'] ); ?>> Móvil</label><br>
                            <label><input type="checkbox" name="akibara_whatsapp[show_on_desktop]" value="1" <?php checked( $s['show_on_desktop'] ); ?>> Desktop</label><br>
                            <label><input type="checkbox" name="akibara_whatsapp[whatsapp_web]"    value="1" <?php checked( $s['whatsapp_web'] ); ?>> WhatsApp Web en desktop</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Ocultar en</th>
                        <td>
                            <?php
                            $hide_opts = [ '404' => '404', 'search' => 'Búsqueda', 'author' => 'Autor', 'date' => 'Fecha' ];
                            foreach ( $hide_opts as $val => $label ) :
                            ?>
                                <label>
                                    <input type="checkbox"
                                           name="akibara_whatsapp[hide_on][]"
                                           value="<?php echo esc_attr( $val ); ?>"
                                           <?php checked( in_array( $val, (array) $s['hide_on'], true ) ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </label><br>
                            <?php endforeach; ?>
                            <br>
                            <label><input type="checkbox" name="akibara_whatsapp[wc_hide_thankyou]" value="1" <?php checked( $s['wc_hide_thankyou'] ); ?>> Thank You</label><br>
                            <label><input type="checkbox" name="akibara_whatsapp[wc_hide_account]"  value="1" <?php checked( $s['wc_hide_account'] ); ?>> Mi Cuenta</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Guardar' ); ?>
            </form>
        </div>
        <?php
    }

    // ─── Email CTA ────────────────────────────────────────────────────────────

    /**
     * Inyecta un CTA de WhatsApp en emails de order confirmation (processing).
     *
     * @param \WC_Order $order
     * @param bool      $sent_to_admin
     * @param bool      $plain_text
     * @param \WC_Email $email
     */
    public function inject_order_email_cta(
        \WC_Order $order,
        bool $sent_to_admin,
        bool $plain_text,
        \WC_Email $email
    ): void {
        if ( $sent_to_admin || $plain_text ) {
            return;
        }
        if ( $email->id !== 'customer_processing_order' ) {
            return;
        }
        if ( ! function_exists( 'akibara_wa_phone' ) || ! akibara_wa_phone() ) {
            return;
        }

        $order_id = $order->get_id();
        $customer = $order->get_billing_first_name() ?: 'Hola';
        $skus     = [];
        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $p = $item->get_product();
            if ( $p instanceof \WC_Product && $p->get_sku() ) {
                $skus[] = $p->get_sku();
            }
        }
        $skus_str = implode( ', ', array_slice( $skus, 0, 3 ) );

        $msg = sprintf(
            '¡Hola Akibara! Soy %s, tengo consulta sobre mi pedido #%d (%s).',
            $customer,
            $order_id,
            $skus_str ?: 'productos'
        );
        $url = akibara_wa_url( $msg );
        if ( ! $url ) {
            return;
        }

        printf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%%;margin:16px 0 24px 0;">
              <tr>
                <td align="center" style="background:#25D366;padding:16px 24px;border-radius:8px;">
                  <a href="%s" style="color:#fff;text-decoration:none;font-weight:600;font-size:15px;line-height:1.4;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,Roboto,sans-serif;">
                    💬 ¿Dudas sobre tu pedido? Escríbenos por WhatsApp
                  </a>
                </td>
              </tr>
            </table>',
            esc_url( $url )
        );
    }
}
