<?php
/*
    Plugin Name: NekoPay Multi-Crypto Payment Gateway for WooCommerce
    Description: Multi-Cryptocurrency Payment Gateway for Zenzo, DogeCash, Flits, and PIVX with tracking and verification.
    Version: 1.1.0
    Author: NekoPay
    Author URI: https://nekosunevr.co.uk
    Plugin URI: https://github.com/nekosunevr/nekopay-woo-plugin
    Developer: NekoPay
*/

// Define constants
const CRYPTO_API_URLS = [
    "dogecash" => "https://widgets.nekosunevr.co.uk/Payment-Checker/DOGEC.php",
    "zenzo"    => "https://widgets.nekosunevr.co.uk/Payment-Checker/ZENZO.php",
    "flits"    => "https://widgets.nekosunevr.co.uk/Payment-Checker/FLITS.php",
    "pivx"     => "https://widgets.nekosunevr.co.uk/Payment-Checker/PIVX.php"
];
const NEKOPAY_ORDERS_TABLE_NAME = "nekopay_cryptocurrency_orders";

// Function to create transaction table on plugin activation
function nekopay_create_transactions_table() {
    global $wpdb;
    $db_table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $db_table_name (
              id int(11) NOT NULL AUTO_INCREMENT,
              transaction_id varchar(150) DEFAULT NULL,
              payment_address varchar(150),
              order_id varchar(250),
              crypto_type varchar(50),
              order_status varchar(250),
              order_time varchar(250),
              order_total varchar(50),
              order_in_crypto varchar(50),
              order_default_currency varchar(50),
              order_crypto_exchange_rate varchar(50),
              confirmation_no int(11) DEFAULT NULL,
              PRIMARY KEY  (id)
          ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'nekopay_create_transactions_table');

// Check WooCommerce dependency and notify if missing
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))) || class_exists('WooCommerce')) {

    // Initialize payment gateway
    add_action('plugins_loaded', 'nekopay_init_payment_gateway');
    function nekopay_init_payment_gateway() {
        require 'WC_NekoPay.php';
        add_filter('woocommerce_payment_gateways', 'nekopay_add_nekopay_crypto_gateway');
    }

    // Register the payment gateway
    function nekopay_add_nekopay_crypto_gateway($gateways) {
        $gateways[] = 'WC_NekoPay';
        return $gateways;
    }

} else {
    // Admin notice if WooCommerce is not installed
    add_action('admin_notices', 'nekopay_admin_notice');
    function nekopay_admin_notice() {
        echo "<div class='error'><p><strong>Please install WooCommerce before using NekoPay Multi-Crypto Payment Gateway.</strong></p></div>";
    }
}

// Enqueue plugin scripts and styles on specific WooCommerce pages
function nekopay_load_cp_scripts() {
    if (is_wc_endpoint_url('order-pay')) {
        wp_enqueue_style('cp-styles', plugins_url('css/cp-styles.css', __FILE__));
        wp_enqueue_script('cp-script', plugins_url('js/cp-script.js', __FILE__));
    }
}
add_action('wp_enqueue_scripts', 'nekopay_load_cp_scripts');

// Process payment order and store data in database
function nekopay_process_order($order_id) {
    global $wp, $wpdb;
    $order = wc_get_order($order_id);

    if (!$order) {
        return;
    }

    $wc_nekopay = new WC_NekoPay;
    $crypto_type = $order->get_meta('_nekopay_crypto_type');
    $order_crypto_exchange_rate = $wc_nekopay->get_exchange_rate($crypto_type);

    if ($order_crypto_exchange_rate == 0) {
        wc_add_notice('Issue fetching current rates. Please try again.', 'error');
        return;
    }

    $payment_address = $wc_nekopay->get_option($crypto_type . '_address');
    $order_total = $order->get_total();
    $order_in_crypto = nekopay_order_total_in_crypto($order_total, $order_crypto_exchange_rate);
    $order_currency = $order->get_currency();

    // Insert transaction into database if not already present
    $table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;
    $existing_transaction = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE order_id = %d", $order_id));
    if ($existing_transaction == 0) {
        $wpdb->insert($table_name, [
            'transaction_id'            => "",
            'payment_address'           => $payment_address,
            'order_id'                  => $order_id,
            'crypto_type'               => $crypto_type,
            'order_total'               => $order_total,
            'order_in_crypto'           => $order_in_crypto,
            'order_default_currency'    => $order_currency,
            'order_crypto_exchange_rate'=> $order_crypto_exchange_rate,
            'order_status'              => 'pending',
            'order_time'                => time()
        ]);
    }
}
add_action("before_woocommerce_pay", "nekopay_process_order", 20);

// Verify payment and update order status
function nekopay_verify_payment() {
    global $wpdb;
    $order_id = intval(sanitize_text_field($_POST['order_id']));
    $order = wc_get_order($order_id);

    if (!$order) {
        echo json_encode(["status" => "failed", "message" => "Order not found."]);
        wp_die();
    }

    $cp_order = nekopay_get_cp_order_info($order_id);
    $crypto_type = $cp_order->crypto_type;
    $api_url = CRYPTO_API_URLS[$crypto_type] ?? '';

    if (empty($api_url)) {
        echo json_encode(["status" => "failed", "message" => "Invalid cryptocurrency selected"]);
        wp_die();
    }

    $response = wp_remote_get($api_url . "?address=" . $cp_order->payment_address . "&tx=" . $cp_order->transaction_id . "&amount=" . $cp_order->order_in_crypto . "&conf=" . $cp_order->confirmation_no);
    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body);

    if ($response_data && $response_data->status == "confirmed") {
        // Update transaction and order status
        $wpdb->update($wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME, [
            'order_status' => 'confirmed'
        ], ['order_id' => $order_id]);

        $order->update_status('processing');
    }

    echo json_encode($response_data);
    wp_die();
}
add_action("wp_ajax_nekopay_verify_payment", "nekopay_verify_payment");
add_action("wp_ajax_nopriv_nekopay_verify_payment", "nekopay_verify_payment");

// Retrieve cryptocurrency order info from database
function nekopay_get_cp_order_info($order_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id));
}

// Utility to calculate order total in cryptocurrency
function nekopay_order_total_in_crypto($order_total, $exchange_rate) {
    return round($order_total / $exchange_rate, 8);
}
?>
