<?php

if (class_exists('WC_Payment_Gateway')) {
    class WC_NekoPay extends WC_Payment_Gateway {

        const CRYPTO_API_URLS = [
            "dogecash" => "https://payment-checker.chisdealhd.co.uk/DOGEC.php",
            "zenzo"    => "https://payment-checker.chisdealhd.co.uk/ZNZ.php",
            "flits"    => "https://payment-checker.chisdealhd.co.uk/FLS.php",
            "pivx"     => "https://payment-checker.chisdealhd.co.uk/PIVX.php"
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
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Enable/Disable', 'woocommerce-nekopay'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable NekoPay Cryptocurrency Payment', 'woocommerce-nekopay'),
                    'default' => 'yes'
                ],
                'title' => [
                    'title'       => __('Method Title', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'default'     => __('NekoPay Cryptocurrency Payment', 'woocommerce-nekopay'),
                    'desc_tip'    => __('The payment method title which you want to appear to the customer in the checkout page.')
                ],
                'description' => [
                    'title'       => __('Payment Description', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'default'     => 'Please send the exact amount to the selected cryptocurrency address below.',
                    'desc_tip'    => __('The payment description message to appear to the customer on the payment page.')
                ],
                'crypto_type' => [
                    'title'       => __('Select Cryptocurrency', 'woocommerce-nekopay'),
                    'type'        => 'select',
                    'options'     => [
                        'dogecash' => 'DogeCash',
                        'zenzo'    => 'Zenzo',
                        'flits'    => 'Flits',
                        'pivx'     => 'PIVX'
                    ],
                    'default'     => 'dogecash',
                    'desc_tip'    => __('Choose the cryptocurrency for this payment method.')
                ],
                'dogecash_wallet_address' => [
                    'title'       => __('DogeCash Wallet Address', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'desc_tip'    => __('Enter your DogeCash wallet address for payments.')
                ],
                'zenzo_wallet_address' => [
                    'title'       => __('Zenzo Wallet Address', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'desc_tip'    => __('Enter your Zenzo wallet address for payments.')
                ],
                'flits_wallet_address' => [
                    'title'       => __('Flits Wallet Address', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'desc_tip'    => __('Enter your Flits wallet address for payments.')
                ],
                'pivx_wallet_address' => [
                    'title'       => __('PIVX Wallet Address', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'desc_tip'    => __('Enter your PIVX wallet address for payments.')
                ],
                'confirmation_no' => [
                    'title'       => __('Minimum Confirmations', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'default'     => '5',
                    'desc_tip'    => __('Number of confirmations required for the order to be confirmed.')
                ],
                'max_time_limit' => [
                    'title'       => __('Maximum Payment Time (in Minutes)', 'woocommerce-nekopay'),
                    'type'        => 'text',
                    'default'     => "15",
                    'desc_tip'    => __('Time allowed for a user to make the required payment.')
                ]
            ];
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

        public function process_payment($order_id) {
            global $woocommerce;
            $order = new WC_Order($order_id);

            wc_reduce_stock_levels($order_id);
            $woocommerce->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url($on_checkout = false) . '&cp=1'
            ];
        }

        public function payment_fields() {
            ?>
            <fieldset style="padding: 0.75em 0.625em 0.75em;">
                <table>
                    <tr style="vertical-align: middle; text-align: left;">
                        <td width="180">
                            <img alt="plugin logo" width="160" style="max-height: 40px;" src="<?php echo plugins_url('img/plugin-logo.png', __FILE__); ?>">
                        </td>
                        <td>
                            <div>Exchange rate:</div>
                            <strong>1 <?php echo strtoupper($this->crypto_type); ?> = <?php echo round($this->exchange_rate, 5); ?> <?php echo $this->default_currency_used; ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div>Payment Address:</div>
                            <strong><?php echo esc_html($this->wallet_addresses[$this->crypto_type]); ?></strong>
                        </td>
                    </tr>
                </table>
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
