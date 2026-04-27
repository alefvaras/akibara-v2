<?php
/**
 * Template Name: Rastrear Pedido
 * Description: Página pública de tracking — sin login requerido.
 *
 * @package Akibara
 */

get_header();
?>

<main class="site-content" id="main-content">
    <div class="page-header">
        <div class="container">
            <nav class="page-header__breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Inicio</a>
                <span class="separator">/</span>
                <span>Rastrear Pedido</span>
            </nav>
            <h1 class="page-header__title">Rastrear Pedido</h1>
        </div>
    </div>

    <div class="aki-track">

        <?php if ( is_user_logged_in() ) : ?>
        <!-- Notice: acceso rápido a Mis Pedidos para usuarios logueados -->
        <div class="aki-track__logged-notice">
            <span>¿Buscas tus pedidos recientes?</span>
            <a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>" class="btn btn--secondary btn--sm">
                Ir a Mis Pedidos →
            </a>
        </div>
        <?php endif; ?>

        <!-- Form de búsqueda — visible para todos -->
        <section class="aki-track__form-section" aria-label="Formulario de rastreo de pedido">
            <p class="aki-track__desc">
                Ingresa tu número de orden y el correo con el que compraste.
            </p>

            <form id="aki-track-form" class="aki-track__form" novalidate>
                <?php wp_nonce_field( 'akibara_track_order', 'track_nonce' ); ?>

                <div class="aki-track__field">
                    <label for="track-order-id">Número de orden</label>
                    <input type="text" id="track-order-id" name="order_id" placeholder="Ej: 14052"
                           required aria-required="true" inputmode="numeric" autocomplete="off">
                </div>

                <div class="aki-track__field">
                    <label for="track-email">Correo electrónico</label>
                    <input type="email" id="track-email" name="email" placeholder="El que usaste al comprar"
                           required aria-required="true" autocomplete="email" inputmode="email">
                </div>

                <button type="submit" class="btn btn--primary aki-track__submit">
                    <span class="aki-track__btn-text">Buscar Pedido</span>
                    <span class="aki-track__btn-loading" style="display:none">Buscando...</span>
                </button>

                <?php if ( ! is_user_logged_in() ) : ?>
                <p class="aki-track__trust">
                    <span>&#128274;</span> No almacenamos tu correo. Solo lo usamos para verificar tu orden.
                </p>
                <?php endif; ?>
            </form>

            <!-- Errores -->
            <div id="aki-track-error" role="alert" aria-live="assertive" aria-atomic="true" style="display:none" class="aki-track__error"></div>
        </section>

        <!-- Resultado -->
        <div id="aki-track-result" role="region" aria-live="polite" aria-atomic="true" style="display:none" class="aki-track__result"></div>

        <?php if ( ! is_user_logged_in() ) : ?>
        <p class="aki-track__login-hint">
            ¿Tienes cuenta? <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>">Inicia sesion</a> para ver todos tus pedidos.
        </p>
        <?php endif; ?>
    </div>
</main>

<!-- Tracking page styles -->

<!-- Tracking JS -->
<script>
(function(){
    var form = document.getElementById('aki-track-form');
    if (!form) return;

    var errorBox = document.getElementById('aki-track-error');
    var resultBox = document.getElementById('aki-track-result');
    var btnText = form.querySelector('.aki-track__btn-text');
    var btnLoad = form.querySelector('.aki-track__btn-loading');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        errorBox.style.display = 'none';
        resultBox.style.display = 'none';

        var orderId = form.querySelector('[name="order_id"]').value.replace(/\D/g, '');
        var email = form.querySelector('[name="email"]').value.trim();
        var nonce = form.querySelector('[name="track_nonce"]').value;

        // Validación básica
        if (!orderId) { showError('Ingresa tu número de orden'); return; }
        if (!email || email.indexOf('@') === -1) { showError('Ingresa un correo electrónico válido'); return; }

        btnText.style.display = 'none';
        btnLoad.style.display = 'inline';

        var fd = new FormData();
        fd.append('action', 'akibara_track_order');
        fd.append('nonce', nonce);
        fd.append('order_id', orderId);
        fd.append('email', email);

        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST', body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btnText.style.display = 'inline';
            btnLoad.style.display = 'none';

            if (data.success) {
                resultBox.innerHTML = data.data.html;
                resultBox.style.display = 'block';
                resultBox.focus();
            } else {
                showError(data.data || 'No encontramos una orden con esos datos.');
            }
        })
        .catch(function() {
            btnText.style.display = 'inline';
            btnLoad.style.display = 'none';
            showError('Error de conexión. Intenta de nuevo.');
        });
    });

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
        errorBox.focus();
    }
})();
</script>

<?php get_footer(); ?>
