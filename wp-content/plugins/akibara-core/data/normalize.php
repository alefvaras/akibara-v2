<?php
/**
 * Akibara — Funciones de normalización compartidas.
 * Compatible con SHORTINIT (sin dependencias de WordPress).
 *
 * Se incluye con require_once desde akibara-core.php y search.php.
 * Define las funciones solo si no existen (para evitar conflictos).
 *
 * @package Akibara
 */

if ( function_exists( 'akb_strip_accents' ) ) {
	return;
}

function akb_strip_accents( string $s ): string {
	static $from = null, $to = null;
	if ( $from === null ) {
		$from = array(
			'á',
			'é',
			'í',
			'ó',
			'ú',
			'ü',
			'ñ',
			'à',
			'è',
			'ì',
			'ò',
			'ù',
			'â',
			'ê',
			'î',
			'ô',
			'û',
			'ä',
			'ë',
			'ï',
			'ö',
			'ã',
			'õ',
			'Á',
			'É',
			'Í',
			'Ó',
			'Ú',
			'Ü',
			'Ñ',
			'À',
			'È',
			'Ì',
			'Ò',
			'Ù',
			'Â',
			'Ê',
			'Î',
			'Ô',
			'Û',
			'Ä',
			'Ë',
			'Ï',
			'Ö',
			'Ã',
			'Õ',
			'ç',
			'Ç',
			'ý',
			'Ý',
			'ß',
			'æ',
			'Æ',
			'ø',
			'Ø',
			'å',
			'Å',
			'č',
			'š',
			'ž',
			'ř',
			'ě',
		);
		$to   = array(
			'a',
			'e',
			'i',
			'o',
			'u',
			'u',
			'n',
			'a',
			'e',
			'i',
			'o',
			'u',
			'a',
			'e',
			'i',
			'o',
			'u',
			'a',
			'e',
			'i',
			'o',
			'a',
			'o',
			'a',
			'e',
			'i',
			'o',
			'u',
			'u',
			'n',
			'a',
			'e',
			'i',
			'o',
			'u',
			'a',
			'e',
			'i',
			'o',
			'u',
			'a',
			'e',
			'i',
			'o',
			'a',
			'o',
			'c',
			'c',
			'y',
			'y',
			'ss',
			'ae',
			'ae',
			'o',
			'o',
			'a',
			'a',
			'c',
			's',
			'z',
			'r',
			'e',
		);
	}
	return str_replace( $from, $to, $s );
}

function akb_normalize( string $text, bool $compact = false ): string {
	$s = mb_strtolower( $text, 'UTF-8' );
	$s = akb_strip_accents( $s );
	$s = preg_replace( '/[\x{2013}\x{2014}\x{2012}\x{2212}]/u', ' ', $s );
	$s = preg_replace( '/\b(?:n[°º]\.?|vol\.?|tomo\.?|tome\.?|cap\.?|#)\s*/ui', '', $s );
	$s = preg_replace( '/[:()\[\]{}\'"!?&+*\/\\\\,;.@]/', ' ', $s );
	$s = str_replace( '-', ' ', $s );
	$s = preg_replace( '/\s+/', $compact ? '' : ' ', trim( $s ) );
	return $s;
}
