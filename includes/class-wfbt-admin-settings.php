<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings Class
 */
class Admin_Settings {

	/**
	 * Initialize Admin Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );

		// Ajax actions for Diagnostics tab.
		add_action( 'wp_ajax_wfbt_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wfbt_resend_order', array( __CLASS__, 'ajax_resend_order' ) );
	}

	/**
	 * Add Settings Page to WooCommerce menu.
	 */
	public static function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Meta CAPI Server-Side', 'wfbt-server-side' ),
			__( 'Meta CAPI', 'wfbt-server-side' ),
			'manage_woocommerce',
			'wfbt-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting( 'wfbt_settings_group', 'wfbt_pixel_id' );
		register_setting( 'wfbt_settings_group', 'wfbt_access_token' );
		register_setting( 'wfbt_settings_group', 'wfbt_test_code' );
		register_setting( 'wfbt_settings_group', 'wfbt_enable_alerts' );
		register_setting( 'wfbt_settings_group', 'wfbt_alert_email' );
	}

	/**
	 * Enqueue admin scripts for the diagnostics page.
	 *
	 * @param string $hook The current admin page.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( 'woocommerce_page_wfbt-settings' !== $hook ) {
			return;
		}

		// Inline script for Diagnostics AJAX.
		$script = "
			jQuery(document).ready(function($){
				$('#wfbt-test-connection').on('click', function(e){
					e.preventDefault();
					var btn = $(this);
					btn.prop('disabled', true).text('Testing...');
					$('#wfbt-test-result').html('');
					
					$.post( ajaxurl, { action: 'wfbt_test_connection', nonce: '" . wp_create_nonce( 'wfbt_admin_nonce' ) . "' }, function(response){
						btn.prop('disabled', false).text('Test API Connection');
						if(response.success) {
							$('#wfbt-test-result').html('<span style=\"color:green;\">Success!</span>');
						} else {
							$('#wfbt-test-result').html('<span style=\"color:red;\">Failed: ' + response.data + '</span>');
						}
					});
				});

				$('.wfbt-resend-btn').on('click', function(e){
					e.preventDefault();
					var btn = $(this);
					var order_id = btn.data('order-id');
					btn.prop('disabled', true).text('Queuing...');

					$.post( ajaxurl, { action: 'wfbt_resend_order', order_id: order_id, nonce: '" . wp_create_nonce( 'wfbt_admin_nonce' ) . "' }, function(response){
						if(response.success) {
							btn.text('Queued!');
						} else {
							btn.prop('disabled', false).text('Failed');
							alert('Error: ' + response.data);
						}
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
			<h1><?php esc_html_e( 'Woo FB Tracking Server-Side', 'wfbt-server-side' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a href="?page=wfbt-settings&tab=configuration" class="nav-tab <?php echo 'configuration' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Configuration', 'wfbt-server-side' ); ?></a>
				<a href="?page=wfbt-settings&tab=tutorial" class="nav-tab <?php echo 'tutorial' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tutorial', 'wfbt-server-side' ); ?></a>
				<a href="?page=wfbt-settings&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Diagnostics', 'wfbt-server-side' ); ?></a>
			</h2>
			<div class="wfbt-settings-content" style="margin-top: 20px;">
				<?php
				if ( 'configuration' === $active_tab ) {
					self::render_configuration_tab();
				} elseif ( 'tutorial' === $active_tab ) {
					self::render_tutorial_tab();
				} elseif ( 'diagnostics' === $active_tab ) {
					self::render_diagnostics_tab();
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
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wfbt_settings_group' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Meta Pixel ID', 'wfbt-server-side' ); ?></th>
					<td><input type="text" name="wfbt_pixel_id" value="<?php echo esc_attr( get_option( 'wfbt_pixel_id' ) ); ?>" class="regular-text" /></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Meta Conversions API Access Token', 'wfbt-server-side' ); ?></th>
					<td><textarea name="wfbt_access_token" rows="4" class="large-text"><?php echo esc_textarea( get_option( 'wfbt_access_token' ) ); ?></textarea></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Test Event Code (Optional)', 'wfbt-server-side' ); ?></th>
					<td><input type="text" name="wfbt_test_code" value="<?php echo esc_attr( get_option( 'wfbt_test_code' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Enter the Test Event Code from your Meta Events Manager to test events.', 'wfbt-server-side' ); ?></p></td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Enable Email Alerts', 'wfbt-server-side' ); ?></th>
					<td>
						<input type="checkbox" name="wfbt_enable_alerts" value="yes" <?php checked( get_option( 'wfbt_enable_alerts' ), 'yes' ); ?> />
						<label><?php esc_html_e( 'Send an email if an API request fails.', 'wfbt-server-side' ); ?></label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><?php esc_html_e( 'Alert Email Address', 'wfbt-server-side' ); ?></th>
					<td><input type="email" name="wfbt_alert_email" value="<?php echo esc_attr( get_option( 'wfbt_alert_email' ) ); ?>" class="regular-text" /></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render Tutorial Tab.
	 */
	private static function render_tutorial_tab() {
		?>
		<div class="card" style="max-width: 800px; padding: 20px;">
			<h3><?php esc_html_e( 'How to configure this plugin', 'wfbt-server-side' ); ?></h3>
			<p><?php esc_html_e( 'Follow these exact steps in the Meta Events Manager to set up the Conversions API:', 'wfbt-server-side' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'Go to your Meta Events Manager and select your Pixel.', 'wfbt-server-side' ); ?></li>
				<li><?php esc_html_e( 'Click on "Settings" and copy your "Pixel ID" into the Configuration tab.', 'wfbt-server-side' ); ?></li>
				<li><?php esc_html_e( 'Under the "Conversions API" section, click on "Set up manually" (Configurer manuellement).', 'wfbt-server-side' ); ?></li>
				<li><?php esc_html_e( 'Select the "Purchase" (Acheter) event and click Continue.', 'wfbt-server-side' ); ?></li>
				<li>
					<strong><?php esc_html_e( 'Select Event Detail Parameters (Paramètres des détails des événements):', 'wfbt-server-side' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'Event Time (Heure de l\'événement)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Event Name (Nom de l\'événement)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Event Source URL (URL de la source de l\'événement)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Action Source (Origine de l\'action)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Currency (Devise)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Value (Valeur)', 'wfbt-server-side' ); ?></li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Select Customer Information Parameters (Paramètres des informations client):', 'wfbt-server-side' ); ?></strong>
					<ul>
						<li><?php esc_html_e( 'Client IP Address (Adresse IP client)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Client User Agent (Agent utilisateur client)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Email (E-mail)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Phone (Téléphone)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'First Name (Prénom)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Last Name (Nom de famille)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'City (Ville)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'State (État)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Zip/Postal Code (Code postal)', 'wfbt-server-side' ); ?></li>
						<li><?php esc_html_e( 'Country (Pays)', 'wfbt-server-side' ); ?></li>
					</ul>
				</li>
				<li><?php esc_html_e( 'Finish the setup, then click "Generate access token" (Générer un token d\'accès).', 'wfbt-server-side' ); ?></li>
				<li><?php esc_html_e( 'Copy the Token and paste it into the Configuration tab.', 'wfbt-server-side' ); ?></li>
				<li><?php esc_html_e( 'Save the settings, and test the connection in the Diagnostics tab using your Test Event Code!', 'wfbt-server-side' ); ?></li>
			</ol>
		</div>
		<?php
	}

	/**
	 * Render Diagnostics Tab.
	 */
	private static function render_diagnostics_tab() {
		?>
		<div style="margin-bottom: 30px;">
			<h3><?php esc_html_e( 'Test Connection', 'wfbt-server-side' ); ?></h3>
			<p><?php esc_html_e( 'Click the button below to send a dummy Purchase event using your Test Event Code.', 'wfbt-server-side' ); ?></p>
			<button id="wfbt-test-connection" class="button button-primary"><?php esc_html_e( 'Test API Connection', 'wfbt-server-side' ); ?></button>
			<span id="wfbt-test-result" style="margin-left: 10px; font-weight: bold;"></span>
		</div>

		<hr />

		<h3><?php esc_html_e( 'Last 20 WooCommerce Orders', 'wfbt-server-side' ); ?></h3>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order ID', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( 'Date', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( 'Total', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( 'Meta CAPI Status', 'wfbt-server-side' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wfbt-server-side' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$orders = wc_get_orders( array(
					'limit'   => 20,
					'orderby' => 'date',
					'order'   => 'DESC',
				) );

				if ( empty( $orders ) ) {
					echo '<tr><td colspan="5">' . esc_html__( 'No orders found.', 'wfbt-server-side' ) . '</td></tr>';
				} else {
					foreach ( $orders as $order ) {
						$order_id   = $order->get_id();
						$status     = get_post_meta( $order_id, '_wfbt_capi_status', true ) ?: 'Pending';
						$error      = get_post_meta( $order_id, '_wfbt_capi_error', true );
						$status_txt = $status;
						if ( 'Failed' === $status && $error ) {
							$status_txt .= ' <br/><small style="color:red;">' . esc_html( $error ) . '</small>';
						}

						echo '<tr>';
						echo '<td>#' . esc_html( $order_id ) . '</td>';
						echo '<td>' . esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '' ) . '</td>';
						echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
						echo '<td>' . wp_kses_post( $status_txt ) . '</td>';
						echo '<td><button class="button wfbt-resend-btn" data-order-id="' . esc_attr( $order_id ) . '">' . esc_html__( 'Resend to Meta', 'wfbt-server-side' ) . '</button></td>';
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * AJAX: Test Connection to Meta CAPI.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'wfbt_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$pixel_id     = get_option( 'wfbt_pixel_id', '' );
		$access_token = get_option( 'wfbt_access_token', '' );
		$test_code    = get_option( 'wfbt_test_code', '' );

		if ( empty( $pixel_id ) || empty( $access_token ) ) {
			wp_send_json_error( 'Pixel ID or Access Token is missing.' );
		}

		$url = "https://graph.facebook.com/v19.0/{$pixel_id}/events";
		$payload = array(
			'event_name' => 'Purchase',
			'event_time' => time(),
			'action_source' => 'website',
			'user_data' => array(
				'em' => array( hash( 'sha256', 'test@example.com' ) ),
			),
			'custom_data' => array(
				'currency' => 'USD',
				'value' => 10.00,
			),
			'event_id' => 'test_' . time(),
		);

		$body = array(
			'data' => array( $payload ),
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
			wp_send_json_error( "HTTP {$status_code}: " . print_r( json_decode($body_json, true), true ) ); // Avoid exposing raw JSON string without parsing on UI
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: Resend Order to Meta.
	 */
	public static function ajax_resend_order() {
		check_ajax_referer( 'wfbt_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( 'Invalid order ID.' );
		}

		// Update order meta to pending and clear errors.
		update_post_meta( $order_id, '_wfbt_capi_status', 'Pending' );
		delete_post_meta( $order_id, '_wfbt_capi_error' );
		delete_post_meta( $order_id, '_wfbt_capi_scheduled' );

		// Schedule background action again.
		Background_Processor::schedule_event( $order_id );

		wp_send_json_success();
	}
}
