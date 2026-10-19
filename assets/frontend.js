document.querySelectorAll('[data-madeit-voucher-form]').forEach((form) => {
    const choices = [...form.querySelectorAll('input[name="madeit_voucher_for"]')];
    const giftFields = form.querySelector('[data-madeit-gift-fields]');
    const giftEmail = form.querySelector('input[name="madeit_voucher_email"]');
    const preview = form.querySelector('[data-madeit-voucher-preview]');
    const galleryPlaceholder = document.querySelector('.woocommerce-product-gallery--without-images .woocommerce-product-gallery__image--placeholder');
    const galleryPreview = preview && galleryPlaceholder ? preview.cloneNode(true) : null;

    if (galleryPreview && galleryPlaceholder) {
        galleryPreview.classList.add('madeit-voucher-gallery-preview');
        galleryPlaceholder.replaceChildren(galleryPreview);
        form.classList.add('has-gallery-preview');
    }

    if (!giftFields || !giftEmail) {
        return;
    }

    const update = () => {
        const isGift = choices.some((choice) => choice.value === 'gift' && choice.checked);
        form.classList.toggle('is-gift', isGift);
        form.classList.add('is-enhanced');
        giftEmail.required = isGift;
        giftFields.querySelectorAll('input, textarea').forEach((field) => {
            field.disabled = !isGift;
        });
        choices.forEach((choice) => {
            choice.closest('.madeit-voucher-choice')?.classList.toggle('is-selected', choice.checked);
        });
    };

    choices.forEach((choice) => choice.addEventListener('change', update));
    update();
});
