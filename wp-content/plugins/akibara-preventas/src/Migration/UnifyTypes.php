<?php

declare(strict_types=1);

namespace Akibara\Preventas\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent migration: unify legacy "pedido_especial" type to "preventa".
 *
 * Wraps the procedural Akibara_Reserva_Migration::maybe_unify_types() from the
 * legacy class (lifted from akibara-reservas). Provides a PSR-4 entry point for
 * programmatic use and testing.
 *
 * Also migrates legacy akibara_encargos_log (wp_options array) into
 * wp_akb_special_orders table (idempotent via migration flag).
 */
final class UnifyTypes {

    /** Option flag for the encargos migration. */
    private const ENCARGOS_MIGRATE_FLAG = 'akb_preventas_encargos_migrated_v1';

    /**
     * Run all idempotent migrations.
     * Safe to call multiple times — each step checks its own flag.
     */
    public static function run(): void {
        // Step 1: Unify pedido_especial → preventa meta.
        if ( class_exists( 'Akibara_Reserva_Migration' ) ) {
            \Akibara_Reserva_Migration::maybe_unify_types();
        }

        // Step 2: Migrate akibara_encargos_log from wp_options to wp_akb_special_orders.
        self::maybe_migrate_encargos_log();
    }

    /**
     * Migrate encargos from the legacy wp_options array to the new DB table.
     * Preserves all existing data. Idempotent via flag option.
     */
    public static function maybe_migrate_encargos_log(): void {
        if ( get_option( self::ENCARGOS_MIGRATE_FLAG ) ) {
            return;
        }

        global $wpdb;

        $legacy = get_option( 'akibara_encargos_log', [] );
        if ( ! is_array( $legacy ) || empty( $legacy ) ) {
            update_option( self::ENCARGOS_MIGRATE_FLAG, [ 'at' => time(), 'migrated' => 0 ] );
            return;
        }

        $table     = $wpdb->prefix . 'akb_special_orders';
        $count     = 0;
        $skipped   = 0;

        foreach ( $legacy as $encargo ) {
            if ( empty( $encargo['email'] ) || empty( $encargo['titulo'] ) ) {
                ++$skipped;
                continue;
            }

            // Check if already migrated (deduplicate by email + titulo + fecha).
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT id FROM {$table} WHERE email = %s AND titulo = %s AND fecha = %s LIMIT 1",
                    $encargo['email'],
                    $encargo['titulo'],
                    $encargo['fecha'] ?? ''
                )
            );

            if ( $exists ) {
                ++$skipped;
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'nombre'    => sanitize_text_field( $encargo['nombre'] ?? '' ),
                    'email'     => sanitize_email( $encargo['email'] ),
                    'titulo'    => sanitize_text_field( $encargo['titulo'] ),
                    'editorial' => sanitize_text_field( $encargo['editorial'] ?? '' ),
                    'volumenes' => sanitize_text_field( $encargo['volumenes'] ?? '' ),
                    'notas'     => sanitize_textarea_field( $encargo['notas'] ?? '' ),
                    'status'    => in_array( $encargo['status'] ?? '', [ 'pendiente', 'en_gestion', 'lista', 'cancelada' ], true )
                        ? $encargo['status']
                        : 'pendiente',
                    'fecha'     => $encargo['fecha'] ?? current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            ++$count;
        }

        update_option(
            self::ENCARGOS_MIGRATE_FLAG,
            [
                'at'       => time(),
                'migrated' => $count,
                'skipped'  => $skipped,
            ]
        );
    }

    /**
     * Verify Jujutsu kaisen 24/26 encargos are preserved post-migration.
     * Returns array with status for each encargo.
     *
     * @return array{ jjk24: bool, jjk26: bool }
     */
    public static function verify_jjk_encargos(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'akb_special_orders';

        $jjk24 = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE titulo LIKE %s",
                '%Jujutsu%24%'
            )
        );

        $jjk26 = (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$table} WHERE titulo LIKE %s",
                '%Jujutsu%26%'
            )
        );

        return [
            'jjk24' => $jjk24 > 0,
            'jjk26' => $jjk26 > 0,
        ];
    }
}
