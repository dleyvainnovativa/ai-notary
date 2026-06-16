import { firebaseAuth } from '../firebase.js';
import { http } from '../helpers/http.js';
import { notify } from '../helpers/toast.js';
import { loading } from '../helpers/loading.js';

async function startSession(idToken) {
    const { redirect } = await http.post('/auth/session', { idToken });
    window.location.href = redirect;
}

export function initLogin() {
    const form = document.getElementById('login-form');
    const googleBtn = document.getElementById('google-login');

    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = form.email.value;
        const password = form.password.value;
        try {
            await loading.wrap((async () => {
                const idToken = await firebaseAuth.emailLogin(email, password);
                await startSession(idToken);
            })());
        } catch (err) {
            notify.error(friendly(err));
        }
    });

    googleBtn?.addEventListener('click', async () => {
        try {
            await loading.wrap((async () => {
                const idToken = await firebaseAuth.googleLogin();
                await startSession(idToken);
            })());
        } catch (err) {
            notify.error(friendly(err));
        }
    });
}

// Map Firebase error codes to human messages (don't leak which part failed)
function friendly(err) {
    const code = err?.code ?? '';
    if (code.includes('invalid-credential') || code.includes('wrong-password') || code.includes('user-not-found'))
        return 'Incorrect email or password.';
    if (code.includes('too-many-requests')) return 'Too many attempts. Try again shortly.';
    if (code.includes('popup-closed')) return 'Sign-in cancelled.';
    return 'Sign-in failed. Please try again.';
}