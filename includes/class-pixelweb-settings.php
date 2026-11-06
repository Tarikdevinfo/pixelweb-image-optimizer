<?php
/**
 * Settings storage (one array option).
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for defaults, reading and sanitizing settings.
 */
class Pixelweb_Settings {

	const OPTION = 'pixelweb_settings';

	/**
	 * Key used by version 1.1.0 for the exclusion list (renamed to "exclusions").
	 */
	const LEGACY_EXCLUSIONS_KEY = 'exclude';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'auto_convert'           => 1,
			'quality'                => 82,
			'resize_uploads'         => 1,
			'max_dimension'          => 2560,
			'delivery_mode'          => 'picture', // picture | rewrite | off.
			'lazy_load'              => 1,
			'lazy_skip'              => 3,
			'seo_column'             => 1,
			'seo_filenames'          => 1,
			'exclusions'             => '',
			'uninstall_delete_files' => 0,
		);
	}

	/**
	 * Create the option on activation, migrating 1.0.0 individual options.
	 */
	public static function add_defaults() {
		if ( false !== get_option( self::OPTION ) ) {
			return;
		}
		$settings = self::defaults();

		$legacy_map = array(
			'pixelweb_auto_convert'  => 'auto_convert',
			'pixelweb_quality'       => 'quality',
			'pixelweb_resize_uploads' => 'resize_uploads',
			'pixelweb_lazy_load'     => 'lazy_load',
			'pixelweb_seo_alt_check' => 'seo_column',
		);
		foreach ( $legacy_map as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value ) {
				$settings[ $new ] = (int) $value;
				delete_option( $old );
			}
		}
		$max_w = get_option( 'pixelweb_max_width', null );
		$max_h = get_option( 'pixelweb_max_height', null );
		if ( null !== $max_w || null !== $max_h ) {
			$settings['max_dimension'] = max( (int) $max_w, (int) $max_h, 100 );
		}
		if ( '0' === (string) get_option( 'pixelweb_serve_format', '1' ) ) {
			$settings['delivery_mode'] = 'off';
		}
		foreach ( array( 'pixelweb_max_width', 'pixelweb_max_height', 'pixelweb_serve_format', 'pixelweb_format', 'pixelweb_quality_jpeg', 'pixelweb_quality_png', 'pixelweb_quality_gif' ) as $old ) {
			delete_option( $old );
		}

		add_option( self::OPTION, $settings );
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = self::migrate( is_array( $stored ) ? $stored : array() );
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Rename keys saved by older versions (1.1.0 stored the exclusion list as "exclude").
	 *
	 * @param array $stored Stored settings.
	 * @return array
	 */
	private static function migrate( $stored ) {
		if ( array_key_exists( self::LEGACY_EXCLUSIONS_KEY, $stored ) ) {
			if ( ! isset( $stored['exclusions'] ) ) {
				$stored['exclusions'] = (string) $stored[ self::LEGACY_EXCLUSIONS_KEY ];
			}
			unset( $stored[ self::LEGACY_EXCLUSIONS_KEY ] );
		}
		return $stored;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Validate submitted values (already unslashed) into a full settings array.
	 *
	 * @param array $input Raw values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$d   = self::defaults();
		$out = array();

		foreach ( array( 'auto_convert', 'resize_uploads', 'lazy_load', 'seo_column', 'seo_filenames', 'uninstall_delete_files' ) as $bool ) {
			$out[ $bool ] = ! empty( $input[ $bool ] ) ? 1 : 0;
		}

		$out['quality']       = isset( $input['quality'] ) ? min( 100, max( 30, absint( $input['quality'] ) ) ) : $d['quality'];
		$out['max_dimension'] = isset( $input['max_dimension'] ) ? min( 10000, max( 320, absint( $input['max_dimension'] ) ) ) : $d['max_dimension'];
		$out['lazy_skip']     = isset( $input['lazy_skip'] ) ? min( 20, absint( $input['lazy_skip'] ) ) : $d['lazy_skip'];

		$mode                 = isset( $input['delivery_mode'] ) ? sanitize_key( $input['delivery_mode'] ) : $d['delivery_mode'];
		$out['delivery_mode'] = in_array( $mode, array( 'picture', 'rewrite', 'off' ), true ) ? $mode : $d['delivery_mode'];

		$exclusions        = isset( $input['exclusions'] ) ? sanitize_textarea_field( (string) $input['exclusions'] ) : '';
		$lines             = array_filter( array_map( 'trim', explode( "\n", $exclusions ) ) );
		$out['exclusions'] = implode( "\n", array_slice( $lines, 0, 100 ) );

		return $out;
	}

	/**
	 * Save a sanitized array.
	 *
	 * @param array $settings Sanitized settings.
	 */
	public static function save( $settings ) {
		update_option( self::OPTION, $settings );
		self::$cache = null;
	}

	/**
	 * Exclusion patterns (substring match against image URL or class).
	 *
	 * @return string[]
	 */
	public static function exclusions() {
		$list = array_filter( array_map( 'trim', explode( "\n", (string) self::get( 'exclusions' ) ) ) );
		$list[] = 'no-pixelweb';
		/**
		 * Filter the list of substrings that exclude an image from processing.
		 *
		 * @param string[] $list Substrings matched against the &lt;img&gt; tag.
		 */
		return apply_filters( 'pixelweb_exclusions', $list );
	}
}
