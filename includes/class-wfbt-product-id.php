<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Content ID Resolver.
 *
 * Single source of truth for the `content_ids` / `contents[].id` values sent to Meta
 * (Pixel + CAPI). These IDs MUST strictly match the "ID" column of the Meta catalog,
 * otherwise Meta reports 0% catalog match rate and Advantage+ catalog ads cannot work.
 *
 * Inter-plugin contract with Woo Meta Catalog Feed SOYOO (catalog = source of truth):
 * - In "auto" mode, the ID is requested through the filter `soyoo_meta_catalog_content_id`
 *   (the feed plugin returns the exact <g:id> it writes in the XML).
 * - If the feed plugin is active but does not implement the filter yet (<= v1.2.0),
 *   its historical logic (SKU, fallback to Product ID) is applied.
 */
class Product_Id {

	/**
	 * Option name storing the selected format.
	 */
	const OPTION = 'wfbt_content_id_format';

	/**
	 * Filter exposed by Woo Meta Catalog Feed SOYOO to resolve the catalog <g:id>.
	 */
	const FEED_FILTER = 'soyoo_meta_catalog_content_id';

	/**
	 * Maximum number of variation IDs sent with a ViewContent on a variable product.
	 */
	const MAX_VARIATION_IDS = 50;

	/**
	 * Supported formats with admin labels.
	 *
	 * @return array
	 */
	public static function get_formats() {
		return array(
			'auto'  => __( 'Automatic — aligned with Woo Meta Catalog Feed SOYOO (recommended, Product ID fallback if not installed)', 'wfbt-server-side' ),
			'id'    => __( 'WooCommerce Product ID (e.g. 1234)', 'wfbt-server-side' ),
			'sku'   => __( 'SKU (fallback to Product ID)', 'wfbt-server-side' ),
			'gla'   => __( 'Google for WooCommerce / Google Listings & Ads (e.g. gla_1234)', 'wfbt-server-side' ),
			'fb_wc' => __( 'Facebook for WooCommerce (e.g. SKU_1234 or wc_post_id_1234)', 'wfbt-server-side' ),
		);
	}

	/**
	 * Selected format (as saved in settings).
	 *
	 * @return string
	 */
	public static function get_format() {
		$format = get_option( self::OPTION, 'auto' );
		return array_key_exists( $format, self::get_formats() ) ? $format : 'auto';
	}

	/**
	 * Is Woo Meta Catalog Feed SOYOO active on this site?
	 *
	 * @return bool
	 */
	public static function is_feed_plugin_active() {
		return defined( 'WOO_META_CATALOG_FEED_VERSION' ) || class_exists( '\SOYOO\MetaCatalog\Feed_Item' );
	}

	/**
	 * Does the feed plugin implement the shared ID contract (filter)?
	 *
	 * @return bool
	 */
	public static function feed_exposes_contract() {
		return self::is_feed_plugin_active() && has_filter( self::FEED_FILTER );
	}

	/**
	 * Feed plugin version, if active.
	 *
	 * @return string
	 */
	public static function get_feed_version() {
		return defined( 'WOO_META_CATALOG_FEED_VERSION' ) ? (string) WOO_META_CATALOG_FEED_VERSION : '';
	}

	/**
	 * Effective strategy actually applied ('feed', 'sku', 'id', 'gla', 'fb_wc').
	 * Used by the JS fallback formatter and the admin status box.
	 *
	 * @return string
	 */
	public static function get_effective_format() {
		$format = self::get_format();
		if ( 'auto' !== $format ) {
			return $format;
		}
		return self::feed_exposes_contract() ? 'feed' : 'id';
	}

	/**
	 * Resolve the Meta content ID for a product or variation.
	 *
	 * @param \WC_Product|int $product Product object or ID.
	 * @return string
	 */
	public static function get( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		$content_id = '';
		$format     = self::get_format();

		// 1. Auto mode: ask the catalog feed plugin for its exact <g:id>.
		if ( 'auto' === $format && self::feed_exposes_contract() ) {
			$feed_id = apply_filters( self::FEED_FILTER, null, $product );
			if ( is_scalar( $feed_id ) && '' !== (string) $feed_id ) {
				$content_id = (string) $feed_id;
			}
		}

		// 2. Static formats (and auto fallback = Product ID).
		if ( '' === $content_id ) {
			$content_id = self::format_static( $product, 'auto' === $format ? 'id' : $format );
		}

		/**
		 * Filter the Meta content ID of a product (custom catalog formats).
		 *
		 * @param string      $content_id Resolved content ID.
		 * @param \WC_Product $product    Product or variation.
		 */
		return (string) apply_filters( 'wfbt_content_id', $content_id, $product );
	}

	/**
	 * ID written by the catalog feed plugin for this product (reference for alignment checks).
	 *
	 * @param \WC_Product $product Product.
	 * @return string Empty if the feed plugin is not active.
	 */
	public static function get_feed_reference_id( $product ) {
		if ( ! self::is_feed_plugin_active() || ! $product instanceof \WC_Product ) {
			return '';
		}
		if ( self::feed_exposes_contract() ) {
			$feed_id = apply_filters( self::FEED_FILTER, null, $product );
			if ( is_scalar( $feed_id ) && '' !== (string) $feed_id ) {
				return (string) $feed_id;
			}
		}
		// Feed <= v1.2.0 historical logic.
		return self::format_static( $product, 'sku' );
	}

	/**
	 * Apply a static ID format.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $format  Format key.
	 * @return string
	 */
	private static function format_static( $product, $format ) {
		$id  = (string) $product->get_id();
		$sku = (string) $product->get_sku();

		switch ( $format ) {
			case 'id':
				return $id;
			case 'gla':
				return 'gla_' . $id;
			case 'fb_wc':
				return '' !== $sku ? $sku . '_' . $id : 'wc_post_id_' . $id;
			case 'sku':
			default:
				return '' !== $sku ? $sku : $id;
		}
	}

	/**
	 * Resolve the IDs to send on a product page view.
	 * Variable products are listed in the catalog as variations (parent is not an item),
	 * so we send the variation IDs to guarantee the catalog match.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public static function get_view_ids( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		if ( $product->is_type( 'variable' ) ) {
			$ids      = array();
			$children = array_slice( $product->get_visible_children(), 0, self::MAX_VARIATION_IDS );
			foreach ( $children as $child_id ) {
				$cid = self::get( $child_id );
				if ( '' !== $cid ) {
					$ids[] = $cid;
				}
			}
			if ( ! empty( $ids ) ) {
				return array_values( array_unique( $ids ) );
			}
		}

		$cid = self::get( $product );
		return '' !== $cid ? array( $cid ) : array();
	}

	/**
	 * Resolve the recommended content_type for ViewContent.
	 * Variable products with active variation IDs use 'product' as the variations
	 * are individual items in the Meta catalog. Falls back to 'product_group'.
	 *
	 * @param \WC_Product $product Product.
	 * @return string 'product' or 'product_group'.
	 */
	public static function get_view_content_type( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return 'product';
		}
		if ( $product->is_type( 'variable' ) ) {
			$ids = self::get_view_ids( $product );
			return empty( $ids ) ? 'product_group' : 'product';
		}
		return 'product';
	}
}

