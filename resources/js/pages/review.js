import Choices from 'choices.js';
import { http } from '../helpers/http.js';
import { notify } from '../helpers/toast.js';


let SCHEMA, DOCUMENT_ID;

/* ---------- Help modal ---------- */
let helpModalEl = null;

function ensureHelpModal() {
    if (helpModalEl) return helpModalEl;

    helpModalEl = document.createElement('div');
    helpModalEl.className = 'rv-help-modal';
    helpModalEl.hidden = true;
    helpModalEl.innerHTML = `
        <div class="rv-help-modal__backdrop" data-close></div>
        <div class="rv-help-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rv-help-title">
            <div class="rv-help-modal__head">
                <h3 class="rv-help-modal__title" id="rv-help-title"></h3>
                <button type="button" class="rv-help-modal__close" data-close aria-label="Cerrar">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="rv-help-modal__body"></div>
            <div class="rv-help-modal__foot">
                <button type="button" class="btn btn-primary btn-sm" data-close>Cerrar</button>
            </div>
        </div>
    `;
    document.body.appendChild(helpModalEl);

    // close on backdrop / close buttons
    helpModalEl.querySelectorAll('[data-close]').forEach(el =>
        el.addEventListener('click', closeHelpModal));

    // close on Escape
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !helpModalEl.hidden) closeHelpModal();
    });

    return helpModalEl;
}

function openHelpModal(title, body) {
    const m = ensureHelpModal();
    m.querySelector('.rv-help-modal__title').textContent = title;
    m.querySelector('.rv-help-modal__body').textContent = body;  // textContent = safe, no HTML injection
    m.hidden = false;
    document.body.style.overflow = 'hidden';   // prevent background scroll
}

function closeHelpModal() {
    if (!helpModalEl) return;
    helpModalEl.hidden = true;
    document.body.style.overflow = '';
}

/* ---------- Persona classifier (enajenantes / adquirientes) ---------- */
const GENERIC_RFC = {
    fisica: ['EXTF900101000'],   // extend as needed
    moral:  ['EXT990101000'],
};

function personaCase(tipo, rfc) {
    const r = String(rfc ?? '').trim().toUpperCase();
    const t = String(tipo ?? '');

    if (t === '1') { // Nacional → classify by RFC structure
        if (/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/.test(r)) return 'nacional_fisica';
        if (/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/.test(r)) return 'nacional_moral';
        return 'unknown';
    }
    if (t === '2') { // Extranjera → list membership only
        if (GENERIC_RFC.fisica.includes(r)) return 'extranjera_fisica';
        if (GENERIC_RFC.moral.includes(r)) return 'extranjera_moral';
        return 'extranjera_invalid';
    }
    return 'unknown';
}

/* ---------- Shared render logic ---------- */
function renderReview(container, payload) {
    SCHEMA = payload.schema;
    container.innerHTML = '';

    // Two-column shell: nav rail + form column
    const layout = document.createElement('div');
    layout.className = 'rv-layout';

    const nav = document.createElement('aside');
    nav.className = 'rv-nav';
    nav.id = 'rv-nav';

    const formCol = document.createElement('div');
    formCol.className = 'rv-form-col';

    // Validation summary (top of form column)
    const summary = document.createElement('div');
    summary.className = 'rv-summary';
    summary.id = 'rv-summary';
    summary.hidden = true;
    formCol.appendChild(summary);

    // Render sections
    SCHEMA.sections.forEach((section, i) => {
        const sectionId = `rv-sec-${i}`;
               const sec = document.createElement('div');
        sec.className = 'rv-section';
        sec.id = sectionId;
        sec.dataset.sectionTitle = section.title;

        const h = document.createElement('div');
        h.className = 'rv-section__header';
        h.textContent = section.title.toUpperCase();
        sec.appendChild(h);

        // section subtitle (optional)
        if (section.subtitle) {
            const sub = document.createElement('div');
            sub.className = 'rv-section__subtitle';
            sub.textContent = section.subtitle;
            sec.appendChild(sub);
        }

        const body = document.createElement('div');
        body.className = 'rv-section__body';
        for (const fieldName of section.fields) {
            const def = SCHEMA.fields[fieldName];
            if (!def) continue;
            renderNode(fieldName, def, payload.data?.[fieldName], body, fieldName);
        }
        sec.appendChild(body);
        formCol.appendChild(sec);

        // Nav item for this section
        const navItem = document.createElement('a');
        navItem.className = 'rv-nav__item';
        navItem.href = `#${sectionId}`;
        navItem.dataset.target = sectionId;
        navItem.innerHTML = `
            <span class="rv-nav__label">${section.title}</span>
            <span class="rv-nav__badge" hidden></span>
        `;
        navItem.addEventListener('click', (e) => {
            e.preventDefault();
            document.getElementById(sectionId)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        nav.appendChild(navItem);
    });

    layout.appendChild(nav);
    layout.appendChild(formCol);
    container.appendChild(layout);

    initChoices(container);
    applyConditional();
    applyServerIssues(payload.issues);
    refreshErrorBadges();      // initial badge state from server issues
    initScrollSpy();

    container.addEventListener('input', onChange);
    container.addEventListener('change', onChange);
    document.getElementById('review-save')?.addEventListener('click', save);
}

/* ---------- Real entry (from the wizard) ---------- */
export async function initReview(documentId) {
    DOCUMENT_ID = documentId;
    const container = document.getElementById('review-form');
    if (!container) return;

    container.innerHTML = '<div class="text-center py-4" style="color:var(--text-muted)">Cargando…</div>';

    let payload;
    try {
        payload = await http.get(`/documents/${documentId}/review-data`);
    } catch {
        container.innerHTML = '<p class="text-danger">No se pudieron cargar los datos extraídos.</p>';
        return;
    }
    renderReview(container, payload);
}

/* ---------- Debug entry (no AI, loads sample) ---------- */
export async function initReviewDebug() {
    DOCUMENT_ID = 'debug';
    const container = document.getElementById('review-form');
    if (!container) return;

    container.innerHTML = '<div class="text-center py-4" style="color:var(--text-muted)">Cargando (debug)…</div>';

    let payload;
    try {
        payload = await http.get('/debug/review-data?module=declaranot');
    } catch {
        container.innerHTML = '<p class="text-danger">No se pudo cargar el sample de debug.</p>';
        return;
    }
    renderReview(container, payload);
}

/* ---------- Scroll spy ---------- */
function initScrollSpy() {
    const sections = [...document.querySelectorAll('.rv-section')];
    if (!sections.length) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                document.querySelectorAll('.rv-nav__item').forEach(n => n.classList.remove('is-current'));
                const navItem = document.querySelector(`.rv-nav__item[data-target="${entry.target.id}"]`);
                navItem?.classList.add('is-current');
            }
        });
    }, {
        rootMargin: '-20% 0px -70% 0px',  // triggers when section is near the top
        threshold: 0,
    });

    sections.forEach(s => observer.observe(s));
}

/* ---------- Node dispatch ---------- */
function renderNode(name, def, value, parent, path) {
    const type = def.type || 'text';
    let el;
    if (type === 'array') el = renderArray(name, def, value || [], path);
    else if (type === 'object') el = renderObject(name, def, value || {}, path);
    else el = renderField(name, def, value, path, type === 'computed');

    // Apply full-width to any node type (field, array, or object)
    if (def.col === 'full' || def.colSpan === 12) {
        el.classList.add('rv-field--full');
    }
    parent.appendChild(el);
}

/* ---------- Field ---------- */
function renderField(name, def, value, path, readOnly) {
    const col = document.createElement('div');
    col.className = 'rv-field';
    col.dataset.path = path;
    col.dataset.field = name;

    const label = document.createElement('label');
    label.className = 'rv-field__label';
    label.innerHTML = (def.label || name) + (def.required ? ' <span class="rv-req">*</span>' : '');
    col.appendChild(label);

    // help icon button
    if (def.help) {
        const helpBtn = document.createElement('button');
        helpBtn.type = 'button';
        helpBtn.className = 'rv-help-btn';
        helpBtn.innerHTML = '<i class="fa-solid fa-circle-question"></i>';
        helpBtn.setAttribute('aria-label', 'Ayuda');
        helpBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openHelpModal(def.help.title || 'Ayuda', def.help.body || '');
        });
        label.appendChild(helpBtn);
    }

    if (def.subtitle) {
        const sub = document.createElement('div');
        sub.className = 'rv-field__subtitle';
        sub.textContent = def.subtitle;
        col.appendChild(sub);
    }


    let input;
    if (def.type === 'select' && def.options) {
        input = document.createElement('select');
        input.className = 'form-select rv-input';
        // const blank = document.createElement('option');
        // blank.value = ''; blank.textContent = '—';
        // input.appendChild(blank);
        for (const opt of def.options) {
            const o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.label;       // show human label, store value
            if (String(value) === String(opt.value)) o.selected = true;
            input.appendChild(o);
        }
    } else {
        input = document.createElement('input');
        input.className = 'form-control rv-input';
        input.type = def.type === 'number' ? 'number' : (def.type === 'date' ? 'date' : 'text');
        if(def.format === "round"){
            input.value = value != null ? Math.round(value) : '';
        }else{
            input.value = value ?? '';
        }

        if (def.type === 'number') {
            if (def.min != null) input.min = def.min;
            if (def.max != null) input.max = def.max;
            if (def.integer) { input.step = '1'; input.dataset.integer = '1'; }

            // integer-only key filtering (you already have this)
            if (def.integer) {
                input.addEventListener('keydown', (e) => {
                    if (['.', ',', 'e', 'E', '+', '-'].includes(e.key)) e.preventDefault();
                });
                input.addEventListener('input', () => {
                    input.value = input.value.replace(/[^\d]/g, '');
                });
            }
            // clamp + format on blur
            input.addEventListener('blur', () => {
                input.value = applyFormat(input.value, def);
                onChange();
            });
        }
    }
    input.dataset.path = path;
    input.dataset.field = name;
    if (readOnly) { input.readOnly = true; input.disabled = true; input.classList.add('is-computed'); }
    col.appendChild(input);

    if (def.description) {
        const desc = document.createElement('div');
        desc.className = 'rv-field__desc';
        desc.textContent = def.description;
        col.appendChild(desc);
    }

    // re-evaluate persona case when RFC loses focus
    if (name === 'rfc') {
        input.addEventListener('blur', () => onChange());
    }


    const err = document.createElement('div');
    err.className = 'rv-field__error';
    col.appendChild(err);
    return col;
}

/* ---------- Value formatting / clamping ---------- */
function applyFormat(rawValue, def) {
    if (rawValue === '' || rawValue == null) return '';

    let num = Number(rawValue);
    if (Number.isNaN(num)) return rawValue; // not a number, leave as-is

    // format rules
    switch (def.format) {
        case 'round':
            num = Math.round(num);
            break;
        // future: case '2dp': num = Math.round(num * 100) / 100; break;
        // future: case 'floor': num = Math.floor(num); break;
    }

    // integer enforcement (independent of format)
    if (def.integer) {
        num = Math.trunc(num);
    }

    // clamp to min/max
    if (def.min != null && num < def.min) num = def.min;
    if (def.max != null && num > def.max) num = def.max;

    return String(num);
}

/* ---------- Object ---------- */
function renderObject(name, def, value, path) {
    const wrap = document.createElement('div');
    wrap.className = 'rv-grid';
    wrap.dataset.path = path;
    for (const [childName, childDef] of Object.entries(def.itemSchema)) {
        renderNode(childName, childDef, value?.[childName], wrap, `${path}.${childName}`);
    }
    return wrap;
}

/* ---------- Array ---------- */
function renderArray(name, def, rows, path) {
    const wrap = document.createElement('div');
    wrap.className = 'rv-array';
    wrap.dataset.path = path;
    wrap.dataset.arrayName = name;

    const head = document.createElement('div');
    head.className = 'rv-array__head';
    head.innerHTML = `<span class="rv-array__count"></span>`;
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'btn btn-sm btn-outline-secondary';
    addBtn.innerHTML = '<i class="fa-solid fa-plus me-1"></i>Agregar';
    head.appendChild(addBtn);
    wrap.appendChild(head);

    if (def.legend) {
        const legend = document.createElement('div');
        legend.className = 'rv-array__legend';
        legend.innerHTML = `<i class="fa-solid fa-circle-info me-1"></i>${def.legend}`;
        wrap.appendChild(legend);
    }

    const rowsWrap = document.createElement('div');
    rowsWrap.className = 'rv-array__rows';
    wrap.appendChild(rowsWrap);

    const buildRow = (rowData, idx) => {
        const row = document.createElement('div');
        row.className = 'rv-row';
        const rowPath = `${path}.${idx}`;
        row.dataset.path = rowPath;

        const rh = document.createElement('div');
        rh.className = 'rv-row__head';
        rh.innerHTML = `<span class="rv-row__num">#${idx + 1}</span>`;
        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'rv-row__remove';
        rm.innerHTML = '<i class="fa-solid fa-xmark me-1"></i>Eliminar';
        rm.onclick = () => { row.remove(); reindex(wrap); updateCount(wrap); onChange(); };
        rh.appendChild(rm);
        row.appendChild(rh);

        const grid = document.createElement('div');
        grid.className = 'rv-grid';
        for (const [cn, cd] of Object.entries(def.itemSchema)) {
            renderNode(cn, cd, rowData?.[cn], grid, `${rowPath}.${cn}`);
        }
        row.appendChild(grid);
        rowsWrap.appendChild(row);
        initChoices(row);
    };

    rows.forEach((r, i) => buildRow(r, i));
    updateCount(wrap);

    addBtn.onclick = () => {
        buildRow({}, rowsWrap.children.length);
        updateCount(wrap);
        applyConditional();
    };
    return wrap;
}

function updateCount(wrap) {
    const n = wrap.querySelectorAll(':scope > .rv-array__rows > .rv-row').length;
    const el = wrap.querySelector('.rv-array__count');
    if (el) el.textContent = `${n} elemento(s)`;
}

function reindex(wrap) {
    const base = wrap.dataset.path;
    [...wrap.querySelectorAll(':scope > .rv-array__rows > .rv-row')].forEach((row, i) => {
        const rowPath = `${base}.${i}`;
        row.dataset.path = rowPath;
        row.querySelector('.rv-row__num').textContent = `#${i + 1}`;
        row.querySelectorAll('[data-field]').forEach(el => {
            el.dataset.path = `${rowPath}.${el.dataset.field}`;
        });
    });
}

/* ---------- Tom Select ---------- */
/* ---------- Choices.js ---------- */
function initChoices(scope) {
    scope.querySelectorAll('select.rv-input:not(.choices-done)').forEach(sel => {
        sel.classList.add('choices-done');
        new Choices(sel, {
            searchEnabled: true,
            itemSelectText: '',
            shouldSort: false,
            allowHTML: false,
        });
    });
}

/* ---------- Conditional ---------- */
function applyConditional() {
    // First: compute the persona case for each enajenante/adquiriente row
    document.querySelectorAll('.rv-array').forEach(arr => {
        const arrDef = defForPath(arr.dataset.path);
        if (!arrDef?.classifier) return;

        arr.querySelectorAll(':scope > .rv-array__rows > .rv-row').forEach(row => {
            const tipoInput = row.querySelector(`.rv-input[data-field="${arrDef.classifier.tipo_field}"]`);
            const rfcInput = row.querySelector(`.rv-input[data-field="${arrDef.classifier.rfc_field}"]`);
            const kase = personaCase(tipoInput?.value, rfcInput?.value);
            row.dataset.personaCase = kase;
        });
    });

    // Then: each field's visibility/required
    document.querySelectorAll('.rv-field, .rv-array').forEach(node => {
        const def = defForPath(node.dataset.path);
        if (!def) return;
        const scopeVals = siblings(node.dataset.path);

        // --- case-based rules (only inside classified rows) ---
        const row = node.closest('.rv-row');
        const kase = row?.dataset.personaCase;
        if (def.required_in_cases || def.show_in_cases) {
            const requiredHere = kase && def.required_in_cases?.includes(kase);
            const shownHere = requiredHere || (kase && def.show_in_cases?.includes(kase));
            node.style.display = shownHere ? '' : 'none';
            node.dataset.dynRequired = requiredHere ? '1' : '0';
            return; // case rules take precedence for these fields
        }

        // --- existing enabled_if / required_if / required_when ---
        const visCond = def.enabled_if || def.required_if;
        let visible = true;
        if (visCond) visible = condMet(visCond, scopeVals);
        if (visCond) node.style.display = visible ? '' : 'none';
        if (def.required_if) node.dataset.dynRequired = condMet(def.required_if, scopeVals) ? '1' : '0';
    });

    // RFC error for invalid extranjera
    flagExtranjeraInvalid();
}

function flagExtranjeraInvalid() {
    document.querySelectorAll('.rv-row').forEach(row => {
        if (row.dataset.personaCase !== 'extranjera_invalid') return;
        const rfcField = row.querySelector('.rv-field[data-field="rfc"]');
        if (!rfcField) return;
        const rfcInput = rfcField.querySelector('.rv-input');
        if (!rfcInput?.value) return; // empty → let normal required handle it
        rfcField.classList.add('has-error');
        const err = rfcField.querySelector('.rv-field__error');
        if (err) err.textContent = 'Para extranjeros, use un RFC genérico: EXTF900101000 (física) o EXT990101000 (moral).';
    });
}

/* ---------- Validation ---------- */
function onChange() {
    applyConditional();
    validateLive();
    refreshErrorBadges();
}

function validateLive() {
    let first = null;
    document.querySelectorAll('.rv-field').forEach(col => {
        const input = col.querySelector('.rv-input');
        const errEl = col.querySelector('.rv-field__error');
        if (!input || col.offsetParent === null) { clear(col, errEl); return; }
        const def = defForPath(col.dataset.path);
        const msg = validateField(def, input.value, col);
        if (msg) { col.classList.add('has-error'); errEl.textContent = msg; if (!first) first = col; }
        else clear(col, errEl);
    });
    return first;
}

function validateField(def, value, col) {
    // if (!def) return null;
    // const required = def.required || col.dataset.dynRequired === '1';
    // const empty = value === '' || value == null;
    // if (required && empty) return 'Este campo es obligatorio.';
    // if (empty) return null;
    // if (def.type === 'select' && def.options) {
    //     if (!def.options.some(o => String(o.value) === String(value))) return 'Valor no permitido.';
    // }
    
    if (!def) return null;
    const required = def.required || col.dataset.dynRequired === '1';

    // For selects, the placeholder value counts as "not selected"
    const placeholder = def.placeholderValue ?? '0';   // default to "0" (Seleccionar opción)
    const isPlaceholder = def.type === 'select' && String(value) === String(placeholder);
    const empty = value === '' || value == null || isPlaceholder;

    if (required && empty) return 'Este campo es obligatorio.';
    if (empty) return null;

    if (def.type === 'select' && def.options) {
        if (!def.options.some(o => String(o.value) === String(value))) return 'Valor no permitido.';
    }

    if (def.type === 'number' && def.integer && /[.,]/.test(String(value))) {
        return 'Solo números enteros.';
    }
    if (def.type === 'number') {
        const num = Number(value);
        if (!Number.isNaN(num)) {
            if (def.min != null && num < def.min) return `Mínimo: ${def.min}.`;
            if (def.max != null && num > def.max) return `Máximo: ${def.max}.`;
        }
    }
    const fmt = def.validation?.format;
    if (fmt === 'rfc' && !/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i.test(value)) return 'RFC inválido.';
    if (fmt === 'curp' && !/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i.test(value)) return 'CURP inválido.';
    if (def.type === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) return 'Fecha inválida (YYYY-MM-DD).';
    return null;
}

function clear(col, errEl) { col.classList.remove('has-error'); if (errEl) errEl.textContent = ''; }

function applyServerIssues(issues) {
    for (const it of issues) {
        const col = document.querySelector(`.rv-field[data-path="${cssEsc(it.path)}"]`);
        if (col) {
            col.classList.add('has-error');
            const e = col.querySelector('.rv-field__error');
            if (e) e.textContent = it.message;
        }
    }
}

/* ---------- Save ---------- */
async function save() {
    const localErr = validateLive();
    if (localErr) {
        notify.error('Corrige los campos marcados.');
        localErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const data = collect(SCHEMA.fields, '');
    console.log(data);

    // Validate server-side first; only export if valid
    let result;
    try {
        result = await http.post(`/documents/${DOCUMENT_ID}/review-validate`, { data });
    } catch {
        notify.error('No se pudo validar. Intenta de nuevo.');
        return;
    }

    if (!result.valid) {
        applyServerIssues(result.issues);
        refreshErrorBadges();
        notify.error('Algunos campos requieren atención.');
        return;
    }

    // Valid → request the TXT export and trigger a download
    await exportTxt(data);
}

async function exportTxt(data) {
    try {
        const res = await fetch(`/documents/${DOCUMENT_ID}/export`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ data }),
        });

        if (!res.ok) {
            notify.error('No se pudo generar el archivo.');
            return;
        }

        // Stream the file blob → download
        const blob = await res.blob();
        const disposition = res.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/);
        const filename = match ? match[1] : 'declaranot.txt';

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);

        notify.success('Archivo generado y descargado.');
    } catch {
        notify.error('Error al generar el archivo.');
    }
}

/* ---------- Collect ---------- */
function collect(fields, prefix) {
    const out = {};
    for (const [name, def] of Object.entries(fields)) {
        const path = prefix ? `${prefix}.${name}` : name;
        const type = def.type || 'text';

        if (type === 'array') {
            const wrap = document.querySelector(`.rv-array[data-path="${cssEsc(path)}"]`);
            // hidden array → null
            if (!wrap || wrap.offsetParent === null) { out[name] = []; continue; }
            out[name] = [...wrap.querySelectorAll(':scope > .rv-array__rows > .rv-row')]
                .map(row => collectScope(def.itemSchema, row.dataset.path));
        } else if (type === 'object') {
            out[name] = collectScope(def.itemSchema, path);
        } else {
            const col = document.querySelector(`.rv-field[data-path="${cssEsc(path)}"]`);
            const input = document.querySelector(`.rv-input[data-path="${cssEsc(path)}"]`);
            // hidden field → null
            if (!col || col.offsetParent === null || !input) { out[name] = null; continue; }
            out[name] = norm(input, type, def);
        }
    }
    return out;
}

function collectScope(fields, scope) {
    const out = {};
    for (const [name, def] of Object.entries(fields)) {
        const path = `${scope}.${name}`;
        const type = def.type || 'text';

        if (type === 'array' || type === 'object') {
            Object.assign(out, { [name]: collect({ [name]: def }, scope)[name] });
        } else {
            const col = document.querySelector(`.rv-field[data-path="${cssEsc(path)}"]`);
            const input = document.querySelector(`.rv-input[data-path="${cssEsc(path)}"]`);
            if (!col || col.offsetParent === null || !input) { out[name] = null; continue; }
            out[name] = norm(input, type, def);
        }
    }
    return out;
}
function norm(input, type, def) {
    const v = input.value;
    if (v === '') return null;
    if (type === 'number') {
        const formatted = applyFormat(v, def || {});
        return def?.integer ? parseInt(formatted, 10) : Number(formatted);
    }
    return v;
}

/* ---------- Path helpers ---------- */
function defForPath(path) {
    const parts = path.split('.');
    let fields = SCHEMA.fields, def = null;
    for (const p of parts) {
        if (/^\d+$/.test(p)) continue;
        def = fields?.[p];
        if (!def) return null;
        if (def.type === 'array' || def.type === 'object') fields = def.itemSchema;
    }
    return def;
}
function siblings(path) {
    const parts = path.split('.'); parts.pop();
    const scope = parts.join('.');
    const out = {};
    document.querySelectorAll('.rv-input').forEach(inp => {
        const pp = inp.dataset.path.split('.'); pp.pop();
        if (pp.join('.') === scope) out[inp.dataset.field] = inp.value;
    });
    return out;
}
function condMet(cond, vals) {
    for (const [field, expected] of Object.entries(cond)) {
        const actual = vals[field];

        // Comparison form: { op: '>', value: 0 }
        if (expected && typeof expected === 'object' && !Array.isArray(expected) && 'op' in expected) {
            if (!compare(actual, expected.op, expected.value)) return false;
            continue;
        }

        // Equality form (value or list) — unchanged
        const allowed = (Array.isArray(expected) ? expected : [expected]).map(String);
        if (!allowed.includes(String(actual))) return false;
    }
    return true;
}

function compare(actual, op, target) {
    const a = Number(actual);
    const t = Number(target);
    if (Number.isNaN(a)) return false;   // non-numeric never satisfies a numeric comparison
    switch (op) {
        case '>':  return a > t;
        case '>=': return a >= t;
        case '<':  return a < t;
        case '<=': return a <= t;
        case '==': return a === t;
        case '!=': return a !== t;
        default:   return false;
    }
}

/* ---------- Error badges per section + summary ---------- */
function refreshErrorBadges() {
    const sections = document.querySelectorAll('.rv-section');
    let totalErrors = 0;

    sections.forEach(sec => {
        // count visible error fields within this section
        const errors = [...sec.querySelectorAll('.rv-field.has-error')]
            .filter(f => f.offsetParent !== null).length;
        totalErrors += errors;

        const navItem = document.querySelector(`.rv-nav__item[data-target="${sec.id}"]`);
        if (!navItem) return;
        const badge = navItem.querySelector('.rv-nav__badge');
        if (errors > 0) {
            badge.textContent = errors;
            badge.hidden = false;
            navItem.classList.add('has-errors');
        } else {
            badge.hidden = true;
            navItem.classList.remove('has-errors');
        }
    });

    updateSummary(totalErrors);
}

function updateSummary(totalErrors) {
    const summary = document.getElementById('rv-summary');
    if (!summary) return;
    if (totalErrors === 0) {
        summary.hidden = true;
        return;
    }
    summary.hidden = false;
    summary.className = 'rv-summary rv-summary--error';
    summary.innerHTML = `
        <i class="fa-solid fa-circle-exclamation me-2"></i>
        <span>${totalErrors} campo(s) requieren atención. Revisa las secciones marcadas.</span>
    `;
    summary.onclick = () => {
        const firstError = document.querySelector('.rv-field.has-error');
        firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    summary.style.cursor = 'pointer';
}

/* ---------- Named conditional rules ---------- */
const NAMED_RULES = {
    rfc_invalid_or_generic_moral(value) {
        const rfc = String(value ?? '').trim().toUpperCase();
        if (rfc === '') return true;                       // no RFC → required
        if (rfc === 'XEXX010101000') return true;          // generic foreign moral → required
        const validRfc = /^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/.test(rfc);
        return !validRfc;                                  // invalid format → required
    },
};

function evalRequiredWhen(def, scopeValues) {
    if (!def.required_when) return false;
    const { field, rule } = def.required_when;
    const fn = NAMED_RULES[rule];
    if (!fn) return false;
    return fn(scopeValues[field]);
}


function cssEsc(s) { return s.replace(/(["\\])/g, '\\$1'); }