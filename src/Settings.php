<?php

namespace Madeit\Vouchers;

defined('ABSPATH') || exit;

/**
 * Reads the plugin options and their defaults. Add-ons extend them through filters.
 */
final class Settings
{
    public const DEFAULT_BRAND_COLOR = '#294a74';

    /**
     * Default customer-facing texts. Placeholders such as {code} are replaced when the text is used.
     *
     * @return array<string, string>
     */
    public static function contentDefaults(): array
    {
        return [
            'product_eyebrow' => __('VOUCHER DETAILS', 'madeit-vouchers'),
            'product_title' => __('Who is this voucher for?', 'madeit-vouchers'),
            'product_description' => __('Choose the recipient. We send the code once the order is paid.', 'madeit-vouchers'),
            'recipient_self_title' => __('For me', 'madeit-vouchers'),
            'recipient_self_description' => __('The voucher goes to your email address', 'madeit-vouchers'),
            'recipient_gift_title' => __('As a gift', 'madeit-vouchers'),
            'recipient_gift_description' => __('We send it to the person you choose', 'madeit-vouchers'),
            'preview_heading' => __('This is your voucher', 'madeit-vouchers'),
            'preview_description' => __('We send it as a PDF once the order is paid.', 'madeit-vouchers'),
            'gift_email_label' => __('Recipient email address', 'madeit-vouchers'),
            'gift_message_label' => __('Message (optional)', 'madeit-vouchers'),
            'gift_message_placeholder' => __('Add a few words of your own…', 'madeit-vouchers'),
            'validity_notice' => __('Valid for {months} months after the order is paid. You can spend the balance over several purchases.', 'madeit-vouchers'),
            'pdf_title' => __('VOUCHER', 'madeit-vouchers'),
            'pdf_subtitle' => __('Give something special', 'madeit-vouchers'),
            'pdf_amount_label' => __('VALUE', 'madeit-vouchers'),
            'pdf_code_label' => __('VOUCHER CODE', 'madeit-vouchers'),
            'pdf_valid_until' => __('Valid until: {expires}', 'madeit-vouchers'),
            'redeem_eyebrow' => __('VOUCHER', 'madeit-vouchers'),
            'redeem_title' => __('Have a voucher code?', 'madeit-vouchers'),
            'redeem_description' => __('Enter the code from your voucher to use the available balance.', 'madeit-vouchers'),
            'redeem_code_label' => __('Voucher code', 'madeit-vouchers'),
            'redeem_placeholder' => __('Enter code', 'madeit-vouchers'),
            'redeem_apply' => __('Apply voucher', 'madeit-vouchers'),
            'redeem_remove' => __('Remove', 'madeit-vouchers'),
            'notice_applied' => __('Voucher applied. The balance is settled when you place the order.', 'madeit-vouchers'),
            'notice_removed' => __('Voucher removed from the cart.', 'madeit-vouchers'),
            'notice_invalid' => __('The voucher code is invalid, expired or has no balance left.', 'madeit-vouchers'),
            'notice_blocked' => __('Add a regular product to the cart. A voucher cannot be paid for with a voucher.', 'madeit-vouchers'),
            'notice_email_invalid' => __('Enter a valid email address for the recipient.', 'madeit-vouchers'),
            'email_subject' => __('Your voucher', 'madeit-vouchers'),
            'email_body' => __("Your voucher is attached as a PDF.\nCode: {code}\nValue: {amount}\nValid until: {expires}{message_section}\n", 'madeit-vouchers'),
            'email_message_section' => __("\n\nMessage from the buyer:\n{message}", 'madeit-vouchers'),
        ];
    }

    /**
     * Customer-facing texts. The add-on can replace them through the `madeit_vouchers_content` filter.
     *
     * @return array<string, string>
     */
    public static function content(): array
    {
        $defaults = self::contentDefaults();
        $content = apply_filters('madeit_vouchers_content', $defaults);

        return array_merge($defaults, array_intersect_key(is_array($content) ? $content : [], $defaults));
    }

    /**
     * @return array<string, string>
     */
    public static function templates(): array
    {
        $templates = apply_filters('madeit_vouchers_pdf_templates', ['classic' => __('Classic', 'madeit-vouchers')]);

        return is_array($templates) && isset($templates['classic']) ? $templates : ['classic' => __('Classic', 'madeit-vouchers')];
    }

    /**
     * Preset colours offered in addition to the shop colour.
     *
     * @return array<string, array{label: string, hex: string}>
     */
    public static function colors(): array
    {
        $colors = apply_filters('madeit_vouchers_pdf_colors', []);

        return is_array($colors) ? $colors : [];
    }

    /**
     * @return array{template: string, color: string, brand: string}
     */
    public static function design(): array
    {
        $saved = get_option('madeit_vouchers_design', []);
        $saved = is_array($saved) ? $saved : [];

        return self::sanitizeDesign((string) ($saved['template'] ?? ''), (string) ($saved['color'] ?? ''), (string) ($saved['brand'] ?? ''));
    }

    /**
     * @return array{template: string, color: string, brand: string}
     */
    public static function sanitizeDesign(string $template, string $color, string $brand): array
    {
        return [
            'template' => array_key_exists($template, self::templates()) ? $template : 'classic',
            'color' => $color === 'brand' || array_key_exists($color, self::colors()) ? $color : 'brand',
            'brand' => sanitize_hex_color($brand) ?: self::DEFAULT_BRAND_COLOR,
        ];
    }

    public static function accent(array $design): string
    {
        $colors = self::colors();

        return $design['color'] !== 'brand' && isset($colors[$design['color']]) ? $colors[$design['color']]['hex'] : $design['brand'];
    }

    public static function validityMonths(): int
    {
        return max(1, min(60, (int) get_option('madeit_vouchers_validity_months', 12)));
    }

    public static function isProActive(): bool
    {
        return defined('MADEIT_VOUCHERS_PRO_VERSION');
    }
}
