<?php

if (class_exists('WC_Payment_Gateway')) {
    class WC_NekoPay extends WC_Payment_Gateway {

        private $crypto_options = [
            'dogecash' => [
                'API_URL' => "https://payment-checker.chisdealhd.co.uk/DOGEC.php",
                'default_address' => '',
                'title' => 'DogeCash'
            ],
            'zenzo' => [
                'API_URL' => "https://payment-checker.chisdealhd.co.uk/ZENZO.php",
                'default_address' => '',
                'title' => 'Zenzo'
            ],
            'flits' => [
                'API_URL' => "https://payment-checker.chisdealhd.co.uk/FLITS.php",
                'default_address' => '',
                'title' => 'Flits'
            ],
            'pivx' => [
                'API_URL' => "https://payment-checker.chisdealhd.co.uk/PIVX.php",
                'default_address' => '',
                'title' => 'PIVX'
            ]
        ];

        public function __construct() {
            $this->id = 'multi_crypto_payment';
            $this->method_title = __('Multi-Cryptocurrency Payment', 'woocommerce-multi-crypto');
            $this->method_description = __('Accept payments in Zenzo, DogeCash, Flits, or PIVX', 'woocommerce-multi-crypto');
            $this->has_fields = true;
            $this->init_form_fields();
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->confirmation_no = $this->get_option('confirmation_no');
            $this->max_time_limit = $this->get_option('max_time_limit');

            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'woocommerce-multi-crypto'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Multi-Cryptocurrency Payment', 'woocommerce-multi-crypto'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __('Title', 'woocommerce-multi-crypto'),
                    'type'        => 'text',
                    'default'     => __('Cryptocurrency Payment', 'woocommerce-multi-crypto'),
                ),
                'description' => array(
                    'title'       => __('Description', 'woocommerce-multi-crypto'),
                    'type'        => 'textarea',
                    'default'     => __('Pay with your preferred cryptocurrency.', 'woocommerce-multi-crypto'),
                ),
                'confirmation_no' => array(
                    'title'       => __('Minimum Confirmations', 'woocommerce-multi-crypto'),
                    'type'        => 'text',
                    'default'     => '5',
                ),
                'max_time_limit' => array(
                    'title'       => __('Max Payment Time (in Minutes)', 'woocommerce-multi-crypto'),
                    'type'        => 'text',
                    'default'     => '15',
                )
            );

            foreach ($this->crypto_options as $crypto_key => $crypto_data) {
                $this->form_fields[$crypto_key . '_address'] = array(
                    'title' => sprintf(__('%s Wallet Address', 'woocommerce-multi-crypto'), $crypto_data['title']),
                    'type'  => 'text',
                    'description' => sprintf(__('Wallet address for %s payments.', 'woocommerce-multi-crypto'), $crypto_data['title']),
                    'desc_tip' => true,
                );
            }
        }

        public function payment_fields() {
            echo '<fieldset><p>' . esc_html($this->description) . '</p>';
            echo '<label for="crypto_select">Choose Cryptocurrency:</label>';
            echo '<select name="crypto_select" id="crypto_select">';
            foreach ($this->crypto_options as $crypto_key => $crypto_data) {
                echo '<option value="' . esc_attr($crypto_key) . '">' . esc_html($crypto_data['title']) . '</option>';
            }
            echo '</select></fieldset>';
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $crypto_selected = sanitize_text_field($_POST['crypto_select']);
            $api_url = $this->crypto_options[$crypto_selected]['API_URL'];
            $payment_address = $this->get_option($crypto_selected . '_address');
            $exchange_rate = $this->get_exchange_rate($api_url);

            // Calculate order total in selected cryptocurrency
            $order_total_crypto = $order->get_total() / $exchange_rate;

            // Save the selected cryptocurrency in order metadata
            $order->update_meta_data('_crypto_selected', $crypto_selected);
            $order->update_meta_data('_crypto_payment_address', $payment_address);
            $order->update_meta_data('_crypto_order_total', $order_total_crypto);
            $order->save();

            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true) . '&crypto=' . urlencode($crypto_selected)
            ];
        }

        private function get_exchange_rate($api_url) {
            $response = wp_remote_get($api_url . "?rate=" . strtolower(get_woocommerce_currency()));
            $data = json_decode(wp_remote_retrieve_body($response), true);
            return $data['current_price'] ?? 0;
        }
    }
}

add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_NekoPay';
    return $gateways;
});
