<?php
/*
Plugin Name: Blue Pay Addon for Give
Description:  Blue Pay Addon for Give plugin
Version:      1.0
Author:       Jomol MJ
Text Domain:  blue-pay-addon
*/

// Plugin Root File.
if ( ! defined( 'BLUEPAY_PLUGIN_FILE' ) ) {
    define( 'BLUEPAY_PLUGIN_FILE', __FILE__ );
}

// Plugin Folder Path.
if ( ! defined( 'BLUEPAY_PLUGIN_DIR' ) ) {
    define( 'BLUEPAY_PLUGIN_DIR', plugin_dir_path( BLUEPAY_PLUGIN_FILE ) );
}


require_once BLUEPAY_PLUGIN_DIR . 'includes/gateways/bluepay.php';


// To register new payment gateway
function give_bluepay_register_gateway( $gateways ) {
	$gateways['bluepay'] = array(
		'admin_label'    => esc_attr__( 'Blue Pay', 'blue-pay-addon' ),
		'checkout_label' => esc_attr__( 'Blue Pay', 'blue-pay-addon' ),
	);

	return $gateways;
}

add_filter( 'give_payment_gateways', 'give_bluepay_register_gateway', 1 );

// To list new payment gateway tab 

function bluepay_get_sections($sections) {  

    $sections['bluepay-settings'] = __( 'Blue Pay', 'blue-pay-addon' );

    return $sections; 

}

add_filter( 'give_get_sections_gateways', 'bluepay_get_sections', 2 );


// To add settings for the new payment gateway  

function bluepay_get_settings($settings){
    $current_section = give_get_current_setting_section();
    switch ( $current_section ) {
        case 'bluepay-settings':
            $settings = array(
                array(
                    'type' => 'title',
                    'id'   => 'give_title_gateway_settings_4',
                ),
                array(
                    'name' => __( 'Account ID', 'blue-pay-addon' ),
                    'desc' => __( 'Enter your Bluepay Account ID', 'blue-pay-addon' ),
                    'id'   => 'bluepay_account_id',
                    'type' => 'text',
                ),
                array(
                    'name' => __( 'Secret Key', 'blue-pay-addon' ),
                    'desc' => __( 'Enter your Bluepay Secret Key', 'blue-pay-addon' ),
                    'id'   => 'bluepay_secret_key',
                    'type' => 'text',
                ),  
                array(
                    'type' => 'sectionend',
                    'id'   => 'give_title_gateway_settings_4',
                )                    
            );
        break;
    }    
    
    return $settings;
}
add_filter( 'give_get_settings_gateways', 'bluepay_get_settings', 3 );

// To process the bluepay payment
function bluepay_process_payment( $payment_data ) {

    // Validate nonce.
    give_validate_nonce( $payment_data['gateway_nonce'], 'give-gateway' );

    $payment_id = bluepay_create_payment( $payment_data );

    // Check payment.
	if ( empty( $payment_id ) ) {
		// Record the error.
		give_record_gateway_error( __( 'Payment Error', 'give' ), sprintf( /* translators: %s: payment data */
			__( 'Payment creation failed before sending donor to BluePay. Payment data: %s', 'give' ), json_encode( $payment_data ) ), $payment_id );
		// Problems? Send back.
		give_send_back_to_checkout( '?payment-mode=' . $payment_data['post_data']['give-gateway'] );
	}
    
    $payment = bluepay_send_payment( $payment_data );

    if($payment->isSuccessfulResponse()){
		give_send_to_success_page();
    }else{

		give_record_gateway_error( __( 'Payment Error', 'give' ), sprintf( /* translators: %s: payment data */
			__( 'Payment creation failed before sending donor to BluePay. Payment data: %s', 'give' ), json_encode( $payment_data ) ), $payment_id );
		// Problems? Send back.
		give_send_back_to_checkout( '?payment-mode=' . $payment_data['post_data']['give-gateway'] );
    }  
	
	exit;

}
add_action( 'give_gateway_bluepay', 'bluepay_process_payment' );

//bluepay payment process

function bluepay_send_payment($payment_data){
    
    $mode_value = '';
    $test_mode  = give_get_option( 'test_mode' );

    if($test_mode=='enabled'){
        $mode_value = "TEST";
    }else{
        $mode_value = "LIVE";
    }

    $account_id = give_get_option( 'bluepay_account_id' );
    $secret_key = give_get_option( 'bluepay_secret_key' );
    $mode       = $mode_value;

    $payment = new BluePay(
        $account_id,
        $secret_key,
        $mode
    );

    $payment->setCustomerInformation(array(
        'firstName' => $payment_data['post_data']['give_first'], 
        'lastName'  => $payment_data['post_data']['give_last'], 
        'addr1'     => $payment_data['post_data']['card_address'], 
        'addr2'     => $payment_data['post_data']['card_address_2'], 
        'city'      => $payment_data['post_data']['card_city'], 
        'state'     => $payment_data['post_data']['card_state'], 
        'zip'       => $payment_data['post_data']['card_zip'], 
        'country'   => $payment_data['post_data']['billing_country'], 
        'phone'     => '', 
        'email'     => $payment_data['user_email'] 
    ));
    
    $payment->setCCInformation(array(
        'cardNumber' => $payment_data['card_info']['card_number'], 
        'cardExpire' => str_replace(' ', '', $payment_data['post_data']['card_expiry']),
        'cvv2'       => $payment_data['card_info']['card_cvc'] 
    ));
    
    $payment->sale($payment_data['price']); 
    
    $payment->process();  
    

    return $payment;
}

// save payment to wp database
function bluepay_create_payment( $payment_data ) {

	$form_id  = intval( $payment_data['post_data']['give-form-id'] );
	$price_id = isset( $payment_data['post_data']['give-price-id'] ) ? $payment_data['post_data']['give-price-id'] : '';

	// Collect payment data.
	$insert_payment_data = array(
		'price'           => $payment_data['price'],
		'give_form_title' => $payment_data['post_data']['give-form-title'],
		'give_form_id'    => $form_id,
		'give_price_id'   => $price_id,
		'date'            => $payment_data['date'],
		'user_email'      => $payment_data['user_email'],
		'purchase_key'    => $payment_data['purchase_key'],
		'currency'        => give_get_currency( $form_id, $payment_data ),
		'user_info'       => $payment_data['user_info'],
		'status'          => 'pending',
		'gateway'         => 'bluepay',
	);
	
	return give_insert_payment( $insert_payment_data );
}

add_filter('give_create_payment','bluepay_create_payment');