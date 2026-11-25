(() => {
    'use strict';

    const feedback = document.getElementById('cart-feedback');
    const requestError = feedback?.dataset.errorMessage || 'Unable to update the cart.';

    function showFeedback(message, isError = false) {
        if (!feedback) {
            return;
        }

        feedback.textContent = message;
        feedback.className = `alert ${isError ? 'alert-danger' : 'alert-success'} cart-feedback container mt-3`;
    }

    function formatRupiah(amount) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(Number(amount));
    }

    function updateSummary(payload) {
        document.querySelectorAll('[data-cart-count]').forEach((element) => {
            element.textContent = payload.cart_count > 99 ? '99+' : String(payload.cart_count);
        });
        document.querySelectorAll('[data-cart-link]').forEach((element) => {
            element.setAttribute('aria-label', `${payload.cart_label || 'Cart'}: ${payload.cart_count}`);
        });
        document.querySelectorAll('[data-cart-subtotal]').forEach((element) => {
            element.textContent = formatRupiah(payload.subtotal);
        });
        document.querySelectorAll('[data-cart-tax]').forEach((element) => {
            element.textContent = formatRupiah(payload.tax_amount);
        });
        document.querySelectorAll('[data-cart-total]').forEach((element) => {
            element.textContent = formatRupiah(payload.total_amount);
        });
    }

    async function submitCartForm(form) {
        const submitButton = form.querySelector('button[type="submit"]');
        const previousText = submitButton?.textContent;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.setAttribute('aria-busy', 'true');
            submitButton.textContent = form.dataset.loadingText || '...';
        }

        try {
            const endpoint = form.getAttribute('action');
            if (!endpoint) {
                throw new Error(requestError);
            }
            const response = await fetch(endpoint, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });
            const payload = await response.json();
            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || requestError);
            }

            updateSummary(payload);
            showFeedback(payload.message || requestError);
            if (form.matches('[data-cart-update-form]') || form.matches('[data-cart-remove-form]')) {
                window.location.reload();
            }
        } catch (error) {
            showFeedback(error instanceof Error ? error.message : requestError, true);
        } finally {
            if (submitButton && submitButton.isConnected) {
                submitButton.disabled = false;
                submitButton.removeAttribute('aria-busy');
                if (typeof previousText === 'string') {
                    submitButton.textContent = previousText;
                }
            }
        }
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-cart-form], [data-cart-update-form], [data-cart-notes-form]')) {
            return;
        }
        event.preventDefault();
        submitCartForm(form);
    });

    document.addEventListener('click', (event) => {
        const stepButton = event.target.closest('[data-quantity-step]');
        if (stepButton instanceof HTMLButtonElement) {
            const form = stepButton.closest('form');
            const input = form?.querySelector('input[name="quantity"]');
            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            const step = Number(stepButton.dataset.quantityStep);
            const maximum = Number(input.max) || Number.MAX_SAFE_INTEGER;
            input.value = String(Math.max(1, Math.min(maximum, Number(input.value || 1) + step)));
            form.requestSubmit();
            return;
        }

        const removeButton = event.target.closest('[data-cart-remove]');
        if (!(removeButton instanceof HTMLButtonElement)) {
            return;
        }

        const form = removeButton.closest('form');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const action = form.querySelector('input[name="action"]');
        if (action instanceof HTMLInputElement) {
            action.value = 'remove';
        }
        form.dataset.cartRemoveForm = 'true';
        submitCartForm(form);
    });
})();
