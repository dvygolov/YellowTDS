(function () {
    const state = { settings: null, revision: 0, plugins: null, backupsLoaded: false, monitoringOperationId: null };

    function node(selector) { return document.querySelector(selector); }

    function setStatus(message, kind = '') {
        const status = node('#settingsSaveStatus');
        if (!status) return;
        status.textContent = message || '';
        status.className = `settings-save-status ${kind}`.trim();
    }

    function setUpdateStatus(message, kind = '') {
        const status = node('#settingsUpdateStatus');
        if (!status) return;
        status.textContent = message || '';
        status.className = `settings-update-status ${kind}`.trim();
    }

    function setBackupsStatus(message, kind = '') {
        const status = node('#backupsStatus');
        if (!status) return;
        status.textContent = message || '';
        status.className = `settings-update-status ${kind}`.trim();
    }

    async function readJsonResponse(response, fallbackMessage) {
        const body = await response.text();
        let result;
        try {
            result = JSON.parse(body);
        } catch (_error) {
            const suffix = response.status ? ` (HTTP ${response.status})` : '';
            const error = new Error(`${fallbackMessage}${suffix}. The server returned an invalid response.`);
            error.httpStatus = response.status;
            throw error;
        }
        if (!response.ok) {
            const error = new Error(result.error || `${fallbackMessage} (HTTP ${response.status})`);
            error.httpStatus = response.status;
            throw error;
        }
        return result;
    }

    function setBusy(busy) {
        ['#saveSettings', '#updateTds', '#updateGeoBases', '#randomizeStorage', '#createFullBackup', '#createQuickBackup'].forEach((selector) => {
            const element = node(selector);
            if (element) element.disabled = busy;
        });
        document.querySelectorAll('.settings-backup-actions button').forEach((button) => { button.disabled = busy; });
    }

    function clearErrors() {
        document.querySelectorAll('#settingsForm .settings-field-error').forEach((element) => element.remove());
        document.querySelectorAll('#settingsForm .is-invalid').forEach((element) => element.classList.remove('is-invalid'));
    }

    function showFieldErrors(fields) {
        clearErrors();
        Object.entries(fields || {}).forEach(([field, message]) => {
            const input = node(`#settingsForm [name="${CSS.escape(field)}"]`);
            if (!input) return;
            input.classList.add('is-invalid');
            const error = document.createElement('small');
            error.className = 'settings-field-error';
            error.textContent = message;
            input.insertAdjacentElement('afterend', error);
        });
    }

    function field(name) { return node(`#settingsForm [name="${name}"]`); }

    function fillFields(settings) {
        ['adminPassword', 'adminDomain', 'adminIp', 'adminPath', 'apiKey', 'dbConnection', 'backupDir', 'cachingDir']
            .forEach((name) => { if (field(name)) field(name).value = settings[name] ?? ''; });
        field('adminPassword').value = '';
        field('useUTP').checked = !!settings.useUTP;
        field('debug').checked = !!settings.debug;
        field('timezone').value = settings.timezone || 'Europe/Moscow';
        field('conversionAttribution').value = settings.conversionAttribution || 'click_time';
        field('logRetentionDays').value = settings.logRetentionDays ?? 30;
    }

    function renderCurrentIp(ip) {
        const button = node('#addCurrentAdminIp');
        const currentIp = String(ip || '').trim();
        button.hidden = currentIp === '';
        button.dataset.ip = currentIp;
        button.textContent = currentIp === '' ? '' : `Add current IP: ${currentIp}`;
    }

    function renderCurrentDomain(domain) {
        const button = node('#addCurrentAdminDomain');
        const currentDomain = String(domain || '').trim();
        button.hidden = currentDomain === '';
        button.dataset.domain = currentDomain;
        button.textContent = currentDomain === '' ? '' : `Add current domain: ${currentDomain}`;
    }

    function createPluginRow(type, id, meta, config) {
        const row = document.createElement('div');
        row.className = 'settings-plugin-row';
        row.dataset.pluginType = type;
        row.dataset.pluginId = id;

        const identity = document.createElement('div');
        identity.className = 'settings-plugin-identity';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'settings-plugin-enabled';
        checkbox.checked = !!config.enabled;
        checkbox.setAttribute('role', 'switch');
        checkbox.setAttribute('aria-label', `Enable ${id}`);
        const text = document.createElement('span');
        text.innerHTML = `<strong>${escapeHtml(id)}</strong><small>${escapeHtml(meta.class || meta.file || '')}</small>`;
        const toggle = document.createElement('label');
        toggle.className = 'settings-plugin-toggle';
        const track = document.createElement('span');
        track.className = 'settings-plugin-switch-track';
        track.setAttribute('aria-hidden', 'true');
        const status = document.createElement('span');
        status.className = 'settings-plugin-state';
        toggle.append(checkbox, track, status);
        identity.append(toggle, text);
        row.append(identity);

        let preferred = null;
        if (type === 'currency') {
            preferred = document.createElement('input');
            preferred.type = 'text';
            preferred.className = 'settings-plugin-preferred';
            preferred.placeholder = 'Preferred: RUB, THB';
            preferred.value = (config.preferredCurrencies || []).join(', ');
            row.append(preferred);
        }

        const syncEnabledState = () => {
            const enabled = checkbox.checked;
            row.classList.toggle('is-disabled', !enabled);
            row.dataset.enabled = enabled ? 'true' : 'false';
            status.textContent = enabled ? 'Enabled' : 'Disabled';
            if (preferred) preferred.disabled = !enabled;
        };
        checkbox.addEventListener('change', syncEnabledState);
        syncEnabledState();
        return row;
    }

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = String(value);
        return element.innerHTML;
    }

    function renderPlugins(settings, catalog) {
        const currencyContainer = node('#currencyPlugins');
        const vpnContainer = node('#vpnPlugins');
        currencyContainer.replaceChildren();
        vpnContainer.replaceChildren();

        Object.entries(catalog.currency || {}).forEach(([id, meta]) => {
            const config = settings.plugins?.currency?.items?.[id] || { enabled: false, preferredCurrencies: [] };
            currencyContainer.append(createPluginRow('currency', id, meta, config));
        });
        Object.entries(catalog.vpn || {}).forEach(([id, meta]) => {
            const config = settings.plugins?.vpn?.items?.[id] || { enabled: false };
            vpnContainer.append(createPluginRow('vpn', id, meta, config));
        });
        node('#vpnMode').value = settings.plugins?.vpn?.mode === 'most' ? 'most' : 'any';

        const errors = node('#pluginErrors');
        const errorItems = catalog.errors || [];
        errors.hidden = errorItems.length === 0;
        errors.innerHTML = errorItems.length
            ? `<strong>Invalid plugins</strong>${errorItems.map((item) => `<div>${escapeHtml(item.file)}: ${escapeHtml(item.error)}</div>`).join('')}`
            : '';
    }

    function collectPluginItems(type) {
        const items = {};
        document.querySelectorAll(`.settings-plugin-row[data-plugin-type="${type}"]`).forEach((row) => {
            const id = row.dataset.pluginId;
            const item = { enabled: !!row.querySelector('.settings-plugin-enabled')?.checked };
            if (type === 'currency') {
                item.preferredCurrencies = (row.querySelector('.settings-plugin-preferred')?.value || '')
                    .split(',')
                    .map((value) => value.trim().toUpperCase())
                    .filter(Boolean);
            }
            items[id] = item;
        });
        return items;
    }

    function collectSettings() {
        const settings = { ...state.settings };
        ['adminPassword', 'adminDomain', 'adminIp', 'adminPath', 'apiKey', 'dbConnection', 'backupDir', 'cachingDir']
            .forEach((name) => { settings[name] = field(name).value.trim(); });
        settings.useUTP = field('useUTP').checked;
        settings.debug = field('debug').checked;
        settings.timezone = field('timezone').value;
        settings.conversionAttribution = field('conversionAttribution').value;
        settings.logRetentionDays = Number.parseInt(field('logRetentionDays').value, 10);
        settings.plugins = {
            currency: { items: collectPluginItems('currency') },
            vpn: { mode: node('#vpnMode').value, items: collectPluginItems('vpn') },
        };
        return settings;
    }

    async function loadSettings() {
        node('#settingsLoading').hidden = false;
        node('#settingsForm').hidden = true;
        setStatus('');
        clearErrors();
        const response = await fetch('settingseditor.php', { headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Failed to load settings');
        state.settings = result.settings;
        state.revision = result.revision;
        state.plugins = result.plugins;
        fillFields(result.settings);
        renderCurrentDomain(result.currentDomain);
        renderCurrentIp(result.currentIp);
        renderPlugins(result.settings, result.plugins);
        node('#tdsVersion').textContent = `Installed version: ${result.updates?.currentVersion || 'unknown'}`;
        node('#geoBasesVersion').textContent = `Installed bases: ${result.updates?.geoBases || 'unknown'}`;
        node('#settingsLoading').hidden = true;
        node('#settingsForm').hidden = false;
    }

    function generateManagementApiKey() {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function randomStorageName(used) {
        const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        let value = '';
        do {
            const bytes = new Uint8Array(12);
            crypto.getRandomValues(bytes);
            value = String.fromCharCode(97 + (bytes[0] % 26));
            for (let index = 1; index < bytes.length; index += 1) value += alphabet[bytes[index] % alphabet.length];
        } while (used.has(value));
        used.add(value);
        return value;
    }

    function randomizeStorage() {
        const used = new Set(['admin', 'api', 'bases', 'caching', 'db', 'docs', 'js', 'logs', 'plugins', 'scripts', 'tests', 'tmp', 'ycclogs', 'temp_update', String(field('adminPath').value || '').toLowerCase()]);
        field('dbConnection').value = `${randomStorageName(used)}.db`;
        ['backupDir', 'cachingDir']
            .forEach((name) => { field(name).value = randomStorageName(used); });
        clearErrors();
        setStatus('New database, backup folder and cache root names generated. Cache subfolders use fixed system names. Save settings to apply the changes.');
    }

    function formatBackupSize(bytes) {
        const size = Number(bytes) || 0;
        if (size < 1024) return `${size} B`;
        if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
        if (size < 1024 * 1024 * 1024) return `${(size / 1024 / 1024).toFixed(1)} MB`;
        return `${(size / 1024 / 1024 / 1024).toFixed(1)} GB`;
    }

    function backupTypeLabel(type) {
        return ({ pre_update: 'Before update', pre_restore: 'Before restore', manual: 'Manual' })[type] || type || 'Unknown';
    }

    function backupModeLabel(mode) {
        return mode === 'full' ? 'Full' : mode === 'quick' ? 'Quick' : 'Unsupported';
    }

    function renderBackups(backups, databaseBytes) {
        const container = node('#backupsList');
        node('#createFullBackup').title = `Includes the current SQLite database (${formatBackupSize(databaseBytes)}). Complete restore point; large databases can take several minutes on shared hosting.`;
        node('#createQuickBackup').title = 'Excludes only the SQLite database. Files, settings, landing pages, white pages and cache are included.';
        container.replaceChildren();
        if (!backups.length) {
            const empty = document.createElement('div');
            empty.className = 'settings-backup-empty';
            empty.textContent = 'No backups yet. Create a Full or Quick backup now.';
            container.append(empty);
            return;
        }

        backups.forEach((backup) => {
            const row = document.createElement('div');
            row.className = `settings-backup-row${backup.valid ? '' : ' is-invalid'}`;
            const info = document.createElement('div');
            const title = document.createElement('div');
            title.className = 'settings-backup-title';
            const mode = document.createElement('span');
            mode.className = `settings-backup-mode is-${backup.mode || 'unsupported'}`;
            mode.innerHTML = backup.mode === 'full'
                ? '<i class="bi bi-database-check"></i> Full'
                : backup.mode === 'quick'
                    ? '<i class="bi bi-lightning-charge"></i> Quick'
                    : '<i class="bi bi-question-circle"></i> Unsupported';
            const titleText = document.createElement('span');
            titleText.textContent = `${backupTypeLabel(backup.type)} · YellowTDS ${backup.version}`;
            title.append(mode, titleText);
            const details = document.createElement('div');
            details.className = 'settings-backup-details';
            const when = backup.createdAt ? new Date(backup.createdAt).toLocaleString() : 'Unknown date';
            details.textContent = backup.valid
                ? `${when} · ${formatBackupSize(backup.size)}`
                : `${when} · Invalid backup: ${backup.error || 'unknown error'}`;
            info.append(title, details);

            const actions = document.createElement('div');
            actions.className = 'settings-backup-actions';
            if (backup.valid) {
                const restore = document.createElement('button');
                restore.type = 'button';
                restore.className = 'btn btn-warning';
                restore.dataset.backupAction = 'restore';
                restore.dataset.backupId = backup.id;
                restore.dataset.backupMode = backup.mode;
                restore.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Restore';
                actions.append(restore);
            }
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-danger settings-backup-delete';
            remove.dataset.backupAction = 'delete';
            remove.dataset.backupId = backup.id;
            remove.dataset.backupMode = backup.mode || '';
            remove.title = 'Delete backup';
            remove.setAttribute('aria-label', 'Delete backup');
            remove.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
            actions.append(remove);
            row.append(info, actions);
            container.append(row);
        });
    }

    async function loadBackups({ resumeOperation = true, silent = false, throwOnError = false } = {}) {
        if (!silent) setBackupsStatus('Loading backups…');
        try {
            const response = await fetch('backups.php', { headers: { Accept: 'application/json' } });
            const result = await readJsonResponse(response, 'Failed to load backups');
            if (Array.isArray(result.backups)) {
                renderBackups(result.backups, result.databaseBytes || 0);
            }
            state.backupsLoaded = true;
            if (resumeOperation && result.operation?.status === 'running') {
                void monitorBackupOperation(result.operation.id, Number(result.operation.startedAt) * 1000);
            } else if (!silent && state.monitoringOperationId === null) {
                setBackupsStatus('');
            }
            return result;
        } catch (error) {
            setBackupsStatus(error.message, 'error');
            if (throwOnError) throw error;
            return null;
        }
    }

    function newOperationId() {
        return typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
    }

    function wait(milliseconds) {
        return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
    }

    async function monitorBackupOperation(operationId, startedAt = Date.now()) {
        if (!operationId || state.monitoringOperationId === operationId) return;
        state.monitoringOperationId = operationId;
        setBusy(true);
        let missingChecks = 0;
        try {
            while (state.monitoringOperationId === operationId) {
                try {
                    const response = await fetch(`backups.php?action=status&operationId=${encodeURIComponent(operationId)}`, { headers: { Accept: 'application/json' } });
                    const result = await readJsonResponse(response, 'Failed to check backup status');
                    const operation = result.operation || {};
                    missingChecks = 0;
                    if (operation.status === 'completed') {
                        await loadBackups({ resumeOperation: false, silent: true, throwOnError: true });
                        setBackupsStatus(operation.message || 'Backup created successfully.', 'success');
                        return;
                    }
                    if (operation.status === 'failed') {
                        const operationError = new Error(operation.error || operation.message || 'Backup creation failed.');
                        operationError.terminal = true;
                        throw operationError;
                    }
                    const elapsed = Date.now() - (startedAt || Date.now());
                    setBackupsStatus(
                        elapsed >= 5 * 60 * 1000
                            ? 'Backup is still running after 5 minutes. YellowTDS will keep checking automatically.'
                            : operation.message || 'Backup is still running. Checking automatically…',
                        elapsed >= 5 * 60 * 1000 ? 'warning' : '',
                    );
                } catch (error) {
                    if (error.terminal) {
                        throw error;
                    } else if (error.httpStatus === 404 && missingChecks < 3) {
                        missingChecks++;
                    } else if (error.httpStatus === 404) {
                        throw new Error('Backup operation status was not found.');
                    } else if (error.httpStatus && error.httpStatus < 500) {
                        throw error;
                    } else {
                        setBackupsStatus('Unable to check the backup yet. Retrying automatically…', 'warning');
                    }
                }
                await wait(5000);
            }
        } catch (error) {
            setBackupsStatus(error.message, 'error');
        } finally {
            if (state.monitoringOperationId === operationId) state.monitoringOperationId = null;
            setBusy(false);
        }
    }

    async function runBackupAction(action, id = '', mode = '') {
        if (action === 'delete' && !window.confirm('Delete this backup permanently?')) return;
        if (action === 'restore') {
            const databaseNotice = mode === 'full'
                ? 'The current SQLite database will be replaced.'
                : 'The current SQLite database will be preserved.';
            if (!window.confirm(`Restore this ${backupModeLabel(mode)} backup? ${databaseNotice} Files, cache and settings will return to the selected state. A matching safety backup is created first.`)) return;
        }

        setBusy(true);
        const progressMessages = {
            create: mode === 'full' ? 'Creating Full backup with SQLite…' : 'Creating Quick backup without SQLite…',
            restore: 'Restoring system…',
            delete: 'Deleting backup…',
        };
        setBackupsStatus(progressMessages[action] || 'Working…');
        const operationId = ['create', 'restore'].includes(action) ? newOperationId() : null;
        const startedAt = Date.now();
        try {
            const payload = { action };
            if (id) payload.id = id;
            if (mode) payload.mode = mode;
            if (operationId) payload.operationId = operationId;
            const response = await fetch('backups.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await readJsonResponse(response, `Failed to ${action} backup`);
            if (action === 'restore') {
                setBackupsStatus(result.message, 'success');
                const destination = result.redirect ? new URL(result.redirect, window.location.href).href : window.location.href;
                window.location.assign(destination);
                return;
            }
            await loadBackups();
            setBackupsStatus(result.message, 'success');
        } catch (error) {
            if (action === 'create' && operationId && (!error.httpStatus || error.httpStatus >= 500)) {
                setBackupsStatus('The web server stopped waiting. The backup may still be running; YellowTDS is checking automatically…', 'warning');
                await monitorBackupOperation(operationId, startedAt);
                return;
            }
            setBackupsStatus(error.message, 'error');
        } finally {
            if (state.monitoringOperationId === null) setBusy(false);
        }
    }

    async function openSettings(event) {
        event?.preventDefault();
        $('#settingsModal').modal({
            modalClass: 'ywbmodal settings-modal',
            fadeDuration: 200,
            showClose: false,
        });
        try {
            state.backupsLoaded = false;
            await loadSettings();
        } catch (error) {
            node('#settingsLoading').textContent = error.message;
            setStatus(error.message, 'error');
        }
    }

    async function saveSettings() {
        setBusy(true);
        setStatus('Saving…');
        clearErrors();
        try {
            const response = await fetch('settingseditor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ settings: collectSettings(), revision: state.revision }),
            });
            const result = await response.json();
            if (!response.ok) {
                if (result.fields) showFieldErrors(result.fields);
                throw new Error(result.error || 'Failed to save settings');
            }
            state.settings = result.settings;
            state.revision = result.revision;
            setStatus('Settings saved', 'success');
            if (result.redirect) {
                window.location.href = new URL(result.redirect, window.location.href).href;
                return;
            }
            fillFields(result.settings);
        } catch (error) {
            setStatus(error.message, 'error');
        } finally {
            setBusy(false);
        }
    }

    function startUpdateOverlay(message) {
        const overlay = node('#updateOverlay');
        const typing = node('#typing-text');
        overlay.style.display = 'flex';
        const rainCleanup = setupMatrixRain();
        const typingCleanup = typeText(message, typing);
        return () => {
            rainCleanup?.();
            typingCleanup?.();
            overlay.style.display = 'none';
        };
    }

    async function updateGeoBases() {
        setBusy(true);
        setUpdateStatus('Updating GeoBases…');
        const cleanup = startUpdateOverlay('GEOBASES UPDATING...');
        try {
            const response = await fetch('../bases/update.php', { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok || result.error) throw new Error(result.result || 'GeoBases update failed');
            setUpdateStatus(result.result.trim(), 'success');
            node('#geoBasesVersion').textContent = 'Installed bases: just updated';
        } catch (error) {
            setUpdateStatus(error.message, 'error');
        } finally {
            cleanup();
            setBusy(false);
        }
    }

    async function updateTds() {
        setBusy(true);
        setUpdateStatus('Checking for updates…');
        const cleanup = startUpdateOverlay('SYSTEM UPDATING...');
        try {
            const check = await sendAutoupdateRequest('check');
            if (!check.success) throw new Error(check.message || 'Update check failed');
            if (!check.hasUpdate) {
                setUpdateStatus('The installed version is up to date.', 'success');
                return;
            }
            if (!window.confirm(`Update to version ${check.version}?`)) {
                setUpdateStatus(`Version ${check.version} is available.`);
                return;
            }
            setUpdateStatus(`Installing ${check.version}…`);
            const result = await sendAutoupdateRequest('update');
            if (!result.success) throw new Error(result.message || 'Update failed');
            setUpdateStatus(result.message, 'success');
            window.location.reload();
        } catch (error) {
            setUpdateStatus(error.message, 'error');
        } finally {
            cleanup();
            setBusy(false);
        }
    }

    function activateTab(name) {
        document.querySelectorAll('.settings-tab-button').forEach((button) => button.classList.toggle('active', button.dataset.settingsTab === name));
        document.querySelectorAll('.settings-tab-panel').forEach((panel) => panel.classList.toggle('active', panel.dataset.settingsPanel === name));
        if (name === 'backups' && !state.backupsLoaded) loadBackups();
    }

    document.addEventListener('DOMContentLoaded', () => {
        node('#openSettings')?.addEventListener('click', openSettings);
        node('#saveSettings')?.addEventListener('click', saveSettings);
        node('#updateGeoBases')?.addEventListener('click', updateGeoBases);
        node('#updateTds')?.addEventListener('click', updateTds);
        node('#randomizeStorage')?.addEventListener('click', randomizeStorage);
        node('#createFullBackup')?.addEventListener('click', () => runBackupAction('create', '', 'full'));
        node('#createQuickBackup')?.addEventListener('click', () => runBackupAction('create', '', 'quick'));
        node('#backupsList')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-backup-action]');
            if (button) runBackupAction(button.dataset.backupAction, button.dataset.backupId, button.dataset.backupMode);
        });
        node('#addCurrentAdminDomain')?.addEventListener('click', () => {
            const button = node('#addCurrentAdminDomain');
            if (!button.dataset.domain) return;
            field('adminDomain').value = button.dataset.domain;
            field('adminDomain').classList.remove('is-invalid');
            field('adminDomain').focus();
        });
        node('#addCurrentAdminIp')?.addEventListener('click', () => {
            const button = node('#addCurrentAdminIp');
            if (!button.dataset.ip) return;
            const input = field('adminIp');
            const configuredIps = input.value
                .split(',')
                .map((ip) => ip.trim())
                .filter(Boolean);
            if (!configuredIps.includes(button.dataset.ip)) {
                configuredIps.push(button.dataset.ip);
            }
            input.value = configuredIps.join(', ');
            input.classList.remove('is-invalid');
            input.focus();
        });
        node('#generateApiKey')?.addEventListener('click', () => {
            const input = field('apiKey');
            if (!input) return;
            input.value = generateManagementApiKey();
            input.classList.remove('is-invalid');
            input.focus();
        });
        node('#copyApiKey')?.addEventListener('click', async () => {
            const value = field('apiKey')?.value.trim() || '';
            if (value === '') {
                setStatus('Generate a management API key first.', 'error');
                return;
            }
            try {
                await navigator.clipboard.writeText(value);
                setStatus('Management API key copied.', 'success');
            } catch (_error) {
                field('apiKey').select();
                setStatus('Copy the selected key with Ctrl+C.', 'error');
            }
        });
        node('#closeSettings')?.addEventListener('click', () => $.modal.close());
        node('#cancelSettings')?.addEventListener('click', () => $.modal.close());
        document.querySelectorAll('.settings-tab-button').forEach((button) => button.addEventListener('click', () => activateTab(button.dataset.settingsTab)));
    });
})();
