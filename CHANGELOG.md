# Changelog

## 1.0.5

- Security: voucher codes are now encrypted with a plugin-owned random key instead of a key derived from the WordPress authentication salts.
- Removed bundled translation files (translations are delivered by translate.wordpress.org).

## 1.0.4

- Fixed: in the checkout block a voucher that covers the whole cart ended with "No payment method provided". The voucher balance is now also deducted from the order created by the block.

## 1.0.3

- Removed the "Tested up to" line from the plugin header (it lives only in readme.txt). No functional changes.

## 1.0.2

- Readme: the contributor is now the WordPress.org user madeitpl. No functional changes.

## 1.0.1

- The publisher is now called MadeIT Labs (plugin header, texts and translations). No functional changes.

## 1.0.0

- First public release: voucher products, recipient choice, PDF by email, redeeming in cart and checkout (classic and blocks), balance ledger, admin list and settings.
