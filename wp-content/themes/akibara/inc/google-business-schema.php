<?php
/**
 * Google Business Profile Schema Injector
 * 
 * Inyecta los atributos de AreaServed (SAB) y Location
 * para coincidir con la configuracion de Google Business Profile.
 *
 * @package Akibara
 */
defined( 'ABSPATH' ) || exit;

add_filter( 'rank_math/json_ld', function( $data, $jsonld ) {
    if ( isset( $data['publisher'] ) ) {
        // Asegurar que el tipo sea compatible con tienda de manga
        if ( isset($data['publisher']['@type']) && $data['publisher']['@type'] === 'Organization' ) {
            $data['publisher']['@type'] = ['Organization', 'BookStore', 'OnlineStore'];
        }

        // Definir como Service Area Business (SAB) para Chile
        $data['publisher']['areaServed'] = [
            '@type' => 'Country',
            'name'  => 'Chile',
            'sameAs'=> 'https://en.wikipedia.org/wiki/Chile'
        ];

        // Ocultar direccion especifica, solo nivel de pais y region
        $data['publisher']['location'] = [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressCountry' => 'CL',
                'addressRegion' => 'Región Metropolitana',
                'addressLocality' => 'Santiago'
            ]
        ];

        // Forzar logo correcto
        $data['publisher']['image'] = 'https://akibara.cl/wp-content/themes/akibara/assets/sin-fondo.png';

        // Anadir priceRange requerido por tiendas
        if ( empty( $data['publisher']['priceRange'] ) ) {
            $data['publisher']['priceRange'] = '$';
        }
    }
    return $data;
}, 99, 2 );

