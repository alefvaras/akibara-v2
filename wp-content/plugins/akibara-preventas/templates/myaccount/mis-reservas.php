<?php
/**
 * Template: Mis Reservas en Mi Cuenta — Akibara.
 *
 * Variables: $reservas (array)
 */

defined( 'ABSPATH' ) || exit;

$wa_url = function_exists( 'akb_reserva_whatsapp_url' )
	? akb_reserva_whatsapp_url( 'Hola, tengo una consulta sobre mis reservas.' )
	: home_url( '/contacto/' );
?>

<div class="aki-panel aki-reservas-panel">

	<div class="aki-panel__header">
		<h2 class="aki-panel__title aki-manga-title"><?php esc_html_e( 'Mis Reservas', 'akibara' ); ?></h2>
	</div>

	<?php if ( empty( $reservas ) ) : ?>

		<div class="aki-reservas-empty">
			<svg width="48" height="48" viewBox="0 0 48 48" fill="none" aria-hidden="true">
				<rect x="8" y="4" width="32" height="40" rx="3" stroke="currentColor" stroke-width="2" opacity=".3"/>
				<path d="M8 12h32M8 20h32M8 28h20" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity=".3"/>
				<path d="M30 32l4 4 6-6" stroke="var(--aki-red)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<p class="aki-reservas-empty__title"><?php esc_html_e( 'No tienes reservas activas', 'akibara' ); ?></p>
			<p class="aki-reservas-empty__sub">
				<?php esc_html_e( 'Cuando hagas una preventa aparecerá acá con su estado y fecha estimada.', 'akibara' ); ?>
			</p>
			<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button aki-btn aki-btn--primary">
				<?php esc_html_e( 'Ver preventas disponibles', 'akibara' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div class="aki-panel__body">
			<div class="aki-reservas-table-wrap">
				<table class="akb-reservas-table aki-table" role="table">
					<caption class="screen-reader-text"><?php esc_html_e( 'Listado de tus reservas y preventas', 'akibara' ); ?></caption>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Pedido', 'akibara' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Producto', 'akibara' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tipo', 'akibara' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Estado', 'akibara' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fecha est.', 'akibara' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $reservas as $reserva ) : ?>
							<?php foreach ( $reserva['items'] as $item ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Pedido', 'akibara' ); ?>">
										<a href="<?php echo esc_url( $reserva['order_url'] ); ?>" class="aki-reservas-order-link">
											#<?php echo esc_html( $reserva['order_number'] ); ?>
										</a>
										<br>
										<small class="aki-muted"><?php echo esc_html( $reserva['order_date'] ); ?></small>
									</td>
									<td data-label="<?php esc_attr_e( 'Producto', 'akibara' ); ?>">
										<?php echo esc_html( $item['name'] ); ?>
										<?php if ( (int) $item['qty'] > 1 ) : ?>
											<small class="aki-muted"> &times;<?php echo esc_html( $item['qty'] ); ?></small>
										<?php endif; ?>
									</td>
									<td data-label="<?php esc_attr_e( 'Tipo', 'akibara' ); ?>">
										<span class="aki-badge aki-badge--gold">PREVENTA</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Estado', 'akibara' ); ?>">
										<span class="aki-estado aki-estado--<?php echo esc_attr( $item['estado'] ); ?>">
											<?php echo esc_html( akb_reserva_estado_label( $item['estado'] ) ); ?>
										</span>
									</td>
									<td data-label="<?php esc_attr_e( 'Fecha est.', 'akibara' ); ?>">
										<?php
										if ( ! empty( $item['fecha'] ) && (int) $item['fecha'] > 0 ) {
											echo esc_html( akb_reserva_fecha( $item['fecha'] ) );
										} else {
											echo '<span class="aki-muted">' . esc_html__( 'Por confirmar', 'akibara' ) . '</span>';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $wa_url ) : ?>
				<p class="aki-reservas-wa">
					<a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" class="aki-wa-link">
						<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="7" stroke="#25D366" stroke-width="1.5"/><path d="M5 8.5c.5 1 1.5 2 3 2.5l1-1.5c-1-.3-1.5-.8-2-1.5L5 8.5zM5 7c0-1.7 1.3-3 3-3s3 1.3 3 3" stroke="#25D366" stroke-width="1.2" stroke-linecap="round"/></svg>
						<?php esc_html_e( 'Consultar por WhatsApp', 'akibara' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

	<?php endif; ?>

</div>
