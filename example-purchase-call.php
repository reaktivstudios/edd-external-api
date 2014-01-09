<?php

/**
 * Below is an example call to the external purchase API.
 *
 * returns an array success(bool) payment ID (int) and purchase key(string)
 *
 * passing a price will override the set download price, and passing a zero will set it to zero (free). otherwise it will use the set product price
 */

$url	= 'http://your-site/edd-external-purchase/';

$args = array(
	'method'	=> 'POST',
	'sslverify' => false,
	'body'		=> array(
		'key'			=> 'YOUR-KEY',
		'token'			=> 'YOUR-TOKEN',
		'product_id'	=> $product_id,
		'price'			=> '99',
		'source'		=> 'AwesomeTown',
		'first_name'	=> 'Customer First Name',
		'last_name'		=> 'Customer Last Name',
		'email'			=> 'email@example.com',
	),
);

$response	= wp_remote_post( $url, $args );
$data		= wp_remote_retrieve_body( $response);

