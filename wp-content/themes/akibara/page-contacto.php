<?php
/**
 * Contact Page Template — Akibara
 *
 * @package Akibara
 */

defined('ABSPATH') || exit;

get_header();

$errors = [];
$submitted = false;
$values = [
    'name' => '',
    'email' => '',
    'reason' => '',
    'order' => '',
    'message' => '',
];

$reasons = [
    'pedido' => 'Estado de mi pedido',
    'preventa' => 'Preventa o reserva',
    'producto' => 'Producto o stock',
    'pago' => 'Pago o facturación',
    'soporte' => 'Problema con mi pedido',
    'otro' => 'Otro',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['akb_contact_nonce'])) {
    $values['name'] = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $values['email'] = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $values['reason'] = sanitize_text_field(wp_unslash($_POST['reason'] ?? ''));
    $values['order'] = sanitize_text_field(wp_unslash($_POST['order'] ?? ''));
    $values['message'] = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
    $honeypot = sanitize_text_field(wp_unslash($_POST['website'] ?? ''));

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['akb_contact_nonce'])), 'akb_contact_form')) {
        $errors[] = 'No pudimos validar el formulario. Intenta nuevamente.';
    }

    if ($values['name'] === '') {
        $errors[] = 'Ingresa tu nombre.';
    }

    if (!is_email($values['email'])) {
        $errors[] = 'Ingresa un correo válido.';
    }

    if (!isset($reasons[$values['reason']])) {
        $errors[] = 'Selecciona un motivo.';
    }

    if ($values['message'] === '') {
        $errors[] = 'Cuéntanos en qué podemos ayudarte.';
    }

    if (empty($errors)) {
        if ($honeypot !== '') {
            $submitted = true;
        } else {
            $reason_label = $reasons[$values['reason']] ?? 'Otro';
            $subject = sprintf('Contacto Akibara: %s — %s', $reason_label, $values['name']);
            $to = apply_filters('akibara_contact_email', get_option('admin_email'));
            $body_lines = [
                'Nombre: ' . $values['name'],
                'Email: ' . $values['email'],
                'Motivo: ' . $reason_label,
                'Orden: ' . ($values['order'] !== '' ? $values['order'] : 'No informado'),
                '',
                $values['message'],
            ];
            $headers = [
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $values['name'] . ' <' . $values['email'] . '>',
            ];

            if (wp_mail($to, $subject, implode("\n", $body_lines), $headers)) {
                $submitted = true;
                $values = [
                    'name' => '',
                    'email' => '',
                    'reason' => '',
                    'order' => '',
                    'message' => '',
                ];
            } else {
                $errors[] = 'No pudimos enviar tu mensaje. Intenta nuevamente o escríbenos por WhatsApp.';
            }
        }
    }
}
?>

<main class="site-content contact-page" id="main-content">
    <section class="contact-hero">
        <div class="contact-hero__inner">
            <span class="contact-hero__eyebrow">Contacto Akibara</span>
            <h1>Hablemos</h1>
            <p>Cuéntanos qué necesitas y te respondemos en menos de 48 horas hábiles.</p>
            <div class="contact-hero__meta">Soporte humano y respuestas claras, sin tickets eternos.</div>
        </div>
    </section>

    <section class="contact-grid">
        <div class="contact-grid__info">
            <div class="contact-card">
                <span class="contact-card__icon" aria-hidden="true"><?php echo akibara_icon('whatsapp', 22); ?></span>
                <h3>WhatsApp directo</h3>
                <p>Para dudas rápidas o seguimiento inmediato de tu pedido.</p>
                <a class="contact-card__link" href="<?php echo esc_url(function_exists('akibara_wa_url') ? akibara_wa_url('Hola, necesito ayuda con mi pedido') : 'https://wa.me/' . ( function_exists('akibara_whatsapp_get_business_number') ? akibara_whatsapp_get_business_number() : '' )); ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
            </div>

            <div class="contact-card">
                <span class="contact-card__icon" aria-hidden="true"><?php echo akibara_icon('truck', 22); ?></span>
                <h3>¿Ya compraste?</h3>
                <p>Revisa el estado de tu envío o actualiza tus datos de entrega.</p>
                <a class="contact-card__link" href="<?php echo esc_url(home_url('/rastrear/')); ?>">Rastrear pedido</a>
            </div>

            <div class="contact-card">
                <span class="contact-card__icon" aria-hidden="true"><?php echo akibara_icon('shield', 22); ?></span>
                <h3>Respuestas rápidas</h3>
                <p>Lo más frecuente está resuelto en nuestra sección de ayuda.</p>
                <a class="contact-card__link" href="<?php echo esc_url(home_url('/preguntas-frecuentes/')); ?>">Ver preguntas frecuentes</a>
            </div>
        </div>

        <div class="contact-grid__form">
            <div class="contact-form">
                <h2>Formulario de contacto</h2>
                <p class="contact-form__lead">Completa estos datos y nuestro equipo te responderá por correo.</p>

                <?php if ($submitted) : ?>
                    <div class="contact-alert contact-alert--success" role="status" aria-live="polite">
                        ¡Gracias! Recibimos tu mensaje y te responderemos pronto.
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)) : ?>
                    <div class="contact-alert contact-alert--error" role="alert" aria-live="assertive">
                        <strong>Revisa estos campos:</strong>
                        <ul>
                            <?php foreach ($errors as $error) : ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(get_permalink()); ?>" novalidate>
                    <?php wp_nonce_field('akb_contact_form', 'akb_contact_nonce'); ?>
                    <input type="text" name="website" class="contact-form__hidden" tabindex="-1" autocomplete="off">

                    <div class="contact-form__row">
                        <div class="contact-form__field">
                            <label class="contact-form__label" for="contact-name">Nombre</label>
                            <input
                                id="contact-name"
                                name="name"
                                class="contact-form__input"
                                type="text"
                                autocomplete="name"
                                required
                                value="<?php echo esc_attr($values['name']); ?>"
                            >
                        </div>
                        <div class="contact-form__field">
                            <label class="contact-form__label" for="contact-email">Email</label>
                            <input
                                id="contact-email"
                                name="email"
                                class="contact-form__input"
                                type="email"
                                autocomplete="email"
                                required
                                value="<?php echo esc_attr($values['email']); ?>"
                            >
                        </div>
                    </div>

                    <div class="contact-form__field">
                        <label class="contact-form__label" for="contact-reason">Motivo</label>
                        <select id="contact-reason" name="reason" class="contact-form__select" required>
                            <option value="">Selecciona una opción</option>
                            <?php foreach ($reasons as $key => $label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($values['reason'], $key); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="contact-form__field">
                        <label class="contact-form__label" for="contact-order">Número de pedido (opcional)</label>
                        <input
                            id="contact-order"
                            name="order"
                            class="contact-form__input"
                            type="text"
                            autocomplete="off"
                            placeholder="Ej: #10234"
                            value="<?php echo esc_attr($values['order']); ?>"
                        >
                    </div>

                    <div class="contact-form__field">
                        <label class="contact-form__label" for="contact-message">Mensaje</label>
                        <textarea
                            id="contact-message"
                            name="message"
                            class="contact-form__textarea"
                            rows="6"
                            required
                        ><?php echo esc_textarea($values['message']); ?></textarea>
                    </div>

                    <button class="btn btn--primary" type="submit"><span>Enviar mensaje</span></button>
                    <p class="contact-form__note">Tus datos se usan solo para responder tu solicitud. No enviamos spam.</p>
                </form>
            </div>
        </div>
    </section>
</main>

<?php get_footer(); ?>
