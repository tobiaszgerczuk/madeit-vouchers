<?php

use Madeit\Vouchers\Plugin;
use Madeit\Vouchers\Repository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

function expectCheckout(bool $condition, string $message): void
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
$testId = wp_rand(5000000, 9000000);
$voucher = $repository->issue($testId, $testId, $testId, 'checkout@example.invalid', 5000, 'PLN');
$code = $repository->reveal($voucher['code_secret']);

$product = new WC_Product_Simple;
$product->set_name('Towar testowy');
$product->set_regular_price('100.00');
$product->save();
WC()->cart->add_to_cart($product->get_id());
WC()->session->set('madeit_voucher_code', $code);
WC()->cart->calculate_totals();
expectCheckout((int) round((float) WC()->cart->get_total('edit') * 100) === 5000, 'Voucher nie zmniejszył kwoty do zapłaty.');
expectCheckout((int) WC()->session->get('madeit_voucher_amount_cents') === 5000, 'Kwota wykorzystania nie została zapamiętana.');

$order = wc_create_order();
$order->set_currency('PLN');
$order->set_total(WC()->cart->get_total('edit'));
$order->save();
(new Plugin($repository))->reserveForOrder($order);
expectCheckout((int) $repository->find($code)['available_cents'] === 0, 'Checkout nie zarezerwował salda.');
expectCheckout((int) $order->get_meta('_madeit_voucher_amount_cents') === 5000, 'Zamówienie nie zapisało płatności voucherem.');

$order->update_status('cancelled');
expectCheckout((int) $repository->find($code)['available_cents'] === 5000, 'Anulowanie nie przywróciło salda.');

$refundId = wp_rand(10000000, 15000000);
$secondVoucher = $repository->issue($refundId, $refundId, $refundId, 'refund@example.invalid', 5000, 'PLN');
$secondCode = $repository->reveal($secondVoucher['code_secret']);
$paidOrder = wc_create_order();
$paidOrder->set_currency('PLN');
$paidOrder->add_product($product, 1);
$paidOrder->calculate_totals();
$paidOrder->set_total(50);
$paidOrder->save();
$repository->reserve($secondCode, $paidOrder->get_id(), 5000, 'PLN');
$paidOrder->update_meta_data('_madeit_voucher_id', $secondVoucher['id']);
$paidOrder->update_meta_data('_madeit_voucher_amount_cents', 5000);
$paidOrder->save();
$paidOrder->payment_complete();
expectCheckout($repository->holdForOrder($paidOrder->get_id())['state'] === 'captured', 'Płatność nie zatwierdziła rezerwacji.');
$refund = wc_create_refund(['amount' => 50, 'order_id' => $paidOrder->get_id(), 'refund_payment' => false]);
expectCheckout($refund instanceof WC_Order_Refund, 'Nie udało się utworzyć zwrotu WooCommerce.');
expectCheckout((int) $repository->find($secondCode)['available_cents'] === 5000, 'Pełny zwrot nie przywrócił salda.');

echo "PASS: checkout total, reservation, cancellation, payment, full refund\n";
