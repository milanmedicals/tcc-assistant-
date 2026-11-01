<?php
/**
 * Plugin Name:       TCC Shop Assistant
 * Description:       A free, self-hosted shopping, order-tracking and support assistant for WooCommerce, built to sit inside the TheChemistClub and DrugsGeneral themes. No monthly fees, no message limits, no third party required. An open-source or self-hosted AI model is optional.
 * Version:           1.7.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tcc-assistant
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'TCCA_VERSION', '1.7.0' );
define( 'TCCA_FILE', __FILE__ );
define( 'TCCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCCA_URL', plugin_dir_url( __FILE__ ) );

require_once TCCA_DIR . 'includes/class-core.php';
require_once TCCA_DIR . 'includes/class-kb.php';
require_once TCCA_DIR . 'includes/class-llm.php';
require_once TCCA_DIR . 'includes/class-product.php';
require_once TCCA_DIR . 'includes/class-index.php';
require_once TCCA_DIR . 'includes/class-lexicon.php';
require_once TCCA_DIR . 'includes/class-track.php';
require_once TCCA_DIR . 'includes/class-insights.php';
require_once TCCA_DIR . 'includes/class-sales.php';
require_once TCCA_DIR . 'includes/class-coupons.php';
require_once TCCA_DIR . 'includes/class-deal.php';
require_once TCCA_DIR . 'includes/class-account.php';
require_once TCCA_DIR . 'includes/class-concierge.php';
require_once TCCA_DIR . 'includes/class-finder.php';
require_once TCCA_DIR . 'includes/class-engine.php';
require_once TCCA_DIR . 'includes/class-rest.php';
require_once TCCA_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'TCCA_Core', 'install' ) );
register_deactivation_hook( __FILE__, array( 'TCCA_Core', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'tcc-assistant', false, dirname( plugin_basename( TCCA_FILE ) ) . '/languages' );
		TCCA_Core::boot();
		TCCA_Index::boot();
		TCCA_Events::boot();
		TCCA_Leads::boot();
		TCCA_REST::boot();
		if ( is_admin() ) {
			TCCA_Admin::boot();
		}
	}
);
