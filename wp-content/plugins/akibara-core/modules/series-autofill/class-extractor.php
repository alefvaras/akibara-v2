<?php
/**
 * Akibara — Series Autofill: Extractor de Serie/Número desde título
 *
 * Lógica pura, sin dependencias de WP, testeable aislada. Los hooks del módulo
 * obtienen el título/brand/número y delegan aquí toda la decisión.
 *
 * Diseño:
 *  - `extract_serie()` quita editorial conocida, número y paréntesis, deja la serie.
 *  - `extract_numero()` saca el número desde patrones comunes (fallback cuando
 *    `_akibara_numero` no existe).
 *  - `normalize_serie_slug()` produce el slug consistente usado en URLs /serie/.
 */

namespace Akibara\SeriesAutofill;

defined( 'ABSPATH' ) || exit;

class Extractor {

	/** Editoriales conocidas — se quitan del sufijo del título. */
	public const PUBLISHERS = array(
		'Ivrea',
		'Panini',
		'Planeta',
		'Milky Way',
		'Ovni Press',
		'Arechi Manga',
		'Kamite',
		'Kemuri',
		'Norma',
		'Hotaru',
		'Distrito Manga',
	);

	/** Países — opcionales detrás de la editorial. */
	public const COUNTRIES = array( 'Argentina', 'España', 'Espana', 'México', 'Mexico', 'Chile' );

	/**
	 * Extrae el nombre de la serie desde un título.
	 *
	 * @param string $title  Título del producto (ej. "One Piece 74 – Ivrea Argentina").
	 * @param string $number Valor de `_akibara_numero` si existe (ancla más confiable que regex).
	 * @param string $brand  Nombre de `product_brand` si existe (ancla para quitar editorial).
	 * @return string Nombre limpio de la serie, o '' si no se pudo determinar.
	 */
	public static function extract_serie( string $title, string $number = '', string $brand = '' ): string {
		$s = trim( $title );
		if ( $s === '' ) {
			return '';
		}

		// 1) Quitar la editorial específica del producto (viene de product_brand)
		if ( $brand !== '' ) {
			$b = preg_quote( $brand, '/' );
			$s = preg_replace( '/\s*[–—\-]\s*' . $b . '\s*$/iu', '', $s );
			$s = preg_replace( '/\s+' . $b . '\s*$/iu', '', $s );
		}

		// 2) Quitar sufijo genérico de editorial + país (cubre casos donde brand
		// no coincide exactamente con lo del título)
		$pubs = implode( '|', array_map( static fn( $p ) => preg_quote( $p, '/' ), self::PUBLISHERS ) );
		$cts  = implode( '|', array_map( static fn( $c ) => preg_quote( $c, '/' ), self::COUNTRIES ) );
		$s    = preg_replace( "/\s*[–—\-]\s*(?:{$pubs})(?:\s+(?:{$cts}))?\s*$/iu", '', $s );
		$s    = preg_replace( "/\s+(?:{$pubs})(?:\s+(?:{$cts}))?\s*$/iu", '', $s );

		// 3) Quitar paréntesis y su contenido (ej. "(3 en 1)", "(edición especial)")
		$s = preg_replace( '/\s*\([^)]+\)\s*/u', ' ', $s );

		// 4) Quitar el número del tomo (preferir el meta; fallback a último número del título)
		if ( $number !== '' && $number !== '0' && (int) $number > 0 ) {
			$n = preg_quote( (string) $number, '/' );
			$s = preg_replace( '/\s+(?:N[o°]?\.?\s*|Vol\.?\s*|Tomo\s*|#)?' . $n . '\s*$/iu', '', $s );
		} else {
			$s = preg_replace( '/\s+(?:N[o°]?\.?\s*|Vol\.?\s*|Tomo\s*|#)?\d+\s*$/u', '', $s );
		}

		// 5) Limpiar separadores y espacios sobrantes
		$s = preg_replace( '/[–—\-]+\s*$/u', '', $s );
		$s = preg_replace( '/\s{2,}/', ' ', $s );
		$s = trim( $s );

		return $s;
	}

	/**
	 * Extrae el número de tomo desde un título (fallback cuando el meta no existe).
	 *
	 * @param string $title Título del producto.
	 * @return string Número como string (preserva formato original) o '' si no se encontró.
	 */
	public static function extract_numero( string $title ): string {
		$s = trim( $title );
		if ( $s === '' ) {
			return '';
		}

		// Quitar editorial al final para no confundir con números en nombre editorial
		$pubs = implode( '|', array_map( static fn( $p ) => preg_quote( $p, '/' ), self::PUBLISHERS ) );
		$cts  = implode( '|', array_map( static fn( $c ) => preg_quote( $c, '/' ), self::COUNTRIES ) );
		$s    = preg_replace( "/\s*[–—\-]\s*(?:{$pubs})(?:\s+(?:{$cts}))?\s*$/iu", '', $s );
		$s    = preg_replace( "/\s+(?:{$pubs})(?:\s+(?:{$cts}))?\s*$/iu", '', $s );
		$s    = preg_replace( '/\s*\([^)]+\)\s*/u', ' ', $s );

		// Buscar número al final (con o sin prefijo N°/Vol./Tomo/#)
		if ( preg_match( '/\s+(?:N[o°]?\.?\s*|Vol\.?\s*|Tomo\s*|#)?(\d+)\s*$/iu', $s, $m ) ) {
			return $m[1];
		}

		return '';
	}

	/**
	 * Normaliza el nombre de serie a slug URL-friendly (compatible con wp_akb_series_subs).
	 *
	 * @param string $serie Nombre limpio de la serie.
	 * @return string Slug normalizado (minúsculas, ASCII, sin espacios).
	 */
	public static function normalize_serie_slug( string $serie ): string {
		$s = $serie;
		// Transliterar a ASCII (sanitize_title hace esto internamente)
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $s );
		}
		// Fallback offline: minúsculas + espacios a guiones
		$s = mb_strtolower( $s, 'UTF-8' );
		$s = preg_replace( '/[^a-z0-9]+/u', '-', $s );
		$s = trim( $s, '-' );
		return $s;
	}
}
