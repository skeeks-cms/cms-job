(function () {
    'use strict';
    if (window.sxJobLogStreamInstalled) { return; }
    window.sxJobLogStreamInstalled = true;
    var streams = new Map();
    var limit = 262144;

    function attach(pre) {
        if (streams.has(pre)) { return; }
        var state = {offset: null, bytes: new Uint8Array(0), stopped: false, timer: null, request: null};
        var status = document.createElement('div');
        status.className = 'sx-collection-cell__secondary';
        status.setAttribute('role', 'status');
        pre.after(status);
        streams.set(pre, state);

        async function poll() {
            if (state.stopped || !pre.isConnected) { return; }
            if (document.hidden) { state.timer = setTimeout(poll, 3000); return; }
            state.request = new AbortController();
            try {
                var url = new URL(pre.dataset.sxLogStream, window.location.href);
                if (state.offset !== null) { url.searchParams.set('offset', state.offset); }
                var response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: state.request.signal,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok) {
                    if ([401, 403, 404, 410].includes(response.status)) {
                        status.textContent = 'Лог недоступен или срок хранения истёк.';
                        state.stopped = true;
                        return;
                    }
                    throw new Error('request');
                }
                var chunk = await response.json();
                if (typeof chunk.bytes !== 'string' || !Number.isSafeInteger(chunk.offset)) { throw new Error('format'); }
                if (!pre.isConnected || state.stopped) { return; }
                var follow = state.offset === null || pre.scrollHeight - pre.scrollTop - pre.clientHeight < 32;
                var oldScroll = pre.scrollTop;
                var raw = atob(chunk.bytes);
                var incoming = Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
                var previous = chunk.reset ? new Uint8Array(0) : state.bytes;
                var combined = new Uint8Array(previous.length + incoming.length);
                combined.set(previous); combined.set(incoming, previous.length);
                var trimmed = combined.length > limit;
                state.bytes = combined.slice(Math.max(0, combined.length - limit));
                if (incoming.length || chunk.reset) {
                    var text = new TextDecoder('utf-8').decode(state.bytes, {stream: !chunk.finished || chunk.hasMore});
                    text = text.replace(/\x1b\[[0-?]*[ -/]*[@-~]/g, '')
                        .replace(/\x1b(?:\[[0-?]*[ -/]*)?$/, '')
                        .replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
                    pre.textContent = text || 'Лог пока пуст.';
                    pre.scrollTop = follow ? pre.scrollHeight : oldScroll;
                }
                state.offset = chunk.offset;
                state.truncated = state.truncated || chunk.truncated || trimmed;
                state.stopped = chunk.finished && !chunk.hasMore;
                status.textContent = (state.stopped ? 'Лог дочитан.' : 'Лог обновляется автоматически.')
                    + (state.truncated ? ' Показаны последние 256 КБ; полный файл доступен для скачивания.' : '');
            } catch (error) {
                if (!state.stopped && pre.isConnected) { status.textContent = 'Не удалось обновить лог. Повторим автоматически.'; }
            } finally {
                state.request = null;
                if (!state.stopped && pre.isConnected) { state.timer = setTimeout(poll, 3000); }
            }
        }
        poll();
    }

    function scan() {
        streams.forEach(function (state, pre) {
            if (!pre.isConnected) {
                state.stopped = true; clearTimeout(state.timer);
                if (state.request) { state.request.abort(); }
                streams.delete(pre);
            }
        });
        document.querySelectorAll('[data-sx-log-stream]').forEach(attach);
    }
    var observer = new MutationObserver(scan);
    observer.observe(document.documentElement, {childList: true, subtree: true});
    window.addEventListener('pagehide', function () {
        observer.disconnect();
        streams.forEach(function (state) {
            state.stopped = true; clearTimeout(state.timer);
            if (state.request) { state.request.abort(); }
        });
        streams.clear();
    });
    window.addEventListener('pageshow', function () {
        observer.observe(document.documentElement, {childList: true, subtree: true}); scan();
    });
    scan();
})();
