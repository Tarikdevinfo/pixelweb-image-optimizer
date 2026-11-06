<?php
/**
 * Uninstall: remove settings, stats and (optionally) generated files.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean one site.
 */
function pixelweb_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'pixelweb_settings', array() );

	if ( ! empty( $settings['uninstall_delete_files'] ) ) {
		$uploads = wp_get_upload_dir();

		// Copies written next to the originals by versions 1.1.0 and 1.1.1 (photo.jpg.webp).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", '_pixelweb_status' ) );
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( empty( $meta['file'] ) ) {
				continue;
			}
			$main  = path_join( $uploads['basedir'], $meta['file'] );
			$dir   = trailingslashit( dirname( $main ) );
			$files = array( $main );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$files[] = $dir . $size['file'];
					}
				}
			}
			foreach ( $files as $file ) {
				if ( file_exists( $file . '.webp' ) ) {
					wp_delete_file( $file . '.webp' );
				}
			}
		}

		// Everything generated since 1.1.2 lives in one folder of the uploads directory.
		$folder = trailingslashit( $uploads['basedir'] ) . 'pixelweb-image-optimizer';
		if ( is_dir( $folder ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$wp_filesystem->rmdir( $folder, true );
			}
		}
	}

	delete_option( 'pixelweb_settings' );
	delete_option( 'pixelweb_db_version' );
	delete_option( 'pixelweb_rebuild_notice' );
	delete_post_meta_by_key( '_pixelweb_status' );
	delete_post_meta_by_key( '_pixelweb_info' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own table.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'pixelweb_stats' ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $pixelweb_site_id ) {
		switch_to_blog( $pixelweb_site_id );
		pixelweb_uninstall_site();
		restore_current_blog();
	}
} else {
	pixelweb_uninstall_site();
}

delete_metadata( 'user', 0, 'pixelweb_upsell_dismissed', '', true );
