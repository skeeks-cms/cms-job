// Execute the existing card poller without a browser or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../src/views/admin-cms-job-run/view.php', 'utf8');
const blocks = [...source.matchAll(/<<<JS\r?\n([\s\S]*?)\r?\nJS/g)];
const code = blocks.at(-1)[1].replace('{$options}', JSON.stringify({id: 1, url: '/progress'})).replace(/\\\$/g, '$');
const fields = new Map();
let poll, response, reloaded = false;
const root = {length: 1, find(selector) {
    if (!fields.has(selector)) fields.set(selector, {value: '', text(value) { this.value = value; }, css() {}});
    return fields.get(selector);
}};
const $ = () => root;
$.getJSON = (url, callback) => callback(response);
vm.runInNewContext(code, {sx: {$}, setInterval(fn) { poll = fn; return 1; }, clearInterval() {}, window: {location: {reload() { reloaded = true; }}}});
for (const label of ['В очереди', 'Выполняется', 'Статус уточняется']) {
    response = {success: true, data: {1: {status: 'queued', label, percent: null, current: 0, finished: false}}};
    poll();
    assert.equal(fields.get('[data-sx-job-status]').value, label);
    assert.equal(reloaded, false, 'Observer requeues do not reload the card');
}
response.data[1] = {...response.data[1], status: 'failed', label: 'Ошибка', finished: true};
poll();
assert.equal(fields.get('[data-sx-job-status]').value, 'Ошибка');
assert.equal(reloaded, true, 'Terminal state refreshes actions and results');
console.log('OK: card status polling and terminal refresh');
