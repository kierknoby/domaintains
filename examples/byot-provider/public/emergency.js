'use strict';

const selection = document.querySelector('#selection');
const form = document.querySelector('#registration');
const section = document.querySelector('#number-section');
const number = document.querySelector('#number');
const confirmed = document.querySelector('#confirmed');
const submit = document.querySelector('#submit');
const notice = document.querySelector('#notice');
const statusLine = document.querySelector('#registration-status');
const labels = {unregistered: 'Not registered', received: 'Information received', awaiting_validation: 'Awaiting validation', submitted: 'Submitted', awaiting_acceptance: 'Awaiting acceptance', registered: 'Registered', rejected: 'Rejected'};
const fields = ['title', 'forename', 'honours', 'name', 'business_suffix', 'address_line_1', 'address_line_2', 'locality', 'region', 'postcode', 'country'];
let intent = '';
let canChangeType = true;
let busy = false;
const retryAt = {retrieve: 0, submit: 0, status: 0};
let contexts = [];

function message(text, error = false) { notice.textContent = text; notice.dataset.error = String(error); }
function clearDetails() {
    form.hidden = true;
    form.reset();
    fields.forEach(field => { form.elements[field].value = ''; });
    confirmed.checked = false;
    intent = '';
    submit.disabled = true;
}
function controls(working) {
    busy = working;
    document.querySelectorAll('button, input, select').forEach(control => { control.disabled = working; });
    submit.disabled = working || !confirmed.checked || !intent || Date.now() < retryAt.submit;
    form.querySelectorAll('[name="type"]').forEach(control => { control.disabled = working || !canChangeType; });
    document.querySelector('#check-status').disabled = working || Date.now() < retryAt.status;
    document.querySelector('#retrieve').disabled = working || Date.now() < retryAt.retrieve;
}
function types() {
    const individual = form.elements.type.value === 'individual';
    form.querySelectorAll('[data-individual]').forEach(element => { element.hidden = !individual; });
    form.querySelectorAll('[data-business]').forEach(element => { element.hidden = individual; });
    document.querySelector('#name-label').textContent = individual ? 'Surname' : 'Business name';
}
async function request(action, extra = {}) {
    const body = {action, csrf: document.querySelector('meta[name="csrf-token"]').content};
    if (action !== 'contexts') { body.service = selection.elements.service.value; body.pbx = selection.elements.pbx.value; }
    if (!['list', 'contexts'].includes(action)) body.number_id = number.value;
    const response = await fetch('emergency-api.php', {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({...body, ...extra})});
    const data = await response.json();
    const waits = data.retry_after_by_operation || (data.retry_after ? {[action]: data.retry_after} : {});
    for (const operation of ['retrieve', 'submit', 'status']) {
        if (Number.isFinite(waits[operation]) && waits[operation] >= 0) {
            retryAt[operation] = Date.now() + waits[operation] * 1000;
            window.setTimeout(() => { if (!busy) controls(false); }, waits[operation] * 1000 + 50);
        }
    }
    if (!response.ok || data.ok !== true) throw new Error(data.message || 'Emergency information is unavailable. Please retry.');
    return data;
}
async function retrieve() {
    if (busy || !number.value) return;
    clearDetails();
    statusLine.textContent = '';
    controls(true);
    message('Retrieving emergency information...');
    try {
        const data = await request('retrieve');
        fields.forEach(field => { form.elements[field].value = data.registration[field] || ''; });
        form.elements.type.value = data.type;
        canChangeType = data.can_change_type === true;
        intent = data.intent;
        types();
        form.hidden = false;
        statusLine.textContent = labels[data.status] || 'Unknown status';
        message('');
    } catch (error) { clearDetails(); message(error.message, true); }
    finally { controls(false); }
}
selection.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    clearDetails();
    section.hidden = true;
    number.replaceChildren();
    statusLine.textContent = '';
    controls(true);
    message('Retrieving authorised numbers...');
    try {
        const data = await request('list');
        data.numbers.forEach(item => { number.add(new Option(item.label, item.id)); });
        section.hidden = data.numbers.length === 0;
        message(data.numbers.length ? '' : 'No authorised numbers are available.');
    } catch (error) { message(error.message, true); }
    finally { controls(false); }
    if (!section.hidden) await retrieve();
});
['service', 'pbx'].forEach(field => selection.elements[field].addEventListener('input', () => { clearDetails(); section.hidden = true; number.replaceChildren(); statusLine.textContent = ''; }));
selection.elements.service.addEventListener('change', () => {
    selection.elements.pbx.replaceChildren();
    contexts.filter(item => item.service === selection.elements.service.value).forEach(item => { selection.elements.pbx.add(new Option(item.pbx, item.pbx)); });
});
number.addEventListener('change', () => {
    Object.keys(retryAt).forEach(operation => { retryAt[operation] = 0; });
    retrieve();
});
document.querySelector('#retrieve').addEventListener('click', retrieve);
document.querySelector('#check-status').addEventListener('click', async () => {
    if (busy) return;
    clearDetails();
    statusLine.textContent = '';
    controls(true);
    message('Checking provider registration status...');
    try { const data = await request('status'); statusLine.textContent = labels[data.status] || 'Unknown status'; message(''); }
    catch (error) { message(error.message, true); }
    finally { controls(false); }
});
form.querySelectorAll('[name="type"]').forEach(control => control.addEventListener('change', () => {
    ['title', 'forename', 'honours', 'name', 'business_suffix'].forEach(field => { form.elements[field].value = ''; });
    confirmed.checked = false;
    types();
    controls(false);
}));
form.addEventListener('input', event => { if (event.target !== confirmed) confirmed.checked = false; submit.disabled = !confirmed.checked || !intent || busy || Date.now() < retryAt.submit; });
form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !confirmed.checked || !intent) return;
    const registration = Object.fromEntries(fields.map(field => [field, form.elements[field].value]));
    const payload = {registration, type: form.elements.type.value, confirmed: true, intent};
    controls(true);
    message('Submitting emergency information...');
    try {
        const data = await request('submit', payload);
        statusLine.textContent = labels[data.status] || 'Unknown status';
        message('');
    } catch (error) { statusLine.textContent = ''; message(error.message, true); }
    finally { clearDetails(); controls(false); }
});
window.addEventListener('pagehide', () => { clearDetails(); statusLine.textContent = ''; });
window.addEventListener('pageshow', event => { if (event.persisted) { clearDetails(); section.hidden = true; number.replaceChildren(); } });
async function initialise() {
    controls(true);
    message('Retrieving authorised services...');
    try {
        const data = await request('contexts');
        contexts = data.contexts;
        [...new Set(contexts.map(item => item.service))].forEach(service => { selection.elements.service.add(new Option(service, service)); });
        selection.elements.service.dispatchEvent(new Event('change'));
        message(contexts.length ? '' : 'No authorised services are available.');
    } catch (error) { message(error.message, true); }
    finally { controls(false); }
}
initialise();