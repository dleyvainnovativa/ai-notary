const KEY = 'app-theme';

function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
}

export const theme = {
    init() {
        const saved = localStorage.getItem(KEY);
        const preferred = saved ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        apply(preferred);
    },
    toggle() {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        apply(next);
        localStorage.setItem(KEY, next);
        return next;
    },
    set(t) { apply(t); localStorage.setItem(KEY, t); },
};