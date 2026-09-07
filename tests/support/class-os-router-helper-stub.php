<?php
/**
 * Minimal OsRouterHelper stand-in for unit tests, which never boot LatePoint.
 *
 * @package ifthenpay-payments-for-latepoint
 */

if ( ! class_exists( 'OsRouterHelper' ) ) {
	/**
	 * An OsRouterHelper stand-in. The real route/link shape is LatePoint's own concern; a fixed,
	 * recognisable placeholder is enough to prove a renderer builds and outputs a link at all.
	 */
	class OsRouterHelper {

		/**
		 * A recognisable, test-inspectable stand-in for the real route name.
		 *
		 * @param string $controller Controller slug.
		 * @param string $action     Action name.
		 */
		public static function build_route_name( string $controller, string $action ): string {
			return $controller . '__' . $action;
		}

		/**
		 * A recognisable, test-inspectable stand-in for the real admin link.
		 *
		 * @param string $route_name As returned by build_route_name().
		 */
		public static function build_link( string $route_name ): string {
			return '/wp-admin/admin.php?route=' . $route_name;
		}
	}
}
