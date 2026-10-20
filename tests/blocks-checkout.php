<?php

use Madeit\Vouchers\Repository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

function expectBlocks(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
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
$testId = wp_rand(15000000, 19000000);
$voucher = $repository->issue($testId, $testId, $testId, 'blocks@example.invalid', 5000, 'PLN');
$code = $repository->reveal($voucher['code_secret']);

$product = new WC_Product_Simple;
$product->set_name('Towar testowy');
$product->set_regular_price('50.00');
$product->save();
WC()->cart->add_to_cart($product->get_id());
WC()->session->set('madeit_voucher_code', $code);
WC()->cart->calculate_totals();
expectBlocks((float) WC()->cart->get_total('edit') === 0.0, 'Voucher nie pokrył całego koszyka.');

// The checkout block rebuilds the order from its line items, so the order starts at the full price.
$order = wc_create_order();
$order->set_currency('PLN');
$order->add_product($product, 1);
$order->calculate_totals();
$order->set_status('pending');
$order->save();
expectBlocks((float) $order->get_total() === 50.0, 'Zamówienie z bloku powinno zaczynać od pełnej ceny.');
expectBlocks($order->needs_payment() === false, 'Voucher pokrywający całość nie może wymagać metody płatności.');

do_action('woocommerce_store_api_checkout_order_processed', $order);
$order = wc_get_order($order->get_id());
expectBlocks((float) $order->get_total() === 0.0, 'Saldo vouchera nie zostało odjęte od zamówienia z bloku.');
expectBlocks((int) $order->get_meta('_madeit_voucher_amount_cents') === 5000, 'Zamówienie z bloku nie zapisało płatności voucherem.');
expectBlocks((int) $repository->find($code)['available_cents'] === 0, 'Zamówienie z bloku nie zarezerwowało salda.');

// A voucher that covers only part of the cart still leaves the rest to pay.
$partId = wp_rand(19000001, 22000000);
$partial = $repository->issue($partId, $partId, $partId, 'blocks-partial@example.invalid', 2000, 'PLN');
WC()->session->set('madeit_voucher_code', $repository->reveal($partial['code_secret']));
WC()->cart->calculate_totals();
$partialOrder = wc_create_order();
$partialOrder->set_currency('PLN');
$partialOrder->add_product($product, 1);
$partialOrder->calculate_totals();
$partialOrder->set_status('pending');
$partialOrder->save();
expectBlocks($partialOrder->needs_payment() === true, 'Voucher na część kwoty nie może znosić płatności.');
do_action('woocommerce_store_api_checkout_order_processed', $partialOrder);
expectBlocks((float) wc_get_order($partialOrder->get_id())->get_total() === 30.0, 'Częściowy voucher źle pomniejszył sumę zamówienia.');

echo "PASS: checkout block order deducts the voucher and skips payment when fully covered\n";
