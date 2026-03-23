<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta Conversions API Handler Class
 */
class Meta_Api {

	/**
	 * Send the Purchase event to Meta CAPI.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 * @return bool True if successful, false otherwise.
	 */
	public function send_purchase_event( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			Logger::log( "Order #{$order_id} not found." );
			return false;
		}

		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$access_token = get_option( 'wfbt_access_token', '' );

		if ( empty( $pixel_id ) || empty( $access_token ) ) {
			Logger::log( "Pixel ID or Access Token is missing. Aborting." );
			update_post_meta( $order_id, '_wfbt_capi_status', 'Failed' );
			update_post_meta( $order_id, '_wfbt_capi_error', 'Missing Config' );
			return false;
		}

		$payload = $this->build_payload( $order );
		$url     = "https://graph.facebook.com/v19.0/{$pixel_id}/events";

		$args = array(
			'method'  => 'POST',
			'timeout' => 45,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( 
				array( 
					'data'         => array( $payload ),
					'access_token' => $access_token,
				) 
			),
		);

		// Add Test Event Code if present.
		$test_code = get_option( 'wfbt_test_code', '' );
		if ( ! empty( $test_code ) ) {
			$body = json_decode( $args['body'], true );
			$body['test_event_code'] = $test_code;
			$args['body'] = wp_json_encode( $body );
		}

		Logger::log( "Sending payload for Order #{$order_id}: \n" . print_r( $args['body'], true ) );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->handle_error( $order_id, 'WP_Error: ' . $error_message );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_json   = wp_remote_retrieve_body( $response );

		Logger::log( "Response for Order #{$order_id} (Status: {$status_code}): \n" . print_r( $body_json, true ) );

		if ( 200 !== $status_code ) {
			$this->handle_error( $order_id, "HTTP {$status_code}: {$body_json}" );
			return false;
		}

		// Success.
		update_post_meta( $order_id, '_wfbt_capi_status', 'Success' );
		delete_post_meta( $order_id, '_wfbt_capi_error' );

		return true;
	}

	/**
	 * Build the Meta CAPI payload for a Purchase event.
	 *
	 * @param \WC_Order $order The WooCommerce Order.
	 * @return array The event data array.
	 */
	private function build_payload( $order ) {
		$user_data   = $this->extract_user_data( $order );
		$custom_data = $this->extract_custom_data( $order );

		$event_time = $order->get_date_created() ? $order->get_date_created()->getOffsetTimestamp() : time();

		$payload = array(
			'event_name'    => 'Purchase',
			'event_time'    => $event_time,
			'action_source' => 'website',
			'user_data'     => $user_data,
			'custom_data'   => $custom_data,
			'event_id'      => 'order_' . $order->get_id(), // Deduplication key.
		);

		// Client IP and User Agent could be retrieved from WC logs or meta if saved during checkout.
		$ip_address = $order->get_customer_ip_address();
		if ( $ip_address ) {
			$payload['user_data']['client_ip_address'] = $ip_address;
		}
		
		$user_agent = $order->get_customer_user_agent();
		if ( $user_agent ) {
			$payload['user_data']['client_user_agent'] = $user_agent;
		}

		return $payload;
	}

	/**
	 * Extracts and hashes user data (PII).
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array The hashed user data.
	 */
	private function extract_user_data( $order ) {
		$user_data = array();

		$email = $order->get_billing_email();
		if ( $email ) {
			$user_data['em'] = array( $this->hash_data( $email ) );
		}

		$phone = $order->get_billing_phone();
		if ( $phone ) {
			$user_data['ph'] = array( $this->hash_data( $phone ) );
		}

		$first_name = $order->get_billing_first_name();
		if ( $first_name ) {
			$user_data['fn'] = array( $this->hash_data( $first_name ) );
		}

		$last_name = $order->get_billing_last_name();
		if ( $last_name ) {
			$user_data['ln'] = array( $this->hash_data( $last_name ) );
		}

		$city = $order->get_billing_city();
		if ( $city ) {
			$user_data['ct'] = array( $this->hash_data( $city ) );
		}

		$state = $order->get_billing_state();
		if ( $state ) {
			$user_data['st'] = array( $this->hash_data( $state ) );
		}

		$postcode = $order->get_billing_postcode();
		if ( $postcode ) {
			$user_data['zp'] = array( $this->hash_data( $postcode ) );
		}

		$country = $order->get_billing_country();
		if ( $country ) {
			$user_data['country'] = array( $this->hash_data( $country ) );
		}

		return $user_data;
	}

	/**
	 * Extracts custom data like value, currency, and contents.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array The custom data.
	 */
	private function extract_custom_data( $order ) {
		$contents = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$contents[] = array(
					'id'       => $product->get_sku() ? $product->get_sku() : $product->get_id(),
					'quantity' => $item->get_quantity(),
					'item_price' => $order->get_item_total( $item, false, false ),
				);
			}
		}

		return array(
			'currency' => $order->get_currency(),
			'value'    => $order->get_total(),
			'contents' => $contents,
		);
	}

	/**
	 * Normalizes and hashes data using SHA-256.
	 *
	 * @param string $string Raw string.
	 * @return string Hashed string.
	 */
	private function hash_data( $string ) {
		$normalized = strtolower( trim( $string ) );
		return hash( 'sha256', $normalized );
	}

	/**
	 * Handle API failures, log them, update the order, and send email alerts if enabled.
	 *
	 * @param int    $order_id The Order ID.
	 * @param string $error    Error details.
	 */
	private function handle_error( $order_id, $error ) {
		Logger::log( "Error sending order #{$order_id} to CAPI: {$error}", 'error' );

		update_post_meta( $order_id, '_wfbt_capi_status', 'Failed' );
		update_post_meta( $order_id, '_wfbt_capi_error', $error );

		$enable_alerts = get_option( 'wfbt_enable_alerts', 'no' );
		$alert_email   = get_option( 'wfbt_alert_email', '' );

		if ( 'yes' === $enable_alerts && is_email( $alert_email ) ) {
			$subject = "Meta CAPI Error for Order #{$order_id}";
			$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$message  = "Hello,\n\n";
			$message .= "The Meta Conversions API request failed for WooCommerce Order #{$order_id}.\n\n";
			$message .= "Error Details:\n{$error}\n\n";
			$message .= "View Order: {$edit_url}\n\n";
			$message .= "Regards,\nWoo FB Tracking Server-Side";

			wp_mail( $alert_email, $subject, $message );
		}
	}
}
