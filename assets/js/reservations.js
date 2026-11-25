(() => {
    'use strict';

    const form = document.querySelector('[data-reservation-filter]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const startInput = form.querySelector('[data-reservation-start]');
    const durationInput = form.querySelector('[data-reservation-duration]');
    const endOutput = document.querySelector('[data-reservation-end]');
    if (!(startInput instanceof HTMLSelectElement) || !(durationInput instanceof HTMLSelectElement)) {
        return;
    }

    function updateEndTime() {
        const [hours, minutes] = startInput.value.split(':').map(Number);
        Array.from(durationInput.options).forEach((option) => {
            option.disabled = (hours * 60) + minutes + Number(option.value) > 22 * 60;
        });

        if (durationInput.selectedOptions[0]?.disabled) {
            const availableOptions = Array.from(durationInput.options).filter((option) => !option.disabled);
            const oneHourOption = availableOptions.find((option) => Number(option.value) === 60);
            const fallbackOption = oneHourOption || availableOptions[0];
            if (fallbackOption) {
                durationInput.value = fallbackOption.value;
            }
        }

        const duration = Number(durationInput.value);
        const endMinutes = (hours * 60) + minutes + duration;
        if (endOutput instanceof HTMLElement && endMinutes <= 22 * 60) {
            endOutput.textContent = `${String(Math.floor(endMinutes / 60)).padStart(2, '0')}:${String(endMinutes % 60).padStart(2, '0')}`;
        }
    }

    startInput.addEventListener('change', updateEndTime);
    durationInput.addEventListener('change', updateEndTime);
    updateEndTime();
})();
