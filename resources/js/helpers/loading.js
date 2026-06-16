let overlay;

function ensureOverlay() {
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'app-loading';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.innerHTML = '<div class="app-loading__spinner" role="status" aria-label="Loading"></div>';
        document.body.appendChild(overlay);
    }
    return overlay;
}

export const loading = {
    show() { ensureOverlay().classList.add('is-active'); },
    hide() { ensureOverlay().classList.remove('is-active'); },
    async wrap(promise) {
        this.show();
        try { return await promise; }
        finally { this.hide(); }
    },
};