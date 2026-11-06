<?php
/**
 * Native lazy loading with LCP protection. No JavaScript, SEO-safe.
 *
 * @package Pixelweb_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds loading="lazy" + decoding="async" to images WordPress missed
 * (for example images without width/height) and never lazy-loads the
 * first N images of the page, which usually hold the LCP element.
 */
class Pixelweb_LazyLoad {

	/**
	 * Images seen on this page.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Hook into WordPress.
	 */
	public function init() {
		if ( ! Pixelweb_Settings::get( 'lazy_load' ) || is_admin() ) {
			return;
		}
		add_filter( 'wp_omit_loading_attr_threshold', array( $this, 'threshold' ) );
		foreach ( array( 'the_content', 'post_thumbnail_html', 'widget_text', 'widget_block_content' ) as $filter ) {
			add_filter( $filter, array( $this, 'filter_html' ), 98 );
		}
	}

	/**
	 * Align WordPress core's own threshold with ours.
	 *
	 * @return int
	 */
	public function threshold() {
		return (int) Pixelweb_Settings::get( 'lazy_skip' );
	}

	/**
	 * Process HTML.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function filter_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $html;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return $html;
		}
		$result = preg_replace_callback(
			'#<(noscript|script|style|textarea)\b.*?</\1\s*>|<img\b[^>]*>#is',
			array( $this, 'replace_match' ),
			$html
		);
		return null === $result ? $html : $result;
	}

	/**
	 * Regex callback.
	 *
	 * @param array $m Match.
	 * @return string
	 */
	public function replace_match( $m ) {
		if ( ! empty( $m[1] ) ) {
			return $m[0];
		}
		$tag = $m[0];
		$this->count++;

		foreach ( Pixelweb_Settings::exclusions() as $needle ) {
			if ( '' !== $needle && false !== stripos( $tag, $needle ) ) {
				return $tag;
			}
		}
		foreach ( array( 'skip-lazy', 'no-lazy', 'data-src=' ) as $needle ) {
			if ( false !== stripos( $tag, $needle ) ) {
				return $tag;
			}
		}

		if ( '' === Pixelweb_Delivery::attr( $tag, 'decoding' ) ) {
			$tag = preg_replace( '/^<img\b/i', '<img decoding="async"', $tag );
		}

		$is_hero = $this->count <= (int) Pixelweb_Settings::get( 'lazy_skip' )
			|| 'high' === strtolower( Pixelweb_Delivery::attr( $tag, 'fetchpriority' ) );

		if ( '' === Pixelweb_Delivery::attr( $tag, 'loading' ) && ! $is_hero ) {
			$tag = preg_replace( '/^<img\b/i', '<img loading="lazy"', $tag );
		}
		return $tag;
	}
}
