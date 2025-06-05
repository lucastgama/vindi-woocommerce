<?php

/**
 * Plugin Name: Vindi WooCommerce 2
 * Plugin URI: https://github.com/vindi/vindi-woocommerce
 * Description: Adiciona o gateway de pagamento da Vindi para o WooCommerce.
 * Author: Vindi
 * Author URI: https://www.vindi.com.br
 * Version: 1.3.9
 * Requires at least: 4.4
 * Tested up to: 6.4
 * Text Domain: vindi-payment-gateway
 *
 * Domain Path: ./src/languages/
 *
 * @package Vindi
 *
 */

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

// Adding the development variables
if(file_exists(plugin_dir_path(__FILE__) . '.env.php')) {
  include plugin_dir_path(__FILE__) . '.env.php';
}

// Adding the variables
require plugin_dir_path(__FILE__) . '/src/utils/DefinitionVariables.php';

// Adding dependency validator
require_once plugin_dir_path(__FILE__) . '/src/validators/Dependencies.php';

require_once VINDI_PATH . 'src/VindiWoocommerce.php';

if (!defined('VINDI_TESTS')) {
  // In tests we run the instance manually.
  // $GLOBALS['vindi'] = WcVindiPayment::get_instance();
}

// function disable_send_order() {
//   return false;
// }
// add_filter('send_order', 'disable_send_order');

// add_filter('pre_http_request', function($pre, $r, $url) {
//     return new WP_Error('bloqueado', 'Envio externo bloqueado pelo desenvolvedor: ' . esc_url_raw($url));
// }, 10, 3);

// add_action('init', function() {
//     // Mata todas as requisições imediatamente.
//     wp_die('Execução interrompida pelo desenvolvedor.');
// });