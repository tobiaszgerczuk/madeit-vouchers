<?php

namespace Madeit\Vouchers;

use wpdb;

defined('ABSPATH') || exit;

// Voucher lists are read straight from the plugin's own tables and must always be current, so queries are not cached.
// The WHERE fragment is assembled from fixed strings in filters(); every user-supplied value goes through prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

final class Admin
{
    public const UPGRADE_URL = 'https://labs.made-it.pl/en/plugins/madeit-vouchers-pro';

    private wpdb $db;

    private string $vouchers;

    private string $ledger;

    public function __construct(private Repository $repository)
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->vouchers = $wpdb->prefix.'madeit_vouchers';
        $this->ledger = $wpdb->prefix.'madeit_voucher_ledger';
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_madeit_vouchers_design', [$this, 'saveDesign']);
        add_action('admin_post_madeit_vouchers_validity', [$this, 'saveValidity']);
    }

    public function menu(): void
    {
        add_menu_page(__('Madeit Vouchers', 'madeit-vouchers'), __('Madeit Vouchers', 'madeit-vouchers'), 'manage_woocommerce', 'madeit-vouchers', [$this, 'renderVouchers'], 'dashicons-tickets-alt', 56);
        add_submenu_page('madeit-vouchers', __('Vouchers', 'madeit-vouchers'), __('Vouchers', 'madeit-vouchers'), 'manage_woocommerce', 'madeit-vouchers', [$this, 'renderVouchers']);
        add_submenu_page('madeit-vouchers', __('Settings', 'madeit-vouchers'), __('Settings', 'madeit-vouchers'), 'manage_woocommerce', 'madeit-vouchers-settings', [$this, 'renderSettings']);
    }

    public function assets(): void
    {
        // Only used to decide whether to load the stylesheet on this plugin's own screens.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (strpos($page, 'madeit-vouchers') !== 0) {
            return;
        }
        wp_enqueue_style('madeit-vouchers-admin', plugins_url('assets/admin.css', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION);
        if ($page === 'madeit-vouchers-settings') {
            wp_enqueue_style('madeit-vouchers-preview', plugins_url('assets/frontend.css', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION);
            wp_enqueue_script('madeit-vouchers-settings', plugins_url('assets/settings.js', MADEIT_VOUCHERS_FILE), [], MADEIT_VOUCHERS_VERSION, true);
        }
    }

    public function renderVouchers(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-vouchers'));
        }

        // Search and paging parameters only filter a read-only list.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $voucherId = absint($_GET['voucher'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $perPage = 20;
        [$where, $parameters] = $this->filters($search, $status);
        $countSql = "SELECT COUNT(*) FROM {$this->vouchers} WHERE {$where}";
        $total = (int) $this->db->get_var($parameters ? $this->db->prepare($countSql, ...$parameters) : $countSql);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT id, order_id, recipient_email, initial_cents, available_cents, currency, status, expires_at FROM {$this->vouchers} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            ...[...$parameters, $perPage, ($page - 1) * $perPage]
        ), ARRAY_A);
        $summary = $this->db->get_row("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' AND expires_at > UTC_TIMESTAMP() AND available_cents > 0 THEN 1 ELSE 0 END) AS active FROM {$this->vouchers}", ARRAY_A);
        $statuses = [
            '' => __('All', 'madeit-vouchers'),
            'active' => __('Active', 'madeit-vouchers'),
            'depleted' => __('No balance', 'madeit-vouchers'),
            'expired' => __('Expired', 'madeit-vouchers'),
            'revoked' => __('Revoked', 'madeit-vouchers'),
        ];
        ?>
        <div class="wrap madeit-admin">
            <div class="madeit-hero">
                <div>
                    <span class="madeit-eyebrow"><?php esc_html_e('MADEIT VOUCHERS / MANAGEMENT', 'madeit-vouchers'); ?></span>
                    <h1><?php esc_html_e('Vouchers', 'madeit-vouchers'); ?></h1>
                    <p><?php esc_html_e('Browse voucher balances and history. Select a voucher to see its details.', 'madeit-vouchers'); ?></p>
                </div>
                <a class="madeit-button madeit-button-light" href="<?php echo esc_url(admin_url('admin.php?page=madeit-vouchers-settings')); ?>"><?php esc_html_e('Settings', 'madeit-vouchers'); ?> <span aria-hidden="true">↗</span></a>
            </div>
            <?php $this->notice(); ?>
            <div class="madeit-stats">
                <div class="madeit-stat"><span><?php esc_html_e('All vouchers', 'madeit-vouchers'); ?></span><strong><?php echo esc_html((string) (int) ($summary['total'] ?? 0)); ?></strong></div>
                <div class="madeit-stat"><span><?php esc_html_e('Active with balance', 'madeit-vouchers'); ?></span><strong><?php echo esc_html((string) (int) ($summary['active'] ?? 0)); ?></strong></div>
                <div class="madeit-stat madeit-stat-note"><span><?php esc_html_e('Default validity', 'madeit-vouchers'); ?></span><strong>
                    <?php
                    /* translators: %d: number of months */
                    echo esc_html(sprintf(_n('%d month', '%d months', Settings::validityMonths(), 'madeit-vouchers'), Settings::validityMonths()));
                    ?>
                </strong></div>
            </div>
            <?php
            if ($voucherId > 0) {
                $this->voucherDetail($voucherId);
            }
            ?>
            <section class="madeit-card">
                <div class="madeit-section-heading"><div>
                    <h2><?php esc_html_e('Voucher list', 'madeit-vouchers'); ?></h2>
                    <?php /* translators: %s: number of results */ ?>
                    <p><?php echo esc_html(sprintf(__('Results: %s', 'madeit-vouchers'), (string) $total)); ?></p>
                </div></div>
                <form class="madeit-filters" method="get">
                    <input type="hidden" name="page" value="madeit-vouchers">
                    <label><span><?php esc_html_e('Search by ID, order or email address', 'madeit-vouchers'); ?></span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('For example 54 or customer@example.com', 'madeit-vouchers'); ?>"></label>
                    <label><span><?php esc_html_e('Status', 'madeit-vouchers'); ?></span><select name="status">
                        <?php foreach ($statuses as $value => $label) { ?>
                            <option value="<?php echo esc_attr($value); ?>"<?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php } ?>
                    </select></label>
                    <button class="madeit-button madeit-button-primary" type="submit"><?php esc_html_e('Show results', 'madeit-vouchers'); ?></button>
                    <?php if ($search !== '' || $status !== '') { ?>
                        <a class="madeit-reset" href="<?php echo esc_url(admin_url('admin.php?page=madeit-vouchers')); ?>"><?php esc_html_e('Clear', 'madeit-vouchers'); ?></a>
                    <?php } ?>
                </form>
                <?php if (! $rows) { ?>
                    <div class="madeit-empty"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span><h3><?php esc_html_e('No vouchers', 'madeit-vouchers'); ?></h3><p><?php esc_html_e('Change the filters or wait for the first paid order with a voucher.', 'madeit-vouchers'); ?></p></div>
                <?php } else { ?>
                    <div class="madeit-list">
                        <?php
                        foreach ($rows as $row) {
                            $this->voucherRow($row);
                        }
                        ?>
                    </div>
                <?php } ?>
                <div class="madeit-pagination">
                    <?php /* translators: 1: current page, 2: total pages */ ?>
                    <span><?php echo esc_html(sprintf(__('Page %1$s of %2$s', 'madeit-vouchers'), (string) $page, (string) $pages)); ?></span>
                    <div>
                        <?php if ($page > 1) { ?>
                            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($this->listUrl($search, $status, $page - 1)); ?>">← <?php esc_html_e('Previous', 'madeit-vouchers'); ?></a>
                        <?php } ?>
                        <?php if ($page < $pages) { ?>
                            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($this->listUrl($search, $status, $page + 1)); ?>"><?php esc_html_e('Next', 'madeit-vouchers'); ?> →</a>
                        <?php } ?>
                    </div>
                </div>
            </section>
        </div>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function settingsTabs(): array
    {
        $tabs = apply_filters('madeit_vouchers_settings_tabs', [
            'appearance' => __('Appearance', 'madeit-vouchers'),
            'validity' => __('Validity', 'madeit-vouchers'),
        ]);

        return is_array($tabs) ? $tabs : [];
    }

    public function renderSettings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-vouchers'));
        }

        $tabs = $this->settingsTabs();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the tab key only selects which screen to show.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'appearance';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $tab = isset($tabs[$tab]) ? $tab : 'appearance';
        ?>
        <div class="wrap madeit-admin">
            <div class="madeit-hero">
                <div>
                    <span class="madeit-eyebrow"><?php esc_html_e('MADEIT VOUCHERS / CONFIGURATION', 'madeit-vouchers'); ?></span>
                    <h1><?php esc_html_e('Settings', 'madeit-vouchers'); ?></h1>
                    <p><?php esc_html_e('Set the voucher appearance and the validity period.', 'madeit-vouchers'); ?></p>
                </div>
                <?php do_action('madeit_vouchers_settings_hero'); ?>
            </div>
            <?php $this->notice(); ?>
            <nav class="madeit-tabs" aria-label="<?php esc_attr_e('Voucher settings', 'madeit-vouchers'); ?>">
                <?php foreach ($tabs as $key => $label) { ?>
                    <a class="<?php echo esc_attr($tab === $key ? 'is-active' : ''); ?>" href="<?php echo esc_url(add_query_arg(['page' => 'madeit-vouchers-settings', 'tab' => $key], admin_url('admin.php'))); ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php } ?>
            </nav>
            <?php
            if ($tab === 'appearance') {
                $this->appearanceTab();
            } elseif ($tab === 'validity') {
                $this->validityTab();
            } else {
                /**
                 * Renders the body of a settings tab added through `madeit_vouchers_settings_tabs`.
                 *
                 * @param  string  $tab  Tab key.
                 */
                do_action('madeit_vouchers_render_settings_tab', $tab);
            }
            ?>
        </div>
        <?php
    }

    private function appearanceTab(): void
    {
        $design = Settings::design();
        $accent = Settings::accent($design);
        $content = Settings::content();
        $pro = Settings::isProActive();
        ?>
        <section class="madeit-card madeit-settings-card">
            <div class="madeit-section-heading"><div>
                <h2><?php esc_html_e('Voucher appearance', 'madeit-vouchers'); ?></h2>
                <p><?php esc_html_e('The colour is used for the PDF and for the forms on the product page, cart and checkout.', 'madeit-vouchers'); ?></p>
            </div></div>
            <div class="madeit-settings-layout">
                <form class="madeit-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="madeit_vouchers_design">
                    <?php wp_nonce_field('madeit_vouchers_design'); ?>
                    <label><span><?php esc_html_e('PDF design', 'madeit-vouchers'); ?></span><select name="template" data-madeit-design-template>
                        <?php foreach (Settings::templates() as $key => $label) { ?>
                            <option value="<?php echo esc_attr($key); ?>"<?php selected($design['template'], $key); ?>><?php echo esc_html($label); ?></option>
                        <?php } ?>
                    </select></label>
                    <label><span><?php esc_html_e('Colour scheme', 'madeit-vouchers'); ?></span><select name="color" data-madeit-design-color>
                        <option value="brand"<?php selected($design['color'], 'brand'); ?>><?php esc_html_e('Custom shop colour', 'madeit-vouchers'); ?></option>
                        <?php foreach (Settings::colors() as $key => $option) { ?>
                            <option value="<?php echo esc_attr($key); ?>" data-accent="<?php echo esc_attr($option['hex']); ?>"<?php selected($design['color'], $key); ?>><?php echo esc_html($option['label']); ?></option>
                        <?php } ?>
                    </select></label>
                    <label<?php echo $design['color'] === 'brand' ? '' : ' hidden'; ?>><span><?php esc_html_e('Custom shop colour', 'madeit-vouchers'); ?></span><input type="color" name="brand" value="<?php echo esc_attr($design['brand']); ?>" data-madeit-design-brand><small><?php esc_html_e('Used in the PDF and in the forms on the product page and in the cart.', 'madeit-vouchers'); ?></small></label>
                    <button class="madeit-button madeit-button-primary" type="submit"><?php esc_html_e('Save appearance', 'madeit-vouchers'); ?></button>
                    <?php if (! $pro) { ?>
                        <p class="madeit-pro-note">
                            <?php
                            printf(
                                /* translators: %s: link to the Pro add-on page */
                                esc_html__('Need more designs, preset colours or editable texts? %s adds them.', 'madeit-vouchers'),
                                '<a href="'.esc_url(self::UPGRADE_URL).'" target="_blank" rel="noopener">'.esc_html__('Madeit Vouchers Pro', 'madeit-vouchers').'</a>'
                            );
                            ?>
                        </p>
                    <?php } ?>
                </form>
                <div class="madeit-settings-preview">
                    <span><?php esc_html_e('PDF voucher preview', 'madeit-vouchers'); ?></span>
                    <div class="madeit-voucher-preview" data-madeit-settings-preview data-template="<?php echo esc_attr($design['template']); ?>" data-color="<?php echo esc_attr($design['color']); ?>" style="--brand-accent:<?php echo esc_attr($design['brand']); ?>">
                        <span class="madeit-preview-brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
                        <span class="madeit-preview-title"><?php echo esc_html($content['pdf_title']); ?></span>
                        <span class="madeit-preview-subtitle"><?php echo esc_html($content['pdf_subtitle']); ?></span>
                        <span class="madeit-preview-value"><?php echo wp_kses_post(wc_price(100)); ?></span>
                        <span class="madeit-preview-code"><?php echo esc_html($content['pdf_code_label']); ?></span>
                    </div>
                    <span class="madeit-interface-preview-title"><?php esc_html_e('Form colour preview', 'madeit-vouchers'); ?></span>
                    <div class="madeit-voucher-recipient madeit-interface-preview" data-madeit-ui-preview style="--madeit-accent:<?php echo esc_attr($accent); ?>">
                        <div class="madeit-voucher-intro"><span class="madeit-voucher-eyebrow"><?php echo esc_html($content['product_eyebrow']); ?></span><h3><?php echo esc_html($content['product_title']); ?></h3><p><?php echo esc_html($content['product_description']); ?></p></div>
                        <div class="madeit-voucher-choices">
                            <div class="madeit-voucher-choice is-selected"><span class="madeit-voucher-choice-icon">✦</span><span class="madeit-voucher-choice-copy"><strong><?php echo esc_html($content['recipient_self_title']); ?></strong><small><?php echo esc_html($content['recipient_self_description']); ?></small></span><span class="madeit-voucher-choice-check"></span></div>
                            <div class="madeit-voucher-choice"><span class="madeit-voucher-choice-icon">♡</span><span class="madeit-voucher-choice-copy"><strong><?php echo esc_html($content['recipient_gift_title']); ?></strong><small><?php echo esc_html($content['recipient_gift_description']); ?></small></span><span class="madeit-voucher-choice-check"></span></div>
                        </div>
                    </div>
                    <form class="madeit-voucher-redeem madeit-interface-preview" data-madeit-ui-preview style="--madeit-accent:<?php echo esc_attr($accent); ?>">
                        <div class="madeit-redeem-heading"><span class="madeit-voucher-eyebrow"><?php echo esc_html($content['redeem_eyebrow']); ?></span><strong><?php echo esc_html($content['redeem_title']); ?></strong><p><?php echo esc_html($content['redeem_description']); ?></p></div>
                        <label><span><?php echo esc_html($content['redeem_code_label']); ?></span><input placeholder="<?php echo esc_attr($content['redeem_placeholder']); ?>" disabled></label>
                        <div class="madeit-redeem-actions"><button type="button" disabled><?php echo esc_html($content['redeem_apply']); ?></button></div>
                    </form>
                </div>
            </div>
        </section>
        <?php
    }

    private function validityTab(): void
    {
        ?>
        <section class="madeit-card madeit-settings-card">
            <div class="madeit-section-heading"><div>
                <h2><?php esc_html_e('Voucher validity', 'madeit-vouchers'); ?></h2>
                <p><?php esc_html_e('Default validity of new vouchers. Vouchers that were already issued keep their date.', 'madeit-vouchers'); ?></p>
            </div></div>
            <form class="madeit-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="madeit_vouchers_validity">
                <?php wp_nonce_field('madeit_vouchers_validity'); ?>
                <label><span><?php esc_html_e('Validity in months', 'madeit-vouchers'); ?></span><input type="number" min="1" max="60" name="months" value="<?php echo esc_attr((string) Settings::validityMonths()); ?>" required></label>
                <button class="madeit-button madeit-button-primary" type="submit"><?php esc_html_e('Save validity', 'madeit-vouchers'); ?></button>
            </form>
        </section>
        <?php
    }

    public function saveDesign(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-vouchers'));
        }
        check_admin_referer('madeit_vouchers_design');
        $template = isset($_POST['template']) ? sanitize_key(wp_unslash($_POST['template'])) : 'classic';
        $color = isset($_POST['color']) ? sanitize_key(wp_unslash($_POST['color'])) : 'brand';
        $brand = isset($_POST['brand']) ? sanitize_hex_color(wp_unslash($_POST['brand'])) : '';
        update_option('madeit_vouchers_design', Settings::sanitizeDesign($template, $color, (string) $brand), false);
        wp_safe_redirect(admin_url('admin.php?page=madeit-vouchers-settings&tab=appearance&madeit_notice=settings'));
        exit;
    }

    public function saveValidity(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'madeit-vouchers'));
        }
        check_admin_referer('madeit_vouchers_validity');
        $months = isset($_POST['months']) ? absint(wp_unslash($_POST['months'])) : 12;
        update_option('madeit_vouchers_validity_months', max(1, min(60, $months)), false);
        wp_safe_redirect(admin_url('admin.php?page=madeit-vouchers-settings&tab=validity&madeit_notice=settings'));
        exit;
    }

    /**
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function filters(string $search, string $status): array
    {
        $where = '1=1';
        $parameters = [];
        if ($search !== '') {
            $email = '%'.$this->db->esc_like($search).'%';
            if (ctype_digit($search)) {
                $where .= ' AND (id = %d OR order_id = %d OR recipient_email LIKE %s)';
                array_push($parameters, (int) $search, (int) $search, $email);
            } else {
                $where .= ' AND recipient_email LIKE %s';
                $parameters[] = $email;
            }
        }
        if ($status === 'active') {
            $where .= " AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND available_cents > 0";
        } elseif ($status === 'depleted') {
            $where .= " AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND available_cents <= 0";
        } elseif ($status === 'expired') {
            $where .= " AND status = 'active' AND expires_at <= UTC_TIMESTAMP()";
        } elseif ($status === 'revoked') {
            $where .= " AND status = 'revoked'";
        }

        return [$where, $parameters];
    }

    private function listUrl(string $search, string $status, int $page): string
    {
        return add_query_arg(array_filter(['page' => 'madeit-vouchers', 's' => $search, 'status' => $status, 'paged' => $page], static fn ($value): bool => $value !== ''), admin_url('admin.php'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function voucherRow(array $row): void
    {
        [$label, $tone] = $this->status($row);
        $initial = (int) $row['initial_cents'];
        $available = (int) $row['available_cents'];
        $percent = $initial > 0 ? max(0, min(100, (int) round($available / $initial * 100))) : 0;
        $detailUrl = add_query_arg(['page' => 'madeit-vouchers', 'voucher' => (int) $row['id']], admin_url('admin.php'));
        ?>
        <article class="madeit-row">
            <div class="madeit-row-identity"><span class="madeit-row-number">#<?php echo esc_html((string) $row['id']); ?></span><strong><?php echo esc_html($row['recipient_email']); ?></strong><small><?php echo esc_html(sprintf(/* translators: %s: order number */ __('Order #%s', 'madeit-vouchers'), (string) $row['order_id'])); ?></small></div>
            <div class="madeit-row-balance"><span class="madeit-cell-label"><?php esc_html_e('Available balance', 'madeit-vouchers'); ?></span><strong><?php echo wp_kses_post(wc_price($available / 100, ['currency' => $row['currency']])); ?></strong><small><?php echo wp_kses_post(sprintf(/* translators: %s: initial voucher value */ __('of %s', 'madeit-vouchers'), wc_price($initial / 100, ['currency' => $row['currency']]))); ?></small><span class="madeit-progress"><span style="width:<?php echo esc_attr((string) $percent); ?>%"></span></span></div>
            <div class="madeit-row-status"><span class="madeit-cell-label"><?php esc_html_e('Status', 'madeit-vouchers'); ?></span><span class="madeit-badge madeit-badge-<?php echo esc_attr($tone); ?>"><?php echo esc_html($label); ?></span></div>
            <div class="madeit-row-date"><span class="madeit-cell-label"><?php esc_html_e('Valid until', 'madeit-vouchers'); ?></span><strong><?php echo esc_html($this->date($row['expires_at'])); ?></strong></div>
            <a class="madeit-button madeit-button-outline" href="<?php echo esc_url($detailUrl); ?>"><?php esc_html_e('Details', 'madeit-vouchers'); ?> →</a>
        </article>
        <?php
    }

    private function voucherDetail(int $voucherId): void
    {
        $voucher = $this->db->get_row($this->db->prepare('SELECT * FROM %i WHERE id = %d', $this->vouchers, $voucherId), ARRAY_A);
        if (! $voucher) {
            ?>
            <div class="madeit-notice madeit-notice-error"><?php echo esc_html(sprintf(/* translators: %s: voucher ID */ __('Voucher #%s not found.', 'madeit-vouchers'), (string) $voucherId)); ?></div>
            <?php
            return;
        }
        [$label, $tone] = $this->status($voucher);
        $order = wc_get_order((int) $voucher['order_id']);
        $entries = $this->db->get_results($this->db->prepare('SELECT kind, delta_cents, created_at FROM %i WHERE voucher_id = %d ORDER BY id DESC LIMIT 20', $this->ledger, $voucherId), ARRAY_A);
        $kinds = [
            'issue' => __('Issued', 'madeit-vouchers'),
            'reserve' => __('Reserved', 'madeit-vouchers'),
            'capture' => __('Settled', 'madeit-vouchers'),
            'release' => __('Released', 'madeit-vouchers'),
            'refund' => __('Refunded', 'madeit-vouchers'),
            'revoke' => __('Revoked', 'madeit-vouchers'),
            'extend' => __('Extended', 'madeit-vouchers'),
        ];
        ?>
        <section class="madeit-card madeit-detail">
            <div class="madeit-section-heading">
                <div><span class="madeit-eyebrow"><?php esc_html_e('VOUCHER DETAILS', 'madeit-vouchers'); ?></span><h2><?php echo esc_html(sprintf(/* translators: %s: voucher ID */ __('Voucher #%s', 'madeit-vouchers'), (string) $voucherId)); ?></h2></div>
                <a class="madeit-close" href="<?php echo esc_url(admin_url('admin.php?page=madeit-vouchers')); ?>"><?php esc_html_e('Close', 'madeit-vouchers'); ?> ×</a>
            </div>
            <div class="madeit-detail-grid">
                <div><span><?php esc_html_e('Recipient', 'madeit-vouchers'); ?></span><strong><?php echo esc_html($voucher['recipient_email']); ?></strong></div>
                <div><span><?php esc_html_e('Status', 'madeit-vouchers'); ?></span><strong class="madeit-badge madeit-badge-<?php echo esc_attr($tone); ?>"><?php echo esc_html($label); ?></strong></div>
                <div><span><?php esc_html_e('Value', 'madeit-vouchers'); ?></span><strong><?php echo wp_kses_post(wc_price((int) $voucher['initial_cents'] / 100, ['currency' => $voucher['currency']])); ?></strong></div>
                <div><span><?php esc_html_e('Available balance', 'madeit-vouchers'); ?></span><strong><?php echo wp_kses_post(wc_price((int) $voucher['available_cents'] / 100, ['currency' => $voucher['currency']])); ?></strong></div>
                <div><span><?php esc_html_e('Valid until', 'madeit-vouchers'); ?></span><strong><?php echo esc_html($this->date($voucher['expires_at'])); ?></strong></div>
                <div><span><?php esc_html_e('Order', 'madeit-vouchers'); ?></span><strong>
                    <?php if ($order) { ?>
                        <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $voucher['order_id']); ?> ↗</a>
                    <?php } else { ?>
                        #<?php echo esc_html((string) $voucher['order_id']); ?>
                    <?php } ?>
                </strong></div>
            </div>
            <?php
            /**
             * Lets an add-on print extra actions in the voucher detail, for example extending the validity.
             *
             * @param  array<string, mixed>  $voucher  Voucher row.
             */
            do_action('madeit_vouchers_voucher_detail_actions', $voucher);
            ?>
            <?php if ($entries) { ?>
                <div class="madeit-history">
                    <h3><?php esc_html_e('Recent operations', 'madeit-vouchers'); ?></h3>
                    <?php foreach ($entries as $entry) { ?>
                        <div><span><?php echo esc_html($kinds[$entry['kind']] ?? $entry['kind']); ?></span><span><?php echo esc_html($this->date($entry['created_at'], get_option('date_format').' '.get_option('time_format'))); ?></span><strong><?php echo wp_kses_post(wc_price((int) $entry['delta_cents'] / 100, ['currency' => $voucher['currency']])); ?></strong></div>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>
        <?php
    }

    private function notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the key only selects a fixed message.
        $notice = isset($_GET['madeit_notice']) ? sanitize_key(wp_unslash($_GET['madeit_notice'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $messages = apply_filters('madeit_vouchers_notice_messages', ['settings' => __('Settings saved.', 'madeit-vouchers')]);
        if (is_array($messages) && isset($messages[$notice])) {
            ?>
            <div class="madeit-notice" role="status"><?php echo esc_html($messages[$notice]); ?></div>
            <?php
        }
    }

    /**
     * @param  array<string, mixed>  $voucher
     * @return array{0: string, 1: string}
     */
    private function status(array $voucher): array
    {
        if ($voucher['status'] === 'revoked') {
            return [__('Revoked', 'madeit-vouchers'), 'muted'];
        }
        if ($voucher['expires_at'] <= current_time('mysql', true)) {
            return [__('Expired', 'madeit-vouchers'), 'warning'];
        }
        if ((int) $voucher['available_cents'] <= 0) {
            return [__('No balance', 'madeit-vouchers'), 'muted'];
        }

        return [__('Active', 'madeit-vouchers'), 'success'];
    }

    private function date(string $utc, ?string $format = null): string
    {
        return wp_date($format ?? get_option('date_format'), strtotime($utc.' UTC'), wp_timezone());
    }
}
