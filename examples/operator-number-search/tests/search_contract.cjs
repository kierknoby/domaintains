'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const crypto = require('node:crypto');

const canonicalSource = fs.readFileSync(path.join(__dirname, '../public/dn-progressive-search.js'), 'utf8')
    .replace("            body.set('context', cfg.context);\n", '')
    .replace("fetch(cfg.availabilityUrl || '/domaintains/availability.php', {", "fetch('/domaintains/availability.php', {")
    .replace("        var controlId = Array.from(String(cfg.context)).map(function (character) { return character.codePointAt(0).toString(16); }).join('-');\n", '')
    .replace("refresh.id = 'dtRefreshAvailability-' + controlId;", "refresh.id = 'dtRefreshAvailability';")
    .replace("radio.name = 'domaintainsNumberChoice-' + controlId;", "radio.name = 'domaintainsNumberChoice';")
    .replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/[^\n]*\n/gm, '');
assert.equal(crypto.createHash('sha256').update(canonicalSource).digest('hex'),
    'bdb2b216848d400c9702115d88cf383f06da5700ddebb561a6d154168a4d56e8',
    'canonical executable engine must match supplied source apart from documented endpoint/context and control-identity wiring');

class Element {
    constructor(tag) {
        this.tagName = tag; this.children = []; this.style = {}; this.attributes = {}; this.listeners = {};
        this.value = ''; this.disabled = false; this._text = ''; this.className = '';
        this.classList = {contains: name => this.className.split(' ').includes(name), toggle: (name, enabled) => {
            const classes = this.className.split(' ').filter(item => item && item !== name);
            if (enabled) { classes.push(name); } this.className = classes.join(' ');
        }};
    }
    set textContent(value) { this._text = value; this.children = []; }
    get textContent() { return this._text; }
    get nextSibling() { return this.parentNode ? this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null : null; }
    get previousElementSibling() { return this.parentNode ? this.parentNode.children[this.parentNode.children.indexOf(this) - 1] || null : null; }
    get offsetParent() { return this.style.display === 'none' ? null : this.parentNode; }
    get checked() { return this._checked || false; }
    set checked(value) {
        this._checked = value;
        const form = this.closest('form');
        if (value && this.type === 'radio' && form) {
            for (const other of form.querySelectorAll('input[type="radio"]')) {
                if (other !== this && other.name === this.name) { other._checked = false; }
            }
        }
    }
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
    insertBefore(child, before) { child.parentNode = this; const index = this.children.indexOf(before); this.children.splice(index < 0 ? this.children.length : index, 0, child); }
    setAttribute(name, value) { this.attributes[name] = value; }
    getAttribute(name) { return this.attributes[name]; }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) { listener(event); } }
    matches(selector) {
        if (selector.startsWith('.')) { return this.className.split(' ').includes(selector.slice(1)); }
        const match = /^([a-z]+)?(?:\[([a-z]+)="([^"]*)"\])?$/.exec(selector);
        return !!match && (!match[1] || this.tagName === match[1]) && (!match[2] || this[match[2]] === match[3]);
    }
    closest(selector) { for (let node = this; node; node = node.parentNode) { if (selector.split(',').some(item => node.matches(item))) { return node; } } return null; }
    querySelectorAll(selector) { return this.children.flatMap(child => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]); }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    scrollIntoView() {}
    click() { if (!this.disabled) { this.dispatchEvent({type: 'click', preventDefault() {}}); } }
}

function fixture() {
    const head = new Element('head'), elements = [];
    const document = {head, createElement(tag) { const node = new Element(tag); elements.push(node); return node; },
        querySelectorAll(selector) { return elements.filter(node => node.matches(selector)); },
        getElementById(id) { return elements.find(node => node.id === id); }};
    const nodes = {};
    for (const name of ['territory', 'region', 'prefix', 'numbers', 'field', 'selected', 'submit', 'range']) { nodes[name] = new Element(name); }
    const parent = new Element('div'); parent.appendChild(nodes.numbers);
    const requests = [], deferred = [], storage = new Map();
    let response = [];
    let failure = null;
    let hold = false;
    const sandbox = {window: {}, document, Map, Set, URLSearchParams, AbortController,
        Event: class {constructor(type) {this.type = type;}}, sessionStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)},
        setTimeout, clearTimeout,
        fetch(url, options) {
            requests.push({url, params: new URLSearchParams(options.body)});
            if (!hold) { return Promise.resolve({status: failure ? failure.status : 200, ok: !failure, json: async () => failure ? failure.body : ({ok: true, numbers: response})}); }
            return new Promise((resolve, reject) => {
                deferred.push(resolve);
                options.signal.addEventListener('abort', () => reject(Object.assign(new Error('cancelled'), {name: 'AbortError'})));
            });
        }};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/dn-progressive-search.js'), 'utf8'), sandbox);
    sandbox.window.DNNumberLivePicker({...nodes, regions: {}, context: 'update-10', nonce: 'test-session', availabilityUrl: '/local-search',
        nationalDisplay: number => /^44/.test(number) ? '0' + number.slice(2) : number,
        showSelected(number) {nodes.selected.textContent = number;}, clearStatus() {}, setStatus() {}});
    const buttons = () => elements.filter(node => node.tagName === 'button');
    const button = text => buttons().find(node => node.textContent === text);
    const inputs = elements.filter(node => node.type === 'search');
    function enter(index, value) {inputs[index].value = value; inputs[index].dispatchEvent({type: 'input'});}
    return {nodes, requests, inputs, button, enter, storage, deferred, sandbox, elements, document,
        offers(numbers) {response = numbers.map((number, index) => ({number, recommended: index < 3}));}, hold(value) {hold = value;},
        failure(status, body = {}) {failure = {status, body};},
        location() {nodes.prefix.value = '0204'; nodes.prefix.dispatchEvent({type: 'change'});}};
}
const flush = async () => { for (let index = 0; index < 40; index++) { await Promise.resolve(); } };
const number = subscriber => '44204' + subscriber;

(async () => {
    const first = fixture();
    first.offers([number('2222222')]); first.location(); await flush();
    assert.equal(first.requests.length, 1);
    assert.equal(first.requests[0].url, '/local-search');
    assert.equal(first.requests[0].params.get('service_id'), '10');
    assert.equal(first.requests[0].params.get('context'), 'update-10');
    first.enter(0, '020'); first.offers([number('2222222')]); first.button('Search').click(); await flush();
    assert.equal(first.nodes.numbers.children.length, 0, 'area-code digits do not match subscriber terms');
    assert.equal(first.requests.length, 6, 'first pattern checks only its five valid positions');
    first.button('Clear').click();
    assert.equal(first.nodes.numbers.children.length, 1);
    assert.equal(first.requests.length, 6, 'Clear never contacts carrier');
    first.enter(0, '12'); first.enter(1, '34'); first.enter(2, '56');
    first.offers([number('1234567'), number('9956999'), number('9934999'), number('1234999'), number('2222222'), number('1234567'), '441611234567', 'invalid']);
    const before = first.requests.length;
    first.button('Search').click(); await flush();
    assert.equal(first.requests.length - before, 6, 'at most six sequential carrier calls per explicit click');
    assert.deepEqual(first.requests.slice(before, before + 3).map(item => item.params.get('search')), ['12', '34', '56']);
    assert.equal(first.nodes.numbers.children.length, 4, 'every batch is checked against all three OR terms and deduplicated');
    const triple = first.nodes.numbers.children.find(node => node.getAttribute('data-number') === number('1234567'));
    assert.match(triple.style.backgroundImage, /33\.3333%/);
    assert.match(triple.style.backgroundImage, /66\.6667%/);
    assert.equal(triple.title, 'Matches Search 1, Search 2, Search 3');
    const double = first.nodes.numbers.children.find(node => node.getAttribute('data-number') === number('1234999'));
    assert.equal(double.title, 'Matches Search 1, Search 2');
    assert.match(double.style.backgroundImage, /50\.0000%/);
    assert.equal(first.nodes.numbers.children.find(node => node.getAttribute('data-number') === number('9956999')).title, 'Matches Search 3');
    const callCount = first.requests.length;
    first.enter(1, '12');
    assert.equal(first.inputs[0].attributes['aria-invalid'], 'true');
    assert.equal(first.inputs[1].attributes['aria-invalid'], 'true');
    assert.equal(first.button('Search').disabled, true);
    assert.equal(first.button('Refresh Availability List').disabled, true);
    first.enter(1, 'a1'); assert.equal(first.inputs[1].value, 'a1');
    for (const value of ['1', '12345678']) { first.enter(1, value); assert.equal(first.inputs[1].attributes['aria-invalid'], 'true'); }
    assert.equal(first.requests.length, callCount, 'editing and validation make no calls');
    first.enter(0, ''); first.enter(1, ''); first.enter(2, '');
    first.button('Refresh Availability List').click(); await flush();
    assert.equal(first.requests.length, callCount + 1, 'manual refresh is exactly one broad request');
    assert.equal(first.requests.at(-1).params.get('search'), null);
    assert.equal(JSON.stringify([...first.storage.values()]).includes('search'), false);

    const pages = fixture();
    pages.offers(Array.from({length: 20}, (_, index) => number('120' + String(index).padStart(4, '0'))));
    pages.location(); await flush();
    pages.offers(Array.from({length: 20}, (_, index) => number('121' + String(index).padStart(4, '0'))));
    pages.button('Refresh Availability List').click(); await flush();
    pages.enter(0, '12'); pages.button('Search').click(); await flush();
    assert.equal(pages.requests.length, 2, '20 cached matches suppress unsolicited carrier search');
    pages.button('See more').click();
    assert.equal(pages.nodes.numbers.children.length, 40);
    const chosen = pages.nodes.numbers.children[30].getAttribute('data-number');
    pages.nodes.numbers.children[30].click(); pages.button('See less').click();
    assert.equal(pages.nodes.field.value, chosen);
    assert.equal(pages.nodes.numbers.children.length, 21, 'selected later-page result remains visible after collapse');
    assert.equal(pages.requests.length, 2);
    pages.hold(true); pages.button('Find more matches').click(); await flush();
    assert.equal(pages.button('Stop').disabled, false);
    pages.button('Stop').click(); await flush();
    assert.equal(pages.requests.length, 3, 'Stop prevents subsequent carrier calls');
    assert.equal(pages.nodes.field.value, chosen);
    pages.button('Clear').click();
    assert.equal(pages.nodes.field.value, chosen);
    assert.equal(pages.nodes.numbers.children.length, 20, 'Clear restores the current broad batch locally');
    assert.equal(pages.requests.length, 3);

    const editing = fixture(); editing.offers([]); editing.location(); await flush();
    editing.enter(0, '12'); editing.hold(true); editing.button('Search').click(); await flush();
    editing.enter(0, '34'); await flush();
    assert.equal(editing.button('Search').textContent, 'Search');
    assert.equal(editing.requests.length, 2, 'editing cancels the old request without starting a new search');

    for (const [status, body, message] of [[403, {error: 'Selection session expired'}, /authorisation is no longer valid/],
        [403, {error: 'Forbidden'}, /blocked/], [429, {}, /Too many requests/], [503, {}, /temporarily busy/], [502, {}, /could not be updated/]]) {
        const failed = fixture(); failed.failure(status, body); failed.location(); await flush();
        assert.equal(failed.requests.length, 1, 'failed requests are never retried automatically');
        assert.match(failed.elements.find(node => node.attributes['aria-live'] === 'polite').textContent, message);
    }

    const bound = fixture();
    bound.sandbox.document.readyState = 'complete';
    const form = bound.document.createElement('form');
    const anchor = bound.document.createElement('input'); anchor.id = 'option-local'; form.appendChild(anchor);
    const target = bound.document.createElement('div'); target.id = 'target-local'; form.appendChild(target);
    const field = bound.document.createElement('input'); field.id = 'existing-selection'; field.name = 'selected_number'; field.value = number('1234567'); form.appendChild(field);
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/number-picker.js'), 'utf8'), bound.sandbox);
    const binding = {context: 'cart-0', mode: 'cart', targetId: 'target-local', fieldId: 'existing-selection', selectionFieldName: 'selected_number',
        nonce: 'fixture', availabilityUrl: '/local-search', regions: {England: {London: {'0204': 'London'}}}};
    bound.sandbox.window.DNNumberPickerMount(binding);
    assert.equal(field.value, number('1234567'), 'mount preserves the operator selection field');
    assert.equal(form.querySelectorAll('input[name="selected_number"]').length, 1, 'no duplicate selection field');
    bound.sandbox.window.DNNumberPickerMount(binding);
    assert.equal(form.querySelectorAll('.dn-number-selector').length, 1, 'repeated mounting cannot duplicate the picker');
    const territory = bound.document.getElementById('dn-territory-63-61-72-74-2d-30');
    const region = bound.document.getElementById('dn-region-63-61-72-74-2d-30');
    const prefix = bound.document.getElementById('dn-area-code-63-61-72-74-2d-30');
    territory.value = 'England'; territory.dispatchEvent({type: 'change'});
    assert.equal(region.disabled, false); assert.equal(prefix.disabled, true);
    region.value = 'London'; region.dispatchEvent({type: 'change'}); assert.equal(prefix.disabled, false);
    assert.equal(bound.requests.length, 0, 'territory and region selection make no carrier request');
    bound.offers([number('1234567')]); prefix.value = '0204'; prefix.dispatchEvent({type: 'change'}); await flush();
    assert.equal(bound.requests.length, 1);
    const grid = form.querySelector('.dt-number-grid'); grid.children[0].click();
    assert.equal(field.value, number('1234567'));
    let prevented = false; field.value = '';
    form.dispatchEvent({type: 'submit', preventDefault() {prevented = true;}});
    assert.equal(prevented, true, 'empty selection cannot submit the cart form');
    const pair = fixture();
    pair.document.readyState = 'complete';
    const sharedForm = pair.document.createElement('form');
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/number-picker.js'), 'utf8'), pair.sandbox);
    const contexts = ['cart-item-a', 'cart-item-b'];
    for (const context of contexts) {
        const itemTarget = pair.document.createElement('div'); itemTarget.id = context; sharedForm.appendChild(itemTarget);
        pair.sandbox.window.DNNumberPickerMount({...binding, context, targetId: context, fieldId: undefined});
    }
    const itemFields = sharedForm.querySelectorAll('input[type="hidden"]');
    assert.equal(itemFields.length, 2, 'two cart items in one form must have separate selection fields');
    assert.notEqual(itemFields[0].name, itemFields[1].name, 'submitted field names must identify distinct cart items');
    for (let index = 0; index < contexts.length; index++) {
        const token = Array.from(contexts[index]).map(character => character.codePointAt(0).toString(16)).join('-');
        assert.equal(itemFields[index].name, 'selected_number[' + token + ']', 'generated field maps to its exact context');
        assert.equal(itemFields[index].getAttribute('data-dn-context'), contexts[index]);
    }
    const pickers = sharedForm.querySelectorAll('.dn-number-selector');
    for (const picker of pickers) {
        const selects = picker.querySelectorAll('select');
        selects[0].value = 'England'; selects[0].dispatchEvent({type: 'change'});
        selects[1].value = 'London'; selects[1].dispatchEvent({type: 'change'});
        pair.offers([number('1234567'), number('7654321')]);
        selects[2].value = '0204'; selects[2].dispatchEvent({type: 'change'}); await flush();
    }
    assert.deepEqual(pair.requests.map(request => request.params.get('context')), contexts, 'requests retain exact cart identities');
    const grids = pickers.map(picker => picker.querySelector('.dt-number-grid'));
    grids[0].children[0].click();
    assert.equal(itemFields[0].value, number('1234567'));
    assert.equal(itemFields[1].value, '', 'selecting item A cannot change item B');
    grids[1].children[1].click();
    assert.equal(itemFields[0].value, number('1234567'), 'selecting item B preserves item A');
    assert.equal(itemFields[1].value, number('7654321'));
    const radios = [grids[0].children[0].querySelector('input'), grids[1].children[1].querySelector('input')];
    assert.notEqual(radios[0].name, radios[1].name, 'each picker must have its own radio group');
    assert.equal(radios[0].checked, true, 'item B must not deselect item A');
    assert.equal(radios[1].checked, true);
    const refreshes = pickers.map(picker => picker.querySelector('.dn-progressive-refresh'));
    assert.notEqual(refreshes[0].id, refreshes[1].id, 'each picker must have a unique Refresh ID');
    refreshes[0].click(); await flush();
    assert.equal(pair.requests.at(-1).params.get('context'), contexts[0], 'Refresh uses its own cart context');
    assert.equal(itemFields[1].value, number('7654321'), 'Refresh in A preserves item B selection');
    assert.equal(radios[1].checked, true);
    const duplicateTarget = pair.document.createElement('div'); duplicateTarget.id = 'duplicate-target'; sharedForm.appendChild(duplicateTarget);
    pair.sandbox.window.DNNumberPickerMount({...binding, context: contexts[0], targetId: duplicateTarget.id, fieldId: undefined});
    assert.equal(sharedForm.querySelectorAll('.dn-number-selector').length, 2, 'same cart identity cannot mount twice');
    const supplied = fixture(); supplied.document.readyState = 'complete';
    const operatorForm = supplied.document.createElement('form');
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/number-picker.js'), 'utf8'), supplied.sandbox);
    const operatorFields = [];
    for (let index = 0; index < 2; index++) {
        const itemTarget = supplied.document.createElement('div'); itemTarget.id = 'operator-target-' + index; operatorForm.appendChild(itemTarget);
        const existing = supplied.document.createElement('input'); existing.id = 'operator-field-' + index;
        existing.name = 'operator_selection[' + index + ']'; existing.value = number(index ? '7654321' : '1234567'); operatorForm.appendChild(existing);
        operatorFields.push(existing);
        supplied.sandbox.window.DNNumberPickerMount({...binding, context: contexts[index], targetId: itemTarget.id, fieldId: existing.id});
    }
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'explicit distinct operator fields are supported');
    assert.equal(operatorFields[0].value, number('1234567')); assert.equal(operatorFields[1].value, number('7654321'));
    const reusedTarget = supplied.document.createElement('div'); reusedTarget.id = 'reused-target'; operatorForm.appendChild(reusedTarget);
    supplied.sandbox.window.DNNumberPickerMount({...binding, context: 'cart-item-c', targetId: reusedTarget.id, fieldId: operatorFields[0].id});
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'another cart item cannot claim an existing binding');
    for (const existing of operatorFields) { existing.name = 'ambiguous_name'; }
    supplied.sandbox.window.DNNumberPickerMount({...binding, context: 'cart-item-c', targetId: reusedTarget.id, fieldId: operatorFields[1].id});
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'duplicate submission names are rejected');
    supplied.sandbox.window.DNNumberPickerMount({...binding, context: 'cart-item-c', targetId: reusedTarget.id, fieldId: 'missing-field'});
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'missing explicit fields are not silently replaced');
    const foreignForm = supplied.document.createElement('form');
    const foreignField = supplied.document.createElement('input'); foreignField.id = 'foreign-field'; foreignField.name = 'foreign_selection'; foreignForm.appendChild(foreignField);
    supplied.sandbox.window.DNNumberPickerMount({...binding, context: 'cart-item-c', targetId: reusedTarget.id, fieldId: foreignField.id});
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'a field in another form cannot be borrowed');
    const firstDuplicate = supplied.document.createElement('input'); firstDuplicate.id = 'duplicate-field'; firstDuplicate.name = 'first_duplicate'; operatorForm.appendChild(firstDuplicate);
    const secondDuplicate = supplied.document.createElement('input'); secondDuplicate.id = 'duplicate-field'; secondDuplicate.name = 'second_duplicate'; foreignForm.appendChild(secondDuplicate);
    supplied.sandbox.window.DNNumberPickerMount({...binding, context: 'cart-item-c', targetId: reusedTarget.id, fieldId: firstDuplicate.id});
    assert.equal(operatorForm.querySelectorAll('.dn-number-selector').length, 2, 'document-wide duplicate field IDs are rejected');
    console.log('Canonical search engine: OR, subscriber-only matching, colours, caching, pagination, selection, Stop/Clear, editing and request-count contracts passed (simulated DOM, not a browser).');
})().catch(error => {console.error(error); process.exitCode = 1;});