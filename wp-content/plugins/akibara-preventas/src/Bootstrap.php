<?php

declare(strict_types=1);

namespace Akibara\Preventas;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap for akibara-preventas addon.
 *
 * Accessed by the entry-point via the akibara_core_init hook.
 * Singleton ensures a single registration even if the file is included twice.
 */
final class Bootstrap {

    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Returns the plugin version constant.
     */
    public function version(): string {
        return AKB_PREVENTAS_VERSION;
    }

    /**
     * Returns the absolute filesystem path to the plugin directory (with trailing slash).
     */
    public function dir(): string {
        return AKB_PREVENTAS_DIR;
    }

    /**
     * Returns the URL to the plugin directory (with trailing slash).
     */
    public function url(): string {
        return AKB_PREVENTAS_URL;
    }
}
