<?php

use Madeit\Vouchers\Repository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

function expectVoucher(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

global $wpdb;
$sentMail = [];
add_filter('pre_wp_mail', static function ($result, $args) use (&$sentMail) {
    if (($args['subject'] ?? '') === 'Your voucher') {
        $attachment = $args['attachments'][0] ?? '';
        expectVoucher(is_string($attachment) && str_ends_with($attachment, '.pdf') && str_starts_with((string) file_get_contents($attachment), '%PDF-'), 'Brak poprawnego załącznika PDF.');
    }
    $sentMail[] = $args;

    return true;
}, 10, 2);
$product = new WC_Product_Simple;
$product->set_name('Voucher testowy');
$product->set_regular_price('100.00');
$product->set_virtual(true);
$product->set_sold_individually(true);
$product->update_meta_data('_madeit_voucher_product', 'yes');
$product->save();
$previousDesign = get_option('madeit_vouchers_design', null);
update_option('madeit_vouchers_design', ['template' => 'classic', 'color' => 'brand', 'brand' => '#294a74'], false);

$_POST['madeit_voucher_for'] = 'gift';
$_POST['madeit_voucher_email'] = 'friend@example.invalid';
$selection = (new Madeit\Vouchers\Plugin(new Repository))->cartItemData([], $product->get_id());
expectVoucher($selection['madeit_voucher_template'] === 'classic' && $selection['madeit_voucher_color'] === 'brand', 'Wygląd nie został zapisany w koszyku.');
$selectionItem = new WC_Order_Item_Product;
$selectionItem->set_product($product);
(new Madeit\Vouchers\Plugin(new Repository))->copyRecipientToOrder($selectionItem, 'test', $selection, new WC_Order);
expectVoucher($selectionItem->get_meta('_madeit_voucher_template') === 'classic' && $selectionItem->get_meta('_madeit_voucher_color') === 'brand', 'Wygląd nie został zapisany w zamówieniu.');
if ($previousDesign === null) {
    delete_option('madeit_vouchers_design');
} else {
    update_option('madeit_vouchers_design', $previousDesign, false);
}
unset($_POST['madeit_voucher_for'], $_POST['madeit_voucher_email']);

$order = wc_create_order();
$order->set_billing_email('voucher-test@example.invalid');
$item = new WC_Order_Item_Product;
$item->set_product($product);
$item->set_quantity(1);
$item->set_total(100);
$item->add_meta_data('_madeit_voucher_for', 'self', true);
$order->add_item($item);
$order->calculate_totals();
$order->save();
$order->payment_complete();

$repository = new Repository;
$table = $wpdb->prefix.'madeit_vouchers';
$voucher = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $order->get_id()), ARRAY_A);
expectVoucher((bool) $voucher, 'Voucher nie został wydany po opłaceniu zamówienia.');
expectVoucher((int) $voucher['available_cents'] === 10000, 'Początkowe saldo jest nieprawidłowe.');
expectVoucher(in_array('voucher-test@example.invalid', array_column($sentMail, 'to'), true), 'Kod nie został wysłany kupującemu.');
$code = $repository->reveal($voucher['code_secret']);
$oldExpiry = $voucher['expires_at'];
$repository->extend((int) $voucher['id'], gmdate('Y-m-d', strtotime('+2 years')));
expectVoucher($repository->find($code)['expires_at'] > $oldExpiry, 'Przedłużenie ważności nie zadziałało.');
$reserveOrder = $order->get_id() + 1000000;
$otherOrder = $order->get_id() + 2000000;
$releaseOrder = $order->get_id() + 3000000;
$refundId = $order->get_id() + 4000000;

$repository->reserve($code, $reserveOrder, 4000, $order->get_currency());
$repository->reserve($code, $reserveOrder, 4000, $order->get_currency());
$voucher = $repository->find($code);
expectVoucher((int) $voucher['available_cents'] === 6000, 'Ponowiona rezerwacja pobrała saldo drugi raz.');

$rejected = false;
try {
    $repository->reserve($code, $otherOrder, 7000, $order->get_currency());
} catch (RuntimeException $error) {
    $rejected = true;
}
expectVoucher($rejected, 'Niedostateczne saldo nie zostało odrzucone.');

$repository->capture($reserveOrder);
$repository->capture($reserveOrder);
$repository->refund($reserveOrder, $refundId, 1000);
$repository->refund($reserveOrder, $refundId, 1000);
expectVoucher((int) $repository->find($code)['available_cents'] === 7000, 'Zwrot został naliczony błędnie.');

$repository->reserve($code, $releaseOrder, 2000, $order->get_currency());
$repository->release($releaseOrder);
$repository->release($releaseOrder);
expectVoucher((int) $repository->find($code)['available_cents'] === 7000, 'Zwolnienie rezerwacji zostało naliczone błędnie.');

$spent = $repository->revokeIssuedForItem($item->get_id());
expectVoucher($spent === 3000, 'Kwota wykorzystanego vouchera jest nieprawidłowa.');
expectVoucher((int) $repository->find($code)['available_cents'] === 0, 'Zwrócony voucher nadal ma saldo.');

$gift = wc_create_order();
$gift->set_billing_email('buyer@example.invalid');
$giftItem = new WC_Order_Item_Product;
$giftItem->set_product($product);
$giftItem->set_quantity(1);
$giftItem->set_total(100);
$giftItem->add_meta_data('_madeit_voucher_for', 'gift', true);
$giftItem->add_meta_data('_madeit_voucher_email', 'friend@example.invalid', true);
$giftItem->add_meta_data('_madeit_voucher_message', 'Wszystkiego dobrego!', true);
$giftItem->add_meta_data('_madeit_voucher_template', 'botanical', true);
$giftItem->add_meta_data('_madeit_voucher_color', 'forest', true);
$gift->add_item($giftItem);
$gift->calculate_totals();
$gift->save();
$gift->payment_complete();
$giftVoucher = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $gift->get_id()), ARRAY_A);
expectVoucher($giftVoucher['recipient_email'] === 'friend@example.invalid', 'Voucher prezentowy ma złego odbiorcę.');
expectVoucher(in_array('friend@example.invalid', array_column($sentMail, 'to'), true), 'Kod prezentowy nie został wysłany obdarowanemu.');

echo "PASS: issue, gift email, reserve, reject overspend, capture, refund, release, revoke\n";
