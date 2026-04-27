<?php
/**
 * Blog Enhancements
 *
 * Reading time, share buttons, table of contents, auto product links.
 *
 * @package Akibara
 * @since   4.6.0
 */

defined( "ABSPATH" ) || exit;

/* --- 1. Reading Time -------------------------------------------------- */

/**
 * Return estimated reading time string.
 *
 * Spanish text averages ~200 wpm; we use strip_tags so shortcodes
 * and HTML do not inflate the count.
 *
 * @param int $post_id  Post ID (defaults to current post in loop).
 * @return string  e.g. "3 min de lectura"
 */
function akibara_reading_time( int $post_id = 0 ): string {
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }
    $content    = get_post_field( "post_content", $post_id );
    $word_count = str_word_count( strip_tags( $content ) );
    $minutes    = max( 1, (int) ceil( $word_count / 200 ) );
    return $minutes . " min de lectura";
}

/* --- 2. Share Buttons ------------------------------------------------- */

/**
 * Render social share buttons (WhatsApp first -- critical for Chile).
 *
 * No external JS dependencies. Copy-link uses Clipboard API
 * with a graceful no-JS label.
 */
function akibara_share_buttons(): void {
    $url   = urlencode( get_permalink() );
    $title = urlencode( get_the_title() );
    $raw_url = esc_js( get_permalink() );
    ?>
    <div class="post-share">
        <span class="post-share__label">Compartir:</span>
        <a href="https://api.whatsapp.com/send?text=<?php echo $title . '%20' . $url; ?>"
           target="_blank" rel="noopener"
           class="post-share__btn post-share__btn--whatsapp"
           aria-label="Compartir en WhatsApp">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492l4.637-1.467A11.941 11.941 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818c-2.168 0-4.19-.587-5.932-1.61l-.424-.253-2.75.87.88-2.685-.278-.44A9.779 9.779 0 012.182 12c0-5.418 4.4-9.818 9.818-9.818 5.418 0 9.818 4.4 9.818 9.818 0 5.418-4.4 9.818-9.818 9.818z"/></svg>
            <span>WhatsApp</span>
        </a>
        <a href="https://twitter.com/intent/tweet?url=<?php echo $url; ?>&text=<?php echo $title; ?>"
           target="_blank" rel="noopener"
           class="post-share__btn post-share__btn--twitter"
           aria-label="Compartir en X">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        </a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $url; ?>"
           target="_blank" rel="noopener"
           class="post-share__btn post-share__btn--facebook"
           aria-label="Compartir en Facebook">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
        </a>
        <button class="post-share__btn post-share__btn--copy"
                onclick="var b=this;navigator.clipboard.writeText('<?php echo $raw_url; ?>').then(function(){var b=event.currentTarget;b.classList.add('copied');b.querySelector('.copy-label').textContent='Copiado';setTimeout(function(){b.classList.remove('copied');b.querySelector('.copy-label').textContent='Copiar'},2000)})"
                aria-label="Copiar enlace">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
            <span class="copy-label">Copiar</span>
        </button>
    </div>
    <?php
}

/* --- 3. Table of Contents --------------------------------------------- */

/**
 * Auto-generate a TOC from H2/H3 headings and inject it before
 * the first heading. Only fires on single blog posts with 3+
 * headings so short posts stay clean.
 *
 * Hooked at priority 5 so it runs before wpautop (priority 10).
 */
function akibara_generate_toc( string $content ): string {
    if ( ! is_single() || get_post_type() !== "post" ) {
        return $content;
    }

    preg_match_all(
        '/<h([23])[^>]*>(.*?)<\/h[23]>/i',
        $content,
        $matches,
        PREG_SET_ORDER
    );

    if ( count( $matches ) < 3 ) {
        return $content;
    }

    $toc  = '<nav class="post-toc" aria-label="Tabla de contenido">';
    $toc .= '<details open>';
    $toc .= '<summary class="post-toc__title">';
    $toc .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="15" y2="12"/><line x1="3" y1="18" x2="18" y2="18"/></svg>';
    $toc .= ' Contenido</summary>';
    $toc .= '<ol class="post-toc__list">';

    foreach ( $matches as $match ) {
        $level = $match[1];
        $text  = strip_tags( $match[2] );
        $id    = "toc-" . sanitize_title( $text );

        // Inject id= into the heading in the post content.
        $replacement = '<h' . $level . ' id="' . esc_attr( $id ) . '">' . $match[2] . '</h' . $level . '>';
        $content     = str_replace( $match[0], $replacement, $content );

        $class = ( $level === "3" ) ? ' class="post-toc__sub"' : '';
        $toc  .= '<li' . $class . '><a href="#' . esc_attr( $id ) . '">' . esc_html( $text ) . '</a></li>';
    }

    $toc .= '</ol></details></nav>';

    return $toc . $content;
}
add_filter( "the_content", "akibara_generate_toc", 5 );

/* --- 4. Image Alt Fallback -------------------------------------------- */

/**
 * Derive a useful alt text for a blog image.
 *
 * Priority:
 *  1) Attachment meta `_wp_attachment_image_alt` (editor-provided).
 *  2) Attachment post_excerpt (caption).
 *  3) Attachment post_title (often the filename prettified).
 *  4) Post title provided by the caller (accurate in-context).
 *  5) Generic site fallback (last resort — never leave empty).
 *
 * @param int    $attachment_id Attachment post ID (0 = no attachment).
 * @param string $context_title Usually the post/product title.
 */
function akibara_blog_image_alt( int $attachment_id, string $context_title = '' ): string {
    // 1) Editor-provided alt (explicit intent — always wins).
    if ( $attachment_id ) {
        $alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        if ( $alt !== '' ) {
            return $alt;
        }
    }

    // 2) Context title from caller (the post/product title — keyword-rich).
    $context_title = trim( wp_strip_all_tags( $context_title ) );
    if ( $context_title !== '' ) {
        return $context_title;
    }

    // 3) Attachment caption / title as lower-value fallback.
    if ( $attachment_id ) {
        $attachment = get_post( $attachment_id );
        if ( $attachment ) {
            if ( $attachment->post_excerpt !== '' ) {
                return wp_strip_all_tags( $attachment->post_excerpt );
            }
            if ( $attachment->post_title !== '' && preg_match( '/\s/', $attachment->post_title ) ) {
                return $attachment->post_title;
            }
        }
    }

    return 'Akibara — manga y comics en Chile';
}

/**
 * Global fallback so any `wp_get_attachment_image()` call on a post-context
 * thumbnail gets a usable alt, even when the editor forgot to set one.
 *
 * Does NOT overwrite existing alt attributes.
 */
add_filter( 'wp_get_attachment_image_attributes', function ( array $attr, $attachment, $size ): array {
    if ( isset( $attr['alt'] ) && trim( $attr['alt'] ) !== '' ) {
        return $attr;
    }

    $attachment_id = is_object( $attachment ) ? (int) $attachment->ID : (int) $attachment;
    $context_title = '';

    // Prefer queried object title when thumbnail belongs to current post.
    if ( is_singular() && get_post_thumbnail_id() === $attachment_id ) {
        $context_title = get_the_title();
    } elseif ( is_object( $attachment ) && $attachment->post_parent ) {
        $context_title = get_the_title( $attachment->post_parent );
    }

    $attr['alt'] = akibara_blog_image_alt( $attachment_id, $context_title );
    return $attr;
}, 20, 3 );

/* --- 5. Related Posts by Category ------------------------------------- */

/**
 * Related posts chosen by shared category (falls back to recent if empty).
 *
 * Better for SEO than random: Google rewards topically-clustered internal
 * linking. Also better UX — readers get adjacent content.
 *
 * @param int $post_id Current post ID.
 * @param int $limit   Max related posts to return.
 * @return WP_Post[]
 */
function akibara_blog_related_posts( int $post_id, int $limit = 4 ): array {
    $cats = wp_get_post_categories( $post_id );
    $cats = array_values( array_diff( $cats, [ 1 ] ) ); // drop Uncategorized

    $related = [];
    if ( $cats ) {
        $related = get_posts( [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'post__not_in'        => [ $post_id ],
            'category__in'        => $cats,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 1,
            'no_found_rows'       => true,
        ] );
    }

    if ( count( $related ) < $limit ) {
        $exclude = array_merge( [ $post_id ], wp_list_pluck( $related, 'ID' ) );
        $fill    = get_posts( [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit - count( $related ),
            'post__not_in'        => $exclude,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => 1,
            'no_found_rows'       => true,
        ] );
        $related = array_merge( $related, $fill );
    }

    return $related;
}

/* --- 6. Auto Product Links -------------------------------------------- */

/**
 * Automatically link series names mentioned in blog posts to their
 * serie landing pages (/serie/{slug}). Only links first occurrence
 * of each series name, and avoids linking inside existing <a> tags
 * or headings.
 *
 * Uses pa_serie taxonomy terms and caches the map for 24 hours.
 */
function akibara_auto_product_links( string $content ): string {
    if ( ! is_single() || get_post_type() !== 'post' ) {
        return $content;
    }

    static $series_map = null;
    if ( $series_map === null ) {
        $series_map = get_transient( 'akb_blog_series_map' );
        if ( $series_map === false ) {
            $terms = get_terms( [
                'taxonomy'   => 'pa_serie',
                'hide_empty' => true,
                'fields'     => 'all',
            ] );

            $series_map = [];
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    if ( strlen( $term->name ) < 4 ) {
                        continue;
                    }
                    $series_map[ $term->name ] = home_url( '/serie/' . $term->slug . '/' );
                }
            }

            // Sort by name length descending so longer names match first
            // (e.g. "Attack On Titan: Sin Remordimientos" before "Attack On Titan")
            uksort( $series_map, function( $a, $b ) {
                return strlen( $b ) - strlen( $a );
            } );

            set_transient( 'akb_blog_series_map', $series_map, DAY_IN_SECONDS );
        }
    }

    if ( empty( $series_map ) ) {
        return $content;
    }

    // Split content into parts: HTML tags vs text nodes.
    // This prevents matching inside existing tags.
    $parts = preg_split( '/(<[^>]+>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
    if ( $parts === false ) {
        return $content;
    }

    $linked   = [];
    $in_a     = 0;
    $in_heading = 0;

    foreach ( $parts as &$part ) {
        // Track whether we're inside an <a> or heading tag
        if ( preg_match( '/<a[\s>]/i', $part ) ) {
            $in_a++;
            continue;
        }
        if ( preg_match( '/<\/a>/i', $part ) ) {
            $in_a = max( 0, $in_a - 1 );
            continue;
        }
        if ( preg_match( '/<h[1-6][\s>]/i', $part ) ) {
            $in_heading++;
            continue;
        }
        if ( preg_match( '/<\/h[1-6]>/i', $part ) ) {
            $in_heading = max( 0, $in_heading - 1 );
            continue;
        }

        // Skip HTML tags and content inside <a> or headings
        if ( $part === '' || $part[0] === '<' || $in_a > 0 || $in_heading > 0 ) {
            continue;
        }

        // Try to match series names in this text node.
        // Case-sensitive on purpose: "Saga" is a real comic, "saga" is a generic
        // story-arc word; matching case-insensitively turned every "saga del
        // ascenso" into a link to /serie/saga/. Proper nouns are capitalized.
        foreach ( $series_map as $serie => $url ) {
            if ( isset( $linked[ $serie ] ) ) {
                continue;
            }

            $pattern = '/\b(' . preg_quote( $serie, '/' ) . ')\b/u';
            if ( preg_match( $pattern, $part ) ) {
                $replacement = '<a href="' . esc_url( $url ) . '" class="blog-product-link" title="Ver ' . esc_attr( $serie ) . ' en Akibara">${1}</a>';
                $part = preg_replace( $pattern, $replacement, $part, 1 );
                $linked[ $serie ] = true;
            }
        }
    }
    unset( $part );

    return implode( '', $parts );
}
add_filter( 'the_content', 'akibara_auto_product_links', 20 );

/* --- 7. Inline Newsletter CTA at post end ----------------------------- */

/**
 * Render a compact newsletter CTA box injected after the product widget.
 *
 * Supressed for visitors with cookie akb_ref (they already have a referral
 * discount — adding a newsletter CTA risks user confusion even though WC
 * individual_use=true prevents actual stacking).
 *
 * Uses the same AJAX action as the footer newsletter (akibara_newsletter_subscribe)
 * and the same Brevo list 2 — no new PHP handler needed.
 */
function akibara_blog_post_newsletter_cta( string $content ): string {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	// Server-side suppression for referral visitors (anti-stacking policy).
	if ( ! empty( $_COOKIE['akb_ref'] ) ) {
		return $content;
	}

	$nonce   = wp_create_nonce( 'akibara-newsletter' );
	$ajax    = esc_url( admin_url( 'admin-ajax.php' ) );
	$shop    = esc_url( home_url( '/tienda/' ) );
	$form_id = 'akb-blog-nl-' . get_the_ID();

	ob_start();
	?>
	<aside class="aki-blog-newsletter" id="<?php echo esc_attr( $form_id . '-wrap' ); ?>" aria-label="Newsletter Akibara">
		<span class="aki-blog-newsletter__eyebrow">Newsletter</span>
		<p class="aki-blog-newsletter__title">¿Te gustó el artículo?</p>
		<p class="aki-blog-newsletter__sub">Suscríbete y recibe novedades manga + <strong>10% OFF</strong> en tu primera compra, al tiro.</p>
		<form class="aki-blog-newsletter__form" id="<?php echo esc_attr( $form_id ); ?>" autocomplete="on" novalidate>
			<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
			<input type="text" name="website_url" value="" autocomplete="off" tabindex="-1"
			       aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0">
			<input type="email" name="email"
			       class="aki-blog-newsletter__input"
			       placeholder="tu@email.com"
			       required autocomplete="email"
			       aria-label="Correo electrónico">
			<button type="submit" class="aki-blog-newsletter__btn">Suscribirme</button>
		</form>
		<div class="aki-blog-newsletter__msg" id="<?php echo esc_attr( $form_id . '-msg' ); ?>" aria-live="polite"></div>
		<p class="aki-blog-newsletter__subscribed" id="<?php echo esc_attr( $form_id . '-ok' ); ?>" style="display:none">
			&#10003; Ya estás suscrito &mdash; <a href="<?php echo $shop; ?>">ver ofertas</a>
		</p>
		<p class="aki-blog-newsletter__privacy">Sin spam. Puedes salir cuando quieras.</p>
	</aside>
	<script>
	(function(){
		var wrap = document.getElementById(<?php echo wp_json_encode( $form_id . '-wrap' ); ?>);
		var form = document.getElementById(<?php echo wp_json_encode( $form_id ); ?>);
		var msg  = document.getElementById(<?php echo wp_json_encode( $form_id . '-msg' ); ?>);
		var okEl = document.getElementById(<?php echo wp_json_encode( $form_id . '-ok' ); ?>);
		if (!form || !wrap) return;

		// Hide if already subscribed (same localStorage keys as footer newsletter).
		try {
			if (localStorage.getItem('akibara_popup_subscribed') === '1' ||
			    localStorage.getItem('akibara_newsletter_subscribed') === '1') {
				if (wrap) wrap.style.display = 'none';
				return;
			}
		} catch(e) {}

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var email    = form.querySelector('[name=email]').value.trim();
			var nonce    = form.querySelector('[name=nonce]').value;
			var honeypot = form.querySelector('[name=website_url]');
			var btn      = form.querySelector('.aki-blog-newsletter__btn');
			if (!email || (honeypot && honeypot.value)) return;

			btn.disabled = true;
			btn.textContent = '...';
			msg.textContent = '';
			msg.className = 'aki-blog-newsletter__msg';

			var fd = new FormData();
			fd.append('action', 'akibara_newsletter_subscribe');
			fd.append('email', email);
			fd.append('nonce', nonce);

			fetch(<?php echo wp_json_encode( $ajax ); ?>, { method: 'POST', body: fd })
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (data.success) {
						try { localStorage.setItem('akibara_newsletter_subscribed', '1'); } catch(ex) {}
						form.style.display = 'none';
						if (okEl) okEl.style.display = '';
					} else {
						msg.textContent = (data.data && data.data.message) || 'Error al suscribirse.';
						msg.className = 'aki-blog-newsletter__msg aki-blog-newsletter__msg--err';
						btn.disabled = false;
						btn.textContent = 'Suscribirme';
					}
				})
				.catch(function(){
					msg.textContent = 'Error de conexión. Intenta nuevamente.';
					msg.className = 'aki-blog-newsletter__msg aki-blog-newsletter__msg--err';
					btn.disabled = false;
					btn.textContent = 'Suscribirme';
				});
		});
	})();
	</script>
	<?php
	return $content . (string) ob_get_clean();
}
add_filter( 'the_content', 'akibara_blog_post_newsletter_cta', 30 );
