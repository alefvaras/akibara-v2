<?php
/**
 * Template Name: Encargos
 * Description: Página de encargos — solicitar títulos no disponibles.
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
                <span>Encargos</span>
            </nav>
            <h1 class="page-header__title">Encargos</h1>
        </div>
    </div>

    <div class="aki-encargos">

        <!-- Intro -->
        <div class="aki-encargos__intro">
            <h2 class="aki-encargos__subtitle">¿No encuentras un titulo? Lo conseguimos para ti</h2>
            <p>Si el manga o comic que buscas no está en nuestro catálogo o está agotado, podemos encargarlo directamente desde la editorial.</p>
        </div>

        <!-- Cómo funciona -->
        <div class="aki-encargos__steps">
            <div class="aki-encargos__step">
                <span class="aki-encargos__step-num">1</span>
                <div>
                    <strong>Solicita tu encargo</strong>
                    <p>Completa el formulario con el titulo y editorial que necesitas.</p>
                </div>
            </div>
            <div class="aki-encargos__step">
                <span class="aki-encargos__step-num">2</span>
                <div>
                    <strong>Te cotizamos en 48 horas</strong>
                    <p>Verificamos disponibilidad con la editorial y te enviamos precio + plazo estimado.</p>
                </div>
            </div>
            <div class="aki-encargos__step">
                <span class="aki-encargos__step-num">3</span>
                <div>
                    <strong>Recibes tu manga</strong>
                    <p>Pagas y te lo enviamos apenas llegue. Plazo estimado: 2-6 semanas.</p>
                </div>
            </div>
        </div>

        <!-- Formulario -->
        <section class="aki-encargos__form-section" aria-label="Formulario de encargo">
            <h3 class="aki-encargos__form-title">Solicitar Encargo</h3>

            <form id="aki-encargo-form" class="aki-encargos__form" novalidate>
                <?php wp_nonce_field( 'akibara_encargo', 'encargo_nonce' ); ?>

                <div class="aki-encargos__row">
                    <div class="aki-encargos__field">
                        <label for="enc-nombre">Tu nombre</label>
                        <input type="text" id="enc-nombre" name="nombre" placeholder="Ej: Alejandro" required>
                    </div>
                    <div class="aki-encargos__field">
                        <label for="enc-email">Correo electrónico</label>
                        <input type="email" id="enc-email" name="email" placeholder="tu@correo.com" required>
                    </div>
                </div>

                <div class="aki-encargos__field">
                    <label for="enc-titulo">Titulo del manga o comic</label>
                    <input type="text" id="enc-titulo" name="titulo" placeholder="Ej: Vagabond Vol. 12" required>
                </div>

                <div class="aki-encargos__row">
                    <div class="aki-encargos__field">
                        <label for="enc-editorial">Editorial preferida</label>
                        <select id="enc-editorial" name="editorial">
                            <option value="">Cualquiera / No sé</option>
                            <?php
                            // Obtener editoriales desde product_brand
                            $brands = get_terms([
                                'taxonomy' => 'product_brand',
                                'hide_empty' => true,
                                'orderby' => 'count',
                                'order' => 'DESC',
                            ]);
                            if (!is_wp_error($brands) && !empty($brands)) :
                                foreach ($brands as $brand) :
                                    echo '<option value="' . esc_attr($brand->name) . '">' . esc_html($brand->name) . ' (' . $brand->count . ' productos)</option>';
                                endforeach;
                            endif;
                            ?>
                            <option value="Otra">Otra editorial</option>
                        </select>
                    </div>
                    <div class="aki-encargos__field">
                        <label for="enc-volumenes">Volúmenes que necesitas</label>
                        <input type="text" id="enc-volumenes" name="volumenes" placeholder="Ej: 1 al 5, o solo el 12">
                    </div>
                </div>

                <div class="aki-encargos__field">
                    <label for="enc-notas">Notas adicionales <span style="color:var(--aki-gray-500)">(opcional)</span></label>
                    <textarea id="enc-notas" name="notas" rows="3" placeholder="Edición especial, idioma, urgencia, etc."></textarea>
                </div>

                <button type="submit" class="btn btn--primary aki-encargos__submit">
                    <span class="aki-encargos__btn-text">Solicitar Cotización</span>
                    <span class="aki-encargos__btn-loading" style="display:none">Enviando...</span>
                </button>

                <p class="aki-encargos__note">
                    Solicitar una cotización no tiene costo ni compromiso. Te contactamos en máximo 48 horas hábiles.
                </p>
            </form>

            <!-- Errores -->
            <div id="aki-encargo-error" role="alert" aria-live="assertive" style="display:none" class="aki-encargos__error"></div>

            <!-- Success -->
            <div id="aki-encargo-success" style="display:none" class="aki-encargos__success">
                <span style="font-size:32px">✅</span>
                <h3>Solicitud enviada</h3>
                <p>Recibimos tu encargo. Te contactaremos a tu correo en máximo 48 horas con la cotización.</p>
                <a href="<?php echo esc_url( home_url( '/tienda/' ) ); ?>" class="btn btn--secondary" style="margin-top:var(--space-4)"><span>Seguir explorando</span></a>
            </div>
        </section>

        <!-- WhatsApp alternativa -->
        <div class="aki-encargos__whatsapp">
            <p>¿Prefieres WhatsApp? Escribenos directo:</p>
            <a href="<?php echo esc_url(function_exists('akibara_wa_url') ? akibara_wa_url('Hola, quiero encargar un manga') : 'https://wa.me/' . ( function_exists('akibara_whatsapp_get_business_number') ? akibara_whatsapp_get_business_number() : '' ) . '?text=' . rawurlencode('Hola, quiero encargar un manga')); ?>" target="_blank" rel="noopener" class="btn btn--secondary">
                <span>Escribir por WhatsApp</span>
            </a>
        </div>

        <!-- Preventas cross-sell -->
        <?php
        $preorders = new WP_Query([
            'post_type' => 'product', 'posts_per_page' => 4, 'post_status' => 'publish',
            'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true,
            'meta_query' => [['key' => '_akb_reserva', 'value' => 'yes'], ['key' => '_akb_reserva_tipo', 'value' => 'preventa']],
        ]);
        if ($preorders->have_posts()) : ?>
        <div class="aki-encargos__crosssell">
            <div class="section-header">
                <h2 class="section-header__title">Preventas Disponibles</h2>
                <a href="<?php echo esc_url(home_url('/preventas/')); ?>" class="section-header__link">Ver todas <?php echo akibara_icon('arrow', 16); ?></a>
            </div>
            <p style="color:var(--aki-gray-400);font-size:var(--text-sm);margin-bottom:var(--space-4)">Estos títulos ya están confirmados y puedes reservarlos ahora.</p>
            <div class="product-grid product-grid--large">
                <?php while ($preorders->have_posts()) : $preorders->the_post();
                    get_template_part('template-parts/content/product-card');
                endwhile; wp_reset_postdata(); ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>


<script>
(function(){
    var form = document.getElementById('aki-encargo-form');
    if (!form) return;

    var errorBox = document.getElementById('aki-encargo-error');
    var successBox = document.getElementById('aki-encargo-success');
    var btnText = form.querySelector('.aki-encargos__btn-text');
    var btnLoad = form.querySelector('.aki-encargos__btn-loading');

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        errorBox.style.display = 'none';

        var nombre = form.querySelector('[name="nombre"]').value.trim();
        var email = form.querySelector('[name="email"]').value.trim();
        var titulo = form.querySelector('[name="titulo"]').value.trim();

        if (!nombre || !email || !titulo) {
            errorBox.textContent = 'Completa los campos obligatorios: nombre, correo y titulo.';
            errorBox.style.display = 'block';
            return;
        }

        btnText.style.display = 'none';
        btnLoad.style.display = 'inline';

        var fd = new FormData(form);
        fd.append('action', 'akibara_encargo_submit');

        fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
            method: 'POST', body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btnText.style.display = 'inline';
            btnLoad.style.display = 'none';
            if (data.success) {
                form.style.display = 'none';
                successBox.style.display = 'block';
            } else {
                errorBox.textContent = data.data || 'Error al enviar. Intenta de nuevo.';
                errorBox.style.display = 'block';
            }
        })
        .catch(function() {
            btnText.style.display = 'inline';
            btnLoad.style.display = 'none';
            errorBox.textContent = 'Error de conexión.';
            errorBox.style.display = 'block';
        });
    });
})();
</script>

<?php get_footer(); ?>
