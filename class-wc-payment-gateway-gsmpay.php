<?php
if (!defined('ABSPATH')) {
    exit;
}

function gsmpay_init_payment_gateway()
{
    add_filter('woocommerce_payment_gateways', 'gsmpay_register_payment_gateway');
    add_filter('woocommerce_currencies', 'add_ir_currencies');
    add_filter('woocommerce_currency_symbol', 'change_existing_currency_symbol', 10, 2);

    function gsmpay_register_payment_gateway($methods)
    {
        $methods[] = 'WC_GSMPay';
        return $methods;
    }

    function add_ir_currencies($currencies) {
        $currencies['IRR'] = __('ریال', WC_GSMPAY_TRANSLATE_DOMAIN);
        $currencies['IRT'] = __('تومان', WC_GSMPAY_TRANSLATE_DOMAIN);
        $currencies['IRHR'] = __('هزار ریال', WC_GSMPAY_TRANSLATE_DOMAIN);
        $currencies['IRHT'] = __('هزار تومان', WC_GSMPAY_TRANSLATE_DOMAIN);

        return $currencies;
    }

    function change_existing_currency_symbol($currency_symbol, $currency) {
        switch ($currency) {
            case 'IRR':
                $currency_symbol = 'ریال';
                break;
            case 'IRT':
                $currency_symbol = 'تومان';
                break;
            case 'IRHR':
                $currency_symbol = 'هزار ریال';
                break;
            case 'IRHT':
                $currency_symbol = 'هزار تومان';
                break;
        }
        return $currency_symbol;
    }

    class WC_GSMPay extends WC_Payment_Gateway
    {
        const PAYMENT_REQUEST_URL = 'https://api.gsmpay.ir/v1/cpg/payments';

        const PAYMENT_VERIFY_URL = 'https://api.gsmpay.ir/v1/cpg/payments/verify';

        public function __construct()
        {
            $this->id = 'WC_GSMPay';
            $this->icon = apply_filters(
                'WC_GSMPay_logo',
                plugin_dir_url(GSMPAY_PLUGIN_FILE) . 'assets/images/logo.png'
            );
            $this->has_fields = true;
            $this->method_title = __('جی‌اس‌ام پی - درگاه پرداخت اعتباری (‌اقساطی)', WC_GSMPAY_TRANSLATE_DOMAIN);
            $this->method_description = __('تنظیمات درگاه پرداخت اعتباری جی‌اس‌ام پی', WC_GSMPAY_TRANSLATE_DOMAIN);
            $this->order_button_text = __('پرداخت با جی‌اس‌ام پی', WC_GSMPAY_TRANSLATE_DOMAIN);
            $this->supports = [
                'products',
                'tokenization',
                'refunds',
                'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'pre-orders',
            ];

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'send_to_gsmpay']);
            add_action('woocommerce_api_' . strtolower(get_class($this)), [$this, 'process_payment_verify']);

            add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'checkout_field_display_admin_order_meta'], 10, 1);
            add_action('woocommerce_checkout_update_order_meta', [$this, 'checkout_update_order_meta']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_gsm_form_styles']);
        }

        public function init_form_fields()
        {
            $this->form_fields = apply_filters('WC_GSMPay_Config', [
                'enabled' => [
                    'title' => __('فعالسازی', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'label' => __('فعالسازی درگاه جی‌اس‌ام پی', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'description' => __('برای فعالسازی درگاه باید چک باکس را تیک بزنید.', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'type' => 'checkbox',
                    'default' => 'yes',
                    'desc_tip' => true,
                ],
                'title' => [
                    'title' => __('عنوان درگاه', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'description' => __('عنوان درگاه که در طی خرید به مشتری نمایش داده می‌شود.', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'type' => 'text',
                    'default' => $this->method_title,
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('توضیحات درگاه', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'description' => __('توضیحاتی که طی عملیات پرداخت نمایش داده خواهد شد.', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'type' => 'text',
                    'default' => __('پرداخت اقساطی از طریق درگاه جی‌اس‌ام پی', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'desc_tip' => true,
                ],
                'merchant_code' => [
                    'title' => __('کد پذیرنده', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'type' => 'text',
                    'description' => __('کد پذیرنده دریافتی از جی‌اس‌ام پی', WC_GSMPAY_TRANSLATE_DOMAIN),
                    'default' => '',
                    'desc_tip' => true
                ],
            ]);
        }

        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }

            echo sprintf(
                '<fieldset id="wc-%s-cc-form" class="wc-credit-card-form wc-payment-form gsmpay-form" style="background:transparent;">
                    <div class="form-row form-row-first">
                        <label>موبایل <span class="required">*</span></label>
                        <input name="payer_mobile" value="%s" type="text" autocomplete="off" placeholder="شماره موبایل" maxlength="11">
                    </div>
                    <div class="form-row form-row-last">
                        <label>کد ملی <span class="required">*</span></label>
                        <input name="payer_national_code" value="%s" type="text" autocomplete="off" placeholder="کد ملی" maxlength="10">
                    </div>
                    <div class="clear"></div>
                </fieldset>',
                esc_attr($this->id),
                $this->getPayerMobile(),
                $this->getPayerNationalCode()
            );
        }

        public function validate_fields()
        {
            $isValid = true;

            $mobile = $this->getPayerMobile();
            $nationalCode = $this->getPayerNationalCode();

            WC()->session->set('payer_mobile', $mobile);
            WC()->session->set('payer_national_code', $nationalCode);

            if (!$mobile) {
                wc_add_notice(__('<strong>شماره موبایل</strong> یک گزینه الزامی است.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $isValid = false;
            } elseif (!GSMPay_Validation::mobile($mobile)) {
                wc_add_notice(__('<strong>شماره موبایل</strong> خود را صحیح وارد کنید.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $isValid = false;
            }

            if (!$nationalCode) {
                wc_add_notice(__('<strong>کد ملی</strong> یک گزینه الزامی است.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $isValid = false;
            } elseif (!GSMPay_Validation::nationalCode($nationalCode)) {
                wc_add_notice(__('<strong>کد ملی</strong> خود را صحیح وارد کنید.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $isValid = false;
            }

            return $isValid;
        }

        public function checkout_field_display_admin_order_meta($order)
        {
            $nationalCode = $order->get_meta('payer_national_code');
            if ($nationalCode) {
                echo sprintf(
                    '<p><strong>%s</strong> %s</p>',
                    __('کد ملی خریدار:', WC_GSMPAY_TRANSLATE_DOMAIN),
                    $nationalCode
                );
            }

            $mobile = $order->get_meta('payer_mobile');
            if ($mobile) {
                echo sprintf(
                    '<p><strong>%s</strong> <a href="tel:%s">%s</a></p>',
                    __('موبایل خریدار:', WC_GSMPAY_TRANSLATE_DOMAIN),
                    $mobile,
                    $mobile
                );
            }
        }

        public function checkout_update_order_meta($order_id)
        {
            $nationalCode = $this->getPayerNationalCode();
            if ($nationalCode) {
                update_post_meta($order_id, 'payer_national_code', $nationalCode);
            }

            $mobile = $this->getPayerMobile();
            if ($mobile) {
                update_post_meta($order_id, 'payer_mobile', $mobile);
            }
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            return [
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        public function send_to_gsmpay($order_id)
        {
            $order = wc_get_order($order_id);

            try {
                $response = (new GSMPay_Http_Request())->post(
                    self::PAYMENT_REQUEST_URL,
                    $this->get_payment_request_params($order)
                );

                if (!$response->isSuccessful()) {
                    wc_add_notice($this->get_gsmpay_errors($response), 'error');
                    $this->redirect(wc_get_checkout_url());
                    return;
                }

                $data = $response->toArray()['data'];

                $order->update_meta_data('gsmpay_token', $data['token']);
                $order->save();

                $order->add_order_note(
                    sprintf(__('کاربر به درگاه پرداخت هدایت شد. شناسه پرداخت: %s', WC_GSMPAY_TRANSLATE_DOMAIN), $data['token'])
                );

                $this->redirect($data['redirect_url']);
            } catch (\Exception $e) {
                wc_add_notice(__('در هنگام اتصال به درگاه جی‌اس‌ام پی خطایی رخ داده است.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $this->redirect(wc_get_checkout_url());
            }
        }

        public function process_payment_verify()
        {
            $order_id = !empty($_GET['wc_order']) ? (int)$_GET['wc_order'] : null;
            $payment_status = !empty($_GET['status']) ? sanitize_text_field($_GET['status']) : null;
            $payment_token = !empty($_GET['token']) ? sanitize_text_field($_GET['token']) : null;

            if (!$order_id) {
                $this->redirect(wc_get_checkout_url());
            }

            $order = wc_get_order($order_id);
            $redirect_url = $order->get_view_order_url();

            if ($order->is_paid() || !$order->needs_payment()) {
                $this->redirect($redirect_url);
            }

            if ($order->get_meta('gsmpay_token') !== $payment_token) {
                wc_add_notice(__('توکن پرداخت معتبر نیست.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $this->redirect($redirect_url);
            }

            if ($payment_status !== 'success') {
                wc_add_notice(__('عملیات پرداخت لغو شده است.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                $order->update_status('cancelled', __('پرداخت لغو شد', WC_GSMPAY_TRANSLATE_DOMAIN));
                $this->redirect($redirect_url);
            }

            try {
                $response = (new GSMPay_Http_Request())
                    ->post(self::PAYMENT_VERIFY_URL, $this->get_payment_verify_params($order, $payment_token));

                if (!$response->isSuccessful()) {
                    $this->set_message($order_id, $this->get_gsmpay_errors($response));
                    $this->redirect($redirect_url);
                }

                $data = $response->toArray()['data'];

                if ($data['is_paid'] !== true) {
                    wc_add_notice(__('خطا در تایید تراکنش پرداخت.', WC_GSMPAY_TRANSLATE_DOMAIN), 'error');
                    $order->update_status('failed', __('پرداخت تایید نشد', WC_GSMPAY_TRANSLATE_DOMAIN));
                    $this->redirect($redirect_url);
                }

                $order->payment_complete();

                wc_add_notice(__('پرداخت با موفقیت انجام شد.', WC_GSMPAY_TRANSLATE_DOMAIN), 'success');
                $this->redirect($this->get_return_url($order));
            } catch (Exception $ex) {
                $this->set_message($order_id, __('در هنگام اتصال به درگاه جی‌اس‌ام پی خطایی رخ داده است.', WC_GSMPAY_TRANSLATE_DOMAIN));
            }

            $this->redirect($redirect_url);
        }

        protected function get_payment_request_params($order)
        {
            $currency = $order->get_currency();

            $data = [
                'merchant_code' => $this->get_option('merchant_code'),
                'invoice_reference' => (string)$order->get_order_number(),
                'invoice_date' => (string)$order->get_date_created(),
                'invoice_amount' => $this->convert_money($currency, $order->get_total()),
                'payer_first_name' => $order->get_billing_first_name(),
                'payer_last_name' => $order->get_billing_last_name(),
                'payer_national_code' => $order->get_meta('payer_national_code'),
                'payer_mobile' => $order->get_meta('payer_mobile'),
                'callback_url' => add_query_arg('wc_order', $order->get_id(), WC()->api_request_url($this->id)),
                'description' => 'پرداخت سفارش #' . $order->get_id(),
                'items' => [],
            ];

            if (!$data['payer_national_code']) {
                $data['payer_national_code'] = $this->getPayerNationalCode();
            }

            if (!$data['payer_mobile']) {
                $data['payer_mobile'] = $this->getPayerMobile();
            }

            foreach ($order->get_items() as $product) {
                $productData = $product->get_data();
                $unitPrice = $product['subtotal'] > 0 ? $product['subtotal'] / $productData['quantity'] : 0;
                $unitTax = $product['total_tax'] > 0 ? $product['total_tax'] / $productData['quantity'] : 0;

                $unitDiscount = $product['subtotal'] - $product['total'];
                if ($unitDiscount > 0) {
                    $unitDiscount = $unitDiscount / $productData['quantity'];
                }

                $data['items'][] = [
                    'reference' => (string)$productData['product_id'],
                    'name' => $productData['name'],
                    'quantity' => $productData['quantity'],
                    'unit_price' => $this->convert_money($currency, $unitPrice),
                    'unit_discount' => $this->convert_money($currency, $unitDiscount),
                    'unit_tax_amount' => $this->convert_money($currency, $unitTax),
                    'is_product' => true,
                ];
            }

            if ($order->get_shipping_total()) {
                $shippingCost = $this->convert_money($currency, $order->get_shipping_total());
                $shippingTax = $this->convert_money($currency, $order->get_shipping_tax());

                $data['items'][] = [
                    'reference' => 'SEND-COST',
                    'name' => 'هزینه ارسال',
                    'quantity' => 1,
                    'unit_price' => $shippingCost,
                    'unit_discount' => 0,
                    'unit_tax_amount' => $shippingTax,
                    'is_product' => false,
                ];
            }

            return $data;
        }

        protected function get_payment_verify_params($order, $payment_token)
        {
            $currency = $order->get_currency();
            $totalAmount = $this->convert_money($currency, $order->get_total());

            return [
                'merchant_code' => $this->get_option('merchant_code'),
                'token' => $payment_token,
                'invoice_reference' => $order->get_order_number(),
                'invoice_amount' => $totalAmount,
            ];
        }

        protected function getPayerNationalCode()
        {
            $nationalCode =  WC()->customer->get_meta('_wc_other/gsmpay/national_code');

            if (empty($nationalCode) && !empty($_POST['payer_national_code'])) {
                $nationalCode = $_POST['payer_national_code'];
            }

            if (empty($nationalCode)) {
                $nationalCode = WC()->session->get('payer_national_code', '');
            }

            return GSMPay_Persian_Text::toEnglishNumber(sanitize_text_field($nationalCode));
        }

        protected function getPayerMobile()
        {
            $mobile = WC()->customer->get_meta('_wc_other/gsmpay/mobile');

            if (empty($mobile) && !empty($_POST['payer_mobile'])) {
                $mobile = $_POST['payer_mobile'];
            }

            if (empty($mobile)) {
                $mobile = WC()->session->get('payer_mobile', '');
            }

            return GSMPay_Persian_Text::toEnglishNumber(sanitize_text_field($mobile));
        }

        protected function convert_money($currency, $amount)
        {
            $currency = strtoupper($currency);

            if ($currency === 'IRT') {
                $amount *= 10;
            } elseif ($currency === 'IRHR') {
                $amount *= 100;
            } elseif ($currency === 'IRHT') {
                $amount *= 1000;
            }

            return (int)$amount;
        }

        protected function set_message($order_id, $note, $notice_type = 'error')
        {
            $order = wc_get_order($order_id);
            $order->add_order_note($note);
            wc_add_notice($note, $notice_type);
        }

        protected function get_gsmpay_errors($response)
        {
            return $response->getErrorMessage();
        }

        protected function redirect($url)
        {
            wp_redirect($url);
            exit;
        }

        /**
         * Enqueue custom CSS for GSM Pay form
         */
        public function enqueue_gsm_form_styles()
        {
            if (is_checkout()) {
                wp_enqueue_style(
                    'gsm-form-style',
                    plugin_dir_url(GSMPAY_PLUGIN_FILE) . 'assets/css/form-style.css',
                    [],
                    '1.0.4'
                );
            }
        }
    }
}

add_action('plugins_loaded', 'gsmpay_init_payment_gateway');
