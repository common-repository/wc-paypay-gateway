<?php
/**
 * Plugin Name: Payment Gateway PayPay for WooCommerce
 * Plugin URI: https://www.wpmarket.jp/product/wc_paypay_gateway/
 * Description: Take PayPay payments on your store of WooCommerce.
 * Author: Hiroaki Miyashita
 * Author URI: https://www.wpmarket.jp/
 * Version: 0.7
 * Requires at least: 4.4
 * Tested up to: 5.8.3
 * WC requires at least: 3.0
 * WC tested up to: 6.1.1
 * Text Domain: wc-paypay-gateway
 * Domain Path: /
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once('vendor/autoload.php');
use PayPay\OpenPaymentAPI\Client;
use PayPay\OpenPaymentAPI\Models\CreateQrCodePayload;
use PayPay\OpenPaymentAPI\Models\OrderItem;
use PayPay\OpenPaymentAPI\Models\CapturePaymentAuthPayload;
use PayPay\OpenPaymentAPI\Models\RefundPaymentPayload;
use PayPay\OpenPaymentAPI\Models\RevertAuthPayload;

function wc_paypay_gateway_missing_admin_notices() {
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'PayPay requires WooCommerce to be installed and active. You can download %s here.', 'wc-paypay-gateway' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

function wc_paypay_gateway_mode_admin_notices() {
	echo '<div class="error"><p><strong><a href="https://www.wpmarket.jp/product/wc_paypay_gateway/?domain='.$_SERVER['HTTP_HOST'].'" target="_blank">'.__( 'In order to make the mode Real, you have to purchase the authentication key at the following site.', 'wc-paypay-gateway' ).'</a></strong></p></div>';
}

add_action( 'plugins_loaded', 'wc_paypay_gateway_plugins_loaded' );
add_filter( 'woocommerce_payment_gateways', 'wc_paypay_gateway_woocommerce_payment_gateways' );
add_action( 'template_redirect', 'wc_paypay_gateway_template_redirect' );

function wc_paypay_gateway_template_redirect() {
	if ( !is_wc_endpoint_url( 'order-received' ) || empty( $_GET['key'] ) ) :
		return;
	endif;
				
	$order_id = wc_get_order_id_by_order_key( $_GET['key'] );
	$order = wc_get_order( $order_id );
	
	if ( 'paypay' !== $order->get_payment_method() ) return;
	
	$WC_Gateway_Paypay = new WC_Gateway_Paypay();
	$client = $WC_Gateway_Paypay->paypay_set_api_key();
	$response = $client->code->getPaymentDetails( "pgw_".$order_id );
	
	if( $response['resultInfo']['code'] !== 'SUCCESS' || ( $response['data']['status'] !== 'COMPLETED' && $response['data']['status'] !== 'AUTHORIZED' ) ) :
		$order->update_status( 'failed', __( 'PayPay charge timed out', 'wc-paypay-gateway' ) );
		wc_add_notice( __( 'PayPay charge timed out', 'wc-paypay-gateway' ), 'error' ); 
		wp_safe_redirect( wc_get_checkout_url() );
    	exit;
	endif;
}

function wc_paypay_gateway_plugins_loaded() {
	load_plugin_textdomain( 'wc-paypay-gateway', false, plugin_basename( dirname( __FILE__ ) ) );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_paypay_gateway_missing_admin_notices' );
		return;
	}
	
	$paypay_option = get_option('woocommerce_paypay_settings');
	if ( empty($paypay_option['authentication_key']) ) :
		add_action( 'admin_notices', 'wc_paypay_gateway_mode_admin_notices' );	
	endif;

	if ( ! class_exists( 'WC_Gateway_Paypay' ) ) :

		class WC_Gateway_Paypay extends WC_Payment_Gateway {			
			public function __construct() {
				$this->id = 'paypay';
				$this->method_title = __('PayPay', 'wc-paypay-gateway');
				$this->method_description = __('PayPay is barcode based payment services in Japan.', 'wc-paypay-gateway');
				$this->has_fields = false;
				$this->supports = array('refunds');
				
				$this->init_form_fields();
				$this->init_settings();
				
				$this->enabled = $this->get_option( 'enabled' );
				$this->title = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );
				$this->api_key = $this->get_option( 'api_key' );
				$this->secret_key = $this->get_option( 'secret_key' );
				$this->merchant_id = $this->get_option( 'merchant_id' );
				$this->mode = $this->get_option( 'mode' );
				$this->authorization = $this->get_option( 'authorization' );
				$this->status = $this->get_option( 'status' );
				$this->logging = $this->get_option( 'logging' );
				$this->authentication_key = $this->get_option( 'authentication_key' );
																				
				add_filter ('woocommerce_gateway_icon', array( &$this, 'wc_paypay_gateway_woocommerce_gateway_icon'), 10, 2 );
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
				add_action( 'woocommerce_thankyou', array( &$this, 'wc_paypay_gateway_woocommerce_thankyou' ) );
				add_action( 'woocommerce_order_status_processing', array( &$this, 'wc_paypay_gateway_woocommerce_order_status_completed' ) );
				add_action( 'woocommerce_order_status_completed', array( &$this, 'wc_paypay_gateway_woocommerce_order_status_completed' ) );
				add_action( 'woocommerce_order_status_cancelled', array( &$this, 'wc_paypay_gateway_woocommerce_order_status_cancelled' ) );
				add_action( 'woocommerce_api_wc_paypay', array( $this, 'check_for_webhook' ) );
				add_action( 'woocommerce_available_payment_gateways', array( $this, 'wc_paypay_gateway_woocommerce_available_payment_gateways' ) );
				
			}
			
			function init_form_fields() {
				$this->form_fields = array(
					'enabled' => array(
						'title'       => __( 'Enable/Disable', 'wc-paypay-gateway' ),
						'label'       => __( 'Enable PayPay', 'wc-paypay-gateway' ),
						'type'        => 'checkbox',
						'description' => '',
						'default'     => 'no',
					),
					'title' => array(
						'title'       => __( 'Title', 'wc-paypay-gateway' ),
						'type'        => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'wc-paypay-gateway' ),
						'default'     => __( 'PayPay', 'wc-paypay-gateway' ),
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => __( 'Description', 'wc-paypay-gateway' ),
						'type'        => 'text',
						'description' => __( 'This controls the description which the user sees during checkout.', 'wc-paypay-gateway' ),
						'default'     => __( 'Pay via PayPay', 'wc-paypay-gateway' ),
						'desc_tip'    => true,
					),
					'api_key'    => array(
						'title' => __('API Key', 'wc-paypay-gateway'),
						'type' => 'text',
						'default' => '' 
					),
					'secret_key'    => array(
						'title' => __('Secret Key', 'wc-paypay-gateway'),
						'type' => 'text',
						'default' => '' 
					),
					'merchant_id'    => array(
						'title' => __('Merchant ID', 'wc-paypay-gateway'),
						'type' => 'text',
						'default' => '' 
					),
					'mode'    => array(
						'title' => __('Mode', 'wc-paypay-gateway'),
						'type' => 'select',
						'default' => '',
						'options'     => array(
							'real' => __('Real', 'wc-paypay-gateway'),
							'sandbox'  => __('Sandbox', 'wc-paypay-gateway')
						)
					),
					'authorization'    => array(
						'title' => __('Authorization', 'wc-paypay-gateway'),
						'type' => 'select',
						'description' => __( 'When order status changes to completed, PayPay charge authorized will be captured automatically.', 'wc-paypay-gateway' ),
						'desc_tip'    => true,
						'default' => '',
						'options'     => array(
							'false' => __('Capture', 'wc-paypay-gateway'),
							'true'  => __('Authorize', 'wc-paypay-gateway')
						)
					),
					'status'    => array(
						'title' => __('Status', 'wc-paypay-gateway'),
						'type' => 'select',
						'description' => __( 'This option is available only in case that Authorization sets to Capture. Authorize moves to On-hold status.', 'wc-paypay-gateway' ),
						'desc_tip'    => true,
						'default' => '',
						'options'     => array(
							'processing'  => __('Processing', 'wc-paypay-gateway'),
							'completed' => __('Completed', 'wc-paypay-gateway')
						)
					),
					'logging'    => array(
						'title'       => __( 'Logging', 'wc-paypay-gateway' ),
						'label'       => __( 'Log debug messages', 'wc-paypay-gateway' ),
						'type'        => 'checkbox',
						'description' => __( 'Save debug messages to the WooCommerce System Status log.', 'wc-paypay-gateway' ),
						'default'     => 'no',
						'desc_tip'    => true,
					),
					'authentication_key'    => array(
						'title' => __('Authentication Key', 'wc-paypay-gateway'),
						'type' => 'text',
						'default' => '',
						'description' => '<a href="https://www.wpmarket.jp/product/wc_paypay_gateway/?domain='.$_SERVER['HTTP_HOST'].'" target="_blank">'.__( 'In order to make the mode Real, you have to purchase the authentication key at the following site.', 'wc-paypay-gateway' ).'</a>',
					),
				);
			}
			
			function wc_paypay_gateway_woocommerce_gateway_icon( $icon, $id ) {
				if ( $id == 'paypay' ) :
					$icon = '<img src="'.plugins_url( 'paypay.png', __FILE__ ).'" alt="PayPay" />';
				endif;
				return $icon;
			}
			
			function process_admin_options( ) {
				$this->init_settings();

				$post_data = $this->get_post_data();
				
				$check_value = $this->wc_paypay_gateway_check_authentication_key( $post_data['woocommerce_paypay_authentication_key'] );
				if ( $check_value == false ) :
					$_POST['woocommerce_paypay_authentication_key'] = '';				
				endif;
				
				if ( $post_data['woocommerce_paypay_mode'] == 'real' && $check_value == false ) :
					$_POST['woocommerce_paypay_mode'] = 'sandbox';
			
					$settings = new WC_Admin_Settings();
         			$settings->add_error( __('Because Authentication Key is not valid, you can not set Real as the mode.', 'wc-paypay-gateway') );
				endif;
				
				return parent::process_admin_options();
			}
			
			function wc_paypay_gateway_check_authentication_key( $auth_key ) {
				$request = wp_remote_get('https://www.wpmarket.jp/auth/?gateway=paypay&domain='.$_SERVER['HTTP_HOST'].'&auth_key='.$auth_key);
				if ( ! is_wp_error( $request ) && $request['response']['code'] == 200 ) :
					if ( $request['body'] == 1 ) :
						return true;
					else :
						return false;
					endif;
				else :
					return false;
				endif;
			}
			
			function process_payment( $order_id ) {
				global $woocommerce;
				
				$client = $this->paypay_set_api_key();
				
				$order = new WC_Order( $order_id );

				$woocommerce->cart->empty_cart();
				
				$CQCPayload = new CreateQrCodePayload();
				$CQCPayload->setMerchantPaymentId( "pgw_".$order_id );
				$CQCPayload->setRequestedAt();
				$CQCPayload->setCodeType("ORDER_QR");
				if ( $this->authorization == 'true' ) :
					$CQCPayload->setIsAuthorization(true);
				endif;
				
				$amount = [
					"amount" => $order->get_total(),
					"currency" => "JPY"
				];
		
				$CQCPayload->setAmount($amount);
				$CQCPayload->setRedirectType('WEB_LINK');
				$CQCPayload->setRedirectUrl( $this->get_return_url( $order ) );
				
				try {
					$response = $client->code->createQRCode($CQCPayload);
					$this->logging( $response );
					$data = $response['data'];
				} catch ( \Exception $e ) {
					$this->logging( $e );
					$order->add_order_note( $e->getMessage() );
				}
		
				if( empty($response) || $response['resultInfo']['code'] !== 'SUCCESS' ) :
					return;
				endif;
				
				return array(
					'result' => 'success',
					'redirect' => $data['url']
				);
			}
			
			function wc_paypay_gateway_woocommerce_available_payment_gateways( $available_gateways ) {
				if ( is_checkout() && is_wc_endpoint_url( 'order-pay' ) ) :
					unset( $available_gateways['paypay'] );
				endif;
				
				return $available_gateways;
			}
			
			function wc_paypay_gateway_woocommerce_thankyou( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( 'paypay' !== $order->get_payment_method() ) return;
				
				$client = $this->paypay_set_api_key();
				try {
					$response = $client->code->getPaymentDetails( "pgw_".$order_id );
					$this->logging( $response );
				} catch ( \Exception $e ) {
					$this->logging( $e );
					$order->add_order_note( $e->getMessage() );
				}
	
				if( $response['resultInfo']['code'] !== 'SUCCESS' ) :
					$order->update_status( 'failed', sprintf( __( 'PayPay payment failed: %s', 'wc-paypay-gateway' ), $response['resultInfo']['message'] ) );
				else :
					if ( $response['data']['status'] == 'COMPLETED' ) :
						$order->update_status( $this->status, sprintf( __( 'PayPay charge completed (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );				
					elseif ( $response['data']['status'] == 'AUTHORIZED' ) :
						$order->update_status( 'on-hold', sprintf( __( 'PayPay charge authorized (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );
					else :
						$order->update_status( 'failed', __( 'PayPay charge timed out', 'wc-paypay-gateway' ) );
					endif;
				endif;
			}
			
			function wc_paypay_gateway_woocommerce_order_status_completed( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( 'paypay' !== $order->get_payment_method() ) return;
				
				$client = $this->paypay_set_api_key();
				try {
					$response = $client->code->getPaymentDetails( "pgw_".$order_id );
					$this->logging( $response );
				} catch ( \Exception $e ) {
					$this->logging( $e );
					$order->add_order_note( $e->getMessage() );
				}
				
				if ( $response['data']['status'] !== 'AUTHORIZED' ) return;
				
				$order_total = $order->get_total();
				if ( 0 < $order->get_total_refunded() ) :
					$order_total = $order_total - $order->get_total_refunded();
				endif;
						
				$CAPayload = new CapturePaymentAuthPayload();
				$CAPayload->setMerchantPaymentId( "pgw_".$order_id );
		
				$amount = [
					"amount" => $order_total,
					"currency" => "JPY"
				];
				$CAPayload->setAmount($amount);
		
				$CAPayload->setMerchantCaptureId( "pgw_".$order_id."_CAPTURE" );
				$CAPayload->setRequestedAt();
				$CAPayload->setOrderDescription( __( 'Charge completed.', 'wc-paypay-gateway' ) );
				$response = $client->payment->capturePaymentAuth($CAPayload);
				$this->logging( $response );
	
				if( $response['resultInfo']['code'] !== 'SUCCESS' ) :
					$order->update_status( 'on-hold', sprintf( __( 'Unable to capture charge: %s', 'wc-paypay-gateway' ), $response['resultInfo']['message'] ) );
				else :
					$order->add_order_note( sprintf( __( 'PayPay charge captured (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );				
				endif;
			}
			
			function wc_paypay_gateway_woocommerce_order_status_cancelled( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( 'paypay' !== $order->get_payment_method() ) return;
				
				$client = $this->paypay_set_api_key();
				try {
					$response = $client->code->getPaymentDetails( "pgw_".$order_id );
					$this->logging( $response );
				} catch ( \Exception $e ) {
					$this->logging( $e );
					$order->add_order_note( $e->getMessage() );
				}
				
				if ( $response['data']['status'] == 'AUTHORIZED' ) :
					$RAPayload = new RevertAuthPayload();
					$RAPayload->setMerchantRevertId( "pgw_".$order_id."_REVERT" );
					$RAPayload->setPaymentId( $response['data']['paymentId'] );
					$RAPayload->setRequestedAt();
					$RAPayload->setReason( __( 'Authorization reverted.', 'wc-paypay-gateway' ) );
					$response = $client->code->revertAuth($RAPayload);
					$this->logging( $response );
	
					if( $response['resultInfo']['code'] !== 'SUCCESS' ) :
						$order->update_status( 'failed', sprintf( __( 'Unable to revert authorization: %s', 'wc-paypay-gateway' ), $response['resultInfo']['message'] ) );
					else :
						$order->add_order_note( sprintf( __( 'PayPay authorization reverted (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );				
					endif;			
				elseif ( $response['data']['status'] == 'COMPLETED' ) :
					$order_total = $order->get_total();
					if ( 0 < $order->get_total_refunded() ) return;
				
					$RPPayload  = new RefundPaymentPayload();
		
					$amount = [
						"amount" => $order_total,
						"currency" => "JPY"
					];
					$RPPayload->setAmount($amount);
		
					$RPPayload->setMerchantRefundId( "pgw_".$order_id."_REFUND" );
					$RPPayload->setPaymentId( $response['data']['paymentId'] );
					$RPPayload->setRequestedAt();
					$response = $client->refund->refundPayment($RPPayload);
					$this->logging( $response );
	
					if( $response['resultInfo']['code'] !== 'SUCCESS' ) :
						$order->update_status( 'failed', sprintf( __( 'Unable to refund charge: %s', 'wc-paypay-gateway' ), $response['resultInfo']['message'] ) );
					else :
						$order->add_order_note( sprintf( __( 'PayPay charge refunded (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );				
					endif;
				endif;
			}

			function process_refund( $order_id, $amount = null, $reason = '' ) {
				$order = wc_get_order( $order_id );
				if ( 'paypay' !== $order->get_payment_method() ) return;
				
				$client = $this->paypay_set_api_key();
				try {
					$response = $client->code->getPaymentDetails( "pgw_".$order_id );
					$this->logging( $response );
				} catch ( \Exception $e ) {
					$this->logging( $e );
					$order->add_order_note( $e->getMessage() );
				}
				
				$RPPayload  = new RefundPaymentPayload();
		
				$amount = [
					"amount" => $amount,
					"currency" => "JPY"
				];
				$RPPayload->setAmount($amount);
		
				$RPPayload->setMerchantRefundId( "pgw_".$order_id."_REFUND" );
				$RPPayload->setPaymentId( $response['data']['paymentId'] );
				$RPPayload->setRequestedAt();
				$response = $client->refund->refundPayment($RPPayload);
	
				if( $response['resultInfo']['code'] !== 'SUCCESS' ) :

				else :
					$order->add_order_note( sprintf( __( 'PayPay charge refunded (Payment ID: %s)', 'wc-paypay-gateway' ), $response['data']['paymentId'] ) );
					return true;
				endif;
			}
			
			function paypay_set_api_key() {
				$this->api_key = $this->get_option( 'api_key' );
				$this->secret_key = $this->get_option( 'secret_key' );
				$this->merchant_id = $this->get_option( 'merchant_id' );
				$this->mode = $this->get_option( 'mode' );
				
				if ( $this->mode == 'real' ) :
					$client = new Client(['API_KEY' => $this->api_key, 'API_SECRET' => $this->secret_key, 'MERCHANT_ID' => $this->merchant_id], true); 
				else :
					$client = new Client(['API_KEY' => $this->api_key, 'API_SECRET' => $this->secret_key, 'MERCHANT_ID' => $this->merchant_id], false); 
				endif;

				return $client;
			}
			
			function check_for_webhook() {			
				$request_body    = file_get_contents( 'php://input' );
				$request_headers = array_change_key_case( $this->get_request_headers(), CASE_UPPER );
				$this->logging( $request_headers );
				$this->logging( $request_body );
		
				$notification = json_decode( $request_body );				
				if ( empty($notification) || $notification->notification_type != 'Transaction' || $notification->merchant_id != $this->merchant_id ) exit;
			
				$order = new WC_Order( preg_replace('/pgw_/', '', $notification->merchant_order_id) );
				if ( !empty($order) ) :
					switch ( $notification->state ) :
						case 'AUTHORIZED' :
							if ( $order->status != 'on-hold' ) :
								$order->update_status( 'on-hold', sprintf( __( 'PayPay charge authorized (Payment ID: %s)', 'wc-paypay-gateway' ), $notification->order_id ) );
							endif;
							break;
						case 'COMPLETED' :
							if ( empty($this->status) ) $this->status = 'processing';
							if ( $order->status != $this->status ) :
								$order->update_status( $this->status, sprintf( __( 'PayPay charge completed (Payment ID: %s)', 'wc-paypay-gateway' ), $notification->order_id ) );
							endif;
							break;
						case 'CANCELED' :
							if ( $order->status != 'cancelled' ) :
								$order->update_status( 'cancelled', sprintf( __( 'PayPay charge canceled (Payment ID: %s)', 'wc-paypay-gateway' ), $notification->order_id ) );
							endif;
							break;
						case 'EXPIRED' :
						case 'EXPIRED_USER_CONFIRMATION' :
							if ( $order->status != 'failed' ) :
								$order->update_status( 'failed', sprintf( __( 'PayPay charge expired (Payment ID: %s)', 'wc-paypay-gateway' ), $notification->order_id ) );
							endif;
							break;
						case 'FAILED' :
							if ( $order->status != 'failed' ) :
								$order->update_status( 'failed', sprintf( __( 'PayPay charge failed (Payment ID: %s)', 'wc-paypay-gateway' ), $notification->order_id ) );
							endif;
							break;
					endswitch;
				endif;
				
				exit;
			}

			function get_request_headers() {
				if ( ! function_exists( 'getallheaders' ) ) :
					$headers = array();

					foreach ( $_SERVER as $name => $value ) :
						if ( 'HTTP_' === substr( $name, 0, 5 ) ) :
							$headers[ str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', substr( $name, 5 ) ) ) ) ) ] = $value;
						endif;
					endforeach;

					return $headers;
				else :
					return getallheaders();
				endif;
			}
			
			function logging( $error ) {
				if ( !empty($this->logging) ) :
					$logger = wc_get_logger();
					$logger->debug( wc_print_r( $error, true ), array( 'source' => 'wc-paypay-gateway' ) );
				endif;
			}
		}

	endif;
}

function wc_paypay_gateway_woocommerce_payment_gateways( $methods ) {
	$methods[] = 'WC_Gateway_Paypay'; 
	return $methods;
}
?>