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
	 * Flag indicating if an add-to-cart event occurred during the current HTTP request.
	 *
	 * @var bool
	 */
	private static $added_in_current_request = false;

	/**
	 * Initialize Front-End Hooks.
	 */
	public static function init() {
		// Server-side AddToCart capture: must be registered BEFORE the is_admin() guard,
		// because theme AJAX add-to-cart handlers (Woodmart, Flatsome...) run through
		// admin-ajax.php where is_admin() returns true.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_add_to_cart' ), 20, 6 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'inject_add_to_cart_fragment' ), 99 );

		// Lightweight fallback endpoint returning pending AddToCart events from session.
		add_action( 'wc_ajax_wfbt_pending_atc', array( __CLASS__, 'ajax_get_pending_add_to_cart' ) );
		add_action( 'wp_ajax_wfbt_pending_atc', array( __CLASS__, 'ajax_get_pending_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_wfbt_pending_atc', array( __CLASS__, 'ajax_get_pending_add_to_cart' ) );

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
				window.wfbtTrackedAtcEvents = window.wfbtTrackedAtcEvents || {};
				window.wfbtPendingConsentAtc = window.wfbtPendingConsentAtc || [];
				var _orig_fbq = window.fbq;
				window.fbq = function() {
					var args = Array.prototype.slice.call(arguments);
					if (args.length) {
						var resolvedSource = null;
						if (args[3] && args[3]._wfbt_source) {
							resolvedSource = args[3]._wfbt_source;
						} else if (args[2] && args[2]._wfbt_source) {
							resolvedSource = args[2]._wfbt_source;
						} else if (window._wfbtLastAtcSource) {
							resolvedSource = window._wfbtLastAtcSource;
						}

						window.wfbtEventsLog.push({
							time: new Date().toLocaleTimeString(),
							action: args[0],
							name: args[1],
							params: args[2] || null,
							options: args[3] || null,
							source: resolvedSource
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

				// Fire contextual page events (ViewContent, InitiateCheckout, Purchase, AddToCart)
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

						if (ev.name === 'InitiateCheckout') {
							// Deduplicate InitiateCheckout on the same cart hash (refresh, back from Alma, validation error)
							var icHash = (ev.params && ev.params.cart_hash) ? ev.params.cart_hash : (ev.options && ev.options.eventID ? ev.options.eventID : 'default');
							var icKey = 'wfbt_ic_tracked_' + icHash;
							try {
								if (sessionStorage.getItem(icKey)) {
									continue;
								}
								sessionStorage.setItem(icKey, '1');
							} catch(e) {}
						}

						if (ev.name === 'AddToCart') {
							var atcEventId = (ev.options && ev.options.eventID) ? ev.options.eventID : null;
							if (atcEventId) {
								if (window.wfbtTrackedAtcEvents[atcEventId]) {
									continue;
								}
								window.wfbtTrackedAtcEvents[atcEventId] = true;
								try {
									sessionStorage.setItem('wfbt_atc_' + atcEventId, '1');
								} catch(e) {}
							}
						}

						window._wfbtLastAtcSource = ev.source || (ev.name === 'AddToCart' ? 'page_render' : null);
						if (ev.options) {
							fbq('track', ev.name, ev.params, ev.options);
						} else {
							fbq('track', ev.name, ev.params);
						}
						window._wfbtLastAtcSource = null;
					}
				}

				// Flush any AddToCart events queued before consent was granted (in-memory + sessionStorage)
				var pendingConsentEvents = [];
				if (window.wfbtPendingConsentAtc && window.wfbtPendingConsentAtc.length) {
					while (window.wfbtPendingConsentAtc.length > 0) {
						pendingConsentEvents.push(window.wfbtPendingConsentAtc.shift());
					}
				}
				try {
					var storedPending = sessionStorage.getItem('wfbt_pending_consent_atc');
					if (storedPending) {
						var parsedPending = JSON.parse(storedPending);
						if (parsedPending && parsedPending.length) {
							pendingConsentEvents = pendingConsentEvents.concat(parsedPending);
						}
						sessionStorage.removeItem('wfbt_pending_consent_atc');
					}
				} catch(e) {}

				if (pendingConsentEvents.length) {
					for (var p = 0; p < pendingConsentEvents.length; p++) {
						var item = pendingConsentEvents[p];
						if (item && typeof window.wfbtDispatchAtc === 'function') {
							window.wfbtDispatchAtc(item.event, item.source);
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

		// 0. AddToCart captured server-side on a classic (non-AJAX) form submission.
		$pending_atc = self::consume_pending_add_to_cart();
		if ( $pending_atc ) {
			$pending_atc['source'] = 'page_render';
			$events[]              = $pending_atc;
		}

		// 1. ViewContent on Single Product
		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( ! $product ) {
				$product = wc_get_product();
			}
			if ( $product ) {
				$content_ids  = Product_Id::get_view_ids( $product );
				$content_type = Product_Id::get_view_content_type( $product );
				$price        = (float) wc_get_price_to_display( $product );
				$contents     = array();
				foreach ( $content_ids as $cid ) {
					$contents[] = array(
						'id'       => $cid,
						'quantity' => 1,
					);
				}
				$events[] = array(
					'name'   => 'ViewContent',
					'params' => array(
						'content_ids'  => $content_ids,
						'contents'     => $contents,
						'content_name' => $product->get_name(),
						'content_type' => $content_type,
						'value'        => $price,
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
						$pid           = Product_Id::get( $product );
						$content_ids[] = $pid;
						$contents[]    = array(
							'id'         => $pid,
							'quantity'   => (int) $cart_item['quantity'],
							'item_price' => (float) $product->get_price(),
						);
					}
				}

				$cart_hash = WC()->cart->get_cart_hash();
				$event_id  = 'ic_' . substr( md5( $cart_hash . '_' . ( ( WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) ? WC()->session->get_customer_id() : '' ) ), 0, 16 );

				// Dispatch CAPI InitiateCheckout if not already sent for this cart hash
				self::maybe_send_initiate_checkout_capi( $event_id, $cart_hash, $content_ids, $contents, $currency );

				$events[] = array(
					'name'    => 'InitiateCheckout',
					'params'  => array(
						'content_type' => 'product',
						'content_ids'  => $content_ids,
						'contents'     => $contents,
						'value'        => (float) WC()->cart->get_total( 'edit' ),
						'currency'     => $currency,
						'num_items'    => (int) WC()->cart->get_cart_contents_count(),
						'cart_hash'    => $cart_hash,
					),
					'options' => array(
						'eventID' => $event_id,
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
							$pid        = Product_Id::get( $product );
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
							'content_ids'  => array_values( array_unique( wp_list_pluck( $contents, 'id' ) ) ),
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
	 * Build the AddToCart event payload from a cart addition.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $quantity     Quantity added.
	 * @param int $variation_id Variation ID (0 if simple).
	 * @return array|null
	 */
	private static function build_add_to_cart_event( $product_id, $quantity, $variation_id = 0 ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return null;
		}

		$cid      = Product_Id::get( $product );
		$quantity = max( 1, (float) $quantity );
		$price    = (float) wc_get_price_to_display( $product );

		return array(
			'name'    => 'AddToCart',
			'params'  => array(
				'content_type' => 'product',
				'content_ids'  => array( $cid ),
				'contents'     => array(
					array(
						'id'         => $cid,
						'quantity'   => $quantity,
						'item_price' => $price,
					),
				),
				'content_name' => $product->get_name(),
				'value'        => round( $price * $quantity, 2 ),
				'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR',
			),
			'options' => array(
				'eventID' => 'atc_' . wp_generate_password( 12, false ),
			),
		);
	}

	/**
	 * Capture every cart addition (classic form POST, wc-ajax, admin-ajax theme handlers)
	 * and queue the AddToCart event in the WooCommerce session.
	 * It is then consumed either by the AJAX fragments response or the next page render.
	 * ALSO sends the AddToCart event via Meta CAPI server-side for hybrid native resilience.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $product_id    Product ID.
	 * @param int    $quantity      Quantity.
	 * @param int    $variation_id  Variation ID.
	 */
	public static function capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0 ) {
		if ( empty( get_option( 'wfbt_pixel_id', '' ) ) || 'yes' !== get_option( 'wfbt_enable_pixel', 'yes' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$event = self::build_add_to_cart_event( $product_id, $quantity, $variation_id );
		if ( ! $event ) {
			return;
		}

		self::$added_in_current_request = true;
		$event['ajax']                  = wp_doing_ajax() || ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX );

		$pending   = WC()->session->get( 'wfbt_pending_atc', array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = $event;
		WC()->session->set( 'wfbt_pending_atc', array_slice( $pending, -10 ) );

		// Set a lightweight 60s first-party cookie so that if the subsequent page load is served
		// by Cloudflare Edge Cache / Rocket.net, the client-side JavaScript detects the pending
		// server event and fetches it via wc-ajax=wfbt_pending_atc.
		if ( ! headers_sent() ) {
			setcookie( 'wfbt_has_pending_atc', '1', time() + 60, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}

		Logger::log( sprintf( 'capture_add_to_cart: queued AddToCart for product #%d (var #%d, qty %s, ajax: %s, eventID: %s)', $product_id, $variation_id, $quantity, $event['ajax'] ? 'yes' : 'no', $event['options']['eventID'] ) );

		// Dispatch CAPI AddToCart event server-side (Hybrid Native with 1:1 deduplication)
		self::maybe_send_add_to_cart_capi( $event, $product_id, $variation_id );
	}

	/**
	 * Send the AddToCart event via Meta Conversions API (CAPI).
	 * Uses the exact same eventID as the browser-side event for seamless 1:1 deduplication.
	 *
	 * @param array $event        Built AddToCart event data.
	 * @param int   $product_id   Product ID.
	 * @param int   $variation_id Variation ID (0 if simple).
	 */
	private static function maybe_send_add_to_cart_capi( $event, $product_id, $variation_id = 0 ) {
		if ( 'yes' !== get_option( 'wfbt_enable_capi_atc', 'yes' ) ) {
			return;
		}

		$respect_consent = get_option( 'wfbt_respect_consent', 'yes' );
		$consent         = self::detect_consent_php();

		if ( 'yes' === $respect_consent && 'denied' === $consent ) {
			Logger::log( 'AddToCart CAPI: transmission cancelled (GDPR marketing consent denied).' );
			return;
		}

		$api       = new Meta_Api();
		$user_data = $api->extract_request_user_data();

		$product    = wc_get_product( $variation_id ? $variation_id : $product_id );
		$source_url = wp_get_referer();
		if ( ! $source_url && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$source_url = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( ! $source_url && $product ) {
			$source_url = $product->get_permalink();
		}

		$payload = array(
			'event_name'       => 'AddToCart',
			'event_time'       => time(),
			'action_source'    => 'website',
			'event_source_url' => $source_url ? $source_url : home_url( '/' ),
			'event_id'         => $event['options']['eventID'], // Strict deduplication key matching client-side fbq.
			'user_data'        => $user_data,
			'custom_data'      => $event['params'],
		);

		Background_Processor::schedule_payload( $payload );
	}

	/**
	 * Send the InitiateCheckout event via Meta Conversions API (CAPI).
	 * Deduplicated per cart_hash to prevent duplicate events on checkout page interactions.
	 *
	 * @param string $event_id    Deduplication eventID matching browser fbq.
	 * @param string $cart_hash   Cart hash.
	 * @param array  $content_ids Content IDs.
	 * @param array  $contents    Contents array.
	 * @param string $currency    Currency.
	 */
	private static function maybe_send_initiate_checkout_capi( $event_id, $cart_hash, $content_ids, $contents, $currency ) {
		if ( 'yes' !== get_option( 'wfbt_enable_capi_ic', 'yes' ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$sent_key = 'wfbt_capi_ic_sent_' . $cart_hash;
		if ( 'yes' === WC()->session->get( $sent_key ) ) {
			return; // Deduplicated: already sent for this exact cart hash.
		}

		$respect_consent = get_option( 'wfbt_respect_consent', 'yes' );
		$consent         = self::detect_consent_php();

		if ( 'yes' === $respect_consent && 'denied' === $consent ) {
			Logger::log( 'InitiateCheckout CAPI: transmission cancelled (GDPR marketing consent denied).' );
			return;
		}

		$api       = new Meta_Api();
		$user_data = $api->extract_request_user_data();

		$payload = array(
			'event_name'       => 'InitiateCheckout',
			'event_time'       => time(),
			'action_source'    => 'website',
			'event_source_url' => wc_get_checkout_url(),
			'event_id'         => $event_id, // Strict deduplication key matching client-side fbq.
			'user_data'        => $user_data,
			'custom_data'      => array(
				'content_type' => 'product',
				'content_ids'  => $content_ids,
				'contents'     => $contents,
				'value'        => (float) WC()->cart->get_total( 'edit' ),
				'currency'     => $currency,
				'num_items'    => (int) WC()->cart->get_cart_contents_count(),
			),
		);

		WC()->session->set( $sent_key, 'yes' );
		Background_Processor::schedule_payload( $payload );
	}

	/**
	 * Pop all pending AddToCart events from the session.
	 *
	 * @return array
	 */
	private static function pop_pending_add_to_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return array();
		}
		$pending = WC()->session->get( 'wfbt_pending_atc', array() );
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return array();
		}
		WC()->session->set( 'wfbt_pending_atc', null );
		return $pending;
	}

	/**
	 * Consume the most recent pending AddToCart event for a full page render
	 * (classic non-AJAX "Add to cart" form submission on single product pages).
	 * AJAX-originated events are discarded here: they are delivered via fragments
	 * or by the fallback endpoint, never replayed on page load (no duplicates).
	 *
	 * @return array|null
	 */
	private static function consume_pending_add_to_cart() {
		$pending = array_filter(
			self::pop_pending_add_to_cart(),
			function ( $event ) {
				return is_array( $event ) && empty( $event['ajax'] );
			}
		);
		$event = ! empty( $pending ) ? end( $pending ) : null;
		if ( $event && ! headers_sent() ) {
			setcookie( 'wfbt_has_pending_atc', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}
		return $event;
	}

	/**
	 * Expose pending AddToCart events inside the AJAX fragments payload,
	 * read by the `added_to_cart` jQuery listener.
	 *
	 * Crucial safety guard: only pop pending events if an addition actually occurred
	 * during this exact request ($added_in_current_request). This prevents passive
	 * fragment refreshes (e.g. cart-fragments.js at page load or in-drawer line edits)
	 * from accidentally wiping the queue before the legitimate response consumes it.
	 *
	 * @param array $fragments Cart fragments.
	 * @return array
	 */
	public static function inject_add_to_cart_fragment( $fragments ) {
		if ( ! self::$added_in_current_request ) {
			return $fragments;
		}

		$pending = self::pop_pending_add_to_cart();
		if ( ! empty( $pending ) ) {
			$fragments['wfbt_atc'] = array_values( $pending );
			if ( ! headers_sent() ) {
				setcookie( 'wfbt_has_pending_atc', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
			}
			Logger::log( 'inject_add_to_cart_fragment: injected ' . count( $pending ) . ' AddToCart event(s) in fragments' );
		}
		return $fragments;
	}

	/**
	 * Dedicated fallback AJAX endpoint returning pending AddToCart events from the session.
	 * Reached at `?wc-ajax=wfbt_pending_atc` or `admin-ajax.php?action=wfbt_pending_atc`.
	 */
	public static function ajax_get_pending_add_to_cart() {
		nocache_headers();

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			wp_send_json(
				array(
					'success' => false,
					'events'  => array(),
					'reason'  => 'no_session',
				)
			);
		}

		$pending = self::pop_pending_add_to_cart();

		// Clear helper cookie if present.
		if ( ! headers_sent() ) {
			setcookie( 'wfbt_has_pending_atc', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}

		if ( ! empty( $pending ) ) {
			Logger::log( 'ajax_get_pending_add_to_cart: returned and popped ' . count( $pending ) . ' event(s)' );
		}

		wp_send_json(
			array(
				'success' => true,
				'events'  => array_values( $pending ),
			)
		);
	}

	/**
	 * Render footer scripts: fbclid capture, AJAX AddToCart listener, and checkout hidden field population.
	 */
	public static function render_footer_scripts() {
		$currency     = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		$endpoint_url = function_exists( 'WC_AJAX' ) ? \WC_AJAX::get_endpoint( 'wfbt_pending_atc' ) : admin_url( 'admin-ajax.php?action=wfbt_pending_atc' );
		?>
		<script type="text/javascript">
		(function() {
			var wfbtEndpointUrl = <?php echo wp_json_encode( esc_url_raw( $endpoint_url ) ); ?>;
			var wfbtCurrency    = <?php echo wp_json_encode( esc_attr( $currency ) ); ?>;

			// Global registries
			window.wfbtTrackedAtcEvents = window.wfbtTrackedAtcEvents || {};
			window.wfbtPendingConsentAtc = window.wfbtPendingConsentAtc || [];

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

			function wfbtClearPendingAtcCookie() {
				document.cookie = 'wfbt_has_pending_atc=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
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
			}

			// 3. Centralized Dispatcher for AddToCart Events
			function wfbtDispatchAtc(atcEvent, source) {
				if (!atcEvent || !atcEvent.params) return;

				var eventId = (atcEvent.options && atcEvent.options.eventID) ? atcEvent.options.eventID : null;
				if (eventId) {
					if (window.wfbtTrackedAtcEvents[eventId]) {
						return; // Anti-duplicate: already tracked in this session
					}
					window.wfbtTrackedAtcEvents[eventId] = true;
					try {
						sessionStorage.setItem('wfbt_atc_' + eventId, '1');
					} catch(e) {}
				}

				var hasConsent = (typeof window.wfbtHasMarketingConsent === 'function') ? window.wfbtHasMarketingConsent() : false;
				var hasFbq     = (typeof window.fbq === 'function');

				if (!hasConsent || !hasFbq) {
					// Queue until consent is granted / pixel initialized
					window.wfbtPendingConsentAtc.push({ event: atcEvent, source: source });
					try {
						sessionStorage.setItem('wfbt_pending_consent_atc', JSON.stringify(window.wfbtPendingConsentAtc));
					} catch(e) {}
					return;
				}

				window._wfbtLastAtcSource = source || 'unknown';
				if (atcEvent.options) {
					window.fbq('track', 'AddToCart', atcEvent.params, atcEvent.options);
				} else {
					window.fbq('track', 'AddToCart', atcEvent.params);
				}
				window._wfbtLastAtcSource = null;
			}
			window.wfbtDispatchAtc = wfbtDispatchAtc;

			// 4. Fetch pending AddToCart from dedicated lightweight server endpoint
			function wfbtFetchPendingAtcEndpoint(source, fallbackButton) {
				if (!window.fetch || !wfbtEndpointUrl) {
					if (fallbackButton) wfbtFallbackAtcFromDom(fallbackButton);
					return;
				}

				fetch(wfbtEndpointUrl, {
					method: 'GET',
					credentials: 'same-origin',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				})
				.then(function(r) { return r.json(); })
				.then(function(res) {
					wfbtClearPendingAtcCookie();
					if (res && res.success && res.events && res.events.length) {
						for (var m = 0; m < res.events.length; m++) {
							wfbtDispatchAtc(res.events[m], source || 'endpoint');
						}
					} else if (fallbackButton) {
						wfbtFallbackAtcFromDom(fallbackButton);
					}
				})
				.catch(function() {
					wfbtClearPendingAtcCookie();
					if (fallbackButton) {
						wfbtFallbackAtcFromDom(fallbackButton);
					}
				});
			}

			// 5. Check if a non-AJAX POST AddToCart is waiting in session (e.g. cached page render)
			function wfbtCheckPendingAtcFromCookie() {
				if (wfbtGetCookie('wfbt_has_pending_atc') === '1') {
					wfbtFetchPendingAtcEndpoint('endpoint_cache_bypass');
				}
			}
			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', wfbtCheckPendingAtcFromCookie);
			} else {
				wfbtCheckPendingAtcFromCookie();
			}

			// 6. DOM Fallback Formatter & Extractor
			var wfbtIdFormat = <?php echo wp_json_encode( Product_Id::get_effective_format() ); ?>;
			function wfbtFormatId(id, sku) {
				id = id ? String(id) : '';
				sku = sku ? String(sku) : '';
				if (!id && !sku) return '';
				switch (wfbtIdFormat) {
					case 'feed':
					case 'id': return id ? id : sku;
					case 'gla': return id ? 'gla_' + id : '';
					case 'fb_wc': return sku ? sku + '_' + id : 'wc_post_id_' + id;
					case 'sku': return sku ? sku : id;
					default: return id ? id : sku;
				}
			}

			function wfbtFallbackAtcFromDom(button) {
				if (!button) return;
				var $btn = window.jQuery ? window.jQuery(button) : null;
				if (!$btn || !$btn.length) return;

				// Discard programmatic cart updates (mini-cart drawer edits, line removals)
				if ($btn.hasClass('cart-drawer') || $btn.closest('.cart-drawer, .cd-row, .cart-form').length || $btn.hasClass('is-updating')) {
					return;
				}

				var productId = $btn.data('product_id') || $btn.val() || '';
				var sku       = $btn.data('product_sku') || '';
				var qty       = parseFloat($btn.data('quantity')) || 1;

				// If button has no ID (e.g. theme mobile bar button), look up parent product form
				if (!productId && window.jQuery) {
					var $form = $btn.closest('form.cart');
					if (!$form.length) {
						$form = window.jQuery('.pdp form.cart, form.cart');
					}
					if ($form.length) {
						var $formBtn = $form.find('[name="add-to-cart"]');
						productId = $formBtn.val() || $formBtn.data('product_id') || '';
						var $qtyInput = $form.find('input.qty, [name="quantity"]');
						if ($qtyInput.length) {
							qty = parseFloat($qtyInput.val()) || qty;
						}
					}
				}

				// If still no productId, check href for ?add-to-cart=123 (e.g. Woodmart custom loop / home carousel)
				if (!productId && $btn.attr('href')) {
					var href = $btn.attr('href');
					var matchId = href.match(/[?&]add-to-cart=(\d+)/);
					if (matchId && matchId[1]) {
						productId = matchId[1];
					}
					var matchQty = href.match(/[?&]quantity=(\d+)/);
					if (matchQty && matchQty[1]) {
						qty = parseFloat(matchQty[1]) || qty;
					}
				}

				var cid = wfbtFormatId(productId, sku);
				if (!cid) return;

				var domEvent = {
					name: 'AddToCart',
					params: {
						content_type: 'product',
						content_ids: [cid],
						contents: [{ id: cid, quantity: qty }],
						currency: wfbtCurrency
					},
					options: {
						eventID: 'atc_dom_' + Date.now() + '_' + Math.floor(Math.random() * 10000)
					}
				};

				wfbtDispatchAtc(domEvent, 'dom');
			}

			// 7. Listen to WooCommerce AJAX AddToCart
			if (window.jQuery) {
				jQuery(document.body).on('added_to_cart', function(event, fragments, cart_hash, button) {
					var $btn = button && button.length ? button : null;

					// Filter out programmatic cart updates (quantity steppers, line removals inside cart drawer / cart page)
					if ($btn && ($btn.hasClass('cart-drawer') || $btn.closest('.cart-drawer, .cd-row, .cart-form').length || $btn.hasClass('is-updating'))) {
						return;
					}

					// Preferred: server-built payload queued by woocommerce_add_to_cart (exact variation, price, catalog ID)
					if (fragments && fragments.wfbt_atc && fragments.wfbt_atc.length) {
						for (var k = 0; k < fragments.wfbt_atc.length; k++) {
							wfbtDispatchAtc(fragments.wfbt_atc[k], 'fragment');
						}
						wfbtClearPendingAtcCookie();
						return;
					}

					// Fallback: fragments missing wfbt_atc, query the dedicated lightweight server endpoint
					wfbtFetchPendingAtcEndpoint('endpoint', button);
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
		$enable    = get_option( 'wfbt_enable_debug_bar', 'no' );
		$is_admin  = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		$has_param = isset( $_GET['wfbt_debug'] ) && '1' === (string) $_GET['wfbt_debug'];

		if ( 'yes' !== $enable && ! $has_param ) {
			return;
		}

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
						var sourceBadge = item.source ? ' <span style="font-size: 9.5px; background: #e0f2fe; color: #0284c7; padding: 1px 5px; border-radius: 3px; font-weight: 600; text-transform: uppercase;">' + item.source + '</span>' : '';
						
						html += '<div style="padding: 5px 0; border-bottom: 1px dotted #dcdcde;">';
						html += '<div style="display: flex; justify-content: space-between; align-items: center;">';
						html += '<strong style="color: ' + (isPurchase ? '#008a00' : '#1877f2') + ';">' + eventTitle + eventIdStr + sourceBadge + '</strong>';
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
