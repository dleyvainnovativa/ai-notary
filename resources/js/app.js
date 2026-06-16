// Bootstrap (JS) + Font Awesome + theme styles

import 'bootstrap';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '../css/theme.css';
import Choices from 'choices.js';

// Reusable helpers
import { http }    from './helpers/http.js';
import { notify }  from './helpers/toast.js';
import { loading } from './helpers/loading.js';
import { modal }   from './helpers/modal.js';
import { serializeForm, fillForm } from './helpers/form.js';
import { theme }   from './helpers/theme.js';
import { firebaseAuth } from './firebase.js';
import { initLogin } from './pages/login.js';
import { initBilling } from './pages/billing.js';
import { initUpload } from './pages/upload.js';
import { initSidebar } from './helpers/sidebar.js';

import { initReviewDebug } from './pages/review.js';
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.pathname === '/debug-review') initReviewDebug();
});


document.addEventListener('DOMContentLoaded', () => initSidebar());
theme.init();
document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('[data-buy]')) initBilling();
});
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('upload-form')) initUpload();
});
// Initialize theme before paint-sensitive work


// Expose a small namespaced API for inline Blade usage where needed
window.App = { http, notify, loading, modal, serializeForm, fillForm, theme };

// Wire any data-theme-toggle buttons automatically
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
        btn.addEventListener('click', () => {
            const t = theme.toggle();
            btn.setAttribute('aria-pressed', String(t === 'dark'));
        });
    });
});

function initChoices(scope) {
    scope.querySelectorAll('select.rv-input:not(.choices-done)').forEach(sel => {
        sel.classList.add('choices-done');
        new Choices(sel, {
            searchEnabled: true,
            itemSelectText: '',
            shouldSort: false,
            placeholder: true,
            placeholderValue: '—',
            allowHTML: false,
        });
    });
}

// expose logout for the navbar button
window.App.logout = async () => {
    await firebaseAuth.logout();           // clear Firebase client state
    const { redirect } = await App.http.post('/auth/logout');
    window.location.href = redirect;
};

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('login-form')) initLogin();
});