(() => {
    'use strict';

    const root = document.querySelector('[data-notification-root]');
    if (!(root instanceof HTMLElement)) return;

    const apiUrl = root.dataset.notificationApi;
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const badge = root.querySelector('[data-notification-count]');
    const list = root.querySelector('[data-notification-list]');
    const emptyLabel = root.dataset.notificationEmpty || '';
    const errorLabel = root.dataset.notificationError || '';
    const csrfToken = csrfMeta instanceof HTMLMetaElement ? csrfMeta.content : '';
    if (!apiUrl || !(badge instanceof HTMLElement) || !(list instanceof HTMLElement) || !csrfToken) return;

    const toast = document.querySelector('[data-notification-toast]');
    const knownIds = new Set();
    let initialized = false;
    let inFlight = false;

    function safeLocalUrl(value) {
        if (typeof value !== 'string' || value === '') return null;
        try {
            const parsed = new URL(value, window.location.origin);
            if (parsed.origin !== window.location.origin) return null;
            return parsed.href;
        } catch {
            return null;
        }
    }

    function renderNotifications(items) {
        list.replaceChildren();
        if (!Array.isArray(items) || items.length === 0) {
            const empty = document.createElement('li');
            empty.className = 'notification-empty';
            empty.textContent = emptyLabel;
            list.append(empty);
            return;
        }

        for (const item of items) {
            if (!item || !Number.isInteger(item.id)) continue;
            const row = document.createElement('li');
            row.className = item.is_read ? 'notification-entry' : 'notification-entry is-unread';
            const url = safeLocalUrl(item.link_url);
            const content = url ? document.createElement('a') : document.createElement('div');
            content.className = 'notification-entry__content';
            if (url && content instanceof HTMLAnchorElement) content.href = url;

            const title = document.createElement('strong');
            title.textContent = String(item.title || '');
            const message = document.createElement('span');
            message.textContent = String(item.message || '');
            const time = document.createElement('small');
            time.textContent = String(item.created_at || '');
            content.append(title, message, time);
            row.append(content);

            if (!item.is_read) {
                const readButton = document.createElement('button');
                readButton.type = 'button';
                readButton.className = 'notification-entry__read';
                readButton.textContent = root.dataset.notificationMarkRead || '';
                readButton.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    markRead([item.id]);
                });
                row.append(readButton);
            }
            list.append(row);
        }
    }

    function showToast(item) {
        if (!(toast instanceof HTMLElement) || !item) return;
        const title = toast.querySelector('[data-notification-toast-title]');
        const message = toast.querySelector('[data-notification-toast-message]');
        if (!(title instanceof HTMLElement) || !(message instanceof HTMLElement)) return;
        title.textContent = String(item.title || '');
        message.textContent = String(item.message || '');
        toast.hidden = false;
        toast.classList.add('is-visible');
        window.setTimeout(() => {
            toast.classList.remove('is-visible');
            window.setTimeout(() => { toast.hidden = true; }, 250);
        }, 5000);
    }

    async function poll() {
        if (inFlight) return;
        inFlight = true;
        try {
            const response = await fetch(apiUrl, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('Notification polling failed.');
            const data = await response.json();
            if (!Number.isInteger(data.unread_count) || !Array.isArray(data.notifications)) {
                throw new Error('Notification response shape is invalid.');
            }
            badge.textContent = data.unread_count > 99 ? '99+' : String(data.unread_count);
            badge.hidden = data.unread_count < 1;
            renderNotifications(data.notifications);

            const fresh = data.notifications.filter(item => Number.isInteger(item.id) && !knownIds.has(item.id));
            if (initialized) {
                for (const item of fresh) showToast(item);
            }
            for (const item of data.notifications) {
                if (Number.isInteger(item.id)) knownIds.add(item.id);
            }
            initialized = true;
        } catch {
            if (errorLabel) {
                root.dataset.pollError = errorLabel;
                console.error(errorLabel);
            }
        } finally {
            inFlight = false;
        }
    }

    async function markRead(ids) {
        try {
            const body = new URLSearchParams({ action: 'mark_read', csrf_token: csrfToken });
            for (const id of ids) body.append('notification_ids[]', String(id));
            const response = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });
            if (!response.ok) throw new Error('Unable to mark notifications as read.');
            await poll();
        } catch {
            if (errorLabel) {
                root.dataset.pollError = errorLabel;
                console.error(errorLabel);
            }
        }
    }

    poll();
    window.setInterval(poll, 15000);
})();
