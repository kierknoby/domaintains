/* DOMAINTAINS: manually controlled Magrathea browsing and up to three searches. */
(function () {
    'use strict';
    if (window.DNNumberLivePicker) { return; }
    window.DNNumberLivePicker = function (cfg) {
        var territory = cfg.territory, region = cfg.region, prefix = cfg.prefix;
        var grid = cfg.numbers, field = cfg.field, selected = cfg.selected;
        var submit = cfg.submit, rangeField = cfg.range;
        var controlId = Array.from(String(cfg.context)).map(function (character) { return character.codePointAt(0).toString(16); }).join('-');
        var results = new Map(), shown = new Set(), browseOffers = new Map(), currentBatch = [];
        var requestVersion = 0, editVersion = 0, aborter = null;
        var busy = false, lastPrefix = '', mode = 'browse', activeSignature = '';
        var searchFinished = false;
        var activeTerms = [], positions = new Map();
        // Search results expand locally. Neither control contacts Magrathea.
        var searchVisibleLimit = 20;
        // Stable colours for Search 1, Search 2 and Search 3.
        var searchColours = [
            {fill: '#d8f3ff', edge: '#6db6d3'},
            {fill: '#cddbff', edge: '#718bd2'},
            {fill: '#ebd6ff', edge: '#a77ad5'}
        ];
        var key = 'dn-number-search:' + cfg.context;
        var stored = {};
        try { stored = JSON.parse(sessionStorage.getItem(key) || '{}') || {}; }
        catch (ignored) { stored = {}; }

        var wrap = document.createElement('div');
        wrap.className = 'dn-progressive-filter';
        wrap.style.cssText = 'margin:15px 0 12px;display:none';
        var label = document.createElement('label');
        label.textContent = 'Search for up to three number patterns';
        var controls = document.createElement('div');
        controls.className = 'dn-progressive-controls';
        var inputs = [0, 1, 2].map(function (index) {
            var input = document.createElement('input');
            input.type = 'search';
            input.className = 'form-control';
            // The field uses the same pastel colour as matching result tiles.
            input.style.backgroundColor = searchColours[index].fill;
            input.style.borderLeft = '3px solid ' + searchColours[index].edge;
            input.id = 'dn-number-search-' + index + '-' + String(cfg.context).replace(/[^a-zA-Z0-9_-]/g, '-');
            input.placeholder = 'Search ' + (index + 1);
            input.setAttribute('aria-label', 'Number pattern ' + (index + 1));
            input.setAttribute('inputmode', 'numeric');
            input.autocomplete = 'off';
            // Keep bad entries visible so they can be corrected. Do not
            // silently erase letters, punctuation or excess digits.
            input.maxLength = 32;
            controls.appendChild(input);
            return input;
        });
        label.htmlFor = inputs[0].id;
        var search = document.createElement('button');
        search.type = 'button';
        search.className = 'secondary dn-progressive-button';
        search.textContent = 'Search';
        var refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.id = 'dtRefreshAvailability-' + controlId;
        refresh.className = 'secondary dn-progressive-button dn-progressive-refresh';
        refresh.textContent = 'Refresh Availability List';
        refresh.setAttribute('aria-label', 'Reload the standard availability list');
        controls.appendChild(search);
        controls.appendChild(refresh);
        var info = document.createElement('div');
        info.className = 'text-muted small';
        info.setAttribute('aria-live', 'polite');
        info.style.cssText = 'margin:7px 0 2px;min-height:18px';
        wrap.appendChild(label);
        wrap.appendChild(controls);
        wrap.appendChild(info);
        grid.parentNode.insertBefore(wrap, grid);
        var pager = document.createElement('div');
        pager.className = 'dn-search-pager';
        pager.style.display = 'none';
        var seeMore = document.createElement('button');
        seeMore.type = 'button';
        seeMore.className = 'secondary dn-progressive-button';
        seeMore.textContent = 'See more';
        var seeLess = document.createElement('button');
        seeLess.type = 'button';
        seeLess.className = 'secondary dn-progressive-button';
        seeLess.textContent = 'See less';
        var findMore = document.createElement('button');
        findMore.type = 'button';
        findMore.className = 'secondary dn-progressive-button';
        findMore.textContent = 'Find more matches';
        // Unlike See more / See less, this explicitly triggers carrier lookups.
        pager.appendChild(seeMore);
        pager.appendChild(seeLess);
        pager.appendChild(findMore);
        grid.parentNode.insertBefore(pager, grid.nextSibling);
        if (!document.getElementById('dn-search-style')) {
            var css = document.createElement('style');
            css.id = 'dn-search-style';
            css.textContent =
                '.dn-progressive-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto auto;' +
                'align-items:stretch;gap:8px;min-width:0}' +
                '.dn-progressive-controls .form-control{min-width:0;width:100%;height:38px}' +
                '.dn-progressive-button{white-space:nowrap;min-height:38px;padding:0 12px;' +
                'border:1px solid #dce4e8;border-radius:5px;background:#f7fafb;' +
                'color:#215a5a;font-weight:600;cursor:pointer}' +
                '.dn-progressive-button:disabled{cursor:not-allowed;opacity:.7;' +
                'background:#e9edf0;color:#849099;border-color:#d2dce3}' +
                '.dn-progressive-button:focus-visible{outline:2px solid #357f86;outline-offset:2px}' +
                '.dn-search-pager{justify-content:center;gap:10px;margin:13px 0 10px}' +
                '@media(max-width:800px){.dn-progressive-controls{grid-template-columns:repeat(3,minmax(0,1fr))}' +
                '.dn-progressive-button{grid-column:span 1}}' +
                '@media(max-width:460px){.dn-progressive-controls{grid-template-columns:repeat(2,minmax(0,1fr))}' +
                '.dn-progressive-controls .form-control:first-child{grid-column:span 2}' +
                '.dn-progressive-button{font-size:12px;padding:0 8px}}';
            document.head.appendChild(css);
        }

        function digits(value) { return String(value || '').replace(/\D/g, ''); }
        function nat(value) { return cfg.nationalDisplay(value); }
        function draftTerms() {
            return inputs.map(function (input) { return input.value; }).filter(Boolean);
        }
        // Validate locally before any carrier request. Duplicate fields are
        // BOTH highlighted, even if one is currently being edited.
        function validateDraft() {
            var counts = new Map(), errors = [];
            inputs.forEach(function (input) {
                var term = input.value;
                if (/^\d{2,7}$/.test(term)) {
                    counts.set(term, (counts.get(term) || 0) + 1);
                }
            });
            inputs.forEach(function (input, index) {
                var term = input.value, error = '';
                if (term && !/^\d+$/.test(term)) {
                    error = 'Digits only';
                } else if (term && (term.length < 2 || term.length > 7)) {
                    error = 'Enter 2 to 7 digits';
                } else if (term && term.length > 11 - prefix.value.length) {
                    error = 'Pattern is too long for this area code';
                } else if (term && counts.get(term) > 1) {
                    error = 'Duplicate search pattern';
                }
                var invalid = !!error;
                errors.push(invalid);
                input.style.backgroundColor = invalid ? '#ffe5e8' : searchColours[index].fill;
                input.style.borderLeft = '3px solid ' + (invalid ? '#d77583' : searchColours[index].edge);
                input.style.borderColor = invalid ? '#d77583' : '';
                input.setAttribute('aria-invalid', invalid ? 'true' : 'false');
                input.title = error;
            });
            var terms = draftTerms();
            return {valid: !!prefix.value && !!terms.length && !errors.some(Boolean),
                terms: terms, hasErrors: errors.some(Boolean)};
        }
        // A number pattern is always matched against the digits AFTER the
        // selected area code. A term such as 020 is valid when the prefix is
        // 0204, but cannot match those leading area-code digits.
        function subscriberDigits(number) {
            var national = nat(number);
            return national.startsWith(prefix.value)
                ? national.slice(prefix.value.length) : '';
        }
        function save() {
            try {
                sessionStorage.setItem(key, JSON.stringify({
                    territory: territory.value, region: region.value, prefix: prefix.value
                }));
            } catch (ignored) { /* Storage is optional. Search terms are never stored. */ }
        }
        function setBusy(value) {
            busy = value;
            // Search -> Stop while running -> Clear when completed.
            // Any input edit resets the button to Search.
            var searching = value && mode === 'search';
            var clearable = !value && mode === 'search' && searchFinished;
            var draft = validateDraft();
            // Search is disabled for empty, duplicate or invalid entries.
            // Stop and Clear must remain enabled to restore normal browsing.
            search.disabled = (searching || clearable) ? false : (value || !draft.valid);
            search.textContent = searching ? 'Stop' : (clearable ? 'Clear' : 'Search');
            search.setAttribute('aria-label', searching ? 'Stop searching' :
                (clearable ? 'Clear the search and show available numbers' : 'Search availability'));
            // Availability browsing is restored by Clear, never by Refresh
            // while a search is entered, running, stopped or completed.
            refresh.disabled = value || mode !== 'browse' ||
                inputs.some(function (input) { return input.value.length > 0; });
        }
        function cancel() {
            requestVersion++;
            if (aborter) { aborter.abort(); aborter = null; }
            setBusy(false);
        }
        function stopSearch() {
            if (!busy || mode !== 'search') { return; }
            // Invalidate the running loop before aborting its pending fetch.
            // Already displayed results and completed positions are retained.
            searchFinished = true;
            cancel();
            render('Search stopped. Results found so far are shown.');
        }
        function clearSelection() {
            if (!field.value) { return; }
            field.value = '';
            field.dispatchEvent(new Event('input', {bubbles:true}));
            field.dispatchEvent(new Event('change', {bubbles:true}));
            selected.className = 'dt-selected';
            selected.textContent = '';
            if (submit) { submit.disabled = true; }
        }
        function clearSearch() {
            mode = 'browse';
            searchFinished = false;
            activeTerms = [];
            activeSignature = '';
            positions.clear();
            results.clear();
            searchVisibleLimit = 20;
        }
        function allVisible() {
            if (mode === 'draft') { return []; }
            if (mode !== 'search') { return currentBatch; }
            var records = Array.from(results.values());
            var favourites = records.filter(function (item) { return item.recommended; }).slice(0, 3);
            var favouriteNumbers = new Set(favourites.map(function (item) { return item.number; }));
            return favourites.concat(records.filter(function (item) { return !favouriteNumbers.has(item.number); }));
        }
        function matchingSlots(number) {
            if (mode !== 'search') { return []; }
            var subscriber = subscriberDigits(number);
            var matches = [];
            inputs.forEach(function (input, index) {
                var term = digits(input.value);
                if (term.length >= 2 && activeTerms.indexOf(term) !== -1 &&
                    subscriber.indexOf(term) !== -1) {
                    matches.push(index);
                }
            });
            return matches;
        }
        function applySearchHighlights(tile, radio, number) {
            var slots = matchingSlots(number);
            if (!slots.length) { return; }
            // Equal left-to-right sections: one full colour, two halves or
            // three thirds. Sharp boundaries keep every matching field visible.
            var stops = [];
            slots.forEach(function (index, section) {
                var start = (section * 100 / slots.length).toFixed(4) + '%';
                var end = ((section + 1) * 100 / slots.length).toFixed(4) + '%';
                var fill = searchColours[index].fill;
                stops.push(fill + ' ' + start, fill + ' ' + end);
            });
            tile.style.backgroundImage = 'linear-gradient(to right, ' + stops.join(', ') + ')';
            var names = slots.map(function (index) { return 'Search ' + (index + 1); });
            tile.title = 'Matches ' + names.join(', ');
            radio.setAttribute('aria-label', nat(number) + ', matches ' + names.join(', '));
        }
        function render(message) {
            var all = allVisible();
            var visible = mode === 'search' ? all.slice(0, searchVisibleLimit) : all;
            // Never hide a selected result when the customer collapses the list.
            if (mode === 'search' && field.value &&
                !visible.some(function (item) { return item.number === field.value; })) {
                var chosen = all.find(function (item) { return item.number === field.value; });
                if (chosen) { visible.push(chosen); }
            }
            var furtherPositions = mode === 'search' && searchFinished && !busy &&
                activeTerms.some(function (term) { return positions.get(term) >= 0; });
            pager.style.display = mode === 'search' &&
                (all.length > 20 || furtherPositions) ? 'flex' : 'none';
            seeMore.style.display = all.length > searchVisibleLimit ? '' : 'none';
            seeLess.style.display = searchVisibleLimit > 20 ? '' : 'none';
            findMore.style.display = furtherPositions ? '' : 'none';
            grid.textContent = '';
            var recommendations = 0;
            visible.forEach(function (item) {
                var recommend = item.recommended && recommendations < 3;
                if (recommend) { recommendations++; }
                var tile = document.createElement('label');
                tile.className = 'dt-number-option' + (recommend ? ' dt-recommended' : '');
                tile.setAttribute('data-number', item.number);
                var radio = document.createElement('input');
                radio.type = 'radio';
                radio.name = 'domaintainsNumberChoice-' + controlId;
                radio.value = item.number;
                radio.checked = field.value === item.number;
                var span = document.createElement('span');
                span.textContent = nat(item.number);
                applySearchHighlights(tile, radio, item.number);
                tile.appendChild(radio);
                tile.appendChild(span);
                tile.classList.toggle('is-selected', radio.checked);
                tile.addEventListener('click', function () {
                    field.value = item.number;
                    field.dispatchEvent(new Event('input', {bubbles:true}));
                    field.dispatchEvent(new Event('change', {bubbles:true}));
                    cfg.showSelected(item.number);
                    if (submit) { submit.disabled = false; }
                    cfg.clearStatus();
                });
                grid.appendChild(tile);
            });
            if (message !== undefined && message !== null) { info.textContent = message; }
            else if (busy) { info.textContent = mode === 'search' ? 'Searching availability…' : 'Updating availability…'; }
            else if (mode === 'search' && !visible.length) { info.textContent = 'No matching numbers found in the positions checked.'; }
            else if (mode !== 'search' && !visible.length) { info.textContent = 'No numbers available in this area code. Try Refresh Availability List.'; }
            else { info.textContent = ''; }
        }
        function validOffers(batch) {
            var found = new Set(), offered = [];
            batch.forEach(function (item) {
                if (!item || typeof item.number !== 'string') { return; }
                var number = digits(item.number);
                var national = nat(number);
                if (!/^0[12]\d{9}$/.test(national) || !national.startsWith(prefix.value)
                    || found.has(number)) { return; }
                found.add(number);
                offered.push({number: number, recommended: item.recommended === true});
            });
            return offered;
        }
        function receiveBrowse(batch) {
            var offered = validOffers(batch);
            // Every carrier offer obtained during browsing remains searchable
            // for this area-code session, not just the latest 20 tiles.
            offered.forEach(function (item) {
                var existing = browseOffers.get(item.number);
                if (!existing) { browseOffers.set(item.number, item); }
                else if (item.recommended) { existing.recommended = true; }
            });
            if (!offered.length) {
                return currentBatch.length ? 'No further numbers found this time.' :
                    'No numbers available this time. Try Refresh Availability List.';
            }
            var recommended = offered.filter(function (item) { return item.recommended; }).slice(0, 3);
            var remainder = offered.filter(function (item) { return !item.recommended; });
            var next = recommended.concat(
                remainder.filter(function (item) { return !shown.has(item.number); }),
                remainder.filter(function (item) { return shown.has(item.number); })
            );
            if (currentBatch.length && !next.some(function (item) { return !shown.has(item.number); })) {
                return 'No further numbers found this time.';
            }
            currentBatch = next; // The complete carrier batch, up to 20.
            next.forEach(function (item) { shown.add(item.number); });
            return '';
        }
        function receiveSearch(batch) {
            var added = 0;
            validOffers(batch).forEach(function (item) {
                var subscriber = subscriberDigits(item.number);
                // Carrier responses can contain numbers outside the requested
                // masked position. Check every returned number against ALL
                // active OR terms, strictly after the selected area code.
                if (!activeTerms.some(function (term) {
                    return subscriber.indexOf(term) !== -1;
                })) { return; }
                if (!results.has(item.number)) { results.set(item.number, item); added++; }
                else if (item.recommended) { results.get(item.number).recommended = true; }
            });
            return added;
        }
        async function request(action, signal, term, position) {
            var body = new URLSearchParams();
            body.set('prefix', prefix.value);
            body.set('nonce', cfg.nonce);
            body.set('action', action);
            body.set('context', cfg.context);
            // Service attribution is verified against the logged-in operator
            // account on the server. This identifier is not trusted as proof.
            var serviceMatch = /^update-(\d+)$/.exec(String(cfg.context));
            if (serviceMatch) { body.set('service_id', serviceMatch[1]); }
            if (action === 'search') {
                body.set('search', term);
                body.set('position', String(position));
            }
            var response = await fetch(cfg.availabilityUrl || '/domaintains/availability.php', {
                method: 'POST', credentials: 'same-origin', signal: signal,
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            });
            if (response.status === 403) {
                // 403 also covers forbidden origins and proxy/WAF denials.
                // Only an actual nonce failure should be called session expiry.
                var denial = null;
                try { denial = await response.json(); } catch (ignored) {}
                throw new Error(denial && denial.error === 'Selection session expired'
                    ? 'SESSION_EXPIRED' : 'REQUEST_FORBIDDEN');
            }
            if (response.status === 429) { throw new Error('SESSION_LIMIT'); }
            if (response.status === 503) { throw new Error('LOCAL_BUSY'); }
            var data = await response.json();
            if (!response.ok || !data || data.ok !== true || !Array.isArray(data.numbers)) {
                throw new Error('AVAILABILITY_ERROR');
            }
            return data.numbers;
        }
        function failureMessage(error) {
            if (error.message === 'SESSION_EXPIRED') { return 'Number search authorisation is no longer valid. Reload the page to continue.'; }
            if (error.message === 'REQUEST_FORBIDDEN') { return 'Availability request was blocked. Reload the page if this continues.'; }
            if (error.message === 'SESSION_LIMIT') { return 'Too many requests. Please try again shortly.'; }
            if (error.message === 'LOCAL_BUSY') { return 'Availability is temporarily busy. Try again shortly.'; }
            if (error.name === 'AbortError') { return 'Availability timed out. Please try again.'; }
            return 'Availability could not be updated. Please try again.';
        }
        async function loadBrowse(action) {
            if (!prefix.value || busy) { return; }
            var generation = requestVersion, edit = editVersion;
            clearSearch();
            setBusy(true);
            wrap.style.display = 'block';
            if (rangeField) { rangeField.value = prefix.value; }
            save();
            var controller = new AbortController();
            aborter = controller;
            var timer = setTimeout(function () { controller.abort(); }, 35000);
            render();
            try {
                var batch = await request(action, controller.signal);
                if (generation !== requestVersion || edit !== editVersion) { return; }
                var message = receiveBrowse(batch);
                cfg.clearStatus();
                render(message);
            } catch (error) {
                if (generation === requestVersion && edit === editVersion) { render(failureMessage(error)); }
            } finally {
                clearTimeout(timer);
                if (generation === requestVersion) {
                    aborter = null;
                    setBusy(false);
                    if (info.textContent === 'Updating availability…') { render(); }
                }
            }
        }
        function initialisePositions(terms) {
            positions.clear();
            terms.forEach(function (term) {
                // A pattern can occur anywhere after the selected area code.
                // A masked Magrathea lookup covers only ONE such position.
                // The endpoint permits position 0..min(remaining, 5).
                positions.set(term, Math.min(5, 11 - prefix.value.length - term.length));
            });
        }
        function seedSearchFromBrowse(terms) {
            // Check *all* batches the customer has already seen before
            // asking Magrathea for anything. Multiple terms mean OR.
            browseOffers.forEach(function (item) {
                var subscriber = subscriberDigits(item.number);
                if (terms.some(function (term) { return subscriber.indexOf(term) !== -1; })) {
                    var existing = results.get(item.number);
                    if (!existing) { results.set(item.number, item); }
                    else if (item.recommended) { existing.recommended = true; }
                }
            });
        }
        function clearSearchAndRestoreList() {
            cancel();
            inputs.forEach(function (input) { input.value = ''; });
            editVersion++;
            clearSearch();
            setBusy(false);
            render(currentBatch.length ? '' : 'Select Refresh Availability List to load numbers.');
        }
        async function loadSearch() {
            if (!prefix.value || busy) { return; }
            var checked = validateDraft();
            if (!checked.valid) { return; }
            var terms = checked.terms;
            var signature = prefix.value + ':' + terms.join(',');
            var continuing = signature === activeSignature;
            if (!continuing) {
                clearSearch();
                activeSignature = signature;
                activeTerms = terms;
                initialisePositions(terms);
                seedSearchFromBrowse(terms);
            }
            mode = 'search';
            searchFinished = false;
            // Display matching numbers from previous availability lists NOW.
            // Carrier lookups can be slower but must never hide a known match.
            render('');
            var remainingPositions = activeTerms.some(function (term) { return positions.get(term) >= 0; });
            if (!remainingPositions) {
                searchFinished = true;
                setBusy(false);
                render(results.size ? 'No further numbers found in the positions checked.' :
                    'No matching numbers returned in the positions checked. Try different digits.');
                return;
            }
            // Existing matches are shown immediately. Do not skip a requested
            // carrier search merely because a term already has one match:
            // the customer may be looking for additional numbers.
            // When 20 matches are already cached, offer explicit Find more
            // instead of making unsolicited carrier calls.
            if (!continuing && results.size >= 20) {
                searchFinished = true;
                setBusy(false);
                render('Showing matches already found. Select Find more matches to search further.');
                return;
            }
            var target = continuing ? results.size + 20 : 20;
            var generation = requestVersion, edit = editVersion;
            var added = 0, failure = null, calls = 0, cursor = 0;
            var attemptedTerms = new Set();
            setBusy(true);
            wrap.style.display = 'block';
            if (rangeField) { rangeField.value = prefix.value; }
            save();
            // One explicit click checks at most six masked positions in total,
            // in round-robin order across the OR patterns. There is NO
            // automatic background polling and no retry on failure.
            // The existing server-wide five-second carrier spacing still applies.
            try {
                while (calls < 6) {
                    if (generation !== requestVersion || edit !== editVersion) { break; }
                    var pending = activeTerms.filter(function (term) { return positions.get(term) >= 0; });
                    if (!pending.length) { break; }
                    // Give each OR term an opportunity to find more numbers.
                    // A single cached match must not starve the other fields.
                    if (results.size >= target && pending.every(function (term) {
                        return attemptedTerms.has(term);
                    })) { break; }
                    var term = pending[cursor % pending.length];
                    cursor++;
                    var position = positions.get(term);
                    var controller = new AbortController();
                    aborter = controller;
                    var timer = setTimeout(function () { controller.abort(); }, 35000);
                    render();
                    try {
                        var batch = await request('search', controller.signal, term, position);
                        if (generation !== requestVersion || edit !== editVersion) { break; }
                        positions.set(term, position - 1);
                        calls++;
                        attemptedTerms.add(term);
                        added += receiveSearch(batch);
                        cfg.clearStatus();
                        render();
                    } catch (error) {
                        if (generation === requestVersion && edit === editVersion) { failure = failureMessage(error); }
                        break;
                    } finally {
                        clearTimeout(timer);
                        if (aborter === controller) { aborter = null; }
                    }
                }
            } finally {
                if (generation === requestVersion) {
                    searchFinished = true;
                    setBusy(false);
                    if (edit !== editVersion) {
                        render('Search changed. Press Search for the new patterns.');
                    } else if (failure) {
                        render(failure);
                    } else {
                        var more = activeTerms.some(function (term) { return positions.get(term) >= 0; });
                        if (more) {
                            render(results.size
                                ? 'Showing matches found so far. Select Find more matches to continue.'
                                : 'No matches in the positions checked. Select Find more matches to continue.');
                        } else if (!results.size) {
                            render('No matching numbers returned in the positions checked. Try different digits.');
                        } else if (!added && continuing) {
                            render('No further numbers found this time.');
                        } else { render(''); }
                    }
                }
            }
        }
        function loadBase() {
            cancel();
            var changed = prefix.value !== lastPrefix;
            if (changed) {
                clearSearch(); shown.clear(); browseOffers.clear(); currentBatch = []; clearSelection();
                inputs.forEach(function (input) { input.value = ''; });
                editVersion++;
            }
            lastPrefix = prefix.value;
            if (!prefix.value) {
                wrap.style.display = 'none'; grid.textContent = '';
                pager.style.display = 'none';
                if (rangeField) { rangeField.value = ''; }
                return;
            }
            if (!changed && currentBatch.length) { wrap.style.display = 'block'; setBusy(false); render(); return; }
            setBusy(false);
            loadBrowse('initial');
        }
        inputs.forEach(function (input) {
            input.addEventListener('input', function () {
                // Invalid characters stay visible and are marked light red.
                // No carrier request is made until every field is valid.
                editVersion++;
                // Editing invalidates the old search results. Cancel any
                // outstanding client request; never launch a carrier request
                // from an input event, including the browser's clear button.
                if (busy) { cancel(); }
                clearSearch();
                setBusy(false);
                if (draftTerms().length === 0) {
                    // Return to the last ordinary availability list immediately.
                    // No request is needed to redisplay already fetched numbers.
                    render(currentBatch.length ? '' :
                        'Select Refresh Availability List to load numbers.');
                } else {
                    mode = 'draft';
                    render(validateDraft().hasErrors ? '' :
                        'Press Search to find numbers matching your patterns.');
                }
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (!busy) { loadSearch(); }
                }
            });
        });
        seeMore.addEventListener('click', function (event) {
            event.preventDefault();
            if (mode !== 'search') { return; }
            searchVisibleLimit += 20;
            render(info.textContent);
        });
        seeLess.addEventListener('click', function (event) {
            event.preventDefault();
            if (mode !== 'search') { return; }
            searchVisibleLimit = 20;
            render(info.textContent);
        });
        findMore.addEventListener('click', function (event) {
            event.preventDefault();
            if (!busy && mode === 'search' && searchFinished) { loadSearch(); }
        });
        search.addEventListener('click', function (event) {
            event.preventDefault();
            if (busy && mode === 'search') { stopSearch(); }
            else if (!busy && mode === 'search' && searchFinished) {
                clearSearchAndRestoreList();
            } else if (!busy) { loadSearch(); }
        });
        refresh.addEventListener('click', function (event) {
            event.preventDefault();
            // Reload returns to ordinary availability browsing and makes
            // exactly ONE broad carrier request, never a patterned search.
            if (!refresh.disabled && !busy && prefix.value) {
                inputs.forEach(function (input) { input.value = ''; });
                editVersion++;
                loadBrowse('refresh');
            }
        });
        function resetLocation() {
            cancel(); clearSearch(); shown.clear(); browseOffers.clear(); currentBatch = []; lastPrefix = '';
            inputs.forEach(function (input) { input.value = ''; });
            editVersion++;
            clearSelection(); wrap.style.display = 'none'; grid.textContent = '';
            pager.style.display = 'none';
            setBusy(false);
            if (rangeField) { rangeField.value = ''; }
            save();
        }
        territory.addEventListener('change', resetLocation);
        region.addEventListener('change', resetLocation);
        prefix.addEventListener('change', function () { save(); loadBase(); });
        if (stored.territory && cfg.regions[stored.territory]
            && !(cfg.context.indexOf('cart-') === 0 && field.value)) {
            territory.value = stored.territory;
            territory.dispatchEvent(new Event('change', {bubbles: true}));
            if (stored.region && cfg.regions[stored.territory][stored.region]) {
                region.value = stored.region;
                region.dispatchEvent(new Event('change', {bubbles: true}));
                if (stored.prefix && prefix.querySelector('option[value="' + stored.prefix + '"]')) {
                    prefix.value = stored.prefix;
                    prefix.dispatchEvent(new Event('change', {bubbles: true}));
                }
            }
        }
    };
})();
