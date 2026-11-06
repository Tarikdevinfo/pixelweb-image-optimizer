<?php
/**
 * Optimization statistics.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one row per attachment (main file + all thumbnails summed).
 */
class Pixelweb_Stats {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'pixelweb_stats';
	}

	/**
	 * Create or update the table.
	 */
	public static function create_table() {
		global $wpdb;
		self::repair_legacy_index();
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			original_size bigint(20) unsigned NOT NULL DEFAULT 0,
			optimized_size bigint(20) unsigned NOT NULL DEFAULT 0,
			format varchar(10) NOT NULL DEFAULT 'webp',
			savings_percent float NOT NULL DEFAULT 0,
			optimized_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Version 1.0.0 created a non-unique "attachment_id" index. dbDelta() cannot turn it
	 * into the unique index used since 1.1.0 (it fails with "Duplicate key name"), so one
	 * image could be counted several times. Keep the newest row per image, then drop the
	 * old index so dbDelta() can create the unique one.
	 */
	private static function repair_legacy_index() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check during upgrade.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check during upgrade.
		$index = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'attachment_id' ), ARRAY_A );
		if ( empty( $index ) || '1' !== (string) $index['Non_unique'] ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off upgrade of our own table.
		$wpdb->query( $wpdb->prepare( 'DELETE older FROM %i AS older INNER JOIN %i AS newer ON older.attachment_id = newer.attachment_id AND older.id < newer.id', $table, $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off upgrade of our own table.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX attachment_id', $table ) );
	}

	/**
	 * Insert or update the row for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $original_size Bytes before.
	 * @param int    $optimized_size Bytes after (best format).
	 * @param string $format Format label, e.g. "webp".
	 */
	public static function record( $attachment_id, $original_size, $optimized_size, $format ) {
		global $wpdb;
		if ( $attachment_id <= 0 || $original_size <= 0 ) {
			return;
		}
		$savings = round( ( 1 - $optimized_size / $original_size ) * 100, 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, write.
		$wpdb->replace(
			self::table(),
			array(
				'attachment_id'   => $attachment_id,
				'original_size'   => $original_size,
				'optimized_size'  => $optimized_size,
				'format'          => substr( $format, 0, 10 ),
				'savings_percent' => max( 0, $savings ),
				'optimized_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%f', '%s' )
		);
		wp_cache_delete( 'dashboard', 'pixelweb' );
	}

	/**
	 * Remove the row for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function delete( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, write.
		$wpdb->delete( self::table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		wp_cache_delete( 'dashboard', 'pixelweb' );
	}

	/**
	 * Empty the table.
	 */
	public static function truncate() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- our own table, emptied on request.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table() ) );
		wp_cache_delete( 'dashboard', 'pixelweb' );
	}

	/**
	 * Dashboard numbers.
	 *
	 * @return array
	 */
	public static function dashboard() {
		$cached = wp_cache_get( 'dashboard', 'pixelweb' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table, object-cached above.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(original_size),0) AS orig, COALESCE(SUM(optimized_size),0) AS opt FROM %i', self::table() ) );

		$total     = Pixelweb_Converter::count_images();
		$pending   = Pixelweb_Converter::count_images( 'pending' );
		$processed = max( 0, $total - $pending );
		$orig      = $row ? (int) $row->orig : 0;
		$opt       = $row ? (int) $row->opt : 0;

		$stats = array(
			'total_images'   => $total,
			'optimized'      => $row ? (int) $row->n : 0,
			'pending'        => $pending,
			'total_original' => $orig,
			'total_saved'    => max( 0, $orig - $opt ),
			'avg_savings'    => $orig > 0 ? round( ( 1 - $opt / $orig ) * 100, 1 ) : 0,
			'percent_done'   => $total > 0 ? (int) floor( $processed / $total * 100 ) : 0,
		);

		wp_cache_set( 'dashboard', $stats, 'pixelweb', 60 );
		return $stats;
	}

	/**
	 * Latest rows with titles.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function recent( $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, dashboard only.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT s.*, p.post_title FROM %i s LEFT JOIN %i p ON s.attachment_id = p.ID ORDER BY s.optimized_at DESC LIMIT %d', self::table(), $wpdb->posts, $limit ) );
	}

	/**
	 * Human readable bytes.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		return $bytes > 0 ? size_format( $bytes, $bytes >= MB_IN_BYTES ? 2 : 1 ) : '0 B';
	}
}
