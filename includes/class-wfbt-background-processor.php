<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background Processor Class
 * Uses WooCommerce Action Scheduler to queue requests.
 */
class Background_Processor {

	/**
	 * Hook used for the Action Scheduler job (orders).
	 */
	const ACTION_HOOK = 'wfbt_send_capi_event';

	/**
	 * Hook used for generic CAPI payloads (AddToCart, InitiateCheckout).
	 */
	const ACTION_PAYLOAD_HOOK = 'wfbt_send_capi_payload';

	/**
	 * Group used in Action Scheduler.
	 */
	const ACTION_GROUP = 'wfbt_capi';

	/**
	 * Initialize the action scheduler hooks.
	 */
	public static function init() {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'process_capi_event' ), 10, 1 );
		add_action( self::ACTION_PAYLOAD_HOOK, array( __CLASS__, 'process_capi_payload' ), 10, 1 );
	}

	/**
	 * Schedules an action to send the Meta CAPI event for an order.
	 *
	 * @param int $order_id The WooCommerce Order ID.
	 */
	public static function schedule_event( $order_id ) {
		if ( false === as_next_scheduled_action( self::ACTION_HOOK, array( 'order_id' => $order_id ), self::ACTION_GROUP ) ) {
			as_enqueue_async_action(
				self::ACTION_HOOK,
				array( 'order_id' => $order_id ),
				self::ACTION_GROUP
			);
			
			// Log that the action was scheduled.
			Logger::log( "Scheduled CAPI async event for Order #{$order_id}" );
		}
	}

	/**
	 * Process the CAPI event when triggered by Action Scheduler (orders).
	 *
	 * @param int $order_id The WooCommerce Order ID.
	 */
	public static function process_capi_event( $order_id ) {
		// Log start.
		Logger::log( "Processing CAPI event for Order #{$order_id} via Action Scheduler" );
		
		$api = new Meta_Api();
		$api->send_purchase_event( $order_id );
	}

	/**
	 * Schedule a generic CAPI payload (AddToCart, InitiateCheckout, etc.) via Action Scheduler.
	 * Falls back to synchronous/direct send if Action Scheduler is not loaded.
	 *
	 * @param array $payload Event payload.
	 */
	public static function schedule_payload( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['event_name'] ) ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::ACTION_PAYLOAD_HOOK,
				array( 'payload' => $payload ),
				self::ACTION_GROUP
			);
			Logger::log( sprintf( 'Scheduled CAPI async event [%s] (ID: %s)', $payload['event_name'], isset( $payload['event_id'] ) ? $payload['event_id'] : 'n/a' ) );
		} else {
			$api = new Meta_Api();
			$api->send_capi_payload( $payload );
		}
	}

	/**
	 * Process a generic CAPI payload triggered by Action Scheduler.
	 *
	 * @param array $payload Event payload.
	 */
	public static function process_capi_payload( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['event_name'] ) ) {
			return;
		}
		$api = new Meta_Api();
		$api->send_capi_payload( $payload );
	}
}

// Initialize the background processor hooks.
Background_Processor::init();
