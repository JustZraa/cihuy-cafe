(() => {
    'use strict';

    const form = document.querySelector('[data-checkout-form]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const orderTypeInputs = form.querySelectorAll('input[name="order_type"]');
    const paymentInputs = form.querySelectorAll('input[name="payment_method"]');
    const cashChoice = form.querySelector('[data-payment-choice="cash"]');
    const targetSection = form.querySelector('[data-dine-in-target]');
    const paymentHint = form.querySelector('[data-payment-hint]');
    const submitButton = form.querySelector('[data-checkout-submit]');

    function updateCheckoutOptions() {
        const orderType = form.querySelector('input[name="order_type"]:checked')?.value || 'takeaway';
        const isTakeaway = orderType === 'takeaway';

        if (targetSection instanceof HTMLElement) {
            targetSection.classList.toggle('d-none', isTakeaway);
            targetSection.setAttribute('aria-hidden', isTakeaway ? 'true' : 'false');
        }
        if (cashChoice instanceof HTMLElement) {
            cashChoice.classList.toggle('d-none', isTakeaway);
            const cashInput = cashChoice.querySelector('input');
            if (cashInput instanceof HTMLInputElement) {
                cashInput.disabled = isTakeaway;
                if (isTakeaway && cashInput.checked) {
                    const qrisInput = form.querySelector('input[name="payment_method"][value="qris"]');
                    if (qrisInput instanceof HTMLInputElement) {
                        qrisInput.checked = true;
                    }
                }
            }
        }
        if (paymentHint instanceof HTMLElement) {
            paymentHint.textContent = isTakeaway
                ? (form.dataset.takeawayHint || '')
                : (form.dataset.dineInHint || '');
        }
        paymentInputs.forEach((input) => {
            if (input instanceof HTMLInputElement && input.value === 'qris') {
                input.checked = isTakeaway || input.checked;
            }
        });
    }

    orderTypeInputs.forEach((input) => input.addEventListener('change', updateCheckoutOptions));
    updateCheckoutOptions();

    form.addEventListener('submit', () => {
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.textContent = form.dataset.loadingText || '...';
        }
    });
})();
