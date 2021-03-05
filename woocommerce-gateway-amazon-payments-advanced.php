<?php // phpcs:ignore
/**
 * Main class and core functions.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/*
 * Plugin Name: WooCommerce Amazon Pay
 * Plugin URI: https://woocommerce.com/products/pay-with-amazon/
 * Description: Amazon Pay is embedded directly into your existing web site, and all the buyer interactions with Amazon Pay and Login with Amazon take place in embedded widgets so that the buyer never leaves your site. Buyers can log in using their Amazon account, select a shipping address and payment method, and then confirm their order. Requires an Amazon Pay seller account and supports USA, UK, Germany, France, Italy, Spain, Luxembourg, the Netherlands, Sweden, Portugal, Hungary, Denmark, and Japan.
 * Version: 2.0.0-alpha7
 * Author: WooCommerce
 * Author URI: https://woocommerce.com
 *
 * Text Domain: woocommerce-gateway-amazon-payments-advanced
 * Domain Path: /languages/
 * Tested up to: 5.5
 * WC tested up to: 4.4
 * WC requires at least: 2.6
 *
 * Copyright: © 2020 WooCommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

define( 'WC_AMAZON_PAY_VERSION', '2.0.0' );

/**
 * Amazon Pay main class
 */
class WC_Amazon_Payments_Advanced {

	/**
	 * Plugin's version.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Plugin's absolute path.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * Plugin's includes path.
	 *
	 * @var string
	 */
	public $includes_path;
	/**
	 * Plugin's URL.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Plugin basename.
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $plugin_basename;

	/**
	 * Amazon Pay settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Amazon Pay Gateway
	 *
	 * @var WC_Gateway_Amazon_Payments_Advanced
	 */
	private $gateway;

	/**
	 * WC logger instance.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * Logger prefix for the whole transaction
	 *
	 * @var string
	 */
	private $logger_prefix;

	/**
	 * Amazon Pay compat handler.
	 *
	 * @since 1.6.0
	 * @var WC_Amazon_Payments_Advanced_Compat
	 */
	private $compat;

	/**
	 * IPN handler.
	 *
	 * @since 1.8.0
	 * @var WC_Amazon_Payments_Advanced_IPN_Handler
	 */
	public $ipn_handler;

	/**
	 * Synchronous handler.
	 *
	 * @since 1.8.0
	 * @var WC_Amazon_Payments_Advanced_Synchronous_Handler
	 */
	public $synchro_handler;

	/**
	 * Simple Path handler.
	 *
	 * @var WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler
	 */
	public $onboarding_handler;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->version         = WC_AMAZON_PAY_VERSION;
		$this->path            = untrailingslashit( plugin_dir_path( __FILE__ ) );
		$this->plugin_url      = untrailingslashit( plugins_url( '/', __FILE__ ) );
		$this->plugin_basename = plugin_basename( __FILE__ );
		$this->includes_path   = $this->path . '/includes/';

		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-merchant-onboarding-handler.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-api-abstract.php';

		include_once $this->includes_path . 'legacy/class-wc-amazon-payments-advanced-api-legacy.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-api.php';

		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-compat.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-ipn-handler-abstract.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-ipn-handler.php';
		include_once $this->includes_path . 'legacy/class-wc-amazon-payments-advanced-ipn-handler-legacy.php';
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-synchronous-handler.php';

		// On install hook.
		include_once $this->includes_path . 'class-wc-amazon-payments-advanced-install.php';
		register_activation_hook( __FILE__, array( 'WC_Amazon_Payments_Advanced_Install', 'install' ) );

		add_action( 'woocommerce_init', array( $this, 'init' ) );

		// REST API support.
		add_action( 'rest_api_init', array( $this, 'rest_api_register_routes' ), 11 );
		add_filter( 'woocommerce_rest_prepare_shop_order', array( $this, 'rest_api_add_amazon_ref_info' ), 10, 2 );

		// IPN handler.
		$this->ipn_handler = new WC_Amazon_Payments_Advanced_IPN_Handler();
		new WC_Amazon_Payments_Advanced_IPN_Handler_Legacy(); // TODO: Maybe register legacy hooks differently
		// Synchronous handler.
		$this->synchro_handler = new WC_Amazon_Payments_Advanced_Synchronous_Handler();
		// Simple path registration endpoint.
		$this->onboarding_handler = new WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler();
		// Third party compatibilities.
		$this->compat = new WC_Amazon_Payments_Advanced_Compat();
	}

	/**
	 * Init.
	 *
	 * @since 1.6.0
	 */
	public function init() {
		$this->settings = WC_Amazon_Payments_Advanced_API::get_settings();

		$this->load_plugin_textdomain();
		if ( is_admin() ) {
			include_once $this->includes_path . 'admin/class-wc-amazon-payments-advanced-admin.php';
			$this->admin = new WC_Amazon_Payments_Advanced_Admin();
		}
		$this->init_gateway();

		do_action( 'woocommerce_amazon_pa_init' );
	}

	/**
	 * Load translations.
	 *
	 * @since 1.6.0
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'woocommerce-gateway-amazon-payments-advanced', false, dirname( $this->plugin_basename ) . '/languages' );
	}

	/**
	 * Init gateway
	 */
	public function init_gateway() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once $this->includes_path . 'class-wc-gateway-amazon-payments-advanced-abstract.php';
		include_once $this->includes_path . 'class-wc-gateway-amazon-payments-advanced-privacy.php';

		$subscriptions_installed = class_exists( 'WC_Subscriptions_Order' ) && function_exists( 'wcs_create_renewal_order' );

		// Check for Subscriptions 2.0, and load support if found.
		if ( $subscriptions_installed && 'yes' === WC_Amazon_Payments_Advanced_API::get_settings( 'subscriptions_enabled' ) ) {

			include_once $this->includes_path . 'class-wc-gateway-amazon-payments-advanced-subscriptions.php';
			include_once $this->includes_path . 'legacy/class-wc-gateway-amazon-payments-advanced-subscriptions-legacy.php';

			$this->subscriptions = new WC_Gateway_Amazon_Payments_Advanced_Subscriptions();
			new WC_Gateway_Amazon_Payments_Advanced_Subscriptions_Legacy();

		}

		if ( WC_Amazon_Payments_Advanced_Merchant_Onboarding_Handler::get_migration_status() ) {
			include_once $this->includes_path . 'class-wc-gateway-amazon-payments-advanced.php';
			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced();
		} else {
			include_once $this->includes_path . 'legacy/class-wc-gateway-amazon-payments-advanced-legacy.php';
			$this->gateway = new WC_Gateway_Amazon_Payments_Advanced_Legacy();
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
	}

	/**
	 * Add Amazon gateway to WC.
	 *
	 * @param array $methods List of payment methods.
	 *
	 * @return array List of payment methods.
	 */
	public function add_gateway( $methods ) {
		$methods[] = $this->gateway;

		return $methods;
	}

	/**
	 * Helper method to get a sanitized version of the site name.
	 *
	 * @return string
	 */
	public static function get_site_name() {
		// Get site setting for blog name.
		$site_name = get_bloginfo( 'name' );
		return self::sanitize_string( $site_name );
	}

	/**
	 * Helper method to get a sanitized version of the site description.
	 *
	 * @return string
	 */
	public static function get_site_description() {
		// Get site setting for blog name.
		$site_description = get_bloginfo( 'description' );
		return self::sanitize_string( $site_description );
	}

	/**
	 * Helper method to get a sanitized version of a string.
	 *
	 * @param $string
	 *
	 * @return string
	 */
	protected static function sanitize_string( $string ) {
		// Decode HTML entities.
		$string = wp_specialchars_decode( $string, ENT_QUOTES );

		// ASCII-ify accented characters.
		$string = remove_accents( $string );

		// Remove non-printable characters.
		$string = preg_replace( '/[[:^print:]]/', '', $string );

		// Clean up leading/trailing whitespace.
		$string = trim( $string );

		return $string;
	}

	/**
	 * Write a message to log if we're in "debug" mode.
	 *
	 * @since 1.6.0
	 *
	 * @param string $context Context for the log.
	 * @param string $message Log message.
	 */
	public function log( $message, $object = null, $context = null ) {
		if ( empty( $this->settings['debug'] ) ) {
			return;
		}

		if ( 'yes' !== $this->settings['debug'] ) {
			return;
		}

		if ( ! is_a( $this->logger, 'WC_Logger' ) ) {
			$this->logger = new WC_Logger();
		}

		if ( empty( $context ) ) {
			$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			array_shift( $backtrace ); // drop current

			$context = isset( $backtrace[0]['function'] ) ? $backtrace[0]['function'] : '';

			if ( isset( $backtrace[0]['class'] ) ) {
				$context = $backtrace[0]['class'] . '::' . $context;
			}
		}

		$log_message = $context . ' - ' . $message;

		if ( ! is_null( $object ) ) {
			if ( is_array( $object ) || is_object( $object ) ) {
				$log_message .= "\n";
				$log_message .= wp_json_encode( $object, JSON_PRETTY_PRINT );
			} elseif ( is_string( $object ) || is_numeric( $object ) ) {
				$log_message .= ' | ' . $object;
			} else {
				$log_message .= ' | ' . var_export( $object, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			}
		}

		if ( ! isset( $this->logger_prefix ) ) {
			$this->logger_prefix = wp_generate_password( 6, false, false );
		}

		$log_message = $this->logger_prefix . ' - ' . $log_message;

		$this->logger->add( 'woocommerce-gateway-amazon-payments-advanced', $log_message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $log_message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Sanitize log message.
	 *
	 * Used to sanitize logged HTTP response message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param mixed $message Log message.
	 *
	 * @return string Sanitized log message.
	 */
	public function sanitize_remote_response_log( $message ) {
		if ( ! is_a( $message, 'SimpleXMLElement' ) ) {
			return (string) $message;
		}

		if ( ! is_callable( array( $message, 'asXML' ) ) ) {
			return '';
		}

		$message = $message->asXML();

		// Sanitize response message.
		$patterns    = array();
		$patterns[0] = '/(<Buyer>)(.+)(<\/Buyer>)/ms';
		$patterns[1] = '/(<PhysicalDestination>)(.+)(<\/PhysicalDestination>)/ms';
		$patterns[2] = '/(<BillingAddress>)(.+)(<\/BillingAddress>)/ms';
		$patterns[3] = '/(<SellerNote>)(.+)(<\/SellerNote>)/ms';
		$patterns[4] = '/(<AuthorizationBillingAddress>)(.+)(<\/AuthorizationBillingAddress>)/ms';
		$patterns[5] = '/(<SellerAuthorizationNote>)(.+)(<\/SellerAuthorizationNote>)/ms';
		$patterns[6] = '/(<SellerCaptureNote>)(.+)(<\/SellerCaptureNote>)/ms';
		$patterns[7] = '/(<SellerRefundNote>)(.+)(<\/SellerRefundNote>)/ms';

		$replacements    = array();
		$replacements[0] = '$1 REMOVED $3';
		$replacements[1] = '$1 REMOVED $3';
		$replacements[2] = '$1 REMOVED $3';
		$replacements[3] = '$1 REMOVED $3';
		$replacements[4] = '$1 REMOVED $3';
		$replacements[5] = '$1 REMOVED $3';
		$replacements[6] = '$1 REMOVED $3';
		$replacements[7] = '$1 REMOVED $3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Sanitize logged request.
	 *
	 * Used to sanitize logged HTTP request message.
	 *
	 * @see https://github.com/woocommerce/woocommerce-gateway-amazon-payments-advanced/issues/133
	 * @since 1.6.0
	 *
	 * @param string $message Log message from stringified array structure.
	 *
	 * @return string Sanitized log message
	 */
	public function sanitize_remote_request_log( $message ) {
		$patterns    = array();
		$patterns[0] = '/(AWSAccessKeyId=)(.+)(&)/ms';
		$patterns[0] = '/(SellerNote=)(.+)(&)/ms';
		$patterns[1] = '/(SellerAuthorizationNote=)(.+)(&)/ms';
		$patterns[2] = '/(SellerCaptureNote=)(.+)(&)/ms';
		$patterns[3] = '/(SellerRefundNote=)(.+)(&)/ms';

		$replacements    = array();
		$replacements[0] = '$1REMOVED$3';
		$replacements[1] = '$1REMOVED$3';
		$replacements[2] = '$1REMOVED$3';
		$replacements[3] = '$1REMOVED$3';

		return preg_replace( $patterns, $replacements, $message );
	}

	/**
	 * Register REST API route for /orders/<order-id>/amazon-payments-advanced/.
	 *
	 * @since 1.6.0
	 */
	public function rest_api_register_routes() {
		// Check to make sure WC is activated and its REST API were loaded
		// first.
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! isset( WC()->api ) ) {
			return;
		}
		if ( ! is_a( WC()->api, 'WC_API' ) ) {
			return;
		}

		require_once $this->includes_path . 'class-wc-amazon-payments-advanced-rest-api-controller.php';

		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller = new WC_Amazon_Payments_Advanced_REST_API_Controller();
		WC()->api->WC_Amazon_Payments_Advanced_REST_API_Controller->register_routes();
	}

	/**
	 * Add Amazon reference information in order item response.
	 *
	 * @since 1.6.0
	 *
	 * @param WP_REST_Response $response Response object.
	 * @param WP_Post          $post     Post object.
	 *
	 * @return WP_REST_Response REST response
	 */
	public function rest_api_add_amazon_ref_info( $response, $post ) {
		if ( 'amazon_payments_advanced' === $response->data['payment_method'] ) {
			$response->data['amazon_reference'] = array(

				'amazon_reference_state'     => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_reference_state' ),
				'amazon_reference_id'        => get_post_meta( $post->ID, 'amazon_reference_id', true ),
				'amazon_authorization_state' => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_authorization_state' ),
				'amazon_authorization_id'    => get_post_meta( $post->ID, 'amazon_authorization_id', true ),
				'amazon_capture_state'       => WC_Amazon_Payments_Advanced_API::get_order_ref_state( $post->ID, 'amazon_capture_state' ),
				'amazon_capture_id'          => get_post_meta( $post->ID, 'amazon_capture_id', true ),
				'amazon_refund_ids'          => get_post_meta( $post->ID, 'amazon_refund_id', false ),
			);
		}

		return $response;
	}

	/**
	 * Return instance of WC_Gateway_Amazon_Payments_Advanced.
	 *
	 * @since 2.0.0
	 *
	 * @return WC_Gateway_Amazon_Payments_Advanced
	 */
	public function get_gateway() {
		return $this->gateway;
	}
}

/**
 * Return instance of WC_Amazon_Payments_Advanced.
 *
 * @since 1.6.0
 *
 * @return WC_Amazon_Payments_Advanced
 */
function wc_apa() {
	static $plugin;

	if ( ! isset( $plugin ) ) {
		$plugin = new WC_Amazon_Payments_Advanced();
	}

	return $plugin;
}

/**
 * Get order property with compatibility for WC lt 3.0.
 *
 * @since 1.7.0
 *
 * @param WC_Order $order Order object.
 * @param string   $key   Order property.
 *
 * @return mixed Value of order property.
 */
function wc_apa_get_order_prop( $order, $key ) {
	switch ( $key ) {
		case 'order_currency':
			return is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : $order->get_order_currency();
		default:
			$getter = array( $order, 'get_' . $key );
			return is_callable( $getter ) ? call_user_func( $getter ) : $order->{ $key };
	}
}

// Provides backward compatibility.
$GLOBALS['wc_amazon_payments_advanced'] = wc_apa();
