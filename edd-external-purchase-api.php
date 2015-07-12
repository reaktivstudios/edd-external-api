<?php
/*
Plugin Name: Easy Digital Downloads - External Purchase API
Plugin URI: http://easydigitaldownloads.com/extension/external-purchase-api/
Description: Provides an API endpoint for creating sales on third party sites
Version: 0.0.2
Author: Reaktiv Studios
Author URI:  http://andrewnorcross.com
Contributors: norcross
*/

if( ! defined( 'EDD_EXAPI_DIR' ) ) {
	define( 'EDD_EXAPI_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * EDD_External_API Class
 *
 * Renders API returns as a JSON/XML array
 *
 * @since  1.5
 */
class EDD_External_Purchase_API {

	/**
	 * API Version
	 */
	const VERSION = '0.0.2';

	/**
	 * Pretty Print?
	 *
	 * @var bool
	 * @access private
	 * @since 1.5
	 */
	private $pretty_print = false;

	/**
	 * Log API requests?
	 *
	 * @var bool
	 * @access private
	 * @since 1.5
	 */
	private $log_requests = true;

	/**
	 * Is this a valid request?
	 *
	 * @var bool
	 * @access private
	 * @since 1.5
	 */
	private $is_valid_request = false;

	/**
	 * User ID Performing the API Request
	 *
	 * @var int
	 * @access private
	 * @since 1.5.1
	 */
	private $user_id = 0;

	/**
	 * Instance of EDD Stats class
	 *
	 * @var object
	 * @access private
	 * @since 1.7
	 */
	private $stats;

	/**
	 * Response data to return
	 *
	 * @var array
	 * @access private
	 * @since 1.5.2
	 */
	private $data = array();

	/**
	 *
	 * @var bool
	 * @access private
	 * @since 1.7
	 */
	private $override = true;

	/**
	 * Setup the EDD API
	 *
	 * @author Daniel J Griffiths
	 * @since 1.5
	 */
	public function __construct() {

		if ( ! function_exists( 'edd_price' ) ) {
			return; // EDD not present
		}
		// include our logging functions
		require_once( EDD_EXAPI_DIR . 'lib/edd-external-api-log.php' );

		add_action( 'init',                   array( __CLASS__, 'add_endpoint'  )    );
		add_action( 'init',                   array( __CLASS__, 'verify_table'  )    );
		add_action( 'template_redirect',      array( $this,     'process_query' ), 1 );
		add_filter( 'query_vars',             array( $this,     'query_vars'    )    );
		add_filter( 'edd_external_whitelist', array( $this,     'whitelist'     )    );
	}

	/**
	 * our activation sequence
	 * @return [type] [description]
	 */
	public static function activate() {
		self::add_endpoint();
		self::verify_table();
		flush_rewrite_rules();
	}

	/**
	 * Registers a new rewrite endpoint for accessing the API
	 *
	 * @access public
	 * @author Andrew Norcross
	 * @since 1.5
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( 'edd-external-api', EP_ALL );
	}

	/**
	 * Looks to see if the logging table exists
	 * and if not, calls the creation
	 *
	 * @access public
	 * @author Andrew Norcross
	 * @since 1.5
	 */
	public static function verify_table() {

		// bail if disabled
		if ( false === apply_filters( 'edd_external_logging', true ) ) {
			return;
		}

		// call the global
		global $wpdb;

		// set the name
		$name   = $wpdb->prefix . "edd_external_log";

		// check for existance of table
		if( $wpdb->get_var( "SHOW TABLES LIKE '$name'" ) != $name ) {
			// no table, so make it
			EDD_External_Purchase_API_Log::create_table();
		}
	}

	/**
	 * Registers query vars for API access
	 *
	 * @access public
	 * @since 1.5
	 * @author Daniel J Griffiths
	 * @param array $vars Query vars
	 * @return array $vars New query vars
	 */
	public function query_vars( $vars ) {

		// add our new vars
		$vars[] = 'token';
		$vars[] = 'key';
		$vars[] = 'trans_type';
		$vars[] = 'payment_id';
		$vars[] = 'product_id';
		$vars[] = 'price';
		$vars[] = 'first_name';
		$vars[] = 'last_name';
		$vars[] = 'email';
		$vars[] = 'source_name';
		$vars[] = 'source_url';
		$vars[] = 'receipt';

		// return the vars
		return $vars;
	}

	/**
	 * Retrieve the user ID based on the public key provided
	 *
	 * @access public
	 * @since 1.5.1
	 * @global object $wpdb Used to query the database using the WordPress
	 * Database API
	 *
	 * @param string $key Public Key
	 *
	 * @return bool if user ID is found, false otherwise
	 */
	public function get_user( $key = '' ) {

		// call the globals
		global $wpdb, $wp_query;

		// check for a key being passed
		if ( empty( $key ) ) {
			$key = urldecode( $wp_query->query_vars['key'] );
		}

		// bail with no key
		if ( empty( $key ) ) {
			return false;
		}

		// do the lookup
		$user = $wpdb->get_var( $wpdb->prepare( "
			SELECT user_id
			FROM $wpdb->usermeta
			WHERE meta_key = '%s'
			LIMIT 1",
		$key ) );

		// we have something. return it
		if ( $user != NULL ) {
			$this->user_id = $user;
			return $user;
		}

		// bail if not there
		return false;
	}

	/**
	 * fetch the product type (standard or bundle)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_type( $product_id ) {

		// fetch it
		$type   = get_post_meta( $product_id, '_edd_product_type', true );

		// return the type
		return ! empty ( $type ) ? $type : 'default';
	}

	/**
	 * fetch the product downloads (standard or bundle)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_files( $product_id ) {

		// check item type
		$itemtype   = $this->get_product_type( $product_id );

		// fetch download files for single items
		if ( $itemtype == 'default' ) {

			// set the data
			$data[] = array(
				'id'    => absint( $product_id ),
				'file'  => edd_get_download_files( $product_id ),
				'name'  => $this->get_product_name( $product_id ),
			);

			// return the data
			return $data;
		}

		// get the bundles
		$bundles    = get_post_meta( $product_id, '_edd_bundled_products', true );

		// nothing? bail
		if ( empty( $bundles ) ) {
			return false;
		}

		// loop it
		foreach ( $bundles as $bundle_id ) {
			$data[] = array(
				'id'    => absint( $bundle_id ),
				'file'  => edd_get_download_files( $bundle_id ),
				'name'  => $this->get_product_name( $bundle_id ),
			);
		}

		// return the data
		return $data;
	}

	/**
	 * fetch the product price (or zero)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_price( $product_id ) {

		// get the price
		$price  = get_post_meta( $product_id, 'edd_price', true );

		// return it
		return ! empty ( $price ) ? edd_sanitize_amount( $price ) : 0;
	}

	/**
	 * fetch the custom product name with standard fallback
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_name( $product_id ) {

		// fetch the custom
		$custom = get_post_meta( $product_id, '_edd_external_title', true );

		// if we have a custom, return that
		if ( ! empty( $custom ) ) {
			return esc_html( $custom );
		}

		// fetch the base one
		$title   = get_the_title( $product_id );

		// check for and return
		return ! empty( $title ) ? esc_html( $title ) : 'EDD Product';
	}

	/**
	 * fetch the product license key (if one exists)
	 * @param  int $payment_id payment ID in the database
	 * @return string
	 */
	public function get_product_license( $payment_id ) {

		// fetch payment data based on ID with license
		$args = array(
			'fields'        => 'ids',
			'nopaging'      => true,
			'meta_key'      => '_edd_sl_payment_id',
			'meta_value'    => $payment_id,
			'post_type'     => 'edd_license',
			'post_status'   => 'any'
		);

		// fetch the licenses
		$licenses = get_posts( $args );

		// bail with none
		if ( empty( $licenses ) ) {
			return false;
		}

		// set an empty default
		$license_keys   = array();

		// fetch license keys and make sure they are in an array to match the download items
		foreach ( $licenses as $license_id ) {
			$license_keys[] = get_post_meta( $license_id, '_edd_sl_key', true );
		}

		// send back key(s)
		return ! empty( $license_keys ) ? $license_keys : false;
	}

	/**
	 * fetch the download URL
	 * @param  string $url
	 * @return bool
	 */
	public function get_product_download_url( $payment_id, $product_id ) {

		$payment_key    = get_post_meta( $payment_id, '_edd_payment_purchase_key', true );
		$payment_email  = get_post_meta( $payment_id, '_edd_payment_user_email', true );

		$params = array(
			'download_key'  => $payment_key,
			'email'         => rawurlencode( $payment_email ),
			'file'          => 0,
			'price_id'      => 0,
			'download_id'   => $product_id,
			'expire'        => rawurlencode( base64_encode( 2147472000 ) )
		);

		// add my args
		$download_url   = add_query_arg( $params, home_url( 'index.php' ) );

		// return the download URL
		return $download_url;
	}

	/**
	 * [fetch_download_urls description]
	 * @return [type] [description]
	 */
	public function fetch_download_data( $payment_id, $product_id ) {

		$downloads      = $this->get_product_files( $product_id );
		$licenses       = $this->get_product_license( $payment_id );

		// set the data return to an array
		$download_data  = array();

		// loop them into a key / value
		foreach ( $downloads as $filekey => $file ) {

			$download_url       = $this->get_product_download_url( $payment_id, $file['id'] );

			// match up licenses to the applicable download item
			$download_license   = isset( $licenses[ $filekey ] ) ? $licenses[ $filekey ] : '';

			$download_data[]    = array(
				'product_id'        => absint( $file['id'] ),
				'product_name'      => esc_attr( $file['name'] ),
				'download_link'     => $download_url,
				'license_key'       => $download_license
			);

		}

		// return the data
		return $download_data;
	}

	/**
	 * [fetch_purchase_data description]
	 * @param  [type] $payment_id [description]
	 * @return [type]             [description]
	 */
	public function fetch_purchase_data( $payment_id ) {

		$purchase_key   = get_post_meta( $payment_id, '_edd_payment_purchase_key', true );
		$purchase_total = get_post_meta( $payment_id, '_edd_payment_total', true );

		$purchase_date  = get_post_field( 'post_date', $payment_id, 'raw' );
		$purchase_stamp = strtotime( $purchase_date );

		$external_meta  = get_post_meta( $payment_id, '_edd_external_purchase_meta', true );

		$source_name    = ! empty( $external_meta['source_name'] ) ? esc_html( $external_meta['source_name'] ) : '';
		$source_url     = ! empty( $external_meta['source_url'] ) ? esc_url( $external_meta['source_url'] ) : '';

		return array(
			'external_source'   => $source_name,
			'external_url'      => $source_url,
			'purchase_key'      => $purchase_key,
			'purchase_total'    => $purchase_total,
			'purchase_date'     => $purchase_date,
			'purchase_stamp'    => $purchase_stamp
		);
	}

	/**
	 * confirm the ID being passed is actually a live item and not something else
	 * @param  int $item_id content ID in the database
	 * @return bool
	 */
	public function confirm_id_exists( $item_id = 0, $type = 'download' ) {

		// fetch the item
		$item   = get_post( $item_id );

		// bail if nothing
		if ( ! $item || ! is_object( $item ) ) {
			return false;
		}

		// bail if not a download
		if ( $item->post_type != $type ) {
			return false;
		}

		// bail if not published
		if ( $item->post_status != 'publish' ) {
			return false;
		}

		// return true
		return true;
	}

	/**
	 * confirm the source URL is whitelisted
	 * @param  string $url
	 * @return bool
	 */
	public function confirm_source_url( $source_url ) {

		// first run bypass filter for those LIVIN ON THE EDGE
		if ( false === $disable = apply_filters( 'edd_external_whitelist_enabled', true ) ) {
			return true;
		}

		// grab the source
		$source     = $this->strip_url( $source_url );

		// check the whitelist
		$whitelist  = apply_filters( 'edd_external_whitelist', array() );
		$whitelist  = ! is_array( $whitelist ) ? array( $whitelist ) : $whitelist;

		// check for a whitelist
		if ( ! $whitelist || empty( $whitelist ) ) {
			return false;
		}

		// loop through the URLs and strip them
		foreach ( $whitelist as $url ) {
			$list[] = $this->strip_url( $url );
		}

		// check said whitelist and return if you're on the list
		return ! in_array( $source, $list ) ? false : true;
	}

	/**
	 * strip the URL down to the host
	 *
	 * @param  string $url
	 * @return string
	 */
	public function strip_url( $url ) {

		// check for the http or https and add it if it's missing
		if ( ! preg_match( '~^(?:f|ht)tps?://~i', $url ) ) {
			$url = 'http://' . $url;
		}

		// clean up the damn link
		$parsed = parse_url( $url );
		$host   = $parsed['host'];
		$parts  = explode( '.', $host );
		// Give us only the last two parts of the host (domain and TLD)
		$domain = join( '.', array_slice( $parts, -2 ) );

		// return the domain
		return $domain;
	}

	/**
	 * run various checks on the purchase request
	 * @param  array $wp_query API query being passed
	 * @return bool
	 */
	public function validate_request( $wp_query, $log_id = 0 ) {

		// check for missing transaction type
		if ( ! isset( $wp_query->query_vars['trans_type'] ) ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'TRANS_TYPE_MISSING',
				'message'       => 'No transaction type has been supplied.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, 'unknown', 0, 0, $response['error_code'] );

			// send the API response
			return false;
		}

		// set our transaction type to use in logging going forward
		$type   = esc_attr( $wp_query->query_vars['trans_type'] );

		// Bail if we're not serving over SSL
		if ( apply_filters( 'edd_external_require_ssl', true ) && ! is_ssl() ) {

			// set the response array
			$response = array(
				'success'    => false,
				'error_code' => 'NO_SSL',
				'message'    => 'The API is only available over HTTPS.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check for both missing key AND token
		if ( ! isset( $wp_query->query_vars['key'] ) && ! isset( $wp_query->query_vars['token'] ) ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'KEY_TOKEN_MISSING',
				'message'       => 'The required API key and token were not provided.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check for missing key
		if ( ! isset( $wp_query->query_vars['key'] ) ) {
			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'KEY_MISSING',
				'message'       => 'The required API key was not provided.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check for missing token
		if ( ! isset( $wp_query->query_vars['token'] ) ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'TOKEN_MISSING',
				'message'       => 'The required token was not provided.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check for missing source URL
		if ( ! isset( $wp_query->query_vars['source_url'] ) ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'SOURCE_URL_MISSING',
				'message'       => 'A source URL is required.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check for source URL on whitelist
		$whitelist  = $this->confirm_source_url( $wp_query->query_vars['source_url'] );

		if ( ! $whitelist ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'SOURCE_URL_WHITELIST',
				'message'       => 'Your site has not been approved for external purchases. Please contact the store owner.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// check if user being passed has purchase access
		$apiuser    = $this->get_user( $wp_query->query_vars['key'] );

		if ( ! user_can( $apiuser, 'edit_shop_payments' ) ) {

			// set the response array
			$response   = array(
				'success'       => false,
				'error_code'    => 'NO_PAYMENT_ACCESS',
				'message'       => 'The API user does not have permission to create payments.'
			);

			// update the log entry
			EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

			// send the API response
			$this->output( $response );

			// and bail
			return false;
		}

		// checks that are tied to purchases
		if ( $type == 'purchase' ) {

			// check for missing product ID
			if ( ! isset( $wp_query->query_vars['product_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NO_PRODUCT_ID',
					'message'       => 'No product ID was provided.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check for invalid product ID
			if ( ! is_numeric( $wp_query->query_vars['product_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'INVALID_PRODUCT_ID',
					'message'       => 'The provided product ID must be numeric.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check if the product ID is an actual product
			$product_check  = $this->confirm_id_exists( $wp_query->query_vars['product_id'], 'download' );

			if ( ! $product_check ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NOT_VALID_PRODUCT',
					'message'       => 'The provided ID was not a valid product.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

		} // end purchase checks

		// run checks related to refunds
		if ( $type == 'refund' ) {

			// check for missing payment ID
			if ( ! isset( $wp_query->query_vars['payment_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NO_PAYMENT_ID',
					'message'       => 'No payment ID was not provided.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check for invalid payment ID
			if ( ! is_numeric( $wp_query->query_vars['payment_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'INVALID_PAYMENT_ID',
					'message'       => 'The provided payment ID must be numeric.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check if the payment ID is an actual payment
			$payment_check  = $this->confirm_id_exists( $wp_query->query_vars['payment_id'], 'edd_payment' );

			if ( ! $payment_check ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NOT_VALID_PAYMENT',
					'message'       => 'The provided ID was not a valid payment ID.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

		} // end refund checks

		// run checks related to details
		if ( $wp_query->query_vars['trans_type'] == 'details' ) {

			// check for missing product ID
			if ( ! isset( $wp_query->query_vars['product_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NO_PRODUCT_ID',
					'message'       => 'No product ID was provided.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check for missing payment ID
			if ( ! isset( $wp_query->query_vars['payment_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NO_PAYMENT_ID',
					'message'       => 'No payment ID was not provided.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check for invalid product ID
			if ( ! is_numeric( $wp_query->query_vars['product_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'INVALID_PRODUCT_ID',
					'message'       => 'The provided product ID must be numeric.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check if the product ID is an actual product
			$product_check  = $this->confirm_id_exists( $wp_query->query_vars['product_id'], 'download' );

			if ( ! $product_check ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NOT_VALID_PRODUCT',
					'message'       => 'The provided ID was not a valid product.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check for invalid payment ID
			if ( ! is_numeric( $wp_query->query_vars['payment_id'] ) ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'INVALID_PAYMENT_ID',
					'message'       => 'The provided payment ID must be numeric.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

			// check if the payment ID is an actual payment
			$payment_check  = $this->confirm_id_exists( $wp_query->query_vars['payment_id'], 'edd_payment' );

			if ( ! $payment_check ) {

				// set the response array
				$response   = array(
					'success'       => false,
					'error_code'    => 'NOT_VALID_PAYMENT',
					'message'       => 'The provided ID was not a valid payment ID.'
				);

				// update the log entry
				EDD_External_Purchase_API_Log::update_log_entry( $log_id, $type, 0, 0, $response['error_code'] );

				// send the API response
				$this->output( $response );

				// and bail
				return false;
			}

		} // end details check

		// all checks passed
		return true;
	}

	/**
	 * Listens for the API and then processes the API requests
	 *
	 * @access public
	 * @author Daniel J Griffiths
	 * @global $wp_query
	 * @since 1.5
	 * @return void
	 */
	public function process_query() {

		// call the global
		global $wp_query;

		// Check for edd-external-purchase var. Get out if not present
		if ( ! isset( $wp_query->query_vars['edd-external-api'] ) ) {
			return;
		}

		// run our initial log
		$log_id = EDD_External_Purchase_API_Log::create_log_entry( 'request', $wp_query->query );

		// run my validation checks
		$validate   = $this->validate_request( $wp_query, $log_id );

		// if validation failed, just return
		if ( ! $validate ) {
			return;
		}

		// set process to false
		$process    = false;

		// process our purchase action
		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'purchase' ) {
			$process    = $this->process_payment( $wp_query, $log_id );
		}

		// process our refund action
		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'refund' ) {
			$process    = $this->process_refund( $wp_query->query_vars['payment_id'], $log_id );
		}

		// // process our details request action
		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'details' ) {
			$process    = $this->process_details( $wp_query, $log_id );
		}

		// bail if nothing came back
		if ( ! $process ) {
			return;
		}

		// Send out data to the output function
		$this->output( $process );
	}

	/**
	 * collect the data and process the payment
	 * @param  [type] $wp_query [description]
	 * @return [type]           [description]
	 */
	public function process_payment( $wp_query, $log_id = 0 ) {

		// fetch my default price and check for custom passed
		$default = $this->get_product_price( $wp_query->query_vars['product_id'] );
		$price   = ! isset( $wp_query->query_vars['price'] ) || empty( $wp_query->query_vars['price'] ) ? $default : $wp_query->query_vars['price'];

		// set up an array of external data stuff
		$source_name = ! empty( $wp_query->query_vars['source_name'] ) ? $wp_query->query_vars['source_name'] : '';
		$source_url  = ! empty( $wp_query->query_vars['source_url'] ) ? $wp_query->query_vars['source_url'] : '';

		$external_meta  = array(
			'source_name' => esc_html( $source_name ),
			'source_url'  => esc_url( $source_url ),
		);

		// build data array of purchase info
		$data = array(
			'product_id'    => absint( $wp_query->query_vars['product_id'] ),
			'price'         => edd_sanitize_amount( $price ),
			'first'         => esc_attr( $wp_query->query_vars['first_name'] ),
			'last'          => esc_attr( $wp_query->query_vars['last_name'] ),
			'email'         => is_email( $wp_query->query_vars['email'] ),
			'date'          => date( 'Y-m-d H:i:s', strtotime( 'NOW', current_time( 'timestamp' ) ) ),
			'external_meta' => $external_meta,
			'receipt'       => isset( $wp_query->query_vars['receipt'] ) ? $wp_query->query_vars['receipt'] : true
		);

		// send purchase data to processing
		$process = $this->create_payment( $data, $log_id );

		// return the processed payment
		return $process;
	}

	/**
	 * [create_user description]
	 * @param  array   $data       [description]
	 * @return [type]              [description]
	 */
	public function create_user( $data = array() ) {

		// run required check for data
		if ( ! $data ) {
			return;
		}

		// set our email setup
		$email = ! empty( $data['email'] ) ? sanitize_email( $data['email'] ) : '';

		// look up the email
		$user_id = email_exists( $email );

		// make sure we don't pull in a duplicate
		if ( ! $user_id ) {

			// create user info array
			$first = ! empty( $data['first'] ) ? sanitize_text_field( $data['first'] ) : '';
			$last  = ! empty( $data['last'] )  ? sanitize_text_field( $data['last'] ) : '';
			$email = ! empty( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
			$date  = ! empty( $data['date'] )  ? $data['date'] : date( 'Y-m-d H:i:s', strtotime( 'NOW', current_time( 'timestamp' ) ) );

			// build user array
			$userdata = array(
				'user_pass'       => wp_generate_password( 16, true, false ),
				'user_login'      => $email,
				'nickname'        => $first,
				'user_nicename'   => sanitize_title_for_query( $email ),
				'display_name'    => $first,
				'user_email'      => $email,
				'first_name'      => $first,
				'last_name'       => $last,
				'user_registered' => $date,
				'role'            => get_option( 'default_role' ),
			);

			// create the user
			$user_id = wp_insert_user( $userdata );

			if ( ! is_wp_error( $user_id ) ) {

				// set a flag for external source so we can find it later
				update_user_meta( $user_id, 'edd_external_user', true );

				// WP admin related
				$pointers = 'wp330_toolbar,wp330_saving_widgets,wp340_choose_image_from_library,wp340_customize_current_theme_link,wp350_media,wp360_revisions,wp360_locks';

				update_user_meta( $user_id, 'show_welcome_panel', false );
				update_user_meta( $user_id, 'show_admin_bar_front', 'false' );
				update_user_meta( $user_id, 'dismissed_wp_pointers', $pointers );

				// Allow themes and plugins to filter the user data
				$user_data = apply_filters( 'edd_insert_user_data', $user_data, $user_args );

				// Allow themes and plugins to hook
				remove_action( 'edd_insert_user', 'edd_new_user_notification', 10, 2 );
				do_action( 'edd_insert_user', $user_id, $user_data );
				add_action( 'edd_insert_user', 'edd_new_user_notification', 10, 2 );

			}

		}

		// return the new user ID
		return $user_id;
	}

	/**
	 * construct the data array and process the payment
	 * @param  [type] $data [description]
	 * @return [type]       [description]
	 */
	public function create_payment( $data, $log_id = 0 ) {

		global $edd_options;

		// look up the user first
		$user = get_user_by( 'email', $data['email'] );

		// generate a new user if we don't have one
		if ( ! $user ) {
			$user_id = $this->create_user( $data );
			if ( ! is_wp_error( $user_id ) ) {
				$user = get_user_by( 'id', $user_id );
			}
		}

		$user_id = $user ? $user->ID : 0;
		$email   = $user ? $user->user_email : strip_tags( trim( $data['email'] ) );

		if ( ! empty( $data['first'] ) ) {
			$user_first = sanitize_text_field( $data['first'] );
		} else {
			$user_first = $user ? $user->first_name : '';
		}

		if ( ! empty( $data['last'] ) ) {
			$user_last = sanitize_text_field( $data['last'] );
		} else {
			$user_last = $user ? $user->last_name : '';
		}

		$user_info = array(
			'id'         => $user_id,
			'email'      => $email,
			'first_name' => $user_first,
			'last_name'  => $user_last,
			'discount'   => 'none'
		);

		$price = edd_sanitize_amount( strip_tags( trim( $data['price'] ) ) );

		// fetch download files
		$downloads = $this->get_product_files( $data['product_id'] );

		// set up cart details
		$cart_details[] = array(
			'name'        => $this->get_product_name( $data['product_id'] ),
			'id'          => $data['product_id'],
			'item_number' => $data['product_id'],
			'price'       => $price,
			'quantity'    => 1,
			'tax'         => 0,
		);

		$date = ! empty( $data['date'] ) ? strip_tags( trim( $data['date'] ) ) : 'NOW';
		$date = date( 'Y-m-d H:i:s', strtotime( $date, current_time( 'timestamp' ) ) );

		$purchase_data = array(
			'price'        => edd_sanitize_amount( $price ),
			'tax'          => 0,
			'post_date'    => $date,
			'purchase_key' => strtolower( md5( uniqid() ) ), // random key
			'user_email'   => $email,
			'user_info'    => $user_info,
			'currency'     => $edd_options['currency'],
			'downloads'    => $downloads,
			'cart_details' => $cart_details,
			'gateway'      => 'external',
			'status'       => 'pending' // start with pending so we can call the update function, which logs all stats
		);

		$payment_id = edd_insert_payment( $purchase_data );

		// add some data regarding the external source
		update_post_meta( $payment_id, '_edd_external_purchase_meta', $data['external_meta'] );

		// remove the receipt action if set to false on the API call
		if ( empty( $data['receipt'] ) || $data['receipt'] != '1' ) {
			remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );
		}

		// increase stats and log earnings
		edd_update_payment_status( $payment_id, 'complete' ) ;

		// send the admin notification
		$this->send_admin_notification( $payment_id, $purchase_data );

		// fetch the download data array
		$download_data = $this->fetch_download_data( $payment_id, $data['product_id'] );

		// update our log file
		EDD_External_Purchase_API_Log::update_log_entry( $log_id, 'purchase', $payment_id, 1, '' );

		// fetch some data for the return
		return array(
			'success'       => true,
			'message'       => 'The payment has been successfully processed',
			'payment_id'    => $payment_id,
			'purchase_key'  => $purchase_data['purchase_key'],
			'download_data' => $download_data,
		);
	}

	/**
	 * [send_admin_notification description]
	 * @param  integer $payment_id   [description]
	 * @param  array   $payment_data [description]
	 * @return [type]                [description]
	 */
	public function send_admin_notification( $payment_id = 0, $purchase_data = array() ) {

		/*
			**TODO** actually check the EDD settings and respect that
			// get the EDD settings
			$settings   = get_option( 'edd_settings' );
			if ( ! isset( $settings['disable_admin_notices'] ) ) {
				return;
			}
		 */

		// run the EDD admin email function
		edd_admin_email_notice( $payment_id, $purchase_data );

		// and send it back
		return;

	}

	/**
	 * [set_html_content_type description]
	 */
	public function set_html_content_type() {
		return 'text/html';
	}

	/**
	 * [get_refund_email_data description]
	 * @param  integer $payment_id [description]
	 * @return [type]              [description]
	 */
	public function get_refund_email_data( $payment_id = 0 ) {

		// get some payment info
		$payment_num    = get_post_meta( $payment_id, '_edd_payment_number', true );
		$purchase_date  = get_post_meta( $payment_id, '_edd_completed_date', true );
		$refund_date    = get_post_meta( $payment_id, '_edd_refunded_date', true );
		$payment_total  = get_post_meta( $payment_id, '_edd_payment_total', true );
		$user_email     = get_post_meta( $payment_id, '_edd_payment_user_email', true );
		$user_id        = get_post_meta( $payment_id, '_edd_payment_user_id', true );
		$edit_link      = get_edit_post_link( $payment_id );
		$user_link      = get_edit_user_link( $user_id );
		$payment_meta   = get_post_meta( $payment_id, '_edd_payment_meta', true );
		$payment_cart   = $payment_meta['cart_details'];
		$payment_items  = wp_list_pluck( $payment_cart, 'name' );

		// set up the data
		$data   = array();

		// set up singles
		$data['payment-num']    = esc_attr( $payment_num );
		$data['purchase-date']  = esc_attr( $purchase_date );
		$data['refund-date']    = esc_attr( $refund_date );
		$data['payment-total']  = esc_attr( $payment_total );
		$data['user-email']     = is_email( $user_email );
		$data['user-link']      = esc_url( $user_link );
		$data['item-link']      = esc_url( $edit_link );
		$data['payment-items']  = (array) $payment_items;

		// return them
		return $data;
	}

	/**
	 * [build_refund_email_content description]
	 * @param  integer $payment_id [description]
	 * @return [type]              [description]
	 */
	public function build_refund_email_content( $payment_id = 0 ) {

		// get the email data
		$data   = $this->get_refund_email_data( $payment_id );

		// bail without data
		if ( empty( $data ) ) {
			return;
		}

		// build the email body
		$output = '';
		// the opening and had
		$output .= '<html>';
		$output .= '<head>';
			$output .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
		$output .= '</head>';
		$output .= '<body>';

		// the meat of the email
		$output .= '<table border="0" cellspacing="0" cellpadding="0">'."\n";
		// begin data check
		if ( ! empty( $data['payment-num'] ) ) {
			// quick part for output
			$edit   = ! empty( $data['item-link'] ) ? '<a href="' . $data['item-link'] . '">&nbsp;<em><small>(edit)</small></em></a>' : '';
			// show it
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">Payment Number:&nbsp;</th>';
				$output .= '<td width="600" valign="top">' . $data['payment-num'] . $edit . '</td>';
			$output .= '</tr>';
		}
		if ( ! empty( $data['purchase-date'] ) ) {
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">Purchase Date:&nbsp;</th>';
				$output .= '<td width="600" valign="top">' . $data['purchase-date'] . '</td>';
			$output .= '</tr>';
		}
		if ( ! empty( $data['refund-date'] ) ) {
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">Refund Date:&nbsp;</th>';
				$output .= '<td width="600" valign="top">' . $data['refund-date'] . '</td>';
			$output .= '</tr>';
		}
		if ( ! empty( $data['payment-total'] ) ) {
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">Payment Total:&nbsp;</th>';
				$output .= '<td width="600" valign="top">$' . $data['payment-total'] . '</td>';
			$output .= '</tr>';
		}
		if ( ! empty( $data['user-email'] ) ) {
			// quick part for output
			$edit   = ! empty( $data['user-link'] ) ? '<a href="' . $data['user-link'] . '">&nbsp;<em><small>(edit)</small></em></a>' : '';
			// show it
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">User Email:&nbsp;</th>';
				$output .= '<td width="600" valign="top">' . $data['user-email'] . $edit . '</td>';
			$output .= '</tr>';
		}
		if ( ! empty( $data['payment-items'] ) ) {
			// show it
			$output .= '<tr>';
				$output .= '<th width="200" align="left" valign="top">Item(s) Purchased:&nbsp;</th>';
				$output .= '<td width="600" valign="top">';
				// we need to loop them
				foreach( $data['payment-items'] as $item ) {
					$output .= esc_attr( $item ) . '<br />';
				}
				$output .= '</td>';
			$output .= '</tr>';

		}
		// end table
		$output .= '</table>';
		// close it up
		$output .= '</body>';
		$output .= '</html>';

		// send it back
		return trim( $output );

	}

	/**
	 * send an email notification to the admin when a refund
	 * has been processed via external call
	 *
	 * @param  integer $payment_id [description]
	 * @return [type]              [description]
	 */
	public function send_refund_notification( $payment_id = 0 ) {

		// run various checks to make sure we aren't doing anything weird
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		// get the notification email address
		$notify = $this->get_single_edd_setting( 'admin_notice_emails' );

		// get the admin email if the field is blank
		$email  = ! empty( $notify ) ? sanitize_email( $notify ) : get_option( 'admin_email' );

		// bail with no email
		if ( empty( $email ) ) {
			return;
		}

		// get email template data
		$from_name  = get_bloginfo( 'name' );
		$from_addr  = get_option( 'admin_email' );

		// generate email headers
		$headers = 'From: '.$from_name.' <'.$from_addr.'>' . "\r\n" ;
		$headers .= 'Return-Path: '.$from_addr."\r\n" ;
		$headers .= 'MIME-Version: 1.0' . "\r\n" ;
		$headers .= 'Content-Type: text/html; charset="UTF-8"'. "\r\n" ;

		// email subject
		$subject = 'EDD Refund Notice';

		// trim and clean it
		$message    = $this->build_refund_email_content( $payment_id );

		// switch to HTML format
		add_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		// send the email
		wp_mail( $email, $subject, $message, $headers );

		// remove the HTML format
		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_content_type' ) );

		return;
	}

	/**
	 * get a single EDD setting from the big array
	 * @param  string $key [description]
	 * @return [type]      [description]
	 */
	public function get_single_edd_setting( $key = '' ) {

		// first get the option array
		$settings   = get_option( 'edd_settings' );

		// bail without the array, or our requested key
		if ( empty( $settings ) || ! empty( $settings ) && empty( $settings[$key] ) ) {
			return false;
		}

		// return the requested item
		return $settings[$key];
	}

	/**
	 * process the refund
	 * @param  integer $payment_id [description]
	 * @param  integer $log_id     [description]
	 * @return [type]              [description]
	 */
	public function process_refund( $payment_id = 0, $log_id = 0 ) {

		// bail without a payment ID
		if ( empty( $payment_id ) ) {
			return;
		}

		// update our payment status
		edd_update_payment_status( $payment_id, 'refunded' );

		// set a meta key
		update_post_meta( $payment_id, '_edd_refunded_date', current_time( 'mysql' ) );

		// send the email notification if enabled
		if ( apply_filters( 'edd_external_enable_refund_email', true ) ) {
			$this->send_refund_notification( $payment_id );
		}

		// update our log entry
		EDD_External_Purchase_API_Log::update_log_entry( $log_id, 'refund', $payment_id, 1, '' );

		// send back the data for the API response
		return array(
			'success'       => true,
			'message'       => 'The payment has been successfully refunded',
		);
	}

	/**
	 * fetch and return the data for a details request
	 * @param  [type]  $wp_query [description]
	 * @param  integer $log_id   [description]
	 * @return [type]            [description]
	 */
	public function process_details( $wp_query, $log_id = 0 ) {

		//get our two IDs
		$product_id     = absint( $wp_query->query_vars['product_id'] );
		$payment_id     = absint( $wp_query->query_vars['payment_id'] );

		// get the data for each one
		$purchase_data  = $this->fetch_purchase_data( $payment_id );
		$download_data  = $this->fetch_download_data( $payment_id, $product_id );

		// update our log entry
		EDD_External_Purchase_API_Log::update_log_entry( $log_id, 'details', $payment_id, 1, '' );

		// send back the data for the API response
		return array(
			'success'       => true,
			'message'       => 'details regarding purchase ID '.$payment_id,
			'download_data' => $download_data,
			'purchase_data' => $purchase_data
		);
	}

	/**
	 * Output Query in either JSON/XML. The query data is outputted as JSON
	 * by default
	 *
	 * @author Daniel J Griffiths
	 * @since 1.5
	 * @global $wp_query
	 *
	 * @param int $status_code
	 */
	public function output( $data ) {

		header( 'HTTP/1.1 200' );
		header( 'Content-type: application/json; charset=utf-8' );
		echo json_encode( $data );

		edd_die();
	}

	/**
	 * set the whitelist for allowed sites to make a connection
	 * @param  [type] $sites [description]
	 * @return [type]        [description]
	 */
	public function whitelist( $sites ) {
		$sites[] = 'http://studiopress.com';
		return $sites;
	}

}

register_activation_hook( __FILE__, array( 'EDD_External_Purchase_API', 'activate' ) );

new EDD_External_Purchase_API();