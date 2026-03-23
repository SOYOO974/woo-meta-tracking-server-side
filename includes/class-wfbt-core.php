<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Controller Class
 */
class Core {

	/**
	 * Single instance of the class.
	 *
	 * @var Core
	 */
	private static $instance = null;

	/**
	 * Main Instance.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize WordPress and WooCommerce hooks.
	 */
	private function init_hooks() {
		// Initialize the admin settings interface.
		if ( is_admin() ) {
			Admin_Settings::init();
		}

		// Hook to handle when an order is paid.
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_send_capi_event' ), 10, 1 );
		
		// Hook to handle when an order status changes to processing or completed if payment bypassing happens.
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_send_capi_event' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_send_capi_event' ), 10, 1 );
	}

	/**
	 * Schedule the CAPI event sending job for a specific order.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function maybe_send_capi_event( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		// Ensure we don't schedule multiple jobs for the same order during different status transitions.
		$is_scheduled = get_post_meta( $order_id, '_wfbt_capi_scheduled', true );
		if ( 'yes' === $is_scheduled ) {
			return;
		}

		// Schedule the action.
		Background_Processor::schedule_event( $order_id );

		// Mark order as scheduled to prevent duplication.
		update_post_meta( $order_id, '_wfbt_capi_scheduled', 'yes' );
	}
}
