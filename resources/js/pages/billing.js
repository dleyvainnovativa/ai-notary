import { http } from '../helpers/http.js';
import { notify } from '../helpers/toast.js';
import { loading } from '../helpers/loading.js';

export function initBilling() {
    document.querySelectorAll('[data-buy]').forEach(btn => {
        btn.addEventListener('click', async () => {
            try {
                await loading.wrap((async () => {
                    const { url } = await http.post('/billing/checkout', { package: btn.dataset.buy });
                    window.location.href = url; // redirect to Stripe Checkout
                })());
            } catch (err) {
                notify.error('Could not start checkout. Please try again.');
            }
        });
    });
}