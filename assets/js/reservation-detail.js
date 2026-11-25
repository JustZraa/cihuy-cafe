(() => {
    'use strict';

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.addEventListener('submit', (event) => {
            const message = form.dataset.confirm || '';
            if (message !== '' && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();
