let stack;

function ensureStack() {
    if (!stack) {
        stack = document.createElement('div');
        stack.className = 'toast-stack';
        document.body.appendChild(stack);
    }
    return stack;
}

export function toast(message, type = 'info', duration = 4000) {
    const el = document.createElement('div');
    el.className = `app-toast app-toast--${type}`;
    el.setAttribute('role', 'status');         // a11y
    el.setAttribute('aria-live', 'polite');
    el.textContent = message;
    ensureStack().appendChild(el);

    setTimeout(() => {
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 200);
    }, duration);
}

export const notify = {
    success: (m, d) => toast(m, 'success', d),
    error:   (m, d) => toast(m, 'error', d),
    info:    (m, d) => toast(m, 'info', d),
};