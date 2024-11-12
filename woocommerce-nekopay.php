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

const CRYPTO_API_URLS = [
    "dogecash" => "https://payment-checker.chisdealhd.co.uk/DOGEC.php",
    "zenzo" => "https://payment-checker.chisdealhd.co.uk/ZENZO.php",
    "flits" => "https://payment-checker.chisdealhd.co.uk/FLITS.php",
    "pivx" => "https://payment-checker.chisdealhd.co.uk/PIVX.php"
];
const NEKOPAY_ORDERS_TABLE_NAME = "nekopay_cryptocurrency_orders";

function nekopay_create_transactions_table()
{
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


// Check if WooCommerce is active
$active_plugins = apply_filters('active_plugins', get_option('active_plugins'));

if (in_array('woocommerce/woocommerce.php', $active_plugins) || class_exists('WooCommerce')) {

    register_activation_hook(__FILE__, 'nekopay_create_transactions_table');
    add_filter('woocommerce_payment_gateways', 'nekopay_add_nekopay_crypto_gateway');

    function nekopay_add_nekopay_crypto_gateway($gateways)
    {
        $gateways[] = 'WC_NekoPay';
        return $gateways;
    }

    add_action('plugins_loaded', 'nekopay_init_payment_gateway');

    function nekopay_init_payment_gateway()
    {
        require 'WC_NekoPay.php';
    }
} else {
    function nekopay_admin_notice()
    {
        echo "<div class='error'><p><strong>Please install WooCommerce before using NekoPay Multi-Crypto Payment Gateway.</strong></p></div>";
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die();
    }
    add_action('admin_notices', 'nekopay_admin_notice');
}


// Load plugin scripts
function nekopay_load_cp_scripts()
{
    if (is_wc_endpoint_url('order-pay')) {
        wp_enqueue_style('cp-styles', plugins_url('css/cp-styles.css', __FILE__));
        wp_enqueue_script('cp-script', plugins_url('js/cp-script.js', __FILE__));
    }
}

add_action('wp_enqueue_scripts', 'nekopay_load_cp_scripts', 30);


// Process order
function nekopay_process_order($order_id)
{
    global $wp;
    $wc_nekopay = new WC_NekoPay;

    $order_id = $wp->query_vars['order-pay'];
    $order = wc_get_order($order_id);
    $order_status = $order->get_status();
    $crypto_type = $order->get_meta('_nekopay_crypto_type');

    $order_crypto_exchange_rate = $wc_nekopay->get_exchange_rate($crypto_type);

    // Redirect if payment is canceled or completed
    if ($order_status == 'cancelled') {
        $redirect = $order->get_cancel_order_url();
        wp_safe_redirect($redirect);
        exit;
    }

    if ($order_status == 'processing') {
        $redirect = $order->get_checkout_order_received_url();
        wp_safe_redirect($redirect);
        exit;
    }

    if ($order_crypto_exchange_rate == 0) {
        wc_add_notice('Issue fetching current rates. Please try again.', 'error');
        wc_print_notices();
        exit;
    }

    if ($order_id > 0 && $order instanceof WC_Order) {

        global $wpdb;
        $db_table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $db_table_name WHERE order_id = %d", $order_id));

        if ($wpdb->last_error) {
            wc_add_notice('Error processing your order. Please try again.', 'error');
            wc_print_notices();
            exit;
        }

        if ($count == 0) {
            $payment_address = $wc_nekopay->payment_address;
            $order_total = $order->get_total();
            $order_in_crypto = nekopay_order_total_in_crypto($order_total, $order_crypto_exchange_rate);
            $order_currency = $order->get_currency();

            $wpdb->insert($db_table_name, array(
                'transaction_id' => "",
                'payment_address' => $payment_address,
                'order_id' => $order_id,
                'crypto_type' => $crypto_type,
                'order_total' => $order_total,
                'order_in_crypto' => $order_in_crypto,
                'order_default_currency' => $order_currency,
                'order_crypto_exchange_rate' => $order_crypto_exchange_rate,
                'order_status' => 'pending',
                'order_time' => time()
            ));

            if ($wpdb->last_error) {
                wc_add_notice('Error processing your order. Please try again.', 'error');
                wc_print_notices();
                exit;
            }
        }
    }
}

add_action("before_woocommerce_pay", "nekopay_process_order", 20);


// Payment Verification
function nekopay_verify_payment()
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;

    $wc_nekopay = new WC_NekoPay;
    $order_id = intval(sanitize_text_field($_POST['order_id']));
    $order = wc_get_order($order_id);

    $cp_order = nekopay_get_cp_order_info($order_id);
    $crypto_type = $cp_order->crypto_type;
    $payment_address = $cp_order->payment_address;
    $transaction_id = $cp_order->transaction_id;
    $order_in_crypto = $cp_order->order_in_crypto;
    $confirmation_no = $wc_nekopay->confirmation_no;
    $order_time = $cp_order->order_time;
    $max_time_limit = $wc_nekopay->max_time_limit;
    $plugin_version = $wc_nekopay->plugin_version;

    $api_url = CRYPTO_API_URLS[$crypto_type] ?? '';
    if (empty($api_url)) {
        echo json_encode(["status" => "failed", "message" => "Invalid cryptocurrency selected"]);
        wp_die();
    }

    $response = wp_remote_get($api_url . "?address=" . $payment_address . "&tx=" . $transaction_id . "&amount=" . $order_in_crypto . "&conf=" . $confirmation_no . "&otime=" . $order_time . "&mtime=" . $max_time_limit . "&pv=" . $plugin_version);
    $response = wp_remote_retrieve_body($response);
    $response = json_decode($response);

    if (!empty($response)) {
        if ($response->status == "invalid") {
            echo json_encode($response);
            die();
        }

        if ($response->status == "expired" && $cp_order->order_status != "expired") {
            $wpdb->update($db_table_name, ['order_status' => 'expired'], ['order_id' => $order_id]);
            $order->update_status('cancelled');
        }

        if ($response->transaction_id != "" && strlen($response->transaction_id) == 64) {
            $transactions = $wpdb->get_results($wpdb->prepare("SELECT id FROM $db_table_name WHERE transaction_id = %s AND order_id <> %d", $response->transaction_id, $order_id));

            if ($wpdb->last_error) {
                wc_add_notice('Unable to process the order. Please try again.', 'error');
                wc_print_notices();
                exit;
            }

            if (count($transactions) > 0) {
                echo json_encode(["status" => "failed"]);
                die();
            }
        }

        if ($response->status == "detected" && empty($cp_order->transaction_id)) {
            $wpdb->update($db_table_name, [
                'transaction_id' => $response->transaction_id,
                'order_status' => 'detected',
                'confirmation_no' => $response->confirmations
            ], ['order_id' => $order_id]);
        }

        if ($response->status == "confirmed" && $cp_order->order_status != "confirmed") {
            $wpdb->update($db_table_name, [
                'transaction_id' => $response->transaction_id,
                'order_status' => 'confirmed',
                'confirmation_no' => $response->confirmations
            ], ['order_id' => $order_id]);
            $order->update_status('processing');
        }
    } else {
        echo json_encode(["status" => "failed"]);
    }

    wp_die();
}

add_action("wp_ajax_nekopay_verify_payment", "nekopay_verify_payment");
add_action("wp_ajax_nopriv_nekopay_verify_payment", "nekopay_verify_payment");


// Retrieve Order Info
function nekopay_get_cp_order_info($order_id)
{
    global $wpdb;
    $db_table_name = $wpdb->prefix . NEKOPAY_ORDERS_TABLE_NAME;

    $result = $wpdb->get_results($wpdb->prepare("SELECT * FROM $db_table_name WHERE order_id = %d", $order_id));

    if ($wpdb->last_error) {
        wc_add_notice('Unable to retrieve order details.', 'error');
        wc_print_notices();
        exit;
    }

    return $result[0];
}
