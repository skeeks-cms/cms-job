// Dependency-free UI state regression; no site requests or queue mutations.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../src/assets/src/job-button.js'), 'utf8');

function element() {
    return {textContent: '', hidden: false, listeners: {}, attributes: {},
        addEventListener(name, fn) { this.listeners[name] = fn; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; }};
}
async function render(run, failed = false) {
    const button = element(), status = element(), result = element();
    const actions = [], requests = [], timers = [];
    const root = {dataset: {label: 'Запустить', startUrl: '/start', statusUrl: '/status'},
        isConnected: true, dispatchEvent() {}, querySelector(selector) {
            return {'[data-sx-job-start]': button, '[data-sx-job-status]': status,
                '[data-sx-job-result]': result}[selector];
        }};
    const context = {window: {}, location: {href: 'http://localhost:8080/agents', origin: 'http://localhost:8080'},
        document: {readyState: 'complete', documentElement: {}, querySelectorAll: () => [root]},
        CustomEvent: class { constructor(name, options) { this.type = name; Object.assign(this, options); } },
        URL, URLSearchParams, AbortController, setTimeout(fn, delay) { timers.push({fn, delay}); return timers.length; }, clearTimeout() {},
        MutationObserver: class { observe() {} },
        yii: {getCsrfParam: () => '_csrf', getCsrfToken: () => 'fixture'},
        sx: {classes: {backend: {widgets: {Action: function (config) { this.go = () => actions.push(config); }}}}},
        fetch: async (url, options) => { requests.push({url, options});
            if (failed) throw new Error('offline');
            return {ok: true, json: async () => ({success: true, run})};
        }};
    vm.runInNewContext(source, context);
    await new Promise(setImmediate);
    return {button, status, result, actions, requests, timers,
        setRun(next) { run = next; }, setFailed(next) { failed = next; }};
}
const base = {status: 'queued', label: 'В очереди', finished: false, percent: null, message: '',
    url: '/view?pk=1', windowUrl: '/view?pk=1&_sxb%5Bel%5D=1'};
(async () => {
    let ui = await render(base);
    assert.equal(ui.button.textContent, 'В очереди');
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.button.attributes['aria-busy'], undefined, 'queued state has no running spinner');
    ui.timers.find(t => t.delay === 2500).fn();
    assert.equal(ui.button.attributes['aria-busy'], undefined, 'queued poll does not flash spinner');
    await new Promise(setImmediate);
    assert.equal(ui.status.hidden, true);
    assert.equal(ui.status.textContent, '');
    let prevented = false;
    ui.result.listeners.click({button: 0, preventDefault() { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(ui.actions[0].isOpenNewWindow, true);
    assert.match(ui.actions[0].url, /_sxb/);
    ui.result.listeners.click({button: 0, ctrlKey: true});
    assert.equal(ui.actions.length, 1, 'modified click preserves ordinary href');

    ui = await render({...base, busy: true});
    assert.equal(ui.button.attributes['aria-busy'], 'true', 'remote operation stays busy while observer is queued');
    for (const status of ['running', 'queued', 'queued']) {
        ui.setRun({...base, status, busy: true});
        ui.timers.filter(t => t.delay === 2500).at(-1).fn();
        await new Promise(setImmediate);
        assert.equal(ui.button.attributes['aria-busy'], 'true', 'remote spinner persists across ' + status);
    }
    ui.setRun({...base, busy: true, status: 'succeeded_with_warnings', finished: true});
    ui.timers.filter(t => t.delay === 2500).at(-1).fn();
    await new Promise(setImmediate);
    assert.equal(ui.button.attributes['aria-busy'], undefined, 'terminal result clears even stale busy flag');

    ui = await render({...base, status: 'running', label: 'Выполняется', percent: 35, message: 'Запись файла'});
    assert.equal(ui.button.attributes['aria-busy'], 'true', 'running spinner persists between polls');
    ui.timers.find(t => t.delay === 2500).fn();
    assert.equal(ui.button.attributes['aria-busy'], 'true', 'running spinner persists during poll');
    await new Promise(setImmediate);
    assert.equal(ui.button.attributes['aria-busy'], 'true', 'running spinner persists after poll');
    assert.equal(ui.status.textContent, '35% · Запись файла');
    assert.equal(ui.status.hidden, false);
    ui.setRun({...base, status: 'succeeded', finished: true, label: 'Выполнено'});
    ui.timers.filter(t => t.delay === 2500).at(-1).fn();
    await new Promise(setImmediate);
    assert.equal(ui.button.attributes['aria-busy'], undefined, 'completion clears spinner');
    assert.equal(ui.button.disabled, false, 'completion enables start');
    ui = await render({...base, status: 'running'});
    ui.setFailed(true);
    ui.timers.find(t => t.delay === 2500).fn();
    await new Promise(setImmediate);
    assert.equal(ui.button.attributes['aria-busy'], undefined, 'network error clears spinner for status retry');
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
    console.log('Job button UI: all assertions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
