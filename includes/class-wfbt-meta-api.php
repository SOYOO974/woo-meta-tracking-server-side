<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta Conversions API (CAPI) Handler Class
 * Compatible with Graph API v21.0 and WooCommerce HPOS.
 */
class Meta_Api {

	/**
	 * Meta Graph API version.
	 */
	const API_VERSION = 'v21.0';

	/**
	 * Send the Purchase event to Meta CAPI.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 * @return bool True if successful or intentionally skipped, false on error.
	 */
	public function send_purchase_event( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			Logger::log( "Order #{$order_id} not found." );
			return false;
		}

		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$access_token = get_option( 'wfbt_access_token', '' );

		if ( empty( $pixel_id ) || empty( $access_token ) ) {
			Logger::log( "Pixel ID or Access Token is missing. Aborting for Order #{$order_id}." );
			$order->update_meta_data( '_wfbt_capi_status', 'Failed' );
			$order->update_meta_data( '_wfbt_capi_error', 'Missing Meta Configuration (Pixel ID or Access Token)' );
			$order->save();
			return false;
		}

		// GDPR Cookie Consent Check (Native Woo Gads & Concord)
		$respect_consent = get_option( 'wfbt_respect_consent', 'yes' );
		$consent         = $order->get_meta( '_wfbt_consent' );

		if ( 'yes' === $respect_consent && 'denied' === $consent ) {
			Logger::log( "Order #{$order_id}: Marketing consent was refused by customer. CAPI transmission cancelled (GDPR compliance)." );
			$order->update_meta_data( '_wfbt_capi_status', 'Ignored (Consent Denied)' );
			$order->delete_meta_data( '_wfbt_capi_error' );
			$order->save();
			return true;
		}

		$payload   = $this->build_payload( $order );
		$user_data = isset( $payload['user_data'] ) ? $payload['user_data'] : array();

		// Pre-flight guard: Meta CAPI strictly requires at least one direct customer matching identifier.
		// Sending an event without email, phone, fbp, fbc, or external_id causes Meta to reject with HTTP 400 (subcode 2804050).
		$has_identifier = ! empty( $user_data['em'] ) || ! empty( $user_data['ph'] ) || ! empty( $user_data['fbp'] ) || ! empty( $user_data['fbc'] ) || ! empty( $user_data['external_id'] );

		if ( ! $has_identifier ) {
			Logger::log( "Order #{$order_id}: Insufficient customer information parameters (missing email, phone, fbp, fbc, external_id). CAPI transmission safely skipped to prevent Meta rejection (subcode 2804050).", 'warning' );
			$order->update_meta_data( '_wfbt_capi_status', 'Ignored (Insufficient Customer Data)' );
			$order->delete_meta_data( '_wfbt_capi_error' );
			$order->save();
			return true;
		}

		$url = sprintf( 'https://graph.facebook.com/%s/%s/events', self::API_VERSION, rawurlencode( $pixel_id ) );

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

		Logger::log( "Sending Meta CAPI payload for Order #{$order_id} (API " . self::API_VERSION . "):\n" . print_r( $args['body'], true ) );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$this->handle_error( $order, 'WP_Error: ' . $error_message );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_json   = wp_remote_retrieve_body( $response );

		Logger::log( "Response for Order #{$order_id} (Status: {$status_code}):\n" . print_r( $body_json, true ) );

		if ( 200 !== $status_code ) {
			$this->handle_error( $order, "HTTP {$status_code}: {$body_json}" );
			return false;
		}

		// Success: update order HPOS metadata
		$order->update_meta_data( '_wfbt_capi_status', 'Success' );
		$order->update_meta_data( '_wfbt_capi_sent_at', current_time( 'mysql' ) );
		$order->delete_meta_data( '_wfbt_capi_error' );
		$order->save();

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

		$event_time = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();

		// Meta CAPI requires event_time to be within the last 7 days.
		$seven_days_ago = time() - ( 7 * 86400 ) + 3600;
		if ( $event_time < $seven_days_ago ) {
			$event_time = $seven_days_ago;
		}

		$payload = array(
			'event_name'        => 'Purchase',
			'event_time'        => $event_time,
			'action_source'     => 'website',
			'event_source_url'  => $order->get_checkout_order_received_url(),
			'user_data'         => $user_data,
			'custom_data'       => $custom_data,
			'event_id'          => 'order_' . $order->get_id(), // Strict deduplication key matching client-side fbq.
		);

		return $payload;
	}

	/**
	 * Extracts and hashes user data (PII) according to Meta specifications.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return array The user data.
	 */
	private function extract_user_data( $order ) {
		$user_data = array();

		// 1. Email (em)
		$email = $order->get_billing_email();
		if ( $email ) {
			$user_data['em'] = array( $this->hash_data( $email ) );
		}

		// 2. Phone (ph) formatted with E.164 normalization
		$phone        = $order->get_billing_phone();
		$country_code = $order->get_billing_country();
		if ( $phone ) {
			$formatted_phone = $this->format_phone_e164( $phone, $country_code );
			if ( ! empty( $formatted_phone ) ) {
				$user_data['ph'] = array( $this->hash_data( $formatted_phone ) );
			}
		}

		// 3. First Name (fn)
		$first_name = $order->get_billing_first_name();
		if ( $first_name ) {
			$user_data['fn'] = array( $this->hash_data( $first_name ) );
		}

		// 4. Last Name (ln)
		$last_name = $order->get_billing_last_name();
		if ( $last_name ) {
			$user_data['ln'] = array( $this->hash_data( $last_name ) );
		}

		// 5. City (ct)
		$city = $order->get_billing_city();
		if ( $city ) {
			$user_data['ct'] = array( $this->hash_data( $city ) );
		}

		// 6. State / Department (st)
		$state = $order->get_billing_state();
		if ( $state ) {
			$user_data['st'] = array( $this->hash_data( $state ) );
		}

		// 7. Zip / Postal Code (zp)
		$postcode = $order->get_billing_postcode();
		if ( $postcode ) {
			$user_data['zp'] = array( $this->hash_data( $postcode ) );
		}

		// 8. Country (country) - Lowercase two-letter ISO 3166-1 alpha-2 hashed SHA-256
		if ( $country_code ) {
			$user_data['country'] = array( $this->hash_data( $country_code ) );
		}

		// 9. Customer External ID (external_id) - WooCommerce Customer ID to boost Event Match Quality (EMQ)
		$customer_id = $order->get_customer_id();
		if ( $customer_id > 0 ) {
			$user_data['external_id'] = array( (string) $customer_id );
		}

		// 10. Browser ID (_fbp) - Meta expects raw unhashed string
		$fbp = $order->get_meta( '_wfbt_fbp' );
		if ( ! empty( $fbp ) ) {
			$user_data['fbp'] = $fbp;
		}

		// 11. Click ID (_fbc) - Meta expects raw unhashed string
		$fbc = $order->get_meta( '_wfbt_fbc' );
		if ( ! empty( $fbc ) ) {
			$user_data['fbc'] = $fbc;
		}

		// 12. Client IP Address & User Agent
		$ip_address = $order->get_customer_ip_address();
		if ( $ip_address ) {
			$user_data['client_ip_address'] = $ip_address;
		}

		$user_agent = $order->get_customer_user_agent();
		if ( $user_agent ) {
			$user_data['client_user_agent'] = $user_agent;
		}

		return $user_data;
	}

	/**
	 * Formats a phone number to strict E.164 international standard without leading plus sign.
	 * Specific handling for La Réunion (262) and Metropolitan France (33).
	 *
	 * @param string $phone Raw phone string.
	 * @param string $country_code 2-letter ISO country code.
	 * @return string Normalized digits.
	 */
	public function format_phone_e164( $phone, $country_code = '' ) {
		if ( empty( $phone ) ) {
			return '';
		}

		// Remove all non-numeric characters except leading plus
		$cleaned = preg_replace( '/[^\d+]/', '', trim( $phone ) );
		$cleaned = ltrim( $cleaned, '+' );

		// Remove leading international double zeros '00'
		if ( 0 === strpos( $cleaned, '00' ) ) {
			$cleaned = substr( $cleaned, 2 );
		}

		$country = strtoupper( trim( $country_code ) );

		// 1. La Réunion numbers: 0692, 0693, 0262 (10 digits) -> convert to 262692..., 262693..., 262262...
		if ( preg_match( '/^0(692|693|262)(\d{6})$/', $cleaned, $matches ) ) {
			return '262' . $matches[1] . $matches[2];
		}

		// Réunion local format without leading zero: 692XXXXXX, 693XXXXXX, 262XXXXXX (9 digits)
		if ( 'RE' === $country && preg_match( '/^(692|693|262)(\d{6})$/', $cleaned, $matches ) ) {
			return '262' . $matches[1] . $matches[2];
		}

		// Already has 262 prefix: 262692XXXXXX, 262693XXXXXX, 262262XXXXXX (12 digits)
		if ( preg_match( '/^262(692|693|262)\d{6}$/', $cleaned ) ) {
			return $cleaned;
		}

		// 2. Metropolitan France numbers: 06, 07, 01-05, 09 (10 digits) -> convert to 33...
		if ( preg_match( '/^0([1-79])(\d{8})$/', $cleaned, $matches ) ) {
			return '33' . $matches[1] . $matches[2];
		}

		// Already has 33 prefix: 33[1-79]XXXXXXXX (11 digits)
		if ( preg_match( '/^33([1-79])\d{8}$/', $cleaned ) ) {
			return $cleaned;
		}

		// 3. Fallback for ISO RE starting with 0
		if ( 'RE' === $country && '0' === substr( $cleaned, 0, 1 ) ) {
			return '262' . substr( $cleaned, 1 );
		}

		// 4. Fallback for ISO FR starting with 0
		if ( 'FR' === $country && '0' === substr( $cleaned, 0, 1 ) ) {
			return '33' . substr( $cleaned, 1 );
		}

		// 5. General valid international length (8 to 15 digits)
		if ( strlen( $cleaned ) >= 8 && strlen( $cleaned ) <= 15 && '0' !== substr( $cleaned, 0, 1 ) ) {
			return $cleaned;
		}

		return $cleaned;
	}

	/**
	 * Extracts custom data like value, currency, contents, and item count.
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
					'id'         => (string) ( $product->get_sku() ? $product->get_sku() : $product->get_id() ),
					'quantity'   => (int) $item->get_quantity(),
					'item_price' => (float) $order->get_item_total( $item, false, false ),
				);
			}
		}

		return array(
			'currency'     => $order->get_currency(),
			'value'        => (float) $order->get_total(),
			'contents'     => $contents,
			'content_type' => 'product',
			'num_items'    => (int) $order->get_item_count(),
		);
	}

	/**
	 * Normalizes and hashes data using SHA-256.
	 *
	 * @param string $string Raw string.
	 * @return string Hashed string in lowercase.
	 */
	private function hash_data( $string ) {
		$normalized = strtolower( trim( (string) $string ) );
		return hash( 'sha256', $normalized );
	}

	/**
	 * Handle API failures, log them, update HPOS order meta, and send email alerts if enabled.
	 *
	 * @param \WC_Order $order The Order object.
	 * @param string    $error Error details.
	 */
	private function handle_error( $order, $error ) {
		$order_id = $order->get_id();
		Logger::log( "Error sending order #{$order_id} to Meta CAPI: {$error}", 'error' );

		$order->update_meta_data( '_wfbt_capi_status', 'Failed' );
		$order->update_meta_data( '_wfbt_capi_error', $error );
		$order->save();

		$enable_alerts = get_option( 'wfbt_enable_alerts', 'no' );
		$alert_email   = get_option( 'wfbt_alert_email', '' );

		if ( 'yes' === $enable_alerts && is_email( $alert_email ) ) {
			/* translators: %d: order ID */
			$subject  = sprintf( __( 'Meta CAPI Error for Order #%d', 'wfbt-server-side' ), $order_id );
			$edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			$message  = sprintf(
				/* translators: 1: order ID, 2: error details, 3: admin edit URL */
				__( "Hello,\n\nThe Meta Conversions API request failed for WooCommerce Order #%1\$d.\n\nError Details:\n%2\$s\n\nView Order: %3\$s\n\nRegards,\nWoo FB Tracking Server-Side", 'wfbt-server-side' ),
				$order_id,
				$error,
				$edit_url
			);

			wp_mail( $alert_email, $subject, $message );
		}
	}
}
