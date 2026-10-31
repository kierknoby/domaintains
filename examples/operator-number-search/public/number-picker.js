(function () {
    'use strict';
    if (window.DNNumberPickerMount) { return; }
    window.DNNumberPickerMount = function (cfg) {
        function init() {
            var target = document.getElementById(cfg.targetId);
            var anchor = cfg.anchorId ? document.getElementById(cfg.anchorId) : null;
            var field = cfg.fieldId ? document.getElementById(cfg.fieldId) : null;
            var form = (anchor || field || target) && (anchor || field || target).closest('form');
            var range = cfg.rangeId ? document.getElementById(cfg.rangeId) : null;
            var submit = cfg.submitId ? document.getElementById(cfg.submitId) : null;
            var id = Array.from(String(cfg.context)).map(function (character) { return character.codePointAt(0).toString(16); }).join('-');
            if (!target || !form || target.getAttribute('data-dn-mounted') || document.getElementById('dn-picker-' + id) ||
                !window.DNNumberLivePicker || (cfg.anchorId && !anchor) || (cfg.rangeId && (!range || !submit))) { return; }
            if (cfg.mode === 'cart') {
                if (target.closest('form') !== form || (anchor && anchor.closest('form') !== form)) { return; }
                if (typeof cfg.context !== 'string' || !cfg.context.startsWith('cart-') || cfg.context.length <= 5) { return; }
                if (!/^[a-zA-Z_][a-zA-Z0-9_\[\].-]*$/.test(cfg.selectionFieldName || '')) { return; }
                if (Array.from(form.querySelectorAll('input')).some(function (input) { return input.getAttribute('data-dn-context') === cfg.context; })) { return; }
                var fieldName = cfg.selectionFieldName + '[' + id + ']';
                if (cfg.fieldId) {
                    var explicit = Array.from(form.querySelectorAll('input')).filter(function (input) { return input.id === cfg.fieldId; });
                    var documentFields = Array.from(document.querySelectorAll('input')).filter(function (input) { return input.id === cfg.fieldId; });
                    if (!field || explicit.length !== 1 || documentFields.length !== 1 || explicit[0] !== field || !field.name) { return; }
                    fieldName = field.name;
                }
                var candidates = Array.from(form.querySelectorAll('input')).filter(function (input) { return input.name === fieldName; });
                if (candidates.length > 1 || (field && candidates[0] !== field)) { return; }
                field = field || candidates[0];
                if (field && field.getAttribute('data-dn-context')) { return; }
                if (!field) {
                    field = document.createElement('input');
                    field.type = 'hidden';
                    field.name = fieldName;
                    form.appendChild(field);
                }
                field.setAttribute('data-dn-context', cfg.context);
                field.type = 'hidden';
                var container = cfg.hideFieldContainerId ? document.getElementById(cfg.hideFieldContainerId) : null;
                if (container && container.closest('form') === form) { container.style.display = 'none'; }
            }
            if (!field || !window.DNNumberLivePicker) { return; }
            target.setAttribute('data-dn-mounted', 'true');
            var picker = document.createElement('div');
            picker.id = 'dn-picker-' + id;
            picker.className = 'dn-number-selector';
            var location = document.createElement('div');
            location.className = 'dn-number-location';
            function selector(name, placeholder) {
                var group = document.createElement('div');
                var label = document.createElement('label');
                var element = document.createElement('select');
                element.className = 'form-control';
                element.id = 'dn-' + name.toLowerCase().replace(/\s+/g, '-') + '-' + id;
                label.htmlFor = element.id;
                label.textContent = name;
                group.appendChild(label);
                group.appendChild(element);
                location.appendChild(group);
                resetOptions(element, placeholder);
                return element;
            }
            function resetOptions(element, placeholder, entries) {
                element.textContent = '';
                var option = document.createElement('option');
                option.value = ''; option.textContent = placeholder;
                element.appendChild(option);
                Object.keys(entries || {}).forEach(function (value) {
                    var item = document.createElement('option');
                    item.value = value; item.textContent = entries[value];
                    element.appendChild(item);
                });
                element.disabled = !entries;
            }
            var territory = selector('Territory', 'Choose a territory');
            var region = selector('Region', 'Choose a region');
            var prefix = selector('Area Code', 'Choose an area code');
            var territories = {};
            Object.keys(cfg.regions).forEach(function (name) { territories[name] = name; });
            resetOptions(territory, 'Choose a territory', territories);
            var status = document.createElement('div');
            status.className = 'alert dt-status'; status.setAttribute('role', 'alert');
            var selected = document.createElement('div'); selected.className = 'dt-selected';
            var numbers = document.createElement('div'); numbers.className = 'dt-number-grid';
            picker.appendChild(location); picker.appendChild(status); picker.appendChild(selected); picker.appendChild(numbers);
            if (cfg.mode === 'cart') { target.parentNode.insertBefore(picker, target.nextSibling); }
            else { target.appendChild(picker); field.value = ''; submit.disabled = true; }
            function national(number) {
                var digits = String(number || '').replace(/\D/g, '');
                return /^44[12]\d{9}$/.test(digits) ? '0' + digits.slice(2) : digits;
            }
            function clearStatus() { status.className = 'alert dt-status'; status.textContent = ''; }
            function setStatus(message) { status.className = 'alert dt-status show alert-danger'; status.textContent = message; }
            function showSelected(number) {
                selected.textContent = 'Selected number: ' + national(number);
                selected.className = 'dt-selected show';
                numbers.querySelectorAll('.dt-number-option').forEach(function (node) {
                    var chosen = node.getAttribute('data-number') === number;
                    node.classList.toggle('is-selected', chosen);
                    node.querySelector('input').checked = chosen;
                });
            }
            territory.addEventListener('change', function () {
                clearStatus();
                var regions = {};
                Object.keys(cfg.regions[territory.value] || {}).forEach(function (name) { regions[name] = name; });
                resetOptions(region, 'Choose a region', territory.value ? regions : null);
                resetOptions(prefix, 'Choose an area code');
            });
            region.addEventListener('change', function () {
                clearStatus();
                var areas = cfg.regions[territory.value] || {};
                resetOptions(prefix, 'Choose an area code', areas[region.value]);
            });
            window.DNNumberLivePicker({territory: territory, region: region, prefix: prefix, numbers: numbers,
                field: field, selected: selected, submit: submit, range: range, context: cfg.context,
                regions: cfg.regions, nonce: cfg.nonce, availabilityUrl: cfg.availabilityUrl,
                nationalDisplay: national, showSelected: showSelected, clearStatus: clearStatus, setStatus: setStatus});
            if (field.value) { showSelected(field.value); }
            form.addEventListener('submit', function (event) {
                if (!field.value.trim() || (range && !range.value.trim())) {
                    event.preventDefault();
                    setStatus('Please choose an available telephone number before continuing.');
                    picker.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            }, true);
            if (!document.getElementById('dn-number-picker-style')) {
                var css = document.createElement('style'); css.id = 'dn-number-picker-style';
                css.textContent = '.dn-number-selector{margin-top:22px;min-width:0}' +
                    '.dn-number-location{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}' +
                    '.dn-number-location select{width:100%;min-width:0}.dn-number-location label{display:block}' +
                    '.dn-number-selector .dt-number-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}' +
                    '.dn-number-selector .dt-number-option{display:flex;align-items:center;gap:9px;margin:0;padding:11px 13px;' +
                    'border:1px solid #d7dce1;border-radius:6px;background:#fff;cursor:pointer;font-weight:500;min-width:0;flex-wrap:wrap}' +
                    '.dn-number-selector .dt-number-option span{overflow-wrap:anywhere}.dn-number-selector .dt-number-option:hover{background:#f7f9fb}' +
                    '.dn-number-selector .dt-number-option.is-selected{border-color:#30beff;box-shadow:0 0 0 1px #30beff;background:#f4fbff}' +
                    '.dn-number-selector .dt-recommended::after{content:"Recommended";margin-left:auto;font-size:11px;font-weight:600;color:#63707c}' +
                    '.dn-number-selector .dt-status,.dn-number-selector .dt-selected{display:none;margin-top:12px}' +
                    '.dn-number-selector .show{display:block}.dn-number-selector .dt-selected{padding:10px 12px;border-radius:6px;background:#f5f7f9}' +
                    '@media(max-width:767px){.dn-number-location{grid-template-columns:1fr}.dn-number-selector .dt-number-grid{grid-template-columns:1fr}}';
                document.head.appendChild(css);
            }
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
        else { init(); }
    };
})();