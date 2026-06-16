import { Modal } from 'bootstrap';

export const modal = {
    show(id) {
        const el = document.getElementById(id);
        if (el) Modal.getOrCreateInstance(el).show();
    },
    hide(id) {
        const el = document.getElementById(id);
        if (el) Modal.getOrCreateInstance(el).hide();
    },
    instance(id) {
        const el = document.getElementById(id);
        return el ? Modal.getOrCreateInstance(el) : null;
    },
};