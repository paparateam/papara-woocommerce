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
            'working_key' => array(
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

        // $response_data is the JSON object which papara returns after payment record creation (look for: Ödeme kaydı işlem sonucu)
        $response_data = $this -> generate_papara_form($order_id);

        if ($response_data != null) {
            $this -> redirect_user($response_data['data']['paymentUrl']);
        } else {
            // redirecting if an error occured during creating process of record payment
            $checkout_url = $woocommerce->cart->get_checkout_url();
            header("Location: ".$checkout_url);

            // error handling
            switch ($order->get_meta('error_code')) {
                case 997:
                    wc_add_notice(__('You have no right to accept payment, should talk to your customer representative.'), 'error');
                    break;
                case 998:
                    if ($order->get_total() < 1) {
                        wc_add_notice(__('Payments less than 1 TRY are not accepted by Papara.'), 'error');
                    } elseif ($order->get_total() > 50000) {
                        wc_add_notice(__('Payments more than 50000 TRY are not accepted by Papara.'), 'error');
                    } else {
                        wc_add_notice(__('Please contact with Papara.'), 'error');
                    }
                    break;
                case 999:
                    wc_add_notice(__('Something went wrong with Papara. Don\'t worry we\'ll figure it out.'), 'error');
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
        $working_key = $this -> get_option('working_key');

        $payload = array(
                'amount' => floatval($amount),
                'referenceId' => $referenceId,
                'orderDescription' => $description,
                'notificationUrl'     => $notify_url,
                'redirectUrl'       => $redirect_Url,
        );

        // posting request for payment record
        $ch = curl_init($environment_url);
        curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$working_key,
                'Content-Type: application/json'
                ),
                CURLOPT_POSTFIELDS => json_encode($payload)
            ));
        $response = curl_exec($ch);
        if ($response === false) {
            die(curl_error($ch));
        }
        $response_data = json_decode($response, true);
        curl_close($ch);

        // checking information for first payment record, if fails there is sth wrong
        // if there is sth wrong, no need to check, so take error code and return
        if ($response_data['error']['code'] != 0) {
            $order->add_meta_data('error_code', $response_data['error']['code'], true);
            $order->update_status('failed');
            return null;
        }
        $ch1 = curl_init();
        curl_setopt_array($ch1, array(
                CURLOPT_URL => $environment_url.'?id='.$response_data['data']['id'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$working_key,
                'Content-Type: application/json'
                )
            ));
        $check_first_response = curl_exec($ch1);
        if ($check_first_response === false) {
            die(curl_error($ch1));
        }
        $check_first_response_data = json_decode($check_first_response, true);
        curl_close($ch1);

        // check for POST data if it is successful and check for papara db if it is exists, then continue
        if ($response_data['succeeded'] == true && $check_first_response_data['succeeded'] == 1) {
            // payment record created
            $order->update_status('pending');
            return $response_data;
        } else {
            // error
            $order->update_meta_data('error_code', $response_data['error']['code'], true);
            $order->update_status('failed');
            return null;
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
    public function check_papara_response()
    {

        // taking data which sent with IPN
        $data = json_decode(file_get_contents('php://input'), true);
        $order = new WC_Order($data['referenceId']);

        $environment_url = ($this -> get_option('environment') == 'TRUE') ? 'https://merchant-api.papara.com/payments' : 'https://merchantapi-test-master.papara.com/payments';
        $working_key = $this -> get_option('working_key');
        $order->set_transaction_id($data['id']);


        // checking for IPN whether payment was successful or not
        $ch2 = curl_init();
        curl_setopt_array($ch2, array(
                CURLOPT_URL => $environment_url.'?id='.$data['id'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$working_key,
                'Content-Type: application/json'
                )
            ));
        $check_second_response = curl_exec($ch2);
        if ($check_second_response === false) {
            die(curl_error($ch2));
        }
        $check_second_response_data = json_decode($check_second_response, true);
        curl_close($ch2);

        /**
        * Verifications of payments
        */

        // HTTP GET to /payments
        if ($check_second_response_data['succeeded'] != 1) {
            die('WooCommerce - RECORD WITH ID IS NOT FOUND');
        } elseif ($check_second_response_data['succeeded'] == 1) {
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

        if (0 == $amount || null == $amount) {
            return new WP_Error('papara', __('Refund Error: You need to specify a refund amount.', 'papara'));
        }
        // notification for full refunds
        if ($amount != $order->get_total()) {
            return new WP_Error('papara', __('Amount error: Need to refund: '.$order->get_total(), 'papara'));
        }

        $environment_url = ($this -> get_option('environment') == 'TRUE') ? 'https://merchant-api.papara.com/payments' : 'https://merchantapi-test-master.papara.com/payments';
        $working_key = $this -> get_option('working_key');

        // HTTP PUT request for refund
        $ch3 = curl_init();
        curl_setopt_array($ch3, array(
                CURLOPT_URL => $environment_url.'?id='.$order->get_transaction_id(),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => array(
                'ApiKey: '.$working_key,
                'Content-Type: application/json'
                ),
                CURLOPT_POSTFIELDS => ''
            ));
        $response = curl_exec($ch3);
        $response_data = json_decode($response, true);

        if ($response_data['succeeded'] == true) {
            $order->update_status('refunded');
            curl_close($ch3);

            // notify user, not an error
            return new WP_Error('papara', __('Refund succeeded!', 'papara'));
        }
    }
}
