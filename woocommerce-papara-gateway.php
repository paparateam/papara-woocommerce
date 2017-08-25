<?php
/*
Plugin Name: Woocommerce Papara Gateway
Plugin URI:  www.papara.com
Description: Accept payments on your Wordpress site via credit card or Papara account.
Version: 	 1.0
Author: 	 Muhammet Uçan
Text Domain: papara
Domain Path: /lang
Author URI:	 https://www.papara.com
License:     GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}
add_action('plugins_loaded', 'woocommerce_papara_gatewawy_init', 0);

add_action('plugins_loaded', 'papara_load_textdomain');
function papara_load_textdomain()
{
    load_plugin_textdomain('papara', false, dirname(plugin_basename(__FILE__)) . '/lang/');
}

function woocommerce_papara_gatewawy_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    include_once('woocommerce-papara.php');

    add_filter('woocommerce_payment_gateways', 'add_papara_gateway');

    function add_papara_gateway($methods)
    {
        $methods[] = 'Papara_Payment';
        return $methods;
    }
}

// Add custom action links
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'papara_action_links');
function papara_action_links($links)
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout') . '">' . __('Settings', 'papara') . '</a>',
    );

    // Merge our new link with the default ones
    return array_merge($plugin_links, $links);
}
