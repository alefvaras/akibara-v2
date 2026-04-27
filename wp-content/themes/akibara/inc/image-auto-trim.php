<?php
/**
 * Image auto-trim — elimina padding blanco al subir imagen de producto.
 *
 * Dispara en `add_attachment` (ANTES de que WP genere thumbnails), así el
 * flujo normal de wp_generate_attachment_metadata produce variants sobre
 * el original ya recortado. No tocamos thumbs existentes aquí porque aún
 * no fueron creados.
 *
 * Solo actúa sobre attachments cuyo parent es un producto WooCommerce.
 * Escapes:
 *   - meta `_akb_skip_trim` = '1' → nunca trim este attachment
 *   - meta `_akb_auto_trimmed` = '1' → ya procesado, skip
 *
 * Log: /wp-content/uploads/_akb-auto-trim.log (CSV append)
 * Backup: /wp-content/uploads/_akb-trim-backup/YYYYMMDD/<aid>/
 *
 * @package Akibara
 * @since   2026-04-22
 */

defined( 'ABSPATH' ) || exit;

const AKB_TRIM_THRESHOLD_PCT = 10;  // área mínima de padding para activar
const AKB_TRIM_TOLERANCE     = 8;   // considera blanco si R,G,B ≥ 255-tolerance
const AKB_TRIM_MARGIN        = 2;   // px margen a preservar alrededor del contenido

add_action( 'add_attachment', 'akb_auto_trim_on_upload', 20 );

/**
 * @param int $attachment_id
 */
function akb_auto_trim_on_upload( $attachment_id ) {
    // Escapes por metadata
    if ( get_post_meta( $attachment_id, '_akb_skip_trim', true ) ) {
        return;
    }
    if ( get_post_meta( $attachment_id, '_akb_auto_trimmed', true ) ) {
        return;
    }

    // Solo si es child de un producto WC
    $parent_id = (int) wp_get_post_parent_id( $attachment_id );
    if ( ! $parent_id || get_post_type( $parent_id ) !== 'product' ) {
        return;
    }

    // Permitir override via filter
    if ( ! apply_filters( 'akb_auto_trim_should_apply', true, $attachment_id, $parent_id ) ) {
        return;
    }

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        return;
    }
    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'webp', 'jpg', 'jpeg', 'png' ], true ) ) {
        return;
    }

    $img = akb_trim_load_gd( $file, $ext );
    if ( ! $img ) {
        return;
    }

    $w = imagesx( $img );
    $h = imagesy( $img );

    [ $pad_l, $pad_r, $pad_t, $pad_b ] = akb_trim_detect_padding( $img, $w, $h );
    imagedestroy( $img );

    // Apply margin
    $pad_l = max( 0, $pad_l - AKB_TRIM_MARGIN );
    $pad_r = max( 0, $pad_r - AKB_TRIM_MARGIN );
    $pad_t = max( 0, $pad_t - AKB_TRIM_MARGIN );
    $pad_b = max( 0, $pad_b - AKB_TRIM_MARGIN );

    $new_w      = $w - $pad_l - $pad_r;
    $new_h      = $h - $pad_t - $pad_b;
    $padding_px = ( $pad_l + $pad_r ) * $h + ( $pad_t + $pad_b ) * $w - ( $pad_l + $pad_r ) * ( $pad_t + $pad_b );
    $pct        = $w * $h > 0 ? ( $padding_px / ( $w * $h ) ) * 100 : 0;

    if ( $pct < AKB_TRIM_THRESHOLD_PCT || $new_w <= 0 || $new_h <= 0 ) {
        return;
    }

    // Heurística: para portrait nativo (h > w) solo trim si padding lateral es consistente (>30px ambos lados).
    // Los portraits bien cortados no deberían tener padding lateral significativo.
    if ( $h > $w && ( $pad_l < 30 || $pad_r < 30 ) ) {
        return;
    }

    // Backup original (thumbnails aún no existen en este hook)
    $backup_dir = WP_CONTENT_DIR . '/uploads/_akb-trim-backup/' . date( 'Ymd' ) . '/' . $attachment_id;
    if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
        return;
    }
    if ( ! copy( $file, $backup_dir . '/' . basename( $file ) ) ) {
        return;
    }

    // Re-load + crop + save
    $src = akb_trim_load_gd( $file, $ext );
    if ( ! $src ) {
        return;
    }
    $dst = imagecreatetruecolor( $new_w, $new_h );
    if ( $ext === 'png' || $ext === 'webp' ) {
        imagealphablending( $dst, false );
        imagesavealpha( $dst, true );
    }
    imagecopy( $dst, $src, 0, 0, $pad_l, $pad_t, $new_w, $new_h );

    $ok = false;
    switch ( $ext ) {
        case 'webp':
            $ok = imagewebp( $dst, $file, 82 );
            break;
        case 'jpg':
        case 'jpeg':
            $ok = imagejpeg( $dst, $file, 88 );
            break;
        case 'png':
            $ok = imagepng( $dst, $file, 6 );
            break;
    }
    imagedestroy( $src );
    imagedestroy( $dst );

    if ( ! $ok ) {
        // Rollback
        copy( $backup_dir . '/' . basename( $file ), $file );
        akb_trim_log( $attachment_id, $parent_id, $w, $h, $new_w, $new_h, 'FAIL_SAVE' );
        return;
    }

    // Marcar como procesado (evita doble-trim en regen posteriores)
    update_post_meta( $attachment_id, '_akb_auto_trimmed', '1' );
    update_post_meta( $attachment_id, '_akb_auto_trimmed_orig', "{$w}x{$h}" );
    akb_trim_log( $attachment_id, $parent_id, $w, $h, $new_w, $new_h, 'OK' );
}

/**
 * @param string $file
 * @param string $ext
 * @return \GdImage|resource|false
 */
function akb_trim_load_gd( $file, $ext ) {
    switch ( $ext ) {
        case 'webp':
            return @imagecreatefromwebp( $file );
        case 'jpg':
        case 'jpeg':
            return @imagecreatefromjpeg( $file );
        case 'png':
            return @imagecreatefrompng( $file );
    }
    return false;
}

/**
 * @return array{0:int,1:int,2:int,3:int}  [pad_l, pad_r, pad_t, pad_b]
 */
function akb_trim_detect_padding( $img, int $w, int $h ): array {
    $white_min = 255 - AKB_TRIM_TOLERANCE;
    $pad_l     = $pad_r = $pad_t = $pad_b = 0;

    for ( $y = 0; $y < $h; $y++ ) {
        for ( $x = 0; $x < $w; $x += 2 ) {
            $rgb = imagecolorat( $img, $x, $y );
            if ( ( ( $rgb >> 16 ) & 0xFF ) < $white_min || ( ( $rgb >> 8 ) & 0xFF ) < $white_min || ( $rgb & 0xFF ) < $white_min ) {
                $pad_t = $y; break 2;
            }
        }
    }
    for ( $y = $h - 1; $y >= 0; $y-- ) {
        for ( $x = 0; $x < $w; $x += 2 ) {
            $rgb = imagecolorat( $img, $x, $y );
            if ( ( ( $rgb >> 16 ) & 0xFF ) < $white_min || ( ( $rgb >> 8 ) & 0xFF ) < $white_min || ( $rgb & 0xFF ) < $white_min ) {
                $pad_b = $h - 1 - $y; break 2;
            }
        }
    }
    for ( $x = 0; $x < $w; $x++ ) {
        for ( $y = 0; $y < $h; $y += 2 ) {
            $rgb = imagecolorat( $img, $x, $y );
            if ( ( ( $rgb >> 16 ) & 0xFF ) < $white_min || ( ( $rgb >> 8 ) & 0xFF ) < $white_min || ( $rgb & 0xFF ) < $white_min ) {
                $pad_l = $x; break 2;
            }
        }
    }
    for ( $x = $w - 1; $x >= 0; $x-- ) {
        for ( $y = 0; $y < $h; $y += 2 ) {
            $rgb = imagecolorat( $img, $x, $y );
            if ( ( ( $rgb >> 16 ) & 0xFF ) < $white_min || ( ( $rgb >> 8 ) & 0xFF ) < $white_min || ( $rgb & 0xFF ) < $white_min ) {
                $pad_r = $w - 1 - $x; break 2;
            }
        }
    }

    return [ $pad_l, $pad_r, $pad_t, $pad_b ];
}

function akb_trim_log( int $aid, int $pid, int $w, int $h, int $new_w, int $new_h, string $status ): void {
    $log_path = WP_CONTENT_DIR . '/uploads/_akb-auto-trim.log';
    $exists   = file_exists( $log_path );
    $fh       = @fopen( $log_path, 'a' );
    if ( ! $fh ) {
        return;
    }
    if ( ! $exists ) {
        fputcsv( $fh, [ 'ts', 'attachment_id', 'product_id', 'old_w', 'old_h', 'new_w', 'new_h', 'status' ] );
    }
    fputcsv( $fh, [ date( 'c' ), $aid, $pid, $w, $h, $new_w, $new_h, $status ] );
    fclose( $fh );
}
