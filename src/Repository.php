<?php

namespace Madeit\Vouchers;

use RuntimeException;
use wpdb;

defined('ABSPATH') || exit;

// The voucher balance lives in custom tables, so direct queries without caching are intentional here:
// balances must always be read fresh and changed inside transactions.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

final class Repository
{
    private wpdb $db;

    private string $vouchers;

    private string $holds;

    private string $ledger;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->vouchers = $wpdb->prefix.'madeit_vouchers';
        $this->holds = $wpdb->prefix.'madeit_voucher_holds';
        $this->ledger = $wpdb->prefix.'madeit_voucher_ledger';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}madeit_vouchers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_hash char(64) NOT NULL,
            code_secret text NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_item_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            recipient_email varchar(190) NOT NULL,
            initial_cents bigint(20) NOT NULL,
            available_cents bigint(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            currency char(3) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash),
            UNIQUE KEY order_item_id (order_item_id),
            KEY order_id (order_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}madeit_voucher_holds (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            voucher_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            amount_cents bigint(20) NOT NULL,
            refunded_cents bigint(20) NOT NULL DEFAULT 0,
            state varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY voucher_id (voucher_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}madeit_voucher_ledger (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            voucher_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            event_key varchar(100) NOT NULL,
            kind varchar(20) NOT NULL,
            delta_cents bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY voucher_id (voucher_id)
        ) $charset;");
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        $this->db->query('ROLLBACK');
    }

    private function ledger(int $voucherId, int $orderId, string $key, string $kind, int $delta): void
    {
        if (! $this->db->insert($this->ledger, [
            'voucher_id' => $voucherId,
            'order_id' => $orderId,
            'event_key' => $key,
            'kind' => $kind,
            'delta_cents' => $delta,
            'created_at' => current_time('mysql', true),
        ])) {
            throw new RuntimeException(esc_html__('Could not record the voucher operation.', 'madeit-vouchers'));
        }
    }

    /**
     * Plugin-owned encryption key, independent of WordPress authentication salts.
     */
    private function secretKey(): string
    {
        $stored = get_option('madeit_vouchers_secret_key');
        if (! is_string($stored) || strlen($stored) !== 64 || ! ctype_xdigit($stored)) {
            $stored = bin2hex(random_bytes(32));
            update_option('madeit_vouchers_secret_key', $stored, false);
        }

        return hex2bin($stored);
    }

    private function encrypt(string $code): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($code, 'aes-256-gcm', $this->secretKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException(esc_html__('Could not secure the voucher code.', 'madeit-vouchers'));
        }

        return base64_encode($nonce.$tag.$cipher);
    }

    public function reveal(string $secret): string
    {
        $raw = base64_decode($secret, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException(esc_html__('The voucher code cannot be read.', 'madeit-vouchers'));
        }
        $code = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->secretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($code === false) {
            throw new RuntimeException(esc_html__('The voucher code cannot be read.', 'madeit-vouchers'));
        }

        return $code;
    }

    public function issue(int $orderId, int $itemId, int $productId, string $email, int $amount, string $currency): array
    {
        if ($amount <= 0 || ! is_email($email)) {
            throw new RuntimeException(esc_html__('Invalid voucher data.', 'madeit-vouchers'));
        }
        $this->begin();
        try {
            $existing = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_item_id = %d FOR UPDATE", $this->vouchers, $itemId), ARRAY_A);
            if ($existing) {
                $this->commit();

                return $existing;
            }
            $code = strtoupper(bin2hex(random_bytes(12)));
            $now = current_time('mysql', true);
            $months = max(1, min(60, (int) get_option('madeit_vouchers_validity_months', 12)));
            $expires = gmdate('Y-m-d H:i:s', strtotime('+'.$months.' months', strtotime($now)));
            $inserted = $this->db->insert($this->vouchers, [
                'code_hash' => hash('sha256', $code),
                'code_secret' => $this->encrypt($code),
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'product_id' => $productId,
                'recipient_email' => $email,
                'initial_cents' => $amount,
                'available_cents' => $amount,
                'status' => 'active',
                'currency' => $currency,
                'expires_at' => $expires,
                'created_at' => $now,
            ]);
            if (! $inserted) {
                throw new RuntimeException(esc_html__('Could not create the voucher.', 'madeit-vouchers'));
            }
            $id = (int) $this->db->insert_id;
            $this->ledger($id, $orderId, 'issue:'.$itemId, 'issue', $amount);
            $voucher = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE id = %d", $this->vouchers, $id), ARRAY_A);
            $this->commit();

            return $voucher;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function find(string $code): ?array
    {
        $hash = hash('sha256', strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $code)));

        return $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE code_hash = %s", $this->vouchers, $hash), ARRAY_A) ?: null;
    }

    public function reserve(string $code, int $orderId, int $amount, string $currency): int
    {
        if ($amount <= 0) {
            throw new RuntimeException(esc_html__('The voucher amount must be positive.', 'madeit-vouchers'));
        }
        $this->begin();
        try {
            $voucher = $this->find($code);
            if (! $voucher) {
                throw new RuntimeException(esc_html__('Voucher not found.', 'madeit-vouchers'));
            }
            $voucher = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE id = %d FOR UPDATE", $this->vouchers, $voucher['id']), ARRAY_A);
            $existing = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_id = %d", $this->holds, $orderId), ARRAY_A);
            if ($existing) {
                if ($existing['state'] === 'released' || (int) $existing['voucher_id'] !== (int) $voucher['id'] || (int) $existing['amount_cents'] !== $amount) {
                    throw new RuntimeException(esc_html__('The order already has a different voucher reservation.', 'madeit-vouchers'));
                }
                $this->commit();

                return $amount;
            }
            if ($voucher['status'] !== 'active' || $voucher['currency'] !== $currency || $voucher['expires_at'] <= current_time('mysql', true) || (int) $voucher['available_cents'] < $amount) {
                throw new RuntimeException(esc_html__('The voucher has expired or its balance is too low.', 'madeit-vouchers'));
            }
            if (! $this->db->query($this->db->prepare("UPDATE %i SET available_cents = available_cents - %d WHERE id = %d", $this->vouchers, $amount, $voucher['id']))) {
                throw new RuntimeException(esc_html__('Could not reserve the balance.', 'madeit-vouchers'));
            }
            if (! $this->db->insert($this->holds, [
                'voucher_id' => $voucher['id'], 'order_id' => $orderId, 'amount_cents' => $amount,
                'state' => 'held', 'created_at' => current_time('mysql', true),
            ])) {
                throw new RuntimeException(esc_html__('Could not save the reservation.', 'madeit-vouchers'));
            }
            $this->ledger((int) $voucher['id'], $orderId, 'reserve:'.$orderId, 'reserve', -$amount);
            $this->commit();

            return $amount;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function holdForOrder(int $orderId): ?array
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_id = %d", $this->holds, $orderId), ARRAY_A) ?: null;
    }

    public function capture(int $orderId): void
    {
        $this->begin();
        try {
            $hold = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $this->holds, $orderId), ARRAY_A);
            if ($hold && $hold['state'] === 'held') {
                $this->db->update($this->holds, ['state' => 'captured'], ['id' => $hold['id']]);
                $this->ledger((int) $hold['voucher_id'], $orderId, 'capture:'.$orderId, 'capture', 0);
            }
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function release(int $orderId): void
    {
        $this->begin();
        try {
            $hold = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $this->holds, $orderId), ARRAY_A);
            if ($hold && $hold['state'] === 'held') {
                $voucher = $this->db->get_row($this->db->prepare("SELECT status FROM %i WHERE id = %d FOR UPDATE", $this->vouchers, $hold['voucher_id']), ARRAY_A);
                $delta = $voucher['status'] === 'active' ? (int) $hold['amount_cents'] : 0;
                if ($delta > 0) {
                    $this->db->query($this->db->prepare("UPDATE %i SET available_cents = available_cents + %d WHERE id = %d", $this->vouchers, $delta, $hold['voucher_id']));
                }
                $this->db->update($this->holds, ['state' => 'released'], ['id' => $hold['id']]);
                $this->ledger((int) $hold['voucher_id'], $orderId, 'release:'.$orderId, 'release', $delta);
            }
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function refund(int $orderId, int $refundId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $this->begin();
        try {
            $hold = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_id = %d FOR UPDATE", $this->holds, $orderId), ARRAY_A);
            $exists = $this->db->get_var($this->db->prepare("SELECT id FROM %i WHERE event_key = %s", $this->ledger, 'refund:'.$refundId));
            if ($exists) {
                $this->commit();

                return;
            }
            if (! $hold || $hold['state'] !== 'captured' || (int) $hold['refunded_cents'] + $amount > (int) $hold['amount_cents']) {
                throw new RuntimeException(esc_html__('Invalid amount to restore to the voucher.', 'madeit-vouchers'));
            }
            $voucher = $this->db->get_row($this->db->prepare("SELECT status FROM %i WHERE id = %d FOR UPDATE", $this->vouchers, $hold['voucher_id']), ARRAY_A);
            if ($voucher['status'] !== 'active') {
                throw new RuntimeException(esc_html__('The voucher was revoked. This refund has to be settled manually.', 'madeit-vouchers'));
            }
            $this->db->query($this->db->prepare("UPDATE %i SET available_cents = available_cents + %d WHERE id = %d", $this->vouchers, $amount, $hold['voucher_id']));
            $this->db->query($this->db->prepare("UPDATE %i SET refunded_cents = refunded_cents + %d WHERE id = %d", $this->holds, $amount, $hold['id']));
            $this->ledger((int) $hold['voucher_id'], $orderId, 'refund:'.$refundId, 'refund', $amount);
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function revokeIssuedForItem(int $itemId): int
    {
        $this->begin();
        try {
            $voucher = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE order_item_id = %d FOR UPDATE", $this->vouchers, $itemId), ARRAY_A);
            if (! $voucher || $voucher['status'] === 'revoked') {
                $this->commit();

                return 0;
            }
            $available = (int) $voucher['available_cents'];
            $this->db->update($this->vouchers, ['status' => 'revoked', 'available_cents' => 0], ['id' => $voucher['id']]);
            $this->ledger((int) $voucher['id'], (int) $voucher['order_id'], 'revoke:'.$itemId, 'revoke', -$available);
            $spent = (int) $voucher['initial_cents'] - $available;
            $this->commit();

            return max(0, $spent);
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function extend(int $voucherId, string $expires): void
    {
        $timestamp = strtotime($expires.' 23:59:59 UTC');
        if (! $timestamp || $timestamp <= time()) {
            throw new RuntimeException(esc_html__('The new expiry date must be in the future.', 'madeit-vouchers'));
        }
        $this->begin();
        try {
            $voucher = $this->db->get_row($this->db->prepare("SELECT * FROM %i WHERE id = %d FOR UPDATE", $this->vouchers, $voucherId), ARRAY_A);
            if (! $voucher || $voucher['status'] !== 'active' || $timestamp <= strtotime($voucher['expires_at'].' UTC')) {
                throw new RuntimeException(esc_html__('The expiry date can only be extended.', 'madeit-vouchers'));
            }
            $this->db->update($this->vouchers, ['expires_at' => gmdate('Y-m-d H:i:s', $timestamp)], ['id' => $voucherId]);
            $this->ledger($voucherId, (int) $voucher['order_id'], 'extend:'.$voucherId.':'.$timestamp, 'extend', 0);
            $this->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }
}
