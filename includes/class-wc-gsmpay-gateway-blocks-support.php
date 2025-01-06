<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_GSMPay_Gateway_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = 'WC_GSMPay';

    public function initialize()
    {
        $this->settings = get_option("woocommerce_{$this->name}_settings", []);
    }

    public function is_active()
    {
        return filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN );
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'wc-payment-method-gsmpay',
            plugin_dir_url(__DIR__) . 'assets/js/wc-payment-method-gsmpay.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        return ['wc-payment-method-gsmpay'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'icon' => plugin_dir_url(__DIR__) . 'assets/images/logo.png',
        ];
    }
}
