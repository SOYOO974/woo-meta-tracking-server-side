<?php

namespace WFBT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom Logger Class integrating with WC_Logger
 */
class Logger {

	/**
	 * Log a message to the WooCommerce logger.
	 *
	 * @param string $message The message to log.
	 * @param string $level   The log level (info, warning, error, etc.).
	 */
	public static function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger  = wc_get_logger();
			$context = array( 'source' => 'wfbt-server-side' );
			$logger->log( $level, $message, $context );
		}
	}
}
