<?php
/**
 * Blog WebP
 *
 * Genera subsizes WebP paralelos a los JPG/PNG para los tamaños blog-card,
 * blog-featured y blog-related. Reemplaza src/srcset de JPG por WebP al renderizar.
 *
 * Alcance acotado: solo featured images de post_type=post. No toca productos
 * ni heroes — el resto del sitio sigue comportamiento por defecto.
 *
 * Dependencias: GD o Imagick con soporte WebP (disponible en Hostinger).
 *
 * @package Akibara
 * @since   4.6.1
 */

defined( "ABSPATH" ) || exit;

const AKIBARA_BLOG_WEBP_SIZES = [ "blog-card", "blog-featured", "blog-related" ];

/**
 * Check si el attachment está usado como featured en algún post_type=post.
 *
 * Cacheado por request para evitar N queries en loops.
 */
function akibara_blog_webp_is_post_thumbnail( int $attachment_id ): bool {
    static $cache = [];
    if ( isset( $cache[ $attachment_id ] ) ) {
        return $cache[ $attachment_id ];
    }
    global $wpdb;
    $parent_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT pm.post_id
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_thumbnail_id'
           AND pm.meta_value = %d
           AND p.post_type = 'post'
         LIMIT 1",
        $attachment_id
    ) );
    return $cache[ $attachment_id ] = ! empty( $parent_id );
}

/**
 * Tras generar subsizes, crea copias .webp para los 3 sizes del blog.
 *
 * Usa wp_get_image_editor (GD o Imagick) con quality 82 (match LiteSpeed default).
 */
add_filter( "wp_generate_attachment_metadata", function ( $metadata, $attachment_id ) {
    if ( empty( $metadata["sizes"] ) || ! is_array( $metadata["sizes"] ) ) {
        return $metadata;
    }
    if ( ! akibara_blog_webp_is_post_thumbnail( (int) $attachment_id ) ) {
        return $metadata;
    }

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) {
        return $metadata;
    }
    $base_dir = dirname( $file );

    foreach ( AKIBARA_BLOG_WEBP_SIZES as $size ) {
        if ( empty( $metadata["sizes"][ $size ]["file"] ) ) {
            continue;
        }
        $src_file = $base_dir . "/" . $metadata["sizes"][ $size ]["file"];
        if ( ! file_exists( $src_file ) ) {
            continue;
        }
        // Skip si ya es webp original o ya existe el destino.
        if ( preg_match( '/\.webp$/i', $src_file ) ) {
            continue;
        }
        $webp_file = preg_replace( '/\.(jpe?g|png)$/i', ".webp", $src_file );
        if ( $webp_file === $src_file ) {
            continue;
        }
        if ( file_exists( $webp_file ) ) {
            continue;
        }

        $editor = wp_get_image_editor( $src_file );
        if ( is_wp_error( $editor ) ) {
            continue;
        }
        $editor->set_quality( 82 );
        $editor->save( $webp_file, "image/webp" );
    }

    return $metadata;
}, 20, 2 );

/**
 * Reemplaza src/srcset de JPG|PNG por .webp cuando el size es del blog
 * y el archivo .webp existe en disco.
 *
 * Opera solo sobre los sizes declarados en AKIBARA_BLOG_WEBP_SIZES,
 * por lo que el resto de imágenes del sitio queda intacto.
 */
add_filter( "wp_get_attachment_image_attributes", function ( $attr, $attachment, $size ) {
    if ( ! is_string( $size ) || ! in_array( $size, AKIBARA_BLOG_WEBP_SIZES, true ) ) {
        return $attr;
    }

    $upload_dir = wp_get_upload_dir();
    $base_url   = trailingslashit( $upload_dir["baseurl"] );
    $base_dir   = trailingslashit( $upload_dir["basedir"] );

    $swap = static function ( string $url ) use ( $base_url, $base_dir ): string {
        if ( ! preg_match( '/\.(jpe?g|png)(\?|$)/i', $url ) ) {
            return $url;
        }
        if ( strpos( $url, $base_url ) !== 0 ) {
            return $url;
        }
        $webp_url = preg_replace( '/\.(jpe?g|png)(\?|$)/i', ".webp$2", $url );
        $rel_path = substr( $webp_url, strlen( $base_url ) );
        $abs_path = $base_dir . $rel_path;
        // Solo reemplazar si el .webp efectivamente existe en disco.
        return file_exists( $abs_path ) ? $webp_url : $url;
    };

    if ( ! empty( $attr["src"] ) ) {
        $attr["src"] = $swap( $attr["src"] );
    }
    if ( ! empty( $attr["srcset"] ) ) {
        $attr["srcset"] = preg_replace_callback(
            '/([^\s,]+)(\s+[^,]+)?/',
            static function ( $m ) use ( $swap ) {
                return $swap( $m[1] ) . ( $m[2] ?? "" );
            },
            $attr["srcset"]
        );
    }

    return $attr;
}, 10, 3 );
