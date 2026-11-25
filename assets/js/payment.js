(() => {
    'use strict';

    document.querySelectorAll('[data-payment-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        const deadline = Number(panel.dataset.deadline);
        const countdown = panel.querySelector('[data-countdown-value]');
        const uploadForm = panel.querySelector('[data-payment-upload]');
        const expiredNotice = panel.querySelector('[data-payment-expired]');
        const csrf = panel.querySelector('input[name="csrf_token"]');
        const paymentId = panel.querySelector('input[name="payment_id"]');
        const statusUrl = panel.dataset.statusUrl;
        let checkingStatus = false;

        if (!Number.isFinite(deadline) || deadline <= 0 || !(countdown instanceof HTMLElement)) {
            return;
        }

        async function expirePayment() {
            if (
                checkingStatus
                || !(csrf instanceof HTMLInputElement)
                || !(paymentId instanceof HTMLInputElement)
                || !statusUrl
            ) {
                return;
            }
            checkingStatus = true;
            try {
                const body = new URLSearchParams({
                    csrf_token: csrf.value,
                    payment_id: paymentId.value
                });
                const response = await fetch(statusUrl, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin'
                });
                const payload = await response.json();
                if (!response.ok || payload.success !== true) {
                    throw new Error(payload.message || 'Unable to refresh payment status.');
                }
                if (payload.status === 'Waiting Verification' || payload.has_proof === true) {
                    countdown.textContent = '--:--';
                    return;
                }
                if (uploadForm instanceof HTMLFormElement) {
                    uploadForm.querySelectorAll('input, button').forEach((input) => {
                        if (input instanceof HTMLInputElement || input instanceof HTMLButtonElement) {
                            input.disabled = true;
                        }
                    });
                }
                if (expiredNotice instanceof HTMLElement) {
                    expiredNotice.classList.remove('d-none');
                }
            } catch (error) {
                countdown.textContent = error instanceof Error ? error.message : '--:--';
            }
        }

        function updateCountdown() {
            const remainingSeconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;
            countdown.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            if (remainingSeconds === 0) {
                if (uploadForm instanceof HTMLFormElement) {
                    uploadForm.querySelectorAll('input, button').forEach((input) => {
                        if (input instanceof HTMLInputElement || input instanceof HTMLButtonElement) {
                            input.disabled = true;
                        }
                    });
                }
                if (expiredNotice instanceof HTMLElement) {
                    expiredNotice.classList.remove('d-none');
                }
                void expirePayment();
                return;
            }
            window.setTimeout(updateCountdown, 1000);
        }

        updateCountdown();
    });

    document.querySelectorAll('[data-payment-upload]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', () => {
            const submit = form.querySelector('[data-upload-submit]');
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = true;
                submit.setAttribute('aria-busy', 'true');
                submit.textContent = form.dataset.loadingText || '...';
            }
        });
    });
})();
