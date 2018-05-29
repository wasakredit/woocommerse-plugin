<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit(); // Exit if accessed directly
}

require_once plugin_dir_path( __FILE__ ) . '../php-checkout-sdk/Wasa.php';

add_action( 'plugins_loaded', 'init_wasa_kredit_gateway' );
add_filter( 'woocommerce_payment_gateways', 'add_wasa_kredit_gateway' );

function add_wasa_kredit_gateway( $methods ) {
	$methods[] = 'WC_Gateway_Wasa_Kredit';

	return $methods;
}

function init_wasa_kredit_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class WC_Gateway_Wasa_Kredit extends WC_Payment_Gateway {
		public function __construct() {
			// Setup payment gateway properties
			$this->id                 = 'wasa_kredit';
			$this->plugin_id          = 'wasa_kredit';
			$this->name               = 'Wasa Kredit';
			$this->title              = 'Wasa Kredit';
			$this->method_title       = 'Wasa Kredit';
			$this->description        = 'Use to pay with Wasa Kredit Checkout.';
			$this->method_description = 'Use to pay with Wasa Kredit Checkout.';
			$this->order_button_text  = __( 'Proceed', 'wasa-kredit-checkout' );
			$this->selected_currency  = get_woocommerce_currency();

			// Where to store settings in DB
			$this->options_key = 'wasa_kredit_settings';

			$this->form_fields = $this->init_form_fields();
			$this->init_settings();

			// Setup dynamic gateway properties
			if ( $this->settings['enabled'] ) {
				$this->enabled = $this->settings['enabled'];
			}

			if ( $this->settings['description'] ) {
				$this->description = $this->settings['description'];
			}

			// Connect to WASA PHP SDK
			$this->_client = new Sdk\Client(
				$this->settings['partner_id'],
				$this->settings['client_secret'],
				'yes' === $this->settings['test_mode'] ? true : false
			);

			// Hooks
			add_action(
				'woocommerce_update_options_payment_gateways_' . $this->id,
				array( $this, 'process_admin_options' )
			);
		}

		public function init_settings() {
			$this->settings = get_option( $this->options_key, null );

			// If there are no settings defined, use defaults.
			if ( ! is_array( $this->settings ) ) {
				$form_fields = $this->get_form_fields();

				$this->settings = array_merge(
					array_fill_keys( array_keys( $form_fields ), '' ),
					wp_list_pluck( $form_fields, 'default' )
				);
			}
		}

		public function init_form_fields() {
			// Defines settings fields on WooCommerce > Settings > Checkout > Wasa Kredit
			return array(
				'enabled'                   => array(
					'title'   => __( 'Enable/Disable', 'wasa-kredit-checkout' ),
					'type'    => 'checkbox',
					'label'   => __(
						'Enable Wasa Kredit Checkout',
						'wasa-kredit-checkout'
					),
					'default' => 'yes',
				),
				'description'               => array(
					'title'       => __( 'Description', 'wasa-kredit-checkout' ),
					'type'        => 'textarea',
					'description' => __(
						'This controls the description which the user sees during checkout.',
						'wasa-kredit-checkout'
					),
					'default'     => __(
						'Pay via Wasa Kredit Checkout.',
						'wasa-kredit-checkout'
					),
				),
				'cart_on_checkout'          => array(
					'title'   => __( 'Enable/Disable', 'wasa-kredit-checkout' ),
					'type'    => 'checkbox',
					'label'   => __(
						'Show cart content on checkout',
						'wasa-kredit-checkout'
					),
					'default' => 'no',
				),
				'widget_on_product_list'    => array(
					'title'       => __( 'Enable/Disable', 'wasa-kredit-checkout' ),
					'type'        => 'checkbox',
					'label'       => __(
						'Show monthly cost in product list',
						'wasa-kredit-checkout'
					),
					'description' => __(
						'Will be shown under the price in product listings. You can also use the shortcode [wasa_kredit_list_widget]',
						'wasa-kredit-checkout'
					),
					'default'     => 'yes',
				),
				'widget_on_product_details' => array(
					'title'       => __( 'Enable/Disable', 'wasa-kredit-checkout' ),
					'type'        => 'checkbox',
					'label'       => __(
						'Show monthly cost in product details',
						'wasa-kredit-checkout'
					),
					'description' => __(
						'Will be shown between the price and the add to cart button. You can also use the shortcode [wasa_kredit_product_widget] whereever you want.',
						'wasa-kredit-checkout'
					),
					'default'     => 'yes',
				),
				'partner_id'                => array(
					'title'       => __( 'Partner ID', 'wasa-kredit-checkout' ),
					'type'        => 'text',
					'description' => __(
						'Partner ID is issued by Wasa Kredit.',
						'wasa-kredit-checkout'
					),
					'default'     => '',
				),
				'client_secret'             => array(
					'title'       => __( 'Client secret', 'wasa-kredit-checkout' ),
					'type'        => 'text',
					'description' => __(
						'Client Secret is issued by Wasa Kredit.',
						'wasa-kredit-checkout'
					),
					'default'     => '',
				),
				'test_mode'                 => array(
					'title'       => __( 'Test mode', 'wasa-kredit-checkout' ),
					'type'        => 'checkbox',
					'label'       => __( 'Enable test mode', 'wasa-kredit-checkout' ),
					'default'     => 'yes',
					'description' => __(
						'This controls if the test API should be called or not. Do not use in production.',
						'wasa-kredit-checkout'
					),
				),
			);
		}

		public function process_admin_options() {
			// On save in admin settings
			$this->init_settings();

			$post_data = $this->get_post_data();

			foreach ( $this->get_form_fields() as $key => $field ) {
				if ( 'title' !== $this->get_field_type( $field ) ) {
					try {
						$this->settings[ $key ] = $this->get_field_value(
							$key,
							$field,
							$post_data
						);
					} catch ( Exception $e ) {
						$this->add_error( $e->getMessage() );
					}
				}
			}

			return update_option(
				$this->options_key,
				apply_filters(
					'woocommerce_settings_api_sanitized_fields_' . $this->id,
					$this->settings
				)
			);
		}

		public function is_available() {
			// If payment gateway should be available for customers

			$enabled = $this->get_option( 'enabled' );
			// Plugin is enabled
			if ( $enabled !== 'yes' ) {
				return false;
			}

			$cart_totals            = WC()->cart->get_totals();
			$cart_total             = $cart_totals['subtotal'] + ( $cart_totals['shipping_total'] - $cart_totals['shipping_tax'] );
			$financed_amount_status = $this->_client->validate_financed_amount( $cart_total );

			// Cart value is within partner limits
			if ( ! isset( $financed_amount_status )
				|| ( $financed_amount_status->statusCode != 200
				|| ! $financed_amount_status->data['validation_result'] ) ) {
				// If total order value is too small or too large
				return false;
			}

			$shipping_country = WC()->customer->get_billing_country();
			$currency         = get_woocommerce_currency();

			// Country is Sweden and currency is Swedish krona
			if ($shipping_country !== "SE" || $currency !== "SEK" ) {
				return false;
			}

			// Everything is fine, show payment method.
			return true;
		}

		public function process_payment( $order_id ) {
			// When clicking Proceed button, create a on-hold order
			global $woocommerce;
			$order = new WC_Order( $order_id );

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		public function get_return_url( $order = null ) {
			// Add order key to custom endpoint route as query param
			return add_query_arg( 'wasa_kredit_checkout', $order->order_key, get_site_url() );
		}

		public function get_title() {
			//Set custom title to payment to display in checkout
			if ( isset( WC()->cart ) ) {
				$cart_totals = WC()->cart->get_totals();

				$total_costs = $cart_totals['subtotal'] + ( $cart_totals['shipping_total'] - $cart_totals['shipping_tax'] );

				$payload['items'][] = array(
					'financed_price' => array(
						'amount'   => $total_costs,
						'currency' => 'SEK',
					),
					'product_id'     => 'CART_VALUE',
				);

				$monthly_cost_response = $this->_client->calculate_monthly_cost( $payload );

				if ( isset( $monthly_cost_response ) && $monthly_cost_response->statusCode == 200 ) {
					$monthly_cost = $monthly_cost_response->data['monthly_costs'][0]['monthly_cost']['amount'];

					return __( 'Financing', 'wasa-kredit-checkout' ) . ' ' . wc_price( $monthly_cost, array( 'decimals' => 0 ) ) . __( '/month', 'wasa-kredit-checkout' );
				}
			}

			return __( 'Financing with Wasa Kredit Checkout', 'wasa-kredit-checkout' );
		}
	}
}
