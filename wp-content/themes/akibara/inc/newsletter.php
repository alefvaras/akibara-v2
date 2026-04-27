<?php
/**
 * Akibara Newsletter — Brevo Integration
 *
 * Renders a footer newsletter form and handles AJAX subscription
 * via Brevo Contacts API (list ID 2).
 * Also provides an inline catalog CTA variant for the product grid.
 *
 * NOTE: This is the single canonical newsletter module for the theme.
 * The duplicate in the plugin popup module (akibara_footer_signup_render)
 * is suppressed via remove_action in functions.php.
 *
 * @package Akibara
 */

defined( "ABSPATH" ) || exit;

/**
 * Render newsletter column in footer via hook
 */
add_action( "akibara_footer_brand_after", function () {
    if ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) && ( is_cart() || is_checkout() ) ) {
        return;
    }

    $nonce = wp_create_nonce( "akibara-newsletter" );
    ?>
    <div class="footer-newsletter" id="footer-newsletter">
        <div class="footer-newsletter__teaser">
            <span class="footer-newsletter__badge">10% OFF</span>
            <div class="footer-newsletter__teaser-copy">
                <strong>10% OFF en tu primera compra</strong>
                <span>Te enviamos el cupón al instante</span>
            </div>
            <button type="button" class="footer-newsletter__teaser-btn" id="footer-newsletter-toggle" aria-expanded="false" aria-controls="footer-newsletter-panel">Obtener descuento</button>
        </div>

        <div class="footer-newsletter__panel" id="footer-newsletter-panel">
            <h4 class="footer-column__title footer-newsletter__title">Newsletter</h4>
            <p class="footer-newsletter__desc">
                <strong>10% OFF en tu primera compra</strong>
                Te enviamos el cupón al instante.
            </p>
            <form class="footer-newsletter__form" id="newsletter-form" autocomplete="on">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                <input type="text" name="website_url" value="" autocomplete="off" tabindex="-1"
                       aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
                <div class="footer-newsletter__field">
                    <input
                        type="email"
                        name="email"
                        class="footer-newsletter__input form-input"
                        placeholder="tu@email.com"
                        required
                        autocomplete="email"
                        aria-label="Correo electr&oacute;nico para newsletter"
                    >
                    <button type="submit" class="footer-newsletter__btn" aria-label="Suscribirse">
                        Suscribirme
                    </button>
                </div>
                <div class="footer-newsletter__msg" id="newsletter-msg" aria-live="polite"></div>
            </form>
            <p class="footer-newsletter__subscribed" id="footer-newsletter-subscribed" style="display:none;">
                &check; Ya estas suscrito &mdash; <a href="/tienda/">ver ofertas</a>
            </p>
            <p class="footer-newsletter__privacy">
                Sin spam. Puedes salir cuando quieras.
            </p>
        </div>
    </div>
    <?php
} );

/**
 * AJAX handler — subscribe to Brevo list
 */
add_action( "wp_ajax_akibara_newsletter_subscribe", "akibara_newsletter_subscribe" );
add_action( "wp_ajax_nopriv_akibara_newsletter_subscribe", "akibara_newsletter_subscribe" );

function akibara_newsletter_subscribe() {
    check_ajax_referer( "akibara-newsletter", "nonce" );

    $email = isset( $_POST["email"] ) ? sanitize_email( wp_unslash( $_POST["email"] ) ) : "";

    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ "message" => "Por favor ingresa un correo v&aacute;lido." ] );
    }

    $api_key = function_exists( "akb_brevo_get_api_key" )
        ? akb_brevo_get_api_key()
        : (string) get_option( "akibara_brevo_api_key", "" );
    if ( empty( $api_key ) ) {
        error_log( "Akibara Newsletter: Brevo API key not configured." );
        wp_send_json_error( [ "message" => "Error interno. Intenta m&aacute;s tarde." ] );
    }

    // Create/update contact and add to list 2 (Newsletter)
    $payload = [
        "email"            => $email,
        "listIds"          => [ 2 ],
        "updateEnabled"    => true,
        "attributes"       => [
            "FUENTE" => "footer_newsletter",
        ],
    ];

    $response = wp_remote_post( "https://api.brevo.com/v3/contacts", [
        "timeout" => 15,
        "headers" => [
            "api-key"      => $api_key,
            "content-type" => "application/json",
            "accept"       => "application/json",
        ],
        "body" => wp_json_encode( $payload ),
    ] );

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    // 201 = created, 204 = updated existing contact
    if ( ! is_wp_error( $response ) && ( $code === 201 || $code === 204 ) ) {
        wp_send_json_success( [ "message" => "Te has suscrito exitosamente." ] );
    }

    // Brevo returns 400 with "Contact already exist" if duplicate
    if ( $code === 400 && isset( $body["message"] ) && stripos( $body["message"], "already exist" ) !== false ) {
        // Contact exists — try to add to list via PATCH
        $patch = wp_remote_request( "https://api.brevo.com/v3/contacts/" . rawurlencode( $email ), [
            "method"  => "PUT",
            "timeout" => 10,
            "headers" => [
                "api-key"      => $api_key,
                "content-type" => "application/json",
                "accept"       => "application/json",
            ],
            "body" => wp_json_encode( [ "listIds" => [ 2 ] ] ),
        ] );
        $patch_code = wp_remote_retrieve_response_code( $patch );
        if ( ! is_wp_error( $patch ) && $patch_code >= 200 && $patch_code < 300 ) {
            wp_send_json_success( [ "message" => "Te has suscrito exitosamente." ] );
        }
        // If even the patch fails, still show success to user (they are a contact)
        wp_send_json_success( [ "message" => "Ya estabas suscrito. Gracias!" ] );
    }

    error_log( "Akibara Newsletter Brevo error (HTTP $code): " . wp_remote_retrieve_body( $response ) );
    wp_send_json_error( [ "message" => "No pudimos suscribirte. Intenta m&aacute;s tarde." ] );
}

/**
 * Inline JS for newsletter form — with honeypot + already-subscribed check
 */
add_action( "wp_footer", function () {
    ?>
    <script>
    (function(){
        var form = document.getElementById("newsletter-form");
        if (!form) return;
        var msg = document.getElementById("newsletter-msg");
        var btn = form.querySelector(".footer-newsletter__btn");
        var subscribedMsg = document.getElementById("footer-newsletter-subscribed");
        var wrapper = document.getElementById("footer-newsletter");
        var toggleBtn = document.getElementById("footer-newsletter-toggle");

        function isMobile() {
            return window.matchMedia("(max-width: 768px)").matches;
        }

        function setOpen(open) {
            if (!wrapper || !toggleBtn) return;
            wrapper.classList.toggle("is-open", !!open);
            toggleBtn.setAttribute("aria-expanded", open ? "true" : "false");
        }

        function setSubscribedState() {
            form.style.display = "none";
            if (subscribedMsg) subscribedMsg.style.display = "";

            if (toggleBtn) {
                toggleBtn.textContent = "Ver ofertas";
                toggleBtn.setAttribute("data-subscribed", "1");
            }

            setOpen(false);
        }

        function syncByViewport() {
            if (!wrapper || !toggleBtn) return;

            if (isMobile()) {
                if (toggleBtn.getAttribute("data-subscribed") !== "1") {
                    setOpen(false);
                }
            } else {
                setOpen(true);
            }
        }

        /* Already subscribed: hide form, show message */
        try {
            if (localStorage.getItem("akibara_popup_subscribed") === "1" ||
                localStorage.getItem("akibara_newsletter_subscribed") === "1") {
                setSubscribedState();
            }
        } catch(e) {}

        /* Protección margen: si el visitante viene vía link de referido
         * (cookie akb_ref), ocultar el widget del newsletter. El cliente ya
         * tendrá su cupón $3.000 al comprar (REFREFERIDO-XXXX); ofrecer además
         * PRIMERACOMPRA10 sería stacking de bienvenidas que erosiona margen.
         * Ver referrals/module.php (individual_use=true) y popup/module.php. */
        try {
            if (document.cookie.indexOf("akb_ref=") !== -1 && wrapper) {
                wrapper.style.display = "none";
            }
        } catch(e) {}

        if (toggleBtn) {
            toggleBtn.addEventListener("click", function() {
                if (toggleBtn.getAttribute("data-subscribed") === "1") {
                    window.location.href = "<?php echo esc_url( home_url( '/tienda/' ) ); ?>";
                    return;
                }

                if (!wrapper) return;
                setOpen(!wrapper.classList.contains("is-open"));
            });
        }

        syncByViewport();
        window.addEventListener("resize", syncByViewport, { passive: true });

        form.addEventListener("submit", function(e) {
            e.preventDefault();
            var email = form.querySelector("[name=email]").value.trim();
            var nonce = form.querySelector("[name=nonce]").value;
            var honeypot = form.querySelector("[name=website_url]");
            if (!email) return;
            if (honeypot && honeypot.value) return; /* bot trap */

            btn.setAttribute("disabled", "disabled");
            btn.className = "footer-newsletter__btn footer-newsletter__btn--loading";
            msg.textContent = "";
            msg.className = "footer-newsletter__msg";

            var fd = new FormData();
            fd.append("action", "akibara_newsletter_subscribe");
            fd.append("email", email);
            fd.append("nonce", nonce);

            fetch("<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>", {
                method: "POST",
                body: fd
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    try { localStorage.setItem("akibara_newsletter_subscribed", "1"); } catch(ex) {}
                    msg.textContent = data.data.message;
                    msg.className = "footer-newsletter__msg footer-newsletter__msg--ok";
                    form.querySelector("[name=email]").value = "";
                    btn.className = "footer-newsletter__btn footer-newsletter__btn--ok";
                    btn.textContent = "\u2713 Listo";
                    /* After 2s, hide form and show subscribed message */
                    setTimeout(function() {
                        setSubscribedState();
                    }, 2000);
                } else {
                    msg.textContent = (data.data && data.data.message) || "Error al suscribirse.";
                    msg.className = "footer-newsletter__msg footer-newsletter__msg--err";
                    btn.className = "footer-newsletter__btn";
                    btn.textContent = "Suscribirme";
                    btn.removeAttribute("disabled");
                }
            })
            .catch(function(){
                msg.textContent = "Error de conexi&oacute;n. Intenta nuevamente.";
                msg.className = "footer-newsletter__msg footer-newsletter__msg--err";
            })
            .finally(function(){
                setTimeout(function(){
                    btn.className = "footer-newsletter__btn";
                    btn.removeAttribute("disabled");
                    btn.textContent = "Suscribirme";
                }, 3000);
            });
        });
    })();
    </script>
    <?php
}, 90 );

