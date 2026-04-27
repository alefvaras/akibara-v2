<?php
/**
 * Bootstrap PHPUnit — tema Akibara (pure PHP, sin WordPress).
 *
 * Provee stubs mínimos de funciones WP/WC para que performance.php
 * sea incluible y testeable de forma standalone.
 */

// ── Constantes WP ─────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __FILE__, 5 ) . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

// ── Fake option/transient store ────────────────────────────────
$GLOBALS['_fake_options']    = [];
$GLOBALS['_fake_transients'] = [];
$GLOBALS['_get_terms_calls'] = 0;
$GLOBALS['_fake_terms']      = [];

function get_option( string $key, $default = false ) {
    return $GLOBALS['_fake_options'][ $key ] ?? $default;
}
function update_option( string $key, $value ): bool {
    $GLOBALS['_fake_options'][ $key ] = $value;
    return true;
}
function get_transient( string $key ) {
    return $GLOBALS['_fake_transients'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $expiration = 0 ): bool {
    $GLOBALS['_fake_transients'][ $key ] = $value;
    return true;
}
function delete_transient( string $key ): bool {
    unset( $GLOBALS['_fake_transients'][ $key ] );
    return true;
}

// ── WP_Error stub ─────────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_code(): string   { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

function is_wp_error( $thing ): bool {
    return $thing instanceof WP_Error;
}

// ── WP_Term stub ──────────────────────────────────────────────
if ( ! class_exists( 'WP_Term' ) ) {
    class WP_Term {
        public int    $term_id;
        public string $name;
        public string $slug;
        public int    $count;

        public function __construct( int $term_id, string $name, string $slug, int $count ) {
            $this->term_id = $term_id;
            $this->name    = $name;
            $this->slug    = $slug;
            $this->count   = $count;
        }
    }
}

// ── Stubs de funciones de datos ───────────────────────────────
function get_terms( array $args = [] ): array|WP_Error {
    $GLOBALS['_get_terms_calls']++;
    return $GLOBALS['_fake_terms'];
}

function get_term_meta( int $term_id, string $key, bool $single = false ) {
    // Por defecto: thumbnail_id=1 para todos los terms, country=''
    if ( $key === 'thumbnail_id' ) return 1;
    if ( $key === 'country' ) return '';
    return '';
}

function wp_get_attachment_image_url( int $id, string $size = 'thumbnail' ): string|false {
    return $id > 0 ? "https://akibara.cl/wp-content/uploads/test-{$id}.webp" : false;
}

function get_term_link( WP_Term $term ): string {
    return "https://akibara.cl/marca/{$term->slug}/";
}

if ( ! function_exists( 'mb_strtolower' ) ) {
    function mb_strtolower( string $str, string $encoding = 'UTF-8' ): string {
        return strtolower( $str );
    }
}

// ── Hooks system (mínimo para test de invalidación) ───────────
$GLOBALS['_wp_actions'] = [];
function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    $GLOBALS['_wp_actions'][ $hook ][] = $callback;
    return true;
}
function do_action( string $hook, ...$args ): void {
    foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
        call_user_func_array( $cb, $args );
    }
}
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return true; // no-op
}
function remove_action( string $hook, $callback, int $priority = 10 ): bool {
    return true; // no-op
}
function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
    return true; // no-op
}
function apply_filters( string $hook, $value, ...$args ) {
    return $value; // no-op — devuelve value sin transformación
}

// ── Incluir el archivo bajo test ───────────────────────────────
// Solo la sección de editorial brands (las otras no tienen dependencias aquí).
// Cargamos performance.php completo — los add_action/add_filter son no-op.
require_once dirname( __FILE__, 3 ) . '/inc/performance.php';
