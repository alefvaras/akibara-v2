<?php
/**
 * Shortcode: [akibara_editoriales_grid]
 *
 * Renders a responsive grid of editoriales (product_brand terms) with logo,
 * description, and real product count. Groups by country meta (AR / ES).
 * Replaces hard-coded HTML in the /editoriales/ page content.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'akibara_editoriales_grid', 'akibara_shortcode_editoriales_grid' );

/**
 * Render the editoriales grid.
 */
function akibara_shortcode_editoriales_grid( $atts = [] ): string {
	$atts = shortcode_atts(
		[
			'group_by_country' => 'yes',   // "yes" | "no"
			'min_count'        => 1,        // hide terms with fewer products
		],
		$atts,
		'akibara_editoriales_grid'
	);

	$cache_key = 'akibara_editoriales_grid_' . md5( wp_json_encode( $atts ) );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$terms = get_terms(
		[
			'taxonomy'   => 'product_brand',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		]
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	// Build entries with metadata.
	$entries = [];
	foreach ( $terms as $term ) {
		if ( $term->count < (int) $atts['min_count'] ) {
			continue;
		}

		$thumb_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		$img_url  = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
		$img_srcset = $thumb_id ? wp_get_attachment_image_srcset( $thumb_id, 'medium' ) : '';
		$country  = (string) get_term_meta( $term->term_id, 'country', true );

		$desc = trim( wp_strip_all_tags( (string) $term->description ) );
		if ( mb_strlen( $desc ) > 180 ) {
			$desc = mb_substr( $desc, 0, 177 ) . '…';
		}

		$entries[] = [
			'term'    => $term,
			'url'     => get_term_link( $term ),
			'img'     => $img_url,
			'srcset'  => $img_srcset,
			'country' => $country,
			'desc'    => $desc,
		];
	}

	if ( empty( $entries ) ) {
		return '';
	}

	// Group by country.
	$groups = [
		'AR'      => [ 'label' => 'Edición Argentina', 'items' => [] ],
		'ES'      => [ 'label' => 'Edición Española',  'items' => [] ],
		'__other' => [ 'label' => 'Otras editoriales', 'items' => [] ],
	];
	foreach ( $entries as $entry ) {
		$key = isset( $groups[ $entry['country'] ] ) ? $entry['country'] : '__other';
		$groups[ $key ]['items'][] = $entry;
	}

	ob_start();
	akibara_editoriales_grid_print_styles_once();

	$img_index = 0; // Track total images rendered to decide priority hint.

	if ( 'yes' === $atts['group_by_country'] ) {
		echo '<div class="aki-editoriales">';
		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) ) {
				continue;
			}
			echo '<section class="aki-editoriales__group">';
			echo '<h2 class="aki-editoriales__country">' . esc_html( $group['label'] ) . '</h2>';
			echo '<div class="aki-editoriales__grid">';
			foreach ( $group['items'] as $entry ) {
				akibara_editoriales_render_card( $entry, $img_index++ );
			}
			echo '</div>';
			echo '</section>';
		}
		echo '</div>';
	} else {
		echo '<div class="aki-editoriales"><div class="aki-editoriales__grid">';
		foreach ( $entries as $entry ) {
			akibara_editoriales_render_card( $entry, $img_index++ );
		}
		echo '</div></div>';
	}

	$html = (string) ob_get_clean();

	set_transient( $cache_key, $html, HOUR_IN_SECONDS );
	return $html;
}

/**
 * Render a single editorial card.
 *
 * @param array $entry   Card data.
 * @param int   $index   Zero-based render index (for fetchpriority / loading hints).
 */
function akibara_editoriales_render_card( array $entry, int $index ): void {
	$term  = $entry['term'];
	$count = (int) $term->count;
	$count_text = $count . ' ' . ( 1 === $count ? 'título' : 'títulos' );

	// First image is eager + high priority (above the fold).
	$loading = $index === 0 ? 'eager' : 'lazy';
	$fetch   = $index === 0 ? 'high' : 'auto';

	?>
	<a class="aki-editorial-card"
	   href="<?php echo esc_url( $entry['url'] ); ?>"
	   aria-label="<?php echo esc_attr( $term->name . ' — ' . $count_text ); ?>">
		<div class="aki-editorial-card__media">
			<?php if ( ! empty( $entry['img'] ) ) : ?>
				<img src="<?php echo esc_url( $entry['img'] ); ?>"
				     <?php if ( ! empty( $entry['srcset'] ) ) : ?>srcset="<?php echo esc_attr( $entry['srcset'] ); ?>" sizes="(max-width: 600px) 40vw, 240px"<?php endif; ?>
				     alt="Logo <?php echo esc_attr( $term->name ); ?>"
				     loading="<?php echo esc_attr( $loading ); ?>"
				     fetchpriority="<?php echo esc_attr( $fetch ); ?>"
				     decoding="async"
				     width="240" height="160">
			<?php else : ?>
				<span class="aki-editorial-card__fallback" aria-hidden="true">
					<?php echo esc_html( mb_strtoupper( mb_substr( $term->name, 0, 2 ) ) ); ?>
				</span>
			<?php endif; ?>
		</div>
		<div class="aki-editorial-card__body">
			<h3 class="aki-editorial-card__title"><?php echo esc_html( $term->name ); ?></h3>
			<?php if ( ! empty( $entry['desc'] ) ) : ?>
				<p class="aki-editorial-card__desc"><?php echo esc_html( $entry['desc'] ); ?></p>
			<?php endif; ?>
			<span class="aki-editorial-card__cta">
				<?php echo esc_html( $count_text ); ?>
				<span aria-hidden="true">→</span>
			</span>
		</div>
	</a>
	<?php
}

/**
 * Print the CSS block once per request.
 */
function akibara_editoriales_grid_print_styles_once(): void {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
<style id="aki-editoriales-grid-css">
.aki-editoriales{display:flex;flex-direction:column;gap:var(--space-10,2.5rem);margin:var(--space-8,2rem) 0}
.aki-editoriales__group{display:flex;flex-direction:column;gap:var(--space-4,1rem)}
.aki-editoriales__country{font-family:var(--font-display,'Bebas Neue',sans-serif);font-size:clamp(1.1rem,2.2vw,1.35rem);letter-spacing:.08em;text-transform:uppercase;color:var(--aki-text-muted,#a0a0a0);margin:0;padding-bottom:var(--space-2,.5rem);border-bottom:1px solid var(--aki-border,#2a2a2e)}
.aki-editoriales__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:var(--space-5,1.25rem)}
.aki-editorial-card{display:flex;flex-direction:column;background:var(--aki-surface,#161618);border:1px solid var(--aki-border,#2a2a2e);border-radius:14px;overflow:hidden;text-decoration:none;color:inherit;transition:transform .25s ease,border-color .25s ease,box-shadow .25s ease}
.aki-editorial-card:hover,.aki-editorial-card:focus-visible{transform:translateY(-3px);border-color:var(--aki-red,#D90010);box-shadow:0 12px 28px -12px rgba(217,0,16,.35);outline:none}
.aki-editorial-card__media{position:relative;height:180px;background:#fff;display:flex;align-items:center;justify-content:center;padding:var(--space-5,1.25rem);flex-shrink:0}
.aki-editorial-card__media img{width:100%;height:100%;object-fit:contain;display:block}
.aki-editorial-card__fallback{font-family:var(--font-display,'Bebas Neue',sans-serif);font-size:3rem;letter-spacing:.05em;color:var(--aki-text-dim,#666)}
.aki-editorial-card__body{display:flex;flex-direction:column;gap:var(--space-2,.5rem);padding:var(--space-4,1rem) var(--space-5,1.25rem) var(--space-5,1.25rem)}
.aki-editorial-card__title{font-size:1.15rem;margin:0;color:var(--aki-text,#f5f5f5);font-weight:700}
.aki-editorial-card__desc{font-size:.9rem;line-height:1.45;color:var(--aki-text-muted,#a0a0a0);margin:0;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.aki-editorial-card__cta{margin-top:auto;font-size:.85rem;font-weight:700;color:var(--aki-red-bright,#FF2020);display:inline-flex;align-items:center;gap:.35rem;padding-top:var(--space-2,.5rem)}
.aki-editorial-card__cta span{transition:transform .2s ease}
.aki-editorial-card:hover .aki-editorial-card__cta span{transform:translateX(4px)}
@media (max-width:600px){
  .aki-editoriales__grid{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:var(--space-3,.75rem)}
  .aki-editorial-card__media{height:120px;padding:var(--space-3,.75rem)}
  .aki-editorial-card__body{padding:var(--space-3,.75rem)}
  .aki-editorial-card__title{font-size:1rem}
  .aki-editorial-card__desc{font-size:.8rem;-webkit-line-clamp:2}
}
</style>
	<?php
}

/**
 * Invalidate shortcode cache when product_brand terms change.
 */
function akibara_editoriales_flush_cache(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_akibara_editoriales_grid_%' OR option_name LIKE '_transient_timeout_akibara_editoriales_grid_%'" );
}
add_action( 'created_product_brand', 'akibara_editoriales_flush_cache' );
add_action( 'edited_product_brand', 'akibara_editoriales_flush_cache' );
add_action( 'delete_product_brand', 'akibara_editoriales_flush_cache' );
add_action( 'saved_term', function ( $term_id, $tt_id, $taxonomy ) {
	if ( 'product_brand' === $taxonomy ) {
		akibara_editoriales_flush_cache();
	}
}, 10, 3 );
