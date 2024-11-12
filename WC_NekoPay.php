<?php

if (class_exists('WC_Payment_Gateway')) {
    class WC_NekoPay extends WC_Payment_Gateway {

        const CRYPTO_API_URLS = [
            "dogecash" => "https://widgets.nekosunevr.co.uk/Payment-Checker/DOGEC.php",
            "zenzo"    => "https://widgets.nekosunevr.co.uk/Payment-Checker/ZNZ.php",
            "flits"    => "https://widgets.nekosunevr.co.uk/Payment-Checker/FLS.php",
            "pivx"     => "https://widgets.nekosunevr.co.uk/Payment-Checker/PIVX.php"
        ];

        public function __construct() {
            $this->id = 'nekopay_payment';
            $this->method_title = __('NekoPay Multi-Crypto Payment', 'woocommerce-nekopay');
            $this->method_description = __('NekoPay Payment Gateway allows you to receive payments in DogeCash, Zenzo, Flits, and PIVX cryptocurrencies', 'woocommerce-nekopay');
            $this->has_fields = true;
            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->crypto_type = $this->get_option('crypto_type');
            $this->confirmation_no = $this->get_option('confirmation_no');
            $this->max_time_limit = $this->get_option('max_time_limit');
            $this->wallet_addresses = [
                'dogecash' => $this->get_option('dogecash_wallet_address'),
                'zenzo'    => $this->get_option('zenzo_wallet_address'),
                'flits'    => $this->get_option('flits_wallet_address'),
                'pivx'     => $this->get_option('pivx_wallet_address')
            ];
            $this->default_currency_used = get_woocommerce_currency();
            $this->exchange_rate = $this->get_exchange_rate($this->crypto_type);
            $this->plugin_version = "1.1.0";

            $this->nekopay_remove_filter('template_redirect', 'maybe_setup_cart', 100);
            $this->supports = ['products', 'subscriptions'];

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
    $this->form_fields = array(
        'enabled' => array(
            'title'   => __( 'Enable/Disable', 'woocommerce-nekopay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable NekoPay Cryptocurrency Payment', 'woocommerce-nekopay' ),
            'default' => 'yes'
        ),
        'title' => array(
            'title'   => __( 'Method Title', 'woocommerce-nekopay' ),
            'type'    => 'text',
            'default' => __( 'Cryptocurrency Payment', 'woocommerce-nekopay' ),
            'desc_tip' => __( 'Title displayed on the checkout page.', 'woocommerce-nekopay' )
        ),
        'description' => array(
            'title' => __( 'Payment Description', 'woocommerce-nekopay' ),
            'type' => 'text',
            'default' => 'Select a cryptocurrency to pay with and send the exact amount to the address below.',
            'desc_tip' => __( 'Description displayed on the payment page.', 'woocommerce-nekopay' ),
        ),
        'payment_addresses' => array(
            'title' => __( 'Wallet Addresses', 'woocommerce-nekopay' ),
            'type'  => 'title',
            'description' => __( 'Enter wallet addresses for each cryptocurrency.', 'woocommerce-nekopay' ),
        ),
        'dogecash_address' => array(
            'title' => __( 'DogeCash Wallet Address', 'woocommerce-nekopay' ),
            'type'  => 'text',
            'desc_tip' => __( 'DogeCash wallet address.', 'woocommerce-nekopay' ),
        ),
        'zenzo_address' => array(
            'title' => __( 'Zenzo Wallet Address', 'woocommerce-nekopay' ),
            'type'  => 'text',
            'desc_tip' => __( 'Zenzo wallet address.', 'woocommerce-nekopay' ),
        ),
        'flits_address' => array(
            'title' => __( 'Flits Wallet Address', 'woocommerce-nekopay' ),
            'type'  => 'text',
            'desc_tip' => __( 'Flits wallet address.', 'woocommerce-nekopay' ),
        ),
        'pivx_address' => array(
            'title' => __( 'PIVX Wallet Address', 'woocommerce-nekopay' ),
            'type'  => 'text',
            'desc_tip' => __( 'PIVX wallet address.', 'woocommerce-nekopay' ),
        ),
        'confirmation_no' => array(
            'title' => __( 'Minimum Confirmations', 'woocommerce-nekopay' ),
            'type' => 'text',
            'default' => '5',
            'desc_tip' => __( 'Number of confirmations for order completion.', 'woocommerce-nekopay' ),
        ),
        'max_time_limit' => array(
            'title' => __( 'Maximum Payment Time (in Minutes)', 'woocommerce-nekopay' ),
            'type' => 'text',
            'default' => "15",
            'desc_tip' => __( 'Time allowed for customer to complete payment.', 'woocommerce-nekopay' ),
        ),
    );
}

        public function admin_options() {
            ?>
            <h3><?php _e('NekoPay Payment Settings', 'woocommerce-nekopay'); ?></h3>
            <p>NekoPay Payment Gateway allows you to receive payments in DogeCash, Zenzo, Flits, or PIVX cryptocurrencies.</p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        // Save the selected cryptocurrency in order meta
public function process_payment( $order_id ) {
    $order = wc_get_order( $order_id );
    $selected_crypto = sanitize_text_field( $_POST['nekopay_crypto_type'] );

    // Save the selected crypto type and related wallet address in order meta
    $order->update_meta_data('_nekopay_crypto_type', $selected_crypto);
    $order->update_meta_data('_nekopay_payment_address', $this->get_option("{$selected_crypto}_address"));
    $order->save();

    return array(
        'result'   => 'success',
        'redirect' => $order->get_checkout_payment_url( true ),
    );
}
        // Show the crypto selection dropdown on the checkout page
public function payment_fields() {
    ?>
    <fieldset>
        <p><?php echo wpautop( esc_html( $this->get_option('description') ) ); ?></p>
        <label for="nekopay_crypto_type"><?php _e( 'Select Cryptocurrency:', 'woocommerce-nekopay' ); ?></label>
        <select id="nekopay_crypto_type" name="nekopay_crypto_type">
            <?php if ( $this->get_option('dogecash_address') ) : ?>
                <option value="dogecash"><?php _e( 'DogeCash', 'woocommerce-nekopay' ); ?></option>
            <?php endif; ?>
            <?php if ( $this->get_option('zenzo_address') ) : ?>
                <option value="zenzo"><?php _e( 'Zenzo', 'woocommerce-nekopay' ); ?></option>
            <?php endif; ?>
            <?php if ( $this->get_option('flits_address') ) : ?>
                <option value="flits"><?php _e( 'Flits', 'woocommerce-nekopay' ); ?></option>
            <?php endif; ?>
            <?php if ( $this->get_option('pivx_address') ) : ?>
                <option value="pivx"><?php _e( 'PIVX', 'woocommerce-nekopay' ); ?></option>
            <?php endif; ?>
        </select>
    </fieldset>
    <?php
}

        public function get_exchange_rate($crypto_type) {
            $api_url = self::CRYPTO_API_URLS[$crypto_type] ?? '';

            if (empty($api_url)) return false;

            $response = wp_remote_get($api_url . "?rate=" . strtolower($this->default_currency_used));
            $price = json_decode(wp_remote_retrieve_body($response));
            $rate = $price[0]->current_price ?? 0;

            return $rate > 0 ? trim($rate) : 0;
        }

        public function nekopay_remove_filter($hook_name = '', $method_name = '', $priority = 0) {
            global $wp_filter;

            if (isset($_GET['pay_for_order'], $_GET['key'], $_GET['cp'])) {
                if (!isset($wp_filter[$hook_name][$priority]) || !is_array($wp_filter[$hook_name][$priority])) {
                    return false;
                }

                foreach ((array) $wp_filter[$hook_name][$priority] as $unique_id => $filter_array) {
                    if (isset($filter_array['function']) && is_array($filter_array['function'])) {
                        if (is_object($filter_array['function'][0]) && get_class($filter_array['function'][0]) && $filter_array['function'][1] == $method_name) {
                            if (is_a($wp_filter[$hook_name], 'WP_Hook')) {
                                unset($wp_filter[$hook_name]->callbacks[$priority][$unique_id]);
                            } else {
                                unset($wp_filter[$hook_name][$priority][$unique_id]);
                            }
                        }
                    }
                }
            }

            return false;
        }
    }
}
?>
