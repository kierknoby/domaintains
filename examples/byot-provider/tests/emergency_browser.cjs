'use strict';

const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '../public');
const template = fs.readFileSync(path.join(root, 'emergency.php'), 'utf8');
const html = template.slice(template.indexOf('<!doctype html>')).replace('<?= htmlspecialchars($csrf, ENT_QUOTES, \'UTF-8\') ?>', 'c'.repeat(64));
const registration = {title: '', forename: '', honours: '', name: 'Synthetic Company', business_suffix: 'Custom Suffix', address_line_1: '1 Example Street', address_line_2: '', locality: 'Example', region: '', postcode: 'EX1 1AA', country: 'GB'};

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const viewport of [{width: 1280, height: 900}, {width: 390, height: 844}]) {
            const page = await browser.newPage({viewport});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            let failRetrieval = false;
            let retrievalType = 'business';
            let status = 'awaiting_acceptance';
            let pending;
            let submissions = 0;
            let retrievals = 0;
            await page.route('https://emergency.example/**', async route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/emergency.php') return route.fulfill({contentType: 'text/html', body: html});
                if (url.pathname.endsWith('.js') || url.pathname.endsWith('.css')) return route.fulfill({contentType: url.pathname.endsWith('.js') ? 'application/javascript' : 'text/css', body: fs.readFileSync(path.join(root, path.basename(url.pathname)))});
                const body = route.request().postDataJSON();
                let response;
                if (body.action === 'contexts') response = {ok: true, contexts: [{service: 'service-a', pbx: 'pbx-a'}]};
                if (body.action === 'list') response = {ok: true, numbers: [{id: 'a'.repeat(64), label: '12025550101'}]};
                if (body.action === 'retrieve') {
                    retrievals++;
                    await new Promise(resolve => { pending = resolve; });
                    if (failRetrieval) return route.fulfill({status: 502, json: {ok: false, message: 'Provider unavailable. Retry.'}});
                    response = {ok: true, registration: {...registration, ...(retrievalType === 'individual' ? {honours: 'OBE', business_suffix: ''} : {})}, type: retrievalType, intent: 'synthetic-intent', can_change_type: true, status: 'registered', retry_after_by_operation: {retrieve: retrievals === 1 ? 60 : 0, submit: 0, status: 0}};
                }
                if (body.action === 'submit') {
                    submissions++;
                    assert.equal(body.confirmed, true);
                    assert.equal(body.registration.business_suffix, 'Editable Custom Suffix');
                    response = {ok: true, status: 'submitted', retry_after_by_operation: {retrieve: 0, submit: 60, status: 0}};
                }
                if (body.action === 'status') response = {ok: true, status};
                return route.fulfill({json: response});
            });
            await page.goto('https://emergency.example/emergency.php');
            await page.waitForFunction(() => document.querySelector('#service').options.length === 1 && !document.querySelector('#service').disabled);
            await page.getByRole('button', {name: 'Load authorised numbers'}).click();
            await page.getByText('Retrieving emergency information...').waitFor();
            assert.equal(await page.locator('#registration').isVisible(), false, 'address fields hidden while loading');
            pending();
            await page.locator('#registration').waitFor({state: 'visible'});
            assert.equal(await page.locator('#retrieve').isDisabled(), true, 'retrieval cooldown enforced independently');
            assert.equal(await page.locator('#check-status').isEnabled(), true, 'retrieval cooldown does not block status');
            assert.equal(await page.locator('[data-individual]').first().isVisible(), false);
            assert.equal(await page.locator('[data-business]').isVisible(), true);
            assert.equal(await page.locator('#submit').isDisabled(), true);
            await page.locator('[name="business_suffix"]').fill('Editable Custom Suffix');
            await page.locator('#confirmed').check();
            assert.equal(await page.locator('#submit').isEnabled(), true);
            await page.locator('#submit').click();
            await page.locator('#registration-status').filter({hasText: /^Submitted$/}).waitFor();
            assert.equal(submissions, 1);
            assert.equal(await page.locator('#registration').isVisible(), false);
            failRetrieval = true;
            await page.locator('#retrieve').click();
            await page.getByText('Retrieving emergency information...').waitFor();
            pending();
            await page.getByText('Provider unavailable. Retry.').waitFor();
            assert.equal(await page.locator('#registration').isVisible(), false, 'failed retrieval never shows stale address');
            failRetrieval = false;
            retrievalType = 'individual';
            await page.locator('#retrieve').click();
            await page.getByText('Retrieving emergency information...').waitFor();
            pending();
            await page.locator('#registration').waitFor({state: 'visible'});
            assert.equal(await page.locator('[name="honours"]').inputValue(), 'OBE');
            assert.equal(await page.locator('[data-business]').isVisible(), false);
            await page.locator('[name="type"][value="business"]').check();
            assert.equal(await page.locator('[name="honours"]').inputValue(), '');
            assert.equal(await page.locator('[name="name"]').inputValue(), '');
            assert.equal(await page.locator('#confirmed').isChecked(), false);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true, 'no horizontal overflow');
            await page.screenshot({path: `/tmp/domaintains-emergency-${viewport.width}.png`, fullPage: true});
            await page.locator('#check-status').click();
            await page.locator('#registration-status').filter({hasText: /^Awaiting acceptance$/}).waitFor();
            assert.equal(errors.length, 0, errors.join('\n'));
            await page.close();
        }
        console.log('Emergency browser contracts passed at desktop and mobile sizes.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });