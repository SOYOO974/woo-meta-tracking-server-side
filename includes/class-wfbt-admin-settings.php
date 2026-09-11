<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings & Diagnostics Class
 * Fully HPOS compatible, translated via standard gettext i18n with default English strings.
 */
class Admin_Settings {

	/**
	 * Initialize Admin Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Ajax actions for Diagnostics tab.
		add_action( 'wp_ajax_wfbt_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wfbt_resend_order', array( __CLASS__, 'ajax_resend_order' ) );
	}

	/**
	 * Get appropriate capability for settings page.
	 * Supports both store managers (manage_woocommerce) and site administrators (manage_options).
	 *
	 * @return string
	 */
	public static function get_capability() {
		return current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Add Settings Page to WooCommerce menu (or Settings menu as fallback).
	 */
	public static function add_settings_page() {
		$capability = self::get_capability();

		if ( current_user_can( 'manage_woocommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Meta Hybrid Tracking (Pixel + CAPI)', 'wfbt-server-side' ),
				__( 'Meta Tracking', 'wfbt-server-side' ),
				$capability,
				'wfbt-settings',
				array( __CLASS__, 'render_settings_page' )
			);
		} else {
			add_options_page(
				__( 'Meta Hybrid Tracking (Pixel + CAPI)', 'wfbt-server-side' ),
				__( 'Meta Tracking', 'wfbt-server-side' ),
				'manage_options',
				'wfbt-settings',
				array( __CLASS__, 'render_settings_page' )
			);
		}
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting( 'wfbt_settings_group', 'wfbt_pixel_id', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_access_token', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_test_code', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_enable_pixel', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_respect_consent', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_concord_cookie_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_consent_action', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_trigger_statuses' );
		register_setting( 'wfbt_settings_group', 'wfbt_enable_alerts', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'wfbt_settings_group', 'wfbt_alert_email', array( 'sanitize_callback' => 'sanitize_email' ) );
	}

	/**
	 * Enqueue admin scripts for the diagnostics page.
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wfbt-settings' !== $hook && 'settings_page_wfbt-settings' !== $hook ) {
			return;
		}

		$i18n = array(
			'testing'        => __( 'Testing...', 'wfbt-server-side' ),
			'test_btn'       => __( 'Test API Connection (CAPI v21.0)', 'wfbt-server-side' ),
			'success'        => __( '✓ Success! Test event received by Meta Graph API.', 'wfbt-server-side' ),
			'failed'         => __( '✗ Failed: ', 'wfbt-server-side' ),
			'ajax_error'     => __( '✗ Network error during AJAX test.', 'wfbt-server-side' ),
			'queuing'        => __( 'Queuing...', 'wfbt-server-side' ),
			'queued'         => __( 'Queued!', 'wfbt-server-side' ),
			'resend'         => __( 'Resend', 'wfbt-server-side' ),
			'status_pending' => __( 'Pending (Action Scheduler)', 'wfbt-server-side' ),
			'error'          => __( 'Error: ', 'wfbt-server-side' ),
			'resend_error'   => __( 'Network error while queuing the order.', 'wfbt-server-side' ),
		);

		$script = "
			jQuery(document).ready(function($){
				var wfbt_i18n = " . wp_json_encode( $i18n ) . ";

				$('#wfbt-test-connection').on('click', function(e){
					e.preventDefault();
					var btn = $(this);
					btn.prop('disabled', true).text(wfbt_i18n.testing);
					$('#wfbt-test-result').html('');
					
					$.post( ajaxurl, {
						action: 'wfbt_test_connection',
						nonce: '" . wp_create_nonce( 'wfbt_admin_nonce' ) . "'
					}, function(response){
						btn.prop('disabled', false).text(wfbt_i18n.test_btn);
						if(response.success) {
							$('#wfbt-test-result').html('<span style=\"color:#008a00; font-weight:bold;\">' + wfbt_i18n.success + '</span>');
						} else {
							$('#wfbt-test-result').html('<span style=\"color:#d93025; font-weight:bold;\">' + wfbt_i18n.failed + response.data + '</span>');
						}
					}).fail(function(){
						btn.prop('disabled', false).text(wfbt_i18n.test_btn);
						$('#wfbt-test-result').html('<span style=\"color:#d93025; font-weight:bold;\">' + wfbt_i18n.ajax_error + '</span>');
					});
				});

				$('.wfbt-resend-btn').on('click', function(e){
					e.preventDefault();
					var btn = $(this);
					var order_id = btn.data('order-id');
					btn.prop('disabled', true).text(wfbt_i18n.queuing);

					$.post( ajaxurl, {
						action: 'wfbt_resend_order',
						order_id: order_id,
						nonce: '" . wp_create_nonce( 'wfbt_admin_nonce' ) . "'
					}, function(response){
						if(response.success) {
							btn.text(wfbt_i18n.queued);
							btn.closest('tr').find('.wfbt-status-cell').html('<span style=\"color:#5f6368;\">' + wfbt_i18n.status_pending + '</span>');
						} else {
							btn.prop('disabled', false).text(wfbt_i18n.resend);
							alert(wfbt_i18n.error + response.data);
						}
					}).fail(function(){
						btn.prop('disabled', false).text(wfbt_i18n.resend);
						alert(wfbt_i18n.resend_error);
					});
				});
			});
		";
		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * Render the main settings page and tabs.
	 */
	public static function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'configuration';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Woo FB Hybrid Tracking (Pixel + Meta CAPI v21.0)', 'wfbt-server-side' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'High-performance hybrid architecture (Browser fbq + Server CAPI deduplicated), 100% GDPR compliant (Concord) and WooCommerce HPOS compatible.', 'wfbt-server-side' ); ?>
			</p>
			<h2 class="nav-tab-wrapper">
				<a href="?page=wfbt-settings&tab=configuration" class="nav-tab <?php echo 'configuration' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Configuration', 'wfbt-server-side' ); ?></a>
				<a href="?page=wfbt-settings&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Diagnostics & Orders', 'wfbt-server-side' ); ?></a>
				<a href="?page=wfbt-settings&tab=tutorial" class="nav-tab <?php echo 'tutorial' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Setup Guide & Instructions', 'wfbt-server-side' ); ?></a>
			</h2>
			<div class="wfbt-settings-content" style="margin-top: 20px;">
				<?php
				if ( 'configuration' === $active_tab ) {
					self::render_configuration_tab();
				} elseif ( 'diagnostics' === $active_tab ) {
					self::render_diagnostics_tab();
				} elseif ( 'tutorial' === $active_tab ) {
					self::render_tutorial_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Configuration Tab.
	 */
	private static function render_configuration_tab() {
		$enable_pixel        = get_option( 'wfbt_enable_pixel', 'yes' );
		$respect_consent     = get_option( 'wfbt_respect_consent', 'yes' );
		$concord_cookie_name = get_option( 'wfbt_concord_cookie_name', 'concord' );
		$consent_action      = get_option( 'wfbt_consent_action', 'anonymize' );
		$trigger_statuses    = get_option( 'wfbt_trigger_statuses', array( 'processing', 'completed' ) );
		if ( ! is_array( $trigger_statuses ) ) {
			$trigger_statuses = array( 'processing', 'completed' );
		}
		$all_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wfbt_settings_group' ); ?>
			
			<h3 style="margin-top: 10px;"><?php esc_html_e( '1. Meta API & Pixel Settings', 'wfbt-server-side' ); ?></h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Meta Pixel ID', 'wfbt-server-side' ); ?></th>
					<td>
						<input type="text" name="wfbt_pixel_id" value="<?php echo esc_attr( get_option( 'wfbt_pixel_id' ) ); ?>" class="regular-text" placeholder="123456789012345" />
						<p class="description"><?php esc_html_e( 'Your Meta Pixel ID (found in Meta Events Manager).', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Conversions API Access Token', 'wfbt-server-side' ); ?></th>
					<td>
						<textarea name="wfbt_access_token" rows="4" class="large-text" placeholder="EAA..."><?php echo esc_textarea( get_option( 'wfbt_access_token' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'System User access token generated from Meta Events Manager > Settings > Conversions API.', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Test Event Code (Optional)', 'wfbt-server-side' ); ?></th>
					<td>
						<input type="text" name="wfbt_test_code" value="<?php echo esc_attr( get_option( 'wfbt_test_code' ) ); ?>" class="regular-text" placeholder="TEST12345" />
						<p class="description"><?php esc_html_e( 'Enter this code to see your events in real-time in Meta Events Manager "Test events" tab.', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Enable Browser Pixel', 'wfbt-server-side' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wfbt_enable_pixel" value="yes" <?php checked( $enable_pixel, 'yes' ); ?> />
							<strong><?php esc_html_e( 'Enable client-side tracking (fbq)', 'wfbt-server-side' ); ?></strong>
						</label>
						<p class="description"><?php esc_html_e( 'Injects fbevents.js and fires PageView, ViewContent, AddToCart (AJAX), InitiateCheckout, and Purchase with eventID deduplication.', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
			</table>

			<hr style="margin: 30px 0;" />

			<h3><?php esc_html_e( '2. GDPR Compliance & Concord Cookie Banner', 'wfbt-server-side' ); ?></h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Condition tracking on GDPR consent', 'wfbt-server-side' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wfbt_respect_consent" value="yes" <?php checked( $respect_consent, 'yes' ); ?> />
							<strong><?php esc_html_e( 'Require marketing consent from Concord Cookie Banner', 'wfbt-server-side' ); ?></strong>
						</label>
						<p class="description"><?php esc_html_e( 'Prevents Browser Pixel execution and blocks or anonymizes CAPI requests if consent is not granted.', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Concord cookie name or prefix', 'wfbt-server-side' ); ?></th>
					<td>
						<input type="text" name="wfbt_concord_cookie_name" value="<?php echo esc_attr( $concord_cookie_name ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Cookie name or prefix used by your cookie banner (default: concord).', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'CAPI action when consent is refused', 'wfbt-server-side' ); ?></th>
					<td>
						<fieldset>
							<label style="display: block; margin-bottom: 10px;">
								<input type="radio" name="wfbt_consent_action" value="anonymize" <?php checked( $consent_action, 'anonymize' ); ?> />
								<strong><?php esc_html_e( 'Send anonymized request (Default & Recommended - No PII, no _fbp/_fbc, no IP/User-Agent)', 'wfbt-server-side' ); ?></strong>
								<p class="description" style="margin: 3px 0 0 24px;"><?php esc_html_e( 'Safely sends order value, currency, contents, and eventID for statistical aggregation without customer personal data.', 'wfbt-server-side' ); ?></p>
							</label>
							<label style="display: block;">
								<input type="radio" name="wfbt_consent_action" value="block" <?php checked( $consent_action, 'block' ); ?> />
								<span><?php esc_html_e( 'Cancel CAPI request completely (Strict CNIL)', 'wfbt-server-side' ); ?></span>
								<p class="description" style="margin: 3px 0 0 24px;"><?php esc_html_e( 'Completely cancels and ignores CAPI transmission if marketing consent is refused.', 'wfbt-server-side' ); ?></p>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<hr style="margin: 30px 0;" />

			<h3><?php esc_html_e( '3. Triggering & Order Statuses (Action Scheduler)', 'wfbt-server-side' ); ?></h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'CAPI trigger order statuses', 'wfbt-server-side' ); ?></th>
					<td>
						<fieldset>
							<?php
							foreach ( $all_statuses as $status_key => $status_label ) {
								$clean_status = str_replace( 'wc-', '', $status_key );
								$is_checked   = in_array( $clean_status, $trigger_statuses, true );
								?>
								<label style="margin-right: 15px; display: inline-block;">
									<input type="checkbox" name="wfbt_trigger_statuses[]" value="<?php echo esc_attr( $clean_status ); ?>" <?php checked( $is_checked ); ?> />
									<?php echo esc_html( $status_label ); ?>
								</label>
								<?php
							}
							?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Order statuses that trigger the asynchronous Purchase event to Meta CAPI (defaults: Processing and Completed).', 'wfbt-server-side' ); ?></p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Email Alerts on Failure', 'wfbt-server-side' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wfbt_enable_alerts" value="yes" <?php checked( get_option( 'wfbt_enable_alerts' ), 'yes' ); ?> />
							<?php esc_html_e( 'Send an email if a CAPI request fails.', 'wfbt-server-side' ); ?>
						</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Alert Email Address', 'wfbt-server-side' ); ?></th>
					<td>
						<input type="email" name="wfbt_alert_email" value="<?php echo esc_attr( get_option( 'wfbt_alert_email' ) ); ?>" class="regular-text" placeholder="dev@soyoo.re" />
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Changes', 'wfbt-server-side' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render Diagnostics Tab.
	 */
	private static function render_diagnostics_tab() {
		?>
		<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 25px;">
			<h3 style="margin-top: 0;"><?php esc_html_e( 'Meta CAPI Connection Test (v21.0)', 'wfbt-server-side' ); ?></h3>
			<p><?php esc_html_e( 'Sends an immediate dummy Purchase test event to Meta Graph API using your configured Test Event Code.', 'wfbt-server-side' ); ?></p>
			<button id="wfbt-test-connection" class="button button-primary"><?php esc_html_e( 'Test API Connection (CAPI v21.0)', 'wfbt-server-side' ); ?></button>
			<span id="wfbt-test-result" style="margin-left: 15px;"></span>
		</div>

		<h3><?php esc_html_e( 'Last 20 WooCommerce Orders (CAPI & Cookies Audit)', 'wfbt-server-side' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Native HPOS inspection of advertising identifiers (_fbp, _fbc), Concord consent state, and CAPI transmission status.', 'wfbt-server-side' ); ?>
		</p>
		<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
			<thead>
				<tr>
					<th style="width: 130px;"><?php esc_html_e( 'Order', 'wfbt-server-side' ); ?></th>
					<th style="width: 140px;"><?php esc_html_e( 'Date', 'wfbt-server-side' ); ?></th>
					<th style="width: 100px;"><?php esc_html_e( 'Total', 'wfbt-server-side' ); ?></th>
					<th style="width: 180px;"><?php esc_html_e( 'Meta CAPI Status', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( '_fbp Cookie', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( '_fbc Cookie', 'wfbt-server-side' ); ?></th>
					<th style="width: 130px;"><?php esc_html_e( 'Concord Consent', 'wfbt-server-side' ); ?></th>
					<th style="width: 120px;"><?php esc_html_e( 'Actions', 'wfbt-server-side' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array(
					'limit'   => 20,
					'orderby' => 'date',
					'order'   => 'DESC',
				) ) : array();

				if ( empty( $orders ) ) {
					echo '<tr><td colspan="8">' . esc_html__( 'No orders found.', 'wfbt-server-side' ) . '</td></tr>';
				} else {
					foreach ( $orders as $order ) {
						if ( ! $order instanceof \WC_Order ) {
							continue;
						}
						$order_id    = $order->get_id();
						$status      = $order->get_meta( '_wfbt_capi_status' ) ?: 'Pending';
						$error       = $order->get_meta( '_wfbt_capi_error' );
						$sent_at     = $order->get_meta( '_wfbt_capi_sent_at' );
						$fbp         = $order->get_meta( '_wfbt_fbp' );
						$fbc         = $order->get_meta( '_wfbt_fbc' );
						$consent     = $order->get_meta( '_wfbt_consent' );

						// Status formatting
						if ( 'Success' === $status ) {
							$status_html = '<span style="color:#008a00; font-weight:bold;">✓ ' . esc_html__( 'Success', 'wfbt-server-side' ) . '</span>';
							if ( $sent_at ) {
								$status_html .= '<br><small style="color:#666;">' . esc_html( $sent_at ) . '</small>';
							}
						} elseif ( 'Failed' === $status ) {
							$status_html = '<span style="color:#d93025; font-weight:bold;">✗ ' . esc_html__( 'Failed', 'wfbt-server-side' ) . '</span>';
							if ( $error ) {
								$status_html .= '<br><small style="color:#d93025; display:inline-block; max-width:200px; word-break:break-word;">' . esc_html( $error ) . '</small>';
							}
						} elseif ( false !== strpos( $status, 'Ignored' ) ) {
							$status_html = '<span style="color:#e37400; font-weight:bold;">⊘ ' . esc_html( $status ) . '</span>';
						} else {
							$status_html = '<span style="color:#5f6368;">' . esc_html__( 'Pending', 'wfbt-server-side' ) . '</span>';
						}

						// _fbp formatting
						if ( ! empty( $fbp ) ) {
							$fbp_html = '<span style="color:#007cba; font-weight:600;">✓ ' . esc_html__( 'Yes', 'wfbt-server-side' ) . '</span><br><code style="font-size:11px; background:#f0f0f1; padding:2px 4px; border-radius:3px;">' . esc_html( substr( $fbp, 0, 16 ) . '...' ) . '</code>';
						} else {
							$fbp_html = '<span style="color:#888;">— ' . esc_html__( 'Not detected', 'wfbt-server-side' ) . '</span>';
						}

						// _fbc formatting
						if ( ! empty( $fbc ) ) {
							$fbc_html = '<span style="color:#007cba; font-weight:600;">✓ ' . esc_html__( 'Yes', 'wfbt-server-side' ) . '</span><br><code style="font-size:11px; background:#f0f0f1; padding:2px 4px; border-radius:3px;">' . esc_html( substr( $fbc, 0, 18 ) . '...' ) . '</code>';
						} else {
							$fbc_html = '<span style="color:#888;">— ' . esc_html__( 'Not detected', 'wfbt-server-side' ) . '</span>';
						}

						// Consent formatting
						if ( 'granted' === $consent ) {
							$consent_html = '<span style="background:#e7f7ed; color:#008a00; padding:3px 8px; border-radius:4px; font-weight:600; font-size:12px;">' . esc_html__( 'Granted', 'wfbt-server-side' ) . '</span>';
						} elseif ( 'denied' === $consent ) {
							$consent_html = '<span style="background:#fce8e6; color:#d93025; padding:3px 8px; border-radius:4px; font-weight:600; font-size:12px;">' . esc_html__( 'Denied', 'wfbt-server-side' ) . '</span>';
						} else {
							$consent_html = '<span style="background:#f1f3f4; color:#5f6368; padding:3px 8px; border-radius:4px; font-size:12px;">' . esc_html__( 'Not detected', 'wfbt-server-side' ) . '</span>';
						}

						echo '<tr>';
						echo '<td><a href="' . esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) . '"><strong>#' . esc_html( $order_id ) . '</strong></a></td>';
						echo '<td>' . esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ) . '</td>';
						echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
						echo '<td class="wfbt-status-cell">' . wp_kses_post( $status_html ) . '</td>';
						echo '<td>' . wp_kses_post( $fbp_html ) . '</td>';
						echo '<td>' . wp_kses_post( $fbc_html ) . '</td>';
						echo '<td>' . wp_kses_post( $consent_html ) . '</td>';
						echo '<td><button class="button button-secondary wfbt-resend-btn" data-order-id="' . esc_attr( $order_id ) . '">' . esc_html__( 'Resend', 'wfbt-server-side' ) . '</button></td>';
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render Detailed Setup Guide & Instructions Tab (Collapsible Accordion).
	 */
	private static function render_tutorial_tab() {
		?>
		<style>
			.wfbt-accordion-step {
				background: #fff;
				border: 1px solid #ccd0d4;
				border-radius: 6px;
				margin-bottom: 14px;
				box-shadow: 0 1px 2px rgba(0,0,0,0.03);
				transition: all 0.2s ease;
				overflow: hidden;
			}
			.wfbt-accordion-step[open] {
				border-color: #007cba;
				box-shadow: 0 2px 8px rgba(0,124,186,0.12);
			}
			.wfbt-accordion-summary {
				padding: 15px 20px;
				cursor: pointer;
				display: flex;
				align-items: center;
				justify-content: space-between;
				background: #fff;
				font-size: 15px;
				font-weight: 600;
				color: #1d2327;
				transition: background 0.15s ease;
				list-style: none;
				outline: none;
			}
			.wfbt-accordion-summary::-webkit-details-marker {
				display: none;
			}
			.wfbt-accordion-summary:hover {
				background: #f6f7f7;
			}
			.wfbt-accordion-step[open] .wfbt-accordion-summary {
				background: #f0f6fc;
				border-bottom: 1px solid #cce5ff;
			}
			.wfbt-step-header {
				display: flex;
				align-items: center;
				gap: 12px;
			}
			.wfbt-step-num {
				background: #007cba;
				color: #fff;
				width: 26px;
				height: 26px;
				border-radius: 50%;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				font-size: 13px;
				font-weight: 700;
				flex-shrink: 0;
			}
			.wfbt-step-arrow {
				font-size: 18px;
				color: #007cba;
				transition: transform 0.2s ease;
			}
			.wfbt-accordion-step[open] .wfbt-step-arrow {
				transform: rotate(180deg);
			}
			.wfbt-step-body {
				padding: 22px 24px;
				font-size: 13.5px;
				line-height: 1.75;
				color: #2c3338;
			}
			.wfbt-step-body ol, .wfbt-step-body ul {
				margin-left: 20px;
				margin-bottom: 15px;
			}
			.wfbt-step-body li {
				margin-bottom: 8px;
			}
			.wfbt-badge-check {
				display: inline-block;
				background: #e7f7ed;
				color: #008a20;
				border: 1px solid #a3e6b8;
				padding: 2px 8px;
				border-radius: 4px;
				font-weight: 600;
				font-size: 12px;
			}
			.wfbt-badge-skip {
				display: inline-block;
				background: #fcf0f0;
				color: #d63638;
				border: 1px solid #f5c2c2;
				padding: 2px 8px;
				border-radius: 4px;
				font-size: 12px;
			}
			.wfbt-box-info {
				background: #f0f6fc;
				border-left: 4px solid #007cba;
				padding: 12px 16px;
				border-radius: 0 4px 4px 0;
				margin: 14px 0;
			}
			.wfbt-box-warning {
				background: #fff8e5;
				border-left: 4px solid #dba617;
				padding: 12px 16px;
				border-radius: 0 4px 4px 0;
				margin: 14px 0;
			}
			.wfbt-param-table {
				width: 100%;
				border-collapse: collapse;
				margin: 14px 0;
				background: #fff;
				border: 1px solid #e2e4e7;
				border-radius: 4px;
			}
			.wfbt-param-table th, .wfbt-param-table td {
				padding: 10px 14px;
				border-bottom: 1px solid #f0f0f1;
				text-align: left;
				font-size: 13px;
			}
			.wfbt-param-table th {
				background: #f6f7f7;
				font-weight: 600;
				color: #1d2327;
			}
		</style>

		<script>
			function wfbtToggleAccordion(expand) {
				var steps = document.querySelectorAll('.wfbt-accordion-step');
				for (var i = 0; i < steps.length; i++) {
					if (expand) {
						steps[i].setAttribute('open', '');
					} else {
						steps[i].removeAttribute('open');
					}
				}
			}
		</script>

		<div style="max-width: 960px;">
			<!-- Header Banner -->
			<div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #007cba; padding: 20px; border-radius: 4px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
				<div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
					<div>
						<h2 style="margin-top: 0; font-size: 19px; color: #1d2327;">
							<?php esc_html_e( 'Step-by-Step Meta Hybrid Tracking Setup Guide', 'wfbt-server-side' ); ?>
						</h2>
						<p style="font-size: 13.5px; line-height: 1.6; color: #50575e; margin-bottom: 0;">
							<?php esc_html_e( 'Follow these 8 detailed steps to configure your Meta Pixel and Conversions API (CAPI). Each section is collapsible to keep your view uncluttered. Click any step to expand its instructions.', 'wfbt-server-side' ); ?>
						</p>
					</div>
					<div style="display: flex; gap: 8px;">
						<button type="button" class="button" onclick="wfbtToggleAccordion(true);">
							<span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle; font-size: 14px;"></span>
							<?php esc_html_e( 'Expand All', 'wfbt-server-side' ); ?>
						</button>
						<button type="button" class="button" onclick="wfbtToggleAccordion(false);">
							<span class="dashicons dashicons-arrow-up-alt2" style="vertical-align: middle; font-size: 14px;"></span>
							<?php esc_html_e( 'Collapse All', 'wfbt-server-side' ); ?>
						</button>
					</div>
				</div>

				<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 15px; padding-top: 15px; border-top: 1px solid #f0f0f1;">
					<a href="https://adsmanager.facebook.com/events_manager2" target="_blank" rel="noopener noreferrer" class="button button-secondary">
						<span class="dashicons dashicons-external" style="vertical-align: middle; margin-right: 4px; font-size: 17px;"></span>
						<?php esc_html_e( 'Open Meta Events Manager', 'wfbt-server-side' ); ?>
					</a>
					<a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener noreferrer" class="button button-secondary">
						<span class="dashicons dashicons-admin-users" style="vertical-align: middle; margin-right: 4px; font-size: 17px;"></span>
						<?php esc_html_e( 'Meta System Users (Business Settings)', 'wfbt-server-side' ); ?>
					</a>
					<a href="https://chromewebstore.google.com/detail/meta-pixel-helper/fdgfkebogiimcoedlicjlajpkdmockpc" target="_blank" rel="noopener noreferrer" class="button button-secondary">
						<span class="dashicons dashicons-visibility" style="vertical-align: middle; margin-right: 4px; font-size: 17px;"></span>
						<?php esc_html_e( 'Meta Pixel Helper (Chrome Extension)', 'wfbt-server-side' ); ?>
					</a>
				</div>
			</div>

			<!-- Step 1 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">1</span>
						<span><?php esc_html_e( 'Find & Copy your Meta Pixel ID / Dataset ID', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to Meta Events Manager */
								__( 'Open %s.', 'wfbt-server-side' ),
								'<a href="https://adsmanager.facebook.com/events_manager2" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Meta Events Manager', 'wfbt-server-side' ) . '</strong> <span class="dashicons dashicons-external" style="font-size: 14px;"></span></a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'In the left sidebar, select your Business Portfolio, then click on "Data Sources" or "Datasets" (Ensembles de données) and choose your Pixel/Dataset.', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Click on the "Settings" (Paramètres) tab in the top horizontal bar.', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Locate the "Dataset ID" or "Pixel ID" section (a 15-16 digit numeric ID, e.g. 123456789012345). Click on it to copy it to your clipboard.', 'wfbt-server-side' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to plugin configuration tab */
								__( 'Return to this plugin, click on the %s tab above, paste the number into the "Meta Pixel ID" field, and click Save Changes.', 'wfbt-server-side' ),
								'<a href="?page=wfbt-settings&tab=configuration"><strong>' . esc_html__( 'Configuration', 'wfbt-server-side' ) . '</strong></a>'
							);
							?>
						</li>
					</ol>
				</div>
			</details>

			<!-- Step 2 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">2</span>
						<span><?php esc_html_e( 'Launch Conversions API (CAPI) Integration Wizard', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li><?php esc_html_e( 'In Meta Events Manager, on your Dataset "Settings" tab, scroll down to the "Conversions API" section.', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Under "Set up manually" (Configurer manuellement), click "Connect activity with the Conversions API" (Associer l\'activité avec l\'API Conversions) or "Get Started".', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'On the first "Overview" (Vue d\'ensemble) screen, click the blue "Continue" (Continuer) button.', 'wfbt-server-side' ); ?></li>
					</ol>
				</div>
			</details>

			<!-- Step 3 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">3</span>
						<span><?php esc_html_e( 'Select Events — Choose ONLY « Purchase » (Acheter)', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li><?php esc_html_e( 'On the "Select events" (Sélectionner des évènements) screen, open the category dropdown: "E-commerce and retail" (E-commerce et vente).', 'wfbt-server-side' ); ?></li>
						<li>
							<strong><?php esc_html_e( 'Check ONLY ONE event:', 'wfbt-server-side' ); ?></strong>
							<span class="wfbt-badge-check"><?php esc_html_e( 'Acheter (Purchase)', 'wfbt-server-side' ); ?></span>
						</li>
					</ol>

					<div class="wfbt-box-info">
						<strong>💡 <?php esc_html_e( 'Why choose ONLY « Purchase » for CAPI?', 'wfbt-server-side' ); ?></strong><br />
						<?php esc_html_e( 'In our native hybrid architecture, the front-end Browser Pixel (fbq) seamlessly handles all upstream browsing events (PageView, ViewContent, AddToCart, InitiateCheckout) in real-time. The server-side Conversions API (CAPI) is strictly dedicated to securing 100% of your Purchases against AdBlockers and iOS ITP.', 'wfbt-server-side' ); ?><br />
						<em><?php esc_html_e( 'If you select other events in this CAPI wizard, Meta will expect server signals that the plugin intentionally does not emit from the server, causing false warning alerts ("Missing server events") in your Events Manager diagnostics!', 'wfbt-server-side' ); ?></em>
					</div>

					<p><?php esc_html_e( 'Click the blue "Continue" (Continuer) button in the bottom right corner.', 'wfbt-server-side' ); ?></p>
				</div>
			</details>

			<!-- Step 4 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">4</span>
						<span><?php esc_html_e( 'Select Event Details & Customer Parameters (Exact Checkbox Guide)', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<p><?php esc_html_e( 'On the "Select event details" (Sélectionner les détails des évènements) screen, configure the parameters that our plugin sends to Meta for the Purchase event:', 'wfbt-server-side' ); ?></p>

					<h4 style="margin-top: 15px; margin-bottom: 8px; color: #1d2327;">
						<?php esc_html_e( 'A. Event Parameters (Top of screen):', 'wfbt-server-side' ); ?>
					</h4>
					<ul>
						<li>
							<em><?php esc_html_e( 'Pre-checked by default:', 'wfbt-server-side' ); ?></em>
							<?php esc_html_e( 'Event time, Event name, Action source, Event source URL, Currency, Value.', 'wfbt-server-side' ); ?>
						</li>
						<li>
							👉 <strong><?php esc_html_e( 'MANDATORY CHECKBOX TO TICK:', 'wfbt-server-side' ); ?></strong>
							<span class="wfbt-badge-check"><?php esc_html_e( 'ID de l\'événement (Event ID)', 'wfbt-server-side' ); ?></span>
							<br /><small style="color: #646970;"><?php esc_html_e( 'Essential! This transmits "order_12345" and ensures 100% perfect deduplication with the browser pixel.', 'wfbt-server-side' ); ?></small>
						</li>
						<li>
							<span class="wfbt-badge-skip"><?php esc_html_e( 'Leave unchecked:', 'wfbt-server-side' ); ?></span>
							<?php esc_html_e( 'Opt out (Se désinscrire), Data Processing Options.', 'wfbt-server-side' ); ?>
						</li>
					</ul>

					<h4 style="margin-top: 20px; margin-bottom: 8px; color: #1d2327;">
						<?php esc_html_e( 'B. Customer Information Parameters (Bottom of screen):', 'wfbt-server-side' ); ?>
					</h4>
					<p><?php esc_html_e( 'Check the following parameters according to the 3 columns displayed by Meta:', 'wfbt-server-side' ); ?></p>

					<table class="wfbt-param-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Left Column', 'wfbt-server-side' ); ?></th>
								<th><?php esc_html_e( 'Center Column', 'wfbt-server-side' ); ?></th>
								<th><?php esc_html_e( 'Right Column', 'wfbt-server-side' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td>
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Client IP address - Do not hash', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'City (Ville)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'First name (Prénom)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Phone (Téléphone)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Postal code (Code postal)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-skip">✗ <?php esc_html_e( 'External ID (Leave unchecked)', 'wfbt-server-side' ); ?></span>
								</td>
								<td>
									<span style="color: #646970;">✓ <?php esc_html_e( 'Client user agent (Pre-checked)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Click ID cookie (fbc) - Do not hash', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'State (État / Région)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-skip">✗ <?php esc_html_e( 'Date of birth (Leave unchecked)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-skip">✗ <?php esc_html_e( 'Gender (Leave unchecked)', 'wfbt-server-side' ); ?></span>
								</td>
								<td>
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Country (Pays)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Email (E-mail)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Browser ID cookie (fbp) - Do not hash', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-check">✓ <?php esc_html_e( 'Last name (Nom de famille)', 'wfbt-server-side' ); ?></span><br />
									<span class="wfbt-badge-skip">✗ <?php esc_html_e( 'Subscription ID (Leave unchecked)', 'wfbt-server-side' ); ?></span>
								</td>
							</tr>
						</tbody>
					</table>

					<p><?php esc_html_e( 'Click "Continue" (Continuer) -> On the "Review setup" (Vérifier la configuration) screen, click "Continue" -> On the "See instructions" screen, click "Finish" (Terminer).', 'wfbt-server-side' ); ?></p>
				</div>
			</details>

			<!-- Step 5 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">5</span>
						<span><?php esc_html_e( 'Generate & Copy your Conversions API Access Token', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li><?php esc_html_e( 'On the "Generate an access token" (Générer un token d\'accès) screen (or in Dataset Settings > Conversions API > Set up manually > "Generate access token"):', 'wfbt-server-side' ); ?></li>
						<li>
							<strong><?php esc_html_e( 'Keep the recommended radio option selected:', 'wfbt-server-side' ); ?></strong><br />
							<span class="wfbt-badge-check"><?php esc_html_e( 'Configurer avec Dataset Quality API (Recommended)', 'wfbt-server-side' ); ?></span><br />
							<small style="color: #646970;"><?php esc_html_e( 'This produces a comprehensive long-lived token compatible with performance monitoring.', 'wfbt-server-side' ); ?></small>
						</li>
						<li>
							<?php esc_html_e( 'Click the blue button "Generate an access token" (Générer un token d\'accès).', 'wfbt-server-side' ); ?>
						</li>
						<li>
							<div class="wfbt-box-warning">
								<strong>⚠️ <?php esc_html_e( 'IMMEDIATE ACTION REQUIRED:', 'wfbt-server-side' ); ?></strong><br />
								<?php esc_html_e( 'A box will appear showing a very long token string (starting with "EAAB..."). Click "Copy" immediately. Meta DOES NOT store this token and will never show it again once you navigate away!', 'wfbt-server-side' ); ?>
							</div>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to plugin configuration tab */
								__( 'Paste this token into the "Conversions API Access Token" field in the %s tab, and click Save Changes.', 'wfbt-server-side' ),
								'<a href="?page=wfbt-settings&tab=configuration"><strong>' . esc_html__( 'Configuration', 'wfbt-server-side' ) . '</strong></a>'
							);
							?>
						</li>
					</ol>

					<div class="wfbt-box-info">
						<strong><?php esc_html_e( 'Alternative (System User Token):', 'wfbt-server-side' ); ?></strong><br />
						<?php
						printf(
							/* translators: %s: HTML link to Meta Business Settings */
							__( 'If the "Generate access token" button is disabled or grayed out in Events Manager, navigate to %s, select or create an Admin System User, assign your Dataset asset with "Manage" permissions, and generate a token with the "ads_management" permission.', 'wfbt-server-side' ),
							'<a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Meta Business Settings > System Users', 'wfbt-server-side' ) . '</strong></a>'
						);
						?>
					</div>
				</div>
			</details>

			<!-- Step 6 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">6</span>
						<span><?php esc_html_e( 'Configure GDPR Consent & Trigger Statuses', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<p><?php esc_html_e( 'In the plugin Configuration tab, review these key settings:', 'wfbt-server-side' ); ?></p>
					<ul>
						<li>
							<strong><?php esc_html_e( 'Enable Browser Pixel:', 'wfbt-server-side' ); ?></strong>
							<?php esc_html_e( 'Ensure this checkbox is checked. This enables client-side tracking for PageView, ViewContent (product page), AddToCart (AJAX), InitiateCheckout, and client-side Purchase.', 'wfbt-server-side' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Concord Cookie Banner integration:', 'wfbt-server-side' ); ?></strong>
							<?php esc_html_e( 'Check "Require marketing consent from Concord Cookie Banner". The default cookie prefix is "concord". The plugin automatically listens to Concord consent acceptance in real-time without requiring a page reload!', 'wfbt-server-side' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Action when consent is refused:', 'wfbt-server-side' ); ?></strong>
							<?php esc_html_e( 'The default and recommended choice is "Send anonymized request". If a customer refuses marketing cookies, CAPI still transmits the order total, currency, purchased item IDs, and deduplication eventID, but strips all customer PII (email, phone, name, address), strips advertising cookies (_fbp, _fbc), and removes IP address / User Agent.', 'wfbt-server-side' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Trigger statuses:', 'wfbt-server-side' ); ?></strong>
							<?php esc_html_e( 'By default, "Processing" (En cours) and "Completed" (Terminée) are enabled. The background Action Scheduler queues the CAPI event as soon as the order transitions into either of these statuses with 100% anti-duplicate safety.', 'wfbt-server-side' ); ?>
						</li>
					</ul>
				</div>
			</details>

			<!-- Step 7 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">7</span>
						<span><?php esc_html_e( 'Test the Connection in Real-Time (Test Event Code)', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to Meta Events Manager */
								__( 'In %s, click on the "Test events" (Tester les événements) tab.', 'wfbt-server-side' ),
								'<a href="https://adsmanager.facebook.com/events_manager2" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Meta Events Manager', 'wfbt-server-side' ) . '</strong></a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'In the section "Confirm your server events are set up correctly" (Confirmer que les événements de votre serveur sont configurés correctement), copy the test code (e.g. TEST12345).', 'wfbt-server-side' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to Configuration tab */
								__( 'Paste this code into the "Test Event Code" field in the %s tab and click Save Changes.', 'wfbt-server-side' ),
								'<a href="?page=wfbt-settings&tab=configuration"><strong>' . esc_html__( 'Configuration', 'wfbt-server-side' ) . '</strong></a>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to Diagnostics tab */
								__( 'Go to the %s tab and click the blue button "Test API Connection (CAPI v21.0)".', 'wfbt-server-side' ),
								'<a href="?page=wfbt-settings&tab=diagnostics"><strong>' . esc_html__( 'Diagnostics & Orders', 'wfbt-server-side' ) . '</strong></a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Switch to your Meta Events Manager screen: you will see a test "Purchase" event appear in green within 2 seconds!', 'wfbt-server-side' ); ?></li>
						<li>
							<div class="wfbt-box-warning">
								<strong>⚠️ <?php esc_html_e( 'Crucial Step before going live:', 'wfbt-server-side' ); ?></strong><br />
								<?php esc_html_e( 'Once you have confirmed the test event works, return to the Configuration tab, delete the Test Event Code, and click Save Changes. Leaving the test code in place would mark real production orders as test events in Meta!', 'wfbt-server-side' ); ?>
							</div>
						</li>
					</ol>
				</div>
			</details>

			<!-- Step 8 -->
			<details class="wfbt-accordion-step">
				<summary class="wfbt-accordion-summary">
					<div class="wfbt-step-header">
						<span class="wfbt-step-num">8</span>
						<span><?php esc_html_e( 'Verify Front-End Tracking & 100% Deduplication', 'wfbt-server-side' ); ?></span>
					</div>
					<span class="dashicons dashicons-arrow-down-alt2 wfbt-step-arrow"></span>
				</summary>
				<div class="wfbt-step-body">
					<ol>
						<li>
							<?php
							printf(
								/* translators: %s: HTML link to Chrome Web Store Meta Pixel Helper */
								__( 'Install the official %s extension in Google Chrome.', 'wfbt-server-side' ),
								'<a href="https://chromewebstore.google.com/detail/meta-pixel-helper/fdgfkebogiimcoedlicjlajpkdmockpc" target="_blank" rel="noopener noreferrer"><strong>' . esc_html__( 'Meta Pixel Helper', 'wfbt-server-side' ) . '</strong> <span class="dashicons dashicons-external" style="font-size: 14px;"></span></a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Open your store in an incognito window with a test click parameter in the URL: yourstore.com/?fbclid=TEST_SOYOO_CLICK_123', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Accept marketing cookies on your cookie banner: Pixel Helper will immediately detect the Pixel initialization and fire PageView without requiring a reload.', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Visit a product: ViewContent fires. Add to cart: AddToCart fires. Proceed to checkout: InitiateCheckout fires.', 'wfbt-server-side' ); ?></li>
						<li>
							<?php esc_html_e( 'Complete a test order. On the Order Received (Thank You) page, Pixel Helper will display the Purchase event with an eventID parameter (e.g. order_1234).', 'wfbt-server-side' ); ?>
						</li>
						<li>
							<?php esc_html_e( 'In Meta Events Manager, check your events: Meta receives both the Browser event (Pixel) and the Server event (CAPI) sharing the exact same eventID ("order_1234") and automatically merges them into a single deduplicated conversion with a green status icon.', 'wfbt-server-side' ); ?>
						</li>
					</ol>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * AJAX: Test Connection to Meta CAPI (Graph API v21.0).
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'wfbt_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wfbt-server-side' ) );
		}

		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$access_token = get_option( 'wfbt_access_token', '' );
		$test_code    = get_option( 'wfbt_test_code', '' );

		if ( empty( $pixel_id ) || empty( $access_token ) ) {
			wp_send_json_error( __( 'Meta Pixel ID or Access Token is missing in settings.', 'wfbt-server-side' ) );
		}

		$url = sprintf( 'https://graph.facebook.com/%s/%s/events', Meta_Api::API_VERSION, rawurlencode( $pixel_id ) );

		$payload = array(
			'event_name'        => 'Purchase',
			'event_time'        => time(),
			'action_source'     => 'website',
			'event_source_url'  => home_url(),
			'user_data'         => array(
				'em'                => array( hash( 'sha256', 'test@soyoo.re' ) ),
				'ph'                => array( hash( 'sha256', '262692000000' ) ),
				'client_ip_address' => '127.0.0.1',
				'client_user_agent' => 'Mozilla/5.0 (Test Connection Meta CAPI)',
			),
			'custom_data'       => array(
				'currency' => 'EUR',
				'value'    => 10.00,
			),
			'event_id'          => 'test_' . time(),
		);

		$body = array(
			'data'         => array( $payload ),
			'access_token' => $access_token,
		);

		if ( ! empty( $test_code ) ) {
			$body['test_event_code'] = $test_code;
		}

		$response = wp_remote_post( $url, array(
			'method'  => 'POST',
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'WP_Error: ' . $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_json   = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			$decoded = json_decode( $body_json, true );
			$msg     = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : $body_json;
			wp_send_json_error( "HTTP {$status_code}: {$msg}" );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Resend Order to Meta (HPOS compatible).
	 */
	public static function ajax_resend_order() {
		check_ajax_referer( 'wfbt_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wfbt-server-side' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'wfbt-server-side' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( __( 'Order not found.', 'wfbt-server-side' ) );
		}

		// Update order HPOS metadata to Pending and clear previous errors
		$order->update_meta_data( '_wfbt_capi_status', 'Pending' );
		$order->delete_meta_data( '_wfbt_capi_error' );
		$order->delete_meta_data( '_wfbt_capi_scheduled' );
		$order->save();

		// Schedule background action via Action Scheduler
		Background_Processor::schedule_event( $order_id );

		wp_send_json_success();
	}
}
