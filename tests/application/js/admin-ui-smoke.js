// On an open campaign settings page (does not save):
// playwright-cli run-code (Get-Content -Raw fromfolder/tests/application/js/admin-ui-smoke.js)
async page => {
    const result = await page.evaluate(() => {
        const check = (condition, message) => { if (!condition) throw new Error(message); };
        const originalAlert = window.alert;
        const originalFx = $.fx.off;
        const alerts = [];
        window.alert = message => alerts.push(message);
        $.fx.off = true;
        const checked = [];
        try {
            for (const [id, rowClass, button, removeClass, max, min] of [
                ['redirect_container', 'redirect-item', 'add-redirect-item', 'remove-redirect-item', 5, 1],
                ['curl_container', 'curl-item', 'add-curl-item', 'remove-curl-item', 5, 1],
                ['errorcodes_container', 'errorcode-item', 'add-errorcode-item', 'remove-errorcode-item', 5, 1],
                ['backfix_urls_container', 'backfix-url-item', 'add-backfix-url-item', 'remove-backfix-url-item', 10, 0],
            ]) {
                const container = document.getElementById(id);
                const original = container.innerHTML;
                const rows = () => $(container).find('.' + rowClass);
                const add = () => $('#' + button).trigger('click');
                const remove = index => rows().eq(index).find('.' + removeClass).trigger('click');
                try {
                    while (rows().length > 1) remove(rows().length - 1);
                    if (!rows().length) add();
                    rows().find('input').val('keep-me');
                    add();
                    check(rows().last().find('input').val() === '', id + ': new row must be empty');
                    rows().last().find('input').val('last-value');
                    add();
                    remove(1);
                    check(rows().first().find('input').val() === 'keep-me', id + ': existing value lost');
                    check(rows().last().find('input').attr('name').endsWith('[1]'), id + ': index gap');
                    while (rows().length < max) add();
                    const beforeMax = alerts.length;
                    add();
                    check(rows().length === max && alerts.length === beforeMax + 1, id + ': max limit');
                    while (rows().length > min) remove(rows().length - 1);
                    if (min) {
                        const beforeMin = alerts.length;
                        remove(0);
                        check(rows().length === min && alerts.length === beforeMin + 1, id + ': min limit');
                    }
                    add();
                    check(rows().length === min + 1, id + ': cannot add after removal');
                    rows().each((index, row) => check($(row).find('input').attr('name').endsWith('[' + index + ']'), id + ': numbering'));
                    checked.push(id);
                } finally {
                    container.innerHTML = original;
                }
            }
        } finally {
            window.alert = originalAlert;
            $.fx.off = originalFx;
        }
        const canvas = document.getElementById('matrix-rain');
        const cleanup = setupMatrixRain();
        window.dispatchEvent(new Event('resize'));
        check(canvas.width === innerWidth && canvas.height === innerHeight, 'rain: resize');
        cleanup();
        canvas.width = 1;
        window.dispatchEvent(new Event('resize'));
        check(canvas.width === 1, 'rain: resize listener was not removed');
        return checked;
    });
    return { passed: 'row limits, blank cloning, removal, numbering and rain cleanup', lists: result };
}
