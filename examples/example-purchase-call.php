<?php

/**
 * Below is an example call to the external API.
 *
 * currently supports either a purchase or a refund (via trans_type variable)
 *
 * REQUIRED FOR ALL: key, token, trans_type, source_url, product ID, email
 *
 *
 * FOR PURCHASE: returns an array success(bool) payment ID (int) purchase key(string) download data (array) with name, download link, and
 * license key
 *
 * passing a price will override the set download price, and passing a zero will set it to zero (free).
 * otherwise it will use the set product price
 *
 */

$url	= 'http://your-site/edd-external-api/';

$args = array(
	'method'	=> 'POST',
	'sslverify' => false,
	'body'		=> array(
		'key'			=> 'YOUR-KEY',
		'token'			=> 'YOUR-TOKEN',
		'trans_type'	=> 'purchase',
		'product_id'	=> 'YOUR-PRODUCT-ID',
		'price'			=> 'YOUR-PRICE',
		'source_name'	=> 'EXTERNAL-SITE-NAME',
		'source_url'	=> 'EXTERNAL-SITE-URL',
		'first_name'	=> 'CUSTOMER-FIRST-NAME',
		'last_name'		=> 'CUSTOMER-LAST-NAME',
		'email'			=> 'CUSTOMER-EMAIL',
		'receipt'		=> false
	),
);

$response	= wp_remote_post( $url, $args );
$body		= wp_remote_retrieve_body( $response );
$data		= json_decode( $body );

