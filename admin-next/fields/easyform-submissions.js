/**
 * easyform-submissions — read-only custom field showing a form's
 * submission counts plus CSV-export and preview buttons, for the admin2
 * "Forms" list page (one row per form).
 *
 * A plain link can't reach either action directly: admin2's login never
 * adopts the shared front-end session (it deliberately restores whatever
 * session existed before authenticating, to avoid booting a front-end
 * visitor sharing the same browser), so a normal navigation to an API
 * route has no credentials to send. Fetching with the same X-API-Token
 * header admin2's own JS uses, then turning the response into a Blob, is
 * the same pattern flex-objects.js and save-redirect.js (both shipped by
 * the flex-objects plugin) use for their own custom fields. The export
 * button turns its blob into a file download; the preview button opens a
 * window synchronously (before the await) and only points it at the blob
 * once fetched, since a browser's popup blocker treats window.open()
 * called after an async gap as no longer tied to the click that started it.
 *
 * The field's value is a JSON string (not a nested object) encoding
 * {name, total, unread} for this row, produced server-side by
 * EasyformsHelper::listAllRaw() — kept as a plain string so this field
 * relies only on the ordinary string-valued list-row handling admin2 is
 * already known to support, rather than an unverified structured value.
 */

const TAG = window.__GRAV_FIELD_TAG;

class EasyformSubmissionsField extends HTMLElement {
    constructor() {
        super();
        this._value = '';
        this._field = null;
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    set value(v) {
        this._value = typeof v === 'string' ? v : '';
        if (this.isConnected) this._render();
    }
    get value() { return this._value; }

    connectedCallback() {
        this._render();
    }

    _parsed() {
        try {
            const data = JSON.parse(this._value || '{}');
            return {
                name: typeof data.name === 'string' ? data.name : '',
                total: Number.isFinite(data.total) ? data.total : 0,
                unread: Number.isFinite(data.unread) ? data.unread : 0,
            };
        } catch {
            return { name: '', total: 0, unread: 0 };
        }
    }

    _apiUrl(path) {
        return (window.__GRAV_API_SERVER_URL || '') +
               (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    _headers() {
        const h = {};
        const token = window.__GRAV_API_TOKEN;
        if (token) h['X-API-Token'] = token;
        return h;
    }

    async _download(name, button) {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = '...';
        try {
            const response = await fetch(this._apiUrl(`/easyform/export/${encodeURIComponent(name)}`), {
                headers: this._headers(),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${name}-submissions.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            button.textContent = 'Error';
            setTimeout(() => { button.textContent = originalText; }, 2000);
            return;
        }
        button.disabled = false;
        button.textContent = originalText;
    }

    async _preview(name, button) {
        const originalText = button.textContent;
        // Opened synchronously, still inside the click's user-activation
        // window — filled in below once the fetch resolves, since setting
        // .href on an already-open window isn't subject to the popup blocker.
        const win = window.open('', '_blank');
        button.disabled = true;
        button.textContent = '...';
        try {
            const response = await fetch(this._apiUrl(`/easyform/preview/${encodeURIComponent(name)}`), {
                headers: this._headers(),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            if (win) {
                win.location.href = url;
            }
        } catch (e) {
            if (win) win.close();
            button.textContent = 'Error';
            setTimeout(() => { button.textContent = originalText; }, 2000);
            return;
        }
        button.disabled = false;
        button.textContent = originalText;
    }

    _render() {
        const { name, total, unread } = this._parsed();
        const countText = unread > 0 ? `${total} (${unread} unread)` : String(total);

        this.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;font-family:inherit;">
                <span>${countText}</span>
                <button type="button" class="button small" ${name ? '' : 'disabled'} data-action="preview">
                    Vorschau
                </button>
                <button type="button" class="button small" ${total === 0 ? 'disabled' : ''} data-action="export">
                    Export CSV
                </button>
            </div>
        `;

        const previewButton = this.querySelector('[data-action="preview"]');
        if (previewButton) {
            previewButton.addEventListener('click', () => this._preview(name, previewButton));
        }

        const exportButton = this.querySelector('[data-action="export"]');
        if (exportButton && total > 0) {
            exportButton.addEventListener('click', () => this._download(name, exportButton));
        }
    }
}

customElements.define(TAG, EasyformSubmissionsField);
