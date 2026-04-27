<?php
/**
 * Single Product — Ficha Técnica partial
 *
 * Specs del manga/cómic: país, editorial, encuadernación, género, autor,
 * idioma, volumen, páginas, ISBN/SKU. Recopila desde taxonomías + post meta.
 *
 * Inherited from single-product.php: $product.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

global $product;
$product_id = $product->get_id();

// Recopilar specs del producto
$ft_pais   = get_the_terms( $product_id, 'pa_pais' );
$ft_encuad = get_the_terms( $product_id, 'pa_encuadernacion' );
$ft_autor  = get_the_terms( $product_id, 'pa_autor' );
$ft_genero = get_the_terms( $product_id, 'pa_genero' );
$ft_num    = get_post_meta( $product_id, '_akibara_numero', true );
$ft_sku    = $product->get_sku();
$ft_pages  = $product->get_meta( '_akibara_paginas' );
$ft_title  = get_the_title( $product_id );

// Extraer editorial del título (patrón: "Título N – Editorial País")
$ft_editorial = '';
if ( preg_match( '/[–—-]\s*(.+)$/u', $ft_title, $m ) ) {
    $ft_editorial = trim( $m[1] );
}

// Idioma desde país
$ft_pais_name = ( $ft_pais && ! is_wp_error( $ft_pais ) ) ? $ft_pais[0]->name : '';
$ft_idioma    = '';
if ( $ft_pais_name ) {
    $idioma_map = [
        'Argentina'      => 'Español',
        'España'         => 'Español',
        'Chile'          => 'Español',
        'México'         => 'Español',
        'Japón'          => 'Japonés',
        'Estados Unidos' => 'Inglés',
        'Francia'        => 'Francés',
        'Italia'         => 'Italiano',
    ];
    $ft_idioma = $idioma_map[ $ft_pais_name ] ?? 'Español';
}

$specs = [];
if ( $ft_pais_name )                              $specs['País']           = $ft_pais_name;
if ( $ft_editorial )                              $specs['Editorial']      = $ft_editorial;
if ( $ft_encuad && ! is_wp_error( $ft_encuad ) )  $specs['Encuadernación'] = $ft_encuad[0]->name;
if ( $ft_genero && ! is_wp_error( $ft_genero ) )  $specs['Género']         = implode( ', ', wp_list_pluck( $ft_genero, 'name' ) );
if ( $ft_autor  && ! is_wp_error( $ft_autor ) )   $specs['Autor']          = implode( ', ', wp_list_pluck( $ft_autor, 'name' ) );
if ( $ft_idioma )                                 $specs['Idioma']         = $ft_idioma;
if ( $ft_num )                                    $specs['Volumen']        = 'Vol. ' . $ft_num;
if ( $ft_pages )                                  $specs['Páginas']        = $ft_pages . ' págs.';
if ( $ft_sku )                                    $specs['ISBN / SKU']     = $ft_sku;

if ( count( $specs ) < 2 ) return;
?>
<div class="product-ficha section-wrapper">
    <h3 class="product-ficha__title">
        <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Ficha Técnica
    </h3>
    <div class="product-ficha__grid">
        <?php
        // Build linked values for taxonomy-backed specs
        $linked_specs = [];
        if ( $ft_pais && ! is_wp_error( $ft_pais ) ) {
            $linked_specs['País'] = '<a href="' . esc_url( get_term_link( $ft_pais[0] ) ) . '">' . esc_html( $ft_pais[0]->name ) . '</a>';
        }
        if ( $ft_editorial ) {
            $brand_term = get_term_by( 'name', $ft_editorial, 'product_brand' );
            if ( ! $brand_term ) {
                $brand_term = get_term_by( 'name', preg_replace( '/\s+(Argentina|España|Chile)$/i', '', $ft_editorial ), 'product_brand' );
            }
            if ( $brand_term ) {
                $linked_specs['Editorial'] = '<a href="' . esc_url( get_term_link( $brand_term ) ) . '">' . esc_html( $ft_editorial ) . '</a>';
            }
        }
        if ( $ft_genero && ! is_wp_error( $ft_genero ) ) {
            $genre_links = [];
            foreach ( $ft_genero as $g ) {
                $genre_links[] = '<a href="' . esc_url( get_term_link( $g ) ) . '">' . esc_html( $g->name ) . '</a>';
            }
            $linked_specs['Género'] = implode( ', ', $genre_links );
        }
        if ( $ft_autor && ! is_wp_error( $ft_autor ) ) {
            $author_links = [];
            foreach ( $ft_autor as $a ) {
                $author_links[] = '<a href="' . esc_url( get_term_link( $a ) ) . '">' . esc_html( $a->name ) . '</a>';
            }
            $linked_specs['Autor'] = implode( ', ', $author_links );
        }

        foreach ( $specs as $label => $value ) : ?>
        <div class="product-ficha__row">
            <span class="product-ficha__label"><?php echo esc_html( $label ); ?></span>
            <span class="product-ficha__value"><?php
                if ( isset( $linked_specs[ $label ] ) ) {
                    echo wp_kses( $linked_specs[ $label ], [ 'a' => [ 'href' => [] ] ] );
                } else {
                    echo esc_html( $value );
                }
            ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
