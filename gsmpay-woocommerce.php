<?php
/*
Plugin Name: افزونه درگاه پرداخت اعتباری جی‌اس‌ام پی
Plugin URI: https://github.com/gsmpay/gsmpay-woocommerce
Description: افزونه درگاه پرداخت اعتباری جی‌اس‌ام پی برای سیستم فروشگاه‌ساز ووکامرس
Author: Mahmood Dehghani, Saleh Hashemi
Author URI: https://gsmpay.ir/
Version: 1.0.5
*/

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('GSMPAY_PLUGIN_FILE')) {
    define('GSMPAY_PLUGIN_FILE', __FILE__);
}

if (! defined('WC_GSMPAY_TRANSLATE_DOMAIN')) {
    define('WC_GSMPAY_TRANSLATE_DOMAIN', 'wc-gsmpay');
}

add_action('woocommerce_loaded', function() {
    require_once 'includes/class-persian-text.php';
    require_once 'includes/class-validation.php';
    require_once 'includes/class-response.php';
    require_once 'includes/class-http-request.php';
    require_once 'class-wc-payment-gateway-gsmpay.php';
});

// Ensure WooCommerce is active
function check_woocommerce_dependency() {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('This plugin requires WooCommerce to be installed and active.');
    }
}
register_activation_hook(__FILE__, 'check_woocommerce_dependency');


add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('woocommerce_blocks_loaded', function() {
    require_once __DIR__.'/includes/class-wc-gsmpay-gateway-blocks-support.php';

    add_action('woocommerce_blocks_payment_method_type_registration', function(PaymentMethodRegistry $registry) {
        $registry->register(new WC_GSMPay_Gateway_Blocks_Support);
    });
});

add_action('woocommerce_blocks_loaded', function() {
    woocommerce_register_additional_checkout_field([
        'id' => 'gsmpay/mobile',
        'label' => __('موبایل', WC_GSMPAY_TRANSLATE_DOMAIN),
        'location' => 'contact',
        'type' => 'text',
        'required' => true,
        'show_in_order_confirmation' => true,
        'validate_callback' => function($value, $field) {
            if (!empty($value) && !GSMPay_Validation::mobile($value)) {
                return new WP_Error(
                    'woocommerce_invalid_mobile_field',
                    __('شماره موبایل خود را صحیح وارد کنید.', WC_GSMPAY_TRANSLATE_DOMAIN)
                );
            }
        },
    ]);

    woocommerce_register_additional_checkout_field([
        'id' => 'gsmpay/national_code',
        'label' => __('کد ملی', WC_GSMPAY_TRANSLATE_DOMAIN),
        'location' => 'contact',
        'type' => 'text',
        'required' => true,
        'show_in_order_confirmation' => true,
        'validate_callback' => function($value, $field) {
            if (!empty($value) && !GSMPay_Validation::nationalCode($value)) {
                return new WP_Error(
                    'woocommerce_invalid_national_code_field',
                    __('کد ملی خود را صحیح وارد کنید.', WC_GSMPAY_TRANSLATE_DOMAIN)
                );
            }
        },
    ]);
});
