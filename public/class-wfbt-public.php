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
		add_action( 'wp_footer', array( __CLASS__, 'maybe_render_debug_bar' ), 100 );

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

			// Helper: Get marketing consent details and state across supported banners
			function wfbtGetConsentInfo() {
				if (!wfbt_respect_consent) {
					return { hasConsent: true, source: 'unrestricted' };
				}

				var cookies = document.cookie.split(';');

				// Priority 1: Native Woo Gads Cookie Banner ('woo_gads_consent')
				for (var i = 0; i < cookies.length; i++) {
					var parts = cookies[i].trim().split('=');
					if (parts[0] === 'woo_gads_consent') {
						var val = parts[1] ? decodeURIComponent(parts[1]) : '';
						try {
							var payload = JSON.parse(val);
							if (payload && typeof payload.marketing !== 'undefined') {
								if (payload.marketing === true) {
									return { hasConsent: true, source: 'woo_gads' };
								} else if (payload.marketing === false) {
									return { hasConsent: false, source: 'woo_gads' };
								}
							}
						} catch(e) {}
					}
				}

				// Priority 2: Concord Cookie Banner & configured prefix
				for (var j = 0; j < cookies.length; j++) {
					var cParts = cookies[j].trim().split('=');
					var cName = cParts[0].toLowerCase();
					var cVal = cParts[1] ? decodeURIComponent(cParts[1]) : '';
					if (cName.indexOf(wfbt_concord_prefix) !== -1) {
						if (cVal.indexOf('"marketing":true') !== -1 ||
							cVal.indexOf('marketing:true') !== -1 ||
							cVal.indexOf('marketing=true') !== -1 ||
							cVal === 'all' || cVal === 'true' || cVal === 'accepted' || cVal === 'granted') {
							return { hasConsent: true, source: 'concord' };
						}
						if (cVal.indexOf('"marketing":false') !== -1 ||
							cVal.indexOf('marketing:false') !== -1 ||
							cVal === 'refused' || cVal === 'denied' || cVal === 'false') {
							return { hasConsent: false, source: 'concord' };
						}
					}
				}

				if (typeof window.ConcordConsent !== 'undefined' && typeof window.ConcordConsent.marketing !== 'undefined') {
					if (window.ConcordConsent.marketing === true) {
						return { hasConsent: true, source: 'concord' };
					}
					if (window.ConcordConsent.marketing === false) {
						return { hasConsent: false, source: 'concord' };
					}
				}

				return { hasConsent: false, source: 'none' };
			}

			function wfbtHasMarketingConsent() {
				return wfbtGetConsentInfo().hasConsent;
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

				// Instrument fbq to record events for live diagnostics and debug bar
				window.wfbtEventsLog = window.wfbtEventsLog || [];
				var _orig_fbq = window.fbq;
				window.fbq = function() {
					var args = Array.prototype.slice.call(arguments);
					if (args.length) {
						window.wfbtEventsLog.push({
							time: new Date().toLocaleTimeString(),
							action: args[0],
							name: args[1],
							params: args[2] || null,
							options: args[3] || null
						});
						if (typeof window.wfbtUpdateDebugBar === 'function') {
							window.wfbtUpdateDebugBar();
						}
					}
					return _orig_fbq.apply(this, arguments);
				};
				for (var prop in _orig_fbq) {
					if (_orig_fbq.hasOwnProperty(prop)) {
						window.fbq[prop] = _orig_fbq[prop];
					}
				}

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
			window.wfbtGetConsentInfo = wfbtGetConsentInfo;

			// Check consent immediately or attach event listeners
			if (wfbtHasMarketingConsent()) {
				initMetaPixel();
			} else {
				var onConsentUpdated = function() {
					if (wfbtHasMarketingConsent()) {
						initMetaPixel();
					}
				};

				// 1. Woo Gads Banner hot activation listeners
				document.addEventListener('woo_gads_consent_updated', onConsentUpdated);
				window.addEventListener('woo_gads_consent_updated', onConsentUpdated);

				// Universal click listener on native Woo Gads Accept button (#woo-gads-btn-accept)
				document.addEventListener('click', function(e) {
					var target = e.target;
					if (target && (target.id === 'woo-gads-btn-accept' || (target.closest && target.closest('#woo-gads-btn-accept')))) {
						setTimeout(function() {
							onConsentUpdated();
						}, 50);
					}
				});

				// 2. Concord Cookie Banner custom events
				document.addEventListener('concord:consent', onConsentUpdated);
				document.addEventListener('concord_consent_updated', onConsentUpdated);
				document.addEventListener('concord:consent_changed', onConsentUpdated);
				window.addEventListener('message', function(event) {
					if (event && event.data && typeof event.data === 'string' && (event.data.indexOf('concord') !== -1 || event.data.indexOf('woo_gads') !== -1)) {
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
					var consentInfo = (typeof window.wfbtGetConsentInfo === 'function') ? window.wfbtGetConsentInfo() : null;
					if (consentInfo) {
						if (consentInfo.hasConsent) {
							consentInput.value = 'granted';
						} else if (consentInfo.source !== 'none') {
							// Explicitly refused on an active banner (woo_gads or concord)
							consentInput.value = 'denied';
						} else {
							// No banner detected or no interaction yet
							consentInput.value = 'unknown';
						}
					} else {
						consentInput.value = 'unknown';
					}
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
		$consent = self::detect_consent_php();
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

		// 3. Capture consent state
		$consent = '';
		if ( ! empty( $_POST['wfbt_consent'] ) ) {
			$consent = sanitize_text_field( wp_unslash( $_POST['wfbt_consent'] ) );
		} else {
			$consent = self::detect_consent_php();
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
	 * Detect marketing consent directly from PHP cookies (Priority: Woo Gads > Concord/Custom).
	 *
	 * @return string 'granted', 'denied', or 'unknown'
	 */
	public static function detect_consent_php() {
		$respect = get_option( 'wfbt_respect_consent', 'yes' );
		if ( 'yes' !== $respect ) {
			return 'granted';
		}

		// Priority 1: Native Woo Gads Cookie Banner ('woo_gads_consent')
		if ( isset( $_COOKIE['woo_gads_consent'] ) ) {
			$raw  = is_string( $_COOKIE['woo_gads_consent'] ) ? wp_unslash( $_COOKIE['woo_gads_consent'] ) : '';
			$data = json_decode( rawurldecode( $raw ), true );
			if ( ! is_array( $data ) ) {
				$data = json_decode( stripslashes( rawurldecode( $raw ) ), true );
			}
			if ( is_array( $data ) && isset( $data['marketing'] ) ) {
				return ( true === $data['marketing'] || 'true' === $data['marketing'] || 1 === $data['marketing'] || '1' === $data['marketing'] ) ? 'granted' : 'denied';
			}
		}

		// Priority 2: Concord Cookie Banner & configured prefix
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

	/**
	 * Backward compatibility alias for detect_consent_php().
	 *
	 * @return string
	 */
	public static function detect_concord_consent_php() {
		return self::detect_consent_php();
	}

	/**
	 * Render floating Front-End Debug Bar for Store Managers and Administrators.
	 */
	public static function maybe_render_debug_bar() {
		$enable = get_option( 'wfbt_enable_debug_bar', 'yes' );
		if ( 'yes' !== $enable ) {
			return;
		}

		$is_admin  = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		$has_param = isset( $_GET['wfbt_debug'] ) && '1' === (string) $_GET['wfbt_debug'];

		if ( ! $is_admin && ! $has_param ) {
			return;
		}

		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$cookie_name  = get_option( 'wfbt_concord_cookie_name', 'concord' );
		?>
		<!-- WFBT Front-End Live Debug Bar -->
		<div id="wfbt-debug-container" style="position: fixed; bottom: 18px; right: 18px; z-index: 9999999; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 13px;">
			<!-- Floating Pill / Toggle Button -->
			<div id="wfbt-debug-pill" style="background: #1877f2; color: #fff; padding: 8px 14px; border-radius: 30px; box-shadow: 0 4px 14px rgba(0,0,0,0.25); cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; transition: transform 0.15s ease, background 0.15s ease;">
				<span style="font-size: 15px;">🔵</span>
				<span>Meta Tracking</span>
				<span id="wfbt-debug-count-badge" style="background: #fff; color: #1877f2; border-radius: 12px; padding: 1px 7px; font-size: 11px; font-weight: 700;">0</span>
				<span id="wfbt-debug-consent-pill-badge" style="background: rgba(255,255,255,0.2); border-radius: 10px; padding: 1px 6px; font-size: 10px;">--</span>
			</div>

			<!-- Expanded Live Inspector Modal -->
			<div id="wfbt-debug-card" style="display: none; width: 380px; max-width: 90vw; background: #fff; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.28); border: 1px solid #ccd0d4; overflow: hidden; margin-top: 10px; text-align: left; color: #1d2327;">
				<!-- Header -->
				<div style="background: #1877f2; color: #fff; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
					<div>
						<div style="font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 6px;">
							<span>Meta Tracking Inspector</span>
							<span style="font-size: 10px; background: rgba(255,255,255,0.25); padding: 2px 6px; border-radius: 4px;">Admin</span>
						</div>
						<div style="font-size: 11px; opacity: 0.9; margin-top: 2px;">
							Pixel: <code><?php echo esc_html( $pixel_id ?: __( 'Not configured', 'wfbt-server-side' ) ); ?></code>
						</div>
					</div>
					<button type="button" id="wfbt-debug-close" style="background: transparent; border: none; color: #fff; font-size: 18px; cursor: pointer; padding: 0 4px; line-height: 1;">✕</button>
				</div>

				<div style="padding: 14px 16px; max-height: 420px; overflow-y: auto;">
					<!-- 1. Consent & Cookies -->
					<div style="margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1;">
						<div style="font-weight: 700; font-size: 12px; text-transform: uppercase; color: #646970; margin-bottom: 8px;">
							<?php esc_html_e( 'GDPR Consent & Cookies', 'wfbt-server-side' ); ?>
						</div>
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
							<span><?php esc_html_e( 'Marketing Consent:', 'wfbt-server-side' ); ?></span>
							<span id="wfbt-debug-consent-val" style="font-weight: 700; font-size: 11px; padding: 2px 8px; border-radius: 4px; background: #f0f0f1; color: #646970;">Checking...</span>
						</div>
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; font-size: 12px;">
							<span><code>_fbp</code> (Browser ID):</span>
							<span id="wfbt-debug-fbp-val" style="font-family: monospace; font-size: 11px; color: #007cba;">--</span>
						</div>
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; font-size: 12px;">
							<span><code>_fbc</code> (Click ID):</span>
							<span id="wfbt-debug-fbc-val" style="font-family: monospace; font-size: 11px; color: #007cba;">--</span>
						</div>
						<div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px;">
							<span><code>?fbclid=</code>:</span>
							<span id="wfbt-debug-fbclid-val" style="font-family: monospace; font-size: 11px; color: #646970;">--</span>
						</div>
					</div>

					<!-- 2. Live fbq Event Stream -->
					<div style="margin-bottom: 14px;">
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
							<span style="font-weight: 700; font-size: 12px; text-transform: uppercase; color: #646970;">
								<?php esc_html_e( 'Live fbq Events on this page', 'wfbt-server-side' ); ?>
							</span>
							<span id="wfbt-debug-pixel-health" style="font-size: 11px; color: #008a00; font-weight: 600;">● Pixel Active</span>
						</div>
						<div id="wfbt-debug-events-list" style="background: #f6f7f7; border: 1px solid #e2e4e7; border-radius: 6px; padding: 8px; max-height: 180px; overflow-y: auto; font-family: monospace; font-size: 11.5px;">
							<div style="color: #646970; text-align: center; padding: 10px;"><?php esc_html_e( 'Waiting for events...', 'wfbt-server-side' ); ?></div>
						</div>
					</div>

					<!-- 3. Quick Actions -->
					<div style="background: #f0f6fc; padding: 10px 12px; border-radius: 6px; font-size: 11.5px; line-height: 1.5;">
						<div style="font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Diagnostic Quick Actions:', 'wfbt-server-side' ); ?></div>
						<div style="display: flex; gap: 6px; flex-wrap: wrap;">
							<button type="button" id="wfbt-debug-btn-sim-click" style="background: #fff; border: 1px solid #ccd0d4; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer;">
								<?php esc_html_e( 'Simulate Meta Click (?fbclid=)', 'wfbt-server-side' ); ?>
							</button>
							<button type="button" id="wfbt-debug-btn-clear" style="background: #fff; border: 1px solid #ccd0d4; padding: 4px 8px; border-radius: 4px; font-size: 11px; cursor: pointer;">
								<?php esc_html_e( 'Clear test cookies', 'wfbt-server-side' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script type="text/javascript">
		(function() {
			var pill = document.getElementById('wfbt-debug-pill');
			var card = document.getElementById('wfbt-debug-card');
			var closeBtn = document.getElementById('wfbt-debug-close');
			var eventsList = document.getElementById('wfbt-debug-events-list');
			var countBadge = document.getElementById('wfbt-debug-count-badge');
			var consentPillBadge = document.getElementById('wfbt-debug-consent-pill-badge');
			var consentVal = document.getElementById('wfbt-debug-consent-val');
			var fbpVal = document.getElementById('wfbt-debug-fbp-val');
			var fbcVal = document.getElementById('wfbt-debug-fbc-val');
			var fbclidVal = document.getElementById('wfbt-debug-fbclid-val');
			var healthBadge = document.getElementById('wfbt-debug-pixel-health');

			if (!pill || !card) return;

			function getCookie(name) {
				var matches = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + '=([^;]*)'));
				return matches ? decodeURIComponent(matches[1]) : '';
			}

			function updateDebugView() {
				// 1. Consent
				var info = (typeof window.wfbtGetConsentInfo === 'function') ? window.wfbtGetConsentInfo() : {
					hasConsent: (typeof window.wfbtHasMarketingConsent === 'function') ? window.wfbtHasMarketingConsent() : false,
					source: 'legacy'
				};

				if (info.hasConsent) {
					var sourceTag = info.source && info.source !== 'unrestricted' && info.source !== 'legacy' ? ' (' + info.source + ')' : '';
					consentVal.textContent = 'GRANTED' + sourceTag;
					consentVal.style.background = '#e7f7ed';
					consentVal.style.color = '#008a00';
					consentPillBadge.textContent = 'Consent: OK' + (info.source === 'woo_gads' ? ' (GAds)' : (info.source === 'concord' ? ' (Concord)' : ''));
					consentPillBadge.style.background = '#008a00';
				} else {
					var deniedTag = info.source && info.source !== 'none' ? ' (' + info.source + ')' : '';
					consentVal.textContent = 'DENIED' + deniedTag + ' / WAITING';
					consentVal.style.background = '#fce8e6';
					consentVal.style.color = '#d93025';
					consentPillBadge.textContent = 'No Consent';
					consentPillBadge.style.background = '#d93025';
				}

				// 2. Cookies
				var fbp = getCookie('_fbp');
				fbpVal.textContent = fbp ? (fbp.substring(0, 18) + '...') : 'None';
				fbpVal.style.color = fbp ? '#007cba' : '#888';

				var fbc = getCookie('_fbc');
				fbcVal.textContent = fbc ? (fbc.substring(0, 18) + '...') : 'None';
				fbcVal.style.color = fbc ? '#007cba' : '#888';

				var urlParams = new URLSearchParams(window.location.search);
				var fbclid = urlParams.get('fbclid') || getCookie('wfbt_fbclid');
				fbclidVal.textContent = fbclid ? (fbclid.substring(0, 18) + '...') : 'None';

				// 3. Pixel Health
				if (typeof window.fbq === 'function') {
					healthBadge.textContent = '● Pixel Ready';
					healthBadge.style.color = '#008a00';
				} else {
					healthBadge.textContent = '● Pixel Blocked / Off';
					healthBadge.style.color = '#d93025';
				}

				// 4. Events
				var logs = window.wfbtEventsLog || [];
				countBadge.textContent = logs.length;

				if (logs.length > 0) {
					var html = '';
					for (var i = logs.length - 1; i >= 0; i--) {
						var item = logs[i];
						var eventTitle = item.name || item.action || 'Event';
						var isPurchase = (eventTitle === 'Purchase');
						var eventIdStr = (item.options && item.options.eventID) ? ' [ID: ' + item.options.eventID + ']' : '';
						
						html += '<div style="padding: 5px 0; border-bottom: 1px dotted #dcdcde;">';
						html += '<div style="display: flex; justify-content: space-between;">';
						html += '<strong style="color: ' + (isPurchase ? '#008a00' : '#1877f2') + ';">' + eventTitle + eventIdStr + '</strong>';
						html += '<span style="color: #888; font-size: 10px;">' + item.time + '</span>';
						html += '</div>';
						if (item.params) {
							var preview = '';
							if (item.params.value) preview += 'val: ' + item.params.value + ' ' + (item.params.currency || '') + ' ';
							if (item.params.content_name) preview += item.params.content_name + ' ';
							if (item.params.num_items) preview += '(' + item.params.num_items + ' items) ';
							if (!preview) preview = JSON.stringify(item.params);
							html += '<div style="font-size: 10px; color: #50575e; word-break: break-all;">' + preview + '</div>';
						}
						html += '</div>';
					}
					eventsList.innerHTML = html;
				}
			}

			window.wfbtUpdateDebugBar = updateDebugView;

			// Toggle open/close
			pill.addEventListener('click', function() {
				if (card.style.display === 'none') {
					card.style.display = 'block';
					pill.style.display = 'none';
					updateDebugView();
				}
			});

			closeBtn.addEventListener('click', function() {
				card.style.display = 'none';
				pill.style.display = 'flex';
			});

			// Actions
			var simBtn = document.getElementById('wfbt-debug-btn-sim-click');
			if (simBtn) {
				simBtn.addEventListener('click', function() {
					var url = new URL(window.location.href);
					url.searchParams.set('fbclid', 'SOYOO_TEST_CLICK_' + Math.floor(Math.random() * 90000 + 10000));
					url.searchParams.set('wfbt_debug', '1');
					window.location.href = url.toString();
				});
			}

			var clearBtn = document.getElementById('wfbt-debug-btn-clear');
			if (clearBtn) {
				clearBtn.addEventListener('click', function() {
					document.cookie = 'wfbt_fbclid=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
					try {
						localStorage.removeItem('wfbt_fbclid');
						localStorage.removeItem('wfbt_fbclid_ts');
					} catch(e) {}
					alert('Test cookies cleared.');
					updateDebugView();
				});
			}

			// Initial refresh
			setTimeout(updateDebugView, 800);
		})();
		</script>
		<!-- End WFBT Front-End Live Debug Bar -->
		<?php
	}
}
