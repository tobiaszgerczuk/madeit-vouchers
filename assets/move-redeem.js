/**
 * Moves the voucher code form above the cart or checkout block once the block has loaded.
 */
(function () {
    'use strict';

    function moveAboveBlock(item) {
        var block = document.querySelector('.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout');
        if (!block || !block.parentNode) {
            return;
        }
        function place() {
            block.parentNode.insertBefore(item, block);
        }
        if (!block.classList.contains('is-loading')) {
            place();
            return;
        }
        // Inserting an element before the block in the HTML breaks its startup, so it is moved only after the block has loaded.
        var observer = new MutationObserver(function () {
            if (!block.classList.contains('is-loading')) {
                observer.disconnect();
                window.clearTimeout(timer);
                place();
            }
        });
        var timer = window.setTimeout(function () { observer.disconnect(); }, 15000);
        observer.observe(block, { attributes: true, attributeFilter: ['class'] });
    }

    document.querySelectorAll('[data-madeit-above-block]').forEach(moveAboveBlock);
})();
