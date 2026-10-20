=== MadeIT Vouchers ===
Contributors: madeitpl
Tags: woocommerce, gift voucher, gift card, voucher, store credit
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell gift vouchers with a balance in WooCommerce. Customers get a PDF with a code and can spend it over several orders.

== Description ==

MadeIT Vouchers turns any simple WooCommerce product into a gift voucher. The product price is the voucher value. When the order is paid, the plugin creates a unique code, generates a PDF and emails it to the buyer or to the person they want to give it to.

Customers enter the code in a dedicated field above the cart or checkout (classic and block versions). The voucher balance is reserved when the order is placed, released if the order is cancelled or payment fails, and settled when the order is paid. Whatever is left stays on the voucher for the next purchase.

**Features**

* Any simple product can be a voucher: tick one checkbox, the product becomes virtual and sold individually.
* Buyer chooses "for me" or "as a gift", with the recipient's email address and an optional message.
* Unique codes are stored encrypted, only a hash is used to look vouchers up.
* PDF voucher in your shop colour, attached to the email.
* Works with the classic cart and checkout and with the WooCommerce Cart and Checkout blocks. Compatible with High-Performance Order Storage.
* Balance is reserved while an order is pending and returned automatically when it is cancelled, fails or is fully refunded.
* A voucher can pay for regular products, but not for another voucher.
* Admin screen with search, status filters, balances and a full history of every operation.
* Default validity period in months.
* Fully translatable.

**MadeIT Vouchers Pro**

An optional add-on from MadeIT Labs (sold separately at [labs.made-it.pl/en](https://labs.made-it.pl/en/plugins/madeit-vouchers-pro)) extends the plugin with additional PDF designs and preset colours, editable customer-facing texts, restoring balance after a partial refund and extending the validity of single vouchers. The free plugin is complete on its own and does not contact any external service.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
3. Edit a simple product, open the General tab of Product data and tick "Voucher". Set the price to the voucher value.
4. Open MadeIT Vouchers → Settings to choose the colour and the validity period.

Your site needs working outgoing email, because the voucher PDF is sent by email. The PHP GD extension with FreeType support is required to render the PDF.

== Frequently Asked Questions ==

= Does it work with the WooCommerce Cart and Checkout blocks? =

Yes. The voucher code field is shown above the block and the balance is applied to the order total.

= Can a customer pay for a voucher with another voucher? =

No. A voucher can only be redeemed against regular products.

= What happens when an order paid with a voucher is refunded? =

On a full refund the unused part of the payment is restored to the voucher automatically. Partial refunds can be restored manually with the Pro add-on.

= Which currencies are supported? =

Currencies with two decimal places. A voucher can only be redeemed in the currency it was issued in.

= Is customer data sent to external services? =

No. The plugin does not make any external requests.

= What happens to the data if I delete the plugin? =

Voucher tables are financial records, so they are kept when the plugin is deleted.

== Screenshots ==

1. Recipient choice and voucher preview on the product page.
2. Voucher code field in the cart and checkout.
3. Voucher list with balances and statuses.
4. Settings with live preview.

== Changelog ==

= 1.0.4 =
* Fixed: in the checkout block a voucher that covers the whole cart ended with "No payment method provided". The voucher balance is now also deducted from the order created by the block.

= 1.0.3 =
* Removed "Tested up to" from the plugin header. No functional changes.

= 1.0.2 =
* Readme: the contributor is now the WordPress.org user madeitpl. No functional changes.

= 1.0.1 =
* The publisher is now called MadeIT Labs. No functional changes.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.4 =
Fixes placing an order in the checkout block when a voucher covers the whole cart.

= 1.0.3 =
No functional changes.

= 1.0.2 =
Readme update only. No functional changes.

= 1.0.1 =
The publisher is now called MadeIT Labs. No functional changes.

= 1.0.0 =
First public release.
