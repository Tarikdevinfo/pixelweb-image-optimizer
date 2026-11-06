<?php
/**
 * Admin AJAX endpoints.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every endpoint checks the nonce and a capability.
 */
class Pixelweb_Ajax {

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
	 * Register actions.
	 */
	public function init() {
		$actions = array(
			'pixelweb_bulk'             => 'bulk',
			'pixelweb_optimize_one'     => 'optimize_one',
			'pixelweb_save_settings'    => 'save_settings',
			'pixelweb_seo_page'         => 'seo_page',
			'pixelweb_save_alt'         => 'save_alt',
			'pixelweb_reset_statuses'   => 'reset_statuses',
			'pixelweb_delete_generated' => 'delete_generated',
			'pixelweb_stats'            => 'stats',
		);
		foreach ( $actions as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Capability guard. Each handler verifies the nonce itself first.
	 *
	 * @param string $cap Capability.
	 */
	private function require_cap( $cap = 'manage_options' ) {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixelweb-image-optimizer' ) ), 403 );
		}
	}

	/**
	 * Process the next batch of pending images.
	 */
	public function bulk() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap();
		$batch = isset( $_POST['batch'] ) ? min( 20, max( 1, absint( $_POST['batch'] ) ) ) : 3;

		if ( ! Pixelweb_Converter::webp_supported() ) {
			wp_send_json_error( array( 'message' => __( 'This server cannot write WebP images (GD or Imagick with WebP support is required).', 'pixelweb-image-optimizer' ) ) );
		}

		$items = array();
		foreach ( Pixelweb_Converter::pending_ids( $batch ) as $id ) {
			$result  = $this->converter->optimize_attachment( $id, null, false, 'bulk' );
			$items[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'status'    => $result['status'],
				'reason'    => isset( $result['reason'] ) ? $result['reason'] : '',
				'original'  => $result['original'],
				'optimized' => $result['optimized'],
				'errors'    => $result['errors'],
			);
		}

		wp_cache_delete( 'dashboard', 'pixelweb' );
		$stats = Pixelweb_Stats::dashboard();
		wp_send_json_success(
			array(
				'items'   => $items,
				'pending' => $stats['pending'],
				'stats'   => $this->stats_payload( $stats ),
			)
		);
	}

	/**
	 * Optimize a single attachment from the media library.
	 */
	public function optimize_one() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap( 'upload_files' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image.', 'pixelweb-image-optimizer' ) ) );
		}
		$result = $this->converter->optimize_attachment( $id, null, true, 'manual' );
		if ( 'optimized' !== $result['status'] ) {
			$message = $result['errors'] ? implode( ' ', $result['errors'] ) : self::reason_label( isset( $result['reason'] ) ? $result['reason'] : '' );
			wp_send_json_error( array( 'message' => $message ) );
		}
		wp_send_json_success( self::status_html_data( $result ) );
	}

	/**
	 * Save settings (+ let add-ons save theirs).
	 */
	public function save_settings() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap();
		// Every value is sanitized as soon as it is received. The exclusion list keeps its line breaks.
		$submitted = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array();
		$submitted['exclusions'] = isset( $_POST['settings']['exclusions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['settings']['exclusions'] ) ) : '';

		Pixelweb_Settings::save( Pixelweb_Settings::sanitize( $submitted ) );

		/**
		 * Fires when the settings form is saved. Add-ons read their own keys, already
		 * passed through sanitize_text_field(), and validate them further.
		 *
		 * @param array $submitted Sanitized submitted values.
		 */
		do_action( 'pixelweb_save_settings', $submitted );

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'pixelweb-image-optimizer' ) ) );
	}

	/**
	 * One page of SEO analysis.
	 */
	public function seo_page() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap( 'upload_files' );
		$page   = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
		$data   = Pixelweb_SEO::analyze_page( $page, 40, $filter );
		$data['summary'] = Pixelweb_SEO::summary();
		wp_send_json_success( $data );
	}

	/**
	 * Inline alt text edit.
	 */
	public function save_alt() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap( 'upload_files' );
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! Pixelweb_SEO::save_alt( $id, $alt ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not save.', 'pixelweb-image-optimizer' ) ) );
		}
		$analysis = Pixelweb_SEO::analyze( $id );
		wp_send_json_success(
			array(
				'score'    => $analysis['score'],
				'problems' => $analysis['problems'],
			)
		);
	}

	/**
	 * Forget statuses so everything is processed again.
	 */
	public function reset_statuses() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap();
		Pixelweb_Converter::reset_statuses();
		wp_send_json_success( array( 'message' => __( 'All images are queued again.', 'pixelweb-image-optimizer' ) ) );
	}

	/**
	 * Delete generated files in batches.
	 */
	public function delete_generated() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap();
		$deleted = 0;
		$ids     = Pixelweb_Converter::processed_ids( 25 );
		foreach ( $ids as $id ) {
			$deleted += $this->converter->delete_generated( $id );
		}
		$remaining = count( Pixelweb_Converter::processed_ids( 1 ) );
		if ( ! $remaining ) {
			Pixelweb_Stats::truncate();
		}
		wp_send_json_success(
			array(
				'deleted' => $deleted,
				'done'    => 0 === $remaining,
			)
		);
	}

	/**
	 * Dashboard numbers.
	 */
	public function stats() {
		check_ajax_referer( 'pixelweb_nonce', 'nonce' );
		$this->require_cap();
		wp_send_json_success( $this->stats_payload( Pixelweb_Stats::dashboard() ) );
	}

	/**
	 * Add formatted values.
	 *
	 * @param array $stats Raw stats.
	 * @return array
	 */
	private function stats_payload( $stats ) {
		$stats['total_saved_h'] = Pixelweb_Stats::format_bytes( $stats['total_saved'] );
		return $stats;
	}

	/**
	 * Human label for a skip reason.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		switch ( $reason ) {
			case 'larger':
				return __( 'Already optimal: WebP would be heavier than the original.', 'pixelweb-image-optimizer' );
			case 'animated':
				return __( 'Animated GIF kept as is.', 'pixelweb-image-optimizer' );
			case 'mime':
				return __( 'Format not converted.', 'pixelweb-image-optimizer' );
			default:
				return __( 'Nothing to do.', 'pixelweb-image-optimizer' );
		}
	}

	/**
	 * Data for the media status cell.
	 *
	 * @param array $result Optimization result.
	 * @return array
	 */
	public static function status_html_data( $result ) {
		$savings = $result['original'] > 0 ? round( ( 1 - $result['optimized'] / $result['original'] ) * 100, 1 ) : 0;
		return array(
			'message' => sprintf(
				/* translators: 1: size before, 2: size after, 3: percent saved */
				__( '%1$s → %2$s (−%3$s%%)', 'pixelweb-image-optimizer' ),
				Pixelweb_Stats::format_bytes( $result['original'] ),
				Pixelweb_Stats::format_bytes( $result['optimized'] ),
				number_format_i18n( $savings, 1 )
			),
		);
	}
}
