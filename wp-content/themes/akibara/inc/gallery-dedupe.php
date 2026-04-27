<?php
/**
 * Dedupe perceptual de imágenes en galería de producto.
 *
 * Usa pHash (8x8 grayscale average) cacheado en postmeta `_akibara_phash`.
 * Dos imágenes con Hamming distance <= AKIBARA_PHASH_THRESHOLD se consideran
 * duplicados visuales, aunque sean attachments distintos o re-encodings.
 *
 * Uso en template:
 *   $gallery_ids = akibara_product_unique_gallery_ids( $product );
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKIBARA_PHASH_THRESHOLD' ) ) {
    define( 'AKIBARA_PHASH_THRESHOLD', 8 ); // bits (de 64) que pueden diferir
}

/**
 * Calcula (o recupera de caché) el perceptual hash de un attachment.
 *
 * Hash = 64 bits (string '0'/'1') basado en 8x8 grayscale vs media.
 * Robusto a cambios de compresión, tamaño, formato (webp/jpg), pero detecta
 * misma imagen. Si GD no está disponible o el archivo no existe, retorna ''.
 *
 * @param int $attachment_id
 * @return string 64-char binary string, o '' si no se pudo calcular.
 */
function akibara_image_phash( $attachment_id ) {
    $attachment_id = (int) $attachment_id;
    if ( ! $attachment_id ) {
        return '';
    }

    $cached = get_post_meta( $attachment_id, '_akibara_phash', true );
    if ( is_string( $cached ) && strlen( $cached ) === 64 ) {
        return $cached;
    }

    if ( ! function_exists( 'imagecreatefromstring' ) ) {
        return '';
    }

    // Preferimos el tamaño thumbnail (más rápido de procesar); fallback al original.
    $src = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
    $data = false;
    if ( $src ) {
        $path = str_replace(
            trailingslashit( wp_get_upload_dir()['baseurl'] ),
            trailingslashit( wp_get_upload_dir()['basedir'] ),
            $src
        );
        if ( file_exists( $path ) ) {
            $data = @file_get_contents( $path );
        }
    }
    if ( ! $data ) {
        $file = get_attached_file( $attachment_id );
        if ( $file && file_exists( $file ) ) {
            $data = @file_get_contents( $file );
        }
    }
    if ( ! $data ) {
        return '';
    }

    $img = @imagecreatefromstring( $data );
    if ( ! $img ) {
        return '';
    }

    $size  = 8;
    $small = imagecreatetruecolor( $size, $size );
    imagecopyresampled( $small, $img, 0, 0, 0, 0, $size, $size, imagesx( $img ), imagesy( $img ) );
    imagedestroy( $img );

    $pixels = [];
    $sum    = 0;
    for ( $y = 0; $y < $size; $y++ ) {
        for ( $x = 0; $x < $size; $x++ ) {
            $rgb     = imagecolorat( $small, $x, $y );
            $r       = ( $rgb >> 16 ) & 0xFF;
            $g       = ( $rgb >> 8 ) & 0xFF;
            $b       = $rgb & 0xFF;
            $grey    = (int) ( ( $r + $g + $b ) / 3 );
            $pixels[] = $grey;
            $sum     += $grey;
        }
    }
    imagedestroy( $small );

    $avg  = $sum / count( $pixels );
    $bits = '';
    foreach ( $pixels as $p ) {
        $bits .= ( $p >= $avg ? '1' : '0' );
    }

    update_post_meta( $attachment_id, '_akibara_phash', $bits );
    return $bits;
}

/**
 * Hamming distance entre dos strings binarios del mismo largo.
 *
 * @param string $a
 * @param string $b
 * @return int
 */
function akibara_phash_hamming( $a, $b ) {
    if ( ! is_string( $a ) || ! is_string( $b ) || strlen( $a ) !== strlen( $b ) || '' === $a ) {
        return PHP_INT_MAX;
    }
    // XOR byte-wise no aplica a strings '0'/'1'; comparamos char a char.
    $len = strlen( $a );
    $d   = 0;
    for ( $i = 0; $i < $len; $i++ ) {
        if ( $a[ $i ] !== $b[ $i ] ) {
            $d++;
        }
    }
    return $d;
}

/**
 * Retorna los IDs de galería del producto SIN duplicados visuales ni la imagen principal.
 *
 * Aplica dedupe por:
 *   - ID: descarta $gid === $main_img_id
 *   - pHash: descarta si Hamming <= AKIBARA_PHASH_THRESHOLD vs main o previos
 *
 * Si pHash no se puede calcular (GD ausente o archivo perdido), mantiene el ID.
 *
 * @param WC_Product $product
 * @return int[]
 */
function akibara_product_unique_gallery_ids( $product ) {
    if ( ! $product instanceof WC_Product ) {
        return [];
    }

    $main_id = (int) $product->get_image_id();
    $gallery = array_map( 'intval', (array) $product->get_gallery_image_ids() );

    $kept   = [];
    $hashes = [];

    if ( $main_id ) {
        $h = akibara_image_phash( $main_id );
        if ( $h ) {
            $hashes[] = $h;
        }
    }

    foreach ( $gallery as $gid ) {
        if ( ! $gid || $gid === $main_id ) {
            continue;
        }

        $h = akibara_image_phash( $gid );

        if ( $h ) {
            $dup = false;
            foreach ( $hashes as $prev ) {
                if ( akibara_phash_hamming( $h, $prev ) <= AKIBARA_PHASH_THRESHOLD ) {
                    $dup = true;
                    break;
                }
            }
            if ( $dup ) {
                continue;
            }
            $hashes[] = $h;
        }

        $kept[] = $gid;
    }

    return $kept;
}

/**
 * Invalida el hash cacheado cuando se regenera/edita un attachment.
 */
add_action( 'delete_attachment', function ( $post_id ) {
    delete_post_meta( $post_id, '_akibara_phash' );
} );
add_action( 'wp_update_attachment_metadata', function ( $meta, $post_id ) {
    delete_post_meta( $post_id, '_akibara_phash' );
    return $meta;
}, 10, 2 );
