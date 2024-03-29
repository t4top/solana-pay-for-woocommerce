<?php
/**
 * The payment gateway class.
 *
 * @package T4top\Solana_Pay_for_WC
 */

namespace T4top\Solana_Pay_for_WC;

// return if WooCommerce payment gateway class is missing
if ( ! class_exists( '\WC_Payment_Gateway' ) ) {
  return;
}

// return if our class is already registered
if ( class_exists( __NAMESPACE__ . '\Solana_Pay_for_WooCommerce' ) ) {
  return;
}

class Solana_Pay_for_WooCommerce extends \WC_Payment_Gateway {

  protected const DEVNET_ENDPOINT = 'https://api.devnet.solana.com';
  protected const CHECKOUT_SESSION_DATA = 'solana_pay_for_wc_session_data';

  /**
   * Array of enqueued scripts
   */
  protected $enqueued_scripts = [];

  public function __construct() {    
    $this->id                 = strtolower( str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) );
    $this->icon               = PLUGIN_URL . '/assets/img/solana_pay_black_gradient.svg';
    $this->has_fields         = false;
    $this->title              = __( 'Solana Pay', 'solana-pay-for-wc' );
    $this->method_title       = $this->title;
    $this->method_description = __( 'Add Solana Pay to your WooCommerce store.', 'solana-pay-for-wc' );

    $this->enabled            = $this->get_option('enabled');
    $this->is_testmode        = $this->get_option('is_testmode');
    $this->merchant_wallet    = $this->get_option('merchant_wallet');
    $this->cryptocurrency     = $this->get_option('cryptocurrency');
    $this->description        = $this->get_option('description');
    $this->instructions       = $this->get_option('instructions');

    // add settings form fields and initialize them
    $this->init_form_fields();
    $this->init_settings();

    
    add_action( "woocommerce_update_options_payment_gateways_$this->id", array( $this, 'process_admin_options' ) );
    add_action( "woocommerce_thank_you_$this->id", array( $this, 'thank_you_page' ) );
    add_action( 'woocommerce_after_checkout_form', array( $this, 'add_custom_payment_modal' ), 10 );
    add_action( "woocommerce_api_$this->id" , array( $this, 'handle_webhook_request' ) );

    add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_files' ) );

    add_filter( 'woocommerce_order_button_html', array( $this, 'add_custom_order_button_html' ) );
    add_filter( 'woocommerce_currency', array( $this, 'change_woocommerce_currency' ) );
    add_filter( 'woocommerce_currencies', array( $this, 'add_woocommerce_currencies' ) );
    add_filter( 'woocommerce_currency_symbol', array( $this, 'add_woocommerce_currency_symbol' ), 10, 2 );
    
    add_filter( 'woocommerce_checkout_fields', array( $this, 'remove_unused_fields' ) );

    add_filter( 'plugin_action_links_' . PLUGIN_BASENAME,  array( $this, 'add_action_links' ) );
  }

  /**
   *
   */
  public function remove_unused_fields( $fields ) {
    $fields['billing']['billing_first_name']['required'] = false;
    $fields['billing']['billing_last_name']['required'] = false;
    $fields['billing']['billing_email']['required'] = false;

    $fields['billing']['billing_company']['required'] = false;
    $fields['billing']['billing_address_1']['required'] = false;
    $fields['billing']['billing_address_2']['required'] = false;
    $fields['billing']['billing_postcode']['required'] = false;
    $fields['billing']['billing_city']['required'] = false;
    $fields['billing']['billing_state']['required'] = false;
    $fields['billing']['billing_phone']['required'] = false;

    // unset( $fields['billing']['billing_company'] );
    // unset( $fields['billing']['billing_address_1'] );
    // unset( $fields['billing']['billing_address_2'] );
    // unset( $fields['billing']['billing_postcode'] );
    // unset( $fields['billing']['billing_city'] );
    // unset( $fields['billing']['billing_state'] );
    // unset( $fields['billing']['billing_phone'] );

    return $fields;
  }

  /**
   * Initialise Gateway Settings Form Fields
   */
  public function init_form_fields() {
    $this->form_fields = apply_filters('solanapay_wc_form_fields',
      array(
        'enabled' => array(
          'title'       => __('Enable/Disable', 'solana-pay-for-wc'),
          'type'        => 'checkbox',
          'label'       => __('Enable Solana Pay for your store.', 'solana-pay-for-wc'),
          'default'     => 'no',
          'desc_tip'    => true,
          'description' => __( 'In order to use Solana Pay processing, this Gateway must be enabled.', 'solana-pay-for-wc' ),
        ),
        'is_testmode' => array(
          'title'       => __('Test Mode', 'solana-pay-for-wc'),
          'type'        => 'checkbox',
          'label'       => __('Enable Test Mode. Must be unchecked for Production.', 'solana-pay-for-wc'),
          'default'     => 'yes',
          'desc_tip'    => true,
          'description' => __('Solana Devnet is used for Test Mode. Mainnet Beta is used for Production.', 'solana-pay-for-wc'),
        ),
        'merchant_wallet' => array(
          'title'       => __('Solana Wallet Address', 'solana-pay-for-wc'),
          'type'        => 'text',
          'default'     => '',
          'desc_tip'    => true,
          'description' => __('Your Solana wallet address where all payments will be sent.', 'solana-pay-for-wc'),
        ),
        'cryptocurrency' => array(
          'title'       => __('Cryptocurrency', 'solanapay_wc'),
          'type'        => 'select',
          'default'     => 'USDC',
          'desc_tip'    => true,
          'description' => __('Select the default cryptocurrency of your products.', 'solanapay_wc'),
          'options'     => array(
                            'USDC' => __( 'USDC', 'solana-pay-for-wc' ),
                            'SOL'  => __( 'SOL', 'solana-pay-for-wc' ),
                           ),
        ),
        'description' => array(
          'title'       => __('Description', 'solana-pay-for-wc'),
          'type'        => 'textarea',
          'default'     => __('Complete your payment with Solana Pay.', 'solana-pay-for-wc'),
          'desc_tip'    => true,
          'description' => __('Payment method description that the customer will see in the checkout.', 'solana-pay-for-wc'),
        ),
        'instructions' => array(
          'title'       => __('Instructions', 'solana-pay-for-wc'),
          'type'        => 'textarea',
          'default'     => __('Thank you for using Solana Pay', 'solana-pay-for-wc'),
          'desc_tip'    => true,
          'description' => __('Delivery or other useful instructions that will be added to the thank you page and order emails.', 'solana-pay-for-wc'),
        ),
      )
    );
  }

  public function change_woocommerce_currency( $currency ) {
    $currency = 'USDC';
    if ( 'SOL' == $this->cryptocurrency ) {
      $currency = 'SOL';
    }
    return $currency;
  }

  // Add crypto tokens supported by Solana Pay to woocommerce currency list
  public function add_woocommerce_currencies( $currencies ) {
    $currencies['USDC'] = __( 'USDC', 'solana-pay-for-wc' );
    $currencies['SOL']  = __( 'SOL (Solana)', 'solana-pay-for-wc' );
    return $currencies;
  }

  public function add_woocommerce_currency_symbol( $currency_symbol, $currency ) {
    switch( $currency ) {
      case 'USDC': $currency_symbol = 'USDC'; break;
      case 'SOL' : $currency_symbol = 'SOL'; break;
    }
    return $currency_symbol;
  }

  /**
   * Add custom action links to Installed Plugins admin page
   */
  public function add_action_links( $links ) {
    array_unshift(
      $links,
      sprintf(
        '<a href="%1$s">%2$s</a>',
        admin_url( "admin.php?page=wc-settings&tab=checkout&section=$this->id" ),
        __( 'Settings', 'solana-pay-for-wc' )
      )
    );

    return $links;
  }

  /**
   * Show the instructions text on the Thank You page.
   */
  public function thank_you_page() {
    if ( $this->instructions ) {
			echo wp_kses_post( wpautop( wptexturize( $this->instructions ) ) );
		}
  }

  /**
   * Add an hidden custom modal for our payment gateway.
   * It will be shown when 'Place order' button is clicked.
   * It also serves as the mount target for the Svelte app.
   */
  public function add_custom_payment_modal() {
    echo get_template_html( '/includes/templates/payment_modal_html.php' );
  }

  /**
   * Add custom 'Place order' button with Solana Pay icon
   */
  public function add_custom_order_button_html( $button ) {
    return get_template_html( '/includes/templates/order_button_html.php', array( 'button' => $button ) );
  }

  /**
   * Enqueue our custom css styles and js scripts
   */
  public function enqueue_files() {
    // enqueue styles
    // foreach( glob( PLUGIN_DIR . '/assets/build/*.css' ) as $file ) {
    //   $file = str_replace( PLUGIN_DIR, '', $file );
    //   enqueue_file( $file );
    // }
    
    // enqueue scripts
    foreach( glob( PLUGIN_DIR . '/assets/build/main*.js' ) as $file ) {
      $file = str_replace( PLUGIN_DIR, '', $file );
      $this->enqueued_scripts[] = enqueue_file( $file, ['jquery'] );
    }
    if ( count( $this->enqueued_scripts ) ) {
      load_enqueued_scripts_as_modules( $this->enqueued_scripts );

      wp_localize_script(
        $this->enqueued_scripts[0],
        'solana_pay_for_wc',
        array(
          'id'       => $this->id,
          'enabled'  => $this->enabled,
          'order'    => $this->get_session_data(),
          'currency' => $this->cryptocurrency,
        )
      );
    }
  }

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
    $order = wc_get_order( $order_id );
    $amount = $order->get_total();
    
		if ( $amount > 0 ) {
			// Mark as processing or on-hold.
			// $order->update_status( apply_filters( 'woocommerce_cod_process_payment_order_status', $order->has_downloadable_item() ? 'on-hold' : 'processing', $order ), __( 'Payment pending.', 'solana-pay-for-wc' ) );

      if ( true != $this->confirm_solana_payment( $order_id ) ) {
        return;
      }
    }

    $order->payment_complete();

    // Remove cart.
    WC()->cart->empty_cart();

    // Return thankyou redirect.
    return array(
      'result'   => 'success',
      'redirect' => $this->get_return_url( $order ),
    );
	}

  public function confirm_solana_payment( $order_id ) {
    $url = self::DEVNET_ENDPOINT;
    $data = $this->get_session_data();
    $reference = $data->reference;
    $nonce = $data->nonce;

    $body = wp_json_encode(
      array(
        'jsonrpc' => '2.0',
        'id'      => 1,
        'method'  => 'getSignaturesForAddress',
        'params'  => array( $reference, array( 'commitment' => 'confirmed' ) )
      )
    );

    $response = wp_remote_post( $url, array(
      'method'      => 'POST',
      'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
      'timeout'     => 45,
      'body'        => $body,
      'data_format' => 'body',
      )
    );

    if ( is_wp_error( $response ) ) {
      $error_message = $response->get_error_message();
      wc_add_notice( __('Payment error:', 'solana-pay-for-wc') . '<p>' . esc_html( $error_message ) . '</p>', 'error' );
    } else {
      $response_code = wp_remote_retrieve_response_code( $response );
  
      if ( 200 === $response_code ) {
        $response_body = wp_remote_retrieve_body( $response );
        $response = json_decode( $response_body, true );
        $result = $response['result'];

        if ( 0 < count( $result ) ) {
          $memo = $result[0]['memo'];

          if ( str_contains( $memo, $nonce ) ) {
            $signature = $result[0]['signature'];
    
            // update order info
            wc_add_order_item_meta( $order_id, 'solana_pay_reference', $reference );
            wc_add_order_item_meta( $order_id, 'solana_pay_signature', $signature );
            wc_add_order_item_meta( $order_id, 'solana_pay_nonce', $nonce );
    
            return true;
          }
        }        
      }
    }

    return false;
  }

  public function update_session_data( $data ) {
    $_SESSION[ self::CHECKOUT_SESSION_DATA ] = json_encode( $data );
  }

  public function get_session_data() {
    start_session();

    if ( isset( $_SESSION[ self::CHECKOUT_SESSION_DATA ] ) ) {
      return json_decode( $_SESSION[ self::CHECKOUT_SESSION_DATA ] );
    }
    
    return array();
  }

  public function handle_webhook_request() {
    $ref = isset( $_GET[ 'ref' ] ) ? $_GET[ 'ref' ] : null;
    if (is_null($ref)) return;

    $data = array(
      'recipient' => $this->merchant_wallet,
      'reference' => $ref,
      'label'     => get_bloginfo( 'name' ),
      'amount'    => WC()->cart->get_cart_contents_total(),
      'currency'  => $this->cryptocurrency,
      'nonce'     => wp_create_nonce(substr(str_shuffle(MD5(microtime())), 0, 12)),
    );

    $this->update_session_data( $data );

    header( 'HTTP/1.1 200 OK' );
    wp_send_json( $data );
    die();
  }

}
