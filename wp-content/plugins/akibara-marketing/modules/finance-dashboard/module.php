<?php
/**
 * Akibara Marketing — Finance Dashboard manga-specific.
 *
 * REBUILD (CLEAN-016): Legacy 1,453 LOC finance-dashboard was NOT migrated.
 * This is a fresh implementation with 5 manga-specific widgets.
 *
 * Pre-condition: Cell H mockup approval required for final UI.
 * Current state: backend data fetch implemented, UI is a stub placeholder.
 * See: audit/sprint-3/cell-b/STUBS.md
 *
 * @package    Akibara\Marketing
 * @subpackage FinanceDashboard
 * @version    1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'AKB_MARKETING_FINANCE_LOADED' ) ) {
	return;
}
define( 'AKB_MARKETING_FINANCE_LOADED', '1.0.0' );

if ( ! function_exists( 'akb_marketing_finance_sentinel' ) ) {

	function akb_marketing_finance_sentinel(): bool {
		return defined( 'AKB_MARKETING_FINANCE_LOADED' );
	}

	add_action(
		'admin_menu',
		static function (): void {
			// Instantiate + register only when admin_menu fires.
			// DashboardController adds the tab via akibara_admin_tabs filter.
			$controller = new \Akibara\Marketing\Finance\DashboardController();
			$controller->register();

			// Sprint 5.5 admin reorg: surface como submenu page bajo Akibara.
			// El sistema akibara_admin_tabs nunca se conectó a render real, por
			// eso el dashboard quedaba "huérfano" en el código sin acceso UI.
			// Registramos como page directa para que aparezca en el sidebar.
			add_submenu_page(
				'akibara',
				'Finanzas Manga',
				'💹 Finanzas Manga',
				'manage_woocommerce',
				'akibara-finance-manga',
				static function () use ( $controller ): void {
					$controller->render();
				}
			);
		},
		20
	);

} // end group wrap
