<?php
/**
 * Single Product — Lightbox overlay partial
 *
 * Overlay fuera del stacking context para evitar z-index issues.
 * Activado por gallery-zoom-btn y thumbs.
 *
 * @package Akibara
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
/*
 * Sprint 11 a11y fix #9 (audit 2026-04-26):
 *  - hidden default: previene focus tabular dentro mientras lightbox cerrado
 *    (JS lightbox debe `removeAttribute('hidden')` al abrir)
 *  - close button: &times; (U+00D7) reemplazado por SVG con aria-hidden
 *    (algunos SR pronuncian × como "multiplicación"; aria-label="Cerrar"
 *    queda como nombre accesible canónico)
 *  - WCAG 2.1.2, 2.4.3, 1.4.13
 */
?>
<div class="product-lightbox" id="product-lightbox" role="dialog" aria-modal="true" aria-label="Imagen ampliada" hidden>
    <button class="product-lightbox__close" id="lightbox-close" type="button" aria-label="Cerrar">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
    </button>
    <img class="product-lightbox__img" id="lightbox-img" alt="" decoding="async">
</div>
