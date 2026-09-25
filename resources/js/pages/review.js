import Choices from 'choices.js';
import { http } from '../helpers/http.js';
import { notify } from '../helpers/toast.js';


let SCHEMA, DOCUMENT_ID;
let INITIAL_LOOKUPS = [];   // CP lookups fired while rendering (settle before autosave arms)

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

/* ---------- Persona classifier dispatcher ---------- */
// classifier = { rule, tipo_field, rfc_field? }
function classifyRow(classifier, rowValues) {
    const rule = classifier?.rule || 'persona_case';
    if (rule === 'persona_tipo_select') {
        return personaTipoSelect(rowValues[classifier.tipo_field]);
    }
    // default: declaranot RFC-derived
    return personaCase(rowValues[classifier.tipo_field], rowValues[classifier.rfc_field]);
}

// declaranot — UNCHANGED (keep your existing body)
function personaCase(tipo, rfc) {
    const r = String(rfc ?? '').trim().toUpperCase();
    const t = String(tipo ?? '');
    if (t === '1') {
        if (/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/.test(r)) return 'nacional_fisica';
        if (/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/.test(r)) return 'nacional_moral';
        return 'unknown';
    }
    if (t === '2') {
        if (GENERIC_RFC.fisica.includes(r)) return 'extranjera_fisica';
        if (GENERIC_RFC.moral.includes(r)) return 'extranjera_moral';
        return 'extranjera_invalid';
    }
    return 'unknown';
}

// UIF — user selects tipo_persona (1=física, 2=moral, 3=fideicomiso)
function personaTipoSelect(tipo) {
    switch (String(tipo ?? '')) {
        case '1': return 'fisica';
        case '2': return 'moral';
        case '3': return 'fideicomiso';
        default:  return 'unknown';
    }
}

/* ---------- Shared render logic ---------- */
function renderReview(container, payload) {
    SCHEMA = payload.schema;
    INITIAL_LOOKUPS = [];
    resetAutosave(payload.draft);
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

         // --- nav item for the section ---
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

    // --- sub-nav children (e.g. per operation: Adquirentes, Vendedores...) ---
    if (section.subnav) {
        const opData = payload.data?.[section.subnav.array] ?? [];
        const opCount = Math.max(opData.length, 1); // at least one shown
        const multi = opCount > 1;

        for (let opIndex = 0; opIndex < opCount; opIndex++) {
            for (const child of section.subnav.children) {
                // target path in the rendered DOM
                const targetPath = `${section.subnav.array}.${opIndex}.${child.field}`;
                const childItem = document.createElement('a');
                childItem.className = 'rv-nav__item rv-nav__item--child';
                childItem.dataset.targetPath = targetPath;
                childItem.dataset.parentTarget = sectionId;
                const label = multi ? `Op ${opIndex + 1} · ${child.label}` : child.label;
                childItem.innerHTML = `
                    <span class="rv-nav__label">${label}</span>
                    <span class="rv-nav__badge" hidden></span>
                `;
                childItem.addEventListener('click', (e) => {
                    e.preventDefault();
                    const el = document.querySelector(`[data-path="${cssEsc(targetPath)}"]`);
                    el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                nav.appendChild(childItem);
            }
        }
    }
    });

    layout.appendChild(nav);
    layout.appendChild(formCol);
    container.appendChild(layout);

    initChoices(container);
    applyConditional();
    applyServerIssues(payload.issues);
    applyNotes(payload.notes || []);   // "tomado de…" notes from the ReferenceResolver
    refreshErrorBadges();      // initial badge state from server issues
    initScrollSpy();

    container.addEventListener('input', onChange);
    container.addEventListener('change', onChange);
    container.addEventListener('change', onDeriveSource);   // CURP/RFC → fecha_nacimiento / fecha_constitucion
    container.addEventListener('input', scheduleAutosave);
    container.addEventListener('change', scheduleAutosave);
    armAutosaveWhenSettled();

    const saveBtn = document.getElementById('review-save');
    if (saveBtn && !saveBtn.dataset.bound) {
        saveBtn.dataset.bound = '1';
        saveBtn.addEventListener('click', save);
    }
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
    } catch (err) {
        const p = document.createElement('p');
        p.className = 'text-danger';
        p.textContent = err?.data?.message || 'No se pudieron cargar los datos extraídos.';
        container.replaceChildren(p);
        return;
    }
    renderReview(container, payload);
}

/* ---------- Debug entry (no AI, loads sample) ---------- */
export async function initReviewDebug() {
    DOCUMENT_ID = 'debug';
    const container = document.getElementById('review-form');
    if (!container) return;

    // read ?module= from the page URL, default to declaranot
    const params = new URLSearchParams(window.location.search);
    const module = params.get('module') || 'declaranot';

    container.innerHTML = '<div class="text-center py-4" style="color:var(--text-muted)">Cargando (debug)…</div>';

    let payload;
    try {
        payload = await http.get(`/debug/review-data?module=${encodeURIComponent(module)}`);
    } catch {
        container.innerHTML = '<p class="text-danger">No se pudo cargar el sample de debug.</p>';
        return;
    }
    renderReview(container, payload);
}

function initScrollSpy() {
    const targets = [
        ...document.querySelectorAll('.rv-section'),
        ...[...document.querySelectorAll('.rv-nav__item--child')]
            .map(n => document.querySelector(`[data-path="${cssEsc(n.dataset.targetPath)}"]`))
            .filter(Boolean),
    ];
    if (!targets.length) return;

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            document.querySelectorAll('.rv-nav__item').forEach(n => n.classList.remove('is-current'));

            // match either a section nav item or a child nav item
            const id = entry.target.id;
            const path = entry.target.dataset.path;
            let navItem = null;
            if (id) navItem = document.querySelector(`.rv-nav__item[data-target="${id}"]`);
            if (!navItem && path) navItem = document.querySelector(`.rv-nav__item--child[data-target-path="${cssEsc(path)}"]`);
            if (navItem) {
                navItem.classList.add('is-current');
                if (window.matchMedia('(max-width: 860px)').matches) {
                    navItem.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                }
            }
        });
    }, { rootMargin: '-15% 0px -75% 0px', threshold: 0 });

    targets.forEach(t => observer.observe(t));
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
    if (def.cp_target) {
        // Colonia starts as TEXT holding the deed's value; it becomes a select
        // once the CP lookup returns colonias (see cpLookup / swapColonia).
        input = makeColoniaText(value);
        col.dataset.coloniaMode = 'text';
        col.dataset.subtitleDefault = def.subtitle || '';
    } else if (def.type === 'select' && def.options) {
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

    // CP → colonia lookup (input now exists)
    if (def.cp_lookup) {
        input.addEventListener('blur', () => cpLookup(input, def.cp_lookup));
        if ((input.value ?? '').length === 5) {
            INITIAL_LOOKUPS.push(new Promise(resolve =>
                setTimeout(() => cpLookup(input, def.cp_lookup, true).finally(resolve), 0)));
        }
    }
    // CP → entidad federativa (derive state from first two digits of the CP)
    if (def.cp_entidad) {
        input.addEventListener('blur', () => cpEntidad(input, def.cp_entidad));
        if ((input.value ?? '').length === 5) {
            // Defer past initChoices() so the entidad Choices instance exists.
            setTimeout(() => cpEntidad(input, def.cp_entidad), 0);
        }
    }
    if (def.cp_target) {
        input.dataset.pendingValue = value ?? '';
        // No CP → no lookup will run: tell the user why it's a text field.
        setColoniaHint(col, COLONIA_HINT_TEXT);
    }

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
    wrap.className = 'rv-subsection';
    wrap.dataset.path = path;

    // label for the object (Domicilio, Inmueble, etc.)
    if (def.label) {
        const title = document.createElement('div');
        title.className = 'rv-subsection__title';
        title.textContent = def.label;
        wrap.appendChild(title);
    }

    const grid = document.createElement('div');
    grid.className = 'rv-grid';
    for (const [childName, childDef] of Object.entries(def.itemSchema)) {
        renderNode(childName, childDef, value?.[childName], grid, `${path}.${childName}`);
    }
    wrap.appendChild(grid);
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
    // add the array title
    head.innerHTML = `
        <div class="rv-array__titles">
            <span class="rv-array__title">${def.label || name}</span>
            ${def.subtitle ? `<span class="rv-array__subtitle">${def.subtitle}</span>` : ''}
            <span class="rv-array__count"></span>
        </div>
    `;
    // ... the Agregar button appends after
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

/* ---------- CP → colonia lookup ----------
 * The colonia field has two modes:
 *   text   — no CP (or the CP has no colonias in the catalog): keeps the deed's value.
 *   select — CP found: colonias from the catalog; the deed's value is preselected
 *            by fuzzy match, or kept as an extra option if it isn't in the list.
 */
const COLONIA_HINT_TEXT = 'Sin C.P.: se muestra la colonia de la escritura. Captura el C.P. para elegirla del catálogo.';
const COLONIA_HINT_EMPTY = 'Este C.P. no tiene colonias en el catálogo: captura la colonia manualmente.';

async function cpLookup(cpInput, targetField, preserveValue = false) {
    const cp = (cpInput.value ?? '').replace(/\D/g, '');

    // Find the colonia field in the SAME scope (same domicilio object).
    // The CP path is e.g. operaciones.0.adquirentes.1.domicilio.codigo_postal
    // The colonia is  ...domicilio.colonia — same parent path, different field.
    const cpPath = cpInput.dataset.path;
    const scope = cpPath.substring(0, cpPath.lastIndexOf('.'));  // ...domicilio
    const target = document.querySelector(`.rv-input[data-path="${cssEsc(scope + '.' + targetField)}"]`);
    if (!target) return;

    const isText = target.tagName === 'INPUT';
    const currentValue = isText
        ? target.value
        : (preserveValue ? (target.dataset.pendingValue || target.value) : target.value);

    // CP cleared → back to text, keeping whatever colonia was chosen.
    if (cp.length === 0) {
        if (!isText) swapColonia(target, 'text', { value: currentValue, hint: COLONIA_HINT_TEXT });
        return;
    }
    if (cp.length !== 5) return;

    let colonias = [];
    try {
        const res = await http.get(`/api/postal-codes/${cp}`);
        colonias = res.colonias ?? [];
    } catch {
        return;   // network error: leave the field exactly as it is
    }

    if (!colonias.length) {
        swapColonia(target, 'text', { value: currentValue, hint: COLONIA_HINT_EMPTY });
        return;
    }

    // Deed/typed text → try to match it; if no match keep it visible as an extra option.
    const matched = matchColonia(currentValue, colonias);
    const extra = isText && currentValue && !matched ? currentValue : null;
    swapColonia(target, 'select', { colonias, selected: matched, extra });
    if (!preserveValue) scheduleAutosave();   // user typed a CP → persist the new colonia state
}

/** Normalize colonia names: no accents/case/punctuation, no type prefix (FRACC., COL., U.H.…). */
function normColonia(s) {
    let n = String(s ?? '')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .toUpperCase().replace(/[^A-Z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
    const PREFIX = /^(COLONIA|COL|FRACCIONAMIENTO|FRACC|FRAC|UNIDAD HABITACIONAL|U H|UH|BARRIO|BO|CONJUNTO HABITACIONAL|CONJ HAB|RESIDENCIAL|RES|EJIDO|PUEBLO|CONGREGACION|INFONAVIT) /;
    let prev;
    do { prev = n; n = n.replace(PREFIX, ''); } while (n !== prev);
    return n;
}

/**
 * Catalog value matching the deed's colonia: exact (normalized), else a unique
 * catalog name that CONTAINS the deed's name ("HIPICO" → "Hípico Residencial").
 * Never the reverse: deed "LAGUNA ENCANTADA" must not match catalog "Laguna".
 */
function matchColonia(value, colonias) {
    const v = normColonia(value);
    if (!v) return null;
    const exact = colonias.find(c => normColonia(c.label) === v);
    if (exact) return exact.value;
    if (v.length < 4) return null;
    const partial = colonias.filter(c => {
        const n = normColonia(c.label);
        return n.includes(v);
    });
    return partial.length === 1 ? partial[0].value : null;
}

function makeColoniaText(value) {
    const input = document.createElement('input');
    input.className = 'form-control rv-input';
    input.type = 'text';
    input.value = value ?? '';
    return input;
}

function setColoniaHint(col, text) {
    let sub = col.querySelector(':scope > .rv-field__subtitle');
    if (!sub) {
        sub = document.createElement('div');
        sub.className = 'rv-field__subtitle';
        col.querySelector(':scope > .rv-field__label')?.after(sub);
    }
    sub.textContent = text;
}

/** Replace the colonia element (text ⇄ select) keeping path/field/pending data. */
function swapColonia(current, mode, { value = '', hint = '', colonias = [], selected = null, extra = null } = {}) {
    const col = current.closest('.rv-field');
    if (current._choices) {
        try { current._choices.destroy(); } catch (e) {}
        current._choices = null;
    }

    let el;
    if (mode === 'text') {
        el = makeColoniaText(value);
    } else {
        el = document.createElement('select');
        el.className = 'form-select rv-input';
    }
    el.dataset.path = current.dataset.path;
    el.dataset.field = current.dataset.field;
    el.dataset.pendingValue = current.dataset.pendingValue ?? '';
    current.replaceWith(el);

    if (col) {
        col.dataset.coloniaMode = mode;
        setColoniaHint(col, mode === 'text' ? hint : (col.dataset.subtitleDefault || 'Selecciona la Colonia cargada del CP.'));
    }
    if (mode === 'select') populateColoniaSelect(el, colonias, selected, extra);
    else onChange();
    return el;
}

/* ---------- CP → entidad federativa ----------
 * First two digits of a Mexican CP deterministically encode the state.
 * [prefixFrom, prefixTo, entidadCatalogCode]. Mirrors the module's
 * catalogs/cp_prefix_entidades.json. Prefixes 17-19 are unassigned in SEPOMEX
 * and intentionally absent -> no fill (notary selects manually).
 */
const CP_ENTIDAD_RANGES = [[0,16,"9"], [20,20,"1"], [21,22,"2"], [23,23,"3"], [24,24,"4"], [25,27,"5"], [28,28,"6"], [29,30,"7"], [31,33,"8"], [34,35,"10"], [36,38,"11"], [39,41,"12"], [42,43,"13"], [44,49,"14"], [50,57,"15"], [58,61,"16"], [62,62,"17"], [63,63,"18"], [64,67,"19"], [68,71,"20"], [72,75,"21"], [76,76,"22"], [77,77,"23"], [78,79,"24"], [80,82,"25"], [83,85,"26"], [86,86,"27"], [87,89,"28"], [90,90,"29"], [91,96,"30"], [97,97,"31"], [98,99,"32"]];

function entidadFromCp(cp) {
    const digits = (cp ?? '').replace(/\D/g, '');
    if (digits.length < 2) return '';
    const prefix = parseInt(digits.slice(0, 2), 10);
    for (const [from, to, code] of CP_ENTIDAD_RANGES) {
        if (prefix >= from && prefix <= to) return code;
    }
    return '';
}

function cpEntidad(cpInput, targetField) {
    const code = entidadFromCp(cpInput.value);
    if (!code) return; // unmapped/invalid CP -> leave entidad as-is

    // Find the entidad select in the SAME scope (same domicilio object),
    // mirroring how cpLookup locates the colonia select.
    const cpPath = cpInput.dataset.path;
    const scope = cpPath.substring(0, cpPath.lastIndexOf('.'));
    const sel = document.querySelector(`.rv-input[data-path="${cssEsc(scope + '.' + targetField)}"]`);
    if (!sel) return;

    // The CP is the source of truth for the state: always set entidad to match
    // the CP, overwriting any previous value. If the new value equals the
    // current one, do nothing (avoids a redundant change event).
    if (String(sel.value ?? '') === String(code)) return;

    // Set the value whether or not the Choices instance is ready yet.
    if (sel._choices) {
        // Choices.js v11: setChoiceByValue reliably swaps a single-select's
        // active option. Pass a string to match the option values.
        sel._choices.setChoiceByValue(String(code));
        // Keep the underlying <select> in sync for any code that reads sel.value.
        sel.value = String(code);
    } else {
        // Choices not initialised yet: mark the raw <option>; initChoices()
        // will honour the pre-selected option when it wraps the select.
        for (const opt of sel.options) opt.selected = (String(opt.value) === String(code));
        sel.value = String(code);
    }
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    if (typeof onChange === 'function') onChange();
}

function populateColoniaSelect(select, colonias, selectedValue = null, extraValue = null) {
    if (select._choices) {
        try { select._choices.destroy(); } catch (e) {}
        select._choices = null;
    }
    select.classList.remove('choices-done');

    select.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Seleccione una colonia';
    select.appendChild(placeholder);

    const choices = colonias.map(c => ({
        value: c.value,
        label: c.label,
        selected: selectedValue != null && String(selectedValue) === String(c.value),
    }));
    // The deed's colonia isn't in this CP's catalog: keep it selectable (and selected)
    // so nothing is lost; the user decides.
    if (extraValue) {
        choices.unshift({ value: extraValue, label: `${extraValue} (de la escritura, no está en el catálogo de este C.P.)`, selected: true });
    }

    const instance = new Choices(select, {
        searchEnabled: true,
        itemSelectText: '',
        shouldSort: false,
        allowHTML: false,
    });
    instance.setChoices(choices, 'value', 'label', true);

    select._choices = instance;
    select.classList.add('choices-done');
    onChange();
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

/* ---------- Choices.js ---------- */
function initChoices(scope) {
    scope.querySelectorAll('select.rv-input:not(.choices-done)').forEach(sel => {
        sel.classList.add('choices-done');
        sel._choices = new Choices(sel, {   // ← store the instance
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
            const rowValues = {};
            (arrDef.classifier.tipo_field ? [arrDef.classifier.tipo_field] : []).forEach(() => {});
            // gather the fields the classifier needs
            [arrDef.classifier.tipo_field, arrDef.classifier.rfc_field].filter(Boolean).forEach(f => {
                const el = row.querySelector(`.rv-input[data-field="${f}"]`);
                rowValues[f] = el?.value;
            });
            const kase = classifyRow(arrDef.classifier, rowValues);
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

    // if (def.type === 'select' && def.options) {
    //     if (!def.options.some(o => String(o.value) === String(value))) return 'Valor no permitido.';
    // }
    if (def.type === 'select' && def.options && !def.cp_target) {
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

/* ---------- Dates encoded in IDs (mirror of app/Services/Schema/IdDates.php) ----------
 * birthdate_from_id:   CURP valid → always wins; else RFC física → only if empty.
 * date_from_rfc_moral: RFC moral → only if empty.
 */
const ID_DATE_GENERIC_RFC = ['XAXX010101000', 'XEXX010101000', 'EXTF900101000', 'EXT990101000'];

function ymdOrNull(y, m, d) {
    const dt = new Date(Date.UTC(y, m - 1, d));
    if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== m - 1 || dt.getUTCDate() !== d) return null;
    return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

function dateFromCurp(curp) {
    const m = String(curp ?? '').trim().toUpperCase().match(/^[A-Z]{4}(\d{2})(\d{2})(\d{2})[HMX][A-Z]{5}([A-Z0-9])\d$/);
    if (!m) return null;
    const century = /\d/.test(m[4]) ? 1900 : 2000;
    return ymdOrNull(century + +m[1], +m[2], +m[3]);
}

function dateFromRfcFisica(rfc, currentYear = new Date().getFullYear()) {
    const r = String(rfc ?? '').trim().toUpperCase();
    if (ID_DATE_GENERIC_RFC.includes(r)) return null;
    const m = r.match(/^[A-ZÑ&]{4}(\d{2})(\d{2})(\d{2})[A-Z0-9]{3}$/);
    if (!m) return null;
    let year = 2000 + +m[1];
    if (year > currentYear - 18) year -= 100;
    return ymdOrNull(year, +m[2], +m[3]);
}

function dateFromRfcMoral(rfc, currentYear = new Date().getFullYear()) {
    const r = String(rfc ?? '').trim().toUpperCase();
    if (ID_DATE_GENERIC_RFC.includes(r)) return null;
    const m = r.match(/^[A-ZÑ&]{3}(\d{2})(\d{2})(\d{2})[A-Z0-9]{3}$/);
    if (!m) return null;
    let year = 2000 + +m[1];
    if (year > currentYear) year -= 100;
    return ymdOrNull(year, +m[2], +m[3]);
}

function deriveFromId(rule, row, current) {
    const empty = current == null || String(current).trim() === '';
    if (rule.rule === 'birthdate_from_id') {
        const fromCurp = dateFromCurp(row[rule.curp || 'curp']);
        if (fromCurp) return fromCurp;
        return empty ? (dateFromRfcFisica(row[rule.rfc || 'rfc']) ?? current) : current;
    }
    if (rule.rule === 'date_from_rfc_moral') {
        return empty ? (dateFromRfcMoral(row[rule.rfc || 'rfc']) ?? current) : current;
    }
    return current;
}

/** A CURP/RFC changed → recompute derived dates in the same scope (row / object). */
function onDeriveSource(e) {
    const src = e.target;
    if (!src?.classList?.contains('rv-input') || !src.dataset.path || !src.dataset.field) return;

    const parts = src.dataset.path.split('.');
    parts.pop();
    const scope = parts.join('.');
    const fields = scope ? defForPath(scope)?.itemSchema : SCHEMA.fields;
    if (!fields) return;

    const inputAt = (name) => document.querySelector(`.rv-input[data-path="${cssEsc(scope ? `${scope}.${name}` : name)}"]`);
    let changed = false;

    for (const [name, def] of Object.entries(fields)) {
        if (!def?.derive?.rule) continue;
        const { rule, ...sources } = def.derive;
        if (!Object.values(sources).includes(src.dataset.field)) continue;

        const target = inputAt(name);
        if (!target) continue;
        const row = {};
        for (const f of Object.values(sources)) row[f] = inputAt(f)?.value ?? null;

        const next = deriveFromId(def.derive, row, target.value);
        if (next && next !== target.value) {
            target.value = next;
            changed = true;
        }
    }
    if (changed) onChange();
}

/* ---------- Resolver notes (info, not errors) ----------
 * Server notes mark values filled by reference ("en esta fecha",
 * "mismo domicilio que el anterior") so the user verifies them.
 * Path targets a field (.rv-field) or an object block (.rv-subsection).
 */
function applyNotes(notes) {
    for (const n of notes) {
        if (!n?.path || !n.message) continue;
        const target = document.querySelector(`[data-path="${cssEsc(n.path)}"]`);
        if (!target) continue;

        const note = document.createElement('div');
        note.className = 'rv-note';
        note.dataset.kind = n.kind || 'info';
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-circle-info';
        const text = document.createElement('span');
        text.textContent = n.message;
        note.append(icon, text);

        if (target.classList.contains('rv-subsection')) {
            const title = target.querySelector(':scope > .rv-subsection__title');
            title ? title.after(note) : target.prepend(note);
        } else {
            target.classList.add('has-note');
            const err = target.querySelector(':scope > .rv-field__error');
            err ? target.insertBefore(note, err) : target.appendChild(note);
        }
    }
}

/* ---------- Draft autosave ----------
 * Saves the raw form state a few seconds after the user stops editing.
 *  - arms only after the initial render settles (CP lookups, entidad fill), and
 *    compares against the last saved JSON, so loading never creates a save;
 *  - optimistic lock: sends the version it loaded; a 409 means another tab or
 *    session saved first → stop autosaving and ask the user to reload;
 *  - network errors retry; a pending change is flushed on page unload (beacon).
 */
const AUTOSAVE_DELAY_MS = 2500;
const AUTOSAVE_RETRY_MS = 10000;
const AUTOSAVE = { ready: false, timer: null, inFlight: false, pending: false, blocked: false, lastJson: null, draft: { version: 0, saved_at: null } };

function resetAutosave(draft) {
    clearTimeout(AUTOSAVE.timer);
    Object.assign(AUTOSAVE, {
        ready: false, timer: null, inFlight: false, pending: false, blocked: false, lastJson: null,
        draft: { version: draft?.version ?? 0, saved_at: draft?.saved_at ?? null },
    });
    setDraftStatus(AUTOSAVE.draft.saved_at ? 'saved' : 'clean');
}

async function armAutosaveWhenSettled() {
    await Promise.allSettled(INITIAL_LOOKUPS);
    await new Promise(r => setTimeout(r, 0));          // let deferred cpEntidad fills run
    AUTOSAVE.lastJson = JSON.stringify(collect(SCHEMA.fields, ''));
    AUTOSAVE.ready = true;
}

function autosaveEnabled() {
    return AUTOSAVE.ready && !AUTOSAVE.blocked && DOCUMENT_ID && DOCUMENT_ID !== 'debug';
}

function scheduleAutosave() {
    if (!autosaveEnabled()) return;
    setDraftStatus('dirty');
    clearTimeout(AUTOSAVE.timer);
    AUTOSAVE.timer = setTimeout(flushAutosave, AUTOSAVE_DELAY_MS);
}

async function flushAutosave() {
    if (!autosaveEnabled()) return;
    clearTimeout(AUTOSAVE.timer);
    AUTOSAVE.timer = null;
    if (AUTOSAVE.inFlight) { AUTOSAVE.pending = true; return; }

    const data = collect(SCHEMA.fields, '');
    const json = JSON.stringify(data);
    if (json === AUTOSAVE.lastJson) {
        setDraftStatus(AUTOSAVE.draft.saved_at ? 'saved' : 'clean');
        return;
    }

    AUTOSAVE.inFlight = true;
    setDraftStatus('saving');
    try {
        const res = await http.post(`/documents/${DOCUMENT_ID}/draft`, { data, version: AUTOSAVE.draft.version });
        AUTOSAVE.draft = res.draft;
        AUTOSAVE.lastJson = json;
        setDraftStatus('saved');
    } catch (err) {
        if (err?.status === 409) {
            AUTOSAVE.blocked = true;
            setDraftStatus('conflict');
        } else {
            setDraftStatus('error');
            AUTOSAVE.timer = setTimeout(flushAutosave, AUTOSAVE_RETRY_MS);
        }
    } finally {
        AUTOSAVE.inFlight = false;
        if (AUTOSAVE.pending) { AUTOSAVE.pending = false; flushAutosave(); }
    }
}

/** Validate / export saved server-side (no version check): adopt the new version. */
function syncDraftAfterExplicitSave(draft, data) {
    if (!draft) return;
    clearTimeout(AUTOSAVE.timer);
    AUTOSAVE.timer = null;
    AUTOSAVE.draft = draft;
    AUTOSAVE.lastJson = JSON.stringify(data);
    AUTOSAVE.blocked = false;
    setDraftStatus('saved');
}

/** Tab closing with unsaved edits: last-chance save (fire-and-forget). */
window.addEventListener('pagehide', () => {
    if (!autosaveEnabled() || !navigator.sendBeacon) return;
    const data = collect(SCHEMA.fields, '');
    const json = JSON.stringify(data);
    if (json === AUTOSAVE.lastJson) return;
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const body = new Blob([JSON.stringify({ data, version: AUTOSAVE.draft.version, _token: token })], { type: 'application/json' });
    navigator.sendBeacon(`/documents/${DOCUMENT_ID}/draft`, body);
});

function setDraftStatus(state) {
    const btn = document.getElementById('review-save');
    if (!btn) return;
    let el = document.getElementById('rv-draft-status');
    if (!el) {
        el = document.createElement('span');
        el.id = 'rv-draft-status';
        el.className = 'rv-draft-status';
        el.setAttribute('aria-live', 'polite');
        btn.parentElement.insertBefore(el, btn);
    }
    const time = AUTOSAVE.draft.saved_at
        ? new Date(AUTOSAVE.draft.saved_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })
        : '';
    const text = {
        clean: '',
        dirty: 'Cambios sin guardar…',
        saving: 'Guardando…',
        saved: time ? `Borrador guardado · ${time}` : 'Borrador guardado',
        error: 'No se pudo guardar. Reintentando…',
        conflict: 'Este documento se guardó en otra pestaña o sesión. Recarga la página para continuar.',
    }[state] ?? '';
    el.dataset.state = state;
    el.textContent = text;
    el.hidden = !text;
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

    syncDraftAfterExplicitSave(result.draft, data);

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

        const v = parseInt(res.headers.get('X-Draft-Version') ?? '', 10);
        if (!Number.isNaN(v)) syncDraftAfterExplicitSave({ version: v, saved_at: new Date().toISOString() }, data);

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

function refreshErrorBadges() {
    let grandTotal = 0;

    // 1. Child nav items — count errors within their specific DOM subtree
    document.querySelectorAll('.rv-nav__item--child').forEach(navChild => {
        const targetPath = navChild.dataset.targetPath;
        const subtree = document.querySelector(`[data-path="${cssEsc(targetPath)}"]`);
        const errors = subtree
            ? [...subtree.querySelectorAll('.rv-field.has-error')].filter(f => f.offsetParent !== null).length
            : 0;
        setBadge(navChild, errors);
    });

    // 2. Section nav items — count ALL errors in the section (includes its children's)
    document.querySelectorAll('.rv-section').forEach((sec, i) => {
        const errors = [...sec.querySelectorAll('.rv-field.has-error')]
            .filter(f => f.offsetParent !== null).length;
        grandTotal += errors;
        const navItem = document.querySelector(`.rv-nav__item[data-target="${sec.id}"]`);
        if (navItem) setBadge(navItem, errors);
    });

    updateSummary(grandTotal);
}

function setBadge(navItem, count) {
    const badge = navItem.querySelector('.rv-nav__badge');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count;
        badge.hidden = false;
        navItem.classList.add('has-errors');
    } else {
        badge.hidden = true;
        navItem.classList.remove('has-errors');
    }
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