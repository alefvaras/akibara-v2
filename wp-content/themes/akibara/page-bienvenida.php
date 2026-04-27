<?php
/**
 * Template: Página de Bienvenida — Cupón 10% OFF
 */
get_header();

$coupon_code = get_option( 'akibara_popup_coupon', 'PRIMERACOMPRA10' );
?>

<main class="site-content">
<div class="aki-welcome-page">
    <div class="aki-welcome-page__card">
        <div class="aki-welcome-page__badge">¡BIENVENIDO!</div>
        <h1 class="aki-welcome-page__title">Tu cupón de 10% de descuento</h1>
        <p class="aki-welcome-page__desc">Gracias por suscribirte. Tu descuento se aplicará automáticamente cuando llegues al checkout.</p>
        
        <div class="aki-welcome-page__coupon">
            <span id="aki-welcome-coupon"><?php echo esc_html( $coupon_code ); ?></span>
            <button class="aki-welcome-page__copy" id="aki-welcome-copy">Copiar código</button>
        </div>
        
        <p class="aki-welcome-page__note">El cupón se aplicará solo al llegar al checkout. También te lo enviamos por email.</p>
        
        <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="aki-welcome-page__cta">
            Explorar catálogo
        </a>
    </div>
</div>
</main>

<style>
.aki-welcome-page {
    min-height: 60vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-8, 40px) var(--space-4, 16px);
}
.aki-welcome-page__card {
    background: var(--aki-surface, #1a1a1a);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 16px;
    padding: 48px 40px;
    max-width: 520px;
    width: 100%;
    text-align: center;
    box-shadow: 0 24px 80px rgba(0,0,0,.5);
}
.aki-welcome-page__badge {
    display: inline-block;
    background: var(--aki-red, #D90010);
    color: #fff;
    font-family: var(--font-heading, 'Bebas Neue', Impact, sans-serif);
    font-size: 28px;
    letter-spacing: .04em;
    padding: 6px 24px;
    margin-bottom: 20px;
    transform: skewX(-5deg);
}
.aki-welcome-page__title {
    font-family: var(--font-heading, 'Bebas Neue', Impact, sans-serif);
    font-size: 32px;
    text-transform: uppercase;
    color: #fff;
    margin: 0 0 12px;
    letter-spacing: .02em;
}
.aki-welcome-page__desc {
    color: rgba(255,255,255,.6);
    font-size: 16px;
    line-height: 1.5;
    margin: 0 0 24px;
}
.aki-welcome-page__coupon {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    background: rgba(217,0,16,.08);
    border: 2px dashed var(--aki-red, #D90010);
    border-radius: 12px;
    padding: 20px 24px;
    margin: 0 0 20px;
}
.aki-welcome-page__coupon span {
    font-family: var(--font-heading, 'Bebas Neue', Impact, sans-serif);
    font-size: 32px;
    letter-spacing: .08em;
    color: var(--aki-red, #D90010);
}
.aki-welcome-page__copy {
    background: var(--aki-red, #D90010);
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background .2s;
}
.aki-welcome-page__copy:hover {
    background: var(--aki-red-dark, #8B0000);
}
.aki-welcome-page__note {
    color: rgba(255,255,255,.4);
    font-size: 13px;
    margin: 0 0 24px;
}
.aki-welcome-page__cta {
    display: inline-block;
    background: var(--aki-red, #D90010);
    color: #fff;
    text-decoration: none;
    font-family: var(--font-heading, 'Bebas Neue', Impact, sans-serif);
    font-size: 20px;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 14px 40px;
    border-radius: 8px;
    transition: background .2s, transform .2s;
}
.aki-welcome-page__cta:hover {
    background: var(--aki-red-dark, #8B0000);
    transform: translateY(-2px);
    color: #fff;
}
@media (max-width: 480px) {
    .aki-welcome-page__card { padding: 32px 20px; }
    .aki-welcome-page__title { font-size: 26px; }
    .aki-welcome-page__coupon { flex-direction: column; gap: 10px; }
    .aki-welcome-page__coupon span { font-size: 26px; }
}
</style>

<script>
(function(){
    var copyBtn = document.getElementById('aki-welcome-copy');
    if (!copyBtn) return;
    var code = document.getElementById('aki-welcome-coupon').textContent.trim();
    copyBtn.addEventListener('click', function(){
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function(){ copyBtn.textContent = '¡Copiado!'; });
        } else {
            var ta = document.createElement('textarea');
            ta.value = code; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); copyBtn.textContent = '¡Copiado!'; } catch(e) {}
            document.body.removeChild(ta);
        }
    });
})();
</script>

<?php get_footer(); ?>
