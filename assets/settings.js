document.querySelectorAll('[data-madeit-settings-preview]').forEach((preview) => {
    const form = preview.closest('.madeit-settings-layout')?.querySelector('form');
    const template = form?.querySelector('[data-madeit-design-template]');
    const color = form?.querySelector('[data-madeit-design-color]');
    const brand = form?.querySelector('[data-madeit-design-brand]');
    const brandLabel = brand?.closest('label');
    const interfacePreviews = document.querySelectorAll('[data-madeit-ui-preview]');

    if (!template || !color || !brand) {
        return;
    }

    const update = () => {
        preview.dataset.template = template.value;
        preview.dataset.color = color.value;
        preview.style.setProperty('--brand-accent', brand.value);
        if (brandLabel) {
            brandLabel.hidden = color.value !== 'brand';
        }
        const accent = color.value === 'brand' ? brand.value : color.selectedOptions[0]?.dataset.accent;
        if (accent) {
            interfacePreviews.forEach((interfacePreview) => interfacePreview.style.setProperty('--madeit-accent', accent));
        }
    };

    [template, color, brand].forEach((field) => field.addEventListener('input', update));
    update();
});
