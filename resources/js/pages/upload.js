import { http } from '../helpers/http.js';
import { notify } from '../helpers/toast.js';
import { initReview } from './review.js';

export function initUpload() {
    const form = document.getElementById('upload-form');
    if (!form) return;

    const moduleSelect = document.getElementById('module-select');
    const submitBtn = document.getElementById('upload-submit');

    // --- module input visibility ---
    function showModuleInputs() {
        const selected = moduleSelect.value;
        document.querySelectorAll('.module-inputs').forEach(g => {
            g.style.display = g.dataset.module === selected ? 'block' : 'none';
        });
    }
    moduleSelect.addEventListener('change', showModuleInputs);
    showModuleInputs();

    // --- dropzones ---
    document.querySelectorAll('[data-dropzone]').forEach(initDropzone);

    // --- submit ---
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const selected = moduleSelect.value;
        const activeFiles = document.querySelectorAll(`.module-file[data-module="${selected}"]`);

        const fd = new FormData();
        fd.append('module', selected);
        let missing = null;

        activeFiles.forEach(input => {
            const dz = input.closest('[data-dropzone]');
            if (input.files[0]) {
                fd.append(input.dataset.inputKey, input.files[0]);
            } else if (dz.dataset.required === '1' && !missing) {
                missing = dz.querySelector('.dropzone-field__label')?.textContent?.trim()
                    ?? input.dataset.inputKey;
            }
        });

        if (missing) {
            notify.error(`Please upload the required file: ${missing.replace(/Required|Optional/, '').trim()}`);
            return;
        }

        submitBtn.disabled = true;
        goToStep(2);
        setProcessingState('working');

        try {
            const res = await http.post('/upload', fd);
            pollStatus(res.document_id);
        } catch (err) {
            setProcessingState('failed', err?.data?.message ?? 'Upload failed.');
        }
    });

    // --- try again ---
    document.getElementById('try-again')?.addEventListener('click', () => {
        resetWizard();
    });

    function resetWizard() {
        form.reset();
        submitBtn.disabled = false;
        showModuleInputs();
        document.querySelectorAll('[data-dropzone]').forEach(clearDropzone);
        goToStep(1);
    }
}

/* ---------- Dropzone behavior ---------- */
function initDropzone(dz) {
    const input = dz.querySelector('.dropzone__input');
    const empty = dz.querySelector('.dropzone__empty');
    const filed = dz.querySelector('.dropzone__file');
    const nameEl = dz.querySelector('.dropzone__file-name');
    const removeBtn = dz.querySelector('.dropzone__remove');

    function showFile(file) {
        nameEl.textContent = file.name;
        empty.hidden = true;
        filed.hidden = false;
        dz.classList.add('has-file');
    }
    function clear() {
        input.value = '';
        empty.hidden = false;
        filed.hidden = true;
        dz.classList.remove('has-file');
    }

    input.addEventListener('change', () => {
        if (input.files[0]) showFile(input.files[0]);
    });

    removeBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        clear();
    });

    ['dragenter', 'dragover'].forEach(ev =>
        dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('is-dragover'); }));
    ['dragleave', 'drop'].forEach(ev =>
        dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove('is-dragover'); }));

    dz.addEventListener('drop', (e) => {
        const file = e.dataTransfer.files[0];
        if (!file) return;
        // assign dropped file to the hidden input
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        showFile(file);
    });

    dz._clear = clear; // expose for resetWizard
}
function clearDropzone(dz) { dz._clear?.(); }

/* ---------- Step navigation ---------- */
function goToStep(n) {
    document.querySelectorAll('.wizard-pane').forEach(p => {
        p.classList.toggle('is-active', p.dataset.pane === String(n));
    });
    document.querySelectorAll('[data-step-indicator]').forEach(s => {
        const step = Number(s.dataset.stepIndicator);
        s.classList.toggle('is-active', step === n);
        s.classList.toggle('is-done', step < n);
    });
}

function setProcessingState(state, message) {
    document.querySelectorAll('.processing-state').forEach(el => {
        el.hidden = el.dataset.state !== state;
    });
    if (state === 'failed' && message) {
        document.getElementById('failed-reason').textContent = message;
    }
}

/* ---------- Status polling ---------- */
async function pollStatus(documentId, attempt = 0, errorStreak = 0) {
    if (attempt > 90) { setProcessingState('failed', 'Still processing — check back shortly.'); return; }
    if (errorStreak >= 3) { setProcessingState('failed', 'Lost connection. Please refresh.'); return; }

    try {
        const { status, error } = await http.get(`/documents/${documentId}/status`);

        if (status === 'requires_review' || status === 'completed') {
            setProcessingState('done');
            const reviewBtn = document.getElementById('goto-review');
            reviewBtn?.addEventListener('click', () => {
    goToStep(3);
    initReview(documentId);
}, { once: true });
            notify.success('Document processed.');
            return;
        }
        if (status === 'failed') {
            setProcessingState('failed', error ?? 'Processing failed.');
            return;
        }
        // still working — update the subtext by stage
        const labels = { uploaded: 'Queued…', extracting: 'Extracting text…', processing: 'Analyzing with AI…' };
        const t = document.getElementById('processing-text');
        if (t && labels[status]) t.textContent = labels[status];

        setTimeout(() => pollStatus(documentId, attempt + 1, 0), 2000);
    } catch {
        setTimeout(() => pollStatus(documentId, attempt + 1, errorStreak + 1), 2000);
    }
}