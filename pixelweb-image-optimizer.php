<?php
/**
 * Plugin Name:       Pixelweb Image Optimizer
 * Plugin URI:        https://pixelweb.ma/landing-pixelweb-image-optimizer/
 * Description:       Convert images to WebP on your own server, serve them with cache-safe &lt;picture&gt; tags, protect your LCP with smart lazy loading and fix image SEO issues. No external API, no quotas.
 * Version:           1.1.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pixelweb
 * Author URI:        https://pixelweb.ma
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pixelweb-image-optimizer
 * Domain Path:       /languages
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIXELWEB_VERSION', '1.1.2' );
define( 'PIXELWEB_DB_VERSION', '4' );
define( 'PIXELWEB_PLUGIN_FILE', __FILE__ );
define( 'PIXELWEB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIXELWEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PIXELWEB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PIXELWEB_PLUGIN_NAME', 'Pixelweb Image Optimizer' );

require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-settings.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-stats.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-converter.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-delivery.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-lazyload.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-seo.php';
require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-ajax.php';

/**
 * Main plugin class.
 */
final class Pixelweb_Image_Optimizer {

	/**
	 * Singleton instance.
	 *
	 * @var Pixelweb_Image_Optimizer|null
	 */
	private static $instance = null;

	/**
	 * Converter instance, shared by every component.
	 *
	 * @var Pixelweb_Converter
	 */
	public $converter;

	/**
	 * Get the singleton.
	 *
	 * @return Pixelweb_Image_Optimizer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		register_activation_hook( PIXELWEB_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ), 5 );
	}

	/**
	 * Start every component once all plugins are loaded (so add-ons can hook in).
	 */
	public function boot() {
		self::maybe_upgrade();

		$this->converter = new Pixelweb_Converter();
		$this->converter->init();

		( new Pixelweb_Delivery( $this->converter ) )->init();
		( new Pixelweb_LazyLoad() )->init();
		( new Pixelweb_SEO() )->init();
		( new Pixelweb_Ajax( $this->converter ) )->init();

		if ( is_admin() ) {
			require_once PIXELWEB_PLUGIN_DIR . 'admin/class-pixelweb-admin.php';
			( new Pixelweb_Admin( $this->converter ) )->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once PIXELWEB_PLUGIN_DIR . 'includes/class-pixelweb-cli.php';
			WP_CLI::add_command( 'pixelweb', new Pixelweb_CLI( $this->converter ) );
		}

		/**
		 * Fires once the plugin is fully loaded. Add-ons should hook here.
		 *
		 * @param Pixelweb_Image_Optimizer $plugin Main plugin instance.
		 */
		do_action( 'pixelweb_loaded', $this );
	}

	/**
	 * Activation: default options + stats table.
	 */
	public static function activate() {
		Pixelweb_Settings::add_defaults();
		Pixelweb_Stats::create_table();
		update_option( 'pixelweb_db_version', PIXELWEB_DB_VERSION, false );
	}

	/**
	 * Run upgrade routines when the stored DB version is older than the code.
	 */
	public static function maybe_upgrade() {
		$stored = get_option( 'pixelweb_db_version' );
		if ( PIXELWEB_DB_VERSION === $stored ) {
			return;
		}
		self::activate();

		// 1.1.2 stores WebP files in uploads/pixelweb-image-optimizer/ instead of next to the
		// originals: queue every processed image again so the next bulk run moves them.
		if ( ( false === $stored || (int) $stored < 4 ) && Pixelweb_Converter::processed_ids( 1 ) ) {
			Pixelweb_Converter::reset_statuses();
			update_option( 'pixelweb_rebuild_notice', 1, false );
		}
		// 1.0.0 stored options that no longer exist.
		foreach ( array( 'pixelweb_delete_originals', 'pixelweb_lazy_threshold', 'pixelweb_bulk_monthly_count', 'pixelweb_bulk_month_reset', 'pixelweb_license_key', 'pixelweb_license_status', 'pixelweb_license_expires', 'pixelweb_total_saved', 'pixelweb_images_optimized' ) as $legacy ) {
			delete_option( $legacy );
		}
	}

	/**
	 * Whether a Pro add-on announced itself (used only to hide upsell links).
	 *
	 * @return bool
	 */
	public static function has_pro() {
		return (bool) apply_filters( 'pixelweb_has_pro', false );
	}
}

/**
 * Accessor for the main plugin instance.
 *
 * @return Pixelweb_Image_Optimizer
 */
function pixelweb() {
	return Pixelweb_Image_Optimizer::instance();
}

pixelweb();
