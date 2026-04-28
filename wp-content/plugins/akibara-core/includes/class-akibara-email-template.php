<?php
/**
 * Akibara Core — Backwards compat shim: AkibaraEmailTemplate → Akibara\Infra\EmailTemplate
 *
 * La clase vive en src/Infra/EmailTemplate.php pero el autoloader de akibara-core
 * solo maneja Akibara\Core\*. Por eso load explícito + class_alias para preservar
 * símbolo legacy `AkibaraEmailTemplate` sin tocar callers.
 *
 * @package Akibara\Core
 * @since   1.0.0
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKIBARA_CORE_PLUGIN_LOADED' ) ) {
	return;
}

// Load explícito de Akibara\Infra\EmailTemplate — autoloader akibara-core solo
// maneja Akibara\Core\*. Sin este require, class_alias falla con warning.
if ( ! class_exists( 'Akibara\\Infra\\EmailTemplate', false ) ) {
	$_akb_email_template = __DIR__ . '/../src/Infra/EmailTemplate.php';
	if ( file_exists( $_akb_email_template ) ) {
		require_once $_akb_email_template;
	}
}

// Backward-compat alias: AkibaraEmailTemplate → Akibara\Infra\EmailTemplate.
if ( ! class_exists( 'AkibaraEmailTemplate', false ) && class_exists( 'Akibara\\Infra\\EmailTemplate' ) ) {
	class_alias( 'Akibara\\Infra\\EmailTemplate', 'AkibaraEmailTemplate' );
}
