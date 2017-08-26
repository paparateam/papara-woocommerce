<?php
/**
* Papara Gateway Class
*/
class Papara_Payment extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this -> id = 'papara';
        $this -> title = __('Papara', 'papara');
        $this -> supports[] = 'refunds';
        $this -> method_title = __('Papara', 'papara');
        $this -> method_description = __("Papara Payment Gateway Plug-in for WooCommerce", 'papara');
        $this -> icon = plugins_url('assets/img/papara-logo.png', __FILE__);
        $this -> has_field = false;
        $this -> order_button_text = __('Pay with Papara', 'papara');
        $this -> description = $this -> get_option('description');
        $this -> init_form_fields();
        $this -> init_settings();

        if (is_admin()) {
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ));
            }
        }
        add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ));
        add_action('woocommerce_api_' . strtolower(get_class($this)), array( $this, 'check_papara_response' ));
    }

    public function init_form_fields()
    {
        // configuration for admin page
        $this -> form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'papara'),
                'type' => 'checkbox',
                'label' => __('Enable Papara Payment Module.', 'papara'),
                'default' => 'no'),
            'title' => array(
                'title' => __('Title:', 'papara'),
                'type'=> 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'papara'),
                'default' => __('Papara', 'papara')),
            'description' => array(
                'title' => __('Description:', 'papara'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'papara'),
                'default' => __('Pay securely with Papara Payment System and Secure Servers.', 'papara')),
            'api_key' => array(
                'title' => __('Api Key', 'papara'),
                'type' => 'text',
                'description' =>  __('Given to Merchant by Papara', 'papara'),
                ),
            'secret_key' => array(
                'title' => __('Secret Key', 'papara'),
                'type' => 'text',
                'description' =>  __('In order to secure payments, can be found on Papara Merchant Account', 'papara'),
                ),
            'environment' => array(
                'title' => __('Test Mode', 'papara'),
                'label' => __('Enable Test Mode', 'papara'),
                'type' => 'checkbox',
                'description' =>  __('In order to make tests check box', 'papara'),
                'default' => 'no',
                ),
            );
    }

    /**
    * Information that will be printed on admin page.
    */
    public function admin_options()
    {
        echo '<h3>'.__('Papara Payment Gateway', 'papara').'</h3>';
        echo '<p>'.__('Papara is most popular payment gateway for online shopping in Turkey', 'papara').'</p>';
        echo '<table class="form-table">';
        $this -> generate_settings_html();
        echo '</table>';
    }

    /**
    * Function that will be processing when user click payment button
    * @param $order_id automatically sent by woocommerce, when checkout button clicked.
    */
    public function receipt_page($order_id)
    {
        global $woocommerce, $error_code;
        $order = new WC_Order($order_id);

        // $result_ARRAY is the JSON object which papara returns after payment record creation (look for: Ödeme kaydı işlem sonucu)
        $result_ARRAY = $this -> generate_papara_form($order_id);

        if ($result_ARRAY != null) {
            $this -> redirect_user($result_ARRAY['data']['paymentUrl']);
        } else {
            // redirecting if an error occured during creating process of record payment
            $checkout_url = $woocommerce->cart->get_checkout_url();
            header("Location: ".$checkout_url);

            // error handling
            switch ($order->get_meta('error_code')) {
                case 997:
                    wc_add_notice(__('Ödeme kabul etme yetkiniz yok. Müşteri temsilciniz ile görüşmelisiniz.'), 'error');
                    break;
                case 998:
                    if ($order->get_total() < 1) {
                        wc_add_notice(__('1 liradan az ödemeler Papara tarafından kabul edilmemektedir.'), 'error');
                    } elseif ($order->get_total() > 50000) {
                        wc_add_notice(__('50.000 liradan fazla ödemeler Papara tarafından kabul edilmemektedir.'), 'error');
                    } else {
                        wc_add_notice(__('Lütfen Papara ile temasa geçin.'), 'error');
                    }
                    break;
                case 999:
                    if ($this->get_option('api_key') == null) {
                        wc_add_notice(__('API Key ile ilgili bir sorun oluştu lütfen kontrol edin.'), 'error');
                    } else {
                        wc_add_notice(__('Papara ile ilgili bir şeyler ters gitti. Kısa bir süre sonra tekrar deneyin.'), 'error');
                    }
                    break;
                default:
                    break;
            }
            die('WooCommerce - SOMETHING WENT WRONG');
        }
    }

    public function process_payment($order_id)
    {
        $order = new WC_Order($order_id);

        return array(
            'result'    => 'success',
            'redirect'    => $order->get_checkout_payment_url(true)
        );
    }

    /**
    * Generating first record and checking object that returning from Papara
    * If fails take error_code
    * @param $order_id taken from func receipt_page and created automatically when user press checkout button
    */
    public function generate_papara_form($order_id)
    {
        global $woocommerce;
        $order = new WC_Order($order_id);

        // deciding whether in test mode or not
        $environment_url = ($this -> get_option('environment') == 'TRUE') ? 'https://merchant-api.papara.com/payments' : 'https://merchantapi-test-master.papara.com/payments';

        // retriving product names in order to show in payment page
        $description = '| ';
        $items = $woocommerce -> cart -> get_cart();
        foreach ($items as $item => $values) {
            $_product = wc_get_product($values['data'] -> get_id());
            $description .= $_product -> get_title().'   x'.$values['quantity']. ' | ';
        }

        // creating JSON object for first payment record
        $amount = $order -> get_total();
        $referenceId = $order_id;
        $redirect_Url = $this -> get_return_url($order);
        $notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'Papara_Payment', home_url('/')));
        $api_key = $this -> get_option('api_key');

        $payload = array(
                'amount' => floatval($amount),
                'referenceId' => $referenceId,
                'orderDescription' => $description,
                'notificationUrl'     => $notify_url,
                'redirectUrl'       => $redirect_Url,
        );

        // posting request for payment record
        $create_payment_POST = curl_init($environment_url);
        curl_setopt_array($create_payment_POST, array(
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$api_key,
                'Content-Type: application/json'
                ),
                CURLOPT_POSTFIELDS => json_encode($payload)
            ));
        
        // mixed return; will return the result on success, FALSE on failure.
        $result_JSON = curl_exec($create_payment_POST);
        if ($result_JSON === false) {
            $order->update_status('failed');
            die(curl_error($create_payment_POST));
        }
        $result_ARRAY = json_decode($result_JSON, true);
        curl_close($create_payment_POST);

        // if there is sth wrong, no need to check with GET method, so take error code and return
        if ($result_ARRAY['succeeded'] == false) {
            $order->add_meta_data('error_code', $result_ARRAY['error']['code'], true);
            $order->update_status('failed');
            return null;
        }

        // checking information for first payment record, if fails there is sth wrong
        $result_verification_GET = curl_init();
        curl_setopt_array($result_verification_GET, array(
                CURLOPT_URL => $environment_url.'?id='.$result_ARRAY['data']['id'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$api_key,
                'Content-Type: application/json'
                )
            ));

        // mixed return; will return the result on success, FALSE on failure.
        $verified_result_JSON = curl_exec($result_verification_GET);
        if ($verified_result_JSON === false) {
            $order->update_status('failed');
            die(curl_error($result_verification_GET));
        }
        $verified_result_ARRAY = json_decode($verified_result_JSON, true);
        curl_close($result_verification_GET);

        // check for POST data if it is successful and check for papara db if it is exists, then continue
        if ($result_ARRAY['succeeded'] == true && $verified_result_ARRAY['succeeded'] == 1) {
            // payment record created
            $order->update_status('pending');
            return $result_ARRAY;
        }
    }

    public function redirect_user($redirectUrl)
    {
        header("Location: ".$redirectUrl);
    }

    /*
    * Checking information which papara made before redirecting user to the merchant site
    * If there is an error, return error code to ipn
    */
    public function check_papara_refund_request_result_JSON()
    {
        // taking data which sent with IPN
        $data = json_decode(file_get_contents('php://input'), true);
        $order = new WC_Order($data['referenceId']);

        $environment_url = ($this -> get_option('environment') == 'TRUE') ? 'https://merchant-api.papara.com/payments' : 'https://merchantapi-test-master.papara.com/payments';
        $api_key = $this -> get_option('api_key');
        $order->set_transaction_id($data['id']);

        // checking for IPN whether payment was successful or not
        $check_payment_GET = curl_init();
        curl_setopt_array($check_payment_GET, array(
                CURLOPT_URL => $environment_url.'?id='.$data['id'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$api_key,
                'Content-Type: application/json'
                )
            ));
        // mixed return; will return the result on success, FALSE on failure.
        $data_sentWithIPN_JSON = curl_exec($check_payment_GET);
        if ($data_sentWithIPN_JSON === false) {
            die(curl_error($check_payment_GET));
        }
        $data_sentWithIPN_ARRAY = json_decode($data_sentWithIPN_JSON, true);
        curl_close($check_payment_GET);

        /**
        * Verifications of payments
        */

        // HTTP GET to /payments
        if ($data_sentWithIPN_ARRAY['succeeded'] != 1) {
            die('WooCommerce - RECORD WITH ID IS NOT FOUND');
        } elseif ($data_sentWithIPN_ARRAY['succeeded'] == 1) {
            // if papara IPN was not send yet due to some problems; however with get request to payment, transaction found in papara db,
            // so check if 'succeeded' and send ok then return
            $order->update_status('processing');
            die('OK');
            return;
        }

        // control for secret key
        if ($data['merchantSecretKey'] <> $this -> get_option('secret_key')) {
            die('WooCommerce - WRONG SECRET KEY');
        }

        // control for amount
        $totalAmount = $order->get_total();
        $totalAmount = str_replace(',', '.', $totalAmount);
        $totalAmount = round($totalAmount, 2);
        $papara_amount = round($data['amount'], 2);
        if ($totalAmount != $papara_amount) {
            die('WooCommerce - INCORRECT AMOUNT '.$totalAmount.' != '.$papara_amount);
        }

        // control for status information at IPN
        if ($data['status'] == 0) {
            $order->update_status('pending');
            die('WooCommerce - ORDER WAS NOT COMPLETED');
        } elseif ($data['status'] == 1) {
            $order->update_status('processing');
        } else {
            $order->update_status('cancelled');
            die('WooCommerce - ORDER WAS CANCELLED');
        }

        die('OK');
    }

    /**
    * Making a refund using woocommerce ui
    * @param $amount and @param $reason can be used if necessary
    * these parameters sent through woocommerce refund ui
    * NOTICE: Although woocommerce enable merchant to make partial refunds, papara only allow full refunds,
    * a notification added in order to guarantee that
    */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = new WC_Order($order_id);

        if ($amount == 0 || $amount == null) {
            return new WP_Error('papara', __('Refund Error: You need to specify a refund amount.', 'papara'));
        }
        // notification for full refunds
        if ($amount != $order->get_total()) {
            return new WP_Error('papara', __('Amount error: Need to refund: '.$order->get_total(), 'papara'));
        }

        $environment_url = ($this -> get_option('environment') == 'TRUE') ? 'https://merchant-api.papara.com/payments' : 'https://merchantapi-test-master.papara.com/payments';
        $api_key = $this -> get_option('api_key');

        // HTTP PUT request for refund
        $refund_request_PUT = curl_init();
        curl_setopt_array($refund_request_PUT, array(
                CURLOPT_URL => $environment_url.'?id='.$order->get_transaction_id(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$api_key,
                'Content-Type: application/json'
                ),
                CURLOPT_POSTFIELDS => ''
            ));
        $refund_request_result_JSON = curl_exec($refund_request_PUT);
        $refund_request_result_ARRAY = json_decode($refund_request_result_JSON, true);

        if ($refund_request_result_ARRAY['succeeded'] == true) {
            $order->update_status('refunded');
            curl_close($refund_request_PUT);

            // notify user, not an error
            return new WP_Error('papara', __('Refund succeeded!', 'papara'));
        }
    }
}
