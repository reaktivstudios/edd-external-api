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
		add_action( 'init',                    array( $this, 'add_endpoint'   ) );
		add_action( 'template_redirect',       array( $this, 'process_query'  ), 1 );
		add_filter( 'query_vars',              array( $this, 'query_vars'     ) );

	}

	/**
	 * Registers a new rewrite endpoint for accessing the API
	 *
	 * @access public
	 * @author Andrew Norcross
	 * @param array $rewrite_rules WordPress Rewrite Rules
	 * @since 1.5
	 */
	public function add_endpoint( $rewrite_rules ) {
		add_rewrite_endpoint( 'edd-external-purchase', EP_ALL );
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
		$vars[] = 'product_id';
		$vars[] = 'price';
		$vars[] = 'first_name';
		$vars[] = 'last_name';
		$vars[] = 'email';
		$vars[] = 'source';

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

		if( empty( $key ) )
			$key = urldecode( $wp_query->query_vars['key'] );

		$user = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'edd_user_public_key' AND meta_value = %s LIMIT 1", $key ) );

		if ( $user != NULL ) {
			$this->user_id = $user;
			return $user;
		}
		return false;
	}

	/**
	 * fetch the product price (or zero)
	 * @param  int $product_id product ID in the database
	 * @return string
	 */
	public function get_product_price( $product_id ) {

		$price	= get_post_meta( $product_id, 'edd_price', true );
		$price	= ! empty ( $price ) ? edd_sanitize_amount( $price ) ? 0;

		return $price;

	}

	/**
	 * confirm the ID being passed is actually a live product and not something else
	 * @param  int $product_id product ID in the database
	 * @return bool
	 */
	public function confirm_product( $product_id ) {

		$product	= get_post( $product_id );

		if ( ! $product )
			return false;

		if ( $product->post_type != 'download' )
			return false;

		if ( $product->post_status != 'publish' )
			return false;

		return true;

	}

	/**
	 * run various checks on the purchase request
	 * @param  array $wp_query API query being passed
	 * @return bool
	 */
	public function validate_request( $wp_query ) {

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

		// check if the product ID is an actual product
		$confirm	= $this->confirm_product( $wp_query->query_vars['product_id'] );
		if ( ! $confirm  ) :

			$response	= array(
				'success'		=> false,
				'error_code'	=> 'NOT_VALID_PRODUCT',
				'message'		=> 'The provided ID was not a valid product.'
			);

			$this->output( $response );
			return false;

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

		// Check for edd-api var. Get out if not present
		if ( ! isset( $wp_query->query_vars['edd-external-purchase'] ) )
			return;

		$validate	= $this->validate_request( $wp_query );

		if ( ! $validate )
			return;

		$userkey	= $wp_query->query_vars['key'];
		$apiuser	= $this->get_user( $data['key'] );

		$setprice	= $this->get_product_price( $wp_query->query_vars['product_id'] );
		$price	= ! isset( $wp_query->query_vars['price'] ) ? $setprice : $wp_query->query_vars['price'];

		$data	= array(
			'apiuser'		=> $apiuser,
			'product_id'	=> absint( $wp_query->query_vars['product_id'] ),
			'price'			=> $price,
			'first'			=> esc_attr( $wp_query->query_vars['first_name'] ),
			'last'			=> esc_attr( $wp_query->query_vars['last_name'] ),
			'email'			=> is_email( $wp_query->query_vars['email'] ),
			'receipt'		=> true
		);

		$process	= $this->create_payment( $data );

		// Send out data to the output function
		$this->output( $process );
	}



	public static function create_payment( $data ) {

		global $edd_options;

		// check my API user
		if ( ! user_can( $data['apiuser'], 'edit_shop_payments' ) )
			return array(
				'success'		=> false,
				'error_code'	=> 'NO_PAYMENT_ACCESS',
				'message'		=> 'The API user does not have permission to create products'
			);

		$user = get_user_by( 'email', $data['email'] );

		$user_id 	= $user ? $user->ID : 0;
		$email 		= $user ? $user->user_email : strip_tags( trim( $data['email'] ) );

		if( isset( $data['first'] ) ) {
			$user_first = sanitize_text_field( $data['first'] );
		} else {
			$user_first	= $user ? $user->first_name : '';
		}

		if( isset( $data['last'] ) ) {
			$user_last = sanitize_text_field( $data['last'] );
		} else {
			$user_last	= $user ? $user->last_name : '';
		}

		$user_info = array(
			'id' 			=> $user_id,
			'email' 		=> $email,
			'first_name'	=> $user_first,
			'last_name'		=> $user_last,
			'discount'		=> 'none'
		);

		$price = edd_sanitize_amount( strip_tags( trim( $data['price'] ) ) );


		// calculate total purchase cost
		$downloads	= edd_get_download_files( $data['product_id'] );

		$cart_details[] = array(
			'name'        => get_the_title( $data['product_id'] ),
			'id'          => $data['product_id'],
			'item_number' => $data['product_id'],
			'price'       => $price,
			'quantity'    => 1,
		);

		$date = date( 'Y-m-d H:i:s', time() );

		$purchase_data     = array(
			'price'        => edd_sanitize_amount( $price ),
			'post_date'    => $date,
			'purchase_key' => strtolower( md5( uniqid() ) ), // random key
			'user_email'   => $email,
			'user_info'    => $user_info,
			'currency'     => $edd_options['currency'],
			'downloads'    => $downloads,
			'cart_details' => $cart_details,
			'status'       => 'pending' // start with pending so we can call the update function, which logs all stats
		);

		$payment_id = edd_insert_payment( $purchase_data );

		if( empty( $data['receipt'] ) || $data['receipt'] != '1' ) {
			remove_action( 'edd_complete_purchase', 'edd_trigger_purchase_receipt', 999 );
		}

		if( ! empty( $data['expiration'] ) && class_exists( 'EDD_Recurring_Customer' ) && $user_id > 0 ) {

			$expiration = strtotime( $data['expiration'] . ' 23:59:59' );

			EDD_Recurring_Customer::set_as_subscriber( $user_id );
			EDD_Recurring_Customer::set_customer_payment_id( $user_id, $payment_id );
			EDD_Recurring_Customer::set_customer_status( $user_id, 'active' );
			EDD_Recurring_Customer::set_customer_expiration( $user_id, $expiration );
		}

		// increase stats and log earnings
		edd_update_payment_status( $payment_id, 'complete' ) ;

		// fetch some data for the return

		return array(
			'success'		=> true,
			'payment_id'	=> $payment_id,
			'purchase_key'	=> $purchase_data['purchase_key'],
			'downloads'		=> $downloads
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


}

new EDD_External_Purchase_API();