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

		// Hook to handle payment completion.
		add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 10, 1 );

		// Hook to handle order status changes dynamically according to settings.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
	}

	/**
	 * Handle payment complete hook.
	 *
	 * @param int $order_id
	 */
	public function on_payment_complete( $order_id ) {
		$this->maybe_send_capi_event( $order_id );
	}

	/**
	 * Handle order status change hook.
	 *
	 * @param int       $order_id
	 * @param string    $old_status
	 * @param string    $new_status
	 * @param \WC_Order $order
	 */
	public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		$trigger_statuses = get_option( 'wfbt_trigger_statuses', array( 'processing', 'completed' ) );
		if ( ! is_array( $trigger_statuses ) ) {
			$trigger_statuses = array( 'processing', 'completed' );
		}

		if ( in_array( $new_status, $trigger_statuses, true ) ) {
			$this->maybe_send_capi_event( $order_id );
		}
	}

	/**
	 * Schedule the CAPI event sending job for a specific order.
	 * HPOS compatible: uses WC_Order CRUD methods.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 */
	public function maybe_send_capi_event( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Ensure we don't schedule multiple jobs for the same order during different status transitions.
		$is_scheduled = $order->get_meta( '_wfbt_capi_scheduled' );
		$capi_status  = $order->get_meta( '_wfbt_capi_status' );

		if ( 'yes' === $is_scheduled || 'Success' === $capi_status ) {
			return;
		}

		// Schedule the action in Action Scheduler.
		Background_Processor::schedule_event( $order_id );

		// Mark order as scheduled to prevent duplication.
		$order->update_meta_data( '_wfbt_capi_scheduled', 'yes' );
		$order->save();
	}
}
