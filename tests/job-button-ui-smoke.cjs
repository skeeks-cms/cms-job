// Dependency-free UI state regression; no site requests or queue mutations.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../src/assets/src/job-button.js'), 'utf8');

function element() {
    return {textContent: '', hidden: false, listeners: {},
        addEventListener(name, fn) { this.listeners[name] = fn; },
        setAttribute() {}, removeAttribute() {}};
}
async function render(run, failed = false) {
    const button = element(), status = element(), result = element();
    const actions = [], requests = [];
    const root = {dataset: {label: 'Запустить', startUrl: '/start', statusUrl: '/status'},
        isConnected: true, querySelector(selector) {
            return {'[data-sx-job-start]': button, '[data-sx-job-status]': status,
                '[data-sx-job-result]': result}[selector];
        }};
    const context = {window: {}, location: {href: 'http://localhost:8080/agents', origin: 'http://localhost:8080'},
        document: {readyState: 'complete', documentElement: {}, querySelectorAll: () => [root]},
        URL, URLSearchParams, AbortController, setTimeout() { return 1; }, clearTimeout() {},
        MutationObserver: class { observe() {} },
        yii: {getCsrfParam: () => '_csrf', getCsrfToken: () => 'fixture'},
        sx: {classes: {backend: {widgets: {Action: function (config) { this.go = () => actions.push(config); }}}}},
        fetch: async (url, options) => { requests.push({url, options});
            if (failed) throw new Error('offline');
            return {ok: true, json: async () => ({success: true, run})};
        }};
    vm.runInNewContext(source, context);
    await new Promise(setImmediate);
    return {button, status, result, actions, requests};
}
const base = {label: 'В очереди', finished: false, percent: null, message: '',
    url: '/view?pk=1', windowUrl: '/view?pk=1&_sxb%5Bel%5D=1'};
(async () => {
    let ui = await render(base);
    assert.equal(ui.button.textContent, 'В очереди');
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.status.hidden, true);
    assert.equal(ui.status.textContent, '');
    let prevented = false;
    ui.result.listeners.click({button: 0, preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(ui.actions[0].isOpenNewWindow, true);
    assert.match(ui.actions[0].url, /_sxb/);
    ui.result.listeners.click({button: 0, ctrlKey: true});
    assert.equal(ui.actions.length, 1, 'modified click preserves ordinary href');

    ui = await render({...base, label: 'Выполняется', percent: 35, message: 'Запись файла'});
    assert.equal(ui.status.textContent, '35% · Запись файла');
    assert.equal(ui.status.hidden, false);
    ui = await render({...base, message: 'В очереди'});
    assert.equal(ui.status.hidden, true, 'same message does not duplicate the state');
    ui = await render({...base, finished: true, label: 'Ошибка'});
    assert.equal(ui.button.textContent, 'Запустить');
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.status.textContent, 'Ошибка', 'terminal outcome remains visible');
    ui = await render(null);
    assert.equal(ui.status.hidden, true);
    assert.equal(ui.result.hidden, true);
    assert.equal(ui.button.disabled, false);
    ui = await render(base, true);
    assert.equal(ui.status.hidden, false);
    assert.equal(ui.button.textContent, 'Проверить статус');
    ui.button.listeners.click();
    await new Promise(setImmediate);
    assert.equal(ui.requests[1].options.method, undefined, 'unknown state retries GET, not POST');
    ui = await render({...base, windowUrl: 'https://other.invalid/view'});
    assert.equal(ui.button.textContent, 'Проверить статус', 'foreign window URL rejected');
    console.log('Job button UI: 21 assertions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
