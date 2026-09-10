/* Job controls inherit backend visuals. Unknown progress stays indeterminate. */
(function () {
    'use strict';
    if (window.sxJobButtonsLoaded) return;
    window.sxJobButtonsLoaded = true;
    function init(root) {
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const button = root.querySelector('[data-sx-job-start]');
        const status = root.querySelector('[data-sx-job-status]');
        const result = root.querySelector('[data-sx-job-result]');
        let pending = false, unknown = true, running = false, timer, windowUrl;
        async function request(start) {
            if (pending || !root.isConnected) return;
            clearTimeout(timer);
            pending = true;
            button.disabled = true;
            // Background polling must not flash the backend button spinner.
            if (start || unknown) button.setAttribute('aria-busy', 'true');
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 15000);
            try {
                const url = new URL(start ? root.dataset.startUrl : root.dataset.statusUrl, location.href);
                if (url.origin !== location.origin) throw new Error('Invalid origin');
                const options = {credentials: 'same-origin', signal: controller.signal,
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
                if (start) {
                    options.method = 'POST';
                    const body = new URLSearchParams();
                    body.set(yii.getCsrfParam(), yii.getCsrfToken());
                    options.body = body;
                }
                const response = await fetch(url, options);
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const payload = await response.json();
                if (!payload.success) throw new Error('Rejected');
                const run = payload.run;
                const active = run && !run.finished;
                running = !!active && (run.displayStatus ? run.displayStatus === 'running' : (run.busy === true || run.status === 'running'));
                unknown = false;
                button.textContent = active ? run.label : root.dataset.label;
                button.disabled = !!active;
                // The active state already lives in the button. Keep only extra detail below it.
                const details = [];
                if (run) {
                    if (!active) details.push(run.label);
                    if (run.percent != null) details.push(run.percent + '%');
                    if (run.message && run.message !== run.label) details.push(run.message);
                }
                status.textContent = details.join(' · ');
                status.hidden = !details.length;
                windowUrl = null;
                result.hidden = !(run && run.url);
                if (run && run.url) {
                    const target = new URL(run.url, location.href);
                    if (target.origin !== location.origin) throw new Error('Invalid result origin');
                    result.href = target.href;
                    result.textContent = active ? 'Открыть задание' : 'Открыть результат';
                    if (run.windowUrl) {
                        const targetWindow = new URL(run.windowUrl, location.href);
                        if (targetWindow.origin !== location.origin) throw new Error('Invalid window origin');
                        windowUrl = targetWindow.href;
                    }
                }
                if (active) timer = setTimeout(() => request(false), document.hidden ? 10000 : 2500);
                root.dispatchEvent(new CustomEvent('sx:job-status', {bubbles: true, detail: {run: run}}));
            } catch (error) {
                unknown = true;
                running = false;
                status.hidden = false;
                status.textContent = 'Не удалось проверить состояние. Проверьте статус перед повторным запуском.';
                button.textContent = 'Проверить статус';
                button.disabled = false;
            } finally {
                clearTimeout(timeout);
                pending = false;
                if (running) button.setAttribute('aria-busy', 'true');
                else button.removeAttribute('aria-busy');
            }
        }
        button.addEventListener('click', () => request(!unknown));
        result.addEventListener('click', (event) => {
            if (!windowUrl || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            new sx.classes.backend.widgets.Action({url: windowUrl, isOpenNewWindow: true}).go();
        });
        request(false);
    }
    function scan() { document.querySelectorAll('[data-sx-job-button]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan);
    else scan();
    new MutationObserver(scan).observe(document.documentElement, {childList: true, subtree: true});
}());
