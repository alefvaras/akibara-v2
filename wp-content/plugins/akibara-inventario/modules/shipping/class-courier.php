<?php
/**
 * Akibara Courier Adapter Interface
 *
 * Contrato que debe implementar cada courier integrado.
 * Permite al orquestador (module.php) despachar tracking,
 * order actions y admin UI de forma uniforme.
 *
 * @package Akibara
 * @since   10.5.0
 */

defined( 'ABSPATH' ) || exit;

interface AKB_Courier_Adapter {

	/**
	 * Identificador único del courier (usado como method_id en WC).
	 */
	public function get_id(): string;

	/**
	 * Nombre visible del courier.
	 */
	public function get_label(): string;

	/**
	 * Icono emoji para el tracking view.
	 */
	public function get_icon(): string;

	/**
	 * Method IDs de WooCommerce que pertenecen a este courier.
	 * Ej: ['bluex-ex', 'bluex-py', 'bluex-md']
	 *
	 * @return string[]
	 */
	public function get_method_ids(): array;

	/**
	 * Obtener info de tracking para un pedido.
	 *
	 * @return array|null {
	 *   courier: string,
	 *   courier_label: string,
	 *   code: string,
	 *   url: string,
	 *   data: array|null,
	 * }
	 */
	public function get_tracking_info( WC_Order $order ): ?array;

	/**
	 * Mapear estado del courier a display para el frontend.
	 *
	 * @return array{ icon: string, label: string, css: string }
	 */
	public function get_status_display( ?string $courier_status, string $wc_status ): array;

	/**
	 * Test de conexión API (si aplica). Retorna true si OK.
	 */
	public function test_connection(): bool;

	/**
	 * ¿Tiene API configurable desde admin? (API key, etc.)
	 */
	public function has_admin_settings(): bool;

	/**
	 * Renderizar sección de settings en admin tab.
	 */
	public function render_admin_settings(): void;

	/**
	 * Guardar settings desde admin POST.
	 */
	public function save_admin_settings(): void;

	/**
	 * ¿Tiene order actions? (crear/cancelar envío)
	 */
	public function has_order_actions(): bool;

	/**
	 * Obtener order actions disponibles para un pedido.
	 *
	 * @return array<string, string> action_slug => label
	 */
	public function get_order_actions( WC_Order $order ): array;

	/**
	 * Ejecutar un order action.
	 */
	public function execute_order_action( string $action_slug, WC_Order $order ): void;

	/**
	 * ¿Tiene webhook entrante?
	 */
	public function has_webhook(): bool;

	/**
	 * Webhook endpoint path (relativo a akibara/v1/).
	 * Ej: 'bluex/webhook'
	 */
	public function get_webhook_path(): string;

	/**
	 * Handler del webhook.
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response;

	/**
	 * Stats para el admin tab (últimos 30 días).
	 *
	 * @return array{ count: int, label: string }
	 */
	public function get_30d_stats(): array;
}

/**
 * Metadata opcional para la UI (checkout grid, PDP widget, etc).
 * Los couriers que la implementen aparecen automáticamente con estilos
 * correctos sin tocar JS/CSS.
 *
 * @since 10.6.0
 */
interface AKB_Courier_UI_Metadata {
	/** Color brand hex (#RRGGBB) usado en icon bubble + border cuando seleccionado */
	public function get_color(): string;

	/** SVG icon inline (viewBox 24x24). Reemplaza el emoji en grid/PDP */
	public function get_icon_svg(): string;

	/** Subtítulo breve debajo del nombre ("Despacho mismo día") */
	public function get_tagline(): string;

	/** Badge destacado ("SAME DAY", "GRATIS", null) */
	public function get_badge(): ?string;

	/** Pill discreto a la derecha del precio ("SOLO RM", null) */
	public function get_pill(): ?string;

	/** Orden de aparición (menor = arriba). BlueX=1, Metro=2 */
	public function get_priority(): int;

	/**
	 * Estimación humana ("Llega hoy", "Llega el martes") para un package dado.
	 * Return null para dejar que el JS calcule un fallback genérico.
	 */
	public function get_delivery_estimate_label( array $package = array() ): ?string;

	/**
	 * Hora de corte (0-23) si aplica. Null = no tiene corte.
	 */
	public function get_cutoff_hour(): ?int;

	/**
	 * Descripción de cobertura en texto plano (para strip abajo del grid).
	 * Null = no mostrar strip.
	 */
	public function get_coverage_text(): ?string;
}

/**
 * Trait con defaults sensatos para couriers que NO tienen metadata UI.
 * Útil para wrappers de plugins externos (ej. BlueX delega todo al plugin).
 */
trait AKB_Courier_UI_Defaults {
	public function get_color(): string {
		return '#666'; }
	public function get_icon_svg(): string {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="m3 7 3-4h12l3 4"/></svg>';
	}
	public function get_tagline(): string {
		return ''; }
	public function get_badge(): ?string {
		return null; }
	public function get_pill(): ?string {
		return null; }
	public function get_priority(): int {
		return 9; }
	public function get_delivery_estimate_label( array $package = array() ): ?string {
		return null; }
	public function get_cutoff_hour(): ?int {
		return null; }
	public function get_coverage_text(): ?string {
		return null; }
}
