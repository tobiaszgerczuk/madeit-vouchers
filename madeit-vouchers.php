<?php
/**
 * Plugin Name: MadeIT Vouchers
 * Plugin URI: https://labs.made-it.pl/en/plugins/madeit-vouchers-pro
 * Description: Sell gift vouchers with a balance in WooCommerce. Customers receive a PDF with a code and can spend it across several orders.
 * Version: 1.0.2
 * Requires at least: 6.6
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: MadeIT Labs
 * Author URI: https://labs.made-it.pl/en
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: madeit-vouchers
 * Domain Path: /languages
 * WC requires at least: 9.0
 * WC tested up to: 10.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Madeit\Vouchers\Plugin;
use Madeit\Vouchers\Repository;

defined('ABSPATH') || exit;

define('MADEIT_VOUCHERS_FILE', __FILE__);
define('MADEIT_VOUCHERS_VERSION', '1.0.2');

require_once __DIR__.'/src/Settings.php';
require_once __DIR__.'/src/Repository.php';
require_once __DIR__.'/src/VoucherPdf.php';
require_once __DIR__.'/src/Admin.php';
require_once __DIR__.'/src/Plugin.php';

register_activation_hook(__FILE__, ['Madeit\\Vouchers\\Repository', 'install']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        return;
    }

    (new Plugin(new Repository))->register();
    do_action('madeit_vouchers_loaded');
});
