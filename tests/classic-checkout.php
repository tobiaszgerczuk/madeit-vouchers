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
$id = wp_rand(16000000, 19000000);
$voucher = $repository->issue($id, $id, $id, 'classic@example.invalid', 4000, 'PLN');
$code = $repository->reveal($voucher['code_secret']);
$product = new WC_Product_Simple;
$product->set_name('Towar classic checkout');
$product->set_regular_price('100.00');
$product->save();
WC()->cart->add_to_cart($product->get_id());
WC()->session->set('madeit_voucher_code', $code);
WC()->cart->calculate_totals();

$result = WC_Checkout::instance()->create_order([
    'billing_first_name' => 'Test',
    'billing_last_name' => 'Klient',
    'billing_email' => 'classic@example.invalid',
    'billing_country' => 'PL',
    'payment_method' => 'bacs',
]);
if (is_wp_error($result)) {
    throw new RuntimeException($result->get_error_message());
}
$order = wc_get_order($result);
if (! $order || (int) round((float) $order->get_total() * 100) !== 6000 || (int) $order->get_meta('_madeit_voucher_amount_cents') !== 4000 || (int) $repository->find($code)['available_cents'] !== 0) {
    throw new RuntimeException('Klasyczny checkout nie zapisał poprawnie płatności voucherem.');
}
$order->update_status('cancelled');
if ((int) $repository->find($code)['available_cents'] !== 4000) {
    throw new RuntimeException('Anulowanie zamówienia z checkoutu nie przywróciło salda.');
}
echo "PASS: classic checkout creates order for 60 PLN, reserves 40 PLN, releases on cancel\n";
