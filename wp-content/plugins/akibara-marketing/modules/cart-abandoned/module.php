<?php
/**
 * Akibara Marketing — Módulo Cart Abandoned (DEPRECADO)
 *
 * DECISIÓN: Este módulo NO fue migrado desde el legacy plugin.
 *
 * Razón: Brevo Abandoned Cart upstream está activo en la cuenta Akibara
 * (confirmado por usuario 2026-04-26). Migrar el módulo local (~539 LOC)
 * duplicaría la funcionalidad que Brevo ya provee nativamente, añadiendo
 * cron jobs, transientes y una tabla de DB innecesarios.
 *
 * Source de verdad: memoria `project_brevo_upstream_capabilities.md`:
 * "Abandoned cart emails → SÍ — activo en cuenta Akibara"
 *
 * NOTA OPERACIONAL:
 * - El módulo legacy en akibara/modules/cart-abandoned/ sigue en producción
 *   hasta que se confirme que ambos (legacy local + Brevo upstream) no están
 *   enviando emails duplicados. Decisión de cleanup: Sprint 3.5 o Sprint 4.
 * - Si Brevo upstream alguna vez se desactiva, este archivo es el punto de
 *   restauración. El código fuente completo está en:
 *   server-snapshot/public_html/wp-content/plugins/akibara/modules/cart-abandoned/module.php
 *
 * Tabla legacy (NO crear aquí): wp_akibara_abandoned_carts — borrar en Sprint 3.5.
 *
 * @package    Akibara\Marketing
 * @subpackage CartAbandoned
 * @version    DEPRECATED
 * @see        https://app.brevo.com/ (Automation > Abandoned Cart)
 */

defined( 'ABSPATH' ) || exit;

// This file is intentionally empty.
// Brevo upstream covers this feature natively.
// See file header for full rationale.
