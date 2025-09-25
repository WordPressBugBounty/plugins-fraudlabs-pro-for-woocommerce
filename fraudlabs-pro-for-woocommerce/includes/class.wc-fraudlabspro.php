<?php
defined( 'DS' ) or define( 'DS', DIRECTORY_SEPARATOR );
define( 'FRAUDLABS_PRO_ROOT', dirname( __DIR__ ) . DS );

require_once FRAUDLABS_PRO_ROOT . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if ( ! class_exists( 'WC_FraudLabs_Pro' ) ) :

class WC_FraudLabs_Pro {
	protected static $instance;

	private $order;
	private $namespace;
	private $enabled;
	private $api_key;
	public  $validation_sequence;
	private $flp_advanced_velocity;
	private $approve_status;
	private $review_status;
	private $reject_status;
	private $db_err_status;
	private $change_status_auto;
	private $reject_failed_order;
	private $fraud_message;
	private $real_ip_detect;
	private $test_ip;
	private $notification_approve;
	private $notification_review;
	private $notification_reject;
	private $expand_report;
	private $debug_log;
	private $debug_log_path;

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}


	public function __construct() {
		// Do not proceed if WooCommerce is not installed.
		if ( ! function_exists( 'WC' ) ) {
			$this->write_debug_log( 'WooCommerce plugin is not installed. FraudLabs Pro validation will not be performed.' );
			return;
		}

		$this->namespace				= 'woocommerce-fraudlabs-pro';
		$this->enabled					= $this->get_setting( 'enabled' );
		$this->api_key					= $this->get_setting( 'api_key' );
		$this->validation_sequence		= ( $this->get_setting( 'validation_sequence' ) ) ? $this->get_setting( 'validation_sequence' ) : 'after';
		$this->flp_advanced_velocity	= $this->get_setting( 'flp_advanced_velocity' );
		$this->approve_status			= $this->get_setting( 'approve_status' );
		$this->review_status			= ( $this->get_setting( 'review_status' ) ) ? $this->get_setting( 'review_status' ) : '';
		$this->reject_status			= ( $this->get_setting( 'reject_status' ) ) ? $this->get_setting( 'reject_status' ) : '';
		$this->db_err_status			= ( $this->get_setting( 'db_err_status' ) ) ? $this->get_setting( 'db_err_status' ) : '';
		$this->change_status_auto		= $this->get_setting( 'change_status_auto' );
		$this->reject_failed_order		= $this->get_setting( 'reject_failed_order' );
		$this->fraud_message			= $this->get_setting( 'fraud_message' );
		$this->real_ip_detect			= ( $this->get_setting( 'real_ip_detect' ) ) ? $this->get_setting( 'real_ip_detect' ) : '';
		$this->test_ip					= $this->get_setting( 'test_ip' );
		$this->notification_approve		= $this->get_setting( 'notification_approve' );
		$this->notification_review		= $this->get_setting( 'notification_review' );
		$this->notification_reject		= $this->get_setting( 'notification_reject' );
		$this->expand_report			= $this->get_setting( 'expand_report' );
		$this->debug_log				= $this->get_setting( 'debug_log' );
		$this->debug_log_path			= $this->get_setting( 'debug_log_path' );

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_footer_text', array( $this, 'admin_footer_text' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'wp_ajax_fraudlabspro_woocommerce_submit_feedback', array( $this, 'submit_feedback' ) );
		add_action( 'wp_ajax_fraudlabspro_woocommerce_validate_api_key', array( $this, 'validate_api_key' ) );
		add_action( 'wp_loaded', array( $this, 'wc_flp_callback' ) );

		// Hooks for WooCommerce
		add_filter( 'manage_shop_order_posts_columns', array( $this, 'add_column' ), 11 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 3 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column_hpos' ), 11 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_fraud_report' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'javascript_agent' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'checkout_order_processed' ), 99, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 99, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_status_completed' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_status_cancelled' ) );
		add_action( 'woocommerce_pre_payment_complete', array( $this, 'pre_payment_complete' ) );
		add_action( 'woocommerce_payment_complete', array( $this, 'payment_complete' ) );
	}


	/**
	 * Validate the order before payment gateway.
	 */
	public function checkout_order_processed( $order_id, $posted_data, $order ) {
		// Collect IP information before the payment gateway
		$ip_x_sucuri_before = $ip_incap_before = $ip_http_cf_connecting_before = $ip_x_forwarded_for_before = $ip_x_real_before = $ip_http_client_before = $ip_http_forwarded_before = $ip_x_forwarded_before ='::1';

		if ( isset( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) && filter_var( $_SERVER['HTTP_X_SUCURI_CLIENTIP'], FILTER_VALIDATE_IP ) ) {
			$ip_x_sucuri_before = $_SERVER['HTTP_X_SUCURI_CLIENTIP'];
		}

		if( isset( $_SERVER['HTTP_INCAP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_INCAP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_incap_before = $_SERVER['HTTP_INCAP_CLIENT_IP'];
		}

		if( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && filter_var( $_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_http_cf_connecting_before = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip_x_real_before = $_SERVER['HTTP_X_REAL_IP'];
		}

		if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xip = trim(current(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));

			if (filter_var($xip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				$ip_x_forwarded_for_before = $xip;
			}
		}

		if( isset( $_SERVER['HTTP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_http_client_before = $_SERVER['HTTP_CLIENT_IP'];
		}

		if( isset( $_SERVER['HTTP_FORWARDED'] ) && filter_var( $_SERVER['HTTP_FORWARDED'], FILTER_VALIDATE_IP ) ) {
			$ip_http_forwarded_before = $_SERVER['HTTP_FORWARDED'];
		}

		if( isset( $_SERVER['HTTP_X_FORWARDED'] ) && filter_var( $_SERVER['HTTP_X_FORWARDED'], FILTER_VALIDATE_IP ) ) {
			$ip_x_forwarded_before = $_SERVER['HTTP_X_FORWARDED'];
		}

		$ip_remote_addr_before = $_SERVER['REMOTE_ADDR'];
		$flp_checksum_before = ( isset( $_COOKIE['flp_checksum'] ) ) ? $_COOKIE['flp_checksum'] : '';
		$flp_device_before = ( isset( $_COOKIE['flp_device'] ) ) ? $_COOKIE['flp_device'] : '';

		$flpIP = [
			'ip_x_sucuri_before'			=> $ip_x_sucuri_before,
			'ip_incap_before'				=> $ip_incap_before,
			'ip_http_cf_connecting_before'	=> $ip_http_cf_connecting_before,
			'ip_x_real_before'				=> $ip_x_real_before,
			'ip_x_forwarded_for_before'		=> $ip_x_forwarded_for_before,
			'ip_http_client_before'			=> $ip_http_client_before,
			'ip_http_forwarded_before'		=> $ip_http_forwarded_before,
			'ip_x_forwarded_before'			=> $ip_x_forwarded_before,
			'ip_remote_addr_before'			=> $ip_remote_addr_before,
			'flp_checksum_before'			=> $flp_checksum_before,
			'flp_device_before'				=> $flp_device_before,
		];

		add_post_meta( $order_id, '_fraudlabspro_ip_before', $flpIP );
		$table_name = $this->create_flpwc_table();
		$this->add_flpwc_data($table_name, $order_id, '_fraudlabspro_ip_before', $flpIP);

		if ( $this->validation_sequence != 'before' ) {
			return;
		}

		$this->write_debug_log( 'Checkout order processed for Order ' . $order_id . '.');
		$this->order = wc_get_order( $order_id );

		if ( $this->validate_order() === false ) {
			wc_add_notice( ( !empty( $this->fraud_message ) ) ? $this->fraud_message : 'This order ' . $this->order->get_id() . ' failed our fraud validation. Please contact us for more details.', 'error' );

			global $woocommerce;
			$woocommerce->cart->empty_cart();

			if ( is_ajax() ) {
				wp_send_json( array(
					'result'   => 'success',
					'redirect' => apply_filters( 'woocommerce_checkout_no_payment_needed_redirect', wc_get_cart_url(), $this->order ),
				) );
			} else {
				wp_safe_redirect(
					apply_filters( 'woocommerce_checkout_no_payment_needed_redirect', wc_get_cart_url(), $this->order )
				);
				exit;
			}
		}
	}


	/**
	 * Validate the order after payment gateway.
	 */
	public function order_status_changed( $order_id, $old_status, $new_status ) {
		if ( $this->validation_sequence == 'before' ) {
			$order = wc_get_order( $order_id );
			$result = get_post_meta( $order_id, '_fraudlabspro' );

			if (!$result) {
				$table_name = $this->create_flpwc_table();
				$result = $this->get_flpwc_data($table_name, $order_id, '_fraudlabspro');
			}

			$idx = count( $result ) - 1;
			if ( isset( $result[$idx] ) ) {
				if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
					$row = json_decode( $result[$idx] );
				}
			} else {
				$row = $result[$idx];
			}

			if (isset($row)) {
				if ( is_array( $result[$idx] ) ) {
					if ( $row['fraudlabspro_status'] == 'APPROVE' && $new_status == 'on-hold' ) {
						$order->update_status( $this->approve_status, __( '', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					}
				} else {
					if (isset($row->fraudlabspro_status)) {
						if ( $row->fraudlabspro_status == 'APPROVE' && $new_status == 'on-hold' ) {
							$order->update_status( $this->approve_status, __( '', $this->namespace ) );
							wp_safe_redirect($_SERVER['REQUEST_URI']);
							exit;
						}
					}
				}
			}
		}

		if ( $this->validation_sequence != 'after' ) {
			return;
		}

		$this->order = wc_get_order( $order_id );

		if ( in_array( $new_status, array( 'processing', 'on-hold', 'failed' ) ) ) {
			$this->write_debug_log( 'Order status changed from ' . $old_status . ' to ' . $new_status . ' for Order ' . $order_id . '.');
			$this->validate_order($old_status, $new_status);
		}
	}


	/**
	 * Perform order validation.
	 */
	public function validate_order($oldStatus = '', $newStatus = '') {
		if ( $this->enabled != 'yes' ) {
			$this->write_debug_log( 'FraudLabs Pro Validation not enabled. Order validation will not be performed.' );
			return;
		}

		// Skip check order screened if Validate Order is Before submit order to payment gateway and enabled Advanced Velocity Screening
		if ( ( $this->validation_sequence == 'before' ) && ( get_option('wc_settings_woocommerce-fraudlabs-pro_flp_advanced_velocity') == 'yes' ) ) {
			$this->write_debug_log( 'Advanced Velocity Screening enabled.' );
		} elseif ( ( $oldStatus == 'failed' ) && ( $newStatus == 'processing' ) ) {
			$this->write_debug_log( 'Advanced Screening for Processing Order from Failed Order.' );
		} elseif ( ( $oldStatus == 'failed' ) && ( $newStatus == 'on-hold' ) ) {
			$this->write_debug_log( 'Advanced Screening for On Hold Order from Failed Order.' );
		} else {
			// Check if order has been screened
			$result = get_post_meta( $this->order->get_id(), '_fraudlabspro' );

			if (!$result) {
				$table_name = $this->create_flpwc_table();
				$result = $this->get_flpwc_data($table_name, $this->order->get_id(), '_fraudlabspro');
			}

			if( count( $result ) > 0 ) {
				return;
			}

			// Double check if order has been screened
			if ( $this->get_order_notes( $this->order->get_id() ) ) {
				$result_order_note = $this->get_order_notes( $this->order->get_id() );
				$this->write_debug_log( 'Order has been validated. Skip for FraudLabs Pro validation.' );
				$this->write_debug_log( $result_order_note );
				return;
			}
		}

		// Prevent digital downloads before order is completed.
		update_option( 'woocommerce_downloads_grant_access_after_payment', 'no' );

		// $this->order->add_order_note( __( 'FraudLabs Pro validation has started.', $this->namespace ) );
		$this->write_debug_log( 'FraudLabs Pro validation has started for Order ' . $this->order->get_id() . '.' );

		$payment_gateway = wc_get_payment_gateway_by_order( $this->order );
		$qty = 0;

		$item_sku = '';
		foreach ($this->order->get_items() as $item_id => $item_data) {
			$item_quantity = $item_data->get_quantity();
			$product = wc_get_product($item_data->get_product_id());
			if ($product->get_sku() != '') {
				$item_type = ($product->get_virtual()) ? 'virtual' : (($product->get_downloadable()) ? 'downloadable' :'physical');
				$item_sku .= $product->get_sku() . ':' . $item_quantity . ':' . $item_type . ',';
			}
			$qty += $item_data['qty'];
		}
		$item_sku = rtrim($item_sku, ',');

		if (preg_match('/^\d+(\.\d)*$/', $qty)) {
			$qty = ceil($qty);
		}

		if ( isset( $payment_gateway->id ) ) {
			switch ( $payment_gateway->id ) {
				case 'stripe':
					$payment_mode = 'creditcard';
					break;

				case 'bacs':
					$payment_mode = 'bankdeposit';
					break;

				case 'cod':
					$payment_mode = 'cod';
					break;

				case 'paypal':
				case 'ppec_paypal':
				case 'paypal_express':
					$payment_mode = 'paypal';
					break;

				case 'elviauthorized':
					$payment_mode = 'elviauthorized';
					break;

				case 'paymitco_gateway':
				case 'universal_gateway':
					$payment_mode = 'paymitco';
					break;

				case 'cybersource_sa_sop_credit_card':
				case 'cybersource_credit_card':
					$payment_mode = 'cybersource';
					break;

				case 'amazon_payments_advanced':
					$payment_mode = 'amazonpay';
					break;

				case 'bitpay_checkout_gateway':
					$payment_mode = 'bitcoin';
					break;

				default:
					$payment_mode = $payment_gateway->id;
			}
		} else {
			$payment_mode = 'others';
		}

		// Collect IP information after the payment gateway
		$ip_x_sucuri_after = $ip_incap_after = $ip_http_cf_connecting_after = $ip_x_real_after = $ip_x_forwarded_for_after = $ip_http_client_after = $ip_http_forwarded_after = $ip_x_forwarded_after = '::1';

		if ( isset( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) && filter_var( $_SERVER['HTTP_X_SUCURI_CLIENTIP'], FILTER_VALIDATE_IP ) ) {
			$ip_x_sucuri_after = $_SERVER['HTTP_X_SUCURI_CLIENTIP'];
		}

		if( isset( $_SERVER['HTTP_INCAP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_INCAP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_incap_after = $_SERVER['HTTP_INCAP_CLIENT_IP'];
		}

		if( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && filter_var( $_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_http_cf_connecting_after = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		if ( isset( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$ip_x_real_after = $_SERVER['HTTP_X_REAL_IP'];
		}

		if( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xip = trim( current( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );

			if ( filter_var( $xip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$ip_x_forwarded_for_after = $xip;
			}
		}

		if( isset( $_SERVER['HTTP_CLIENT_IP'] ) && filter_var( $_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP ) ) {
			$ip_http_client_after = $_SERVER['HTTP_CLIENT_IP'];
		}

		if( isset( $_SERVER['HTTP_FORWARDED'] ) && filter_var( $_SERVER['HTTP_FORWARDED'], FILTER_VALIDATE_IP ) ) {
			$ip_http_forwarded_after = $_SERVER['HTTP_FORWARDED'];
		}

		if( isset( $_SERVER['HTTP_X_FORWARDED'] ) && filter_var( $_SERVER['HTTP_X_FORWARDED'], FILTER_VALIDATE_IP ) ) {
			$ip_x_forwarded_after = $_SERVER['HTTP_X_FORWARDED'];
		}

		// The internal function will check for X_REAL_IP followed by X_FORWARDED_FOR and REMOTE_ADDR
		// Paypal incorrect IP address was solved using the below function
		$client_ip = $this->order->get_customer_ip_address();

		// Get IP result
		$result_ip = get_post_meta( $this->order->get_id(), '_fraudlabspro_ip_before' );

		if (!$result_ip) {
			$table_name = $this->create_flpwc_table();
			$result_ip = $this->get_flpwc_data($table_name, $this->order->get_id(), '_fraudlabspro_ip_before');
		}

		if( count( $result_ip ) > 0 ) {
			if ( !is_array( $result_ip[0] ) && strpos( $result_ip[0], '\\' ) ) {
				$result_ip[0] = str_replace( '\\', '', $result_ip[0] );
			}

			if ( !is_array( $result_ip[0] ) ) {
				$row = json_decode( $result_ip[0] );

				$ip_x_sucuri_before = $row->ip_x_sucuri_before;
				$ip_incap_before = $row->ip_incap_before;
				$ip_http_cf_connecting_before = $row->ip_http_cf_connecting_before;
				$ip_x_real_before = $row->ip_x_real_before;
				$ip_x_forwarded_for_before = $row->ip_x_forwarded_for_before;
				$ip_http_client_before = $row->ip_http_client_before;
				$ip_http_forwarded_before = $row->ip_http_forwarded_before;
				$ip_x_forwarded_before = $row->ip_x_forwarded_before;
				$ip_remote_addr_before = $row->ip_remote_addr_before;
				$flp_checksum_before = $row->flp_checksum_before;
				$flp_device_before = $row->flp_device_before;
			} else {
				$row = $result_ip[0];

				$ip_x_sucuri_before = $row['ip_x_sucuri_before'];
				$ip_incap_before = $row['ip_incap_before'];
				$ip_http_cf_connecting_before = $row['ip_http_cf_connecting_before'];
				$ip_x_real_before = $row['ip_x_real_before'];
				$ip_x_forwarded_for_before = $row['ip_x_forwarded_for_before'];
				$ip_http_client_before = $row['ip_http_client_before'];
				$ip_http_forwarded_before = $row['ip_http_forwarded_before'];
				$ip_x_forwarded_before = $row['ip_x_forwarded_before'];
				$ip_remote_addr_before = $row['ip_remote_addr_before'];
				$flp_checksum_before = $row['flp_checksum_before'];
				$flp_device_before = $row['flp_device_before'];
			}

			if ( isset( $_SERVER['HTTP_X_SUCURI_CLIENTIP'] ) && $ip_x_sucuri_before != '::1' ) {
				$client_ip = $ip_x_sucuri_before;
			} elseif( isset( $_SERVER['HTTP_INCAP_CLIENT_IP'] ) && $ip_incap_before != '::1' ) {
				$client_ip = $ip_incap_before;
			} elseif( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && $ip_http_cf_connecting_before != '::1' ){
				$client_ip = $ip_http_cf_connecting_before;
			} elseif ( isset( $_SERVER['HTTP_X_REAL_IP'] ) && $ip_x_real_before != '::1' ) {
				$client_ip = $ip_x_real_before;
			} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && $ip_x_forwarded_for_before != '::1' ) {
				$client_ip = $ip_x_forwarded_for_before;
			} elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
				$client_ip = $ip_remote_addr_before;
				if (filter_var($ip_remote_addr_before, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
					if (!filter_var($ip_remote_addr_before, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
						$client_ip = '127.0.0.1';
					}
				}
			}

			if (!empty($real_ip_detect) && ($real_ip_detect != 'no_override')) {
				switch ($real_ip_detect) {
					case 'remote_addr':
						if (isset($_SERVER['REMOTE_ADDR']) && ($ip_remote_addr_before != '::1')) {
							$client_ip = $ip_remote_addr_before;
						}
					break;

					case 'http_cf_connecting_ip':
						if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && ($ip_http_cf_connecting_before != '::1')) {
							$client_ip = $ip_http_cf_connecting_before;
						}
					break;

					case 'http_client_ip':
						if (isset($_SERVER['HTTP_CLIENT_IP']) && ($ip_http_client_before != '::1')) {
							$client_ip = $ip_http_client_before;
						}
					break;

					case 'http_forwarded':
						if (isset($_SERVER['HTTP_FORWARDED']) && ($ip_http_forwarded_before != '::1')) {
							$client_ip = $ip_http_forwarded_before;
						}
					break;

					case 'http_incap_client_ip':
						if (isset($_SERVER['HTTP_INCAP_CLIENT_IP']) && ($ip_incap_before != '::1')) {
							$client_ip = $ip_incap_before;
						}
					break;

					case 'http_x_forwarded':
						if (isset($_SERVER['HTTP_X_FORWARDED']) && ($ip_x_forwarded_before != '::1')) {
							$client_ip = $ip_x_forwarded_before;
						}
					break;

					case 'http_x_forwarded_for':
						if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && ($ip_x_forwarded_for_before != '::1')) {
							$client_ip = $ip_x_forwarded_for_before;
						}
					break;

					case 'http_x_real_ip':
						if (isset($_SERVER['HTTP_X_REAL_IP']) && ($ip_x_real_before != '::1')) {
							$client_ip = $ip_x_real_before;
						}
					break;

					case 'http_x_sucuri_clientip':
						if (isset($_SERVER['HTTP_X_SUCURI_CLIENTIP']) && ($ip_x_sucuri_before != '::1')) {
							$client_ip = $ip_x_sucuri_before;
						}
					break;
				}
			}
		} elseif ( $ip_x_real_after != '::1' ) {
			// Get IP via order from eBay [YLL-612-89047]
			$client_ip = $ip_x_real_after;
		}

		if ( !isset( $ip_x_sucuri_before ) ) { $ip_x_sucuri_before = '::1'; }
		if ( !isset( $ip_incap_before ) ) { $ip_incap_before = '::1'; }
		if ( !isset( $ip_http_cf_connecting_before ) ) { $ip_http_cf_connecting_before = '::1'; }
		if ( !isset( $ip_x_real_before ) ) { $ip_x_real_before = '::1'; }
		if ( !isset( $ip_x_forwarded_for_before ) ) { $ip_x_forwarded_for_before = '::1'; }
		if ( !isset( $ip_http_client_before ) ) { $ip_http_client_before = '::1'; }
		if ( !isset( $ip_http_forwarded_before ) ) { $ip_http_forwarded_before = '::1'; }
		if ( !isset( $ip_x_forwarded_before ) ) { $ip_x_forwarded_before = '::1'; }
		if ( !isset( $ip_remote_addr_before ) ) { $ip_remote_addr_before = '::1'; }
		if ( !isset( $flp_checksum_before ) ) { $flp_checksum_before = ''; }
		if ( !isset( $flp_device_before ) ) { $flp_device_before = ''; }

		$credit_card_number = '';
		$cc_key = '';
		$postData = '';
		if ( !empty( $_POST ) ) {
			// Get BIN number data
			foreach ( $_POST as $key => $value ) {
				if (is_string($value)) {
					$value = preg_replace ('/\D/', '', $value);
					if ( $this->is_credit_card( $value ) ) {
						if ( strpos( $key, 'wc_order_attribution' ) !== false ) {}
						elseif ( strpos( $key, 'billing_' ) !== false ) {}
						elseif ( strpos( $key, 'shipping_' ) !== false ) {}
						elseif ( strpos( $key, 'phone' ) !== false ) {}
						else {
							$credit_card_number = $value;
							$cc_key = $key;
							break;
						}
					}
				}
			}
			// Collect data to review
			foreach ( $_POST as $key => $value ) {
				if (is_string($value)) {
					$value = preg_replace ('/\D/', '', $value);
					if (strlen($value) > 10) {
						$postData .= $key . ':' . $value . ',';
					}
				}
			}
			$postData = rtrim($postData, ',');
		}

		$binNo = '';
		$binNo = get_post_meta( $this->order->get_id(), '_flp_bin_no' );

		if (!$binNo) {
			$table_name = $this->create_flpwc_table();
			$binNo = $this->get_flpwc_data($table_name, $this->order->get_id(), '_flp_bin_no');
		}

		$couponCode = '';
		$couponAmt = '';
		$couponType = '';
		if ( $this->order->get_coupon_codes() != '' ) {
			foreach( $this->order->get_coupon_codes() as $coupon_code ) {
				$coupon = new WC_Coupon($coupon_code);
				$couponCode = $coupon->get_code();
				$couponAmt = $coupon->get_amount();
				$couponType = $coupon->get_discount_type();
			}
		}

		$current_user = wp_get_current_user();
		if ( $current_user !== '' ) {
			$current_username = $current_user->user_login;
		} else {
			$current_username = '';
		}

		$bill_country = $ship_country = '';
		$bill_country = ( $this->order->get_billing_country() !== "default" ) ? $this->order->get_billing_country() : '';
		$ship_country = ( $this->order->get_shipping_country() !== "default" ) ? $this->order->get_shipping_country() : '';

		$flpCallbackNonce = $this->create_custom_nonce('check-flp-callback');

		$queries = [
			'key'							=> $this->api_key,
			'format'						=> 'json',
			'ip'							=> ( filter_var( $this->test_ip, FILTER_VALIDATE_IP ) ) ? $this->test_ip : $client_ip,
			'ip_x_sucuri_before'			=> $ip_x_sucuri_before,
			'ip_incap_before'				=> $ip_incap_before,
			'ip_http_cf_connecting_before'	=> $ip_http_cf_connecting_before,
			'ip_x_real_before'				=> $ip_x_real_before,
			'ip_x_forwarded_for_before'		=> $ip_x_forwarded_for_before,
			'ip_http_client_before'			=> $ip_http_client_before,
			'ip_http_forwarded_before'		=> $ip_http_forwarded_before,
			'ip_x_forwarded_before'			=> $ip_x_forwarded_before,
			'ip_remote_addr_before'			=> $ip_remote_addr_before,
			'ip_x_sucuri_after'				=> $ip_x_sucuri_after,
			'ip_incap_after'				=> $ip_incap_after,
			'ip_http_cf_connecting_after'	=> $ip_http_cf_connecting_after,
			'ip_x_real_after'				=> $ip_x_real_after,
			'ip_x_forwarded_for_after'		=> $ip_x_forwarded_for_after,
			'ip_http_client_after'			=> $ip_http_client_after,
			'ip_http_forwarded_after'		=> $ip_http_forwarded_after,
			'ip_x_forwarded_after'			=> $ip_x_forwarded_after,
			'first_name'					=> $this->order->get_billing_first_name(),
			'last_name'						=> $this->order->get_billing_last_name(),
			'bill_addr'						=> trim( $this->order->get_billing_address_1() . ' ' . $this->order->get_billing_address_2() ),
			'bill_city'						=> $this->order->get_billing_city(),
			'bill_state'					=> $this->order->get_billing_state(),
			'bill_zip_code'					=> $this->order->get_billing_postcode(),
			'bill_country'					=> $bill_country,
			'user_phone'					=> $this->order->get_billing_phone(),
			'ship_first_name'				=> $this->order->get_shipping_first_name(),
			'ship_last_name'				=> $this->order->get_shipping_last_name(),
			'ship_addr'						=> trim( $this->order->get_shipping_address_1() . ' ' . $this->order->get_shipping_address_2() ),
			'ship_city'						=> $this->order->get_shipping_city(),
			'ship_state'					=> $this->order->get_shipping_state(),
			'ship_zip_code'					=> $this->order->get_shipping_postcode(),
			'ship_country'					=> $ship_country,
			'email'							=> $this->order->get_billing_email(),
			'email_domain'					=> substr( $this->order->get_billing_email(), strpos( $this->order->get_billing_email(), '@' ) + 1 ),
			'email_hash'					=> $this->hash_string( $this->order->get_billing_email() ),
			'user_order_id'					=> $this->order->get_order_number(),
			'amount'						=> $this->order->get_total(),
			'quantity'						=> $qty,
			'currency'						=> $this->order->get_currency(),
			'payment_gateway'				=> ( $payment_gateway->id ) ?? 'others',
			'payment_mode'					=> $payment_mode,
			'wc_payment_method'				=> ( $payment_gateway->id ) ??'others',
			'flp_checksum'					=> ( $_COOKIE['flp_checksum'] ) ?? $flp_checksum_before,
			'device_fingerprint'			=> ( $_COOKIE['flp_device'] ) ?? $flp_device_before,
			'bin_no'						=> (( $credit_card_number ) ? substr( $credit_card_number, 0, 6 ) : (( $binNo ) ? substr( $binNo[0], 0, 6 ) : '')),
			'card_hash'						=> ( $credit_card_number ) ? $this->hash_string( $credit_card_number ) : '',
			'validation_sequence'			=> $this->validation_sequence,
			'advanced_velocity_screening'	=> ( get_option('wc_settings_woocommerce-fraudlabs-pro_flp_advanced_velocity') == "yes" ) ? 'enabled' : 'disabled',
			'source'						=> 'woocommerce',
			'source_version'				=> '2.23.2',
			'items'							=> $item_sku,
			'cc_key'						=> $cc_key,
			'username'						=> $current_username,
			'avs_result'					=> ( $_SESSION['flp_avs'] ) ?? '',
			'cvv_result'					=> ( $_SESSION['flp_cvv'] ) ?? '',
			'coupon_code'					=> $couponCode,
			'coupon_amount'					=> $couponAmt,
			'coupon_type'					=> $couponType,
			'wc_post_data'					=> $postData,
			'is_wc_failed_order'			=> ($newStatus == 'failed') ? true : false,
			'reject_wc_failed_order'		=> (get_option('wc_settings_woocommerce-fraudlabs-pro_reject_failed_order') == 'yes') ? true : false,
			'callback_nonce'				=> $flpCallbackNonce,
		];

		if ( isset( $_SESSION['flp_avs'] ) ) {
			unset ($_SESSION['flp_avs']);
		}
		if ( isset( $_SESSION['flp_cvv'] ) ) {
			unset ($_SESSION['flp_cvv']);
		}

		$request = $this->post( 'https://api.fraudlabspro.com/v2/order/screen', $queries );

		// Give up fraud check if having network issue
		if ( ( $response = json_decode( $request ) ) === null ) {
			$this->write_debug_log( 'FraudLabs Pro validation has been skipped for order ' . $this->order->get_id() . '.' );
			$this->write_debug_log( 'Error for order ' . $this->order->get_id() . ': ' . $request );
			$this->order->add_order_note( __( 'FraudLabs Pro validation has been skipped. ' . $request, $this->namespace ) );

			// Save the static data to prevent duplicate checking
			$staticData = [
				'order_id'			=> $this->order->get_id(),
				'ip_address'		=> ( filter_var( $this->test_ip, FILTER_VALIDATE_IP ) ) ? $this->test_ip : $client_ip,
				'fraudlabspro_id'	=> '',
				'api_key'			=> $this->api_key,
			];

			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_fraudlabspro', $staticData );
			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 101 - add_post_meta function failed.' );
			}

			$table_name = $this->create_flpwc_table();
			$add_post_meta_result_hpos = $this->add_flpwc_data($table_name, $this->order->get_id(), '_fraudlabspro', $staticData);
			if ( ! $add_post_meta_result_hpos ) {
				$this->write_debug_log( 'ERROR 101 - add_post_meta HPOS failed.' );
			}

			return;
		}

		// Make sure response is an object
		if ( ! is_object( $response ) ) {
			$this->write_debug_log( 'FraudLabs Pro validation has been skipped for order ' . $this->order->get_id() . ' due to network issue.' );
			$this->order->add_order_note( __( 'FraudLabs Pro validation has been skipped due to network issues.', $this->namespace ) );

			// Save the static data to prevent duplicate checking
			$staticData = [
				'order_id'			=> $this->order->get_id(),
				'ip_address'		=> ( filter_var( $this->test_ip, FILTER_VALIDATE_IP ) ) ? $this->test_ip : $client_ip,
				'fraudlabspro_id'	=> '',
				'api_key'			=> $this->api_key,
			];

			$add_post_meta_result = add_post_meta( $this->order->get_id(), '_fraudlabspro', $staticData );
			if ( ! $add_post_meta_result ) {
				$this->write_debug_log( 'ERROR 102 - add_post_meta function failed.' );
			}

			$table_name = $this->create_flpwc_table();
			$add_post_meta_result_hpos = $this->add_flpwc_data($table_name, $this->order->get_id(), '_fraudlabspro', $staticData);
			if ( ! $add_post_meta_result_hpos ) {
				$this->write_debug_log( 'ERROR 102 - add_post_meta HPOS failed.' );
			}

			return;
		}

		// Save fraud check result
		$flpResult = [
			'order_id'						=> $this->order->get_id(),
			'is_country_match'				=> ($response->billing_address->is_ip_country_match) ? 'Y' : 'N',
			'is_high_risk_country'			=> '',
			'distance_in_km'				=> $response->billing_address->ip_distance_in_km,
			'distance_in_mile'				=> $response->billing_address->ip_distance_in_mile,
			'ip_address'					=> ( filter_var( $this->test_ip, FILTER_VALIDATE_IP ) ) ? $this->test_ip : $client_ip,
			'ip_country'					=> $response->ip_geolocation->country_code,
			'ip_region'						=> $response->ip_geolocation->region,
			'ip_city'						=> $response->ip_geolocation->city,
			'ip_continent'					=> $response->ip_geolocation->continent,
			'ip_latitude'					=> $response->ip_geolocation->latitude,
			'ip_longitude'					=> $response->ip_geolocation->longitude,
			'ip_timezone'					=> $response->ip_geolocation->timezone,
			'ip_elevation'					=> $response->ip_geolocation->elevation,
			'ip_domain'						=> $response->ip_geolocation->domain,
			'ip_mobile_mnc'					=> $response->ip_geolocation->mobile_mnc,
			'ip_mobile_mcc'					=> $response->ip_geolocation->mobile_mcc,
			'ip_mobile_brand'				=> $response->ip_geolocation->mobile_brand,
			'ip_netspeed'					=> $response->ip_geolocation->netspeed,
			'ip_isp_name'					=> $response->ip_geolocation->isp_name,
			'ip_usage_type'					=> is_array($response->ip_geolocation->usage_type) ? implode(', ', $response->ip_geolocation->usage_type) : $response->ip_geolocation->usage_type,
			'is_free_email'					=> ($response->email_address->is_free) ? 'Y' : 'N',
			'is_new_domain_name'			=> ($response->email_address->is_new_domain_name) ? 'Y' : 'N',
			'is_proxy_ip_address'			=> ($response->ip_geolocation->is_proxy) ? 'Y' : 'N',
			'is_bin_found'					=> ($response->credit_card->is_bin_exist) ? 'Y' : 'N',
			'is_bin_country_match'			=> ($response->credit_card->is_bin_country_match) ? 'Y' : 'N',
			'is_bin_name_match'				=> '',
			'is_bin_phone_match'			=> '',
			'is_bin_prepaid'				=> ($response->credit_card->is_prepaid) ? 'Y' : 'N',
			'is_address_ship_forward'		=> ($response->shipping_address->is_address_ship_forward) ? 'Y' : 'N',
			'is_bill_ship_city_match'		=> ($response->shipping_address->is_bill_city_match) ? 'Y' : 'N',
			'is_bill_ship_state_match'		=> ($response->shipping_address->is_bill_state_match) ? 'Y' : 'N',
			'is_bill_ship_country_match'	=> ($response->shipping_address->is_bill_country_match) ? 'Y' : 'N',
			'is_bill_ship_postal_match'		=> ($response->shipping_address->is_bill_postcode_match) ? 'Y' : 'N',
			'is_ip_blacklist'				=> ($response->ip_geolocation->is_in_blacklist) ? 'Y' : 'N',
			'is_email_blacklist'			=> ($response->email_address->is_in_blacklist) ? 'Y' : 'N',
			'is_credit_card_blacklist'		=> ($response->credit_card->is_in_blacklist) ? 'Y' : 'N',
			'is_device_blacklist'			=> ($response->device->is_in_blacklist) ? 'Y' : 'N',
			'is_user_blacklist'				=> ($response->username->is_in_blacklist) ? 'Y' : 'N',
			'is_phone_verified'				=> 'No',
			'fraudlabspro_score'			=> $response->fraudlabspro_score,
			'fraudlabspro_distribution'		=> '',
			'fraudlabspro_status'			=> $response->fraudlabspro_status,
			'fraudlabspro_id'				=> $response->fraudlabspro_id,
			'fraudlabspro_error_code'		=> ($response->error->error_code) ?? '',
			'fraudlabspro_message'			=> ($response->error->error_message) ?? '',
			'fraudlabspro_credits'			=> $response->remaining_credits,
			'fraudlabspro_rules'			=> is_array($response->fraudlabspro_rules) ? implode(', ', $response->fraudlabspro_rules) : $response->fraudlabspro_rules,
			'flp_callback_nonce'			=> $flpCallbackNonce,
			'api_key'						=> $this->api_key,
		];

		$add_post_meta_result = add_post_meta( $this->order->get_id(), '_fraudlabspro', $flpResult );
		if ( ! $add_post_meta_result ) {
			$this->write_debug_log( 'ERROR 103 - add_post_meta function failed.' );
		}

		$table_name = $this->create_flpwc_table();
		$add_post_meta_result_hpos = $this->add_flpwc_data($table_name, $this->order->get_id(), '_fraudlabspro', $flpResult);
		if ( ! $add_post_meta_result_hpos ) {
			$this->write_debug_log( 'ERROR 103 - add_post_meta HPOS failed.' );
		}

		$flpErrorMsg = ($response->error->error_message) ?? '';
		if ( strpos( $flpErrorMsg, 'SYSTEM DATABASE ERROR' ) !== false ) {
			$this->order->add_order_note( __( 'An error has occurred in the FraudLabs Pro system database.', $this->namespace ) );
		}

		$this->order->add_order_note( __( 'FraudLabs Pro Status: ' . $response->fraudlabspro_status . '. Transaction ID: ' . $response->fraudlabspro_id, $this->namespace ) );
		$this->write_debug_log( 'FraudLabs Pro validation has been completed for Order ' . $this->order->get_id() . '. Status: ' . $response->fraudlabspro_status . ', Transaction ID: ' . $response->fraudlabspro_id );

		if ( strpos( $flpErrorMsg, 'SYSTEM DATABASE ERROR' ) !== false ) {
			if ( $this->db_err_status && $this->db_err_status != $this->order->get_status() ) {
				$this->order->update_status( $this->db_err_status, __( '', $this->namespace ) );
			}
		}
		elseif ( ($response->fraudlabspro_status == 'REJECT') && ($newStatus != 'failed') ) {
			if ( $this->reject_status && $this->reject_status != $this->order->get_status() ) {
				$this->order->update_status( $this->reject_status, __( '', $this->namespace ) );
			}
		}
		elseif ( ($response->fraudlabspro_status == 'REVIEW') && ($newStatus != 'failed') ) {
			if ( $this->review_status && ($this->review_status != $this->order->get_status()) ) {
				$this->order->update_status( $this->review_status, __( '', $this->namespace ) );
			}
		}
		elseif ( ($response->fraudlabspro_status == 'APPROVE') && ($newStatus != 'failed') ) {
			if ( $this->approve_status && $this->approve_status != $this->order->get_status() && $this->order->get_status() != 'wc-completed' ) {
				$this->order->update_status( $this->approve_status, __( '', $this->namespace ) );
			}
		}

		if ( ( $this->notification_approve == 'yes' && $response->fraudlabspro_status == 'APPROVE' ) || ( $this->notification_review == 'yes' && $response->fraudlabspro_status == 'REVIEW' ) || ( $this->notification_reject == 'yes' && $response->fraudlabspro_status == 'REJECT' ) ) {
			$first_name = $this->order->get_billing_first_name();
			$last_name = $this->order->get_billing_last_name();

			// Use zaptrigger API to get zap information
			$request = wp_remote_get( 'https://api.fraudlabspro.com/v2/zaptrigger?' . http_build_query( array(
				'key'		=> $this->api_key,
				'format'	=> 'json'
			) ) );

			if ( ! is_wp_error( $request ) ) {
				// Get the HTTP response
				$zap_trigger = json_decode( wp_remote_retrieve_body( $request ) );

				if ( is_object( $zap_trigger ) ) {
					$target_url = $zap_trigger->target_url;
				}
			}

			if ( ! empty( $target_url ) ) {
				$zapresponse = $this->http($target_url, [
					'id'			=> $response->fraudlabspro_id,
					'date_created'	=> gmdate('Y-m-d H:i:s'),
					'flp_status'	=> $response->fraudlabspro_status,
					'full_name'		=> $first_name . ' ' . $last_name,
					'email'			=> $this->order->get_billing_email(),
					'order_id'		=> $this->order->get_id(),
				]);
				$zapdata = json_decode($zapresponse);
				if ( is_object( $zapdata ) ) {
					if ( $zapdata->status == 'success' ) {
						$this->write_debug_log( 'Hooks sent successful.' );
					} else {
						$this->write_debug_log( 'Hooks sent failed.' );
					}
				} else {
					$this->write_debug_log( 'Failed in sending hook to Zapier.' );
				}
			} else {
				$this->write_debug_log( 'Zapier target_url not found.' );
			}
		}

		if ( $response->fraudlabspro_status == 'REJECT' ) {
			return false;
		}

		return true;
	}


	/**
	 * Includes required scripts and styles.
	 */
	public function admin_enqueue_scripts( $hook ) {
		if (current_user_can('administrator')) {
			wp_enqueue_script( 'fraudlabspro_woocommerce_admin_script', plugins_url( '/assets/js/script.js', WC_FLP_DIR ), array( 'jquery' ), '1.0', true );
		}

		wp_enqueue_style( 'fraudlabs_pro_admin_menu_styles', untrailingslashit( plugins_url( '/', WC_FLP_DIR ) ) . '/assets/css/style.css', array() );

		if ( $hook == 'plugins.php' ) {
			// Add in required libraries for feedback modal
			wp_enqueue_script('jquery-ui-dialog');
			wp_enqueue_style('wp-jquery-ui-dialog');

			wp_enqueue_script( 'fraudlabs_pro_woocommerce_admin_script', plugins_url( '/assets/js/feedback.js', WC_FLP_DIR ), array( 'jquery' ), true );
		}
		elseif ( $hook != 'toplevel_page_woocommerce-fraudlabs-pro ' ) {
			return;
		}
	}


	/**
	 * Add wizard form upon plugin activation in dashboard.
	 */
	public function admin_notices() {
		$current_screen = get_current_screen();

		if ( 'plugins' == $current_screen->parent_base ) {
			$setup_fraudlabs_pro = false;

			if ($this->api_key != '') {
				$setup_fraudlabs_pro = true;
			}

			if (!$setup_fraudlabs_pro) {
				echo '
				<div id="modal-step-1" class="fraudlabs-pro-modal" style="display:block">
					<div class="fraudlabs-pro-modal-content" style="width:400px;height:320px">
					<script type="text/javascript" src="https://use.fontawesome.com/30858dc40a.js"></script>
					<button type="button" class="dismiss-button"><i class="fa fa-times-circle"></i></button>
						<div align="center">
							<h1>Set Up FraudLabs Pro API Key</h1>
							<table class="setup" width="200">
								<tr>
									<td align="center">
										<img src="' . plugins_url('/assets/images/step-1-selected.png', WC_FLP_DIR) . '" width="32" height="32" align="center"><br>
										<strong>Step 1</strong>
									</td>
									<td align="center">
										<img src="' . plugins_url('/assets/images/step-2.png', WC_FLP_DIR) . '" width="32" height="32" align="center"><br>
										Step 2
									</td>
								</tr>
							</table>
							<div class="line"></div>
						</div>

						<form>
							<p class="description">
								Thank you for choosing FraudLabs Pro to protect your WooCommerce store from payment fraud.
							</p>
							<p>
								<label>Enter FraudLabs Pro API Key</label>
								<input type="text" id="setup_flp_key" class="regular-text code" maxlength="64" style="width:100%">
							</p>
							<p class="description">
								Don\'t have an account yet? You can sign up for a free API key at <a href="https://www.fraudlabspro.com/subscribe?id=1#woocommerce-pltwzd" target="_blank">FraudLabs Pro</a>.
							</p>
						</form>
						<p style="text-align:right;margin-top:15px">
							<button id="btn-to-step-2" class="button button-primary" disabled>Next &raquo;</button>
						</p>
						<br>
					</div>
				</div>
				<div id="modal-step-2" class="fraudlabs-pro-modal">
					<div class="fraudlabs-pro-modal-content" style="width:400px;height:320px">
						<div align="center">
							<h1>Validate FraudLabs Pro API Key</h1>
							<table class="setup" width="200">
								<tr>
									<td align="center">
										<img src="' . plugins_url('/assets/images/step-1.png', WC_FLP_DIR) . '" width="32" height="32" align="center"><br>
										Step 1
									</td>
									<td align="center">
										<img src="' . plugins_url('/assets/images/step-2-selected.png', WC_FLP_DIR) . '" width="32" height="32" align="center"><br>
										<strong>Step 2</strong>
									</td>
								</tr>
							</table>
							<div class="line"></div>
						</div>

						<form style="height:140px">
							<p id="fraudlabs_pro_key_validation_status"></p>
						</form>
						<p style="text-align:right;margin-top:30px">
							<button id="btn-to-step-1" class="button button-primary" disabled>&laquo; Previous</button>
							<button id="btn-to-step-3" class="button button-primary" disabled>Next &raquo;</button>
						</p>
					</div>
				</div>
				<div id="modal-step-3" class="fraudlabs-pro-modal">
					<div class="fraudlabs-pro-modal-content" style="width:400px;height:320px">
						<div align="center">
							<img src="' . plugins_url('/assets/images/step-end.png', WC_FLP_DIR) . '" width="300" height="225" align="center"><br>
							The fraud prevention solution has been enabled. Please review and update the order status, for the Approve, Review and Reject action, at the Settings page.
						</div>
						<p style="text-align:right;margin-top:15px">
							<button class="button button-primary" onclick="window.location.href=\'' . admin_url('admin.php?page=woocommerce-fraudlabs-pro') . '\';">Done</button>
						</p>
					</div>
				</div>
				<input type="hidden" id="validate_api_key_nonce" value="' . wp_create_nonce('validate-api-key') . '">';
			}
		}
	}


	/**
	 * Admin menu.
	 */
	public function admin_menu() {
		add_menu_page( 'FraudLabs Pro', 'FraudLabs Pro', 'manage_options', 'woocommerce-fraudlabs-pro', array( $this, 'settings_page' ), 'dashicons-admin-fraudlabs-pro', 30 );
	}


	/**
	 * Settings page.
	 */
	public function settings_page() {
		if (!current_user_can('administrator')) {
			$this->write_debug_log( 'Not logged in as administrator. Settings page will not be shown.' );
			return;
		}

		$form_status = '';

		wp_enqueue_script( 'jquery' );

		$tab = (isset($_GET['tab'])) ? sanitize_text_field($_GET['tab']) : 'settings';
		switch ($tab) {
			case 'order':
			$form_status = '';

			$wc_order_statuses = wc_get_order_statuses();
			$wc_order_statuses[''] = 'No Status Change';
			$validation_sequence = ( isset( $_POST['validation_sequence'] ) ) ? sanitize_text_field($_POST['validation_sequence']) : $this->get_setting( 'validation_sequence' );
			$enable_wc_fraudlabspro_advanced_velocity = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro_advanced_velocity'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro_advanced_velocity'] ) ) ) ? 'no' : $this->get_setting( 'flp_advanced_velocity' ) );
			$approve_status = ( isset( $_POST['approve_status'] ) ) ? sanitize_text_field($_POST['approve_status']) : $this->get_setting( 'approve_status' );
			$review_status = ( isset( $_POST['review_status'] ) ) ? sanitize_text_field($_POST['review_status']) : $this->get_setting( 'review_status' );
			$reject_status = ( isset( $_POST['reject_status'] ) ) ? sanitize_text_field($_POST['reject_status']) : $this->get_setting( 'reject_status' );
			$db_err_status = ( isset( $_POST['db_err_status'] ) ) ? sanitize_text_field($_POST['db_err_status']) : $this->get_setting( 'db_err_status' );
			$enable_wc_fraudlabspro_auto_change_status = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro_auto_change_status'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro_auto_change_status'] ) ) ) ? 'no' : $this->get_setting( 'change_status_auto' ) );
			$enable_wc_fraudlabspro_reject_fail_status = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro_reject_fail_status'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro_reject_fail_status'] ) ) ) ? 'no' : $this->get_setting( 'reject_failed_order' ) );
			$fraud_message = ( isset( $_POST['fraud_message'] ) ) ? sanitize_text_field($_POST['fraud_message']) : $this->get_setting( 'fraud_message' );
			$real_ip_detect = ( isset( $_POST['real_ip_detect'] ) ) ? sanitize_text_field($_POST['real_ip_detect']) : $this->get_setting( 'real_ip_detect' );
			$test_ip = ( isset( $_POST['test_ip'] ) ) ? sanitize_text_field(esc_attr($_POST['test_ip'])) : $this->get_setting( 'test_ip' );
			$enable_wc_fraudlabspro_report_expand = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro_report_expand'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro_report_expand'] ) ) ) ? 'no' : $this->get_setting( 'expand_report' ) );
			$enable_wc_fraudlabspro_debug_log = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro_debug_log'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro_debug_log'] ) ) ) ? 'no' : $this->get_setting( 'debug_log' ) );
			$wc_fraudlabspro_debug_log_path = ( isset( $_POST['wc_fraudlabspro_debug_log_path'] ) ) ? sanitize_text_field(esc_url($_POST['wc_fraudlabspro_debug_log_path'])) : $this->get_setting( 'debug_log_path' );

			if ( isset( $_POST['submit'] ) ) {
				check_admin_referer('save-settings', '_wpnonce_save_settings');

				if ( !empty( $test_ip ) && !filter_var( $test_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$test_ip = '';
					$form_status .= '
					<div id="message" class="error">
						<p><strong>ERROR</strong>: Please enter a valid IP address.</p>
					</div>';
				}

				if (!empty($wc_fraudlabspro_debug_log_path)) {
					if (!is_writable(ABSPATH . $wc_fraudlabspro_debug_log_path)) {
						$wc_fraudlabspro_debug_log_path = '';
						$form_status .= '
						<div id="message" class="error">
							<p><strong>ERROR</strong>: Please enter a valid Debug Log Path.</p>
						</div>';
					}
				}

				if ( empty( $form_status ) ) {
					$this->update_setting( 'validation_sequence', $validation_sequence );
					$this->update_setting( 'flp_advanced_velocity', $enable_wc_fraudlabspro_advanced_velocity );
					$this->update_setting( 'approve_status', $approve_status );
					$this->update_setting( 'review_status', $review_status );
					$this->update_setting( 'reject_status', $reject_status );
					$this->update_setting( 'db_err_status', $db_err_status );
					$this->update_setting( 'change_status_auto', $enable_wc_fraudlabspro_auto_change_status );
					$this->update_setting( 'reject_failed_order', $enable_wc_fraudlabspro_reject_fail_status );
					$this->update_setting( 'fraud_message', $fraud_message );
					$this->update_setting( 'real_ip_detect', $real_ip_detect );
					$this->update_setting( 'test_ip', $test_ip );
					$this->update_setting( 'expand_report', $enable_wc_fraudlabspro_report_expand );
					$this->update_setting( 'debug_log', $enable_wc_fraudlabspro_debug_log );
					$this->update_setting( 'debug_log_path', $wc_fraudlabspro_debug_log_path );

					$form_status = '
					<div id="message" class="updated">
						<p>Changes saved.</p>
					</div>';
				}
			}

			echo '
			<script>
				jQuery("#approve_status").change(function (e) {
					if ((jQuery("#validation_sequence").val() == "before") && (jQuery("#approve_status").val() == "wc-completed")) {
						if (!confirm("You have set to change the Approve Status to \"Completed\", and this will straightaway complete the order flow without sending to the payment gateway. Do you still want to continue?")) {
							jQuery("#approve_status").val("' . $approve_status . '");
						} else {
							e.preventDefault();
						}
					}
				});

				jQuery("#validation_sequence").change(function (e) {
					if ((jQuery("#validation_sequence").val() == "before") && (jQuery("#approve_status").val() == "wc-completed")) {
						if (!confirm("You have set to change the Approve Status to \"Completed\", and this will straightaway complete the order flow without sending to the payment gateway. Do you still want to continue?")) {
							jQuery("#validation_sequence").val("' . $validation_sequence . '");
						} else {
							e.preventDefault();
						}
					}
				});
			</script>';

			echo '
			<div class="wrap">
				<h1>FraudLabs Pro for WooCommerce</h1>
				' . $form_status . '
				<form method="post" novalidate="novalidate">
				' . wp_nonce_field('save-settings', '_wpnonce_save_settings') . '
					<div id="message" class="notice" style="border-left-color:#57b1f9;">
						<h3 style="margin-bottom:15px;">Quick Start Guide</h3>
						<p style="font-size:13px;margin-bottom:5px;"><b>👋 Just getting started?</b> &nbsp;Follow our <a href="https://www.fraudlabspro.com/supported-platforms/woocommerce" target="_blank">setup guide</a> to install and configure the plugin.</p>
						<p style="font-size:13px;margin-bottom:15px;"><b>🔍 Ready to explore?</b> &nbsp;Check out our <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-test-fraudlabs-pro-plugin-on-woocomerce/" target="_blank">usage guide</a> on how to use and test the plugin.</p>
					</div>
					' . $this->admin_tabs() . '
					<div class="fraudlabspro-woocommece-tab-content">
						<table class="form-table">
							<tr>
								<td scope="row" colspan="2">
									<h2>Validation Settings</h2><hr />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="validation_sequence">Validation Trigger Point</label>
								</th>
								<td>
									<select name="validation_sequence" id="validation_sequence">
										<option value="after"' . ( ( $validation_sequence == 'after' ) ? ' selected' : '' ) . '> After submit order to payment gateway</option>
										<option value="before"' . ( ( $validation_sequence == 'before' ) ? ' selected' : '' ) . '> Before submit order to payment gateway</option>
									</select>
									<p class="description">
										You can choose to trigger the fraud validation either before or after the payment process. Please visit the <a href="https://www.fraudlabspro.com/resources/tutorials/what-is-validation-order-on-fraudlabspro-woocommerce/" target="_blank">Validation Trigger Point</a> article to learn more. <br /><br />
										<strong>Important Note: </strong> For the “Before submit order to payment gateway” option, the system will cancel the payment processing if it was rejected by FraudLabs Pro.
									</p>
								</td>
							</tr>

							<tr id="enable_wc_advanced_velocity_tr">
								<th scope="row">
									<label for="enable_wc_fraudlabspro_advanced_velocity">Advanced Velocity Screening</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro_advanced_velocity" id="enable_wc_advanced_velocity"' . ( ( $enable_wc_fraudlabspro_advanced_velocity == 'yes' ) ? ' checked' : ( ( $validation_sequence == 'after' ) ? ' disabled' : '' ) ) . '>
									<p class="description">
										Enable advanced velocity screening that might consumes extra FraudLabs Pro credits. This option only available for <strong>Validation Trigger Point Before submit order to payment gateway</strong>. Please visit the <a href="https://www.fraudlabspro.com/resources/tutorials/what-is-advanced-velocity-screening-in-fraudlabs-pro-for-woocommerce-plugin/" target="_blank">Advanced Velocity Screening</a> article to learn more.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="manage_rules">Validation Rules</label>
								</th>
								<td>
									<input type="button" name="button" id="button-merchant-rule" class="button button-primary" value="Login to Merchant Area Rule Page" style="margin-top:5px; margin-bottom:5px;"/>
									<p class="description">
										You will need to login to merchant area to view and configure the validation rules.
									</p>
								</td>
							</tr>
								<tr>
								<td scope="row" colspan="2">
									<h2>WooCommerce Order Settings</h2><hr />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="approve_status">Approve Status</label>
								</th>
								<td>
									<select name="approve_status" id="approve_status">
			';

			foreach ( $wc_order_statuses as $key => $status ) {
				echo '
										<option value="' . $key . '"' . ( ( $approve_status == $key ) ? ' selected' : '' ) . '> ' . esc_html($status) . '</option>';
			}

			echo '
									</select>
									<p class="description">
										Change to this order status if the order has been approved either by FraudLabs Pro, or via manual action.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="review_status">Review Status</label>
								</th>
								<td>
									<select name="review_status" id="review_status">';

			foreach ( $wc_order_statuses as $key => $status ) {
				echo '
										<option value="' . $key . '"' . ( ( $review_status == $key ) ? ' selected' : '' ) . '> ' . $status . '</option>';
			}

			echo '
									</select>
									<p class="description">
										Change to this order status if the order has been marked as <strong>REVIEW</strong> by FraudLabs Pro.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="reject_status">Reject Status</label>
								</th>
								<td>
									<select name="reject_status" id="reject_status">
			';

			foreach ( $wc_order_statuses as $key => $status ) {
				echo '
										<option value="' . $key . '"' . ( ( $reject_status == $key ) ? ' selected' : '' ) . '> ' . $status . '</option>';
			}

			echo '
									</select>
									<p class="description">
										Change to this order status if the order has been rejected either by FraudLabs Pro, or via manual action.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="db_err_status">Internal Error Status</label>
								</th>
								<td>
									<select name="db_err_status" id="db_err_status">';

			foreach ( $wc_order_statuses as $key => $status ) {
				echo '
										<option value="' . $key . '"' . ( ( $db_err_status == $key ) ? ' selected' : '' ) . '> ' . $status . '</option>';
			}

			echo '
									</select>
									<p class="description">
										Change to this order status if FraudLabs Pro fails to perform the fraud validation due to internal errors.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="enable_wc_fraudlabspro_auto_change_status">Sync WooCommerce Completed/Cancelled Status</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro_auto_change_status" id="enable_wc_fraudlabspro_auto_change_status"' . ( ( $enable_wc_fraudlabspro_auto_change_status == 'yes' ) ? ' checked' : '' ) . '>
									<p class="description">
										Automatically synchronize the WooCommerce Completed order with the FraudLabs Pro Approve status and WooCommerce Cancelled order with the FraudLabs Pro Reject status. Please visit this <a href="https://www.fraudlabspro.com/resources/tutorials/what-is-automated-order-approval-rejection-in-fraudlabs-pro-for-woocommerce-plugin/" target="_blank">article</a> for the detailed explanation.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="enable_wc_fraudlabspro_reject_fail_status">Reject WooCommerce Failed Order</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro_reject_fail_status" id="enable_wc_fraudlabspro_reject_fail_status"' . ( ( $enable_wc_fraudlabspro_reject_fail_status == 'yes' ) ? ' checked' : '' ) . '>
									<p class="description">
										Automatically reject orders in a Failed status to prevent further processing or fulfillment of potentially invalid or declined transactions.
									</p>
								</td>
							</tr>

							<tr id="fraud_message_tr">
								<th scope="row">
									<label for="fraud_message">Fraud Message</label>
								</th>
								<td>
									<textarea name="fraud_message" id="fraud_message" class="large-text" rows="3" ' . ( ( $validation_sequence == 'after' ) ? ' disabled' : '' ) . '>' . $fraud_message . '</textarea>
									<p class="description">
										Display this message to customer if the order has been rejected by FraudLabs Pro. Please visit the <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-custom-the-fraud-message-for-woocommerce-display/" target="_blank">Fraud Message</a> article to learn more. This option only available for <strong>Validation Trigger Point Before submit order to payment gateway</strong>.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="real_ip_detection">Real IP Detection</label>
								</th>
								<td>
									<select name="real_ip_detect" id="real_ip_detect">
										<option value="no_override"' . ( ( $real_ip_detect == 'no_override' ) ? ' selected' : '' ) . '>No Override</option>
										<option value="remote_addr"' . ( ( $real_ip_detect == 'remote_addr' ) ? ' selected' : '' ) . '>REMOTE_ADDR</option>
										<option value="http_cf_connecting_ip"' . ( ( $real_ip_detect == 'http_cf_connecting_ip' ) ? ' selected' : '' ) . '>HTTP_CF_CONNECTING_IP</option>
										<option value="http_client_ip"' . ( ( $real_ip_detect == 'http_client_ip' ) ? ' selected' : '' ) . '>HTTP_CLIENT_IP</option>
										<option value="http_forwarded"' . ( ( $real_ip_detect == 'http_forwarded' ) ? ' selected' : '' ) . '>HTTP_FORWARDED</option>
										<option value="http_incap_client_ip"' . ( ( $real_ip_detect == 'http_incap_client_ip' ) ? ' selected' : '' ) . '>HTTP_INCAP_CLIENT_IP</option>
										<option value="http_x_forwarded"' . ( ( $real_ip_detect == 'http_x_forwarded' ) ? ' selected' : '' ) . '>HTTP_X_FORWARDED</option>
										<option value="http_x_forwarded_for"' . ( ( $real_ip_detect == 'http_x_forwarded_for' ) ? ' selected' : '' ) . '>HTTP_X_FORWARDED_FOR</option>
										<option value="http_x_real_ip"' . ( ( $real_ip_detect == 'http_x_real_ip' ) ? ' selected' : '' ) . '>HTTP_X_REAL_IP</option>
										<option value="http_x_sucuri_clientip"' . ( ( $real_ip_detect == 'http_x_sucuri_clientip' ) ? ' selected' : '' ) . '>HTTP_X_SUCURI_CLIENTIP</option>
									</select>
									<p class="description">
										If your WooCommerce is behind a reverse proxy or load balancer, use this option to choose the correct header for the real visitor IP detected by FraudLabs Pro.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="test_ip">Test IP</label>
								</th>
								<td>
									<input type="text" name="test_ip" id="test_ip" maxlength="15" value="' . $test_ip . '" class="regular-text code" />
									<p class="description">
										Simulate visitor IP address. Leave this field blank for live mode.
									</p>
								</td>
							</tr>
							<tr>
								<td scope="row" colspan="2">
									<h2>Plugin Settings</h2><hr />
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="enable_wc_fraudlabspro_report_expand">Expanded View</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro_report_expand" id="enable_wc_fraudlabspro_report_expand"' . ( ( $enable_wc_fraudlabspro_report_expand == 'yes' ) ? ' checked' : '' ) . '>
									<p class="description">Display the FraudLabs Pro report in expanded mode on Order Details page. By default, the report is in expanded mode.</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="enable_wc_fraudlabspro_debug_log">Enable Debug Log</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro_debug_log" id="enable_wc_fraudlabspro_debug_log"' . ( ( $enable_wc_fraudlabspro_debug_log == 'yes' ) ? ' checked' : '' ) . '>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="wc_fraudlabspro_debug_log_path">Debug Log Path</label>
								</th>
								<td>
									<input type="text" name="wc_fraudlabspro_debug_log_path" id="wc_fraudlabspro_debug_log_path" style="width:50% !important;" value="' . $wc_fraudlabspro_debug_log_path . '" class="regular-text"' . ( ( $enable_wc_fraudlabspro_debug_log != 'yes' ) ? ' disabled' : '' ) . ' placeholder="/wp-content/plugins/fraudlabs-pro-for-woocommerce/" />
									<p class="description">
										The path to store the debug log file. Leave this field blank for default log path which located in the fraudlabs-pro-for-woocommerce plugin folder.
									</p>
								</td>
							</tr>';

					$filePath = ( ( get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') != '' ) ? ABSPATH . get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') . 'debug.log' : ABSPATH . '/wp-content/plugins/fraudlabs-pro-for-woocommerce/debug.log' );
					$file = ( ( get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') != '' ) ? get_site_url() . get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') . 'debug.log' : get_site_url() . '/wp-content/plugins/fraudlabs-pro-for-woocommerce/debug.log' );
					if (is_writable($filePath)) {
						$file_headers = @get_headers($file);
						if ( strpos( $file_headers[0], "OK" ) !== false ) {
						echo '
								<tr>
									<th scope="row">
									</th>
									<td>
										<a href="' . $file . '" download="debug.log"><input type="button" name="button" id="button-download-log" class="button" value="Download Debug Log File" /></a>
									</td>
								</tr>';
						}
					}
				echo '
							</table>
							<p class="submit">
								<input type="hidden" name="form_order_submitted" value="" />
								<input style="padding:3px 16px; font-size:13px;" type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes" />
							</p>
						</div>
					</form>
				</div>
			';
			break;

			case 'notification':
			$notification_on_approve = ( isset( $_POST['submit'] ) && isset( $_POST['notification_on_approve'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['notification_on_approve'] ) ) ) ? 'no' : $this->get_setting( 'notification_approve' ) );
			$notification_on_review = ( isset( $_POST['submit'] ) && isset( $_POST['notification_on_review'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['notification_on_review'] ) ) ) ? 'no' : $this->get_setting( 'notification_review' ) );
			$notification_on_reject = ( isset( $_POST['submit'] ) && isset( $_POST['notification_on_reject'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['notification_on_reject'] ) ) ) ? 'no' : $this->get_setting( 'notification_reject' ) );

			$form_status = '';

			if ( isset( $_POST['submit'] ) ) {
				check_admin_referer('save-settings', '_wpnonce_save_settings');

				if ( empty( $form_status ) ) {
					$this->update_setting( 'notification_approve', $notification_on_approve );
					$this->update_setting( 'notification_review', $notification_on_review );
					$this->update_setting( 'notification_reject', $notification_on_reject );

					$form_status = '
					<div id="message" class="updated">
						<p>Changes saved.</p>
					</div>';
				}
			}

			echo '
			<div class="wrap">
				<h1>FraudLabs Pro for WooCommerce</h1>
				' . $form_status . '
				<form method="post" novalidate="novalidate">
				' . wp_nonce_field('save-settings', '_wpnonce_save_settings') . '
					<div id="message" class="notice" style="border-left-color:#57b1f9;">
						<h3 style="margin-bottom:15px;">Quick Start Guide</h3>
						<p style="font-size:13px;margin-bottom:5px;"><b>👋 Just getting started?</b> &nbsp;Follow our <a href="https://www.fraudlabspro.com/supported-platforms/woocommerce" target="_blank">setup guide</a> to install and configure the plugin.</p>
						<p style="font-size:13px;margin-bottom:15px;"><b>🔍 Ready to explore?</b> &nbsp;Check out our <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-test-fraudlabs-pro-plugin-on-woocomerce/" target="_blank">usage guide</a> on how to use and test the plugin.</p>
					</div>
					' . $this->admin_tabs() . '
					<div class="fraudlabspro-woocommece-tab-content">
						<table class="form-table">
							<tr>
								<td scope="row" colspan="2">
									<h2>Notification</h2><hr />
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="notification_status">Email Notification</label>
								</th>
								<td>
									<p>
										Please login to <a href="https://www.fraudlabspro.com/merchant/setting" target="_blank">merchant area</a> to configure the email notification under the Settings page.
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="notification_status">Zapier Notification</label>
								</th>
								<td>
									<p>
										<input type="checkbox" name="notification_on_approve" id="notification_on_approve"' . ( ( $notification_on_approve == 'yes' ) ? ' checked' : '' ) . '> Approve Status
									</p>
									<p>
										<input type="checkbox" name="notification_on_review" id="notification_on_review"' . ( ( $notification_on_review == 'yes' ) ? ' checked' : '' ) . '> Review Status
									</p>
									<p>
										<input type="checkbox" name="notification_on_reject" id="notification_on_reject"' . ( ( $notification_on_reject == 'yes' ) ? ' checked' : '' ) . '> Reject Status
									</p>
									<p class="description">
										You can trigger notification, such as email sending, using the Zapier service. Please configure the integration in Zapier.com before enabling this option. You can visit the <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-enable-notification-using-zapier-in-woocommerce/" target="_blank">How to Enable Notification Using Zapier</a> article to learn more.
									</p>
								</td>
							</tr>
						</table>
						<p class="submit">
							<input type="hidden" name="form_notification_submitted" value="" />
							<input style="padding:3px 16px; font-size:13px;" type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes" />
						</p>
					</div>
				</form>
			</div>';
			break;

			case 'data':
			if ( isset( $_POST['purge'] ) ) {
				check_admin_referer('purge-data', '_wpnonce_purge_data');

				global $wpdb;
				$wpdb->query('DELETE FROM ' . $wpdb->prefix . 'postmeta WHERE meta_key LIKE "%fraudlabspro%"');
				$wpdb->query('TRUNCATE `' . $wpdb->prefix . 'fraudlabspro_wc`');
				$form_status = '
					<div id="message" class="updated">
						<p>All data have been deleted.</p>
					</div>';
			}
			echo '
			<div class="wrap">
				<h1>FraudLabs Pro for WooCommerce</h1>
				<div id="message" class="notice" style="border-left-color:#57b1f9;">
					<h3 style="margin-bottom:15px;">Quick Start Guide</h3>
					<p style="font-size:13px;margin-bottom:5px;"><b>👋 Just getting started?</b> &nbsp;Follow our <a href="https://www.fraudlabspro.com/supported-platforms/woocommerce" target="_blank">setup guide</a> to install and configure the plugin.</p>
					<p style="font-size:13px;margin-bottom:15px;"><b>🔍 Ready to explore?</b> &nbsp;Check out our <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-test-fraudlabs-pro-plugin-on-woocomerce/" target="_blank">usage guide</a> on how to use and test the plugin.</p>
				</div>
				' . $this->admin_tabs() . '
				<div class="fraudlabspro-woocommece-tab-content">
					<p>
						<form id="form-purge" method="post">
							<h2>Data Management</h2><hr />
							<input type="hidden" name="purge" value="true">
							<p>Remove <strong>all FraudLabs Pro data</strong> from your local storage (WordPress). Please note that this action is not reversible!</p>
							<input type="button" name="button" id="button-purge" class="button" style="background-color:red; color:white;" value="Delete All Data" />
							' . wp_nonce_field('purge-data', '_wpnonce_purge_data') . '
						</form>
					</p>
				</div>
			</div>
			';
			break;

			case 'settings':
			default:
			$plan_name = '';
			$plan_upgrade = '';
			$credit_display = '';
			$credit_warning = '';
			// Use plan API to get license information
			$request = wp_remote_get( 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query( array(
				'key'		=> $this->api_key,
				'format'	=> 'json'
			) ) );

			if ( ! is_wp_error( $request ) ) {
				// Get the HTTP response
				$response = json_decode( wp_remote_retrieve_body( $request ) );

				if ( is_object( $response ) ) {
					$plan_name = $response->plan_name;
					$credit_available = $response->query_limit - $response->query_limit_used;
					$next_renewal_date = $response->next_renewal_date;
					$plan_upgrade = (($plan_name != 'FraudLabs Pro Enterprise') ? '<input type="button" name="button" id="button-upgrade" class="button button-outline-primary" value="Explore More Plans" style="margin-left:20px;" />' : '');

					if (($plan_name == 'FraudLabs Pro Micro') && ($credit_available <= 100)){
						$credit_display = 'color:red;';
						$credit_warning = 'You are going to run out of credits, you should <a href="https://www.fraudlabspro.com/pricing" target="_blank">upgrade</a> now to avoid service disruptions.';
					} elseif ($credit_available <= 100) {
						$credit_display = 'color:red;';
						$credit_warning = '';
					} else {
						$credit_display = $credit_warning = '';
					}
				}
			}

			$form_status = '';

			$enable_wc_fraudlabspro = ( isset( $_POST['submit'] ) && isset( $_POST['enable_wc_fraudlabspro'] ) ) ? 'yes' : ( ( ( isset( $_POST['submit'] ) && !isset( $_POST['enable_wc_fraudlabspro'] ) ) ) ? 'no' : $this->get_setting( 'enabled' ) );
			$api_key = ( isset( $_POST['api_key'] ) ) ? sanitize_text_field(esc_attr($_POST['api_key'])) : $this->get_setting( 'api_key' );

			if ( isset( $_POST['submit'] ) ) {
				check_admin_referer('save-settings', '_wpnonce_save_settings');

				if ( !preg_match( '/^[0-9A-Z]{32}$/', $api_key ) ) {
					$api_key = '';
					$form_status .= '
					<div id="message" class="error">
						<p><strong>ERROR</strong>: Please enter a valid FraudLabs Pro API key.</p>
					</div>';
				}

				if ( empty( $form_status ) ) {
					$this->update_setting( 'enabled', $enable_wc_fraudlabspro );
					$this->update_setting( 'api_key', $api_key );

					if ( $api_key !== $this->api_key ) {
						// Use plan API to get license information
						$request = wp_remote_get( 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query( array(
							'key'		=> $api_key,
							'format'	=> 'json'
						) ) );

						if ( ! is_wp_error( $request ) ) {
							// Get the HTTP response
							$response = json_decode( wp_remote_retrieve_body( $request ) );

							if ( is_object( $response ) ) {
								$plan_name = $response->plan_name;
								$credit_available = $response->query_limit - $response->query_limit_used;
								$next_renewal_date = $response->next_renewal_date;

								if (($plan_name == 'FraudLabs Pro Micro') && ($credit_available <= 100)){
									$credit_display = 'color:red;';
									$credit_warning = 'You are going to run out of credits, you should <a href="https://www.fraudlabspro.com/pricing" target="_blank">upgrade</a> now to avoid service disruptions.';
								} elseif ($credit_available <= 100) {
									$credit_display = 'color:red;';
									$credit_warning = '';
								} else {
									$credit_display = $credit_warning = '';
								}
							}
						}
					}

					$form_status = '
					<div id="message" class="updated">
						<p>Changes saved.</p>
					</div>';
				}
			}

			echo '
			<script>
				jQuery(document).ready(function($) {
					$("#button-merchant").on("click", function(e) {
						window.open("https://www.fraudlabspro.com/merchant/login", "_blank");
					});

					$("#button-merchant-rule").on("click", function(e) {
						window.open("https://www.fraudlabspro.com/merchant/rule", "_blank");
					});

					$("#button-upgrade").on("click", function(e) {
						var plan_name = "' . $plan_name . '";
						switch (plan_name) {
							case "FraudLabs Pro Micro":
								window.open("https://www.fraudlabspro.com/subscribe?id=8", "_blank");
								break;

							case "FraudLabs Pro Mini":
								window.open("https://www.fraudlabspro.com/subscribe?id=2", "_blank");
								break;

							case "FraudLabs Pro Small":
								window.open("https://www.fraudlabspro.com/subscribe?id=3", "_blank");
								break;

							case "FraudLabs Pro Medium":
								window.open("https://www.fraudlabspro.com/subscribe?id=4", "_blank");
								break;

							case "FraudLabs Pro Large":
								window.open("https://www.fraudlabspro.com/subscribe?id=5", "_blank");
								break;

							default:
								window.open("https://www.fraudlabspro.com/pricing", "_blank");
								break;
						}
					});
				});
			</script>';

			echo '
			<div class="wrap">
				<h1>FraudLabs Pro for WooCommerce</h1>
				' . $form_status . '
				<form method="post" novalidate="novalidate">
				' . wp_nonce_field('save-settings', '_wpnonce_save_settings') . '
					<div id="message" class="notice" style="border-left-color:#57b1f9;">
						<h3 style="margin-bottom:15px;">Quick Start Guide</h3>
						<p style="font-size:13px;margin-bottom:5px;"><b>👋 Just getting started?</b> &nbsp;Follow our <a href="https://www.fraudlabspro.com/supported-platforms/woocommerce" target="_blank">setup guide</a> to install and configure the plugin.</p>
						<p style="font-size:13px;margin-bottom:15px;"><b>🔍 Ready to explore?</b> &nbsp;Check out our <a href="https://www.fraudlabspro.com/resources/tutorials/how-to-test-fraudlabs-pro-plugin-on-woocomerce/" target="_blank">usage guide</a> on how to use and test the plugin.</p>
					</div>
					' . $this->admin_tabs() . '
					<div class="fraudlabspro-woocommece-tab-content">
						<table class="form-table">
							<tr>
								<td scope="row" colspan="2">
									<h2>General Settings </h2><hr />
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="enable_wc_fraudlabspro">Enable FraudLabs Pro Validation</label>
								</th>
								<td>
									<input type="checkbox" name="enable_wc_fraudlabspro" id="enable_wc_fraudlabspro"' . ( ( $enable_wc_fraudlabspro == 'yes' ) ? ' checked' : '' ) . '>
								</td>
							</tr>
							<tr>
								<td scope="row" colspan="2">
									<h2>License Information</h2><hr />
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="plan_name">Plan Name</label>
								</th>
								<td>
									<p>' . esc_html($plan_name) . '</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="credit_available">Credit Available</label>
								</th>
								<td>
									<p style=' . $credit_display . '>' . number_format((int)$credit_available, false, false, ",") . '</p><p class="description"><strong>' . $credit_warning . '</strong></p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="next_renewal_date">Next Renewal Date</label>
								</th>
								<td>
									<p>' . esc_html($next_renewal_date) . '</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="api_key">API Key</label>
								</th>
								<td>
									<input type="text" name="api_key" id="api_key" maxlength="32" value="' . $api_key . '" class="regular-text code" />
									<p class="description">
										You can sign up for a free API key at <a href="https://www.fraudlabspro.com/subscribe?id=1#woocommerce-pltstg" target="_blank">FraudLabs Pro</a>.
									</p>
								</td>
							</tr>
						</table>

						<p style="margin-top:15px;font-size:14px;">To manage your account settings, view fraud reports, or adjust validation rules:</p>
						<a style="font-size:14px;" href="https://www.fraudlabspro.com/merchant/login" target="_blank">Login to Your Merchant Area >></a>

						<p style="font-size:14px;">To unlock more advanced features for fraud protection:</p>
						<a style="font-size:14px;" href="https://www.fraudlabspro.com/pricing" target="_blank">Explore More Plans >></a>

						<p style="margin-top:35px;">
							<input style="padding:3px 16px; font-size:13px;" type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes" />
						</p>
					</div>
				</form>
			</div>';
		}
	}

	/**
	 * Create admin tab.
	 */
	private function admin_tabs()
	{
		$disable_tabs = false;
		$tab = (isset($_GET['tab'])) ? sanitize_text_field($_GET['tab']) : 'settings';

		return '
		<h2 class="nav-tab-wrapper fraudlabspro-woocommerce-wrapper">
			<a href="' . (($disable_tabs) ? 'javascript:;' : admin_url('admin.php?page=woocommerce-fraudlabs-pro&tab=settings')) . '" class="nav-tab' . (($tab == 'settings') ? ' nav-tab-active' : '') . '" style="margin-left:0;">General</a>
			<a href="' . (($disable_tabs) ? 'javascript:;' : admin_url('admin.php?page=woocommerce-fraudlabs-pro&tab=order')) . '" class="nav-tab' . (($tab == 'order') ? ' nav-tab-active' : '') . '">Order</a>
			<a href="' . (($disable_tabs) ? 'javascript:;' : admin_url('admin.php?page=woocommerce-fraudlabs-pro&tab=notification')) . '" class="nav-tab' . (($tab == 'notification') ? ' nav-tab-active' : '') . '">Notification</a>
			<a href="' . (($disable_tabs) ? 'javascript:;' : admin_url('admin.php?page=woocommerce-fraudlabs-pro&tab=data')) . '" class="nav-tab' . (($tab == 'data') ? ' nav-tab-active' : '') . '">Data</a>
		</h2>';
	}

	/**
	 * Javascript agent.
	 */
	public function javascript_agent() {
		echo '<script>!function(){function t(){var t=document.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://cdn.fraudlabspro.com/s.js";var e=document.getElementsByTagName("script")[0];e.parentNode.insertBefore(t,e)}window.attachEvent?window.attachEvent("onload",t):window.addEventListener("load",t,!1)}();</script>';
	}


	/**
	 * Add risk score column to order list.
	 */
	public function add_column( $columns ) {
		if ( $this->enabled != 'yes' ) {
			return $columns;
		}

		$columns = array_merge( array_slice( $columns, 0, 5 ), array( 'fraudlabspro_score' => 'Risk Score' ), array_slice( $columns, 5 ) );
		return $columns;
	}


	/**
	 * Add risk score column to order list for HPOS.
	 */
	public function add_column_hpos( $columns ) {
		if ( $this->enabled != 'yes' ) {
			return $columns;
		}

		$columns = array_merge( array_slice( $columns, 0, 5 ), array( 'fraudlabspro_score' => 'Risk Score' ), array_slice( $columns, 5 ) );
		return $columns;
	}


	/**
	 * Fill in FraudLabs Pro score into risk score column.
	 */
	public function render_column( $column ) {
		if ( $this->enabled != 'yes' ) {
			return;
		}

		if ( $column != 'fraudlabspro_score' ) {
			return;
		}

		global $post;

		$result = get_post_meta( $post->ID, '_fraudlabspro' );

		if (!$result) {
			$table_name = $this->create_flpwc_table();
			$result = $this->get_flpwc_data($table_name, $post->ID, '_fraudlabspro');
		}

		if ( count( $result ) > 0 ) {
			$idx = count( $result ) - 1;
			if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) && strpos( $result[$idx], '\\' ) ) {
				$result[$idx] = str_replace( '\\', '', $result[$idx] );
			}

			if( is_array( $result[$idx] ) ){
				if ( is_null( $row = $result[$idx] ) === FALSE ) {
					if ( isset( $row['fraudlabspro_score'] ) ) {
						if ( $row['fraudlabspro_score'] > 80 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
						elseif ( $row['fraudlabspro_score'] > 60 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
						else {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
					}
				}
			} else {
				if( is_object( $result[$idx] ) ){
					$row = $result[$idx];
				} else {
					$row = json_decode( $result[$idx] );
				}
				if ( is_null( $row ) === FALSE ) {
					if ( isset( $row->fraudlabspro_score ) ) {
						if ( $row->fraudlabspro_score > 80 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
						elseif ( $row->fraudlabspro_score > 60 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
						else {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $post->ID . '&action=edit#flp-details"><div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
					}
				}
			}
		}
	}

	/**
	 * Fill in FraudLabs Pro score into risk score column for HPOS.
	 */
	public function render_column_hpos( $column, $order ) {
		if ( $this->enabled != 'yes' ) {
			return;
		}

		if ( $column != 'fraudlabspro_score' ) {
			return;
		}

		$result = get_post_meta( $order->get_id(), '_fraudlabspro' );

		if (!$result) {
			$table_name = $this->create_flpwc_table();
			$result = $this->get_flpwc_data($table_name, $order->get_id(), '_fraudlabspro');
		}

		if ( count( $result ) > 0 ) {
			$idx = count( $result ) - 1;
			if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) && strpos( $result[$idx], '\\' ) ) {
				$result[$idx] = str_replace( '\\', '', $result[$idx] );
			}

			if( is_array( $result[$idx] ) ){
				if ( is_null( $row = $result[$idx] ) === FALSE ) {
					if ( isset( $row['fraudlabspro_score'] ) ) {
						if ( $row['fraudlabspro_score'] > 80 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
						elseif ( $row['fraudlabspro_score'] > 60 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
						else {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row['fraudlabspro_score'] . '</strong></div></a>';
						}
					}
				}
			} else {
				if( is_object( $result[$idx] ) ){
					$row = $result[$idx];
				} else {
					$row = json_decode( $result[$idx] );
				}
				if ( is_null( $row ) === FALSE ) {
					if ( isset( $row->fraudlabspro_score ) ) {
						if ( $row->fraudlabspro_score > 80 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#ff0000"><span class="dashicons dashicons-warning"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
						elseif ( $row->fraudlabspro_score > 60 ) {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#f0c850"><span class="dashicons dashicons-warning"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
						else {
							echo '<a href="' . get_site_url() . '/wp-admin/post.php?post=' . $order->get_id() . '&action=edit#flp-details"><div style="color:#66cc00"><span class="dashicons dashicons-thumbs-up"></span> <strong>' . $row->fraudlabspro_score . '</strong></div></a>';
						}
					}
				}
			}
		}
	}


	/**
	 * Append FraudLabs Pro report to order details.
	 */
	public function render_fraud_report() {
		if ( $this->enabled != 'yes' ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		// Checking for HPOS
		if ( isset( $_GET['id'] ) ) {
			$_GET['post'] = $_GET['id'];
		}

		if ( isset( $_POST['orderId'] ) ) {
			$order = wc_get_order( sanitize_text_field($_POST['orderId']) );
		}

		if ( isset( $_POST['approve-flp'] ) ) {
			check_admin_referer('review-action', '_wpnonce_review_flp');

			$queries = [
				'key'			=> $this->api_key,
				'action'		=> 'APPROVE',
				'id'			=> sanitize_text_field($_POST['transactionId']),
				'format'		=> 'json',
				'source'		=> 'woocommerce',
				'triggered_by'	=> 'manual'
			];
			$request = $this->post( 'https://api.fraudlabspro.com/v2/order/feedback', $queries );

			if ( $request ) {
				$response = json_decode( $request );

				if ( is_object( $response ) ) {
					$result = get_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro' );

					if (!$result) {
						$table_name = $this->create_flpwc_table();
						$result = $this->get_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro');
					}

					$idx = count( $result ) - 1;
					if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
						$row = json_decode( $result[$idx] );
					} else {
						$row = $result[$idx];
					}

					if ( is_array( $result[$idx] ) ) {
						$row['fraudlabspro_status'] = 'APPROVE';
					} else {
						if (isset($row->fraudlabspro_status)) {
							$row->fraudlabspro_status = 'APPROVE';
						}
					}
					update_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro', $row );
					$table_name = $this->create_flpwc_table();
					$this->update_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro', $row);

					if( $this->approve_status && $order->get_status() != 'wc-completed' ) {
						$order->add_order_note( __( 'FraudLabs Pro status has been changed from Review to Approved. This order status has also been updated.', $this->namespace ) );
						$order->update_status( $this->approve_status, __( '', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					} else {
						//only add the note
						$order->add_order_note( __( 'FraudLabs Pro status has been changed from Review to Approved.', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					}
				}
			}
		}

		if( isset( $_POST['reject-flp'] ) ) {
			check_admin_referer('review-action', '_wpnonce_review_flp');

			$queries = [
				'key'			=> $this->api_key,
				'action'		=> 'REJECT',
				'id'			=> sanitize_text_field($_POST['transactionId']),
				'format'		=> 'json',
				'source'		=> 'woocommerce',
				'triggered_by'	=> 'manual'
			];
			$request = $this->post( 'https://api.fraudlabspro.com/v2/order/feedback', $queries );

			if ( $request ) {
				$response = json_decode( $request );

				if ( is_object( $response ) ) {
					$result = get_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro' );

					if (!$result) {
						$table_name = $this->create_flpwc_table();
						$result = $this->get_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro');
					}

					$idx = count( $result ) - 1;
					if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
						$row = json_decode( $result[$idx] );
					} else {
						$row = $result[$idx];
					}

					if ( is_array( $result[$idx] ) ) {
						$row['fraudlabspro_status'] = 'REJECT';
					} else {
						if (isset($row->fraudlabspro_status)) {
							$row->fraudlabspro_status = 'REJECT';
						}
					}
					update_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro', $row );
					$table_name = $this->create_flpwc_table();
					$this->update_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro', $row);

					if( $this->reject_status ) {
						$order->add_order_note( __( 'FraudLabs Pro status has been changed from Review to Rejected. This order status has also been updated.', $this->namespace ) );
						$order->update_status( $this->reject_status, __( '', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					} else {
						//just add the note
						$order->add_order_note( __( 'FraudLabs Pro status has been changed from Review to Rejected.', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					}
				}
			}
		}

		$reject_blacklist = ( isset ( $_POST['new_status'] ) ) ? sanitize_text_field($_POST['new_status']) : '';

		if ( $reject_blacklist === 'reject_blacklist') {
			check_admin_referer('review-action', '_wpnonce_review_flp');

			$queries = [
				'key'			=> $this->api_key,
				'action'		=> 'REJECT_BLACKLIST',
				'id'			=> sanitize_text_field($_POST['transactionId']),
				'format'		=> 'json',
				'note'			=> sanitize_text_field($_POST['feedback_note']),
				'source'		=> 'woocommerce',
				'triggered_by'	=> 'manual'
			];
			$request = $this->post( 'https://api.fraudlabspro.com/v2/order/feedback', $queries );

			if ( $request ) {
				$response = json_decode( $request );

				if ( is_object( $response ) ) {
					$result = get_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro' );

					if (!$result) {
						$table_name = $this->create_flpwc_table();
						$result = $this->get_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro');
					}

					$idx = count( $result ) - 1;
					if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
						$row = json_decode( $result[$idx] );
					} else {
						$row = $result[$idx];
					}

					if ( is_array( $result[$idx] ) ) {
						$row['fraudlabspro_status'] = 'REJECT';
						$row['is_blacklisted'] = '1';
					} else {
						if (isset($row->fraudlabspro_status)) {
							$row->fraudlabspro_status = 'REJECT';
							$row->is_blacklisted = '1';
						}
					}
					update_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro', $row );
					$table_name = $this->create_flpwc_table();
					$this->update_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro', $row);

					if( $this->reject_status ) {
						$order->add_order_note( __( 'This order has been blacklisted by FraudLabs Pro and the order status has also been updated.', $this->namespace ) );
						$order->update_status( $this->reject_status, __( '', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					} else {
						//just add the note
						$order->add_order_note( __( 'This order has been blacklisted by FraudLabs Pro.', $this->namespace ) );
						wp_safe_redirect($_SERVER['REQUEST_URI']);
						exit;
					}
				}
			}
		}

		$plan_name = '';
		// Use plan API to get license information
		$request = wp_remote_get( 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query( array(
			'key'		=> $this->api_key,
			'format'	=> 'json'
		) ) );

		if ( ! is_wp_error( $request ) ) {
			// Get the HTTP response
			$response = json_decode( wp_remote_retrieve_body( $request ) );

			if ( is_object( $response ) ) {
				$plan_name = $response->plan_name;
			}
		}

		if ( isset( $_GET['post'] ) ) {
			$result = get_post_meta( sanitize_text_field($_GET['post']), '_fraudlabspro' );

			if (!$result) {
				$table_name = $this->create_flpwc_table();
				$result = $this->get_flpwc_data($table_name, sanitize_text_field($_GET['post']), '_fraudlabspro');
			}

			if ( count( $result ) > 0 ) {
				$idx = count( $result ) - 1;
				if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) && strpos( $result[$idx], '\\' ) ) {
					$result[$idx] = str_replace( '\\', '', $result[$idx] );
				}

				if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
					$row = json_decode( $result[$idx] );
				} else {
					$row = $result[$idx];
				}

				if( is_array( $result[$idx] ) ){
					if ( $row['fraudlabspro_id'] != '' ){
						$table = '
						<style type="text/css">
							.fraudlabspro {width:100%;}
							.fraudlabspro td{padding:10px 0; vertical-align:top}
							.flp-helper{text-decoration:none}

							/* color: Approve - #45b6af, Reject - #f3565d, Review - #dfba49 */
						</style>

						<table class="fraudlabspro">
							<tr>
								<td colspan="3" style="text-align:center; background-color:#ab1b1c; border:1px solid #ab1b1c; padding-top:10px; padding-bottom:10px;">
									<a href="https://www.fraudlabspro.com" target="_blank"><img src="'. plugins_url( '/assets/images/logo_200.png', WC_FLP_DIR ) .'" alt="FraudLabs Pro" /></a>
								</td>
							</tr>';

						$location = array();
						if ( strlen( $row['ip_country'] ) == 2 ) {
							$location = array(
								$this->get_country_by_code( esc_html($row['ip_country']) ),
								esc_html($row['ip_region']),
								esc_html($row['ip_city'])
							);

							$location = array_unique( $location );
						}

						switch( $row['fraudlabspro_status'] ) {
							case 'REVIEW':
								$fraudlabspro_status_display = "REVIEW";
								$color = 'dfba49';
								break;

							case 'REJECT':
								$fraudlabspro_status_display = "REJECTED";
								$color = 'f3565d';
								break;

							case 'APPROVE':
								$fraudlabspro_status_display = "APPROVED";
								$color = '45b6af';
								break;
						}

						$table .= '
							<tr>
								<td><p style="font-size:16px;margin: 0 auto;"><b>General</b></p></td>
							</tr>
							<tr>
								<td rowspan="2">
									<b>FraudLabs Pro Score</b> <a href="javascript:;" class="flp-helper" title="Risk score, 0 (low risk) - 100 (high risk)."><span class="dashicons dashicons-editor-help"></span></a><br/>
									<img class="img-responsive" alt="" src="//cdn.fraudlabspro.com/assets/img/scores/meter-' . ( ( $row['fraudlabspro_score'] ) ? esc_html($row['fraudlabspro_score']) . '.png' : 'nofraudprotection.png' ) . '" style="width:160px;" />
								</td>
								<td>
									<b>FraudLabs Pro Status</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro status."><span class="dashicons dashicons-editor-help"></span></a>
									<span style="color:#' . $color . ';font-size:28px; display:block;">' . $fraudlabspro_status_display . '</span>
								</td>
								<td>
									<b>Credit Balance</b> <a href="javascript:;" class="flp-helper" title="Balance of the credits available after this transaction."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['fraudlabspro_credits']) . ' [<a href="https://www.fraudlabspro.com/pricing" target="_blank">Upgrade</a>]</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Transaction ID</b> <a href="javascript:;" class="flp-helper" title="Unique identifier for a transaction screened by FraudLabs Pro system."><span class="dashicons dashicons-editor-help"></span></a>
									<p><a href="https://www.fraudlabspro.com/merchant/transaction-details/' . esc_html($row['fraudlabspro_id']) . '" target="_blank">' . esc_html($row['fraudlabspro_id']) . '</a></p>
								</td>
								<td>
									<b>Triggered Rules</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro Rules triggered."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( strpos( $plan_name, 'Micro' ) ? '<span style="color:orange">Available for <a href="https://www.fraudlabspro.com/pricing" target="_blank">Mini plan</a> onward. Please <a href="https://www.fraudlabspro.com/merchant/login" target="_blank">upgrade</a>.</span>' : ( ( isset( $row['fraudlabspro_rules'] ) ) ? esc_html($row['fraudlabspro_rules']) : '-' ) )  . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td><p style="font-size:16px;margin:0 auto;"><b>IP Geolocation</b></p></td>
							</tr>
							<tr>
								<td style="width:33%;">
									<b>IP Address</b>
									<p>' . esc_html($row['ip_address']) . '</p>
								</td>
								<td style="width:33%;">
									<b>Coordinate</b>
									<p>' . esc_html($row['ip_latitude']) . ', ' . esc_html($row['ip_longitude']) . '</p>
								</td>
								<td>
									<b>IP Location</b>
									<p>' . implode( ', ', $location ) . ' <a href="https://www.geolocation.com/' . esc_html($row['ip_address']) . '" target="_blank">[Map]</a></p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Time Zone</b>
									<p>' . esc_html($row['ip_timezone']) . '</p>
								</td>
								<td>
									<b>IP to Billing Distance</b>
									<p>' . ( ( $row['distance_in_km'] ) ? ( esc_html($row['distance_in_km']) . ' KM / ' . esc_html($row['distance_in_mile']) . ' Miles' ) : '-' ) . '</p>
								</td>
								<td>
									<b>IP ISP Name</b> <a href="javascript:;" class="flp-helper" title="ISP of the IP address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['ip_isp_name']) . '</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>IP Domain</b> <a href="javascript:;" class="flp-helper" title="Domain name of the IP address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['ip_domain']) . '</p>
								</td>
								<td>
									<b>IP Usage Type</b> <a href="javascript:;" class="flp-helper" title="Usage type of the IP address. E.g, ISP, Commercial, Residential."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( ($row['ip_usage_type'] == 'NA' ) ? 'Not available [<a href="https://www.fraudlabspro.com/pricing" target="_blank">Upgrade</a>]' : esc_html($row['ip_usage_type']) ) . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td><p style="font-size:16px;margin:0 auto;"><b>Validation Information</b></p></td>
							</tr>
							<tr>
								<td>
									<b>Free Email Domain</b>
									<p>' . $this->parse_fraud_result( $row['is_free_email'] ) . '</p>
								</td>
								<td>
									<b>IP in Blacklist</b>
									<p>' . $this->parse_fraud_result( $row['is_ip_blacklist'] ) . '</p>
								</td>
								<td>
									<b>Email in Blacklist</b>
									<p>' . $this->parse_fraud_result( $row['is_email_blacklist'] ) . '</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Proxy IP</b> <a href="javascript:;" class="flp-helper" title="Whether IP address is from Anonymous Proxy Server."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . $this->parse_fraud_result( $row['is_proxy_ip_address'] ) . '</p>
								</td>
								<td>
									<b>Ship Forwarder</b> <a href="javascript:;" class="flp-helper" title="Whether shipping address is a freight forwarder address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . $this->parse_fraud_result( $row['is_address_ship_forward'] ) . '</p>
								</td>
								<td>
									<b>Phone Verified</b>
									<p>'. ( isset( $row['is_phone_verified'] ) ? ( ( is_plugin_active( 'fraudlabs-pro-sms-verification/fraudlabspro-sms-verification.php' ) ) ? esc_html($row['is_phone_verified']) : '- [<a href="https://wordpress.org/plugins/fraudlabs-pro-sms-verification/" target="_blank">FraudLabs Pro SMS Verification Plugin Required</a>]' ) : 'NA [<a href="https://wordpress.org/plugins/fraudlabs-pro-sms-verification/" target="_blank">FraudLabs Pro SMS Verification Plugin Required</a>]' ) .'</p>
								</td>
							</tr>
							<tr>
								<td colspan="3">
									<b>Error Message</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro error message description."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( ( $row['fraudlabspro_message'] ) ? esc_html($row['fraudlabspro_error_code']) . ':' . esc_html($row['fraudlabspro_message']) : '-' ) . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td colspan="3">
									<p>Please login to <a href="https://www.fraudlabspro.com/merchant/transaction-details/' . esc_html($row['fraudlabspro_id']) . '" target="_blank">FraudLabs Pro Merchant Area</a> for more information about this order.</p>
								</td>
							</tr>
						</table>
						<form id="review-action" method="post">
							<p align="center">
								<input type="hidden" name="transactionId" value="' . esc_html($row['fraudlabspro_id']) . '" >
								<input type="hidden" name="orderId" value="' . esc_html($row['order_id']) . '" >
								<input type="hidden" id="new_status" name="new_status" value="" />
								<input type="hidden" id="feedback_note" name="feedback_note" value="" />
								' . wp_nonce_field('review-action', '_wpnonce_review_flp');

						if( $row['fraudlabspro_status'] == 'REVIEW' ) {
							$table .= '
							<input type="submit" name="approve-flp" id="approve-order" value="' . __( 'Approve', $this->namespace ) . '" style="padding:10px 5px; background:#45B6AF; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;" />
							<input type="submit" name="reject-flp" id="reject-order" value="' . __( 'Reject', $this->namespace ) . '" style="padding:10px 5px; background:#F3565D; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;" />';
						}

						if ( !isset( $row['is_blacklisted'] ) ) {
							$table .= '
							<input type="button" name="reject-blacklist" id="reject-blacklist-order" value="' . __( 'Blacklist', $this->namespace ) . '" style="padding:10px 5px; background:#666; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;color:#fff" />';
						}

						$table .= '
							</p>
						</form>';

						$report_display = ( get_option('wc_settings_woocommerce-fraudlabs-pro_expand_report') != 'yes' ) ? 'style="display:none"' : '';
						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table" ' . $report_display . '><blockquote>' . preg_replace( '/[\n]*/is', '', str_replace( '\'', '\\\'', $table ) ) . '</blockquote></div></div></div>\');';

						if ( get_option('wc_settings_woocommerce-fraudlabs-pro_expand_report') != 'yes' ) {
							echo '
							if (window.location.href.indexOf("flp-details") > -1) {
								jQuery("#flp-details-table").toggle("show");
								jQuery("#flp-details-span").toggleClass("dashicons-arrow-up dashicons-arrow-down");
							}';
						}

						echo '
							jQuery("#flp-details").click(function(){
								jQuery("#flp-details-table").toggle("show");
								jQuery("#flp-details-span").toggleClass("dashicons-arrow-up dashicons-arrow-down");
							});

							jQuery("#reject-blacklist-order").click(function(){
								var note = prompt("Please enter the reason(s) for blacklisting this order. (Optional)");
								if(note !== null){
									jQuery("#feedback_note").val(note);
									jQuery("#new_status").val("reject_blacklist");
									jQuery("#review-action").submit();
								}
							});
						});
						</script>';
					} else {
						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table"><blockquote>This order has not been screened by FraudLabs Pro.</blockquote></div></div></div>\');
						});
						</script>';
					}
				} else {
					if ( $row->fraudlabspro_id != '' ){
						$table = '
						<style type="text/css">
							.fraudlabspro {width:100%;}
							.fraudlabspro td{padding:10px 0; vertical-align:top}
							.flp-helper{text-decoration:none}

							/* color: Approve - #45b6af, Reject - #f3565d, Review - #dfba49 */
						</style>

						<table class="fraudlabspro">
							<tr>
								<td colspan="3" style="text-align:center; background-color:#ab1b1c; border:1px solid #ab1b1c; padding-top:10px; padding-bottom:10px;">
									<a href="https://www.fraudlabspro.com" target="_blank"><img src="'. plugins_url( '/assets/images/logo_200.png', WC_FLP_DIR ) .'" alt="FraudLabs Pro" /></a>
								</td>
							</tr>';

						$location = array();
						if ( strlen( $row->ip_country ) == 2 ) {
							$location = array(
								$this->get_country_by_code( esc_html($row->ip_country) ),
								esc_html($row->ip_region),
								esc_html($row->ip_city)
							);

							$location = array_unique( $location );
						}

						switch( $row->fraudlabspro_status ) {
							case 'REVIEW':
								$fraudlabspro_status_display = "REVIEW";
								$color = 'dfba49';
								break;

							case 'REJECT':
								$fraudlabspro_status_display = "REJECTED";
								$color = 'f3565d';
								break;

							case 'APPROVE':
								$fraudlabspro_status_display = "APPROVED";
								$color = '45b6af';
								break;
						}

						$table .= '
							<tr>
								<td><p style="font-size:16px;margin: 0 auto;"><b>General</b></p></td>
							</tr>
							<tr>
								<td rowspan="2">
									<b>FraudLabs Pro Score</b> <a href="javascript:;" class="flp-helper" title="Risk score, 0 (low risk) - 100 (high risk)."><span class="dashicons dashicons-editor-help"></span></a><br/>
									<img class="img-responsive" alt="" src="//cdn.fraudlabspro.com/assets/img/scores/meter-' . ( ( $row['fraudlabspro_score'] ) ? esc_html($row['fraudlabspro_score']) . '.png' : 'nofraudprotection.png' ) . '" style="width:160px;" />
								</td>
								<td>
									<b>FraudLabs Pro Status</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro status."><span class="dashicons dashicons-editor-help"></span></a>
									<span style="color:#' . $color . ';font-size:28px; display:block;">' . $fraudlabspro_status_display . '</span>
								</td>
								<td>
									<b>Credit Balance</b> <a href="javascript:;" class="flp-helper" title="Balance of the credits available after this transaction."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['fraudlabspro_credits']) . ' [<a href="https://www.fraudlabspro.com/pricing" target="_blank">Upgrade</a>]</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Transaction ID</b> <a href="javascript:;" class="flp-helper" title="Unique identifier for a transaction screened by FraudLabs Pro system."><span class="dashicons dashicons-editor-help"></span></a>
									<p><a href="https://www.fraudlabspro.com/merchant/transaction-details/' . esc_html($row['fraudlabspro_id']) . '" target="_blank">' . esc_html($row['fraudlabspro_id']) . '</a></p>
								</td>
								<td>
									<b>Triggered Rules</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro Rules triggered."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( strpos( $plan_name, 'Micro' ) ? '<span style="color:orange">Available for <a href="https://www.fraudlabspro.com/pricing" target="_blank">Mini plan</a> onward. Please <a href="https://www.fraudlabspro.com/merchant/login" target="_blank">upgrade</a>.</span>' : ( ( isset( $row['fraudlabspro_rules'] ) ) ? esc_html($row['fraudlabspro_rules']) : '-' ) )  . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td><p style="font-size:16px;margin:0 auto;"><b>IP Geolocation</b></p></td>
							</tr>
							<tr>
								<td style="width:33%;">
									<b>IP Address</b>
									<p>' . esc_html($row['ip_address']) . '</p>
								</td>
								<td style="width:33%;">
									<b>Coordinate</b>
									<p>' . esc_html($row['ip_latitude']) . ', ' . esc_html($row['ip_longitude']) . '</p>
								</td>
								<td>
									<b>IP Location</b>
									<p>' . implode( ', ', $location ) . ' <a href="https://www.geolocation.com/' . esc_html($row['ip_address']) . '" target="_blank">[Map]</a></p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Time Zone</b>
									<p>' . esc_html($row['ip_timezone']) . '</p>
								</td>
								<td>
									<b>IP to Billing Distance</b>
									<p>' . ( ( $row['distance_in_km'] ) ? ( esc_html($row['distance_in_km']) . ' KM / ' . esc_html($row['distance_in_mile']) . ' Miles' ) : '-' ) . '</p>
								</td>
								<td>
									<b>IP ISP Name</b> <a href="javascript:;" class="flp-helper" title="ISP of the IP address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['ip_isp_name']) . '</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>IP Domain</b> <a href="javascript:;" class="flp-helper" title="Domain name of the IP address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . esc_html($row['ip_domain']) . '</p>
								</td>
								<td>
									<b>IP Usage Type</b> <a href="javascript:;" class="flp-helper" title="Usage type of the IP address. E.g, ISP, Commercial, Residential."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( ($row['ip_usage_type'] == 'NA' ) ? 'Not available [<a href="https://www.fraudlabspro.com/pricing" target="_blank">Upgrade</a>]' : esc_html($row['ip_usage_type']) ) . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td><p style="font-size:16px;margin:0 auto;"><b>Validation Information</b></p></td>
							</tr>
							<tr>
								<td>
									<b>Free Email Domain</b>
									<p>' . $this->parse_fraud_result( $row['is_free_email'] ) . '</p>
								</td>
								<td>
									<b>IP in Blacklist</b>
									<p>' . $this->parse_fraud_result( $row['is_ip_blacklist'] ) . '</p>
								</td>
								<td>
									<b>Email in Blacklist</b>
									<p>' . $this->parse_fraud_result( $row['is_email_blacklist'] ) . '</p>
								</td>
							</tr>
							<tr>
								<td>
									<b>Proxy IP</b> <a href="javascript:;" class="flp-helper" title="Whether IP address is from Anonymous Proxy Server."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . $this->parse_fraud_result( $row['is_proxy_ip_address'] ) . '</p>
								</td>
								<td>
									<b>Ship Forwarder</b> <a href="javascript:;" class="flp-helper" title="Whether shipping address is a freight forwarder address."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . $this->parse_fraud_result( $row['is_address_ship_forward'] ) . '</p>
								</td>
								<td>
									<b>Phone Verified</b>
									<p>'. ( isset( $row['is_phone_verified'] ) ? ( ( is_plugin_active( 'fraudlabs-pro-sms-verification/fraudlabspro-sms-verification.php' ) ) ? esc_html($row['is_phone_verified']) : '- [<a href="https://wordpress.org/plugins/fraudlabs-pro-sms-verification/" target="_blank">FraudLabs Pro SMS Verification Plugin Required</a>]' ) : 'NA [<a href="https://wordpress.org/plugins/fraudlabs-pro-sms-verification/" target="_blank">FraudLabs Pro SMS Verification Plugin Required</a>]' ) .'</p>
								</td>
							</tr>
							<tr>
								<td colspan="3">
									<b>Error Message</b> <a href="javascript:;" class="flp-helper" title="FraudLabs Pro error message description."><span class="dashicons dashicons-editor-help"></span></a>
									<p>' . ( ( $row['fraudlabspro_message'] ) ? esc_html($row['fraudlabspro_error_code']) . ':' . esc_html($row['fraudlabspro_message']) : '-' ) . '</p>
								</td>
							</tr>
							<tr><td colspan="3" style="margin:10px auto;"><hr></td></tr>
							<tr>
								<td colspan="3">
									<p>Please login to <a href="https://www.fraudlabspro.com/merchant/transaction-details/' . esc_html($row['fraudlabspro_id']) . '" target="_blank">FraudLabs Pro Merchant Area</a> for more information about this order.</p>
								</td>
							</tr>
						</table>
						<form id="review-action" method="post">
							<p align="center">
								<input type="hidden" name="transactionId" value="' . esc_html($row->fraudlabspro_id) . '" >
								<input type="hidden" name="orderId" value="' . esc_html($row->order_id) . '" >
								<input type="hidden" id="new_status" name="new_status" value="" />
								<input type="hidden" id="feedback_note" name="feedback_note" value="" />
								' . wp_nonce_field('review-action', '_wpnonce_review_flp');

						if( $row->fraudlabspro_status == 'REVIEW' ) {
							$table .= '
							<input type="submit" name="approve-flp" id="approve-order" value="' . __( 'Approve', $this->namespace ) . '" style="padding:10px 5px; background:#45B6AF; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;" />
							<input type="submit" name="reject-flp" id="reject-order" value="' . __( 'Reject', $this->namespace ) . '" style="padding:10px 5px; background:#F3565D; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;" />';
						}

						if ( !isset( $row->is_blacklisted ) ) {
							$table .= '
							<input type="button" name="reject-blacklist" id="reject-blacklist-order" value="' . __( 'Blacklist', $this->namespace ) . '" style="padding:10px 5px; background:#666; color:#fff; border:1px solid #ccc; min-width:100px; cursor: pointer;color:#fff" />';
						}

						$table .= '
							</p>
						</form>';

						$report_display = ( get_option('wc_settings_woocommerce-fraudlabs-pro_expand_report') != 'yes' ) ? 'style="display:none"' : '';
						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table" ' . $report_display . '><blockquote>' . preg_replace( '/[\n]*/is', '', str_replace( '\'', '\\\'', $table ) ) . '</blockquote></div></div></div>\');

							jQuery("#flp-details").click(function(){
								jQuery("#flp-details-table").toggle("show");
								jQuery("#flp-details-span").toggleClass("dashicons-arrow-up dashicons-arrow-down");
							});

							jQuery("#reject-blacklist-order").click(function(){
								var note = prompt("Please enter the reason(s) for blacklisting this order. (Optional)");
								if(note !== null){
									jQuery("#feedback_note").val(note);
									jQuery("#new_status").val("reject_blacklist");
									jQuery("#review-action").submit();
								}
							});
						});
						</script>';
					} else {
						echo '
						<script>
						jQuery(function(){
							jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table"><blockquote>This order has not been screened by FraudLabs Pro.</blockquote></div></div></div>\');
						});
						</script>';
					}
				}
			} else {
				echo '
				<script>
				jQuery(function(){
					jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table"><blockquote>This order has not been screened by FraudLabs Pro.</blockquote></div></div></div>\');
				});
				</script>';
			}
		} else {
			echo '
			<script>
			jQuery(function(){
				jQuery("#woocommerce-order-items").before(\'<div class="metabox-holder"><div class="postbox" style="padding: 10px 0px;"><div id="flp-details"><h2 style="text-align:center; display:inline;">FraudLabs Pro Details</h2> <a href="javascript:;" class="flp-helper" title="Collapse/Expand"><span style="float:right; padding-right:10px;" class="dashicons dashicons-arrow-up" id="flp-details-span"></span></a></div><div id="flp-details-table"><blockquote>This order has not been screened by FraudLabs Pro.</blockquote></div></div></div>\');
			});
			</script>';
		}
	}


	/**
	 * Auto approve the order as the merchant mark the order as completed.
	 */
	public function order_status_completed( $order_id ) {
		if ( get_option('wc_settings_woocommerce-fraudlabs-pro_change_status_auto') != 'yes' ) {
			return;
		}

		$result = get_post_meta( $order_id, '_fraudlabspro' );

		if (!$result) {
			$table_name = $this->create_flpwc_table();
			$result = $this->get_flpwc_data($table_name, $order_id, '_fraudlabspro');
		}

		$idx = count( $result ) - 1;

		if ($idx < 0) {
			return;
		}

		if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
			$row = json_decode( $result[$idx] );
		} else {
			$row = $result[$idx];
		}

		$flp_id = is_array( $result[$idx] ) ? $row['fraudlabspro_id'] : $row->fraudlabspro_id;
		$queries = [
			'key'			=> $this->api_key,
			'action'		=> 'APPROVE',
			'id'			=> $flp_id,
			'format'		=> 'json',
			'source'		=> 'woocommerce',
			'triggered_by'	=> 'order_status_completed'
		];
		$request = $this->post( 'https://api.fraudlabspro.com/v2/order/feedback', $queries );

		if ( $request ) {
			$response = json_decode( $request );

			if ( is_object( $response ) ) {
				if ( is_array( $result[$idx] ) ) {
					$row['fraudlabspro_status'] = 'APPROVE';
				} else {
					if (isset($row->fraudlabspro_status)) {
						$row->fraudlabspro_status = 'APPROVE';
					}
				}
				update_post_meta( $order_id, '_fraudlabspro', $row );
				$table_name = $this->create_flpwc_table();
				$this->update_flpwc_data($table_name, $order_id, '_fraudlabspro', $row);
			}
		}
	}


	/**
	 * Auto reject the order as the merchant mark the order as cancelled.
	 */
	public function order_status_cancelled( $order_id ) {
		if ( get_option('wc_settings_woocommerce-fraudlabs-pro_change_status_auto') != 'yes' ) {
			return;
		}

		$result = get_post_meta( $order_id, '_fraudlabspro' );

		if (!$result) {
			$table_name = $this->create_flpwc_table();
			$result = $this->get_flpwc_data($table_name, $order_id, '_fraudlabspro');
		}

		$idx = count( $result ) - 1;

		if ($idx < 0) {
			return;
		}

		if ( !is_array( $result[$idx] ) && !is_object( $result[$idx] ) ) {
			$row = json_decode( $result[$idx] );
		} else {
			$row = $result[$idx];
		}

		// Configures FraudLabs Pro API key
		$flp_id = is_array( $result[$idx] ) ? $row['fraudlabspro_id'] : $row->fraudlabspro_id;
		$queries = [
			'key'			=> $this->api_key,
			'action'		=> 'REJECT',
			'id'			=> $flp_id,
			'format'		=> 'json',
			'source'		=> 'woocommerce',
			'triggered_by'	=> 'order_status_cancelled'
		];
		$request = $this->post( 'https://api.fraudlabspro.com/v2/order/feedback', $queries );

		if ( $request ) {
			$response = json_decode( $request );

			if ( is_object( $response ) ) {
				if ( is_array( $result[$idx] ) ) {
					$row['fraudlabspro_status'] = 'REJECT';
				} else {
					if (isset($row->fraudlabspro_status)) {
						$row->fraudlabspro_status = 'REJECT';
					}
				}
				update_post_meta( $order_id, '_fraudlabspro', $row );
				$table_name = $this->create_flpwc_table();
				$this->update_flpwc_data($table_name, $order_id, '_fraudlabspro', $row);
			}
		}
	}


	public function pre_payment_complete( $order_id ) {
		$this->write_debug_log( 'Prepayment completed for Order ' . $order_id . '.');
	}


	public function payment_complete( $order_id ) {
		$this->write_debug_log( 'Payment completed for Order ' . $order_id . '.');
	}


	public function validate_api_key() {
		check_ajax_referer('validate-api-key', '__nonce');

		try {
			$apiKey = ( isset( $_POST['token'] ) ) ? sanitize_text_field($_POST['token']) : '';

			$request = wp_remote_get( 'https://api.fraudlabspro.com/v2/plan/result?' . http_build_query( array(
				'key'		=> $apiKey,
				'format'	=> 'json'
			) ) );

			if ( ! is_wp_error( $request ) ) {
				// Get the HTTP response
				$response = json_decode( wp_remote_retrieve_body( $request ) );

				if ( is_object( $response ) ) {
					if ( $response->plan_name == '') {
						die('Invalid API Key.');
					}

					update_option( 'wc_settings_woocommerce-fraudlabs-pro_enabled', 'yes' );
					update_option( 'wc_settings_woocommerce-fraudlabs-pro_api_key', $apiKey );

					die( 'SUCCESS_' . esc_html($response->plan_name) );
				} else {
					die( 'Response Error.' );
				}
			} else {
				die( 'WP Error.' );
			}
		} catch (Exception $e) {
			die( $e->getMessage() );
		}
	}


	/**
	 * Write to debug log to record details of process.
	 */
	public function write_debug_log( $message ) {
		if ( get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log') != 'yes' ) {
			return;
		}

		if ( get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') != '' ) {
			$path = ABSPATH . get_option('wc_settings_woocommerce-fraudlabs-pro_debug_log_path') . 'debug.log';
		} else {
			$path = FRAUDLABS_PRO_ROOT . 'debug.log';
		}

		if ( is_array( $message ) || is_object( $message ) ) {
			file_put_contents( $path, gmdate('Y-m-d H:i:s') . "\t" . print_r( $message, true ) . "\n", FILE_APPEND );
		} else {
			file_put_contents( $path, gmdate('Y-m-d H:i:s') . "\t" . $message . "\n", FILE_APPEND );
		}
	}


	public function submit_feedback() {
		check_ajax_referer('submit-feedback', '__nonce');

		$feedback = ( isset( $_POST['feedback'] ) ) ? sanitize_text_field($_POST['feedback']) : '';
		$others = ( isset($_POST['others'] ) ) ? sanitize_text_field($_POST['others']) : '';

		$options = [
			1 => 'I no longer need the plugin',
			2 => 'I couldn\'t get the plugin to work',
			3 => 'The plugin doesn\'t meet my requirements',
			4 => 'Other concerns' . (($others) ? (' - ' . $others) : ''),
		];

		if ( isset($options[$feedback] ) ) {
			if ( !class_exists('WP_Http') ) {
				include_once ABSPATH . WPINC . '/class-http.php';
			}

			$request = new WP_Http();
			$response = $request->request('https://www.fraudlabspro.com/wp-plugin-feedback?' . http_build_query([
				'name'    => 'fraudlabs-pro-for-woocommerce',
				'message' => $options[$feedback],
			]), ['timeout' => 5]);
		}
	}


	public function admin_footer_text($footer_text) {
		$plugin_name = 'fraudlabs-pro-for-woocommerce';
		$current_screen = get_current_screen();

		if (($current_screen && strpos($current_screen->id, 'woocommerce-fraudlabs-pro') !== false)) {
			$footer_text .= sprintf(
				__('</br>Enjoyed %1$s? Please leave us a %2$s rating. A huge thanks in advance!', $plugin_name),
				'<strong>' . __('FraudLabs Pro for WooCommerce', $plugin_name) . '</strong>',
				'<a href="https://wordpress.org/support/plugin/' . $plugin_name . '/reviews/#new-post" target="_blank">&#9733;&#9733;&#9733;&#9733;&#9733;</a>'
			);
		}

		if ($current_screen->id == 'plugins') {
			return $footer_text . '
			<div id="fraudlabs-pro-for-woocommerce-feedback-modal" class="hidden" style="max-width:800px">
				<span id="fraudlabs-pro-for-woocommerce-feedback-response"></span>
				<p>
					<strong>Would you mind sharing with us the reason to deactivate the plugin?</strong>
				</p>
				<p>
					<label>
						<input type="radio" name="fraudlabs-pro-for-woocommerce-feedback" value="1"> I no longer need the plugin
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="fraudlabs-pro-for-woocommerce-feedback" value="2"> I couldn\'t get the plugin to work
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="fraudlabs-pro-for-woocommerce-feedback" value="3"> The plugin doesn\'t meet my requirements
					</label>
				</p>
				<p>
					<label>
						<input type="radio" name="fraudlabs-pro-for-woocommerce-feedback" value="4"> Other concerns
						<br><br>
						<textarea id="fraudlabs-pro-for-woocommerce-feedback-other" style="display:none;width:100%"></textarea>
					</label>
				</p>
				<p>
					<div style="float:left">
						<input type="button" id="fraudlabs-pro-for-woocommerce-submit-feedback-button" class="button button-danger" value="Submit & Deactivate" />
					</div>
					<div style="float:right">
						<a href="#">Skip & Deactivate</a>
					</div>
				</p>
				<input type="hidden" id="fraudlabs_pro_woocommerce_feedback_nonce" value="' . wp_create_nonce('submit-feedback') . '">
			</div>';
		}

		return $footer_text;
	}


	/**
	 * Use WP_LOADED for the callback function to work.
	 */
	function wc_flp_callback() {
		global $wp;

		if ( get_option( 'wc_settings_woocommerce-fraudlabs-pro_api_key' ) == '' ) {
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_approve_status', 'wc-processing' );
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_review_status', 'wc-on-hold' );
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_reject_status', 'wc-cancelled' );
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_reject_failed_order', 'yes' );
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_expand_report', 'yes' );
		}

		if ( get_option( 'wc_settings_woocommerce-fraudlabs-pro_reject_failed_order' ) == '' ) {
			update_option( 'wc_settings_woocommerce-fraudlabs-pro_reject_failed_order', 'yes' );
		}

		$current_host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
		$current_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
		$current_url = $current_host . $current_uri;

		if (strpos($current_url, 'flp-callback')) {
			include(plugin_dir_path(__FILE__) . '/flp-callback.php');
		}
	}


	/**
	 * Parse FraudLabs Pro API result.
	 */
	private function parse_fraud_result( $result ) {
		if ( $result == 'Y' )
			return 'Yes';

		if ( $result == 'N' )
			return 'No';

		if ( $result == 'NA' )
			return '-';

		return $result;
	}

	/**
	 * Get country name by country code.
	 */
	private function get_country_by_code( $code ) {
		$countries = array( 'AF' => 'Afghanistan','AL' => 'Albania','DZ' => 'Algeria','AS' => 'American Samoa','AD' => 'Andorra','AO' => 'Angola','AI' => 'Anguilla','AQ' => 'Antarctica','AG' => 'Antigua and Barbuda','AR' => 'Argentina','AM' => 'Armenia','AW' => 'Aruba','AU' => 'Australia','AT' => 'Austria','AZ' => 'Azerbaijan','BS' => 'Bahamas','BH' => 'Bahrain','BD' => 'Bangladesh','BB' => 'Barbados','BY' => 'Belarus','BE' => 'Belgium','BZ' => 'Belize','BJ' => 'Benin','BM' => 'Bermuda','BT' => 'Bhutan','BO' => 'Bolivia','BA' => 'Bosnia and Herzegovina','BW' => 'Botswana','BV' => 'Bouvet Island','BR' => 'Brazil','IO' => 'British Indian Ocean Territory','BN' => 'Brunei Darussalam','BG' => 'Bulgaria','BF' => 'Burkina Faso','BI' => 'Burundi','KH' => 'Cambodia','CM' => 'Cameroon','CA' => 'Canada','CV' => 'Cape Verde','KY' => 'Cayman Islands','CF' => 'Central African Republic','TD' => 'Chad','CL' => 'Chile','CN' => 'China','CX' => 'Christmas Island','CC' => 'Cocos (Keeling) Islands','CO' => 'Colombia','KM' => 'Comoros','CG' => 'Congo','CK' => 'Cook Islands','CR' => 'Costa Rica','CI' => 'Cote D\'Ivoire','HR' => 'Croatia','CU' => 'Cuba','CY' => 'Cyprus','CZ' => 'Czech Republic','CD' => 'Democratic Republic of Congo','DK' => 'Denmark','DJ' => 'Djibouti','DM' => 'Dominica','DO' => 'Dominican Republic','TP' => 'East Timor','EC' => 'Ecuador','EG' => 'Egypt','SV' => 'El Salvador','GQ' => 'Equatorial Guinea','ER' => 'Eritrea','EE' => 'Estonia','ET' => 'Ethiopia','FK' => 'Falkland Islands (Malvinas)','FO' => 'Faroe Islands','FJ' => 'Fiji','FI' => 'Finland','FR' => 'France','FX' => 'France, Metropolitan','GF' => 'French Guiana','PF' => 'French Polynesia','TF' => 'French Southern Territories','GA' => 'Gabon','GM' => 'Gambia','GE' => 'Georgia','DE' => 'Germany','GH' => 'Ghana','GI' => 'Gibraltar','GR' => 'Greece','GL' => 'Greenland','GD' => 'Grenada','GP' => 'Guadeloupe','GU' => 'Guam','GT' => 'Guatemala','GN' => 'Guinea','GW' => 'Guinea-bissau','GY' => 'Guyana','HT' => 'Haiti','HM' => 'Heard and Mc Donald Islands','HN' => 'Honduras','HK' => 'Hong Kong','HU' => 'Hungary','IS' => 'Iceland','IN' => 'India','ID' => 'Indonesia','IR' => 'Iran (Islamic Republic of)','IQ' => 'Iraq','IE' => 'Ireland','IL' => 'Israel','IT' => 'Italy','JM' => 'Jamaica','JP' => 'Japan','JO' => 'Jordan','KZ' => 'Kazakhstan','KE' => 'Kenya','KI' => 'Kiribati','KR' => 'Korea, Republic of','KW' => 'Kuwait','KG' => 'Kyrgyzstan','LA' => 'Lao People\'s Democratic Republic','LV' => 'Latvia','LB' => 'Lebanon','LS' => 'Lesotho','LR' => 'Liberia','LY' => 'Libyan Arab Jamahiriya','LI' => 'Liechtenstein','LT' => 'Lithuania','LU' => 'Luxembourg','MO' => 'Macau','MK' => 'Macedonia','MG' => 'Madagascar','MW' => 'Malawi','MY' => 'Malaysia','MV' => 'Maldives','ML' => 'Mali','MT' => 'Malta','MH' => 'Marshall Islands','MQ' => 'Martinique','MR' => 'Mauritania','MU' => 'Mauritius','YT' => 'Mayotte','MX' => 'Mexico','FM' => 'Micronesia, Federated States of','MD' => 'Moldova, Republic of','MC' => 'Monaco','MN' => 'Mongolia','MS' => 'Montserrat','MA' => 'Morocco','MZ' => 'Mozambique','MM' => 'Myanmar','NA' => 'Namibia','NR' => 'Nauru','NP' => 'Nepal','NL' => 'Netherlands','AN' => 'Netherlands Antilles','NC' => 'New Caledonia','NZ' => 'New Zealand','NI' => 'Nicaragua','NE' => 'Niger','NG' => 'Nigeria','NU' => 'Niue','NF' => 'Norfolk Island','KP' => 'North Korea','MP' => 'Northern Mariana Islands','NO' => 'Norway','OM' => 'Oman','PK' => 'Pakistan','PW' => 'Palau','PA' => 'Panama','PG' => 'Papua New Guinea','PY' => 'Paraguay','PE' => 'Peru','PH' => 'Philippines','PN' => 'Pitcairn','PL' => 'Poland','PT' => 'Portugal','PR' => 'Puerto Rico','QA' => 'Qatar','RE' => 'Reunion','RO' => 'Romania','RU' => 'Russian Federation','RW' => 'Rwanda','KN' => 'Saint Kitts and Nevis','LC' => 'Saint Lucia','VC' => 'Saint Vincent and the Grenadines','WS' => 'Samoa','SM' => 'San Marino','ST' => 'Sao Tome and Principe','SA' => 'Saudi Arabia','SN' => 'Senegal','SC' => 'Seychelles','SL' => 'Sierra Leone','SG' => 'Singapore','SK' => 'Slovak Republic','SI' => 'Slovenia','SB' => 'Solomon Islands','SO' => 'Somalia','ZA' => 'South Africa','GS' => 'South Georgia And The South Sandwich Islands','ES' => 'Spain','LK' => 'Sri Lanka','SH' => 'St. Helena','PM' => 'St. Pierre and Miquelon','SD' => 'Sudan','SR' => 'Suriname','SJ' => 'Svalbard and Jan Mayen Islands','SZ' => 'Swaziland','SE' => 'Sweden','CH' => 'Switzerland','SY' => 'Syrian Arab Republic','TW' => 'Taiwan','TJ' => 'Tajikistan','TZ' => 'Tanzania, United Republic of','TH' => 'Thailand','TG' => 'Togo','TK' => 'Tokelau','TO' => 'Tonga','TT' => 'Trinidad and Tobago','TN' => 'Tunisia','TR' => 'Turkey','TM' => 'Turkmenistan','TC' => 'Turks and Caicos Islands','TV' => 'Tuvalu','UG' => 'Uganda','UA' => 'Ukraine','AE' => 'United Arab Emirates','GB' => 'United Kingdom','US' => 'United States','UM' => 'United States Minor Outlying Islands','UY' => 'Uruguay','UZ' => 'Uzbekistan','VU' => 'Vanuatu','VA' => 'Vatican City State (Holy See)','VE' => 'Venezuela','VN' => 'Viet Nam','VG' => 'Virgin Islands (British)','VI' => 'Virgin Islands (U.S.)','WF' => 'Wallis and Futuna Islands','EH' => 'Western Sahara','YE' => 'Yemen','YU' => 'Yugoslavia','ZM' => 'Zambia','ZW' => 'Zimbabwe' );

		return ( isset( $countries[$code] ) ) ? $countries[$code] : NULL;
	}

	/**
	 * Get plugin settings.
	 */
	private function get_setting( $key ) {
		return get_option( 'wc_settings_woocommerce-fraudlabs-pro_' . $key );
	}

	/**
	 * Update plugin settings.
	 */
	private function update_setting( $key, $value = null ) {
		return update_option( 'wc_settings_woocommerce-fraudlabs-pro_' . $key, $value );
	}

	/**
	 * Hash a string to send to FraudLabs Pro API.
	 */
	private function hash_string( $s ) {
		$hash = 'fraudlabspro_' . $s;

		for( $i = 0; $i < 65536; $i++ ) {
			$hash = sha1( 'fraudlabspro_' . $hash );
		}

		$hash2 = hash('sha256', $hash);

		return $hash2;
	}

	private function create_flpwc_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name = $wpdb->prefix . 'fraudlabspro_wc';

		$sql = "CREATE TABLE $table_name (
			`flp_wc_id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`post_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
			`meta_key` VARCHAR(255) NULL DEFAULT NULL,
			`meta_value` LONGTEXT NULL DEFAULT NULL,
			PRIMARY KEY (`flp_wc_id`) USING BTREE,
			INDEX `post_id` (`post_id`) USING BTREE,
			INDEX `meta_key` (`meta_key`) USING BTREE
		) $charset_collate;";

		$sql1 = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
		$tbExists = $wpdb->get_row($sql1);

		if (!$tbExists) {
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}

		return $table_name;
	}

	private function add_flpwc_data($table_name, $post_id, $key, $value) {
		global $wpdb;

		$meta_subtype = get_object_subtype( 'post', $post_id );
		$key = wp_unslash( $key );
		$value = wp_unslash( $value );
		$value = sanitize_meta( $key, $value, 'post', $meta_subtype );
		$value = maybe_serialize( $value );

		return $wpdb->replace($table_name, array('post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value), array('%s', '%s', '%s'));
	}

	private function get_flpwc_data($table_name, $post_id, $key) {
		global $wpdb;

		$data = [];
		$sql = $wpdb->prepare("SELECT `meta_value` FROM $table_name WHERE post_id = %s AND meta_key = %s LIMIT 1", $post_id, $key);
		$metaValue = $wpdb->get_row($sql);

		if ($metaValue) {
			$data[] = unserialize($metaValue->meta_value);
		}
		return $data;
	}

	private function update_flpwc_data($table_name, $post_id, $key, $value) {
		global $wpdb;

		$meta_subtype = get_object_subtype( 'post', $post_id );
		$key = wp_unslash( $key );
		$value = wp_unslash( $value );
		$value = sanitize_meta( $key, $value, 'post', $meta_subtype );
		$value = maybe_serialize( $value );

		return $wpdb->update($table_name, array('meta_value' => $value), array('post_id' => $post_id, 'meta_key' => $key), array('%s'), array('%s', '%s'));
	}

	/**
	 * Validate a credit card number.
	 */
	private function is_credit_card( $number ) {
		$card_type = null;

		$patterns = array(
			'/^4\d{12}(\d\d\d){0,1}$/'			=> 'visa',
			'/^(5[12345]|2[234567])\d{14}$/'	=> 'mastercard',
			'/^3[47]\d{13}$/'					=> 'amex',
			'/^6011\d{12}$/'					=> 'discover',
			'/^30[012345]\d{11}$/'				=> 'diners',
			'/^3[68]\d{12}$/'					=> 'diners'
		);

		foreach ( $patterns as $regex => $type ) {
			if ( @preg_match( $regex, (string)$number ) ) {
				$card_type = $type;
				break;
			}
		}

		if ( !$card_type ) {
			return false;
		}

		$rev_code = strrev( $number );
		$checksum = 0;

		for ( $i = 0; $i < strlen( $rev_code ); $i++ ) {
			$current = intval ( $rev_code[$i] );

			if ( $i & 1 ) {
				$current *= 2;
			}

			$checksum += $current % 10;

			if ( $current > 9 ) {
				$checksum += 1;
			}
		}

		return ( $checksum % 10 == 0 ) ? true : false;
	}

	/**
	 * Get order notes details.
	 */
	private function get_order_notes( $order_id ) {
		global $wpdb;

		$table_perfixed = $wpdb->prefix . 'comments';
		$results = $wpdb->get_results("
			SELECT * FROM $table_perfixed
			WHERE `comment_post_ID` = $order_id
			AND `comment_type` LIKE 'order_note'
			AND `comment_content` LIKE 'FraudLabs Pro validation completed%'
		");

		if ( count( $results ) > 0 ) {
			foreach ( $results as $note ) {
				$order_note[] = array(
					'note_id'      => $note->comment_ID,
					'note_date'    => $note->comment_date,
					'note_author'  => $note->comment_author,
					'note_content' => $note->comment_content,
				);
			}
			return $order_note;
		} else {
			return false;
		}
	}

	private function create_custom_nonce($action) {
		$nonce = wp_create_nonce($action);
		$nonceExpiry = $nonce . '|' . (time() + 86400);
		return $nonceExpiry;
	}

	private function post($url, $fields = '') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);

		if (!empty($fields)) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($fields)) ? http_build_query($fields) : $fields);
		}

		$response = curl_exec($ch);
		$info = curl_getinfo($ch);

		if (!curl_errno($ch)) {
			return $response;
		} else {
			$this->write_debug_log($info);
			$this->write_debug_log('CURL Error: ' . $url . ' - ' . curl_error($ch));
			return 'CURL Error: ' . curl_error($ch);
		}
	}

	private function http($url, $fields = '') {
		$ch = curl_init();

		if ($fields) {
			$data_string = json_encode($fields);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
		}

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, '1.1');
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($data_string))
		);

		$response = curl_exec($ch);

		if (!curl_errno($ch)) {
			return $response;
		}

		return false;
	}
}

endif;
