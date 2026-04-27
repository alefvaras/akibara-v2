<?php
/**
 * Akibara Core – Shared Helpers
 *
 * Sprint 2 Cell Core Phase 1 (2026-04-27): relocated desde
 * `plugins/akibara/includes/akibara-core.php` legacy.
 *
 * Librería base: normalización, extracción serie/tomo, patrones editoriales.
 * Funciones públicas: `akb_extract_info()`, `akb_editorial_pattern()`,
 * `akb_edition_pattern()`, + helpers de `data/normalize.php` (`akb_normalize`,
 * `akb_strip_accents`).
 *
 * Idempotent: si las funciones ya están loaded por otro path (e.g., legacy
 * plugin akibara/ active simultáneamente con akibara-core), early return
 * sin re-declarar.
 *
 * @package Akibara\Core
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Guard: cargar SOLO si plugin akibara legacy (V10) o akibara-core están active.
if ( ! defined( 'AKIBARA_V10_LOADED' ) && ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}

// Idempotent guard: si helpers ya están loaded por otro path, skip.
if ( defined( 'AKB_CORE_HELPERS_LOADED' ) ) {
	return;
}
define( 'AKB_CORE_HELPERS_LOADED', '1.0.0' );

// ═══════════════════════════════════════════════════════════════
// NORMALIZACIÓN — carga desde data/normalize.php (fuente única)
// ═══════════════════════════════════════════════════════════════
// Si ya cargó normalize.php desde otra path (e.g., legacy `akibara-core.php`
// internal file), skip via function_exists check.
if ( ! function_exists( 'akb_normalize' ) ) {
	require_once __DIR__ . '/../data/normalize.php';
}

// ═══════════════════════════════════════════════════════════════
// PATRONES DE EDITORIALES Y EDICIONES
// ═══════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_editorial_pattern' ) ) {
	/**
	 * Regex para detectar editorial (+ país opcional) al final de un título.
	 * Ejemplo: "– Panini Argentina", "- Ivrea España"
	 */
	function akb_editorial_pattern(): string {
		$eds    = array(
			'Panini',
			'Ivrea',
			'Milky\s*Way',
			'Norma',
			'ECC',
			'Kodai',
			'Planeta',
			'Kamite',
			'Distrito\s*Manga',
			'Moztros',
			'OVNI\s*Press',
			'Babylon',
			'Utopia',
			'Gl[eé]nat',
			'Totem',
			'Bruguera',
			'Grijalbo',
		);
		$paises = array(
			'Argentina',
			'Espa[nñ]a',
			'Mexico',
			'M[eé]xico',
			'Chile',
			'Per[uú]',
			'Colombia',
			'Paraguay',
			'Uruguay',
			'Brasil',
			'Venezuela',
			'Bolivia',
			'Ecuador',
		);
		$ed_rx  = implode( '|', $eds );
		$pai_rx = implode( '|', $paises );
		return "(?:{$ed_rx})(?:\s+(?:{$pai_rx}))?";
	}
}

if ( ! function_exists( 'akb_edition_pattern' ) ) {
	/**
	 * Regex para detectar formatos de edición especial (Kanzenban, Deluxe, etc.)
	 */
	function akb_edition_pattern(): string {
		return implode(
			'|',
			array(
				'Kanzenban',
				'Kanzen',
				'Deluxe',
				'Ultimate',
				'Master',
				'Perfect',
				'Big',
				'Complete',
				'Premium',
				'Integral',
				'Omnibus',
				'Maximum',
				'Absolute',
				'Oversized',
				'Gaucho',
				'Collectors?',
				'Anniversary',
				'Gold\s*Edition',
				'Silver\s*Edition',
				'Full\s*Color',
				'Color\s*Edition',
				'Digital\s*Colored',
				'Wideban',
				'Wide',
				'Jump\s*Remix',
				'Bunko',
			)
		);
	}
}

// ═══════════════════════════════════════════════════════════════
// EXTRACTOR SERIE + TOMO
// ═══════════════════════════════════════════════════════════════

if ( ! function_exists( 'akb_extract_info' ) ) {
	/**
	 * Extrae serie, número de tomo, tipo y prioridad de un título de producto.
	 *
	 * @return array{
	 *   serie:      string,   Nombre de la serie para mostrar
	 *   serie_norm: string,   Clave de agrupación (lowercase, sin tildes, sin espacios)
	 *   numero:     int,      Número de tomo
	 *   tipo:       string,   estandar | formato_especial | compilacion | tomo_unico | box_set | sin_numero
	 *   prioridad:  int       Offset dentro de la serie (0=normal, 9000+=especiales al final)
	 * }
	 */
	function akb_extract_info( string $titulo ): array {
		$empty = array(
			'serie'      => '',
			'serie_norm' => '',
			'numero'     => 0,
			'tipo'       => 'invalido',
			'prioridad'  => 0,
		);

		if ( empty( $titulo ) || ! is_string( $titulo ) ) {
			return $empty;
		}

		// ── Limpiar editorial del final ──────────────────────────────
		$editorial_rx = akb_editorial_pattern();
		$limpio       = preg_replace(
			"/\s*(?:[-–—]\s*)?(?:{$editorial_rx})\s*$/iu",
			'',
			$titulo
		);
		$limpio       = trim( $limpio );

		// ── Patrones en orden de especificidad (el primero que matchea gana) ──
		$edition_rx    = akb_edition_pattern();
		$special_start = defined( 'AKB_SPECIAL_OFFSET' ) ? AKB_SPECIAL_OFFSET : 9000;

		$patterns = array(

			// Box Set / Colección completa (sin número)
			array(
				'/\b(?:box[\s-]?set|serie\s+completa|pack\s+completo|colecci[oó]n\s+completa)\b/iu',
				function ( $m, $t ) use ( $special_start ) {
					$s = trim( preg_replace( '/\s*\b(?:box[\s-]?set|serie\s+completa|pack\s+completo|colecci[oó]n\s+completa).*$/iu', '', $t ) );
					return array( $s ?: $t, 9999, 'box_set', $special_start + 500 );
				},
			),

			// Artbook / Visual Book (sin número)
			array(
				'/\b(?:artbook|art\s+book|visual\s+book|illustrations?|guide\s+book)\b/iu',
				function ( $m, $t ) use ( $special_start ) {
					$s = trim( preg_replace( '/\s*\b(?:artbook|art\s+book|visual\s+book|illustrations?|guide\s+book).*$/iu', '', $t ) );
					return array( $s ?: $t, 9998, 'artbook', $special_start + 300 );
				},
			),

			// Tomo Único / One-Shot (sin número)
			array(
				'/\b(?:tomo\s+[uú]nico|one[\s-]?shot|volume\s+[uú]nico)\b/iu',
				function ( $m, $t ) use ( $special_start ) {
					$s = trim( preg_replace( '/\s*\b(?:tomo\s+[uú]nico|one[\s-]?shot|volume\s+[uú]nico).*$/iu', '', $t ) );
					return array( $s ?: $t, 9997, 'tomo_unico', $special_start + 200 );
				},
			),

			// Special / Limited / Collectors Edition sin número
			array(
				'/\b(?:limited|collector[s\']?|special)\s+edition\b(?!\s*\d)/iu',
				function ( $m, $t ) use ( $special_start ) {
					$s = trim( preg_replace( '/\s*\b(?:limited|collector[s\']?|special)\s+edition.*$/iu', '', $t ) );
					return array( $s ?: $t, 9996, 'special_edition', $special_start + 400 );
				},
			),

			// Compilación: "One Piece nº 1 (3 en 1)" o "(3-en-1)" o "(edicion 2-en-1)"
			array(
				'/^(.+?)\s+n[°º]?\s*(\d+)\s*\(\s*(?:edici[oó]n\s+)?(\d+)[\s-]+en[\s-]+1\s*\)/iu',
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' ' . (int) $m[3] . ' en 1';
					return array( $s, (int) $m[2], 'compilacion', 0 );
				},
			),

			// Compilación: "Naruto (Edicion 2-en-1) 5" o "(2-en-1) Vol.5"
			array(
				'/^(.+?)\s*\(\s*(?:edici[oó]n\s+)?(\d+)[\s-]+en[\s-]+1\s*\)\s*(?:vol\.?|tomo\.?|n[°º]\.?)?\s*(\d+)$/iu',
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' ' . (int) $m[2] . ' en 1';
					return array( $s, (int) $m[3], 'compilacion', 0 );
				},
			),

			// Compilación: "One Piece 3 en 1 - 15"
			array(
				'/^(.+?)\s+(\d+)[\s-]+en[\s-]+1\s*(?:[-–—]\s*|n[°º]?\s*)(\d+)$/iu',
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' ' . (int) $m[2] . ' en 1';
					return array( $s, (int) $m[3], 'compilacion', 0 );
				},
			),

			// Compilación: "Dragon Ball Super 3 en 1 Vol.10"
			array(
				'/^(.+?)\s+(\d+)[\s-]+en[\s-]+1\s+(?:vol\.?|tomo\.?|n[°º]\.?)\s*(\d+)$/iu',
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' ' . (int) $m[2] . ' en 1';
					return array( $s, (int) $m[3], 'compilacion', 0 );
				},
			),

			// Edición especial con número: "Berserk Deluxe 5"
			array(
				"/^(.+?)\s+(?:Ed\.?|Edici[oó]n\.?)?\s*({$edition_rx})\s+(\d+)$/iu",
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' ' . ucfirst( mb_strtolower( trim( $m[2] ), 'UTF-8' ) );
					return array( $s, (int) $m[3], 'formato_especial', 0 );
				},
			),

			// Volumen explícito: "Naruto Tomo 15"
			array(
				'/^(.+?)\s+(?:vol\.?|tomo\.?|tome\.?|n[°º]\.?|cap\.?|#)\s*(\d+)$/iu',
				function ( $m, $t ) {
					return array( trim( $m[1] ), (int) $m[2], 'estandar', 0 );
				},
			),

			// Series con fracción: "Ranma 1/2 38"
			array(
				'/^(.+?\d+\/\d+)\s+(\d+)$/u',
				function ( $m, $t ) {
					return array( trim( $m[1] ), (int) $m[2], 'con_fraccion', 0 );
				},
			),

			// Partes/Sagas: "JoJo Parte 5 Vol. 3"
			array(
				'/^(.+?)\s+(?:parte|part|saga)\s+(\d+)\s+(?:vol\.?|n[°º]\.?|#)\s*(\d+)$/iu',
				function ( $m, $t ) {
					$s = trim( $m[1] ) . ' Parte ' . (int) $m[2];
					return array( $s, (int) $m[3], 'parte_volumen', 0 );
				},
			),

			// Estándar: número al final — "Naruto 72"
			array(
				'/^(.+?)\s+(\d{1,4})$/u',
				function ( $m, $t ) {
					return array( trim( $m[1] ), (int) $m[2], 'estandar', 0 );
				},
			),

			// Sin número detectado (fallback)
			array(
				'/^(.+)$/u',
				function ( $m, $t ) {
					return array( trim( $m[1] ), 0, 'sin_numero', 0 );
				},
			),
		);

		$result_serie     = $limpio;
		$result_numero    = 0;
		$result_tipo      = 'sin_numero';
		$result_prioridad = -1;

		foreach ( $patterns as [$regex, $handler] ) {
			if ( preg_match( $regex, $limpio, $matches ) ) {
				[$result_serie, $result_numero, $result_tipo, $result_prioridad] = $handler( $matches, $limpio );
				break;
			}
		}

		// Clave de agrupación: solo alfanumérico lowercase sin tildes
		$serie_norm = preg_replace( '/[^a-z0-9]/u', '', akb_normalize( $result_serie, true ) );

		// Normalizar alias de ediciones
		$serie_norm = strtr(
			$serie_norm,
			array(
				'edkanzenban'      => 'kanzenban',
				'edicionkanzenban' => 'kanzenban',
				'eddeluxe'         => 'deluxe',
				'ediciondeluxe'    => 'deluxe',
				'edultimate'       => 'ultimate',
				'goldedition'      => 'gold',
				'silveredition'    => 'silver',
			)
		);

		return array(
			'serie'      => $result_serie,
			'serie_norm' => $serie_norm,
			'numero'     => (int) $result_numero,
			'tipo'       => $result_tipo,
			'prioridad'  => (int) $result_prioridad,
		);
	}
}
