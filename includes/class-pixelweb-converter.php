<?php
/**
 * WebP conversion engine.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts attachments to WebP and keeps the originals untouched.
 *
 * Generated files are stored in their own folder inside the uploads directory,
 * mirroring the media library: uploads/2026/10/photo.jpg is converted to
 * uploads/pixelweb-image-optimizer/2026/10/photo.jpg.webp. Keeping the source
 * extension means photo.jpg and photo.png never collide.
 */
class Pixelweb_Converter {

	const META_STATUS = '_pixelweb_status';
	const META_INFO   = '_pixelweb_info';

	/**
	 * Folder (inside the uploads directory) that holds every generated file.
	 */
	const OUTPUT_DIR = 'pixelweb-image-optimizer';

	/**
	 * Mime types we convert.
	 *
	 * @var string[]
	 */
	public static $source_mimes = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		if ( Pixelweb_Settings::get( 'auto_convert' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 20, 2 );
		}
		if ( Pixelweb_Settings::get( 'resize_uploads' ) ) {
			add_filter( 'big_image_size_threshold', array( $this, 'big_image_threshold' ), 20 );
		}
		add_action( 'delete_attachment', array( $this, 'cleanup_attachment' ) );
	}

	/**
	 * Use WordPress' own non-destructive "scaled" image feature for resizing.
	 * The original upload is kept as original_image; the scaled copy is served.
	 *
	 * @return int
	 */
	public function big_image_threshold() {
		return (int) Pixelweb_Settings::get( 'max_dimension' );
	}

	/**
	 * Convert right after WordPress generated all sub-sizes.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function on_generate_metadata( $metadata, $attachment_id = 0 ) {
		// Some plugins apply this filter with the metadata only: nothing to convert then.
		if ( is_array( $metadata ) && $attachment_id > 0 ) {
			$this->optimize_attachment( $attachment_id, $metadata, true, 'upload' );
		}
		return $metadata;
	}

	/* ---------- Server support ---------- */

	/**
	 * Can this server write WebP files?
	 *
	 * Some hosts announce WebP support but write empty files, so the answer comes
	 * from a real encode, cached for a day. Only called from the admin, WP-CLI,
	 * uploads and background tasks: a visitor's page view never runs it.
	 *
	 * @return bool
	 */
	public static function webp_supported() {
		static $supported = null;
		if ( null !== $supported ) {
			return $supported;
		}
		$supported = false;
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return false;
		}
		// The cache key changes when PHP or an image library is updated.
		$key   = 'pixelweb_probe_webp_' . md5( self::environment_fingerprint() );
		$known = get_transient( $key );
		if ( false !== $known ) {
			$supported = ( 'yes' === $known );
			return $supported;
		}
		$supported = self::probe();
		set_transient( $key, $supported ? 'yes' : 'no', DAY_IN_SECONDS );
		return $supported;
	}

	/**
	 * Formats written by this plugin.
	 *
	 * @deprecated 1.1.2 Kept so that add-ons written for 1.1.x keep working. Use webp_supported().
	 *
	 * @return string[]
	 */
	public function formats() {
		return self::webp_supported() ? array( 'webp' ) : array();
	}

	/**
	 * Whether the server can write a format.
	 *
	 * @deprecated 1.1.2 Kept so that add-ons written for 1.1.x keep working. Use webp_supported().
	 *
	 * @param string $format Format.
	 * @return bool
	 */
	public static function server_supports( $format ) {
		return 'webp' === $format && self::webp_supported();
	}

	/**
	 * Identify the image libraries in use.
	 *
	 * @return string
	 */
	public static function environment_fingerprint() {
		$parts   = array( PHP_VERSION );
		$parts[] = extension_loaded( 'imagick' ) ? (string) phpversion( 'imagick' ) : 'no-imagick';
		if ( function_exists( 'gd_info' ) ) {
			$gd      = gd_info();
			$parts[] = isset( $gd['GD Version'] ) ? $gd['GD Version'] : 'gd';
		}
		return implode( '|', $parts );
	}

	/**
	 * Create an empty temporary file.
	 *
	 * The function wp_tempnam() lives in an admin-only file that WordPress does not load
	 * on the front end, in WP-Cron or in some REST requests: load it when missing.
	 *
	 * @param string $prefix File name prefix.
	 * @return string Path.
	 */
	public static function temp_file( $prefix ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return wp_tempnam( $prefix );
	}

	/**
	 * Encode a small test image to WebP.
	 *
	 * @return bool
	 */
	private static function probe() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) {
			return true; // Cannot test without GD: trust the editor.
		}
		$tmp = self::temp_file( 'pixelweb-probe' );
		$src = $tmp . '.jpg';
		$img = imagecreatetruecolor( 32, 32 );
		imagefilledrectangle( $img, 0, 0, 15, 31, imagecolorallocate( $img, 200, 40, 40 ) );
		imagejpeg( $img, $src, 90 );
		imagedestroy( $img );

		$ok     = false;
		$editor = wp_get_image_editor( $src );
		if ( ! is_wp_error( $editor ) ) {
			$saved = $editor->save( $tmp . '.webp', 'image/webp' );
			$out   = ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) ? $saved['path'] : '';
			$ok    = $out && file_exists( $out ) && filesize( $out ) > 0;
			if ( $out && file_exists( $out ) ) {
				wp_delete_file( $out );
			}
		}
		foreach ( array( $tmp, $src ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		return $ok;
	}

	/**
	 * Server capabilities for the admin screens.
	 *
	 * @return array
	 */
	public static function capabilities() {
		return array(
			'gd'      => extension_loaded( 'gd' ),
			'imagick' => extension_loaded( 'imagick' ),
			'webp'    => self::webp_supported(),
		);
	}

	/* ---------- Where generated files live ---------- */

	/**
	 * Absolute path of the plugin's folder inside the uploads directory.
	 *
	 * @return string
	 */
	public static function output_root() {
		$uploads = wp_get_upload_dir();
		return wp_normalize_path( untrailingslashit( $uploads['basedir'] ) ) . '/' . self::OUTPUT_DIR;
	}

	/**
	 * Generated-file path for a source file, without the added extension.
	 *
	 * uploads/2026/10/photo.jpg becomes uploads/pixelweb-image-optimizer/2026/10/photo.jpg.
	 * Add-ons append their own extension to it.
	 *
	 * @param string $source Absolute path of a file in the uploads directory.
	 * @return string Empty when the source is not in the uploads directory.
	 */
	public static function output_base( $source ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		$source  = wp_normalize_path( (string) $source );
		if ( '' === $source || 0 !== strpos( $source, $base . '/' ) ) {
			return '';
		}
		$relative = substr( $source, strlen( $base ) );
		if ( 0 === strpos( $relative, '/' . self::OUTPUT_DIR . '/' ) || false !== strpos( $relative, '..' ) ) {
			return '';
		}
		return $base . '/' . self::OUTPUT_DIR . $relative;
	}

	/**
	 * Generated-file URL and path for an uploads URL, without the added extension.
	 *
	 * The URL keeps the scheme and host it was written with (CDN, http/https).
	 *
	 * @param string $url Image URL.
	 * @return array|false Array with 'url' and 'path', or false when the URL is not in the uploads directory.
	 */
	public static function output_base_for_url( $url ) {
		$uploads   = wp_get_upload_dir();
		$base_url  = untrailingslashit( $uploads['baseurl'] );
		$base_less = preg_replace( '#^https?:#i', '', $base_url );
		$base_rel  = (string) wp_parse_url( $base_url, PHP_URL_PATH );

		$clean      = preg_replace( '/[?#].*$/', '', html_entity_decode( (string) $url ) );
		$schemeless = preg_replace( '#^https?:#i', '', $clean );

		if ( 0 === strpos( $schemeless, $base_less . '/' ) ) {
			$prefix   = substr( $clean, 0, strlen( $clean ) - strlen( $schemeless ) + strlen( $base_less ) );
			$relative = substr( $schemeless, strlen( $base_less ) );
		} elseif ( '' !== $base_rel && 0 === strpos( $clean, $base_rel . '/' ) ) {
			$prefix   = $base_rel;
			$relative = substr( $clean, strlen( $base_rel ) );
		} else {
			return false;
		}

		$decoded = rawurldecode( $relative );
		if ( false !== strpos( $decoded, '..' ) || 0 === strpos( $decoded, '/' . self::OUTPUT_DIR . '/' ) ) {
			return false;
		}
		return array(
			'url'  => $prefix . '/' . self::OUTPUT_DIR . $relative,
			'path' => self::output_root() . $decoded,
		);
	}

	/**
	 * WebP path for a source file.
	 *
	 * @param string $source Source path.
	 * @return string Empty when the source is not in the uploads directory.
	 */
	public static function webp_path( $source ) {
		$base = self::output_base( $source );
		return '' === $base ? '' : $base . '.webp';
	}

	/* ---------- Conversion ---------- */

	/**
	 * Optimize one attachment (main file + every thumbnail).
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata (read from DB when null).
	 * @param bool       $force         Re-convert even if files exist.
	 * @param string     $context       upload|bulk|manual|cli, passed on to add-ons.
	 * @return array Result with status, original, optimized, converted, errors.
	 */
	public function optimize_attachment( $attachment_id, $metadata = null, $force = false, $context = 'manual' ) {
		$result = array(
			'id'        => (int) $attachment_id,
			'status'    => 'skipped',
			'original'  => 0,
			'optimized' => 0,
			'converted' => 0,
			'formats'   => array(),
			'errors'    => array(),
		);

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::$source_mimes, true ) ) {
			$result['reason'] = 'mime';
			return $this->finish( $attachment_id, $result );
		}

		if ( ! self::webp_supported() ) {
			$result['status']   = 'error';
			$result['errors'][] = __( 'This server cannot write WebP images (GD or Imagick with WebP support is required).', 'pixelweb-image-optimizer' );
			return $result; // Do not store: retry once the server is fixed.
		}

		$files = $this->source_files( $attachment_id, $metadata );
		if ( empty( $files ) ) {
			$result['status']   = 'error';
			$result['errors'][] = __( 'File not found on disk.', 'pixelweb-image-optimizer' );
			return $this->finish( $attachment_id, $result );
		}

		if ( 'image/gif' === $mime && self::is_animated_gif( $files[0] ) ) {
			$result['reason'] = 'animated';
			return $this->finish( $attachment_id, $result );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		foreach ( $files as $file ) {
			$size = (int) filesize( $file );
			$best = $size;

			$converted = $this->convert_file( $file, $force );
			if ( is_wp_error( $converted ) ) {
				$result['errors'][] = basename( $file ) . ': ' . $converted->get_error_message();
			} elseif ( $converted ) {
				$best = min( $best, (int) filesize( $converted ) );
				$result['converted']++;
			}
			self::delete_legacy_copy( $file );

			$result['original']  += $size;
			$result['optimized'] += $best;
		}

		if ( $result['converted'] > 0 ) {
			$result['status']  = 'optimized';
			$result['formats'] = array( 'webp' );
			Pixelweb_Stats::record( $attachment_id, $result['original'], $result['optimized'], 'webp' );
		} elseif ( ! empty( $result['errors'] ) ) {
			$result['status'] = 'error';
		} else {
			$result['reason'] = 'larger';
		}

		/**
		 * Fires after an attachment was processed.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param array  $result        Result data.
		 * @param string $context       upload|bulk|manual|cli.
		 */
		do_action( 'pixelweb_attachment_optimized', $attachment_id, $result, $context );

		return $this->finish( $attachment_id, $result );
	}

	/**
	 * Persist status + summary.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Result.
	 * @return array
	 */
	private function finish( $attachment_id, $result ) {
		update_post_meta( $attachment_id, self::META_STATUS, $result['status'] );
		update_post_meta(
			$attachment_id,
			self::META_INFO,
			array(
				'original'  => $result['original'],
				'optimized' => $result['optimized'],
				'formats'   => $result['formats'],
				'reason'    => isset( $result['reason'] ) ? $result['reason'] : '',
				'errors'    => array_slice( $result['errors'], 0, 5 ),
				'time'      => time(),
			)
		);
		return $result;
	}

	/**
	 * Convert one file to WebP.
	 *
	 * @param string $file  Source path.
	 * @param bool   $force Overwrite an existing WebP file.
	 * @return string|false|WP_Error WebP path, false when the WebP file was larger, or an error.
	 */
	public function convert_file( $file, $force = false ) {
		$dest = self::webp_path( $file );
		if ( '' === $dest ) {
			return new WP_Error( 'pixelweb_location', __( 'The image is not in the uploads folder.', 'pixelweb-image-optimizer' ) );
		}

		if ( ! $force && file_exists( $dest ) && filemtime( $dest ) >= filemtime( $file ) ) {
			$existing = self::keep_if_smaller( $file, $dest );
			if ( ! is_wp_error( $existing ) ) {
				return $existing;
			}
		}

		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new WP_Error( 'pixelweb_mkdir', __( 'Could not create the folder for converted images in the uploads directory.', 'pixelweb-image-optimizer' ) );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$quality = (int) Pixelweb_Settings::get( 'quality' );
		/**
		 * Filter the WebP quality used for one conversion.
		 *
		 * @param int    $quality Quality 1-100.
		 * @param string $file    Source path.
		 * @param string $format  Always 'webp' (kept for compatibility).
		 */
		$quality = (int) apply_filters( 'pixelweb_quality', $quality, $file, 'webp' );
		$quality = max( 1, min( 100, $quality ) );

		// WordPress' GD editor cannot write palette PNG/GIF to WebP: do it ourselves.
		$mime = wp_check_filetype( $file )['type'];
		if ( $editor instanceof WP_Image_Editor_GD && in_array( $mime, array( 'image/png', 'image/gif' ), true ) ) {
			$written = self::convert_palette_with_gd( $file, $dest, $quality, $mime );
			if ( is_wp_error( $written ) ) {
				return $written;
			}
			return self::keep_if_smaller( $file, $dest );
		}

		$editor->set_quality( $quality );
		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$written = isset( $saved['path'] ) ? wp_normalize_path( $saved['path'] ) : $dest;
		if ( $written !== $dest ) {
			// The editor fell back to another format: this is not a WebP file, discard it.
			if ( file_exists( $written ) ) {
				wp_delete_file( $written );
			}
			return new WP_Error( 'pixelweb_write', __( 'Could not write the converted file.', 'pixelweb-image-optimizer' ) );
		}
		clearstatcache( true, $dest );

		return self::keep_if_smaller( $file, $dest );
	}

	/**
	 * Keep the converted file only when it is lighter than the source.
	 *
	 * @param string $file Source.
	 * @param string $dest Converted file.
	 * @return string|false|WP_Error
	 */
	private static function keep_if_smaller( $file, $dest ) {
		clearstatcache( true, $dest );
		if ( ! file_exists( $dest ) || 0 === filesize( $dest ) ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return new WP_Error( 'pixelweb_write', __( 'Could not write the converted file.', 'pixelweb-image-optimizer' ) );
		}
		if ( filesize( $dest ) >= filesize( $file ) ) {
			wp_delete_file( $dest );
			return false;
		}
		return $dest;
	}

	/**
	 * GD conversion that handles palette images and transparency.
	 *
	 * @param string $file    Source.
	 * @param string $dest    Destination.
	 * @param int    $quality Quality.
	 * @param string $mime    Source mime.
	 * @return true|WP_Error
	 */
	private static function convert_palette_with_gd( $file, $dest, $quality, $mime ) {
		if ( ! function_exists( 'imagewebp' ) ) {
			return new WP_Error( 'pixelweb_gd_save', __( 'Could not write the converted file.', 'pixelweb-image-optimizer' ) );
		}
		$image = 'image/png' === $mime ? @imagecreatefrompng( $file ) : @imagecreatefromgif( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $image ) {
			return new WP_Error( 'pixelweb_gd_load', __( 'Could not read the image.', 'pixelweb-image-optimizer' ) );
		}
		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}
		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$ok = @imagewebp( $image, $dest, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		imagedestroy( $image );

		return $ok ? true : new WP_Error( 'pixelweb_gd_save', __( 'Could not write the converted file.', 'pixelweb-image-optimizer' ) );
	}

	/**
	 * Versions 1.1.0 and 1.1.1 wrote "photo.jpg.webp" next to the original.
	 * Remove that copy once the file lives in the plugin's own folder.
	 *
	 * @param string $file Source path.
	 */
	private static function delete_legacy_copy( $file ) {
		$legacy = $file . '.webp';
		if ( file_exists( $legacy ) ) {
			wp_delete_file( $legacy );
		}
	}

	/**
	 * Absolute paths of the main file and every generated size.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Metadata.
	 * @return string[]
	 */
	public function source_files( $attachment_id, $metadata = null ) {
		$main = get_attached_file( $attachment_id );
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$uploads = wp_get_upload_dir();
			$main    = path_join( $uploads['basedir'], $metadata['file'] );
		}
		if ( ! $main || ! file_exists( $main ) ) {
			return array();
		}

		$files = array( $main );
		$dir   = trailingslashit( dirname( $main ) );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$path = $dir . $size['file'];
				if ( file_exists( $path ) && in_array( wp_check_filetype( $path )['type'], self::$source_mimes, true ) ) {
					$files[] = $path;
				}
			}
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * Delete every file generated for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Files deleted.
	 */
	public function delete_generated( $attachment_id ) {
		$deleted = 0;
		$files   = $this->source_files( $attachment_id );
		$main    = get_attached_file( $attachment_id );
		if ( $main ) {
			$files[] = $main;
			$meta    = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['original_image'] ) ) {
				$files[] = path_join( dirname( $main ), $meta['original_image'] );
			}
		}
		$files = array_values( array_unique( $files ) );

		/**
		 * Fires before the files generated for an attachment are deleted, so add-ons
		 * can delete theirs.
		 *
		 * @param int      $attachment_id Attachment ID.
		 * @param string[] $files         Source files (main file, sizes, original upload).
		 */
		do_action( 'pixelweb_delete_generated', $attachment_id, $files );

		foreach ( $files as $file ) {
			$candidates = array( self::webp_path( $file ), $file . '.webp' );
			// Version 1.0.0 wrote "photo.webp" next to "photo.jpg". Never delete a real media file.
			$info = pathinfo( $file );
			if ( isset( $info['extension'] ) && 'webp' !== strtolower( $info['extension'] ) ) {
				$old = $info['dirname'] . '/' . $info['filename'] . '.webp';
				if ( file_exists( $old ) && ! self::is_media_file( $old ) ) {
					$candidates[] = $old;
				}
			}
			foreach ( array_filter( $candidates ) as $candidate ) {
				if ( file_exists( $candidate ) ) {
					wp_delete_file( $candidate );
					$deleted++;
				}
			}
		}
		delete_post_meta( $attachment_id, self::META_STATUS );
		delete_post_meta( $attachment_id, self::META_INFO );
		Pixelweb_Stats::delete( $attachment_id );
		return $deleted;
	}

	/**
	 * Is this file an attachment of the media library?
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_media_file( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
		$path    = wp_normalize_path( $path );
		if ( 0 !== strpos( $path, $base . '/' ) ) {
			return true; // Unknown location: be safe.
		}
		$url = untrailingslashit( $uploads['baseurl'] ) . substr( $path, strlen( $base ) );
		return attachment_url_to_postid( $url ) > 0;
	}

	/**
	 * Hook: attachment deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function cleanup_attachment( $attachment_id ) {
		if ( in_array( get_post_mime_type( $attachment_id ), self::$source_mimes, true ) ) {
			$this->delete_generated( $attachment_id );
		}
	}

	/* ---------- Queries ---------- */

	/**
	 * Base query args for convertible images.
	 *
	 * @return array
	 */
	private static function base_query() {
		return array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => self::$source_mimes,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
	}

	/**
	 * Count images: all, or pending (never processed).
	 *
	 * @param string $state all|pending.
	 * @return int
	 */
	public static function count_images( $state = 'all' ) {
		$args                   = self::base_query();
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;
		if ( 'pending' === $state ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_STATUS,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * IDs waiting for optimization.
	 *
	 * @param int $limit How many.
	 * @return int[]
	 */
	public static function pending_ids( $limit ) {
		$args                   = self::base_query();
		$args['posts_per_page'] = max( 1, (int) $limit );
		$args['orderby']        = 'ID';
		$args['order']          = 'DESC';
		$args['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => self::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
		);
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * IDs that have a status (used to delete generated files in batches).
	 *
	 * @param int $limit How many.
	 * @return int[]
	 */
	public static function processed_ids( $limit ) {
		$args                   = self::base_query();
		$args['posts_per_page'] = max( 1, (int) $limit );
		$args['meta_query']     = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'     => self::META_STATUS,
				'compare' => 'EXISTS',
			),
		);
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Forget every status so the bulk tool processes everything again.
	 * Existing converted files are reused unless they are older than the source.
	 */
	public static function reset_statuses() {
		delete_post_meta_by_key( self::META_STATUS );
		delete_post_meta_by_key( self::META_INFO );
		wp_cache_delete( 'dashboard', 'pixelweb' );
	}

	/**
	 * Animated GIFs are left alone (converting would drop the animation).
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function is_animated_gif( $file ) {
		$contents = file_get_contents( $file, false, null, 0, 2 * MB_IN_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return false;
		}
		return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $contents ) > 1;
	}
}
