<?php

namespace Madeit\Vouchers;

use WC_Order;
use WC_Order_Item_Product;

defined('ABSPATH') || exit;

final class Plugin
{
    public function __construct(private Repository $repository) {}

    public function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [$this, 'productField']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductField']);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'recipientFields']);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validateRecipient'], 10, 2);
        add_filter('woocommerce_add_cart_item_data', [$this, 'cartItemData'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'displayCartItemData'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copyRecipientToOrder'], 10, 3);
        add_action('woocommerce_payment_complete', [$this, 'issuePaidOrder']);
        add_action('woocommerce_order_status_processing', [$this, 'issuePaidOrder']);
        add_action('woocommerce_order_status_completed', [$this, 'issuePaidOrder']);
        add_action('woocommerce_order_status_changed', [$this, 'orderStatusChanged'], 10, 4);
        add_action('woocommerce_order_refunded', [$this, 'fullRefund'], 10, 2);
        add_action('woocommerce_order_refunded', [$this, 'revokeRefundedPurchase'], 20, 2);
        add_action('woocommerce_before_cart', [$this, 'redeemForm']);
        add_action('woocommerce_before_checkout_form', [$this, 'redeemForm']);
        add_filter('render_block', [$this, 'renderRedeemAfterBlock'], 10, 2);
        add_shortcode('madeit_voucher_redeem', [$this, 'redeemShortcode']);
        add_action('wp_loaded', [$this, 'handleRedeemForm']);
        add_filter('woocommerce_calculated_total', [$this, 'applyBalance'], 20, 2);
        add_filter('woocommerce_get_order_item_totals', [$this, 'showVoucherPayment'], 10, 2);
        add_action('woocommerce_checkout_order_created', [$this, 'reserveForOrder']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'reserveForOrder']);
        add_action('woocommerce_thankyou', [$this, 'clearRedeemedCode']);
        add_action('madeit_vouchers_check_pending_order', [$this, 'checkPendingOrder']);
        add_action('wp_enqueue_scripts', [$this, 'frontendAssets']);

        (new Admin($this->repository))->register();
    }

    private function isVoucher(int $productId): bool
    {
        return get_post_meta($productId, '_madeit_voucher_product', true) === 'yes';
    }

    public function productField(): void
    {
        woocommerce_wp_checkbox([
            'id' => '_madeit_voucher_product',
            'label' => __('Voucher', 'madeit-vouchers'),
            'description' => __('The product price is the voucher value. One voucher per order.', 'madeit-vouchers'),
        ]);
    }

    /**
     * @param  \WC_Product  $product
     */
    public function saveProductField($product): void
    {
        // WooCommerce verifies the product edit nonce before this hook runs.
        $enabled = isset($_POST['_madeit_voucher_product']) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product->update_meta_data('_madeit_voucher_product', $enabled);
        if ($enabled === 'yes') {
            $product->set_virtual(true);
            $product->set_sold_individually(true);
        }
    }

    /**
     * Recipient choice posted with the add-to-cart form. WooCommerce handles the add-to-cart request itself
     * and offers no nonce for it, so the values are only sanitized here and never trigger any state change.
     *
     * @return array{for: string, email: string, message: string}
     */
    private function postedRecipient(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $for = isset($_POST['madeit_voucher_for']) && sanitize_key(wp_unslash($_POST['madeit_voucher_for'])) === 'gift' ? 'gift' : 'self';
        $email = isset($_POST['madeit_voucher_email']) ? sanitize_email(wp_unslash($_POST['madeit_voucher_email'])) : '';
        $message = isset($_POST['madeit_voucher_message']) ? sanitize_textarea_field(wp_unslash($_POST['madeit_voucher_message'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return ['for' => $for, 'email' => $email, 'message' => $message];
    }

    public function recipientFields(): void
    {
        global $product;
        if (! $product || ! $this->isVoucher((int) $product->get_id())) {
            return;
        }
        $posted = $this->postedRecipient();
        $months = Settings::validityMonths();
        $design = Settings::design();
        $accent = Settings::accent($design);
        $content = Settings::content();
        ?>
        <section class="madeit-voucher-recipient" aria-labelledby="madeit-voucher-heading" data-madeit-voucher-form style="--madeit-accent:<?php echo esc_attr($accent); ?>">
            <div class="madeit-voucher-intro">
                <span class="madeit-voucher-eyebrow"><?php echo esc_html($content['product_eyebrow']); ?></span>
                <h3 id="madeit-voucher-heading"><?php echo esc_html($content['product_title']); ?></h3>
                <p><?php echo esc_html($content['product_description']); ?></p>
            </div>
            <div class="madeit-voucher-choices" role="radiogroup" aria-label="<?php esc_attr_e('Voucher recipient', 'madeit-vouchers'); ?>">
                <label class="madeit-voucher-choice">
                    <input type="radio" name="madeit_voucher_for" value="self"<?php checked($posted['for'], 'self'); ?>>
                    <span class="madeit-voucher-choice-icon" aria-hidden="true">✦</span>
                    <span class="madeit-voucher-choice-copy"><strong><?php echo esc_html($content['recipient_self_title']); ?></strong><small><?php echo esc_html($content['recipient_self_description']); ?></small></span>
                    <span class="madeit-voucher-choice-check" aria-hidden="true"></span>
                </label>
                <label class="madeit-voucher-choice">
                    <input type="radio" name="madeit_voucher_for" value="gift"<?php checked($posted['for'], 'gift'); ?>>
                    <span class="madeit-voucher-choice-icon" aria-hidden="true">♡</span>
                    <span class="madeit-voucher-choice-copy"><strong><?php echo esc_html($content['recipient_gift_title']); ?></strong><small><?php echo esc_html($content['recipient_gift_description']); ?></small></span>
                    <span class="madeit-voucher-choice-check" aria-hidden="true"></span>
                </label>
            </div>
            <div class="madeit-voucher-design">
                <div class="madeit-voucher-design-heading"><strong><?php echo esc_html($content['preview_heading']); ?></strong><span><?php echo esc_html($content['preview_description']); ?></span></div>
                <div class="madeit-voucher-preview" data-madeit-voucher-preview data-template="<?php echo esc_attr($design['template']); ?>" data-color="<?php echo esc_attr($design['color']); ?>" style="--brand-accent:<?php echo esc_attr($accent); ?>">
                    <span class="madeit-preview-brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
                    <span class="madeit-preview-title"><?php echo esc_html($content['pdf_title']); ?></span>
                    <span class="madeit-preview-subtitle"><?php echo esc_html($content['pdf_subtitle']); ?></span>
                    <span class="madeit-preview-value"><?php echo wp_kses_post($product->get_price_html()); ?></span>
                    <span class="madeit-preview-code"><?php echo esc_html($content['pdf_code_label']); ?></span>
                </div>
            </div>
            <div class="madeit-voucher-gift-fields" data-madeit-gift-fields>
                <label for="madeit-voucher-email"><span><?php echo esc_html($content['gift_email_label']); ?></span><input id="madeit-voucher-email" type="email" name="madeit_voucher_email" value="<?php echo esc_attr($posted['email']); ?>" maxlength="190" autocomplete="email" placeholder="name@example.com"></label>
                <label for="madeit-voucher-message"><span><?php echo esc_html($content['gift_message_label']); ?></span><textarea id="madeit-voucher-message" name="madeit_voucher_message" maxlength="500" rows="3" placeholder="<?php echo esc_attr($content['gift_message_placeholder']); ?>"><?php echo esc_textarea($posted['message']); ?></textarea></label>
            </div>
            <div class="madeit-voucher-footnote"><span aria-hidden="true">✓</span><p><?php echo esc_html(str_replace('{months}', (string) $months, $content['validity_notice'])); ?></p></div>
        </section>
        <?php
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function cartItemData(array $data, int $productId): array
    {
        if (! $this->isVoucher($productId)) {
            return $data;
        }
        $posted = $this->postedRecipient();
        $design = Settings::design();
        $data['madeit_voucher_for'] = $posted['for'];
        $data['madeit_voucher_email'] = $posted['for'] === 'gift' ? $posted['email'] : '';
        $data['madeit_voucher_message'] = $posted['for'] === 'gift' ? $posted['message'] : '';
        $data['madeit_voucher_template'] = $design['template'];
        $data['madeit_voucher_color'] = $design['color'];
        $data['madeit_voucher_brand_color'] = $design['brand'];

        return $data;
    }

    public function validateRecipient(bool $valid, int $productId): bool
    {
        if (! $this->isVoucher($productId)) {
            return $valid;
        }
        $posted = $this->postedRecipient();
        if ($posted['for'] === 'gift' && ! is_email($posted['email'])) {
            wc_add_notice(Settings::content()['notice_email_invalid'], 'error');

            return false;
        }

        return $valid;
    }

    /**
     * @param  array<int, array<string, string>>  $itemData
     * @param  array<string, mixed>  $cartItem
     * @return array<int, array<string, string>>
     */
    public function displayCartItemData(array $itemData, array $cartItem): array
    {
        if (($cartItem['madeit_voucher_for'] ?? '') === 'gift') {
            $itemData[] = ['name' => __('Recipient', 'madeit-vouchers'), 'value' => esc_html($cartItem['madeit_voucher_email'])];
        }
        $templates = Settings::templates();
        if (count($templates) > 1 && isset($cartItem['madeit_voucher_template'], $templates[$cartItem['madeit_voucher_template']])) {
            $itemData[] = ['name' => __('Design', 'madeit-vouchers'), 'value' => $templates[$cartItem['madeit_voucher_template']]];
        }

        return $itemData;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function copyRecipientToOrder(WC_Order_Item_Product $item, string $cartItemKey, array $values): void
    {
        if (! $this->isVoucher((int) $item->get_product_id())) {
            return;
        }
        $item->add_meta_data('_madeit_voucher_for', $values['madeit_voucher_for'] ?? 'self', true);
        $item->add_meta_data('_madeit_voucher_email', $values['madeit_voucher_email'] ?? '', true);
        $item->add_meta_data('_madeit_voucher_message', $values['madeit_voucher_message'] ?? '', true);
        $item->add_meta_data('_madeit_voucher_template', $values['madeit_voucher_template'] ?? 'classic', true);
        $item->add_meta_data('_madeit_voucher_color', $values['madeit_voucher_color'] ?? 'brand', true);
        $item->add_meta_data('_madeit_voucher_brand_color', $values['madeit_voucher_brand_color'] ?? Settings::DEFAULT_BRAND_COLOR, true);
    }

    public function issuePaidOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (! $order || ! $order->is_paid()) {
            return;
        }
        if (wc_get_price_decimals() !== 2) {
            $order->add_order_note(__('Voucher not issued: this version requires a currency with two decimal places.', 'madeit-vouchers'));

            return;
        }
        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product || ! $this->isVoucher((int) $item->get_product_id())) {
                continue;
            }
            $email = $item->get_meta('_madeit_voucher_for') === 'gift' ? $item->get_meta('_madeit_voucher_email') : $order->get_billing_email();
            $amount = (int) round(((float) $item->get_total() + (float) $item->get_total_tax()) * 100);
            try {
                $voucher = $this->repository->issue($orderId, $item->get_id(), (int) $item->get_product_id(), $email, $amount, $order->get_currency());
                if ($item->get_meta('_madeit_voucher_email_sent')) {
                    continue;
                }
                $code = $this->repository->reveal($voucher['code_secret']);
                $message = (string) $item->get_meta('_madeit_voucher_message');
                $expires = wp_date(get_option('date_format'), strtotime($voucher['expires_at'].' UTC'), wp_timezone());
                $content = Settings::content();
                $formattedAmount = html_entity_decode(wp_strip_all_tags(wc_price($amount / 100, ['currency' => $order->get_currency()])), ENT_QUOTES, 'UTF-8');
                $messageSection = $message === '' ? '' : str_replace('{message}', $message, $content['email_message_section']);
                $body = strtr($content['email_body'], ['{code}' => $code, '{amount}' => $formattedAmount, '{expires}' => $expires, '{message_section}' => $messageSection]);
                $pdf = (new VoucherPdf)->create(
                    (string) $item->get_meta('_madeit_voucher_template'),
                    (string) $item->get_meta('_madeit_voucher_color'),
                    $code,
                    $formattedAmount,
                    $expires,
                    $message,
                    wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    (string) $item->get_meta('_madeit_voucher_brand_color'),
                    $content
                );
                try {
                    $sent = wp_mail($email, $content['email_subject'], $body, [], [$pdf]);
                } finally {
                    wp_delete_file($pdf);
                }
                if ($sent) {
                    $item->update_meta_data('_madeit_voucher_email_sent', 'yes');
                    $item->save();
                } else {
                    /* translators: %s: recipient email address */
                    $order->add_order_note(sprintf(__('Could not send the voucher to %s. Send it again once email works.', 'madeit-vouchers'), $email));
                }
            } catch (\Throwable $error) {
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('Voucher issue error: %s', 'madeit-vouchers'), $error->getMessage()));
            }
        }
    }

    public function redeemForm(): void
    {
        if (function_exists('WC') && WC()->cart) {
            $this->renderRedeem();
        }
    }

    /**
     * @param  array<string, mixed>  $block
     */
    public function renderRedeemAfterBlock(string $content, array $block): string
    {
        if (is_admin() || ! in_array($block['blockName'] ?? '', ['woocommerce/cart', 'woocommerce/checkout'], true)) {
            return $content;
        }
        $page = get_post();
        if ($page && has_shortcode($page->post_content, 'madeit_voucher_redeem')) {
            return $content;
        }

        wp_enqueue_script('madeit-vouchers-move-redeem', plugins_url('assets/move-redeem.js', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION, true);
        $form = str_replace('<div class="madeit-redeem-wrap">', '<div class="madeit-redeem-wrap" data-madeit-above-block>', $this->redeemShortcode());

        return $content.$form;
    }

    public function redeemShortcode(): string
    {
        if (! function_exists('WC') || ! WC()->cart) {
            return '';
        }
        ob_start();
        $this->renderRedeem();

        return (string) ob_get_clean();
    }

    private function renderRedeem(): void
    {
        $code = WC()->session ? (string) WC()->session->get('madeit_voucher_code', '') : '';
        $content = Settings::content();
        $accent = Settings::accent(Settings::design());
        // The notice key only selects one of the fixed messages below, so no nonce is needed.
        $notice = isset($_GET['madeit_voucher_notice']) ? sanitize_key(wp_unslash($_GET['madeit_voucher_notice'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $messages = [
            'applied' => ['success', $content['notice_applied']],
            'removed' => ['success', $content['notice_removed']],
            'invalid' => ['error', $content['notice_invalid']],
            'blocked' => ['error', $content['notice_blocked']],
        ];
        ?>
        <div class="madeit-redeem-wrap">
            <?php if (isset($messages[$notice])) { ?>
                <div class="madeit-redeem-feedback madeit-redeem-feedback-<?php echo esc_attr($messages[$notice][0]); ?>" role="<?php echo esc_attr($messages[$notice][0] === 'error' ? 'alert' : 'status'); ?>"><?php echo esc_html($messages[$notice][1]); ?></div>
            <?php } ?>
            <form method="post" class="madeit-voucher-redeem" style="--madeit-accent:<?php echo esc_attr($accent); ?>">
                <div class="madeit-redeem-heading">
                    <span class="madeit-voucher-eyebrow"><?php echo esc_html($content['redeem_eyebrow']); ?></span>
                    <strong><?php echo esc_html($content['redeem_title']); ?></strong>
                    <p><?php echo esc_html($content['redeem_description']); ?></p>
                </div>
                <label><span><?php echo esc_html($content['redeem_code_label']); ?></span><input name="madeit_voucher_code" value="<?php echo esc_attr($code); ?>" autocomplete="off" placeholder="<?php echo esc_attr($content['redeem_placeholder']); ?>"></label>
                <?php wp_nonce_field('madeit_voucher_redeem', 'madeit_voucher_nonce'); ?>
                <div class="madeit-redeem-actions">
                    <button name="madeit_voucher_action" value="apply" type="submit"><?php echo esc_html($content['redeem_apply']); ?></button>
                    <?php if ($code !== '') { ?>
                        <button class="madeit-redeem-remove" name="madeit_voucher_action" value="remove" type="submit"><?php echo esc_html($content['redeem_remove']); ?></button>
                    <?php } ?>
                </div>
            </form>
        </div>
        <?php
    }

    public function handleRedeemForm(): void
    {
        if (! isset($_POST['madeit_voucher_action'], $_POST['madeit_voucher_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['madeit_voucher_nonce'])), 'madeit_voucher_redeem')) {
            return;
        }
        if (! function_exists('WC') || ! WC()->session) {
            return;
        }
        $notice = 'invalid';
        if (sanitize_key(wp_unslash($_POST['madeit_voucher_action'])) === 'remove') {
            WC()->session->set('madeit_voucher_code', '');
            WC()->session->set('madeit_voucher_amount_cents', 0);
            $notice = 'removed';
        } elseif (! WC()->cart || ! $this->cartHasRedeemableProduct(WC()->cart)) {
            $notice = 'blocked';
        } else {
            $code = isset($_POST['madeit_voucher_code']) ? sanitize_text_field(wp_unslash($_POST['madeit_voucher_code'])) : '';
            $voucher = $this->repository->find($code);
            if (! $voucher || ! $this->isUsable($voucher) || (int) $voucher['available_cents'] <= 0) {
                $notice = 'invalid';
            } else {
                WC()->session->set('madeit_voucher_code', $code);
                $notice = 'applied';
            }
        }
        if (WC()->cart) {
            WC()->cart->calculate_totals();
        }
        $target = remove_query_arg('madeit_voucher_notice', wp_get_referer() ?: wc_get_cart_url());
        wp_safe_redirect(add_query_arg('madeit_voucher_notice', $notice, $target));
        exit;
    }

    /**
     * @param  array<string, mixed>  $voucher
     */
    private function isUsable(array $voucher): bool
    {
        return $voucher['status'] === 'active'
            && $voucher['expires_at'] > current_time('mysql', true)
            && $voucher['currency'] === get_woocommerce_currency();
    }

    private function cartHasRedeemableProduct(\WC_Cart $cart): bool
    {
        foreach ($cart->get_cart() as $item) {
            if (! $this->isVoucher((int) $item['product_id'])) {
                return true;
            }
        }

        return false;
    }

    public function applyBalance(float $total, \WC_Cart $cart): float
    {
        if (wc_get_price_decimals() !== 2 || ! WC()->session) {
            return $total;
        }
        if (! $this->cartHasRedeemableProduct($cart)) {
            WC()->session->set('madeit_voucher_amount_cents', 0);

            return $total;
        }
        $code = (string) WC()->session->get('madeit_voucher_code', '');
        if ($code === '') {
            return $total;
        }
        $voucher = $this->repository->find($code);
        if (! $voucher || ! $this->isUsable($voucher)) {
            WC()->session->set('madeit_voucher_amount_cents', 0);

            return $total;
        }
        $voucherProductCents = 0;
        foreach ($cart->get_cart() as $item) {
            if ($this->isVoucher((int) $item['product_id'])) {
                $voucherProductCents += (int) round(((float) $item['line_total'] + (float) $item['line_tax']) * 100);
            }
        }
        $redeemableCents = max(0, (int) round($total * 100) - $voucherProductCents);
        $amount = min((int) $voucher['available_cents'], $redeemableCents);
        WC()->session->set('madeit_voucher_amount_cents', $amount);

        return max(0, $total - $amount / 100);
    }

    /**
     * @param  array<string, array<string, string>>  $totals
     * @return array<string, array<string, string>>
     */
    public function showVoucherPayment(array $totals, WC_Order $order): array
    {
        $amount = (int) $order->get_meta('_madeit_voucher_amount_cents');
        if ($amount <= 0) {
            return $totals;
        }
        $row = ['madeit_voucher' => ['label' => __('Paid with voucher:', 'madeit-vouchers'), 'value' => '-'.wc_price($amount / 100, ['currency' => $order->get_currency()])]];
        $result = [];
        foreach ($totals as $key => $value) {
            if ($key === 'order_total') {
                $result += $row;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    public function reserveForOrder(WC_Order $order): void
    {
        if (! WC()->session || $order->get_meta('_madeit_voucher_id')) {
            return;
        }
        $code = (string) WC()->session->get('madeit_voucher_code', '');
        $amount = (int) WC()->session->get('madeit_voucher_amount_cents', 0);
        if ($code === '' || $amount <= 0) {
            return;
        }
        $this->repository->reserve($code, $order->get_id(), $amount, $order->get_currency());
        $voucher = $this->repository->find($code);
        $order->update_meta_data('_madeit_voucher_id', (int) $voucher['id']);
        $order->update_meta_data('_madeit_voucher_amount_cents', $amount);
        $order->save();
        if (! wp_next_scheduled('madeit_vouchers_check_pending_order', [$order->get_id()])) {
            wp_schedule_single_event(time() + DAY_IN_SECONDS, 'madeit_vouchers_check_pending_order', [$order->get_id()]);
        }
    }

    public function orderStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
    {
        try {
            if (in_array($to, ['processing', 'completed'], true) && $order->is_paid()) {
                $this->repository->capture($orderId);
            } elseif (in_array($to, ['cancelled', 'failed'], true) && ! $order->is_paid()) {
                $this->repository->release($orderId);
            }
        } catch (\Throwable $error) {
            /* translators: %s: error message */
            $order->add_order_note(sprintf(__('Voucher settlement error: %s', 'madeit-vouchers'), $error->getMessage()));
        }
    }

    public function fullRefund(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $hold = $this->repository->holdForOrder($orderId);
        if (! $order || ! $hold || $hold['state'] !== 'captured') {
            return;
        }
        $remaining = (int) $hold['amount_cents'] - (int) $hold['refunded_cents'];
        if ($remaining > 0 && (float) $order->get_total_refunded() >= (float) $order->get_total()) {
            try {
                $this->repository->refund($orderId, $refundId, $remaining);
            } catch (\Throwable $error) {
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('Error restoring the balance to the voucher: %s', 'madeit-vouchers'), $error->getMessage()));
            }
        }
    }

    public function revokeRefundedPurchase(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);
        if (! $order || ! $refund) {
            return;
        }
        $fullyRefunded = (float) $order->get_total_refunded() >= (float) $order->get_total();
        foreach ($order->get_items() as $item) {
            if (! $this->isVoucher((int) $item->get_product_id())) {
                continue;
            }
            $itemRefunded = false;
            foreach ($refund->get_items() as $refundedItem) {
                if ((int) $refundedItem->get_meta('_refunded_item_id') === (int) $item->get_id()) {
                    $itemRefunded = true;
                    break;
                }
            }
            if (! $fullyRefunded && ! $itemRefunded) {
                continue;
            }
            try {
                $spent = $this->repository->revokeIssuedForItem((int) $item->get_id());
                if ($spent > 0) {
                    /* translators: %s: amount already spent from the voucher */
                    $order->add_order_note(sprintf(__('Warning: the refunded voucher had already been used for %s. It needs to be settled manually.', 'madeit-vouchers'), wp_strip_all_tags(wc_price($spent / 100))));
                }
            } catch (\Throwable $error) {
                /* translators: %s: error message */
                $order->add_order_note(sprintf(__('Error revoking the refunded voucher: %s', 'madeit-vouchers'), $error->getMessage()));
            }
        }
    }

    public function checkPendingOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order && ! $order->is_paid() && $order->has_status(['pending', 'failed', 'cancelled'])) {
            if ($order->has_status('pending')) {
                $order->update_status('cancelled', __('The voucher reservation expired after 24 hours.', 'madeit-vouchers'));
            }
            $this->repository->release($orderId);
        }
    }

    public function clearRedeemedCode(): void
    {
        if (WC()->session) {
            WC()->session->set('madeit_voucher_code', '');
            WC()->session->set('madeit_voucher_amount_cents', 0);
        }
    }

    public function frontendAssets(): void
    {
        wp_enqueue_style('madeit-vouchers-frontend', plugins_url('assets/frontend.css', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION);
        if (function_exists('is_product') && is_product() && $this->isVoucher(get_queried_object_id())) {
            wp_enqueue_script('madeit-vouchers-frontend', plugins_url('assets/frontend.js', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION, true);
        }
    }
}
