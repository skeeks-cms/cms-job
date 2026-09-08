// Browser-independent stream lifecycle test; never contacts the site or servers.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../src/assets/src/job-log-stream.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
async function scenario() {
    let status, observer, timer, calls = 0;
    const events = {};
    const pre = {isConnected: true, dataset: {sxLogStream: '/log-chunk?id=1'},
        scrollHeight: 900, clientHeight: 320, scrollTop: 0,
        textContent: '', after(node) { status = node; }};
    const bytes = Buffer.from('Привет\n\x1b[31m<script>hello</script>\x1b[0m');
    const chunks = [
        {bytes: bytes.subarray(0, 3).toString('base64'), offset: 3, reset: true, finished: false},
        {bytes: bytes.subarray(3).toString('base64'), offset: bytes.length, reset: false, finished: false},
        {bytes: '', offset: bytes.length, reset: false, finished: true},
    ];
    vm.runInNewContext(source, {
        window: {location: {href: 'http://localhost/'}, addEventListener(name, cb) { events[name] = cb; }},
        document: {hidden: false, documentElement: {}, createElement() { return {setAttribute() {}}; },
            querySelectorAll() { return pre.isConnected ? [pre] : []; }},
        MutationObserver: class { constructor(cb) { observer = cb; } observe() {} disconnect() {} },
        Map, Uint8Array, TextDecoder, URL, AbortController,
        atob: s => Buffer.from(s, 'base64').toString('binary'),
        fetch: async url => { calls++; if (calls > 1) assert.equal(url.searchParams.get('offset'), String(chunks[calls - 2].offset));
            return {ok: true, json: async () => chunks[calls - 1]}; },
        setTimeout(cb) { timer = cb; return 1; }, clearTimeout() { timer = null; },
    });
    await tick();
    assert.equal(pre.textContent, 'П'); // split Cyrillic codepoint is withheld, not corrupted
    assert.equal(pre.scrollTop, 900);
    pre.scrollTop = 100; // reading older output must not jump to the bottom
    let next = timer; timer = null; next(); await tick();
    assert.equal(pre.textContent, 'Привет\n<script>hello</script>');
    assert.equal(pre.scrollTop, 100);
    next = timer; timer = null; next(); await tick();
    assert.equal(status.textContent, 'Лог дочитан.');
    assert.equal(timer, null);
    assert.equal(calls, 3);
    pre.isConnected = false; observer(); events.pagehide();
    console.log('PASS split UTF-8, ANSI removal, literal HTML, cursor, scroll preservation, completion and detach');
}
scenario().catch(error => { console.error(error); process.exitCode = 1; });
