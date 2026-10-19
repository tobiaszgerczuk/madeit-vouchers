<?php

use Madeit\Vouchers\Repository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

if (! WC()->session) {
    WC()->session = new WC_Session_Handler;
    WC()->session->init();
}
WC()->customer = new WC_Customer(0, true);
WC()->customer->set_billing_country('PL');
WC()->customer->set_shipping_country('PL');
WC()->cart = new WC_Cart;
update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_currency', 'PLN');
$rateId = WC_Tax::_insert_tax_rate([
    'tax_rate_country' => 'PL', 'tax_rate_state' => '', 'tax_rate' => '23.0000',
    'tax_rate_name' => 'VAT test', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
    'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '',
]);

try {
    $product = new WC_Product_Simple;
    $product->set_name('Produkt VAT test');
    $product->set_regular_price('100.00');
    $product->set_tax_status('taxable');
    $product->save();
    WC()->cart->add_to_cart($product->get_id());
    WC()->cart->calculate_totals();
    $taxBefore = (float) WC()->cart->get_total_tax();
    $totalBefore = (float) WC()->cart->get_total('edit');
    if ($taxBefore <= 0) {
        throw new RuntimeException('Sklep testowy nie obliczył podatku.');
    }
    $repository = new Repository;
    $id = wp_rand(20000000, 25000000);
    $voucher = $repository->issue($id, $id, $id, 'tax@example.invalid', 4000, 'PLN');
    WC()->session->set('madeit_voucher_code', $repository->reveal($voucher['code_secret']));
    WC()->cart->calculate_totals();
    $taxAfter = (float) WC()->cart->get_total_tax();
    $totalAfter = (float) WC()->cart->get_total('edit');
    if (abs($taxBefore - $taxAfter) > 0.001 || abs(($totalBefore - $totalAfter) - 40.0) > 0.001) {
        throw new RuntimeException('Voucher zmienił podatek lub nie obniżył płatności o 40 zł.');
    }
    echo "PASS: VAT unchanged, payment reduced by 40 PLN\n";
} finally {
    WC_Tax::_delete_tax_rate($rateId);
    update_option('woocommerce_calc_taxes', 'no');
}
