<?php require_once __DIR__ . '/timezones.php'; ?>
<link rel="stylesheet" href="<?=get_admin_url_path()?>css/modal-common.css">

<div id="settingsModal" class="ywbmodal settings-modal" aria-labelledby="settingsModalTitle">
    <div class="modal-content">
        <div class="modal-header settings-modal-header">
            <div>
                <h5 id="settingsModalTitle"><i class="bi bi-gear"></i> Settings</h5>
            </div>
            <button type="button" class="settings-close" id="closeSettings" aria-label="Close">&times;</button>
        </div>
        <div class="settings-tabs" role="tablist">
            <button type="button" class="settings-tab-button active" data-settings-tab="general">General</button>
            <button type="button" class="settings-tab-button" data-settings-tab="security">Security</button>
            <button type="button" class="settings-tab-button" data-settings-tab="storage">Storage</button>
            <button type="button" class="settings-tab-button" data-settings-tab="backups">Backups</button>
            <button type="button" class="settings-tab-button" data-settings-tab="plugins">Plugins</button>
            <button type="button" class="settings-tab-button" data-settings-tab="updates">Updates</button>
        </div>
        <div class="modal-body settings-modal-body">
            <div id="settingsLoading" class="settings-loading">Loading settings…</div>
            <form id="settingsForm" autocomplete="off" hidden>
                <section class="settings-tab-panel active" data-settings-panel="general">
                    <div class="settings-grid">
                        <label class="settings-field">
                            <span>TDS timezone</span>
                            <select class="form-select" name="timezone">
                                <?php $timezoneLabelDate = new DateTimeImmutable('now'); ?>
                                <?php foreach (DateTimeZone::listIdentifiers() as $timezoneId) { ?>
                                <option value="<?= htmlspecialchars($timezoneId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(get_timezone_option_label($timezoneId, $timezoneLabelDate), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                <?php } ?>
                            </select>
                            <small>Used as the default timezone for new campaigns.</small>
                        </label>
                        <label class="settings-field">
                            <span>Conversion attribution</span>
                            <select class="form-select" name="conversionAttribution">
                                <option value="click_time">Click time</option>
                                <option value="conversion_time">Conversion time</option>
                            </select>
                            <small>Applies consistently to conversion, revenue and status metrics.</small>
                        </label>
                        <label class="settings-field">
                            <span>Log retention (days)</span>
                            <input type="number" name="logRetentionDays" min="1" max="3650" step="1">
                            <small>Structured server logs older than this are removed automatically.</small>
                        </label>
                    </div>
                    <div class="settings-switches">
                        <label><input type="checkbox" name="useUTP"> Use Universal Thank You Page</label>
                        <label><input type="checkbox" name="debug"> Debug mode</label>
                    </div>
                </section>

                <section class="settings-tab-panel" data-settings-panel="security">
                    <div class="settings-grid">
                        <label class="settings-field">
                            <span>New admin password</span>
                            <input type="password" name="adminPassword" autocomplete="new-password" placeholder="Leave blank to keep current password">
                            <small>For security, the current password is never shown.</small>
                        </label>
                        <label class="settings-field">
                            <span>Admin path</span>
                            <input type="text" name="adminPath" maxlength="64">
                            <small>The physical admin directory will be renamed.</small>
                        </label>
                        <label class="settings-field">
                            <span>Allowed admin domain</span>
                            <input type="text" name="adminDomain" placeholder="Empty means any domain">
                            <button type="button" class="settings-current-value" id="addCurrentAdminDomain" hidden></button>
                        </label>
                        <label class="settings-field">
                            <span>Allowed admin IPs</span>
                            <input type="text" name="adminIp" placeholder="Comma-separated; empty means any IP">
                            <button type="button" class="settings-current-value" id="addCurrentAdminIp" hidden></button>
                        </label>
                        <div class="settings-field settings-field-wide">
                            <span>Management API key</span>
                            <div class="settings-key-row">
                                <input id="managementApiKey" type="text" name="apiKey" autocomplete="off" spellcheck="false" placeholder="Empty disables the API">
                                <button type="button" class="btn btn-secondary" id="generateApiKey">Generate</button>
                                <button type="button" class="btn btn-secondary" id="copyApiKey">Copy</button>
                            </div>
                            <small>Used by <code>api/manage.php</code>. Empty disables it. Requests without this key return 404 unless Debug mode is on.</small>
                        </div>
                    </div>
                </section>

                <section class="settings-tab-panel" data-settings-panel="storage">
                    <div class="settings-section-heading settings-storage-heading">
                        <div><h6>Storage names</h6><small>Randomize the database, backup folder and cache root. Cache subfolders use fixed system names.</small></div>
                        <button type="button" class="btn btn-primary" id="randomizeStorage"><i class="bi bi-shuffle"></i> Randomize main paths</button>
                    </div>
                    <div class="settings-notice">Renamed database, backup and cache locations are moved physically. Existing target names are never merged or overwritten.</div>
                    <div class="settings-grid">
                        <label class="settings-field"><span>Database file</span><input type="text" name="dbConnection"></label>
                        <label class="settings-field"><span>Backup folder</span><input type="text" name="backupDir"><small>Update and restore snapshots are stored here. Only the newest five are kept.</small></label>
                        <label class="settings-field"><span>Cache root</span><input type="text" name="cachingDir"></label>
                    </div>
                </section>

                <section class="settings-tab-panel" data-settings-panel="backups">
                    <div class="settings-section-heading settings-backup-heading">
                        <h6>
                            <i
                                class="bi bi-info-circle settings-help-icon"
                                tabindex="0"
                                role="img"
                                aria-label="Full restore replaces the SQLite database. Quick restore preserves the current database. A matching safety backup is created first."
                                data-tooltip="Full restore replaces the SQLite database. Quick restore preserves the current database. A matching safety backup is created first."
                            ></i>
                            System backups
                        </h6>
                        <div class="settings-backup-toolbar">
                            <button type="button" class="btn btn-primary" id="createFullBackup" title="Includes the current SQLite database. Complete restore point; large databases can take several minutes on shared hosting."><i class="bi bi-database-add"></i> Full backup</button>
                            <button type="button" class="btn btn-primary" id="createQuickBackup" title="Excludes only the SQLite database. Files, settings, landing pages, white pages and cache are included."><i class="bi bi-lightning-charge"></i> Quick backup</button>
                        </div>
                    </div>
                    <div id="backupsList" class="settings-backup-list"></div>
                    <div id="backupsStatus" class="settings-update-status" aria-live="polite"></div>
                </section>

                <section class="settings-tab-panel" data-settings-panel="plugins">
                    <div class="settings-plugin-section">
                        <div class="settings-section-heading">
                            <div><h6>Currency sources</h6><small>Preferred currencies use ISO three-letter codes separated by commas.</small></div>
                        </div>
                        <div id="currencyPlugins" class="settings-plugin-list"></div>
                    </div>
                    <div class="settings-plugin-section">
                        <div class="settings-section-heading">
                            <div><h6>VPN / proxy detectors</h6><small>No enabled detector means the VPN check is disabled.</small></div>
                            <label class="settings-inline-field">
                                <i
                                    class="bi bi-info-circle settings-help-icon settings-decision-help"
                                    tabindex="0"
                                    role="img"
                                    aria-label="Any positive classifies the visitor as VPN/proxy when at least one enabled detector returns a positive result. Majority requires positive results from more than half of the detectors that returned a valid result. With one responding detector, both modes work the same. If every detector fails, the visitor is treated as VPN/proxy."
                                    data-tooltip="Any positive: one positive enabled detector is enough. Majority: more than half of the detectors that returned a valid result must be positive. With one responding detector, both modes work the same. If every detector fails, the visitor is treated as VPN/proxy."
                                ></i>
                                <span>Decision</span>
                                <select class="form-select settings-decision-select" id="vpnMode" aria-label="VPN / proxy decision mode"><option value="any">Any positive</option><option value="most">Majority</option></select>
                            </label>
                        </div>
                        <div id="vpnPlugins" class="settings-plugin-list"></div>
                    </div>
                    <div id="pluginErrors" class="settings-plugin-errors" hidden></div>
                </section>

                <section class="settings-tab-panel" data-settings-panel="updates">
                    <div class="settings-update-card">
                        <div><h6>YellowTDS</h6><div id="tdsVersion" class="settings-update-meta"></div></div>
                        <button type="button" class="btn btn-primary settings-update-action" id="updateTds"><i class="bi bi-cloud-arrow-down"></i> Check &amp; update</button>
                    </div>
                    <div class="settings-update-card">
                        <div><h6>GeoBases</h6><div id="geoBasesVersion" class="settings-update-meta"></div></div>
                        <button type="button" class="btn btn-primary settings-update-action" id="updateGeoBases"><i class="bi bi-globe2"></i> Update GeoBases</button>
                    </div>
                    <div id="settingsUpdateStatus" class="settings-update-status" aria-live="polite"></div>
                </section>
            </form>
        </div>
        <div class="modal-footer settings-modal-footer">
            <div id="settingsSaveStatus" class="settings-save-status" aria-live="polite"></div>
            <button type="button" class="btn btn-secondary" id="cancelSettings">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveSettings">Save settings</button>
        </div>
    </div>
</div>
