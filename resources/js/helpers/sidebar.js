export function initSidebar() {
    const sidebar = document.getElementById('app-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const toggle = document.getElementById('sidebar-toggle');
    if (!sidebar) return;

    const open = () => { sidebar.classList.add('is-open'); backdrop.classList.add('is-open'); };
    const close = () => { sidebar.classList.remove('is-open'); backdrop.classList.remove('is-open'); };

    toggle?.addEventListener('click', open);
    backdrop?.addEventListener('click', close);
}