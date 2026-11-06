<?php
/**
 * Front-end delivery of converted images.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two modes:
 * - picture (default): wraps <img> in <picture> with <source type="image/webp">.
 *   WebP files live in uploads/pixelweb-image-optimizer/ (see Pixelweb_Converter).
 *   The browser picks the format, so it is safe with every page cache and CDN.
 * - rewrite: swaps .jpg/.png URLs for their .webp copy. Use it when a theme's
 *   CSS breaks with <picture>. WebP works in every current browser.
 */
class Pixelweb_Delivery {

	/**
	 * Converter.
	 *
	 * @var Pixelweb_Converter
	 */
	private $converter;

	/**
	 * URL => WebP URL|false lookups for this request.
	 *
	 * @var array
	 */
	private $lookup = array();

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
	 * Hook into WordPress.
	 */
	public function init() {
		$mode = Pixelweb_Settings::get( 'delivery_mode' );
		if ( 'off' === $mode || is_admin() ) {
			return;
		}

		$html_filters = array(
			'the_content',
			'post_thumbnail_html',
			'widget_text',
			'widget_block_content',
			'wp_get_attachment_image',
			'woocommerce_single_product_image_thumbnail_html',
		);
		// Block theme templates: images get their srcset in wp_filter_content_tags(), then this filter runs.
		add_filter( 'wp_content_img_tag', array( $this, 'filter_template_img' ), 99, 2 );
		/**
		 * Filter the hooks whose HTML gets processed.
		 *
		 * @param string[] $html_filters Filter names receiving HTML as first argument.
		 */
		foreach ( (array) apply_filters( 'pixelweb_delivery_filters', $html_filters ) as $filter ) {
			add_filter( $filter, array( $this, 'filter_html' ), 99 );
		}

		if ( 'rewrite' === $mode ) {
			add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 99 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 99 );
		} else {
			add_action( 'wp_enqueue_scripts', array( $this, 'picture_css' ) );
		}
	}

	/**
	 * Keep <picture> invisible to layouts (flex/grid behave as if <img> were alone).
	 */
	public function picture_css() {
		wp_register_style( 'pixelweb-picture', false, array(), PIXELWEB_VERSION );
		wp_enqueue_style( 'pixelweb-picture' );
		wp_add_inline_style( 'pixelweb-picture', 'picture.pixelweb-picture{display:contents}' );
	}

	/**
	 * Should this request be processed?
	 *
	 * @return bool
	 */
	private function active() {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}
		return (bool) apply_filters( 'pixelweb_delivery_active', true );
	}

	/**
	 * Process an HTML fragment.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function filter_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) || ! $this->active() ) {
			return $html;
		}
		$result = preg_replace_callback(
			'#<(picture|noscript|script|style|textarea|svg)\b.*?</\1\s*>|<img\b[^>]*>#is',
			array( $this, 'replace_match' ),
			$html
		);
		return null === $result ? $html : $result;
	}

	/**
	 * Images in block templates and template parts (post content is handled by filter_html,
	 * which also knows when an image already sits inside a <picture>).
	 *
	 * @param string $tag     Final <img> tag.
	 * @param string $context Where wp_filter_content_tags() runs.
	 * @return string
	 */
	public function filter_template_img( $tag, $context = '' ) {
		if ( 'template' !== $context || ! is_string( $tag ) || ! $this->active() ) {
			return $tag;
		}
		return $this->process_img( $tag );
	}

	/**
	 * Regex callback: leave protected blocks untouched, process <img>.
	 *
	 * @param array $m Match.
	 * @return string
	 */
	public function replace_match( $m ) {
		if ( ! empty( $m[1] ) ) {
			return $m[0];
		}
		return $this->process_img( $m[0] );
	}

	/**
	 * Process one <img> tag.
	 *
	 * @param string $tag Tag.
	 * @return string
	 */
	public function process_img( $tag ) {
		if ( false !== strpos( $tag, 'data-pw' ) ) {
			return $tag;
		}
		foreach ( Pixelweb_Settings::exclusions() as $needle ) {
			if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
				return $tag;
			}
		}

		$src    = self::attr( $tag, 'src' );
		$srcset = self::attr( $tag, 'srcset' );
		if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return $tag;
		}

		if ( 'rewrite' === Pixelweb_Settings::get( 'delivery_mode' ) ) {
			return $this->rewrite_tag( $tag, $src, $srcset );
		}

		$candidates = '' !== $srcset ? self::parse_srcset( $srcset ) : array( array( $src, '' ) );
		$sizes      = self::attr( $tag, 'sizes' );
		$sizes      = trim( preg_replace( '/^\s*auto\s*,?/i', '', $sizes ) );

		$sources = array();
		$parts   = array();
		foreach ( $candidates as $candidate ) {
			$webp = $this->converted_url( $candidate[0] );
			if ( $webp ) {
				$parts[] = trim( $webp . ' ' . $candidate[1] );
			}
		}
		if ( $parts ) {
			$sources[] = array(
				'type'   => 'image/webp',
				'srcset' => implode( ', ', $parts ),
			);
		}

		/**
		 * Filter the <source> elements placed before the <img> inside <picture>.
		 * The browser uses the first source it supports. Add-ons can add their own.
		 *
		 * @param array[] $sources    Each item has 'type' (mime) and 'srcset'.
		 * @param array[] $candidates Image candidates: array( url, descriptor ).
		 * @param string  $tag        Original <img> tag.
		 */
		$sources = (array) apply_filters( 'pixelweb_picture_sources', $sources, $candidates, $tag );

		$html = '';
		foreach ( $sources as $source ) {
			if ( empty( $source['type'] ) || empty( $source['srcset'] ) ) {
				continue;
			}
			$html .= sprintf(
				'<source type="%s" srcset="%s"%s>',
				esc_attr( $source['type'] ),
				esc_attr( $source['srcset'] ),
				( '' !== $sizes && '' !== $srcset ) ? ' sizes="' . esc_attr( $sizes ) . '"' : ''
			);
		}
		$sources = $html;

		if ( '' === $sources ) {
			return $tag;
		}
		// The marker keeps the operation idempotent (the same tag can pass through several filters).
		$tag = preg_replace( '/^<img\b/i', '<img data-pw', $tag );
		return '<picture class="pixelweb-picture">' . $sources . $tag . '</picture>';
	}

	/**
	 * Rewrite mode: replace URLs inside the tag.
	 *
	 * @param string $tag    Tag.
	 * @param string $src    src value.
	 * @param string $srcset srcset value.
	 * @return string
	 */
	private function rewrite_tag( $tag, $src, $srcset ) {
		$new_src = $this->converted_url( $src );
		if ( $new_src ) {
			$tag = self::set_attr( $tag, 'src', $new_src );
		}
		if ( '' !== $srcset ) {
			$parts = array();
			foreach ( self::parse_srcset( $srcset ) as $candidate ) {
				$url     = $this->converted_url( $candidate[0] );
				$parts[] = trim( ( $url ? $url : $candidate[0] ) . ' ' . $candidate[1] );
			}
			$tag = self::set_attr( $tag, 'srcset', implode( ', ', $parts ) );
		}
		return $tag;
	}

	/**
	 * Rewrite mode: wp_get_attachment_image_src.
	 *
	 * @param array|false $image Image data.
	 * @return array|false
	 */
	public function filter_image_src( $image ) {
		if ( is_array( $image ) && ! empty( $image[0] ) && $this->active() ) {
			$url = $this->converted_url( $image[0] );
			if ( $url ) {
				$image[0] = $url;
			}
		}
		return $image;
	}

	/**
	 * Rewrite mode: srcset arrays.
	 *
	 * @param array $sources Sources.
	 * @return array
	 */
	public function filter_srcset( $sources ) {
		if ( ! is_array( $sources ) || ! $this->active() ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$url = $this->converted_url( $source['url'] );
			if ( $url ) {
				$sources[ $width ]['url'] = $url;
			}
		}
		return $sources;
	}

	/**
	 * WebP URL for an image URL, when the WebP file exists.
	 *
	 * @param string $url Original URL.
	 * @return string|false
	 */
	public function converted_url( $url ) {
		if ( isset( $this->lookup[ $url ] ) ) {
			return $this->lookup[ $url ];
		}
		$this->lookup[ $url ] = false;

		$clean = preg_replace( '/[?#].*$/', '', html_entity_decode( (string) $url ) );
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $clean ) ) {
			return false;
		}
		$base = Pixelweb_Converter::output_base_for_url( $clean );
		if ( $base && file_exists( $base['path'] . '.webp' ) && filesize( $base['path'] . '.webp' ) > 0 ) {
			$this->lookup[ $url ] = $base['url'] . '.webp';
		}
		return $this->lookup[ $url ];
	}

	/**
	 * Read an attribute from a tag.
	 *
	 * @param string $tag  Tag.
	 * @param string $name Attribute.
	 * @return string
	 */
	public static function attr( $tag, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * Replace (or add) an attribute value.
	 *
	 * @param string $tag   Tag.
	 * @param string $name  Attribute.
	 * @param string $value Unescaped value.
	 * @return string
	 */
	public static function set_attr( $tag, $name, $value ) {
		$escaped = $name . '="' . esc_attr( $value ) . '"';
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\']).*?\1/is';
		if ( preg_match( $pattern, $tag ) ) {
			return preg_replace_callback(
				$pattern,
				static function () use ( $escaped ) {
					return ' ' . $escaped;
				},
				$tag,
				1
			);
		}
		return preg_replace( '/^<img\b/i', '<img ' . $escaped, $tag );
	}

	/**
	 * Split a srcset into [url, descriptor] pairs.
	 *
	 * @param string $srcset Srcset.
	 * @return array
	 */
	public static function parse_srcset( $srcset ) {
		$out = array();
		foreach ( preg_split( '/,\s+/', trim( $srcset ) ) as $item ) {
			$bits = preg_split( '/\s+/', trim( $item ), 2 );
			if ( ! empty( $bits[0] ) ) {
				$out[] = array( $bits[0], isset( $bits[1] ) ? $bits[1] : '' );
			}
		}
		return $out;
	}
}
