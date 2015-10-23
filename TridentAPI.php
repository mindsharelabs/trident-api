<?php
/**
 * Trident API
 *
 * @class      TridentAPI
 *
 * @author     Damian Taggart
 * @version    1.1
 * @copyright  Mindshare Studios, Inc., July 2013 - April 2014
 * @package    Shopp
 * @since      1.4
 * @subpackage TridentAPI
 *
 * $Id$
 **/

// API Help docs:
// @link http://resources.merchante-solutions.com/display/TPGPUB/PHP+Client+Library
// @link http://resources.merchante-solutions.com/download/attachments/1411006/MeS+Gateway+v4.8+April+2013.pdf?version=1&modificationDate=1365522978760

defined('WPINC') || header('HTTP/1.1 403') & exit; // Prevent direct access

class TridentAPI extends GatewayFramework implements GatewayModule {
	var $cards = array(
		"visa",
		"mc",
		"amex",
		"disc"
	);
	var $secure = TRUE;
	var $captures = TRUE;
	var $refunds = TRUE;

	var $liveurl = "https://api.merchante-solutions.com/mes-api/tridentApi";
	var $testurl = "https://cert.merchante-solutions.com/mes-api/tridentApi";

	var $xtypes = array(
		'sale'    => 'D',
		'auth'    => 'P', // using D instead of P to cause the transaction to settle right away
		'capture' => 'S',
		'refund'  => 'U',
		'void'    => 'V'
	);

	// D=Sale
	// C=Credit (Domestic only)
	// P=Pre-Auth
	// J=Re-Auth (of a declined transaction)
	// O=Offline (Domestic only)
	// V-Void
	// S=Settle Pre-Auth
	// U=Refund (no multiples for International)
	// T=Store card data.
	// X=Delete Store card data
	// A=Verification Only (Domestic only)
	// I=Inquiry
	// Z=Batch Close

	function __construct() {
		parent::__construct();
		$this->setup('test-login', 'test-password', 'testmode');

		add_filter('shopp_tridentapi_url', array($this, 'url'));
		add_action('shopp_tridentapi_sale', array($this, 'sale'));
		add_action('shopp_tridentapi_auth', array($this, 'sale'));
		add_filter('shopp_purchase_order_tridentapi_processing', create_function('', 'return "sale";'));
		add_action('shopp_tridentapi_void', array($this, 'void'));
		add_action('shopp_tridentapi_capture', array($this, 'capture'));
		add_action('shopp_tridentapi_refund', array($this, 'refund'));
	}

	function actions() {
	}

	function sale($Event) {

		$_ = array();
		$_ = $this->header($_);
		$_ = $this->payment($_, $Event->name);
		//mapi_var_dump($_, 1);

		$response = $this->send($_);

		if(!$response || is_a($response, 'ShoppError')) {
			return shopp_add_order_event(
				$Event->order,
				'auth-fail',
				array(
					'amount'  => $Event->amount, // Amount to be captured
					'error'   => $response->error_code, // Error code (if provided)
					'message' => $response->auth_response_text, // Error message reported by the gateway
					'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
				)
			);
		}

		$Paymethod = $this->Order->paymethod();
		$Billing = $this->Order->Billing;

		// authorized or held
		if($response->error_code == '000') {
			shopp_add_order_event(
				$Event->order,
				'authed',
				array(
					'txnid'     => $this->txnid($response), // Transaction ID
					'amount'    => $Event->amount, // Gross amount authorized
					'gateway'   => $Paymethod->processor, // Gateway handler name (module name from @subpackage)
					'paymethod' => $Paymethod->label, // Payment method (payment method label from payment settings)
					'paytype'   => $Billing->cardtype, // Type of payment (check, MasterCard, etc)
					'payid'     => $Billing->card, // Payment ID (last 4 of card or check number)
					'capture'   => ('sale' == $Event->name) // True if the payment was captured
				)
			);
			/*
				TODO add log order event for held transactions
			*/
		}
	}

	function capture($Event) {
		$_ = array();
		$_ = $this->header($_);

		$_['transaction_type'] = $this->xtypes[$Event->name];
		$_['transaction_id'] = $Event->txnid;

		$response = $this->send($_);

		if(is_a($response, 'ShoppError')) {
			return shopp_add_order_event(
				$Event->order,
				'capture-fail',
				array(
					'amount'  => $Event->amount, // Amount to be captured
					'error'   => $response->error_code, // Error code (if provided)
					'message' => $response->auth_response_text, // Error message reported by the gateway
					'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
				)
			);
		}

		$txnid = $this->txnid($response->transaction_id);

		// authorized or held
		if($response->error_code == '000') {
			shopp_add_order_event(
				$Event->order,
				'captured',
				array(
					'txnid'   => $txnid, // Transaction ID of the CAPTURE event
					'amount'  => $Event->amount, // Amount captured
					'fees'    => 0.0, // Transaction fees taken by the gateway net revenue = amount-fees
					'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
				)
			);
			/*
				TODO add log order event for held transactions
			*/
		}
	}

	function void($Event) {
		$Order = shopp_order($Event->txnid, 'trans');

		$_ = array();
		$_ = $this->header($_);

		$_['transaction_type'] = $this->xtypes[$Event->name];
		$_['transaction_id'] = $Event->txnid;
		$response = $this->send($_);

		$txnid = $this->txnid($response->transaction_id);
		if(is_a($response, 'ShoppError')) {
			return shopp_add_order_event(
				$Event->order,
				'void-fail',
				array(
					'error'   => $response->error_code, // Error code (if provided)
					'message' => $response->auth_response_text, // Error message reported by the gateway
					'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
				)
			);
		}

		shopp_add_order_event(
			$Event->order,
			'voided',
			array(
				'txnorigin' => $Event->txnid, // Original transaction ID (txnid of original Purchase record)
				'txnid'     => $txnid, // Transaction ID for the VOID event
				'gateway'   => $Event->gateway // Gateway handler name (module name from @subpackage)
			)
		);
	}

	function refund($Event) {
		$Order = shopp_order($Event->order);

		$_ = array();
		$_ = $this->header($_);

		$_['transaction_type'] = $this->xtypes[$Event->name];
		$_['transaction_id'] = $Event->txnid;
		$_['transaction_amount'] = $Event->amount;

		$_['card_number'] = $Order->card;
		$_['card_exp_date'] = _d('my', $Purchase->cardexpires);

		$response = $this->send($_);

		if(is_a($response, 'ShoppError')) {
			return shopp_add_order_event(
				$Event->order,
				'refund-fail',
				array(
					'amount'  => $Event->amount, // Amount of the refund attempt
					'error'   => $response->error_code, // Error code (if provided)
					'message' => $response->auth_response_text, // Error message reported by the gateway
					'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
				)
			);
		}

		$txnid = $this->txnid($response->transaction_id);

		shopp_add_order_event(
			$Event->order,
			'refunded',
			array(
				'txnid'   => $txnid, // Transaction ID for the REFUND event
				'amount'  => $Event->amount, // Amount refunded
				'gateway' => $Event->gateway // Gateway handler name (module name from @subpackage)
			)
		);
	}

	function header($_) {

		// determine which login to use (testing or live)
		if(Shopp::str_true($this->settings['testmode'])) {
			$_['profile_id'] = $this->settings['test-login'];
			$_['profile_key'] = $this->settings['test-password'];
		} else {
			$_['profile_id'] = $this->settings['login'];
			$_['profile_key'] = $this->settings['password'];
		}

		return $_;
	}

	function txnid($response) {
		if(!isset($response->transaction_id) || empty($response->transaction_id)) {
			return parent::txnid();
		}
		return $response->transaction_id;
	}

	function error($Response) {

		return new ShoppError($Response->auth_response_text, 'trident_api_error', SHOPP_TRXN_ERR,
			array('code' => $Response->error_code));
	}

	function payment($_, $type) {
		$Order = ShoppOrder();
		$Customer = $Order->Customer;
		$Billing = $Order->Billing;
		$Shipping = $Order->Shipping;
		$Cart = $Order->Cart;
		//$Totals = $Cart->Totals;

		// Options
		$_['transaction_type'] = $this->xtypes[$type];

		// Required Fields
		$_['transaction_amount'] = $this->amount('total');
		$_['ip_address'] = $_SERVER["REMOTE_ADDR"];

		// Customer Contact
		$_['cardholder_first_name'] = $Customer->firstname;
		$_['cardholder_last_name'] = $Customer->lastname;
		$_['cardholder_email'] = $Customer->email;
		$_['cardholder_phone'] = $Customer->phone;

		// Billing
		$_['card_number'] = $Billing->card;
		$_['card_exp_date'] = date("my", $Billing->cardexpires);
		$_['cvv2'] = $Billing->cvv;

		$_['cardholder_street_address'] = $Billing->address;
		$_['cardholder_zip'] = $Billing->postcode;
		//$_['country_code'] = $Billing->country;

		// Shipping
		$_['ship_to_first_name'] = $Customer->firstname;
		$_['ship_to_last_name'] = $Customer->lastname;
		$_['ship_to_address'] = $Shipping->address;
		$_['ship_to_zip'] = $Shipping->postcode;
		//$_['dest_country_code'] = $Shipping->country;

		// Transaction
		$_['shipping_amount'] = $this->amount('shipping');
		$_['tax_amount'] = $this->amount('tax');

		return $_;
	}

	function send($data) {
		$url = apply_filters('shopp_tridentapi_url', $url);

		$request = $this->encode($data);
		//mapi_var_dump($data,1);

		$response = parent::send($request, $url);
		new ShoppError('RESPONSE: '.$response, FALSE, SHOPP_DEBUG_ERR);
		$response = $this->response($response);

		if(!$response) {
			return FALSE;
		}

		// not authorized or held
		if($response->error_code != '000') {
			return $this->error($response);
		}

		return $response;
	}

	function response($buffer) {
		// convert the result string into an object
		$array = array();
		parse_str($buffer, $array);
		$_ = new stdClass();
		$_ = json_decode(json_encode($array), FALSE);
		return $_;
	}

	function url($url) {
		if(str_true($this->settings['testmode'])) {
			return $this->testurl;
		}
		return $this->liveurl;
	}

	function settings() {
		$this->ui->cardmenu(
			0,
			array(
				'name'     => 'cards',
				'selected' => $this->settings['cards']
			), $this->cards);

		$this->ui->text(
			1,
			array(
				'name'  => 'login',
				'value' => $this->settings['login'],
				'size'  => '16',
				'label' => __('Enter your Merchant e-Solutions Profile ID.', 'Shopp')
			)
		);

		$this->ui->password(
			1,
			array(
				'name'  => 'password',
				'value' => $this->settings['password'],
				'size'  => '24',
				'label' => __('Enter your Merchant e-Solutions Profile Key.', 'Shopp')
			)
		);

		$this->ui->text(
			1,
			array(
				'name'  => 'test-login',
				'value' => $this->settings['test-login'],
				'size'  => '16',
				'label' => __('Enter your Merchant e-Solutions Testing Profile ID.', 'Shopp')
			)
		);

		$this->ui->password(
			1,
			array(
				'name'  => 'test-password',
				'value' => $this->settings['test-password'],
				'size'  => '24',
				'label' => __('Enter your Merchant e-Solutions Testing Profile Key.', 'Shopp')
			)
		);

		$this->ui->checkbox(
			1,
			array(
				'name'    => 'testmode',
				'checked' => $this->settings['testmode'],
				'label'   => __('Enable test mode', 'Shopp')
			)
		);
	}
}
