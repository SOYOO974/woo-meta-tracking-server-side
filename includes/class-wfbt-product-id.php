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
 */
class Product_Id {

	/**
	 * Option name storing the selected format.
	 */
	const OPTION = 'wfbt_content_id_format';

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
			'sku'   => __( 'SKU (fallback to Product ID) — default, same as Woo Merchant Sync SOYOO', 'wfbt-server-side' ),
			'id'    => __( 'WooCommerce Product ID (e.g. 1234)', 'wfbt-server-side' ),
			'gla'   => __( 'Google for WooCommerce / Google Listings & Ads (e.g. gla_1234)', 'wfbt-server-side' ),
			'fb_wc' => __( 'Facebook for WooCommerce (e.g. SKU_1234 or wc_post_id_1234)', 'wfbt-server-side' ),
		);
	}

	/**
	 * Current format.
	 *
	 * @return string
	 */
	public static function get_format() {
		$format = get_option( self::OPTION, 'sku' );
		return array_key_exists( $format, self::get_formats() ) ? $format : 'sku';
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

		$id  = (string) $product->get_id();
		$sku = (string) $product->get_sku();

		switch ( self::get_format() ) {
			case 'id':
				$content_id = $id;
				break;
			case 'gla':
				$content_id = 'gla_' . $id;
				break;
			case 'fb_wc':
				$content_id = '' !== $sku ? $sku . '_' . $id : 'wc_post_id_' . $id;
				break;
			case 'sku':
			default:
				$content_id = '' !== $sku ? $sku : $id;
				break;
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
}
