<?php
/**
 * Sitemap and Indexing Enhancements for SEO
 * Ensures better crawling and indexing by Google.
 */

defined( 'ABSPATH' ) || exit;

// One-shot rewrite flush — el sitemap de Rank Math (`/sitemap_index.xml`)
// devolvió HTTP 404 desde 9-abr porque las rewrite rules se perdieron.
// Esto regenera las rules una vez al cargar el tema, luego se marca como hecho.
// Priority 999 garantiza que corre después de que Rank Math/WC registren sus rewrites.
add_action( 'init', function () {
	if ( get_option( 'akibara_rewrite_flush_v4' ) === 'done' ) return;
	flush_rewrite_rules( true ); // true = también escribe .htaccess
	update_option( 'akibara_rewrite_flush_v4', 'done', false );
}, 999 );

// Diagnóstico endpoint — devuelve estado de rewrite rules actuales para sitemap.
// Solo para debugging; bloqueado a admin.
add_action( 'init', function () {
	if ( ! isset( $_GET['akb_rewrite_check'] ) || $_GET['akb_rewrite_check'] !== '1' ) return;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'unauthorized', 403 );
	}
	$rules = get_option( 'rewrite_rules' );
	$matches = [];
	if ( is_array( $rules ) ) {
		foreach ( $rules as $rule => $rewrite ) {
			if ( stripos( $rule, 'sitemap' ) !== false ) {
				$matches[] = "$rule => $rewrite";
			}
		}
	}
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo "Rewrite rules con 'sitemap':\n\n" . implode( "\n", $matches );
	echo "\n\nFlush option: " . var_export( get_option( 'akibara_rewrite_flush_v4' ), true );
	exit;
}, 1 );

function akibara_sitemap_priority( $priority, $post_type, $post ) {
    // Higher priority for product pages
    if ( $post_type === 'product' ) {
        $priority = 0.8;
    }
    return $priority;
}
add_filter( 'rank_math/sitemap/entry_priority', 'akibara_sitemap_priority', 10, 3 );

function akibara_sitemap_changefreq( $changefreq, $post_type, $post ) {
    // More frequent updates for products
    if ( $post_type === 'product' ) {
        $changefreq = 'weekly';
    }
    return $changefreq;
}
add_filter( 'rank_math/sitemap/entry_changefreq', 'akibara_sitemap_changefreq', 10, 3 );

function akibara_robots_txt( $output, $public ) {
    // Encourage crawling of important pages
    $output .= "Allow: /producto/*
";
    $output .= "Allow: /serie/*
";
    $output .= "Allow: /autor/*
";
    return $output;
}
add_filter( 'robots_txt', 'akibara_robots_txt', 10, 2 );
