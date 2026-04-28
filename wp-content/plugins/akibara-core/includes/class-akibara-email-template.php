<?php
/**
 * Akibara Core — Backwards compat shim: AkibaraEmailTemplate → Akibara\Infra\EmailTemplate
 *
 * La clase vive en src/Infra/EmailTemplate.php (PSR-4 autoload via akibara-core).
 * Este archivo preserva el símbolo legacy `AkibaraEmailTemplate` vía class_alias
 * para que los callers existentes (modules/*, addons) sigan funcionando sin tocarlos.
 *
 * Migrado desde akibara/includes/class-akibara-email-template.php (Polish #1 2026-04-26).
 *
 * @package Akibara\Core
 * @since   1.0.0
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}

// Backward-compat alias: AkibaraEmailTemplate → Akibara\Infra\EmailTemplate
// La clase real se carga via PSR-4 autoloader de akibara-core (spl_autoload_register).
if ( ! class_exists( 'AkibaraEmailTemplate', false ) ) {
	class_alias( 'Akibara\\Infra\\EmailTemplate', 'AkibaraEmailTemplate' );
}
