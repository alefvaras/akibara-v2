<?php
/**
 * Plugin Name: Akibara No-Translate Guard
 * Description: Bloquea auto-translate browser (Chrome Google Translate, Safari Translate, Firefox) sitewide. Previene `NotFoundError: removeChild on Node` causado por auto-translate mutando DOM en conflicto con WC/jQuery reconciliation. Akibara es 100% audience Chile español — auto-translate NO aporta valor, solo riesgo.
 * Version: 1.0.0
 * Author: Akibara
 * Requires PHP: 8.1
 *
 * Trigger
 * -------
 * Usuario reportó `removeChild` JS error en checkout 2026-04-27. Sin Sentry
 * Browser SDK al momento del error → no stack trace. Hipótesis #1: Chrome
 * Google Translate intentando traducir formularios + WC JS reconciliation
 * = race condition NotFoundError en DOM.
 *
 * Confirmación pending: Sentry browser SDK YA active post B-S1-EXTRA, si
 * el error reaparece capturará stack trace para confirmación.
 *
 * Trade-off
 * ---------
 * - Pro: bloquea causa #1 del removeChild sitewide
 * - Pro: Akibara audience 100% Chile español (user confirmado), auto-translate
 *        NO aporta valor a clientes legítimos
 * - Pro: defensa-in-depth, también previene similar issues en mi-cuenta,
 *        single product, wishlist (cualquier página con JS reconciliation)
 * - Con: si Akibara expande a market english/portugués (M4+ growth-deferred),
 *        revisar este guard. Hasta entonces, no aplica.
 *
 * Rollback
 * --------
 * Borrar este archivo o renombrar a `.disabled`. Sin efecto colateral en
 * resto del stack.
 *
 * @package Akibara
 * @see ADR-001 docs/adr/001-sentry-stack-architecture.md
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_head',
	static function (): void {
		// Skip admin (admin no tiene auto-translate browser).
		if ( is_admin() ) {
			return;
		}
		echo '<meta name="google" content="notranslate">' . "\n";
		// Bonus: hint a otros translation tools (DeepL, Bing Translator)
		echo '<meta http-equiv="Content-Language" content="es-CL">' . "\n";
	},
	1
);
