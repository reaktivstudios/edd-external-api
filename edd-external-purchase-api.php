<?php
/*
Plugin Name: Easy Digital Downloads - External Purchase API
Plugin URI: http://easydigitaldownloads.com/extension/external-purchase-api/
Description: Provides an API endpoint for creating sales on third party sites
Version: 0.0.1
Author: Reaktiv Studios
Author URI:  http://andrewnorcross.com
Contributors: norcross
*/

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
	const VERSION = '1.0';

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

		if ( ! function_exists( 'edd_price' ) )
			return; // EDD not present

		add_action( 'init',                   array( __CLASS__, 'add_endpoint'  )    );
		add_action( 'template_redirect',      array( $this,     'process_query' ), 1 );
		add_filter( 'query_vars',             array( $this,     'query_vars'    )    );
		add_filter( 'edd_external_whitelist', array( $this,     'whitelist'     )    );

	}

	public static function activate() {
		self::add_endpoint();
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
	 * Registers query vars for API access
	 *
	 * @access public
	 * @since 1.5
	 * @author Daniel J Griffiths
	 * @param array $vars Query vars
	 * @return array $vars New query vars
	 */
	public function query_vars( $vars ) {
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
		global $wpdb, $wp_query;

		if ( empty( $key ) )
			$key = urldecode( $wp_query->query_vars['key'] );

		$user = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'edd_user_public_key' AND meta_value = %s LIMIT 1", $key ) );

		if ( $user != NULL ) {
			$this->user_id = $user;
			return $user;
		}
		return false;
	}

	/**
	 * fetch the product type (standard or bundle)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_type( $product_id ) {

		$type	= get_post_meta( $product_id, '_edd_product_type', true );
		$type	= ! empty ( $type ) ? $type : 'default';

		return $type;

	}

	/**
	 * fetch the product downloads (standard or bundle)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_files( $product_id ) {

		// check item type
		$itemtype	= $this->get_product_type( $product_id );

		// fetch download files for single items
		if ( $itemtype == 'default' ) :
			$data[]	= array(
				'id'	=> absint( $product_id ),
				'file'	=> edd_get_download_files( $product_id ),
				'name'	=> $this->get_product_name( $product_id ),
			);

			return $data;

		endif;

		$bundles	= get_post_meta( $product_id, '_edd_bundled_products', true );

		foreach ( $bundles as $bundle_id ):
			$data[]	= array(
				'id'	=> absint( $bundle_id ),
				'file'	=> edd_get_download_files( $bundle_id ),
				'name'	=> $this->get_product_name( $bundle_id ),
			);
		endforeach;

		return $data;

	}

	/**
	 * fetch the product price (or zero)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_price( $product_id ) {

		$price	= get_post_meta( $product_id, 'edd_price', true );
		$price	= ! empty ( $price ) ? edd_sanitize_amount( $price ) : 0;

		return $price;

	}

	/**
	 * fetch the custom product name with standard fallback
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_name( $product_id ) {

		$custom	= get_post_meta( $product_id, '_edd_external_title', true );
		$base	= get_the_title( $product_id );

		$title	= ! empty( $custom ) ? $custom : $base;

		return esc_html( $title );

	}

	/**
	 * fetch the product license key (if one exists)
	 * @param  int $payment_id payment ID in the database
	 * @return string
	 */
	public function get_product_license( $payment_id ) {

		// fetch payment data based on ID with license
		$args = array(
			'fields'		=> 'ids',
			'nopaging'		=> true,
			'meta_key'		=> '_edd_sl_payment_id',
			'meta_value'	=> $payment_id,
			'post_type'		=> 'edd_license',
			'post_status'	=> 'any'
		);

		$licenses = get_posts( $args );

		if ( ! $licenses )
			return false;

		// fetch license keys and make sure they are in an array to match the download items
		foreach ( $licenses as $license_id ):
			$license_keys[]	= get_post_meta( $license_id, '_edd_sl_key', true );
		endforeach;


		// bail if we don't have anything
		if ( ! $license_keys || empty( $license_keys ) )
			return false;

		// send back key(s)
		return $license_keys;

	}


	/**
	 * fetch the download URL
	 * @param  string $url
	 * @return bool
	 */
	public function get_product_download_url( $payment_id, $product_id ) {

		$payment_key	= get_post_meta( $payment_id, '_edd_payment_purchase_key', true );
		$payment_email	= get_post_meta( $payment_id, '_edd_payment_user_email', true );

		$params = array(
			'download_key'	=> $payment_key,
			'email'			=> rawurlencode( $payment_email ),
			'file'			=> 0,
			'price_id'		=> 0,
			'download_id'	=> $product_id,
			'expire'		=> rawurlencode( base64_encode( 2147472000 ) )
		);

		$download_url	= add_query_arg( $params, home_url( 'index.php' ) );

		return $download_url;

	}

	/**
	 * [fetch_download_urls description]
	 * @return [type] [description]
	 */
	public function fetch_download_data( $payment_id, $product_id ) {

		$downloads		= $this->get_product_files( $product_id );
		$licenses		= $this->get_product_license( $payment_id );

		// set the data return to an array
		$download_data	= array();
		foreach ( $downloads as $filekey => $file ) :

			$download_url		= $this->get_product_download_url( $payment_id, $file['id'] );

			// match up licenses to the applicable download item
			$download_license	= isset( $licenses[ $filekey ] ) ? $licenses[ $filekey ] : '';

			$download_data[]	= array(
				'product_id'		=> absint( $file['id'] ),
				'product_name'		=> esc_attr( $file['name'] ),
				'download_link'		=> $download_url,
				'license_key'		=> $download_license
			);

		endforeach;

		return $download_data;

	}

	/**
	 * [fetch_purchase_data description]
	 * @param  [type] $payment_id [description]
	 * @return [type]             [description]
	 */
	public function fetch_purchase_data( $payment_id ) {

		$purchase_key	= get_post_meta( $payment_id, '_edd_payment_purchase_key', true );
		$purchase_total	= get_post_meta( $payment_id, '_edd_payment_total', true );

		$purchase_date	= get_post_field( 'post_date', $payment_id, 'raw' );
		$purchase_stamp	= strtotime( $purchase_date );

		$external_meta	= get_post_meta( $payment_id, '_edd_external_purchase_meta', true );

		$source_name	= ! empty( $external_meta['source_name'] ) ? esc_html( $external_meta['source_name'] ) : '';
		$source_url		= ! empty( $external_meta['source_url'] ) ? esc_url( $external_meta['source_url'] ) : '';

		return array(
			'external_source'	=> $source_name,
			'external_url'		=> $source_url,
			'purchase_key'		=> $purchase_key,
			'purchase_total'	=> $purchase_total,
			'purchase_date'		=> $purchase_date,
			'purchase_stamp'	=> $purchase_stamp
		);

	}

	/**
	 * confirm the ID being passed is actually a live product and not something else
	 * @param  int $product_id product ID in the database
	 * @return bool
	 */
	public function confirm_product( $product_id ) {

		$product	= get_post( $product_id );

		if ( ! $product ) {
			return false;
		}

		if ( $product->post_type != 'download' ) {
			return false;
		}

		if ( $product->post_status != 'publish' ) {
			return false;
		}

		return true;

	}

	/**
	 * confirm the ID being passed is actually a payment product and not something else
	 * @param  int $payment_id product ID in the database
	 * @return bool
	 * @todo  indicate if item is missing or if has already been refunded
	 */
	public function confirm_payment( $payment_id ) {

		$payment	= get_post( $payment_id );

		if ( ! $payment ) {
			return false;
		}

		if ( $payment->post_type != 'edd_payment' ) {
			return false;
		}

		if ( $payment->post_status != 'publish' ) {
			return false;
		}

		return true;

	}

	/**
	 * confirm the source URL is whitelisted
	 * @param  string $url
	 * @return bool
	 */
	public function confirm_source_url( $source_url ) {

		// first run bypass filter for those LIVIN ON THE EDGE
		$disable	= apply_filters( 'edd_external_whitelist_enabled', true );

		if ( ! $disable )
			return true;

		$source		= $this->strip_url( $source_url );

		$whitelist	= apply_filters( 'edd_external_whitelist', array() );
		$whitelist	= ! is_array( $whitelist ) ? array( $whitelist ) : $whitelist;

		// check for a whitelist
		if ( ! $whitelist || empty( $whitelist ) )
			return false;

		// loop through the URLs and strip them
		foreach ( $whitelist as $url ) :
			$list[]	= $this->strip_url( $url );
		endforeach;

		// check said whitelist
		if ( ! in_array( $source, $list ) )
			return false;

		// you're on the list
		return true;

	}

	/**
	 * strip the URL down to the host
	 *
	 * @param  string $url
	 * @return string
	 */
	public function strip_url( $url ) {

		// check for the http or https and add it if it's missing
		if ( ! preg_match( '~^(?:f|ht)tps?://~i', $url ) )
			$url = 'http://' . $url;

		// clean up the damn link
		$parsed = parse_url( $url );
		$host   = $parsed['host'];
		$parts  = explode( '.', $host );
		// Give us only the last two parts of the host (domain and TLD)
		$domain = join( '.', array_slice( $parts, -2 ) );

		return $domain;

	}

	/**
	 * run various checks on the purchase request
	 * @param  array $wp_query API query being passed
	 * @return bool
	 */
	public function validate_request( $wp_query ) {

		// Bail if we're not serving over SSL
		if ( apply_filters( 'edd_external_require_ssl', true ) && ! is_ssl() ) {
			$response = array(
				'success'    => false,
				'error_code' => 'NO_SSL',
				'message'    => 'The API is only available over HTTPS.'
			);

			$this->output( $response );
			return false;
		}

		// check for both missing key AND token
		if ( ! isset( $wp_query->query_vars['key'] ) && ! isset( $wp_query->query_vars['token'] ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'KEY_TOKEN_MISSING',
				'message'		=> 'The required API key and token were not provided.'
			);

			$this->output( $response );
			return false;

		endif;

		// check for missing key
		if ( ! isset( $wp_query->query_vars['key'] ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'KEY_MISSING',
				'message'		=> 'The required API key was not provided.'
			);

			$this->output( $response );
			return false;

		endif;

		// check for missing token
		if ( ! isset( $wp_query->query_vars['token'] ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'TOKEN_MISSING',
				'message'		=> 'The required token was not provided.'
			);

			$this->output( $response );
			return false;

		endif;

		// check for missing source URL
		if ( ! isset( $wp_query->query_vars['source_url'] ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'SOURCE_URL_MISSING',
				'message'		=> 'A source URL is required.'
			);

			$this->output( $response );
			return false;

		endif;

		// check for source URL on whitelist
		$whitelist	= $this->confirm_source_url( $wp_query->query_vars['source_url'] );
		if ( ! $whitelist ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'SOURCE_URL_WHITELIST',
				'message'		=> 'Your site has not been approved for external purchases. Please contact the store owner.'
			);

			$this->output( $response );
			return false;

		endif;

		// check for missing transaction type
		if ( ! isset( $wp_query->query_vars['trans_type'] ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'TRANS_TYPE_MISSING',
				'message'		=> 'No transaction type has been supplied.'
			);

			$this->output( $response );
			return false;

		endif;

		// check if user being passed has purchase access
		$apiuser	= $this->get_user( $wp_query->query_vars['key'] );
		if ( ! user_can( $apiuser, 'edit_shop_payments' ) ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'NO_PAYMENT_ACCESS',
				'message'		=> 'The API user does not have permission to create payments.'
			);

			$this->output( $response );
			return false;

		endif;

		// checks that are tied to purchases
		if ( $wp_query->query_vars['trans_type'] == 'purchase' ) :

			// check for missing product ID
			if ( ! isset( $wp_query->query_vars['product_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NO_PRODUCT_ID',
					'message'		=> 'No product ID was provided.'
				);

				$this->output( $response );
				return false;

			endif;

			// check for invalid product ID
			if ( ! is_numeric( $wp_query->query_vars['product_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'INVALID_PRODUCT_ID',
					'message'		=> 'The provided product ID must be numeric.'
				);

				$this->output( $response );
				return false;

			endif;

			// check if the product ID is an actual product
			$product_check	= $this->confirm_product( $wp_query->query_vars['product_id'] );
			if ( ! $product_check ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NOT_VALID_PRODUCT',
					'message'		=> 'The provided ID was not a valid product.'
				);

				$this->output( $response );
				return false;

			endif;

		endif;

		// run checks related to refunds
		if ( $wp_query->query_vars['trans_type'] == 'refund' ) :

			// check for missing payment ID
			if ( ! isset( $wp_query->query_vars['payment_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NO_PAYMENT_ID',
					'message'		=> 'No payment ID was not provided.'
				);

				$this->output( $response );
				return false;

			endif;

			// check for invalid payment ID
			if ( ! is_numeric( $wp_query->query_vars['payment_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'INVALID_PAYMENT_ID',
					'message'		=> 'The provided payment ID must be numeric.'
				);

				$this->output( $response );
				return false;

			endif;

			// check if the payment ID is an actual payment
			$payment_check	= $this->confirm_payment( $wp_query->query_vars['payment_id'] );
			if ( ! $payment_check ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NOT_VALID_PAYMENT',
					'message'		=> 'The provided ID was not a valid payment ID.'
				);

				$this->output( $response );
				return false;

			endif;

		endif;

		// run checks related to details
		if ( $wp_query->query_vars['trans_type'] == 'details' ) :

			// check for missing product ID
			if ( ! isset( $wp_query->query_vars['product_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NO_PRODUCT_ID',
					'message'		=> 'No product ID was provided.'
				);

				$this->output( $response );
				return false;

			endif;

			// check for missing payment ID
			if ( ! isset( $wp_query->query_vars['payment_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NO_PAYMENT_ID',
					'message'		=> 'No payment ID was not provided.'
				);

				$this->output( $response );
				return false;

			endif;

			// check for invalid product ID
			if ( ! is_numeric( $wp_query->query_vars['product_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'INVALID_PRODUCT_ID',
					'message'		=> 'The provided product ID must be numeric.'
				);

				$this->output( $response );
				return false;

			endif;

			// check if the product ID is an actual product
			$product_check	= $this->confirm_product( $wp_query->query_vars['product_id'] );
			if ( ! $product_check ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NOT_VALID_PRODUCT',
					'message'		=> 'The provided ID was not a valid product.'
				);

				$this->output( $response );
				return false;

			endif;

			// check for invalid payment ID
			if ( ! is_numeric( $wp_query->query_vars['payment_id'] ) ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'INVALID_PAYMENT_ID',
					'message'		=> 'The provided payment ID must be numeric.'
				);

				$this->output( $response );
				return false;

			endif;

			// check if the payment ID is an actual payment
			$payment_check	= $this->confirm_payment( $wp_query->query_vars['payment_id'] );
			if ( ! $payment_check ) :

				$response	= array(
					'success'		=> false,
					'error_code'	=> 'NOT_VALID_PAYMENT',
					'message'		=> 'The provided ID was not a valid payment ID.'
				);

				$this->output( $response );
				return false;

			endif;

		endif;

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

		global $wp_query;

		// Check for edd-external-purchase var. Get out if not present
		if ( ! isset( $wp_query->query_vars['edd-external-api'] ) ) {
			return;
		}

		// run my validation checks
		$validate	= $this->validate_request( $wp_query );
		if ( ! $validate ) {
			return;
		}

		// set process to false
		$process	= false;

		// Determine transaction type and act accordingly
		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'purchase' ) {
			$process	= $this->process_payment( $wp_query );
		}

		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'refund' ) {
			$process	= $this->process_refund( $wp_query->query_vars['payment_id'] );
		}

		// secondary setup for getting most recent details
		if ( isset( $wp_query->query_vars['trans_type'] ) && $wp_query->query_vars['trans_type'] == 'details' ) {
			$process	= $this->process_details( $wp_query );
		}

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
	public function process_payment( $wp_query ) {

		// fetch my default price and check for custom passed
		$default = $this->get_product_price( $wp_query->query_vars['product_id'] );
		$price   = ! isset( $wp_query->query_vars['price'] ) || empty( $wp_query->query_vars['price'] ) ? $default : $wp_query->query_vars['price'];

		// set up an array of external data stuff
		$source_name = ! empty( $wp_query->query_vars['source_name'] ) ? $wp_query->query_vars['source_name'] : '';
		$source_url  = ! empty( $wp_query->query_vars['source_url'] ) ? $wp_query->query_vars['source_url'] : '';

		$external_meta	= array(
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
		$process = $this->create_payment( $data );

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

		return $user_id;

	}

	/**
	 * construct the data array and process the payment
	 * @param  [type] $data [description]
	 * @return [type]       [description]
	 */
	public function create_payment( $data ) {

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
			$user_first	= $user ? $user->first_name : '';
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
			$settings	= get_option( 'edd_settings' );
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
	 * process the refund
	 * @param  [type] $payment_id [description]
	 * @return [type]             [description]
	 */
	public function process_refund( $payment_id ) {

		edd_update_payment_status( $payment_id, 'refunded' );

		return array(
			'success'		=> true,
			'message'		=> 'The payment has been successfully refunded',
		);

	}


	/**
	 * [process_details description]
	 * @return [type] [description]
	 */
	public function process_details( $wp_query ) {

		$product_id		= absint( $wp_query->query_vars['product_id'] );
		$payment_id		= absint( $wp_query->query_vars['payment_id'] );

		$purchase_data	= $this->fetch_purchase_data( $payment_id );
		$download_data	= $this->fetch_download_data( $payment_id, $product_id );

		return array(
			'success'		=> true,
			'message'		=> 'details regarding purchase ID '.$payment_id,
			'download_data'	=> $download_data,
			'purchase_data'	=> $purchase_data
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