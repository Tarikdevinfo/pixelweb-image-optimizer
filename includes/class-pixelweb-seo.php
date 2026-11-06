<?php
/**
 * Image SEO checks, alt text editor and SEO-friendly file names.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image SEO.
 */
class Pixelweb_SEO {

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		if ( Pixelweb_Settings::get( 'seo_column' ) && is_admin() ) {
			add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
			add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		}
		if ( Pixelweb_Settings::get( 'seo_filenames' ) ) {
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'clean_upload_name' ) );
			add_filter( 'wp_handle_sideload_prefilter', array( $this, 'clean_upload_name' ) );
		}
	}

	/**
	 * "IMG_2034 Été.JPG" becomes "img-2034-ete.jpg".
	 *
	 * @param array $file Upload array.
	 * @return array
	 */
	public function clean_upload_name( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) || ! is_string( $file['name'] ) ) {
			return $file;
		}
		$type = ! empty( $file['type'] ) ? $file['type'] : (string) wp_check_filetype( $file['name'] )['type'];
		if ( 0 !== strpos( $type, 'image/' ) ) {
			return $file;
		}
		$file['name'] = self::seo_filename( $file['name'] );
		return $file;
	}

	/**
	 * Build an SEO-friendly file name.
	 *
	 * @param string $name Original name.
	 * @return string
	 */
	public static function seo_filename( $name ) {
		$info = pathinfo( $name );
		$ext  = isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';
		$base = remove_accents( $info['filename'] );
		$base = function_exists( 'mb_strtolower' ) ? mb_strtolower( $base, 'UTF-8' ) : strtolower( $base );
		$base = preg_replace( '/[\s_.+]+/u', '-', $base );
		$base = preg_replace( '/[^\p{L}\p{N}-]+/u', '', (string) $base );
		// preg_* return null on invalid UTF-8: fall back to WordPress' own cleaning.
		$base = null === $base ? sanitize_title( $info['filename'] ) : $base;
		$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );
		if ( '' === $base ) {
			$base = 'image';
		}
		return $ext ? $base . '.' . $ext : $base;
	}

	/**
	 * Media list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['pixelweb_seo'] = __( 'Image SEO', 'pixelweb-image-optimizer' );
		return $columns;
	}

	/**
	 * Render the column.
	 *
	 * @param string $column        Column.
	 * @param int    $attachment_id Attachment ID.
	 */
	public function render_column( $column, $attachment_id = 0 ) {
		if ( 'pixelweb_seo' !== $column || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			return;
		}
		$analysis = self::analyze( $attachment_id );
		$class    = $analysis['score'] >= 70 ? 'good' : ( $analysis['score'] >= 40 ? 'ok' : 'poor' );
		printf(
			'<span class="pixelweb-score pixelweb-score-%1$s">%2$d%%</span>',
			esc_attr( $class ),
			(int) $analysis['score']
		);
		if ( $analysis['problems'] ) {
			echo '<br><small class="pixelweb-muted">' . esc_html( implode( ' · ', $analysis['problems'] ) ) . '</small>';
		}
	}

	/**
	 * Score an image out of 100.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array score, problems (labels), codes.
	 */
	public static function analyze( $attachment_id ) {
		$score    = 100;
		$problems = array();
		$codes    = array();

		$alt      = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$file     = get_attached_file( $attachment_id );
		$base     = $file ? preg_replace( '/-(scaled|rotated)$/', '', pathinfo( $file, PATHINFO_FILENAME ) ) : '';
		$title    = trim( (string) get_the_title( $attachment_id ) );
		$alt_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $alt ) : strlen( $alt );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( '' === $alt ) {
			$score -= 40;
			$codes[]    = 'missing_alt';
			$problems[] = __( 'Missing alt text', 'pixelweb-image-optimizer' );
		} elseif ( $alt_len < 5 ) {
			$score -= 15;
			$codes[]    = 'short_alt';
			$problems[] = __( 'Alt text too short', 'pixelweb-image-optimizer' );
		} elseif ( $alt_len > 125 ) {
			$score -= 10;
			$codes[]    = 'long_alt';
			$problems[] = __( 'Alt text too long', 'pixelweb-image-optimizer' );
		}

		if ( $base && ( preg_match( '/^(img|dsc|dcim|pxl|screenshot|screen-shot|capture|whatsapp|image|photo|picture|untitled|sans-titre|unnamed|download|telechargement)[-_\s]?/i', $base ) || preg_match( '/^[\d_-]+$|^[a-f0-9]{16,}$/i', $base ) ) ) {
			$score -= 20;
			$codes[]    = 'filename';
			$problems[] = __( 'Non-descriptive file name', 'pixelweb-image-optimizer' );
		} elseif ( $base && preg_match( '/[A-Z\s_]/', $base ) ) {
			$score -= 5;
			$codes[]    = 'filename_format';
			$problems[] = __( 'File name has capitals, spaces or underscores', 'pixelweb-image-optimizer' );
		}

		if ( $file && file_exists( $file ) && filesize( $file ) > 300 * KB_IN_BYTES ) {
			$has_next_gen = in_array( get_post_meta( $attachment_id, Pixelweb_Converter::META_STATUS, true ), array( 'optimized' ), true );
			if ( ! $has_next_gen ) {
				$score -= 15;
				$codes[]    = 'heavy';
				$problems[] = __( 'Heavy file without WebP version', 'pixelweb-image-optimizer' );
			}
		}

		if ( ! empty( $metadata['width'] ) && $metadata['width'] > 2560 ) {
			$score -= 10;
			$codes[]    = 'too_wide';
			$problems[] = __( 'Wider than 2560 px', 'pixelweb-image-optimizer' );
		}

		$title_as_name = sanitize_title( $title ) === sanitize_title( $base );
		if ( '' === $title || ( $title_as_name && in_array( 'filename', $codes, true ) ) ) {
			$score -= 10;
			$codes[]    = 'title';
			$problems[] = __( 'No descriptive title', 'pixelweb-image-optimizer' );
		}

		return array(
			'score'    => max( 0, $score ),
			'problems' => $problems,
			'codes'    => $codes,
		);
	}

	/**
	 * Analyze one page of the library.
	 *
	 * @param int    $page     1-based page.
	 * @param int    $per_page Items.
	 * @param string $filter   all|missing_alt|poor.
	 * @return array
	 */
	public static function analyze_page( $page = 1, $per_page = 50, $filter = 'all' ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, $page ),
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);
		if ( 'missing_alt' === $filter ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => '_wp_attachment_image_alt',
					'value' => '',
				),
			);
		}
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$analysis = self::analyze( $post->ID );
			if ( 'poor' === $filter && $analysis['score'] >= 70 ) {
				continue;
			}
			$items[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'file'     => wp_basename( (string) get_attached_file( $post->ID ) ),
				'thumb'    => wp_get_attachment_image_url( $post->ID, 'thumbnail' ),
				'edit'     => get_edit_post_link( $post->ID, 'raw' ),
				'alt'      => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'score'    => $analysis['score'],
				'problems' => $analysis['problems'],
				'codes'    => $analysis['codes'],
			);
		}
		return array(
			'items'       => $items,
			'page'        => (int) $args['paged'],
			'total_pages' => (int) $query->max_num_pages,
			'total'       => (int) $query->found_posts,
		);
	}

	/**
	 * Library-wide summary (alt coverage is computed with one query).
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate count, read-only.
		$total = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate count, read-only.
		$with_alt = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt' AND m.meta_value <> '' WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'" );
		return array(
			'total'        => $total,
			'missing_alt'  => max( 0, $total - $with_alt ),
			'alt_coverage' => $total > 0 ? (int) round( $with_alt / $total * 100 ) : 100,
		);
	}

	/**
	 * Save alt text.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alt text.
	 * @return bool
	 */
	public static function save_alt( $attachment_id, $alt ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		return true;
	}
}
