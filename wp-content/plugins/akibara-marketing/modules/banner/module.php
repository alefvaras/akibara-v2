<?php
/**
 * Akibara Marketing — Módulo Banner / Topbar
 *
 * Lifted from server-snapshot plugins/akibara/modules/banner/module.php (v2.0.0).
 * Adapted: load guard changed from AKIBARA_V10_LOADED → AKB_MARKETING_LOADED.
 * Group wrap pattern applied (Sprint 2 REDESIGN.md §9).
 *
 * Gestión completa del topbar:
 *  - Panel admin para CRUD de mensajes estáticos
 *  - Lectura automática de promos desde módulo de descuentos
 *  - Rotación con fade en el frontend
 *  - Ordenamiento y activación/desactivación por mensaje
 *
 * @package    Akibara\Marketing
 * @subpackage Banner
 * @version    2.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AKB_MARKETING_LOADED' ) ) {
	return;
}

if ( defined( 'AKB_MARKETING_BANNER_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_BANNER_LOADED', '2.0.0' );

// ── Constants (always defined) ──────────────────────────────────────────────
if ( ! defined( 'AKIBARA_BANNER_OPTION' ) ) {
	define( 'AKIBARA_BANNER_OPTION', 'akibara_banner_mensajes' );
}
if ( ! defined( 'AKIBARA_BANNER_INTERVALO' ) ) {
	define( 'AKIBARA_BANNER_INTERVALO', 6 ); // segundos entre mensajes
}
if ( ! defined( 'AKIBARA_BANNER_MAX_PROMOS' ) ) {
	define( 'AKIBARA_BANNER_MAX_PROMOS', 2 );
}

// ── Group wrap ───────────────────────────────────────────────────────────────
if ( ! function_exists( 'akb_marketing_banner_sentinel' ) ) {

	function akb_marketing_banner_sentinel(): bool {
		return defined( 'AKB_MARKETING_BANNER_LOADED' );
	}

	// ── MIGRACIÓN — crear option si no existe con mensajes por defecto ─────────
	add_action( 'init', 'akibara_banner_migrar', 5 );

	function akibara_banner_migrar(): void {
		if ( get_option( AKIBARA_BANNER_OPTION ) !== false ) {
			return;
		}

		$defaults = array(
			array(
				'texto'  => 'Envío gratis en pedidos sobre $55.000',
				'icono'  => '',
				'link'   => '',
				'activo' => true,
			),
			array(
				'texto'  => 'Retiro GRATIS en San Miguel (solo RM)',
				'icono'  => '',
				'link'   => '',
				'activo' => true,
			),
			array(
				'texto'  => 'Hasta 3 cuotas sin interés',
				'icono'  => '',
				'link'   => '',
				'activo' => true,
			),
			array(
				'texto'  => 'Todos nuestros mangas incluyen funda protectora',
				'icono'  => '',
				'link'   => '',
				'activo' => true,
			),
		);

		update_option( AKIBARA_BANNER_OPTION, $defaults, false );
	}

	// ── HELPER: obtener mensajes activos ───────────────────────────────────────

	function akibara_banner_get_mensajes_estaticos(): array {
		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );
		if ( ! is_array( $mensajes ) ) {
			return array();
		}

		$user_in_regiones = function_exists( 'akibara_user_is_regiones' ) && akibara_user_is_regiones();

		$activos = array();
		foreach ( $mensajes as $m ) {
			if ( empty( $m['activo'] ) ) {
				continue;
			}
			$texto = trim( $m['texto'] ?? '' );
			if ( $texto === '' ) {
				continue;
			}

			if ( $user_in_regiones ) {
				$lower = mb_strtolower( $texto );
				if ( strpos( $lower, 'san miguel' ) !== false || strpos( $lower, 'solo rm' ) !== false ) {
					continue;
				}
			}

			$activos[] = $m;
		}
		return $activos;
	}

	function akibara_banner_get_primer_mensaje(): string {
		$mensajes = akibara_banner_get_mensajes_estaticos();
		return ! empty( $mensajes ) ? $mensajes[0]['texto'] : 'Envío gratis sobre $55.000 CLP · Mismo día en RM';
	}

	// ── ADMIN ──────────────────────────────────────────────────────────────────

	add_action( 'admin_menu', 'akibara_banner_admin_menu' );

	if ( function_exists( 'akb_ajax_endpoint' ) ) {
		akb_ajax_endpoint(
			'akibara_banner_save',
			array(
				'nonce'      => 'akibara_banner',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					akibara_banner_ajax_save();
				},
			)
		);
		akb_ajax_endpoint(
			'akibara_banner_delete',
			array(
				'nonce'      => 'akibara_banner',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					akibara_banner_ajax_delete();
				},
			)
		);
		akb_ajax_endpoint(
			'akibara_banner_toggle',
			array(
				'nonce'      => 'akibara_banner',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					akibara_banner_ajax_toggle();
				},
			)
		);
		akb_ajax_endpoint(
			'akibara_banner_reorder',
			array(
				'nonce'      => 'akibara_banner',
				'capability' => 'manage_woocommerce',
				'handler'    => static function ( array $post ): void {
					akibara_banner_ajax_reorder();
				},
			)
		);
	}

	function akibara_banner_admin_menu(): void {
		if ( defined( 'AKIBARA_ADMIN_DASHBOARD_LOADED' ) ) {
			return;
		}

		add_submenu_page(
			'akibara',
			'Topbar — Mensajes',
			'Topbar',
			'manage_woocommerce',
			'akibara-topbar',
			'akibara_banner_render_admin'
		);
	}

	function akibara_banner_render_admin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos' );
		}

		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );
		if ( ! is_array( $mensajes ) ) {
			$mensajes = array();
		}

		$promos = akibara_banner_get_mensajes_promo( AKIBARA_BANNER_MAX_PROMOS );
		?>
		<div class="akb-page-header" style="display:flex;justify-content:space-between;align-items:center">
			<div>
				<h2 class="akb-page-header__title">Topbar</h2>
				<p class="akb-page-header__desc">Gestión de mensajes rotativos del topbar. v<?php echo esc_html( AKB_MARKETING_BANNER_LOADED ); ?></p>
			</div>
			<button class="akb-btn akb-btn--primary" onclick="abtAbrirModal()">+ Nuevo mensaje</button>
		</div>

		<p class="akb-section-label">Mensajes estáticos</p>

		<?php if ( empty( $mensajes ) ) : ?>
			<div class="akb-empty">
				<p>No hay mensajes configurados.</p>
				<button class="akb-btn akb-btn--primary" onclick="abtAbrirModal()">Crear primer mensaje</button>
			</div>
		<?php else : ?>
			<?php
			foreach ( $mensajes as $idx => $msg ) :
				$activo = ! empty( $msg['activo'] );
				?>
			<div class="akb-card <?php echo $activo ? '' : 'akb-card--inactive'; ?>">
				<div class="akb-card__header">
					<div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
						<div class="akb-reorder">
							<button class="akb-reorder__btn" onclick="abtReorder(<?php echo (int) $idx; ?>,'up')" title="Subir" <?php echo $idx === 0 ? 'disabled' : ''; ?>>&#9650;</button>
							<button class="akb-reorder__btn" onclick="abtReorder(<?php echo (int) $idx; ?>,'down')" title="Bajar" <?php echo $idx === count( $mensajes ) - 1 ? 'disabled' : ''; ?>>&#9660;</button>
						</div>
						<span class="akb-icon-preview"><?php echo esc_html( $msg['icono'] ?? '' ); ?></span>
						<div style="min-width:0">
							<div class="akb-msg-text"><?php echo esc_html( $msg['texto'] ?? '' ); ?></div>
							<?php if ( ! empty( $msg['link'] ) ) : ?>
								<div class="akb-msg-link"><?php echo esc_html( $msg['link'] ); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div style="display:flex;align-items:center;gap:8px">
						<span class="akb-badge <?php echo $activo ? 'akb-badge--active' : 'akb-badge--inactive'; ?>"><?php echo $activo ? 'Activo' : 'Inactivo'; ?></span>
						<div class="akb-card__actions">
							<button class="akb-btn akb-btn--sm" onclick="abtToggle(<?php echo (int) $idx; ?>)"><?php echo $activo ? 'Desactivar' : 'Activar'; ?></button>
							<button class="akb-btn akb-btn--sm" onclick="abtEditar(<?php echo (int) $idx; ?>)">Editar</button>
							<button class="akb-btn akb-btn--sm akb-btn--danger" onclick="abtEliminar(<?php echo (int) $idx; ?>)">Eliminar</button>
						</div>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( ! empty( $promos ) ) : ?>
			<p class="akb-section-label">Promos automáticas <small>(desde Descuentos)</small></p>
			<?php foreach ( $promos as $promo ) : ?>
				<div class="akb-promo-card">
					<span class="akb-badge--auto">AUTO</span>
					<?php echo esc_html( $promo ); ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<div class="akb-notice akb-notice--info">
			<strong>Cómo funciona:</strong><br>
			Los mensajes estáticos + las promos automáticas de descuentos rotan en el topbar del sitio cada <?php echo esc_html( (string) AKIBARA_BANNER_INTERVALO ); ?> segundos.
		</div>

		<!-- Modal -->
		<div id="abt-modal" class="akb-modal-overlay">
			<div class="akb-modal" style="max-width:520px">
				<div class="akb-modal__header">
					<h2 class="akb-modal__title" id="abt-modal-title">Nuevo mensaje</h2>
					<button class="akb-modal__close" onclick="abtCerrarModal()">&times;</button>
				</div>
				<form id="abt-form">
					<input type="hidden" name="idx" id="abt-idx" value="-1">

					<div class="akb-field__row">
						<div class="akb-field" style="flex:0 0 80px">
							<label class="akb-field__label">Icono</label>
							<input type="text" name="icono" id="abt-icono" class="akb-field__input" placeholder="" maxlength="10" style="max-width:80px">
							<p class="akb-field__hint">Emoji opcional</p>
						</div>
						<div class="akb-field" style="flex:1">
							<label class="akb-field__label">Texto del mensaje</label>
							<input type="text" name="texto" id="abt-texto" class="akb-field__input" required placeholder="Ej: Envío gratis en pedidos sobre $55.000" style="max-width:100%">
						</div>
					</div>

					<div class="akb-field">
						<label class="akb-field__label">Link (opcional)</label>
						<input type="url" name="link" id="abt-link" class="akb-field__input" placeholder="https://akibara.cl/tienda/" style="max-width:100%">
					</div>

					<div class="akb-field">
						<label>
							<input type="checkbox" name="activo" id="abt-activo" checked>
							Mensaje activo
						</label>
					</div>

					<div class="akb-modal__footer">
						<button type="button" class="akb-btn" onclick="abtCerrarModal()">Cancelar</button>
						<button type="submit" class="akb-btn akb-btn--primary">Guardar</button>
					</div>
				</form>
			</div>
		</div>

		<script>
		var abtMensajes = <?php
			$safe_mensajes = array_map(
				function ( array $m ): array {
					return array(
						'icono'  => sanitize_text_field( $m['icono'] ?? '' ),
						'texto'  => sanitize_text_field( $m['texto'] ?? '' ),
						'link'   => esc_url_raw( $m['link'] ?? '' ),
						'activo' => ! empty( $m['activo'] ),
					);
				},
				is_array( $mensajes ) ? $mensajes : array()
			);
			echo wp_json_encode( $safe_mensajes, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE );
		?>;
		var abtAjaxUrl  = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
		var abtNonce    = '<?php echo esc_js( wp_create_nonce( 'akibara_banner' ) ); ?>';

		function abtPost(body){fetch(abtAjaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body+'&nonce='+abtNonce}).then(function(r){return r.json();}).then(function(data){if(data.success)location.reload();else alert(data.data||'Error');}).catch(function(){alert('Error de conexión');});}
		function abtAbrirModal(idx){idx=(idx!==undefined)?idx:-1;document.getElementById('abt-idx').value=idx;document.getElementById('abt-modal-title').textContent=idx>=0?'Editar mensaje':'Nuevo mensaje';document.getElementById('abt-icono').value='';document.getElementById('abt-texto').value='';document.getElementById('abt-link').value='';document.getElementById('abt-activo').checked=true;if(idx>=0&&abtMensajes[idx]){var m=abtMensajes[idx];document.getElementById('abt-icono').value=m.icono||'';document.getElementById('abt-texto').value=m.texto||'';document.getElementById('abt-link').value=m.link||'';document.getElementById('abt-activo').checked=!!m.activo;}document.getElementById('abt-modal').classList.add('akb-modal-overlay--open');document.body.style.overflow='hidden';}
		function abtCerrarModal(){document.getElementById('abt-modal').classList.remove('akb-modal-overlay--open');document.body.style.overflow='';}
		function abtEditar(idx){abtAbrirModal(idx);}
		function abtToggle(idx){abtPost('action=akibara_banner_toggle&idx='+idx);}
		function abtEliminar(idx){if(!confirm('¿Eliminar este mensaje?'))return;abtPost('action=akibara_banner_delete&idx='+idx);}
		function abtReorder(idx,dir){abtPost('action=akibara_banner_reorder&idx='+idx+'&dir='+dir);}
		document.getElementById('abt-form').addEventListener('submit',function(e){e.preventDefault();var fd=new FormData(this);fd.append('action','akibara_banner_save');fd.append('nonce',abtNonce);fetch(abtAjaxUrl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(data){if(data.success)location.reload();else alert(data.data||'Error al guardar');}).catch(function(){alert('Error de conexión');});});
		document.getElementById('abt-modal').addEventListener('click',function(e){if(e.target===this)abtCerrarModal();});
		</script>
		<?php
	}

	// ── AJAX HANDLERS ──────────────────────────────────────────────────────────

	function akibara_banner_ajax_save(): void {
		check_ajax_referer( 'akibara_banner', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$idx      = (int) ( $_POST['idx'] ?? -1 );
		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );
		if ( ! is_array( $mensajes ) ) {
			$mensajes = array();
		}

		$mensaje = array(
			'texto'  => sanitize_text_field( wp_unslash( $_POST['texto'] ?? '' ) ),
			'icono'  => sanitize_text_field( wp_unslash( $_POST['icono'] ?? '' ) ),
			'link'   => esc_url_raw( wp_unslash( $_POST['link'] ?? '' ) ),
			'activo' => ! empty( $_POST['activo'] ),
		);

		if ( $mensaje['texto'] === '' ) {
			wp_send_json_error( 'El texto es obligatorio' );
		}

		if ( $idx >= 0 && isset( $mensajes[ $idx ] ) ) {
			$mensajes[ $idx ] = $mensaje;
		} else {
			$mensajes[] = $mensaje;
		}

		update_option( AKIBARA_BANNER_OPTION, $mensajes, false );
		wp_send_json_success();
	}

	function akibara_banner_ajax_delete(): void {
		check_ajax_referer( 'akibara_banner', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$idx      = (int) ( $_POST['idx'] ?? -1 );
		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );

		if ( $idx >= 0 && isset( $mensajes[ $idx ] ) ) {
			array_splice( $mensajes, $idx, 1 );
			update_option( AKIBARA_BANNER_OPTION, $mensajes, false );
		}

		wp_send_json_success();
	}

	function akibara_banner_ajax_toggle(): void {
		check_ajax_referer( 'akibara_banner', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$idx      = (int) ( $_POST['idx'] ?? -1 );
		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );

		if ( $idx >= 0 && isset( $mensajes[ $idx ] ) ) {
			$mensajes[ $idx ]['activo'] = empty( $mensajes[ $idx ]['activo'] );
			update_option( AKIBARA_BANNER_OPTION, $mensajes, false );
		}

		wp_send_json_success();
	}

	function akibara_banner_ajax_reorder(): void {
		check_ajax_referer( 'akibara_banner', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Sin permisos' );
		}

		$idx      = (int) ( $_POST['idx'] ?? -1 );
		$dir      = sanitize_key( $_POST['dir'] ?? '' );
		$mensajes = get_option( AKIBARA_BANNER_OPTION, array() );

		if ( $idx < 0 || ! isset( $mensajes[ $idx ] ) ) {
			wp_send_json_error( 'Indice inválido' );
		}

		$swap = $dir === 'up' ? $idx - 1 : $idx + 1;
		if ( $swap < 0 || $swap >= count( $mensajes ) ) {
			wp_send_json_error( 'No se puede mover' );
		}

		[ $mensajes[ $idx ], $mensajes[ $swap ] ] = array( $mensajes[ $swap ], $mensajes[ $idx ] );
		$mensajes = array_values( $mensajes );

		update_option( AKIBARA_BANNER_OPTION, $mensajes, false );
		wp_send_json_success();
	}

	// ── FRONTEND — Rotación ────────────────────────────────────────────────────

	add_action( 'wp_footer', 'akibara_banner_rotativo', 99 );

	function akibara_banner_rotativo(): void {
		if ( is_admin() ) {
			return;
		}

		$mensajes  = akibara_banner_get_mensajes_promo( AKIBARA_BANNER_MAX_PROMOS );
		$estaticos = akibara_banner_get_mensajes_estaticos();
		foreach ( $estaticos as $i => $m ) {
			if ( $i === 0 ) {
				continue;
			}
			$texto      = trim( $m['icono'] ?? '' ) !== '' ? $m['icono'] . ' ' . $m['texto'] : $m['texto'];
			$mensajes[] = $texto;
		}

		if ( empty( $mensajes ) ) {
			return;
		}

		$json_mensajes = wp_json_encode( array_map( 'wp_strip_all_tags', $mensajes ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		$intervalo_ms  = (int) AKIBARA_BANNER_INTERVALO * 1000;
		?>
		<script>
		(function(){
			var extras=<?php echo $json_mensajes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode con JSON_HEX_* flags es seguro en contexto script. ?>;
			var ms=<?php echo (int) $intervalo_ms; ?>;
			var inner=document.querySelector('.akibara-topbar-inner');
			if(!inner)return;
			var span=inner.querySelector('.aki-topbar-text')||inner.querySelector('span:not(.aki-topbar-icon)');
			if(!span)return;

			var orig=span.textContent.trim();
			var msgs=[{t:orig,ico:true}];
			extras.forEach(function(m){msgs.push({t:m,ico:false});});
			if(msgs.length<2)return;

			var idx=0;
			var icons=inner.querySelectorAll('.aki-topbar-icon');
			inner.style.position='relative';inner.style.height='18px';inner.style.display='flex';inner.style.alignItems='center';inner.style.justifyContent='center';inner.style.overflow='hidden';
			span.style.position='absolute';span.style.width='100%';span.style.left='0';span.style.transition='transform 0.5s cubic-bezier(0.68,-0.55,0.265,1.55),opacity 0.5s ease';
			var nextSpan=span.cloneNode(true);nextSpan.style.transform='translateY(100%)';nextSpan.style.opacity='0';inner.appendChild(nextSpan);

			setInterval(function(){
				idx=(idx+1)%msgs.length;
				nextSpan.textContent=msgs[idx].t;
				span.style.transform='translateY(-100%)';span.style.opacity='0';
				nextSpan.style.transform='translateY(0)';nextSpan.style.opacity='1';
				icons.forEach(function(el){el.style.opacity=msgs[idx].ico?'1':'0';});
				setTimeout(function(){
					var temp=span;span=nextSpan;nextSpan=temp;
					nextSpan.style.transition='none';nextSpan.style.transform='translateY(100%)';nextSpan.style.opacity='0';
					setTimeout(function(){nextSpan.style.transition='transform 0.5s cubic-bezier(0.68,-0.55,0.265,1.55),opacity 0.5s ease';},50);
				},500);
			},ms);
		})();
		</script>
		<?php
	}

	// Flash Sale Timer
	add_action( 'wp_footer', 'akibara_flash_sale_timer', 100 );

	function akibara_flash_sale_timer(): void {
		if ( is_admin() ) {
			return;
		}

		$reglas = get_option( 'akibara_descuento_reglas', array() );
		if ( ! is_array( $reglas ) || empty( $reglas ) ) {
			return;
		}

		$tz         = new DateTimeZone( 'America/Santiago' );
		$now        = new DateTime( 'now', $tz );
		$flash_sale = null;

		foreach ( $reglas as $regla ) {
			if ( empty( $regla['activo'] ) || empty( $regla['fecha_fin'] ) ) {
				continue;
			}
			$start = ! empty( $regla['fecha_inicio'] ) ? new DateTime( $regla['fecha_inicio'], $tz ) : null;
			$end   = new DateTime( $regla['fecha_fin'] . ' 23:59:59', $tz );
			if ( $start && $now < $start ) {
				continue;
			}
			if ( $now > $end ) {
				continue;
			}
			$diff = $now->diff( $end );
			$days = $diff->invert ? -1 : $diff->days;
			if ( $days > 3 ) {
				continue;
			}
			$valor = intval( $regla['valor'] ?? 0 );
			if ( $valor <= 0 ) {
				continue;
			}
			$flash_sale = array(
				'end_iso' => $end->format( 'c' ),
				'valor'   => $valor,
				'nombre'  => $regla['nombre'] ?? '',
			);
			break;
		}

		if ( ! $flash_sale ) {
			return;
		}

		$json = wp_json_encode( $flash_sale, JSON_HEX_TAG | JSON_HEX_AMP );
		?>
		<script>
		(function(){
			var sale=<?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode con JSON_HEX_* flags es seguro en contexto script. ?>;
			if(!sale||!sale.end_iso)return;
			var end=new Date(sale.end_iso).getTime();if(isNaN(end))return;
			var timer=document.createElement('div');timer.className='aki-flash-timer';
			timer.innerHTML='<span class="aki-flash-timer__icon">&#9889;</span><span class="aki-flash-timer__text"></span><span class="aki-flash-timer__countdown"></span>';
			var topbar=document.querySelector('.akibara-topbar');if(!topbar)return;
			topbar.parentNode.insertBefore(timer,topbar.nextSibling);
			var textEl=timer.querySelector('.aki-flash-timer__text');var countEl=timer.querySelector('.aki-flash-timer__countdown');
			var label=sale.nombre||sale.valor+'% OFF';textEl.textContent=label+' termina en: ';
			function pad(n){return n<10?'0'+n:String(n);}
			function update(){var now=Date.now();var diff=end-now;if(diff<=0){timer.remove();return;}var d=Math.floor(diff/86400000);var h=Math.floor((diff%86400000)/3600000);var m=Math.floor((diff%3600000)/60000);var s=Math.floor((diff%60000)/1000);var parts=[];if(d>0)parts.push(d+'d');parts.push(pad(h)+'h');parts.push(pad(m)+'m');parts.push(pad(s)+'s');countEl.textContent=parts.join(' ');}
			update();setInterval(update,1000);
		})();
		</script>
		<?php
	}

	// ── PROMOS desde módulo descuentos ─────────────────────────────────────────

	function akibara_banner_get_mensajes_promo( int $max = 2 ): array {
		static $cache = array();
		$cache_key    = 'max_' . $max;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		$reglas = get_option( 'akibara_descuento_reglas', array() );
		if ( ! is_array( $reglas ) || empty( $reglas ) ) {
			return array();
		}

		$tz     = new DateTimeZone( 'America/Santiago' );
		$now    = current_time( 'U' );
		$now_dt = new DateTime( 'now', $tz );
		$promos = array();

		foreach ( $reglas as $regla ) {
			if ( empty( $regla['activo'] ) ) {
				continue;
			}
			if ( ! empty( $regla['fecha_inicio'] ) && $now < strtotime( $regla['fecha_inicio'] ) ) {
				continue;
			}
			if ( ! empty( $regla['fecha_fin'] ) && $now > strtotime( $regla['fecha_fin'] . ' 23:59:59' ) ) {
				continue;
			}

			$dias_restantes = PHP_INT_MAX;
			if ( ! empty( $regla['fecha_fin'] ) ) {
				$end            = new DateTime( $regla['fecha_fin'], $tz );
				$diff           = $now_dt->diff( $end );
				$dias_restantes = $diff->invert ? -1 : $diff->days;
			}

			$mensaje = akibara_banner_generar_mensaje( $regla );
			if ( $mensaje ) {
				$promos[] = array(
					'mensaje'        => $mensaje,
					'dias_restantes' => $dias_restantes,
					'porcentaje'     => intval( $regla['valor'] ?? 0 ),
				);
			}
		}

		usort(
			$promos,
			function ( array $a, array $b ): int {
				if ( $a['dias_restantes'] !== $b['dias_restantes'] ) {
					return $a['dias_restantes'] - $b['dias_restantes'];
				}
				return $b['porcentaje'] - $a['porcentaje'];
			}
		);

		$result              = array_column( array_slice( $promos, 0, $max ), 'mensaje' );
		$cache[ $cache_key ] = $result;
		return $result;
	}

	function akibara_banner_generar_mensaje( array $regla ): ?string {
		$porcentaje = intval( $regla['valor'] ?? 0 );
		if ( $porcentaje <= 0 ) {
			return null;
		}

		$nombre     = $regla['nombre'] ?? '';
		$taxonomias = $regla['taxonomias'] ?? array();
		$fecha_fin  = $regla['fecha_fin'] ?? '';

		$tipo_promo = akibara_banner_tipo_promo( $taxonomias );
		$mensaje    = akibara_banner_construir_msg( $porcentaje, $tipo_promo, $nombre );

		if ( $fecha_fin !== '' ) {
			$mensaje = akibara_banner_urgencia( $mensaje, $fecha_fin );
		}

		return $mensaje;
	}

	function akibara_banner_tipo_promo( array $taxonomias ): array {
		if ( empty( $taxonomias ) ) {
			return array( 'tipo' => 'global', 'nombre' => 'toda la tienda' );
		}
		$inclusiones = array_filter( $taxonomias, fn( array $t ) => ( $t['tipo'] ?? 'incluir' ) === 'incluir' );
		if ( empty( $inclusiones ) ) {
			return array( 'tipo' => 'global', 'nombre' => 'toda la tienda' );
		}
		$primera  = reset( $inclusiones );
		$taxonomy = $primera['taxonomy'] ?? '';
		$term_id  = $primera['term_id'] ?? 0;
		$term     = get_term( $term_id, $taxonomy );
		$name     = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
		if ( $name === '' ) {
			return array( 'tipo' => 'global', 'nombre' => 'productos seleccionados' );
		}
		return match ( $taxonomy ) {
			'product_cat'   => array( 'tipo' => 'categoria', 'nombre' => $name ),
			'product_tag'   => array( 'tipo' => 'etiqueta', 'nombre' => $name ),
			'product_brand' => array( 'tipo' => 'marca', 'nombre' => $name ),
			default         => str_starts_with( $taxonomy, 'pa_' )
				? array( 'tipo' => 'atributo', 'nombre' => $name )
				: array( 'tipo' => 'seleccion', 'nombre' => $name ),
		};
	}

	function akibara_banner_construir_msg( int $porcentaje, array $tipo_promo, string $nombre_regla ): string {
		$tipo   = $tipo_promo['tipo'];
		$target = $tipo_promo['nombre'];

		if ( $nombre_regla !== '' && mb_strlen( $nombre_regla ) > 3 ) {
			$lower = mb_strtolower( $nombre_regla );
			foreach ( array( 'promo', 'descuento', 'oferta', 'especial' ) as $kw ) {
				if ( str_contains( $lower, $kw ) ) {
					return "{$nombre_regla} - {$porcentaje}% OFF";
				}
			}
		}

		return match ( $tipo ) {
			'global'   => "{$porcentaje}% de descuento en toda la tienda",
			'etiqueta' => "{$porcentaje}% en productos {$target}",
			default    => "{$porcentaje}% en {$target}",
		};
	}

	function akibara_banner_urgencia( string $mensaje, string $fecha_fin ): string {
		$tz   = new DateTimeZone( 'America/Santiago' );
		$now  = new DateTime( 'now', $tz );
		$end  = new DateTime( $fecha_fin, $tz );
		$diff = $now->diff( $end );
		$dias = $diff->invert ? -1 : $diff->days;
		if ( $dias < 0 ) {
			return $mensaje;
		}
		$dias_full  = array( 'domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado' );
		$dias_short = array( 'dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb' );
		$w          = (int) $end->format( 'w' );
		return match ( true ) {
			$dias === 0 => $mensaje . ' - ÚLTIMO DÍA!',
			$dias === 1 => $mensaje . ' - Hasta mañana!',
			$dias === 2 => $mensaje . " - Solo hasta el {$dias_full[$w]}!",
			$dias === 3 => $mensaje . ' - Últimos 3 días!',
			default     => $mensaje . " hasta el {$dias_short[$w]} " . $end->format( 'j' ),
		};
	}

} // end group wrap
