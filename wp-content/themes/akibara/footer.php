<?php do_action( "akibara_before_footer" ); ?>
<footer class="site-footer">
    <div class="footer-main">
        <!-- Brand -->
        <div class="footer-brand">
            <?php

            $custom_logo_id = get_theme_mod("custom_logo");
            if (file_exists(get_template_directory() . '/assets/img/logo-akibara.webp')) {
                echo '<a href="' . esc_url(home_url("/")) . '" class="footer-brand__logo">';
                echo '<img src="' . esc_url(AKIBARA_THEME_URI . '/assets/img/logo-akibara.webp') . '?v=5" alt="' . esc_attr(get_bloginfo("name")) . '" width="201" height="80" loading="lazy" decoding="async">';
                echo "</a>";
            } else if ($custom_logo_id) {

                echo "<a href=\"" . esc_url(home_url("/")) . "\" class=\"footer-brand__logo\">";
                echo wp_get_attachment_image($custom_logo_id, "full", false, ["alt" => get_bloginfo("name")]);
                echo "</a>";
            }
            ?>
            <?php // Branding canónico 2026-04-25 (owner): orden cómics→manga + guiño Akihabara. ?>
            <p class="footer-brand__desc">
                Tu Distrito de Cómics y Manga — el Akihabara chileno (秋葉原). Envíos a todo Chile, la mejor selección importada de España y Argentina.
            </p>
            <div class="footer-social">
                <a href="https://instagram.com/akibara.cl" class="footer-social__link" target="_blank" rel="noopener" aria-label="Instagram">
                    <?php echo akibara_icon("instagram", 18); ?>
                </a>
                <a href="https://www.facebook.com/akibara.cl" class="footer-social__link" target="_blank" rel="noopener" aria-label="Facebook">
                    <?php echo akibara_icon("facebook", 18); ?>
                </a>
                <a href="<?php echo esc_url(function_exists("akibara_wa_url") ? akibara_wa_url() : 'https://wa.me/' . ( function_exists('akibara_whatsapp_get_business_number') ? akibara_whatsapp_get_business_number() : '' )); ?>" class="footer-social__link" target="_blank" rel="noopener" aria-label="WhatsApp">
                    <?php echo akibara_icon("whatsapp", 18); ?>
                </a>
            </div>
            <div class="footer-mobile-shortcuts" aria-label="Accesos rápidos">
                <a href="<?php echo esc_url(home_url("/tienda/")); ?>" class="footer-mobile-shortcuts__item">Tienda</a>
                <a href="<?php echo esc_url(home_url("/rastrear/")); ?>" class="footer-mobile-shortcuts__item">Rastrear</a>
                <a href="<?php echo esc_url(home_url("/contacto/")); ?>" class="footer-mobile-shortcuts__item">Contacto</a>
            </div>
        </div>

        <?php // Sprint 11 a11y fix #8 (audit 2026-04-26): aria-expanded sync corregido en
        // main.js setupFooterAccordion (desktop='true' panel visible, mobile='false'
        // hidden=true). NO se setea hidden server-side: en desktop CSS display:flex
        // no override [hidden] HTML attr, generaría FOUC. JS init via DOMContentLoaded
        // gestiona toggle correcto en ambos breakpoints. ?>

        <!-- Catálogo -->
        <div class="footer-column">
            <button type="button" class="footer-column__title footer-column__toggle" aria-expanded="false" aria-controls="footer-panel-catalogo">Catálogo</button>
            <ul class="footer-column__list" id="footer-panel-catalogo">
                <li><a href="<?php echo esc_url(home_url("/manga/")); ?>" class="footer-column__link">Manga</a></li>
                <li><a href="<?php echo esc_url(home_url("/comics/")); ?>" class="footer-column__link">Cómics</a></li>
                <li><a href="<?php echo esc_url(home_url("/preventas/")); ?>" class="footer-column__link">Preventas</a></li>
                <li><a href="<?php echo esc_url(home_url("/editoriales/")); ?>" class="footer-column__link">Editoriales</a></li>
                <li><a href="<?php echo esc_url(home_url("/blog")); ?>" class="footer-column__link">Blog</a></li>
            </ul>
        </div>

        <!-- Explorar -->
        <div class="footer-column">
            <button type="button" class="footer-column__title footer-column__toggle" aria-expanded="false" aria-controls="footer-panel-explorar">Explorar</button>
            <ul class="footer-column__list" id="footer-panel-explorar">
                <li><a href="<?php echo esc_url(home_url("/shonen/")); ?>" class="footer-column__link">Shonen</a></li>
                <li><a href="<?php echo esc_url(home_url("/seinen/")); ?>" class="footer-column__link">Seinen</a></li>
                <li><a href="<?php echo esc_url(home_url("/shojo/")); ?>" class="footer-column__link">Shojo</a></li>
                <li><a href="<?php echo esc_url(home_url("/manhwa/")); ?>" class="footer-column__link">Manhwa</a></li>
            </ul>
        </div>

        <!-- Ayuda -->
        <div class="footer-column">
            <button type="button" class="footer-column__title footer-column__toggle" aria-expanded="false" aria-controls="footer-panel-ayuda">Ayuda</button>
            <ul class="footer-column__list" id="footer-panel-ayuda">
                <li><a href="<?php echo esc_url(home_url("/preguntas-frecuentes/")); ?>" class="footer-column__link">Preguntas Frecuentes</a></li>
                <li><a href="<?php echo esc_url(home_url("/nosotros/")); ?>" class="footer-column__link">Sobre Nosotros</a></li>
                <li><a href="<?php echo esc_url(home_url("/rastrear/")); ?>" class="footer-column__link">Rastrear Pedido</a></li>
                <li><a href="<?php echo esc_url(home_url("/contacto/")); ?>" class="footer-column__link">Contacto</a></li>
                <li><a href="<?php echo esc_url(home_url("/devoluciones/")); ?>" class="footer-column__link">Cambios y Devoluciones</a></li>
                <li><a href="<?php echo esc_url(home_url("/terminos-y-condiciones/")); ?>" class="footer-column__link">Términos y Condiciones</a></li>
                <li><a href="<?php echo esc_url(home_url("/politica-de-privacidad/")); ?>" class="footer-column__link">Política de Privacidad</a></li>
            </ul>
        </div>

        <!-- Newsletter -->
        <?php do_action( "akibara_footer_brand_after" ); ?>
    </div>

    <!-- Bottom -->
    <div class="footer-bottom">
        <span>&copy; <?php echo wp_date("Y"); ?> <?php bloginfo("name"); ?>. Todos los derechos reservados.</span>
    </div>
</footer>

<!-- ===== BOTTOM NAV (mobile) ===== -->
<nav class="bottom-nav" id="bottom-nav" aria-label="Navegación móvil">
    <a href="<?php echo esc_url(home_url("/")); ?>" class="bottom-nav__item <?php echo is_front_page() ? "bottom-nav__item--active" : ""; ?>"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span class="bottom-nav__label">Inicio</span>
    </a>
    <button class="bottom-nav__item button-search-popup" type="button" aria-label="Buscar productos" aria-haspopup="dialog" aria-expanded="false" aria-controls="akibara-pro-popup">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span class="bottom-nav__label">Buscar</span>
    </button>
    <a href="<?php echo esc_url(home_url("/wishlist/")); ?>" class="bottom-nav__item <?php echo is_page("wishlist") ? "bottom-nav__item--active" : ""; ?>"<?php echo is_page("wishlist") ? ' aria-current="page"' : ''; ?> id="bottom-nav-wishlist">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 00-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 00-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 000-7.78z"/></svg>
        <span class="bottom-nav__label">Favoritos</span>
        <span class="bottom-nav__badge bottom-nav__badge--hidden js-wishlist-count">0</span>
    </a>
    <?php $aki_bn_cart = akibara_cart_count(); ?>
    <button class="bottom-nav__item" id="bottom-nav-cart" type="button" aria-label="<?php echo $aki_bn_cart > 0 ? sprintf( esc_attr__( 'Abrir carrito, %d artículos', 'akibara' ), $aki_bn_cart ) : esc_attr__( 'Abrir carrito, vacío', 'akibara' ); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        <span class="bottom-nav__label">Carrito</span>
        <span class="bottom-nav__badge<?php echo $aki_bn_cart > 0 ? '' : ' bottom-nav__badge--hidden'; ?>" id="bottom-nav-count" aria-hidden="true"><?php echo (int) $aki_bn_cart; ?></span>
    </button>
</nav>

<!-- Scroll to top -->
<button class="scroll-top" id="scroll-top" aria-label="Volver arriba">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
</button>

<!-- akibara-orchestrator-validation: 2026-04-28T17:30Z -->
<!-- akb-v7-20260428T181340Z -->
<?php wp_footer(); ?>
</body>
</html>
