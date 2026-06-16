export function serializeForm(formEl) {
    const fd = new FormData(formEl);
    const out = {};
    for (const [key, value] of fd.entries()) {
        if (key in out) {
            if (!Array.isArray(out[key])) out[key] = [out[key]];
            out[key].push(value);
        } else {
            out[key] = value;
        }
    }
    // Unchecked checkboxes don't appear in FormData — capture them as false
    formEl.querySelectorAll('input[type="checkbox"]').forEach(cb => {
        if (!(cb.name in out)) out[cb.name] = false;
    });
    return out;
}

export function fillForm(formEl, data) {
    Object.entries(data).forEach(([key, value]) => {
        const field = formEl.elements[key];
        if (!field) return;
        if (field.type === 'checkbox') field.checked = Boolean(value);
        else field.value = value ?? '';
    });
}