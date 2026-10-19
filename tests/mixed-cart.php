<?php

use Madeit\Vouchers\Repository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

if (! WC()->session) {
    WC()->session = new WC_Session_Handler;
    WC()->session->init();
}
if (! WC()->customer) {
    WC()->customer = new WC_Customer(0, true);
}
WC()->cart = new WC_Cart;
update_option('woocommerce_currency', 'PLN');
update_option('woocommerce_ship_to_countries', 'disabled');

$repository = new Repository;
$testId = wp_rand(26000000, 29000000);
$voucher = $repository->issue($testId, $testId, $testId, 'mixed@example.invalid', 15000, 'PLN');
$code = $repository->reveal($voucher['code_secret']);

$voucherProduct = new WC_Product_Simple;
$voucherProduct->set_name('Nowy voucher testowy');
$voucherProduct->set_regular_price('100.00');
$voucherProduct->set_virtual(true);
$voucherProduct->set_sold_individually(true);
$voucherProduct->update_meta_data('_madeit_voucher_product', 'yes');
$voucherProduct->save();

$regularProduct = new WC_Product_Simple;
$regularProduct->set_name('Zwykły towar testowy');
$regularProduct->set_regular_price('100.00');
$regularProduct->save();

WC()->cart->add_to_cart($voucherProduct->get_id());
WC()->session->set('madeit_voucher_code', $code);
WC()->cart->calculate_totals();
if ((int) WC()->session->get('madeit_voucher_amount_cents') !== 0 || (int) round((float) WC()->cart->get_total('edit') * 100) !== 10000) {
    throw new RuntimeException('Nowego vouchera nie wolno opłacić starym voucherem.');
}

WC()->cart->add_to_cart($regularProduct->get_id());
WC()->cart->calculate_totals();
if ((int) WC()->session->get('madeit_voucher_amount_cents') !== 10000 || (int) round((float) WC()->cart->get_total('edit') * 100) !== 10000) {
    throw new RuntimeException('W koszyku mieszanym voucher powinien pokryć tylko zwykły towar.');
}

$result = WC_Checkout::instance()->create_order([
    'billing_first_name' => 'Test',
    'billing_last_name' => 'Mieszany',
    'billing_email' => 'mixed@example.invalid',
    'billing_country' => 'PL',
    'payment_method' => 'bacs',
]);
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
$order = wc_get_order($result);
if (! $order || (int) $order->get_meta('_madeit_voucher_amount_cents') !== 10000 || (int) $repository->find($code)['available_cents'] !== 5000) {
    throw new RuntimeException('Checkout nie zarezerwował prawidłowej kwoty z vouchera.');
}

echo "PASS: mixed cart charges voucher product and covers regular product only\n";
