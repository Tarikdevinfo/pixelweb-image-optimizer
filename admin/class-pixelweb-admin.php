<?php
/**
 * Admin screens.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, pages, media library integration.
 */
class Pixelweb_Admin {

	const SLUG    = 'pixelweb-optimizer';
	const PRO_URL = 'https://pixelweb.ma/image-optimizer#pro';

	/**
	 * Converter.
	 *
	 * @var Pixelweb_Converter
	 */
	private $converter;

	/**
	 * Constructor.
	 *
	 * The argument is optional because version 1.0.0 created this class without it: during an
	 * update made outside the WordPress admin (FTP, WP-CLI), PHP's opcode cache can still run
	 * the old main file for a few seconds while this new file is already in place.
	 *
	 * @param Pixelweb_Converter|null $converter Converter.
	 */
	public function __construct( $converter = null ) {
		$this->converter = $converter instanceof Pixelweb_Converter ? $converter : new Pixelweb_Converter();
	}

	/**
	 * Hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_notices', array( $this, 'rebuild_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . PIXELWEB_PLUGIN_BASENAME, array( $this, 'plugin_links' ) );
		add_filter( 'manage_media_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_field' ), 10, 2 );
		add_action( 'wp_ajax_pixelweb_dismiss_upsell', array( $this, 'dismiss_upsell' ) );
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pixelweb-settings' ) ) . '">' . esc_html__( 'Settings', 'pixelweb-image-optimizer' ) . '</a>' );
		if ( ! Pixelweb_Image_Optimizer::has_pro() ) {
			$links[] = '<a href="' . esc_url( self::PRO_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get Pro', 'pixelweb-image-optimizer' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Menu.
	 */
	public function menu() {
		add_menu_page( PIXELWEB_PLUGIN_NAME, __( 'Pixelweb', 'pixelweb-image-optimizer' ), 'manage_options', self::SLUG, array( $this, 'page_dashboard' ), self::menu_icon(), 81 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'pixelweb-image-optimizer' ), __( 'Dashboard', 'pixelweb-image-optimizer' ), 'manage_options', self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Bulk Optimize', 'pixelweb-image-optimizer' ), __( 'Bulk Optimize', 'pixelweb-image-optimizer' ), 'manage_options', 'pixelweb-bulk', array( $this, 'page_bulk' ) );
		add_submenu_page( self::SLUG, __( 'Image SEO', 'pixelweb-image-optimizer' ), __( 'Image SEO', 'pixelweb-image-optimizer' ), 'upload_files', 'pixelweb-seo', array( $this, 'page_seo' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'pixelweb-image-optimizer' ), __( 'Settings', 'pixelweb-image-optimizer' ), 'manage_options', 'pixelweb-settings', array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Tools', 'pixelweb-image-optimizer' ), __( 'Tools', 'pixelweb-image-optimizer' ), 'manage_options', 'pixelweb-tools', array( $this, 'page_tools' ) );

		/**
		 * Add-ons register their submenu pages here.
		 *
		 * @param string $parent Parent menu slug.
		 */
		do_action( 'pixelweb_admin_menu', self::SLUG );
	}

	/**
	 * Monochrome menu icon (WordPress recolors it to match the admin theme).
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M5 2h10a3 3 0 0 1 3 3v10a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V5a3 3 0 0 1 3-3zM13.5 5.2a1.6 1.6 0 1 0 0 3.2a1.6 1.6 0 1 0 0-3.2zM4.3 15.2h11.4l-3.4-4.8-1.7 2.1-2.5-3.4z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- menu icon.
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		$ours = false !== strpos( $hook, 'pixelweb' );
		if ( ! $ours && ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pixelweb-admin', PIXELWEB_PLUGIN_URL . 'admin/css/admin.css', array(), PIXELWEB_VERSION );
		wp_enqueue_script( 'pixelweb-admin', PIXELWEB_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), PIXELWEB_VERSION, true );
		wp_localize_script(
			'pixelweb-admin',
			'pixelwebData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'pixelweb_nonce' ),
				'i18n'    => array(
					'working'       => __( 'Working…', 'pixelweb-image-optimizer' ),
					'optimize'      => __( 'Optimize now', 'pixelweb-image-optimizer' ),
					'retry'         => __( 'Retry', 'pixelweb-image-optimizer' ),
					'error'         => __( 'Something went wrong. Please try again.', 'pixelweb-image-optimizer' ),
					'saved'         => __( 'Settings saved.', 'pixelweb-image-optimizer' ),
					'done'          => __( 'All images are optimized.', 'pixelweb-image-optimizer' ),
					'stopped'       => __( 'Paused. Click start to resume where you left off.', 'pixelweb-image-optimizer' ),
					'start'         => __( 'Start optimization', 'pixelweb-image-optimizer' ),
					'resume'        => __( 'Resume', 'pixelweb-image-optimizer' ),
					'pending'       => __( 'pending', 'pixelweb-image-optimizer' ),
					'optimized'     => __( 'optimized', 'pixelweb-image-optimizer' ),
					'skipped'       => __( 'already optimal', 'pixelweb-image-optimizer' ),
					'failed'        => __( 'error', 'pixelweb-image-optimizer' ),
					'save'          => __( 'Save', 'pixelweb-image-optimizer' ),
					'allGood'       => __( 'No issue', 'pixelweb-image-optimizer' ),
					'noResults'     => __( 'No image found.', 'pixelweb-image-optimizer' ),
					'prev'          => __( '← Previous', 'pixelweb-image-optimizer' ),
					'next'          => __( 'Next →', 'pixelweb-image-optimizer' ),
					/* translators: 1: current page number, 2: total number of pages */
					'pageOf'        => __( 'Page %1$d of %2$d', 'pixelweb-image-optimizer' ),
					'altPlaceholder' => __( 'Describe the image…', 'pixelweb-image-optimizer' ),
					'confirmReset'  => __( 'Queue every image again? Existing WebP files are reused.', 'pixelweb-image-optimizer' ),
					'confirmDelete' => __( 'Delete every file generated by the plugin? Your original images are never touched.', 'pixelweb-image-optimizer' ),
					'deleted'       => __( 'files deleted', 'pixelweb-image-optimizer' ),
					'altCoverage'   => __( 'Alt text coverage', 'pixelweb-image-optimizer' ),
					'missingAlt'    => __( 'Images without alt text', 'pixelweb-image-optimizer' ),
					'allImages'     => __( 'Images in the library', 'pixelweb-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Common header.
	 *
	 * @param string $title Page title.
	 */
	private function header( $title ) {
		$caps = Pixelweb_Converter::capabilities();
		?>
		<div class="pixelweb-header">
			<h1><img class="pixelweb-logo" src="<?php echo esc_url( PIXELWEB_PLUGIN_URL . 'admin/images/icon-128.png' ); ?>" width="36" height="36" alt=""> <?php echo esc_html( $title ); ?></h1>
			<span class="pixelweb-version">v<?php echo esc_html( PIXELWEB_VERSION ); ?></span>
		</div>
		<?php if ( ! $caps['webp'] ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Your server cannot create WebP images. Ask your host to enable GD or Imagick with WebP support (PHP 7.4+ usually includes it).', 'pixelweb-image-optimizer' ); ?></p></div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Dashboard.
	 */
	public function page_dashboard() {
		wp_cache_delete( 'dashboard', 'pixelweb' );
		$stats  = Pixelweb_Stats::dashboard();
		$caps   = Pixelweb_Converter::capabilities();
		$recent = Pixelweb_Stats::recent( 8 );
		?>
		<div class="wrap pixelweb-wrap">
			<?php $this->header( PIXELWEB_PLUGIN_NAME ); ?>

			<div class="pixelweb-stats-grid">
				<div class="pixelweb-card"><div class="pixelweb-card-value"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></div><div class="pixelweb-card-label"><?php esc_html_e( 'Images optimized', 'pixelweb-image-optimizer' ); ?></div></div>
				<div class="pixelweb-card"><div class="pixelweb-card-value"><?php echo esc_html( Pixelweb_Stats::format_bytes( $stats['total_saved'] ) ); ?></div><div class="pixelweb-card-label"><?php esc_html_e( 'Weight saved', 'pixelweb-image-optimizer' ); ?></div></div>
				<div class="pixelweb-card"><div class="pixelweb-card-value"><?php echo esc_html( number_format_i18n( $stats['avg_savings'], 1 ) ); ?>%</div><div class="pixelweb-card-label"><?php esc_html_e( 'Average reduction', 'pixelweb-image-optimizer' ); ?></div></div>
				<div class="pixelweb-card"><div class="pixelweb-card-value"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></div><div class="pixelweb-card-label"><?php esc_html_e( 'Waiting', 'pixelweb-image-optimizer' ); ?></div></div>
			</div>

			<div class="pixelweb-card pixelweb-card-wide">
				<h2><?php esc_html_e( 'Library progress', 'pixelweb-image-optimizer' ); ?></h2>
				<div class="pixelweb-progress-bar"><div class="pixelweb-progress-fill" style="width:<?php echo esc_attr( $stats['percent_done'] ); ?>%"><?php echo esc_html( $stats['percent_done'] ); ?>%</div></div>
				<p class="pixelweb-muted">
					<?php
					printf(
						/* translators: 1: output format (WebP), 2: GD yes/no, 3: Imagick yes/no */
						esc_html__( 'Output: %1$s · GD: %2$s · Imagick: %3$s', 'pixelweb-image-optimizer' ),
						$caps['webp'] ? 'WEBP' : '—',
						$caps['gd'] ? '✓' : '✗',
						$caps['imagick'] ? '✓' : '✗'
					);
					?>
				</p>
				<?php if ( $stats['pending'] > 0 ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=pixelweb-bulk' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Optimize existing images', 'pixelweb-image-optimizer' ); ?></a></p>
				<?php endif; ?>
			</div>

			<?php
			/**
			 * Add-ons add dashboard cards here.
			 */
			do_action( 'pixelweb_dashboard_after_stats' );

			$this->upsell_card();
			?>

			<?php if ( $recent ) : ?>
			<div class="pixelweb-card pixelweb-card-wide">
				<h2><?php esc_html_e( 'Recent optimizations', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Image', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Before', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'After', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Saved', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Format', 'pixelweb-image-optimizer' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->post_title ? $row->post_title : '#' . $row->attachment_id ); ?></td>
							<td><?php echo esc_html( Pixelweb_Stats::format_bytes( $row->original_size ) ); ?></td>
							<td><?php echo esc_html( Pixelweb_Stats::format_bytes( $row->optimized_size ) ); ?></td>
							<td><strong>−<?php echo esc_html( number_format_i18n( (float) $row->savings_percent, 1 ) ); ?>%</strong></td>
							<td><?php echo esc_html( strtoupper( $row->format ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One dismissible Pro card on the dashboard (no site-wide notices).
	 */
	private function upsell_card() {
		if ( Pixelweb_Image_Optimizer::has_pro() || get_user_meta( get_current_user_id(), 'pixelweb_upsell_dismissed', true ) ) {
			return;
		}
		?>
		<div class="pixelweb-card pixelweb-card-wide pixelweb-upsell" id="pixelweb-upsell">
			<button type="button" class="pixelweb-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'pixelweb-image-optimizer' ); ?>">×</button>
			<h2><?php esc_html_e( 'Need more? Pixelweb Image Optimizer Pro', 'pixelweb-image-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'AVIF (about 20–30% lighter than WebP), quality per image type, blur-up placeholders, automatic alt text and white-label client reports. Sold separately as an add-on.', 'pixelweb-image-optimizer' ); ?></p>
			<a class="button" href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See Pro features', 'pixelweb-image-optimizer' ); ?></a>
		</div>
		<?php
	}

	/**
	 * After the 1.1.2 update: ask to run Bulk Optimize once, so WebP files move to their new folder.
	 */
	public function rebuild_notice() {
		if ( ! get_option( 'pixelweb_rebuild_notice' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( 0 === Pixelweb_Converter::count_images( 'pending' ) ) {
			delete_option( 'pixelweb_rebuild_notice' );
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Pixelweb Image Optimizer now keeps WebP files in their own folder (uploads/pixelweb-image-optimizer). Run Bulk Optimize once to move your existing images; until then they are served in their original format.', 'pixelweb-image-optimizer' ),
			esc_url( admin_url( 'admin.php?page=pixelweb-bulk' ) ),
			esc_html__( 'Open Bulk Optimize', 'pixelweb-image-optimizer' )
		);
	}

	/**
	 * AJAX: hide the Pro card for this user.
	 */
	public function dismiss_upsell() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		update_user_meta( get_current_user_id(), 'pixelweb_upsell_dismissed', 1 );
		wp_send_json_success();
	}

	/**
	 * Bulk page.
	 */
	public function page_bulk() {
		wp_cache_delete( 'dashboard', 'pixelweb' );
		$stats = Pixelweb_Stats::dashboard();
		?>
		<div class="wrap pixelweb-wrap">
			<?php $this->header( __( 'Bulk Optimize', 'pixelweb-image-optimizer' ) ); ?>
			<div class="pixelweb-card pixelweb-card-wide">
				<p>
					<?php
					printf(
						/* translators: 1: pending images, 2: total images */
						esc_html__( '%1$s of %2$s images are waiting. Originals are never modified; WebP copies are created next to them.', 'pixelweb-image-optimizer' ),
						'<strong id="pixelweb-pending">' . esc_html( number_format_i18n( $stats['pending'] ) ) . '</strong>',
						esc_html( number_format_i18n( $stats['total_images'] ) )
					);
					?>
				</p>
				<div class="pixelweb-bulk-controls">
					<label><?php esc_html_e( 'Images per request', 'pixelweb-image-optimizer' ); ?>
						<select id="pixelweb-batch-size"><option value="1">1</option><option value="3" selected>3</option><option value="5">5</option><option value="10">10</option></select>
					</label>
					<button type="button" id="pixelweb-start-bulk" class="button button-primary button-hero" <?php disabled( 0 === $stats['pending'] ); ?>><?php esc_html_e( 'Start optimization', 'pixelweb-image-optimizer' ); ?></button>
					<button type="button" id="pixelweb-stop-bulk" class="button button-hero" hidden><?php esc_html_e( 'Pause', 'pixelweb-image-optimizer' ); ?></button>
				</div>
				<div class="pixelweb-progress-bar"><div class="pixelweb-progress-fill" id="pixelweb-bulk-fill" style="width:<?php echo esc_attr( $stats['percent_done'] ); ?>%"><?php echo esc_html( $stats['percent_done'] ); ?>%</div></div>
				<p id="pixelweb-bulk-status" class="pixelweb-muted" aria-live="polite"></p>
				<div id="pixelweb-bulk-log" class="pixelweb-bulk-log" hidden></div>
				<p class="pixelweb-muted"><?php esc_html_e( 'Tip: on big libraries, WP-CLI is faster — run "wp pixelweb optimize".', 'pixelweb-image-optimizer' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * SEO page.
	 */
	public function page_seo() {
		?>
		<div class="wrap pixelweb-wrap">
			<?php $this->header( __( 'Image SEO', 'pixelweb-image-optimizer' ) ); ?>
			<div class="pixelweb-stats-grid" id="pixelweb-seo-summary"></div>
			<div class="pixelweb-card pixelweb-card-wide">
				<div class="pixelweb-toolbar">
					<label><?php esc_html_e( 'Show', 'pixelweb-image-optimizer' ); ?>
						<select id="pixelweb-seo-filter">
							<option value="all"><?php esc_html_e( 'All images', 'pixelweb-image-optimizer' ); ?></option>
							<option value="missing_alt" selected><?php esc_html_e( 'Missing alt text', 'pixelweb-image-optimizer' ); ?></option>
							<option value="poor"><?php esc_html_e( 'Score below 70', 'pixelweb-image-optimizer' ); ?></option>
						</select>
					</label>
					<button type="button" id="pixelweb-run-seo" class="button button-primary"><?php esc_html_e( 'Analyze', 'pixelweb-image-optimizer' ); ?></button>
					<?php
					/**
					 * Add-ons add buttons (e.g. automatic alt text) here.
					 */
					do_action( 'pixelweb_seo_toolbar' );
					?>
				</div>
				<p class="pixelweb-muted"><?php esc_html_e( 'Type an alt text and press Enter to save it. Good alt text describes the image in a short sentence and uses your keyword only when it fits.', 'pixelweb-image-optimizer' ); ?></p>
				<table class="widefat striped pixelweb-seo-table" id="pixelweb-seo-table" hidden>
					<thead><tr><th class="pixelweb-col-thumb"></th><th><?php esc_html_e( 'Image', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Alt text', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Score', 'pixelweb-image-optimizer' ); ?></th><th><?php esc_html_e( 'Issues', 'pixelweb-image-optimizer' ); ?></th></tr></thead>
					<tbody id="pixelweb-seo-tbody"></tbody>
				</table>
				<div class="pixelweb-pager" id="pixelweb-seo-pager"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Settings page.
	 */
	public function page_settings() {
		$s = Pixelweb_Settings::all();
		?>
		<div class="wrap pixelweb-wrap">
			<?php $this->header( __( 'Settings', 'pixelweb-image-optimizer' ) ); ?>
			<form id="pixelweb-settings-form" class="pixelweb-card pixelweb-card-wide">
				<h2><?php esc_html_e( 'Conversion', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'New uploads', 'pixelweb-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="settings[auto_convert]" value="1" <?php checked( $s['auto_convert'] ); ?>> <?php esc_html_e( 'Create WebP copies automatically on upload', 'pixelweb-image-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="pixelweb-quality"><?php esc_html_e( 'Quality', 'pixelweb-image-optimizer' ); ?></label></th>
						<td>
							<input type="range" id="pixelweb-quality" name="settings[quality]" min="30" max="100" value="<?php echo esc_attr( $s['quality'] ); ?>">
							<output id="pixelweb-quality-val"><?php echo esc_html( $s['quality'] ); ?></output>
							<p class="description"><?php esc_html_e( '75–85 is invisible to the eye for photos. Lower = lighter.', 'pixelweb-image-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Resize huge uploads', 'pixelweb-image-optimizer' ); ?></th>
						<td>
							<label><input type="checkbox" name="settings[resize_uploads]" value="1" <?php checked( $s['resize_uploads'] ); ?>> <?php esc_html_e( 'Limit the served image to', 'pixelweb-image-optimizer' ); ?></label>
							<input type="number" name="settings[max_dimension]" value="<?php echo esc_attr( $s['max_dimension'] ); ?>" min="320" max="10000" class="small-text"> px
							<p class="description"><?php esc_html_e( 'Uses WordPress’ built-in “scaled” copy: the full-size original stays on the server.', 'pixelweb-image-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Delivery', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Serve WebP with', 'pixelweb-image-optimizer' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="settings[delivery_mode]" value="picture" <?php checked( $s['delivery_mode'], 'picture' ); ?>> <strong>&lt;picture&gt;</strong> — <?php esc_html_e( 'recommended, works with every cache and CDN', 'pixelweb-image-optimizer' ); ?></label><br>
								<label><input type="radio" name="settings[delivery_mode]" value="rewrite" <?php checked( $s['delivery_mode'], 'rewrite' ); ?>> <?php esc_html_e( 'URL rewrite — use if your theme’s CSS breaks with <picture>', 'pixelweb-image-optimizer' ); ?></label><br>
								<label><input type="radio" name="settings[delivery_mode]" value="off" <?php checked( $s['delivery_mode'], 'off' ); ?>> <?php esc_html_e( 'Off — only create the files (e.g. your CDN or server serves them)', 'pixelweb-image-optimizer' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pixelweb-exclude"><?php esc_html_e( 'Exclusions', 'pixelweb-image-optimizer' ); ?></label></th>
						<td>
							<textarea id="pixelweb-exclude" name="settings[exclusions]" rows="3" class="large-text code" placeholder="logo.png&#10;slider-"><?php echo esc_textarea( $s['exclusions'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Any image whose tag contains this text is left untouched (file name, CSS class…). The class "no-pixelweb" always works.', 'pixelweb-image-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Performance', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazy loading', 'pixelweb-image-optimizer' ); ?></th>
						<td>
							<label><input type="checkbox" name="settings[lazy_load]" value="1" <?php checked( $s['lazy_load'] ); ?>> <?php esc_html_e( 'Native lazy loading for every image WordPress missed', 'pixelweb-image-optimizer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pixelweb-lazy-skip"><?php esc_html_e( 'Protect LCP', 'pixelweb-image-optimizer' ); ?></label></th>
						<td>
							<?php
							printf(
								/* translators: %s: number input */
								esc_html__( 'Never lazy-load the first %s images of a page', 'pixelweb-image-optimizer' ),
								'<input type="number" id="pixelweb-lazy-skip" name="settings[lazy_skip]" value="' . esc_attr( $s['lazy_skip'] ) . '" min="0" max="20" class="small-text">'
							);
							?>
							<p class="description"><?php esc_html_e( 'Lazy-loading the hero image hurts Largest Contentful Paint. 2–3 is right for most sites.', 'pixelweb-image-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'SEO', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'File names', 'pixelweb-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="settings[seo_filenames]" value="1" <?php checked( $s['seo_filenames'] ); ?>> <?php esc_html_e( 'Clean names on upload: “Photo Été_2024.JPG” → “photo-ete-2024.jpg”', 'pixelweb-image-optimizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media library', 'pixelweb-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="settings[seo_column]" value="1" <?php checked( $s['seo_column'] ); ?>> <?php esc_html_e( 'Show the SEO score column', 'pixelweb-image-optimizer' ); ?></label></td>
					</tr>
				</table>

				<?php
				/**
				 * Add-ons print extra settings here. Inputs must be named settings[...].
				 *
				 * @param array $s Current free settings.
				 */
				do_action( 'pixelweb_settings_fields', $s );
				?>

				<h2><?php esc_html_e( 'Uninstall', 'pixelweb-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Clean up', 'pixelweb-image-optimizer' ); ?></th>
						<td><label><input type="checkbox" name="settings[uninstall_delete_files]" value="1" <?php checked( $s['uninstall_delete_files'] ); ?>> <?php esc_html_e( 'Delete the generated files (uploads/pixelweb-image-optimizer) when the plugin is deleted', 'pixelweb-image-optimizer' ); ?></label></td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'pixelweb-image-optimizer' ); ?></button>
					<span id="pixelweb-save-status" class="pixelweb-muted" aria-live="polite"></span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tools page.
	 */
	public function page_tools() {
		?>
		<div class="wrap pixelweb-wrap">
			<?php $this->header( __( 'Tools', 'pixelweb-image-optimizer' ) ); ?>
			<div class="pixelweb-card pixelweb-card-wide">
				<h2><?php esc_html_e( 'Re-optimize everything', 'pixelweb-image-optimizer' ); ?></h2>
				<p><?php esc_html_e( 'After changing the quality, queue every image again, then run Bulk Optimize.', 'pixelweb-image-optimizer' ); ?></p>
				<button type="button" class="button" id="pixelweb-reset"><?php esc_html_e( 'Queue all images again', 'pixelweb-image-optimizer' ); ?></button>
				<span class="pixelweb-muted" id="pixelweb-reset-status" aria-live="polite"></span>
			</div>
			<div class="pixelweb-card pixelweb-card-wide">
				<h2><?php esc_html_e( 'Remove generated files', 'pixelweb-image-optimizer' ); ?></h2>
				<p><?php esc_html_e( 'Deletes every copy created by this plugin. Original images are never touched, so the site keeps working.', 'pixelweb-image-optimizer' ); ?></p>
				<button type="button" class="button button-link-delete" id="pixelweb-delete-generated"><?php esc_html_e( 'Delete generated files', 'pixelweb-image-optimizer' ); ?></button>
				<span class="pixelweb-muted" id="pixelweb-delete-status" aria-live="polite"></span>
			</div>
			<div class="pixelweb-card pixelweb-card-wide">
				<h2>WP-CLI</h2>
				<p><code>wp pixelweb optimize</code> · <code>wp pixelweb optimize 42 --force</code> · <code>wp pixelweb status</code> · <code>wp pixelweb clean</code></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Media list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function media_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$columns['pixelweb_status'] = __( 'WebP', 'pixelweb-image-optimizer' );
		return $columns;
	}

	/**
	 * Render the media column.
	 *
	 * @param string $column Column.
	 * @param int    $id     Attachment ID.
	 */
	public function render_media_column( $column, $id = 0 ) {
		if ( 'pixelweb_status' === $column ) {
			echo $this->status_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html().
		}
	}

	/**
	 * Attachment modal field.
	 *
	 * @param array        $fields Fields.
	 * @param WP_Post|null $post   Attachment (some plugins apply this filter without it).
	 * @return array
	 */
	public function attachment_field( $fields, $post = null ) {
		if ( is_array( $fields ) && $post instanceof WP_Post && in_array( $post->post_mime_type, Pixelweb_Converter::$source_mimes, true ) ) {
			$fields['pixelweb_status'] = array(
				'label' => 'WebP',
				'input' => 'html',
				'html'  => $this->status_html( $post->ID ),
			);
		}
		return $fields;
	}

	/**
	 * Status + button.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private function status_html( $id ) {
		if ( ! in_array( get_post_mime_type( $id ), Pixelweb_Converter::$source_mimes, true ) ) {
			return '<span class="pixelweb-muted">—</span>';
		}
		$status = get_post_meta( $id, Pixelweb_Converter::META_STATUS, true );
		$info   = (array) get_post_meta( $id, Pixelweb_Converter::META_INFO, true );
		$html   = '<div class="pixelweb-status" data-id="' . esc_attr( $id ) . '">';

		if ( 'optimized' === $status && ! empty( $info['original'] ) ) {
			$data  = Pixelweb_Ajax::status_html_data(
				array(
					'original'  => $info['original'],
					'optimized' => $info['optimized'],
				)
			);
			$html .= '<span class="pixelweb-ok">✓ ' . esc_html( $data['message'] ) . '</span>';
		} elseif ( 'skipped' === $status ) {
			$html .= '<span class="pixelweb-muted">' . esc_html( Pixelweb_Ajax::reason_label( isset( $info['reason'] ) ? $info['reason'] : '' ) ) . '</span>';
		} else {
			if ( 'error' === $status && ! empty( $info['errors'] ) ) {
				$html .= '<span class="pixelweb-err">' . esc_html( implode( ' ', (array) $info['errors'] ) ) . '</span><br>';
			}
			$html .= '<button type="button" class="button button-small pixelweb-optimize-btn" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Optimize now', 'pixelweb-image-optimizer' ) . '</button>';
		}
		return $html . '<span class="pixelweb-optimize-result"></span></div>';
	}
}
