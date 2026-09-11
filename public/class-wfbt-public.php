<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public Front-End Tracking & Pixel Handler
 */
class Public_Handler {

	/**
	 * Initialize Front-End Hooks.
	 */
	public static function init() {
		// Only run on frontend.
		if ( is_admin() ) {
			return;
		}

		// Enqueue / print Pixel scripts in <head> and footer.
		add_action( 'wp_head', array( __CLASS__, 'render_head_pixel' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_footer_scripts' ), 99 );

		// Capture cookies on checkout submission.
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'capture_checkout_order_meta' ), 10, 1 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'capture_checkout_order_meta_legacy' ), 10, 1 );

		// Render hidden fields in checkout form for seamless fbp/fbc/consent capture.
		add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_checkout_hidden_fields' ) );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_checkout_hidden_fields' ) );
	}

	/**
	 * Render Meta Pixel base script and PageView / ViewContent / InitiateCheckout / Purchase events.
	 */
	public static function render_head_pixel() {
		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$enable_pixel = get_option( 'wfbt_enable_pixel', 'yes' );

		if ( empty( $pixel_id ) || 'yes' !== $enable_pixel ) {
			return;
		}

		$respect_consent     = get_option( 'wfbt_respect_consent', 'yes' );
		$concord_cookie_name = get_option( 'wfbt_concord_cookie_name', 'concord' );

		// Prepare dynamic page event data.
		$event_data = self::get_page_event_data();
		?>
		<!-- Meta Pixel Code by Woo FB Tracking Server-Side (SOYOO) -->
		<script type="text/javascript">
		(function() {
			var wfbt_pixel_id = <?php echo wp_json_encode( esc_attr( $pixel_id ) ); ?>;
			var wfbt_respect_consent = <?php echo wp_json_encode( 'yes' === $respect_consent ); ?>;
			var wfbt_concord_prefix = <?php echo wp_json_encode( strtolower( esc_attr( $concord_cookie_name ) ) ); ?>;
			var wfbt_page_events = <?php echo wp_json_encode( $event_data ); ?>;

			// Helper: Check Concord Cookie Banner marketing consent
			function wfbtHasMarketingConsent() {
				if (!wfbt_respect_consent) {
					return true;
				}
				var cookies = document.cookie.split(';');
				for (var i = 0; i < cookies.length; i++) {
					var parts = cookies[i].trim().split('=');
					var name = parts[0].toLowerCase();
					var val = parts[1] ? decodeURIComponent(parts[1]) : '';
					if (name.indexOf(wfbt_concord_prefix) !== -1) {
						if (val.indexOf('"marketing":true') !== -1 ||
							val.indexOf('marketing:true') !== -1 ||
							val.indexOf('marketing=true') !== -1 ||
							val === 'all' || val === 'true' || val === 'accepted' || val === 'granted') {
							return true;
						}
						if (val.indexOf('"marketing":false') !== -1 ||
							val.indexOf('marketing:false') !== -1 ||
							val === 'refused' || val === 'denied' || val === 'false') {
							return false;
						}
					}
				}
				if (typeof window.ConcordConsent !== 'undefined') {
					if (window.ConcordConsent.marketing === true) return true;
					if (window.ConcordConsent.marketing === false) return false;
				}
				return false;
			}

			// Base fbevents.js initialization
			var pixelInitialized = false;
			function initMetaPixel() {
				if (pixelInitialized) return;
				pixelInitialized = true;

				!function(f,b,e,v,n,t,s)
				{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
				n.callMethod.apply(n,arguments):n.queue.push(arguments)};
				if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
				n.queue=[];t=b.createElement(e);t.async=!0;
				t.src=v;s=b.getElementsByTagName(e)[0];
				s.parentNode.insertBefore(t,s)}(window, document,'script',
				'https://connect.facebook.net/en_US/fbevents.js');

				fbq('init', wfbt_pixel_id);
				fbq('track', 'PageView');

				// Fire contextual page events (ViewContent, InitiateCheckout, Purchase)
				if (wfbt_page_events && wfbt_page_events.length) {
					for (var j = 0; j < wfbt_page_events.length; j++) {
						var ev = wfbt_page_events[j];
						if (ev.name === 'Purchase') {
							// Prevent duplicate Purchase firing on browser refresh
							var purchaseKey = 'wfbt_purchase_tracked_' + (ev.options && ev.options.eventID ? ev.options.eventID : '');
							try {
								if (sessionStorage.getItem(purchaseKey)) {
									continue;
								}
								sessionStorage.setItem(purchaseKey, '1');
							} catch(e) {}
						}

						if (ev.options) {
							fbq('track', ev.name, ev.params, ev.options);
						} else {
							fbq('track', ev.name, ev.params);
						}
					}
				}
			}

			window.wfbtInitMetaPixel = initMetaPixel;
			window.wfbtHasMarketingConsent = wfbtHasMarketingConsent;

			// Check consent immediately or attach event listeners
			if (wfbtHasMarketingConsent()) {
				initMetaPixel();
			} else {
				// Listen to Concord Cookie Banner custom events
				var onConsentUpdated = function() {
					if (wfbtHasMarketingConsent()) {
						initMetaPixel();
					}
				};

				document.addEventListener('concord:consent', onConsentUpdated);
				document.addEventListener('concord_consent_updated', onConsentUpdated);
				document.addEventListener('concord:consent_changed', onConsentUpdated);
				window.addEventListener('message', function(event) {
					if (event && event.data && typeof event.data === 'string' && event.data.indexOf('concord') !== -1) {
						onConsentUpdated();
					}
				});

				// Polling fallback during 20 seconds for hot accept without page reload
				var checkCount = 0;
				var consentInterval = setInterval(function() {
					checkCount++;
					if (wfbtHasMarketingConsent()) {
						clearInterval(consentInterval);
						initMetaPixel();
					} else if (checkCount > 40) {
						clearInterval(consentInterval);
					}
				}, 500);
			}
		})();
		</script>
		<!-- End Meta Pixel Code by Woo FB Tracking Server-Side -->
		<?php
	}

	/**
	 * Prepare contextual page events (ViewContent, InitiateCheckout, Purchase).
	 *
	 * @return array
	 */
	private static function get_page_event_data() {
		$events   = array();
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';

		// 1. ViewContent on Single Product
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product();
			if ( $product ) {
				$product_id = (string) ( $product->get_sku() ?: $product->get_id() );
				$events[] = array(
					'name'   => 'ViewContent',
					'params' => array(
						'content_ids'  => array( $product_id ),
						'content_name' => $product->get_name(),
						'content_type' => 'product',
						'value'        => (float) $product->get_price(),
						'currency'     => $currency,
					),
				);
			}
		}

		// 2. InitiateCheckout on Checkout Page
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) {
				$content_ids = array();
				$contents    = array();
				foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
					$product = $cart_item['data'];
					if ( $product ) {
						$pid = (string) ( $product->get_sku() ?: $product->get_id() );
						$content_ids[] = $pid;
						$contents[]    = array(
							'id'         => $pid,
							'quantity'   => (int) $cart_item['quantity'],
							'item_price' => (float) $product->get_price(),
						);
					}
				}

				$events[] = array(
					'name'   => 'InitiateCheckout',
					'params' => array(
						'content_type' => 'product',
						'content_ids'  => $content_ids,
						'contents'     => $contents,
						'value'        => (float) WC()->cart->get_total( 'edit' ),
						'currency'     => $currency,
						'num_items'    => (int) WC()->cart->get_cart_contents_count(),
					),
				);
			}
		}

		// 3. Purchase on Order Received Page
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			global $wp;
			$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
			if ( ! $order_id && isset( $_GET['order-received'] ) ) {
				$order_id = absint( $_GET['order-received'] );
			}

			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$contents = array();
					foreach ( $order->get_items() as $item ) {
						$product = $item->get_product();
						if ( $product ) {
							$pid        = (string) ( $product->get_sku() ?: $product->get_id() );
							$contents[] = array(
								'id'         => $pid,
								'quantity'   => (int) $item->get_quantity(),
								'item_price' => (float) $order->get_item_total( $item, false, false ),
							);
						}
					}

					$events[] = array(
						'name'    => 'Purchase',
						'params'  => array(
							'content_type' => 'product',
							'contents'     => $contents,
							'value'        => (float) $order->get_total(),
							'currency'     => $order->get_currency(),
							'num_items'    => (int) $order->get_item_count(),
						),
						'options' => array(
							'eventID' => 'order_' . $order->get_id(), // Deduplication key matching CAPI.
						),
					);
				}
			}
		}

		return $events;
	}

	/**
	 * Render footer scripts: fbclid capture, AJAX AddToCart listener, and checkout hidden field population.
	 */
	public static function render_footer_scripts() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		?>
		<script type="text/javascript">
		(function() {
			// 1. Capture fbclid from URL and store in first-party cookie + localStorage
			try {
				var urlParams = new URLSearchParams(window.location.search);
				var fbclid = urlParams.get('fbclid');
				if (fbclid) {
					var expiryDate = new Date();
					expiryDate.setTime(expiryDate.getTime() + (90 * 24 * 60 * 60 * 1000)); // 90 days
					document.cookie = 'wfbt_fbclid=' + encodeURIComponent(fbclid) + '; expires=' + expiryDate.toUTCString() + '; path=/; SameSite=Lax';
					try {
						localStorage.setItem('wfbt_fbclid', fbclid);
						localStorage.setItem('wfbt_fbclid_ts', Date.now().toString());
					} catch(err) {}
				}
			} catch(e) {}

			// Helper to get cookie by name
			function wfbtGetCookie(name) {
				var matches = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
				return matches ? decodeURIComponent(matches[1]) : '';
			}

			// Helper to resolve canonical fbc
			function wfbtResolveFbc() {
				var fbc = wfbtGetCookie('_fbc');
				if (fbc) return fbc;

				var fbclid = wfbtGetCookie('wfbt_fbclid');
				var ts = Date.now();
				try {
					if (!fbclid && localStorage.getItem('wfbt_fbclid')) {
						fbclid = localStorage.getItem('wfbt_fbclid');
						var savedTs = localStorage.getItem('wfbt_fbclid_ts');
						if (savedTs) ts = savedTs;
					}
				} catch(e) {}

				if (fbclid) {
					return 'fb.1.' + ts + '.' + fbclid;
				}
				return '';
			}

			// 2. Populate checkout hidden fields
			function wfbtSyncCheckoutFields() {
				var fbpInput = document.getElementById('wfbt_fbp');
				var fbcInput = document.getElementById('wfbt_fbc');
				var consentInput = document.getElementById('wfbt_consent');

				if (fbpInput) {
					fbpInput.value = wfbtGetCookie('_fbp');
				}
				if (fbcInput) {
					fbcInput.value = wfbtResolveFbc();
				}
				if (consentInput) {
					consentInput.value = (typeof window.wfbtHasMarketingConsent === 'function' && window.wfbtHasMarketingConsent()) ? 'granted' : 'denied';
				}
			}

			// Run on DOM ready
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', wfbtSyncCheckoutFields);
			} else {
				wfbtSyncCheckoutFields();
			}

			// Refresh right before checkout submission
			if (window.jQuery) {
				jQuery(document.body).on('checkout_place_order', function() {
					wfbtSyncCheckoutFields();
				});

				// 3. Listen to WooCommerce AJAX AddToCart
				jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, button) {
					if (typeof window.fbq === 'function') {
						var productId = '';
						var qty = 1;
						if (button && button.length) {
							productId = button.data('product_id') ? String(button.data('product_id')) : '';
							if (button.data('quantity')) {
								qty = parseFloat(button.data('quantity')) || 1;
							}
						}

						window.fbq('track', 'AddToCart', {
							content_type: 'product',
							content_ids: productId ? [productId] : [],
							currency: <?php echo wp_json_encode( esc_attr( $currency ) ); ?>
						});
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Render hidden fields in the checkout form.
	 */
	public static function render_checkout_hidden_fields() {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		$fbp     = isset( $_COOKIE['_fbp'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) ) ) : '';
		$fbc     = isset( $_COOKIE['_fbc'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) ) ) : '';
		$consent = self::detect_concord_consent_php();
		?>
		<input type="hidden" name="wfbt_fbp" id="wfbt_fbp" value="<?php echo esc_attr( $fbp ); ?>" />
		<input type="hidden" name="wfbt_fbc" id="wfbt_fbc" value="<?php echo esc_attr( $fbc ); ?>" />
		<input type="hidden" name="wfbt_consent" id="wfbt_consent" value="<?php echo esc_attr( $consent ); ?>" />
		<?php
	}

	/**
	 * Capture fbp, fbc, and consent into the WC_Order object (HPOS native).
	 *
	 * @param \WC_Order $order
	 */
	public static function capture_checkout_order_meta( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::save_order_tracking_meta( $order );
	}

	/**
	 * Capture fbp, fbc, and consent into the order (legacy order_id fallback).
	 *
	 * @param int $order_id
	 */
	public static function capture_checkout_order_meta_legacy( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		self::save_order_tracking_meta( $order );
	}

	/**
	 * Persist tracking metadata onto the order using HPOS CRUD methods.
	 *
	 * @param \WC_Order $order
	 */
	private static function save_order_tracking_meta( $order ) {
		// 1. Capture _fbp
		$fbp = '';
		if ( ! empty( $_POST['wfbt_fbp'] ) ) {
			$fbp = sanitize_text_field( wp_unslash( $_POST['wfbt_fbp'] ) );
		} elseif ( ! empty( $_COOKIE['_fbp'] ) ) {
			$fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
		}

		// 2. Capture _fbc or reconstruct from fbclid
		$fbc = '';
		if ( ! empty( $_POST['wfbt_fbc'] ) ) {
			$fbc = sanitize_text_field( wp_unslash( $_POST['wfbt_fbc'] ) );
		} elseif ( ! empty( $_COOKIE['_fbc'] ) ) {
			$fbc = sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) );
		} elseif ( ! empty( $_COOKIE['wfbt_fbclid'] ) ) {
			$fbclid = sanitize_text_field( wp_unslash( $_COOKIE['wfbt_fbclid'] ) );
			$fbc    = 'fb.1.' . ( time() * 1000 ) . '.' . $fbclid;
		}

		// 3. Capture Concord consent state
		$consent = '';
		if ( ! empty( $_POST['wfbt_consent'] ) ) {
			$consent = sanitize_text_field( wp_unslash( $_POST['wfbt_consent'] ) );
		} else {
			$consent = self::detect_concord_consent_php();
		}

		// Update order metadata if values found
		$updated = false;
		if ( ! empty( $fbp ) && $order->get_meta( '_wfbt_fbp' ) !== $fbp ) {
			$order->update_meta_data( '_wfbt_fbp', $fbp );
			$updated = true;
		}
		if ( ! empty( $fbc ) && $order->get_meta( '_wfbt_fbc' ) !== $fbc ) {
			$order->update_meta_data( '_wfbt_fbc', $fbc );
			$updated = true;
		}
		if ( ! empty( $consent ) && $order->get_meta( '_wfbt_consent' ) !== $consent ) {
			$order->update_meta_data( '_wfbt_consent', $consent );
			$updated = true;
		}

		if ( $updated ) {
			$order->save();
		}
	}

	/**
	 * Detect Concord marketing consent directly from PHP cookies.
	 *
	 * @return string 'granted', 'denied', or 'unknown'
	 */
	public static function detect_concord_consent_php() {
		$respect = get_option( 'wfbt_respect_consent', 'yes' );
		if ( 'yes' !== $respect ) {
			return 'granted';
		}

		$prefix = strtolower( get_option( 'wfbt_concord_cookie_name', 'concord' ) );
		foreach ( $_COOKIE as $cookie_name => $cookie_val ) {
			if ( false !== stripos( $cookie_name, $prefix ) ) {
				$val = is_string( $cookie_val ) ? strtolower( $cookie_val ) : '';
				if ( false !== strpos( $val, 'marketing":true' ) ||
					 false !== strpos( $val, 'marketing:true' ) ||
					 false !== strpos( $val, 'marketing=true' ) ||
					 'all' === $val || 'true' === $val || 'accepted' === $val || 'granted' === $val ) {
					return 'granted';
				}
				if ( false !== strpos( $val, 'marketing":false' ) ||
					 false !== strpos( $val, 'marketing:false' ) ||
					 'refused' === $val || 'denied' === $val || 'false' === $val ) {
					return 'denied';
				}
			}
		}

		return 'unknown';
	}
}
