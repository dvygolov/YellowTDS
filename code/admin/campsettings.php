<?php
require_once __DIR__ . '/securitycheck.php';
require_once __DIR__ . '/campinit.php';
require_once __DIR__ . '/timezones.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../abtest.php';
global $c, $db, $campId;
?>
<!doctype html>
<html lang="en">
<?php include __DIR__.'/head.php' ?>
<link rel="stylesheet" href="<?=get_admin_url_path()?>css/campsettings.css?v=<?=filemtime(__DIR__.'/css/campsettings.css')?>">
<link rel="stylesheet" href="<?=get_admin_url_path()?>css/fileeditor.css?v=<?=filemtime(__DIR__.'/css/fileeditor.css')?>">

<body>
    <?php include __DIR__.'/header.php' ?>
    <div class="all-content-wrapper">
        <div class="camp-layout">
            <nav class="camp-sidebar" data-campaign-id="<?= (int)$campId ?>">
                <div class="camp-name">
                    <span class="camp-name-text"><?= htmlspecialchars($campName) ?></span>
                    <button
                        type="button"
                        class="camp-rename-btn"
                        id="renameCampaign"
                        data-campaign-id="<?= (int)$campId ?>"
                        data-campaign-name="<?= htmlspecialchars($campName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        title="Rename campaign"
                        aria-label="Rename campaign <?= htmlspecialchars($campName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    ><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                </div>
                <ul>
                    <li><a href="#sec-domains" class="active"><i class="bi bi-globe2 campaign-nav-icon" aria-hidden="true"></i>Domains</a></li>
                    <li class="safepage-nav-root nav-tree-parent">
                        <button type="button" class="campaign-nav-toggle" data-tree-toggle="safe-pages" aria-expanded="true" aria-label="Collapse domain-specific safe pages" title="Collapse domain-specific safe pages"><i class="bi bi-dash-square" aria-hidden="true"></i></button>
                        <a href="#sec-safepage"><i class="bi bi-shield-check campaign-nav-icon" aria-hidden="true"></i>Safe Page</a>
                    </li>
                    <?php if ($c->white->domainFilterEnabled) foreach ($c->domains as $di => $domainName) { ?>
                    <li class="dws-nav-item" data-domain="<?= htmlspecialchars($domainName) ?>"><a href="#sec-dws-<?= $di ?>"><?= htmlspecialchars($domainName) ?></a></li>
                    <?php } ?>
                    <li class="flows-nav-root nav-tree-parent">
                        <button type="button" class="campaign-nav-toggle" data-tree-toggle="flows" aria-expanded="true" aria-label="Collapse flows" title="Collapse flows"><i class="bi bi-dash-square" aria-hidden="true"></i></button>
                        <a href="#sec-flows"><i class="bi bi-diagram-3 campaign-nav-icon" aria-hidden="true"></i>Flows</a>
                    </li>
                    <?php foreach ($c->black->flows as $fi => $flow) { ?>
                    <li class="flow-nav-item nav-tree-parent" data-flow-index="<?= $fi ?>" data-flow-key="<?= htmlspecialchars($flow->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <button type="button" class="campaign-nav-toggle" data-tree-toggle="steps" aria-expanded="true" aria-label="Collapse steps for <?= htmlspecialchars($flow->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" title="Collapse steps"><i class="bi bi-dash-square" aria-hidden="true"></i></button>
                        <a href="#sec-flow-<?= $fi ?>"><?= htmlspecialchars($flow->name) ?></a>
                    </li>
                    <?php foreach ($flow->steps as $si => $step) { ?>
                    <li class="step-nav-item" data-flow-index="<?= $fi ?>" data-step-index="<?= $si ?>"><a href="#sec-step-<?= $fi ?>-<?= $si ?>">Step <?= $si + 1 ?></a></li>
                    <?php } ?>
                    <?php } ?>
                    <li><a href="#sec-conversions"><i class="bi bi-bullseye campaign-nav-icon" aria-hidden="true"></i>Conversions</a></li>
                    <li><a href="#sec-events"><i class="bi bi-activity campaign-nav-icon" aria-hidden="true"></i>Events</a></li>
                    <li><a href="#sec-misc"><i class="bi bi-sliders campaign-nav-icon" aria-hidden="true"></i>Misc</a></li>
                    <li><a href="#sec-postbacks"><i class="bi bi-arrow-left-right campaign-nav-icon" aria-hidden="true"></i>Postbacks</a></li>
                    <li><a href="#sec-api"><i class="bi bi-plug campaign-nav-icon" aria-hidden="true"></i>Integration</a></li>
                    <li><a href="#sec-scripts"><i class="bi bi-code-slash campaign-nav-icon" aria-hidden="true"></i>Scripts</a></li>
                </ul>
            </nav>
            <div class="camp-content">
        <form id="campsettings" autocomplete="off">
            <section id="sec-domains" class="camp-section active">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-globe2" aria-hidden="true"></i> Domains</h3>
                <p>Add the domains that route traffic through this campaign.</p>
            </div>
            <div class="flow-group domains-group">
                <span class="flow-group-title">
                    <i class="bi bi-info-circle admin-info-icon" title="Add all of the campaign's domains WITHOUT HTTP(S)! You can use *.xxx.com to match ALL subdomains."></i>Domains list
                </span>
                <div id="domains_container">
                    <?php foreach ($c->domains as $dn) { ?>
                    <div class="form-group-inner domain-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Domain:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control domain-name" value="<?= htmlspecialchars($dn) ?>" placeholder="domain.com" readonly /></div>
                            <div class="col-lg-1 domain-status-col"><i class="bi bi-hourglass-split domain-status" style="color:#94a3b8" title="Checking..."></i></div>
                            <div class="col-lg-2 domain-action-col"><a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn remove-domain-item" title="Delete"><i class="bi bi-trash"></i></a></div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-domain-item" class="btn btn-primary campaign-action-btn" href="javascript:void(0)"><i class="bi bi-plus-circle"></i> Add Domain</a>
            </div>
            </section>

            <section id="sec-safepage" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-shield-check" aria-hidden="true"></i> Safe Page</h3>
                <p>Choose which visitors see the safe page and how it is served.</p>
            </div>
            <div class="flow-group">
            <span class="flow-group-title flow-group-title-with-help">
                <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Traffic matching these filters will be shown the safe page. Everyone else goes to the Flows section." data-tooltip="Traffic matching these filters will be shown the safe page. Everyone else goes to the Flows section."></i>
                Filters
            </span>
            <div class="form-group-inner">
                <div class="row">
                    <div id="filtersbuilder"></div>
                </div>
            </div>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Scope</span>
            <div class="form-group-inner">
                <div class="row">
                    <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Safe page mode:</label></div>
                    <div class="col-lg-9">
                        <div class="ywb-radios">
                            <label class="ywb-radio-label"><input type="radio" <?= !$c->white->domainFilterEnabled ? 'checked' : '' ?> value="false" name="white.domainfilter.use" class="white-scope-radio" /> Global <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Uses the same safe page configuration for every campaign domain." data-tooltip="Uses the same safe page configuration for every campaign domain."></i></label>
                            <label class="ywb-radio-label"><input type="radio" <?= $c->white->domainFilterEnabled ? 'checked' : '' ?> value="true" name="white.domainfilter.use" class="white-scope-radio" /> Domain-Specific <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Creates an independent safe page configuration for each campaign domain." data-tooltip="Creates an independent safe page configuration for each campaign domain."></i></label>
                        </div>
                    </div>
                </div>
            </div>
            </div>

            <div id="global-white-config" style="display:<?= !$c->white->domainFilterEnabled ? 'block' : 'none' ?>">
            <div class="flow-group">
            <span class="flow-group-title">Method</span>
            <div class="form-group-inner">
                <div class="row">
                    <div class="col-lg-3 col-md-6 col-sm-6 col-xs-12">
                        <label for="white-action-select" class="login2 pull-left pull-left-pro">Choose
                            method:</label>
                    </div>
                    <div class="col-lg-5 col-md-6 col-sm-6 col-xs-12">
                        <select id="white-action-select" class="form-select white-action-select" name="white.action">
                            <option value="folder" <?= $c->white->action === 'folder' ? 'selected' : '' ?>>Local safe page from folder</option>
                            <option value="redirect" <?= $c->white->action === 'redirect' ? 'selected' : '' ?>>Redirect</option>
                            <option value="curl" <?= $c->white->action === 'curl' ? 'selected' : '' ?>>Load a website using CURL</option>
                            <option value="error" <?= $c->white->action === 'error' ? 'selected' : '' ?>>Return HTTP-code</option>
                        </select>
                    </div>
                </div>
            </div>
            <div id="b_2" style="display:<?= $c->white->action === 'folder' ? 'block' : 'none' ?>;">
                <div id="white_folder_container">
                    <?php for ($i = 0; $i < count($c->white->folderNames); $i++) {
                            $fn = $c->white->folderNames[$i];
                    ?>
                    <div class="form-group-inner white-folder-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Safe page folder:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control folder-value-input white-folder-name" value="<?= htmlspecialchars($fn) ?>" placeholder="safe1" readonly tabindex="-1" /></div>
                            <div class="col-lg-4"><div class="btn-group campaign-icon-group"><a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn" data-mode="<?= htmlspecialchars($c->white->getLoadMode($fn)) ?>" data-modes="base,rewrite,direct" title="Loading mode"><i class="bi <?= match($c->white->getLoadMode($fn)) { 'rewrite' => 'bi-arrow-repeat', 'direct' => 'bi-hdd-network', default => 'bi-house-door' } ?>"></i></a><a href="javascript:void(0)" class="btn btn-warning white-edit-folder" title="Edit files"><i class="bi bi-pencil-square"></i></a><a href="javascript:void(0)" class="btn btn-danger remove-white-folder-item" title="Delete"><i class="bi bi-trash"></i></a></div></div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn white-add-existing"><i class="bi bi-folder-symlink"></i> Add Existing</a>
                <a href="javascript:void(0)" class="btn btn-info campaign-action-btn white-upload-zip"><i class="bi bi-upload"></i> Upload ZIP</a>
            </div>
            <div id="b_3" style="display:<?= ($c->white->action === 'redirect' ? 'block' : 'none') ?>;">

                <div id="redirect_container">
                    <?php for ($i = 0; $i < max(1, count($c->white->redirectUrls)); $i++) {
                            $ru = $c->white->redirectUrls[$i] ?? '';
                    ?>
                    <div class="form-group-inner redirect-item">
                        <div class="row">
                            <div class="col-lg-3 col-md-12 col-sm-12 col-xs-12">
                                <label class="login2 pull-left pull-left-pro">Redirect address:</label>
                            </div>
                            <div class="col-lg-3 col-md-3 col-sm-3 col-xs-3">
                                <div class="input-group">
                                    <input type="text" class="form-control" placeholder="https://ya.ru" value="<?=$ru?>" name="white.redirect.urls[<?= $i ?>]" />
                                </div>
                            </div>
                            <div class="col-lg-1 col-md-1 col-sm-1 col-xs-1">
                                <a href="javascript:void(0)" class="remove-redirect-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-redirect-item" class="btn btn-primary" href="javascript:;">+ Add Redirect</a>

                <div class="form-group-inner">
                    <div class="row">
                        <div class="col-lg-3 col-md-6 col-sm-6 col-xs-12">
                            <label class="login2 pull-left pull-left-pro">Redirect type:</label>
                        </div>
                        <div class="col-lg-3 col-md-6 col-sm-6 col-xs-12">
                            <select class="form-select" name="white.redirect.type">
                                <?php foreach ([301,302,303,307] as $rt) { ?>
                                <option value="<?= $rt ?>" <?= $c->white->redirectType === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div id="b_4" style="display:<?= $c->white->action === 'curl' ? 'block' : 'none' ?>;">
                <div id="curl_container">
                    <?php for ($i = 0; $i < max(1, count($c->white->curlUrls)); $i++) {
                            $cu = $c->white->curlUrls[$i] ?? '';
                    ?>
                    <div class="form-group-inner curl-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Curl address:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control white-curl-url" placeholder="https://ya.ru" value="<?=$cu?>" name="white.curls[<?= $i ?>]" /></div>
                            <div class="col-lg-2"><div class="btn-group campaign-icon-group"><a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn" data-mode="<?= htmlspecialchars($c->white->getLoadMode($cu)) ?>" data-modes="rewrite,direct" title="Loading mode"><i class="bi <?= $c->white->getLoadMode($cu) === 'direct' ? 'bi-hdd-network' : 'bi-arrow-repeat' ?>"></i></a><a href="javascript:void(0)" class="btn btn-danger remove-curl-item" title="Delete"><i class="bi bi-trash"></i></a></div></div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-curl-item" class="btn btn-primary" href="javascript:;">+ Add Curl</a>
            </div>
            <div id="b_5" style="display:<?= $c->white->action === 'error' ? 'block' : 'none' ?>;">
                <div id="errorcodes_container">
                    <?php for ($i = 0; $i < max(1, count($c->white->errorCodes)); $i++) {
                            $ec = $c->white->errorCodes[$i] ?? '';
                    ?>
                    <div class="form-group-inner errorcode-item">
                        <div class="row">
                            <div class="col-lg-3 col-md-12 col-sm-12 col-xs-12">
                                <label class="login2 pull-left pull-left-pro">HTTP code: <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Examples: 404 Not Found or 200 OK." data-tooltip="Examples: 404 Not Found or 200 OK."></i></label>
                            </div>
                            <div class="col-lg-3 col-md-3 col-sm-3 col-xs-3">
                                <div class="input-group">
                                    <input type="text" class="form-control" placeholder="404" value="<?=$ec?>" name="white.errorcodes[<?= $i ?>]" />
                                </div>
                            </div>
                            <div class="col-lg-1 col-md-1 col-sm-1 col-xs-1">
                                <a href="javascript:void(0)" class="remove-errorcode-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-errorcode-item" class="btn btn-primary" href="javascript:;">+ Add HTTP Code</a>
            </div>
            </div>

            </div><!-- /#global-white-config -->

            </section>

            <?php
            // Build a lookup map: domain => DomainWhiteSettings
            $dwsMap = [];
            foreach ($c->white->domainSpecific as $dws) {
                $dwsMap[$dws->domain] = $dws;
            }
            foreach ($c->domains as $di => $domainName) {
                $dws = $dwsMap[$domainName] ?? null;
                $dwAction = $dws ? $dws->action : 'folder';
            ?>
            <section id="sec-dws-<?= $di ?>" class="camp-section dws-section" data-domain="<?= htmlspecialchars($domainName) ?>">
            <h5 class="dws-page-title"><i class="bi bi-globe2" aria-hidden="true"></i><?= htmlspecialchars($domainName) ?> — Safe Page</h5>

            <div class="flow-group dws-method-group">
            <span class="flow-group-title">Method</span>
            <div class="form-group-inner">
                <div class="row">
                    <div class="col-lg-3 col-md-6 col-sm-6 col-xs-12">
                        <label for="dws-action-<?= $di ?>" class="login2 pull-left pull-left-pro">Choose method:</label>
                    </div>
                    <div class="col-lg-5 col-md-6 col-sm-6 col-xs-12">
                        <select id="dws-action-<?= $di ?>" name="dws_action_<?= $di ?>" class="form-select dws-action" data-di="<?= $di ?>">
                            <option value="folder" <?= $dwAction === 'folder' ? 'selected' : '' ?>>Local safe page from folder</option>
                            <option value="redirect" <?= $dwAction === 'redirect' ? 'selected' : '' ?>>Redirect</option>
                            <option value="curl" <?= $dwAction === 'curl' ? 'selected' : '' ?>>Load a website using CURL</option>
                            <option value="error" <?= $dwAction === 'error' ? 'selected' : '' ?>>Return HTTP-code</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="dws-folder-block" data-di="<?= $di ?>" style="display:<?= $dwAction === 'folder' ? 'block' : 'none' ?>">
                <div class="dws-folder-items">
                <?php if ($dws) foreach ($dws->folderNames as $fn) { ?>
                    <div class="form-group-inner dws-folder-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Safe page folder:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control folder-value-input dws-folder-name" value="<?= htmlspecialchars($fn) ?>" readonly tabindex="-1" /></div>
                            <div class="col-lg-4"><div class="btn-group campaign-icon-group">
                                <a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn" data-mode="<?= htmlspecialchars($dws->getLoadMode($fn)) ?>" data-modes="base,rewrite,direct" title="Loading mode"><i class="bi <?= match($dws->getLoadMode($fn)) { 'rewrite' => 'bi-arrow-repeat', 'direct' => 'bi-hdd-network', default => 'bi-house-door' } ?>"></i></a>
                                <a href="javascript:void(0)" class="btn btn-warning dws-edit-folder" title="Edit files"><i class="bi bi-pencil-square"></i></a>
                                <a href="javascript:void(0)" class="btn btn-danger dws-remove-folder" title="Delete"><i class="bi bi-trash"></i></a>
                            </div></div>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn dws-add-existing" data-di="<?= $di ?>"><i class="bi bi-folder-symlink"></i> Add Existing</a>
                <a href="javascript:void(0)" class="btn btn-info campaign-action-btn dws-upload-zip" data-di="<?= $di ?>"><i class="bi bi-upload"></i> Upload ZIP</a>
            </div>

            <div class="dws-redirect-block" data-di="<?= $di ?>" style="display:<?= $dwAction === 'redirect' ? 'block' : 'none' ?>">
                <div class="dws-redirect-items">
                <?php if ($dws) foreach ($dws->redirectUrls as $ru) { ?>
                    <div class="form-group-inner dws-redirect-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect address:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control dws-redirect-url" value="<?= htmlspecialchars($ru) ?>" placeholder="https://example.com" /></div>
                            <div class="col-lg-1"><a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn dws-remove-redirect" title="Delete"><i class="bi bi-trash"></i></a></div>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn dws-add-redirect" data-di="<?= $di ?>">+ Add Redirect</a>
                <div class="form-group-inner" style="margin-top:10px">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect type:</label></div>
                        <div class="col-lg-3"><select class="form-select dws-redirect-type">
                            <?php $dwRt = $dws ? $dws->redirectType : 302; foreach ([301,302,303,307] as $rt) { ?>
                            <option value="<?= $rt ?>" <?= $dwRt === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                            <?php } ?>
                        </select></div>
                    </div>
                </div>
            </div>

            <div class="dws-curl-block" data-di="<?= $di ?>" style="display:<?= $dwAction === 'curl' ? 'block' : 'none' ?>">
                <div class="dws-curl-items">
                <?php if ($dws) foreach ($dws->curlUrls as $cu) { ?>
                    <div class="form-group-inner dws-curl-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Curl address:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control dws-curl-url" value="<?= htmlspecialchars($cu) ?>" placeholder="https://example.com" /></div>
                            <div class="col-lg-2"><div class="btn-group campaign-icon-group">
                                <a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn" data-mode="<?= htmlspecialchars($dws->getLoadMode($cu)) ?>" data-modes="rewrite,direct" title="Loading mode"><i class="bi <?= $dws->getLoadMode($cu) === 'direct' ? 'bi-hdd-network' : 'bi-arrow-repeat' ?>"></i></a>
                                <a href="javascript:void(0)" class="btn btn-danger dws-remove-curl" title="Delete"><i class="bi bi-trash"></i></a>
                            </div></div>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn dws-add-curl" data-di="<?= $di ?>">+ Add Curl</a>
            </div>

            <div class="dws-error-block" data-di="<?= $di ?>" style="display:<?= $dwAction === 'error' ? 'block' : 'none' ?>">
                <div class="dws-error-items">
                <?php if ($dws) foreach ($dws->errorCodes as $ec) { ?>
                    <div class="form-group-inner dws-error-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">HTTP code: <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Examples: 404 Not Found or 200 OK." data-tooltip="Examples: 404 Not Found or 200 OK."></i></label></div>
                            <div class="col-lg-3"><input type="text" class="form-control dws-error-code" value="<?= htmlspecialchars($ec) ?>" placeholder="404" /></div>
                            <div class="col-lg-1"><a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn dws-remove-error" title="Delete"><i class="bi bi-trash"></i></a></div>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn dws-add-error" data-di="<?= $di ?>">+ Add HTTP Code</a>
            </div>
            </div><!-- /.dws-method-group -->

            </section><!-- /sec-dws -->
            <?php } ?>

            <section id="sec-flows" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-diagram-3" aria-hidden="true"></i> Flows</h3>
                <p>Create and prioritize the routes and funnel steps used by this campaign.</p>
            </div>
            <div class="form-group-inner">
                <p>Flows are processed top-to-bottom. First flow whose filters match the visitor gets the traffic. Empty filters = catch-all.</p>
                <div id="flows-list">
                <?php foreach ($c->black->flows as $fi => $flow) { ?>
                    <div class="flow-list-row" data-flow-index="<?= $fi ?>">
                        <button type="button" class="reorder-handle flow-drag-handle" title="Drag to reorder" aria-label="Reorder <?= htmlspecialchars($flow->name) ?>. Drag or use the arrow keys."><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
                        <input type="text" class="form-control flow-name-label" value="<?= htmlspecialchars($flow->name) ?>" readonly />
                        <a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn flow-delete" title="Delete"><i class="bi bi-trash"></i></a>
                    </div>
                <?php } ?>
                </div>
                <a id="add-flow-btn" class="btn btn-primary campaign-action-btn" href="javascript:void(0)" style="margin-top:15px;">+ Add Flow</a>
            </div>
            <hr/>
            <div class="campaign-setting-row">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Keeps the same step variants when the visitor returns to the same flow." data-tooltip="Keeps the same step variants when the visitor returns to the same flow."></i>
                    <span>Save user path (Sticky)</span>
                </div>
                <input type="hidden" id="save-user-flow-value" name="saveuserflow" value="<?= $c->saveUserFlow ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="save-user-flow-toggle">
                    <input
                        type="checkbox"
                        id="save-user-flow-toggle"
                        class="campaign-switch-input"
                        data-value-target="save-user-flow-value"
                        aria-label="Save user path"
                        <?= $c->saveUserFlow ? 'checked' : '' ?>
                    />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>

            <?php $jbd = $c->black->jsBotDetection; ?>
            <div class="campaign-setting-row campaign-setting-row-separated">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Shows the safe page first, then opens the routed page only after browser-side checks confirm a real visitor." data-tooltip="Shows the safe page first, then opens the routed page only after browser-side checks confirm a real visitor."></i>
                    <span>JS Bot Detection</span>
                </div>
                <input type="hidden" id="js-bot-detection-value" name="black.jsbotdetection.enabled" value="<?= $jbd->enabled ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="js-bot-detection-toggle">
                    <input
                        type="checkbox"
                        id="js-bot-detection-toggle"
                        class="campaign-switch-input"
                        data-value-target="js-bot-detection-value"
                        data-controls="jbd-settings"
                        aria-label="Enable JS Bot Detection"
                        aria-controls="jbd-settings"
                        <?= $jbd->enabled ? 'checked' : '' ?>
                    />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="jbd-settings" class="campaign-dependent-settings" <?= $jbd->enabled ? '' : 'hidden' ?>>
                <div class="form-group-inner">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Timeout (msec):</label></div>
                        <div class="col-lg-3"><input type="text" class="form-control" placeholder="10000" name="black.jsbotdetection.timeout" value="<?= $jbd->timeout ?>" /></div>
                    </div>
                </div>
                <div class="form-group-inner">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Tests:</label></div>
                        <div class="col-lg-9"><div class="ywb-radios">
                            <?php foreach (['pointerdown' => 'Mouse click / Touch start', 'keydown' => 'Text typing', 'devicemotion' => 'Device motion (Android only)', 'deviceorientation' => 'Device orientation (Android only)', 'audiocontext' => 'Audio engine existence', 'timezone' => 'Time zone', 'browserscreen' => 'Browser screen', 'webdriver' => 'WebDriver automation'] as $ev => $evLabel) { ?>
                            <label class="ywb-radio-label"><input type="checkbox" name="black.jsbotdetection.events[]" value="<?= $ev ?>" <?= in_array($ev, $jbd->events) ? 'checked' : '' ?> <?= $ev === 'timezone' ? 'onchange="(document.getElementById(\'jbd-tz\').style.display = this.checked ? \'block\' : \'none\')"' : '' ?> /> <?= $evLabel ?></label>
                            <?php } ?>
                        </div></div>
                    </div>
                </div>
                <div id="jbd-tz" class="form-group-inner" style="display:<?= in_array('timezone', $jbd->events) ? 'block' : 'none' ?>;">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Minimum allowed timezone</label></div>
                        <div class="col-lg-3"><input type="text" class="form-control" placeholder="-3" name="black.jsbotdetection.timezone.min" value="<?= $jbd->tzMin ?>" /></div>
                    </div>
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Maximum allowed timezone</label></div>
                        <div class="col-lg-3"><input type="text" class="form-control" placeholder="3" name="black.jsbotdetection.timezone.max" value="<?= $jbd->tzMax ?>" /></div>
                    </div>
                </div>
            </div>
            </section>

            <?php foreach ($c->black->flows as $fi => $flow) { ?>
            <section id="sec-flow-<?= $fi ?>" class="camp-section flow-section" data-flow-index="<?= $fi ?>">
            <h5 class="flow-section-title"><?= htmlspecialchars($flow->name) ?></h5>

            <div class="flow-group">
            <span class="flow-group-title">Flow Filters</span>
            <div class="form-group-inner">
                <div class="row">
                    <div id="flow-filters-<?= $fi ?>"></div>
                </div>
            </div>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Distribution</span>
            <div class="form-group-inner">
                <select class="form-select flow-dist" data-fi="<?= $fi ?>">
                    <option value="equal" <?= $flow->distribution === 'equal' ? 'selected' : '' ?>>Equal</option>
                    <option value="weighted" <?= $flow->distribution === 'weighted' ? 'selected' : '' ?>>Weighted</option>
                    <option value="thompson" <?= $flow->distribution === 'thompson' ? 'selected' : '' ?>>Thompson Sampling</option>
                </select>
            </div>
            <div class="flow-thompson-opts" id="flow-thompson-opts-<?= $fi ?>" style="display:<?= $flow->distribution === 'thompson' ? 'block' : 'none' ?>">
                <div class="form-group-inner">
                    <label class="login2 pull-left pull-left-pro">Optimize for:</label>
                    <div class="ywb-radios">
                        <label class="ywb-radio-label"><input type="radio" <?= $flow->optimize_for === 'Lead' ? 'checked' : '' ?> value="Lead" name="flow_<?= $fi ?>_optimize_for" class="flow-optimize-for" data-fi="<?= $fi ?>" /> Lead</label>
                        <label class="ywb-radio-label"><input type="radio" <?= $flow->optimize_for === 'Purchase' ? 'checked' : '' ?> value="Purchase" name="flow_<?= $fi ?>_optimize_for" class="flow-optimize-for" data-fi="<?= $fi ?>" /> Purchase</label>
                    </div>
                </div>
                <div class="form-group-inner flow-optimize-mode-wrap" id="flow-optimize-mode-wrap-<?= $fi ?>" style="display:<?= $flow->hasMultipleSteps() ? 'block' : 'none' ?>">
                    <label class="login2 pull-left pull-left-pro">Optimize mode:</label>
                    <div class="ywb-radios">
                        <label class="ywb-radio-label"><input type="radio" <?= $flow->optimize_mode === 'funnels' ? 'checked' : '' ?> value="funnels" name="flow_<?= $fi ?>_optimize_mode" class="flow-optimize-mode" data-fi="<?= $fi ?>" /> Funnels (step combos)</label>
                        <label class="ywb-radio-label"><input type="radio" <?= $flow->optimize_mode === 'separate' ? 'checked' : '' ?> value="separate" name="flow_<?= $fi ?>_optimize_mode" class="flow-optimize-mode" data-fi="<?= $fi ?>" /> Separate (independent per step)</label>
                    </div>
                </div>
                <?php
                // ── Win Probability computation ──
                if ($flow->distribution === 'thompson') {
                    $totalImp = 0;
                    $isFunnel = $flow->hasMultipleSteps() && $flow->optimize_mode === 'funnels';

                    if ($isFunnel) {
                        $stats = $db->get_funnel_stats($campId, $flow->name, $flow->optimize_for);
                        $statsMap = [];
                        foreach ($stats as $row) {
                            $pathArr = json_decode($row['path'], true);
                            $key = is_array($pathArr) ? implode(' → ', $pathArr) : $row['path'];
                            $statsMap[$key] = ['imp' => (int)$row['impressions'], 'conv' => (int)$row['conversions']];
                            $totalImp += (int)$row['impressions'];
                        }
                        $winProbData = AbTest::compute_win_probabilities($statsMap);
                    } else {
                        // Separate mode: per-step probabilities
                        $stepWinProbs = [];
                        foreach ($flow->steps as $si => $step) {
                            $curItems = $step->getItems();
                            if (count($curItems) < 2) continue;
                            $sStats = $db->get_variant_stats($campId, $flow->name, $si, $flow->optimize_for);
                            $sMap = [];
                            foreach ($sStats as $row) {
                                if (!in_array($row['variant'], $curItems, true)) continue;
                                $sMap[$row['variant']] = ['imp' => (int)$row['impressions'], 'conv' => (int)$row['conversions']];
                                $totalImp += (int)$row['impressions'];
                            }
                            $probs = AbTest::compute_win_probabilities($sMap);
                            if (!empty($probs)) $stepWinProbs[$si] = $probs;
                        }
                    }
                ?>
                <div class="flow-winprob">
                    <?php if ($totalImp < 10) { ?>
                        <div class="winprob-empty">Not enough data yet (<?= $totalImp ?> impressions)</div>
                    <?php } elseif ($isFunnel && !empty($winProbData)) { ?>
                        <div class="winprob-section-title">Funnel Win Probability</div>
                        <?php $isFirst = true; foreach ($winProbData as $variant => $prob) { ?>
                        <div class="winprob-row <?= $isFirst ? 'winprob-leader' : '' ?>">
                            <span class="winprob-name"><?= htmlspecialchars($variant) ?></span>
                            <div class="winprob-bar-wrap">
                                <div class="winprob-bar" style="width: <?= max($prob, 2) ?>%"></div>
                            </div>
                            <span class="winprob-pct"><?= $prob ?>%</span>
                        </div>
                        <?php $isFirst = false; } ?>
                    <?php } elseif (!$isFunnel && !empty($stepWinProbs)) { ?>
                        <?php foreach ($stepWinProbs as $si => $probs) { ?>
                        <div class="winprob-section-title">Step <?= $si + 1 ?> Win Probability</div>
                        <?php $isFirst = true; foreach ($probs as $variant => $prob) { ?>
                        <div class="winprob-row <?= $isFirst ? 'winprob-leader' : '' ?>">
                            <span class="winprob-name"><?= htmlspecialchars($variant) ?></span>
                            <div class="winprob-bar-wrap">
                                <div class="winprob-bar" style="width: <?= max($prob, 2) ?>%"></div>
                            </div>
                            <span class="winprob-pct"><?= $prob ?>%</span>
                        </div>
                        <?php $isFirst = false; } ?>
                        <?php } ?>
                    <?php } else { ?>
                        <div class="winprob-empty">No variant data collected yet</div>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
            </div>

            <?php $hasRedirect = false; foreach ($flow->steps as $s) { if ($s->action === 'redirect') { $hasRedirect = true; break; } } ?>
            <div class="flow-group">
            <span class="flow-group-title">Steps</span>
            <div id="steps-list-<?= $fi ?>" class="steps-list">
                <?php $lastSi = count($flow->steps) - 1; foreach ($flow->steps as $si => $step) { $isStepRedirect = ($step->action === 'redirect'); ?>
                <div class="step-list-row" data-flow-index="<?= $fi ?>" data-step-index="<?= $si ?>">
                    <button type="button" class="reorder-handle step-drag-handle<?= $isStepRedirect ? ' is-disabled' : '' ?>" title="<?= $isStepRedirect ? 'Redirect must remain the last step' : 'Drag to reorder' ?>" aria-label="Reorder Step <?= $si + 1 ?>. Drag or use the arrow keys."<?= $isStepRedirect ? ' aria-disabled="true"' : '' ?>><i class="bi bi-grip-vertical" aria-hidden="true"></i></button>
                    <span class="step-list-label">Step <?= $si + 1 ?></span>
                    <span class="step-list-info"><?php
                        if ($step->action === 'redirect' && !empty($step->redirectUrls)) {
                            $hosts = array_map(function($ru) {
                                $u = is_array($ru) ? ($ru['url'] ?? '') : $ru;
                                try { $h = parse_url($u, PHP_URL_HOST); return $h ? preg_replace('/^www\./', '', $h) : 'redirect'; } catch (\Exception $e) { return 'redirect'; }
                            }, $step->redirectUrls);
                            echo htmlspecialchars(implode(', ', $hosts));
                        } elseif ($step->action === 'redirect') {
                            echo 'redirect';
                        } else {
                            $stepItems = $step->getItems();
                            echo count($stepItems) ? htmlspecialchars(implode(', ', $stepItems)) : 'empty';
                        }
                    ?></span>
                    <a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn flow-remove-step" title="Delete"><i class="bi bi-trash"></i></a>
                </div>
                <?php } ?>
            </div>
            <div style="margin-top:10px;">
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-add-step" data-fi="<?= $fi ?>"<?= $hasRedirect ? ' style="pointer-events:none;opacity:0.5" aria-disabled="true"' : '' ?>><i class="bi bi-plus-circle"></i> Add Step</a>
            </div>
            </div>
            </section>

            <?php $lastSi = count($flow->steps) - 1; foreach ($flow->steps as $si => $step) { $isLast = ($si === $lastSi); ?>
            <section id="sec-step-<?= $fi ?>-<?= $si ?>" class="camp-section step-section" data-flow-index="<?= $fi ?>" data-step-index="<?= $si ?>">
            <h5 class="flow-section-title"><?= htmlspecialchars($flow->name) ?> &rsaquo; Step <?= $si + 1 ?></h5>

            <div class="flow-group">
            <span class="flow-group-title">Action</span>
            <div class="form-group-inner">
                <div class="ywb-radios">
                    <label class="ywb-radio-label">
                        <input type="radio" <?= ($step->action === 'folder' || !$isLast) ? 'checked' : '' ?> value="folder" name="flow_<?= $fi ?>_step_<?= $si ?>_action" class="flow-step-action" data-fi="<?= $fi ?>" data-si="<?= $si ?>" <?= !$isLast ? 'disabled' : '' ?> /> Local page(s) from folder
                    </label>
                    <label class="ywb-radio-label">
                        <input type="radio" <?= ($step->action === 'redirect' && $isLast) ? 'checked' : '' ?> value="redirect" name="flow_<?= $fi ?>_step_<?= $si ?>_action" class="flow-step-action" data-fi="<?= $fi ?>" data-si="<?= $si ?>" <?= !$isLast ? 'disabled' : '' ?> /> Redirect(s)
                    </label>
                </div>
                <?php if (!$isLast) { ?>
                <p class="step-action-hint" style="font-size:12px;margin-top:6px;">Only the last step can use redirects.</p>
                <?php } ?>
            </div>
            </div>

            <div class="flow-step-folders" style="display:<?= $step->action === 'folder' ? 'block' : 'none' ?>">
            <div class="flow-group">
            <span class="flow-group-title">Folders</span>
                <div class="flow-step-folder-items">
                <?php foreach ($step->folders as $ii => $folder) {
                    $fn = $folder->name;
                    $mvtJson = json_encode($folder->mvt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                ?>
                    <div class="form-group-inner flow-path-item" data-mvt="<?= htmlspecialchars($mvtJson ?: '{}', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Folder:</label></div>
                            <div class="col-lg-3"><input type="text" class="form-control folder-value-input flow-step-folder" value="<?= htmlspecialchars($fn) ?>" placeholder="folder1" readonly tabindex="-1" /></div>
                            <div class="col-lg-2 flow-weight-col" style="display:<?= $flow->distribution === 'weighted' ? 'block' : 'none' ?>">
                                <input type="number" step="1" class="form-control flow-step-weight" value="<?= $folder->weight ?>" placeholder="%" style="width:70px" />
                            </div>
                            <div class="col-lg-3"><div class="btn-group campaign-icon-group"><a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn flow-step-mode" data-mode="<?= $step->isDirectLoad($fn) ? 'direct' : 'base' ?>" data-modes="base,direct" title="Loading mode"><i class="bi <?= $step->isDirectLoad($fn) ? 'bi-hdd-network' : 'bi-house-door' ?>"></i></a><a href="javascript:void(0)" class="btn btn-warning flow-edit-folder" title="Edit files"><i class="bi bi-pencil-square"></i></a><a href="javascript:void(0)" class="btn btn-danger flow-remove-step-item" title="Delete"><i class="bi bi-trash"></i></a></div></div>
                        </div>
                        <div class="flow-mvt-panel"></div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-step-add-existing" data-fi="<?= $fi ?>" data-si="<?= $si ?>"><i class="bi bi-folder-symlink"></i> Add Existing</a>
                <a href="javascript:void(0)" class="btn btn-info campaign-action-btn flow-step-upload-zip" data-fi="<?= $fi ?>" data-si="<?= $si ?>"><i class="bi bi-upload"></i> Upload ZIP</a>
            </div>
            </div>

            <div class="flow-step-redirects" style="display:<?= $step->action === 'redirect' ? 'block' : 'none' ?>">
            <div class="flow-group">
            <span class="flow-group-title">Redirects</span>
                <div class="flow-step-redirect-items">
                <?php foreach ($step->redirectUrls as $ri => $ru) { ?>
                    <div class="form-group-inner flow-path-item">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect URL:</label></div>
                            <div class="col-lg-4"><input type="text" class="form-control flow-step-redirect" value="<?= htmlspecialchars(is_array($ru) ? $ru['url'] : $ru) ?>" placeholder="https://..." /></div>
                            <div class="col-lg-2 flow-weight-col" style="display:<?= $flow->distribution === 'weighted' ? 'block' : 'none' ?>">
                                <input type="number" step="1" class="form-control flow-step-weight" value="<?= (int)($ru['weight'] ?? 0) ?>" placeholder="%" style="width:70px" />
                            </div>
                            <div class="col-lg-1"><a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn flow-remove-step-item" title="Delete"><i class="bi bi-trash"></i></a></div>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-step-add-redirect" data-fi="<?= $fi ?>" data-si="<?= $si ?>">+ Add Redirect</a>
                <div class="form-group-inner" style="margin-top:10px">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect type:</label></div>
                        <div class="col-lg-3">
                            <select class="form-select flow-step-redirect-type" data-fi="<?= $fi ?>" data-si="<?= $si ?>">
                                <?php foreach ([301,302,303,307] as $rt) { ?>
                                <option value="<?= $rt ?>" <?= $step->redirectType === $rt ? 'selected' : '' ?>><?= $rt ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </section>
            <?php } ?>
            <?php } ?>

            <section id="sec-scripts" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-code-slash" aria-hidden="true"></i> Scripts</h3>
                <p>Configure browser-side behavior for landing pages and funnel transitions.</p>
            </div>
            <div class="flow-group">
            <span class="flow-group-title">Backfix</span>
            <div class="campaign-setting-row script-switch-row">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle admin-info-icon" title="Backfix prevents the visitor from returning to the previous page and shows another configured page instead."></i>
                    <span>Use Backfix</span>
                </div>
                <input type="hidden" id="scripts-backfix-use" name="scripts.backfix.use" value="<?= $c->scripts->backfix ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="scripts-backfix-toggle">
                    <input type="checkbox" id="scripts-backfix-toggle" class="campaign-switch-input" data-value-target="scripts-backfix-use" data-controls="b_backfix" aria-controls="b_backfix" aria-label="Use Backfix" <?= $c->scripts->backfix ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="b_backfix" class="campaign-dependent-settings script-dependent-settings" <?= $c->scripts->backfix ? '' : 'hidden' ?>>
                <div id="backfix_urls_container">
                    <?php
                    $bfCount = max(count($c->scripts->backfixUrls), 1);
                    for ($i = 0; $i < $bfCount; $i++) {
                            $bu = $c->scripts->backfixUrls[$i] ?? '';
                    ?>
                    <div class="form-group-inner backfix-url-item">
                        <div class="row">
                            <div class="col-lg-3 col-md-12 col-sm-12 col-xs-12">
                                <label class="login2 pull-left pull-left-pro">Backfix URL:</label>
                            </div>
                            <div class="col-lg-6 col-md-6 col-sm-6 col-xs-6">
                                <div class="input-group">
                                    <input type="text" class="form-control" placeholder="http://ya.ru?pixel={px}&clickid={clickid}" value="<?= $bu ?>" name="scripts.backfix.urls[<?= $i ?>]" />
                                </div>
                            </div>
                            <div class="col-lg-1 col-md-1 col-sm-1 col-xs-1">
                                <a href="javascript:void(0)" class="remove-backfix-url-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-backfix-url-item" class="btn btn-primary" href="javascript:;">+ Add Backfix URL</a>
            </div>
            </div>
            <?php
                $scriptFlowNames = array_map(fn($flow) => $flow->name, $c->black->flows);
                $scriptFlowStepCounts = [];
                foreach ($c->black->flows as $flow) {
                    $scriptFlowStepCounts[$flow->name] = count($flow->steps);
                }
                $scriptMaxStepCount = empty($scriptFlowStepCounts) ? 0 : max($scriptFlowStepCounts);
                $scriptFlowOptionsHtml = '<option value="*">Any flow</option>';
                foreach ($scriptFlowNames as $flowName) {
                    $scriptFlowOptionsHtml .= '<option value="' . htmlspecialchars($flowName, ENT_QUOTES) . '">' . htmlspecialchars($flowName) . '</option>';
                }
                $nextRedirectRules = $c->scripts->nextRedirectRules;
                $submitRedirectRules = $c->scripts->submitRedirectRules;
                if ($c->scripts->nextRedirectUse && empty($nextRedirectRules)) {
                    $nextRedirectRules[] = ['flow' => '*', 'steps' => '*', 'url' => ''];
                }
                if ($c->scripts->submitRedirectUse && empty($submitRedirectRules)) {
                    $submitRedirectRules[] = ['flow' => '*', 'steps' => '*', 'url' => ''];
                }
            ?>

            <div class="flow-group">
            <span class="flow-group-title">Next Step Redirect</span>
            <div class="campaign-setting-row script-switch-row">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle script-info-icon" title="If a rule matches the current flow and step, the next step opens in a new tab and the current tab is redirected to the rule URL."></i>
                    <span>Enable Next Step Redirect</span>
                </div>
                <input type="hidden" id="scripts-next-redirect-use" name="scripts.nextredirect.use" value="<?= $c->scripts->nextRedirectUse ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="scripts-next-redirect-toggle">
                    <input type="checkbox" id="scripts-next-redirect-toggle" class="campaign-switch-input" data-value-target="scripts-next-redirect-use" data-controls="next_redirect_rules_block" aria-controls="next_redirect_rules_block" aria-label="Enable Next Step Redirect" <?= $c->scripts->nextRedirectUse ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="next_redirect_rules_block" class="campaign-dependent-settings script-dependent-settings" <?= $c->scripts->nextRedirectUse ? '' : 'hidden' ?>>
                <div id="next_redirect_rules_container" class="script-rules-container">
                    <?php foreach ($nextRedirectRules as $ri => $rule) {
                        $ruleFlow = (string)($rule['flow'] ?? '*');
                        $ruleSteps = $rule['steps'] ?? '*';
                        $ruleStepsValue = $ruleSteps === '*' ? '*' : implode(',', $ruleSteps);
                        $ruleUrl = (string)($rule['url'] ?? '');
                    ?>
                    <div class="form-group-inner script-rule-item" data-rule-kind="next">
                        <div class="row script-rule-header-row">
                            <div class="col-lg-2"><label class="login2 pull-left pull-left-pro">Flow</label></div>
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Steps</label></div>
                            <div class="col-lg-6"><label class="login2 pull-left pull-left-pro">Redirect current tab to</label></div>
                        </div>
                        <div class="row script-rule-body-row">
                            <div class="col-lg-2 col-md-4 col-sm-12 col-xs-12">
                                <select class="form-select script-rule-flow" data-rule-kind="next" name="scripts.nextredirect.rules[<?= $ri ?>][flow]">
                                    <?= str_replace('value="' . htmlspecialchars($ruleFlow, ENT_QUOTES) . '"', 'value="' . htmlspecialchars($ruleFlow, ENT_QUOTES) . '" selected', $scriptFlowOptionsHtml) ?>
                                </select>
                            </div>
                            <div class="col-lg-3 col-md-4 col-sm-12 col-xs-12">
                                <input type="hidden" class="script-rule-steps-value" data-rule-kind="next" name="scripts.nextredirect.rules[<?= $ri ?>][steps]" value="<?= htmlspecialchars($ruleStepsValue, ENT_QUOTES) ?>" />
                                <div class="script-step-chips" data-rule-kind="next"></div>
                            </div>
                            <div class="col-lg-6 col-md-3 col-sm-12 col-xs-12">
                                <input type="text" class="form-control" name="scripts.nextredirect.rules[<?= $ri ?>][url]" value="<?= htmlspecialchars($ruleUrl, ENT_QUOTES) ?>" placeholder="https://example.com/path?clickid={clickid}" />
                            </div>
                            <div class="col-lg-1 col-md-1 col-sm-12 col-xs-12 script-rule-remove-col">
                                <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                                <a href="javascript:void(0)" class="remove-script-rule-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-next-redirect-rule" class="btn btn-primary" href="javascript:;">+ Add Rule</a>
            </div>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Form Submit Redirect</span>
            <div class="campaign-setting-row script-switch-row">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle script-info-icon" title="If a rule matches the current flow and terminal step, form submit opens in a new tab and the current tab is redirected to the rule URL."></i>
                    <span>Enable Form Submit Redirect</span>
                </div>
                <input type="hidden" id="scripts-submit-redirect-use" name="scripts.submitredirect.use" value="<?= $c->scripts->submitRedirectUse ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="scripts-submit-redirect-toggle">
                    <input type="checkbox" id="scripts-submit-redirect-toggle" class="campaign-switch-input" data-value-target="scripts-submit-redirect-use" data-controls="submit_redirect_rules_block" aria-controls="submit_redirect_rules_block" aria-label="Enable Form Submit Redirect" <?= $c->scripts->submitRedirectUse ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="submit_redirect_rules_block" class="campaign-dependent-settings script-dependent-settings" <?= $c->scripts->submitRedirectUse ? '' : 'hidden' ?>>
                <div id="submit_redirect_rules_container" class="script-rules-container">
                    <?php foreach ($submitRedirectRules as $ri => $rule) {
                        $ruleFlow = (string)($rule['flow'] ?? '*');
                        $ruleSteps = $rule['steps'] ?? '*';
                        $ruleStepsValue = $ruleSteps === '*' ? '*' : implode(',', $ruleSteps);
                        $ruleUrl = (string)($rule['url'] ?? '');
                    ?>
                    <div class="form-group-inner script-rule-item" data-rule-kind="submit">
                    <div class="row script-rule-header-row">
                        <div class="col-lg-2"><label class="login2 pull-left pull-left-pro">Flow</label></div>
                        <div class="col-lg-8"><label class="login2 pull-left pull-left-pro">Redirect current tab to</label></div>
                    </div>
                    <div class="row script-rule-body-row">
                        <div class="col-lg-2 col-md-4 col-sm-12 col-xs-12">
                            <select class="form-select script-rule-flow" data-rule-kind="submit" name="scripts.submitredirect.rules[<?= $ri ?>][flow]">
                                <?= str_replace('value="' . htmlspecialchars($ruleFlow, ENT_QUOTES) . '"', 'value="' . htmlspecialchars($ruleFlow, ENT_QUOTES) . '" selected', $scriptFlowOptionsHtml) ?>
                            </select>
                        </div>
                            <input type="hidden" class="script-rule-steps-value" data-rule-kind="submit" name="scripts.submitredirect.rules[<?= $ri ?>][steps]" value="*" />
                            <div class="col-lg-8 col-md-7 col-sm-12 col-xs-12">
                                <input type="text" class="form-control" name="scripts.submitredirect.rules[<?= $ri ?>][url]" value="<?= htmlspecialchars($ruleUrl, ENT_QUOTES) ?>" placeholder="https://example.com/path?clickid={clickid}" />
                            </div>
                            <div class="col-lg-1 col-md-1 col-sm-12 col-xs-12 script-rule-remove-col">
                                <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                                <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                                <a href="javascript:void(0)" class="remove-script-rule-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php } ?>
                </div>
                <a id="add-submit-redirect-rule" class="btn btn-primary" href="javascript:;">+ Add Rule</a>
            </div>
            </div>

            <template id="script-rule-template-next">
                <div class="form-group-inner script-rule-item" data-rule-kind="__KIND__">
                    <div class="row script-rule-header-row">
                        <div class="col-lg-2"><label class="login2 pull-left pull-left-pro">Flow</label></div>
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Steps</label></div>
                        <div class="col-lg-6"><label class="login2 pull-left pull-left-pro">Redirect current tab to</label></div>
                    </div>
                    <div class="row script-rule-body-row">
                        <div class="col-lg-2 col-md-4 col-sm-12 col-xs-12">
                            <select class="form-select script-rule-flow" data-rule-kind="__KIND__" name="scripts.__FIELD__.rules[__INDEX__][flow]">
                                <?= $scriptFlowOptionsHtml ?>
                            </select>
                        </div>
                        <div class="col-lg-3 col-md-4 col-sm-12 col-xs-12">
                            <input type="hidden" class="script-rule-steps-value" data-rule-kind="__KIND__" name="scripts.__FIELD__.rules[__INDEX__][steps]" value="*" />
                            <div class="script-step-chips" data-rule-kind="__KIND__"></div>
                        </div>
                        <div class="col-lg-6 col-md-3 col-sm-12 col-xs-12">
                            <input type="text" class="form-control" name="scripts.__FIELD__.rules[__INDEX__][url]" value="" placeholder="https://example.com/path?clickid={clickid}" />
                        </div>
                        <div class="col-lg-1 col-md-1 col-sm-12 col-xs-12 script-rule-remove-col">
                            <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                            <a href="javascript:void(0)" class="remove-script-rule-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                        </div>
                    </div>
                </div>
            </template>
            <template id="script-rule-template-submit">
                <div class="form-group-inner script-rule-item" data-rule-kind="submit">
                    <div class="row script-rule-header-row">
                        <div class="col-lg-2"><label class="login2 pull-left pull-left-pro">Flow</label></div>
                        <div class="col-lg-8"><label class="login2 pull-left pull-left-pro">Redirect current tab to</label></div>
                    </div>
                    <div class="row script-rule-body-row">
                        <div class="col-lg-2 col-md-4 col-sm-12 col-xs-12">
                            <select class="form-select script-rule-flow" data-rule-kind="submit" name="scripts.submitredirect.rules[__INDEX__][flow]">
                                <?= $scriptFlowOptionsHtml ?>
                            </select>
                        </div>
                        <input type="hidden" class="script-rule-steps-value" data-rule-kind="submit" name="scripts.submitredirect.rules[__INDEX__][steps]" value="*" />
                        <div class="col-lg-8 col-md-7 col-sm-12 col-xs-12">
                            <input type="text" class="form-control" name="scripts.submitredirect.rules[__INDEX__][url]" value="" placeholder="https://example.com/path?clickid={clickid}" />
                        </div>
                        <div class="col-lg-1 col-md-1 col-sm-12 col-xs-12 script-rule-remove-col">
                            <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-outline-light campaign-icon-btn script-rule-move-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
                            <a href="javascript:void(0)" class="remove-script-rule-item btn btn-danger campaign-icon-btn" title="Delete"><i class="bi bi-trash"></i></a>
                        </div>
                    </div>
                </div>
            </template>
            <div class="flow-group">
            <span class="flow-group-title">Page Loading</span>
            <div class="campaign-setting-row script-switch-row">
                <div class="campaign-setting-label">
                    <span>Lazy-load images</span>
                </div>
                <input type="hidden" id="scripts-images-lazy-load" name="scripts.imageslazyload" value="<?= $c->scripts->imagesLazyLoad ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="scripts-images-lazy-load-toggle">
                    <input type="checkbox" id="scripts-images-lazy-load-toggle" class="campaign-switch-input" data-value-target="scripts-images-lazy-load" aria-label="Lazy-load images" <?= $c->scripts->imagesLazyLoad ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            </div>
            </section>

            <section id="sec-events" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-activity" aria-hidden="true"></i> Events</h3>
                <p>Choose which landing interactions and performance metrics this campaign records.</p>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Scroll depth</span>
            <div class="campaign-setting-row">
                <div class="campaign-setting-label events-setting-label">
                    <span>Track scroll depth</span>
                    <small>Record the first time each configured percentage is reached.</small>
                </div>
                <input type="hidden" id="events-scroll-tracking-use" name="events.scroll.use" value="<?= $c->events->scrollTrackingUse ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="events-scroll-tracking-toggle">
                    <input type="checkbox" id="events-scroll-tracking-toggle" class="campaign-switch-input" data-value-target="events-scroll-tracking-use" data-controls="events-scroll-tracking-block" aria-controls="events-scroll-tracking-block" aria-label="Track scroll depth" <?= $c->events->scrollTrackingUse ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="events-scroll-tracking-block" class="campaign-dependent-settings events-dependent-settings" <?= $c->events->scrollTrackingUse ? '' : 'hidden' ?>>
                <div class="events-field-row">
                    <label for="events-scroll-thresholds">Scroll thresholds, %</label>
                    <div>
                        <input id="events-scroll-thresholds" type="hidden" name="events.scroll.thresholds" value="<?= htmlspecialchars(implode(',', $c->events->scrollTrackingThresholds), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                        <div class="events-threshold-control" data-threshold-control="events-scroll-thresholds">
                            <div class="events-threshold-chips" data-threshold-chips aria-label="Scroll thresholds">
                                <input type="text" class="events-threshold-entry" data-threshold-entry-for="events-scroll-thresholds" placeholder="Add %" inputmode="numeric" autocomplete="off" aria-label="Add scroll threshold" />
                            </div>
                        </div>
                        <small>Up to 32 whole numbers from 1 to 100. Press Enter or comma to add a threshold. Example events: <code>scroll_50</code>, <code>scroll_90</code>.</small>
                    </div>
                </div>
            </div>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Visible time</span>
            <div class="campaign-setting-row">
                <div class="campaign-setting-label events-setting-label">
                    <span>Track visible time on page</span>
                    <small>Count only seconds while the page is visible to the visitor.</small>
                </div>
                <input type="hidden" id="events-time-tracking-use" name="events.time.use" value="<?= $c->events->timeTrackingUse ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="events-time-tracking-toggle">
                    <input type="checkbox" id="events-time-tracking-toggle" class="campaign-switch-input" data-value-target="events-time-tracking-use" data-controls="events-time-tracking-block" aria-controls="events-time-tracking-block" aria-label="Track visible time on page" <?= $c->events->timeTrackingUse ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            <div id="events-time-tracking-block" class="campaign-dependent-settings events-dependent-settings" <?= $c->events->timeTrackingUse ? '' : 'hidden' ?>>
                <div class="events-field-row">
                    <label for="events-time-thresholds">Time thresholds, seconds</label>
                    <div>
                        <input id="events-time-thresholds" type="hidden" name="events.time.thresholds" value="<?= htmlspecialchars(implode(',', $c->events->timeTrackingThresholds), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                        <div class="events-threshold-control" data-threshold-control="events-time-thresholds">
                            <div class="events-threshold-chips" data-threshold-chips aria-label="Visible-time thresholds">
                                <input type="text" class="events-threshold-entry" data-threshold-entry-for="events-time-thresholds" placeholder="Add seconds" inputmode="numeric" autocomplete="off" aria-label="Add visible-time threshold" />
                            </div>
                        </div>
                        <small>Up to 32 whole numbers from 1 to 86400. Press Enter or comma to add a threshold. Example events: <code>stay_30s</code>, <code>stay_60s</code>.</small>
                    </div>
                </div>
            </div>
            </div>

            <div class="flow-group">
            <span class="flow-group-title">Performance</span>
            <div class="campaign-setting-row">
                <div class="campaign-setting-label events-setting-label">
                    <span>
                        Measure landing performance
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon events-performance-help" tabindex="0" role="img" aria-label="Collected performance metrics: LCP — Largest Contentful Paint; INP — Interaction to Next Paint; CLS — Cumulative Layout Shift; TTFB — Time to First Byte; FCP — First Contentful Paint." data-tooltip="LCP — Largest Contentful Paint&#10;INP — Interaction to Next Paint&#10;CLS — Cumulative Layout Shift&#10;TTFB — Time to First Byte&#10;FCP — First Contentful Paint"></i>
                    </span>
                    <small>Collect browser performance metrics once for every clickid + step.</small>
                </div>
                <input type="hidden" id="events-performance-tracking-use" name="events.performance.use" value="<?= $c->events->performanceTrackingUse ? 'true' : 'false' ?>" />
                <label class="campaign-switch" for="events-performance-tracking-toggle">
                    <input type="checkbox" id="events-performance-tracking-toggle" class="campaign-switch-input" data-value-target="events-performance-tracking-use" aria-label="Measure landing performance" <?= $c->events->performanceTrackingUse ? 'checked' : '' ?> />
                    <span class="campaign-switch-track" aria-hidden="true">
                        <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                        <span class="campaign-switch-option campaign-switch-option-on">On</span>
                        <span class="campaign-switch-thumb"></span>
                    </span>
                </label>
            </div>
            </div>

            <div class="flow-group">
                <div class="events-custom-heading">
                    <div>
                        <span class="flow-group-title">Custom events</span>
                        <p>Only names in this list are accepted. Add up to 64 names and call the helper when the action happens on a landing.</p>
                    </div>
                    <button type="button" id="add-custom-event" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add event</button>
                </div>
                <div id="custom-event-empty" class="events-empty-state" <?= $c->events->customEventNames === [] ? '' : 'hidden' ?>>No custom events configured.</div>
                <div id="custom-event-list" class="events-custom-list">
                    <?php foreach ($c->events->customEventNames as $eventIndex => $eventName) { ?>
                    <div class="events-custom-row" data-custom-event-row>
                        <div>
                            <label class="visually-hidden" for="custom-event-name-<?= $eventIndex ?>">Custom event name</label>
                            <input id="custom-event-name-<?= $eventIndex ?>" type="text" class="form-control custom-event-name" name="events.custom[<?= $eventIndex ?>]" value="<?= htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="cta_click" pattern="[a-z][a-z0-9_]{0,63}" maxlength="64" required />
                            <small>Lowercase letters, numbers and underscores; start with a letter.</small>
                        </div>
                        <div class="events-custom-actions">
                            <button type="button" class="btn btn-outline-light btn-sm copy-custom-event" title="Copy landing code" aria-label="Copy code for <?= htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><i class="bi bi-copy"></i><span>Copy</span></button>
                            <button type="button" class="btn btn-danger campaign-icon-btn remove-custom-event" title="Delete event" aria-label="Delete <?= htmlspecialchars($eventName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
            </section>

            <?php $uniqueness = $c->uniqueness; ?>
            <section id="sec-misc" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-sliders" aria-hidden="true"></i> Misc</h3>
                <p>Configure campaign-wide uniqueness and reporting settings.</p>
            </div>
            <div class="flow-group">
                <span class="flow-group-title flow-group-title-with-help"><i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Calculates campaign and flow uniqueness for every recorded flow click. Remove uniqueness rules from flows before switching this off." data-tooltip="Calculates campaign and flow uniqueness for every recorded flow click. Remove uniqueness rules from flows before switching this off."></i>Uniqueness counting</span>
                <div class="campaign-setting-row campaign-setting-row-control-only">
                    <input type="hidden" id="uniqueness-enabled-value" name="uniqueness.enabled" value="<?= $uniqueness->enabled ? 'true' : 'false' ?>" />
                    <label class="campaign-switch" for="uniqueness-counting-toggle">
                        <input type="checkbox" id="uniqueness-counting-toggle" class="campaign-switch-input" data-value-target="uniqueness-enabled-value" data-controls="uniqueness-settings" aria-label="Enable uniqueness counting" aria-controls="uniqueness-settings" <?= $uniqueness->enabled ? 'checked' : '' ?> />
                        <span class="campaign-switch-track" aria-hidden="true">
                            <span class="campaign-switch-option campaign-switch-option-off">Off</span>
                            <span class="campaign-switch-option campaign-switch-option-on">On</span>
                            <span class="campaign-switch-thumb"></span>
                        </span>
                    </label>
                </div>
                <div id="uniqueness-settings" class="campaign-dependent-settings" <?= $uniqueness->enabled ? '' : 'hidden' ?>>
                    <div class="form-group-inner">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro" for="uniqueness-method">Method:</label></div>
                            <div class="col-lg-4">
                                <select id="uniqueness-method" name="uniqueness.method" class="form-select">
                                    <?php foreach (['ip' => 'IP', 'ip_ua' => 'IP + UserAgent', 'cookie' => 'Cookie', 'cookie_ip' => 'Cookie / IP', 'cookie_ip_ua' => 'Cookie / IP + UserAgent', 'get' => 'GET parameter'] as $methodValue => $methodLabel) { ?>
                                    <option value="<?= $methodValue ?>" <?= $uniqueness->method === $methodValue ? 'selected' : '' ?>><?= $methodLabel ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group-inner" id="uniqueness-get-row" <?= $uniqueness->method === 'get' ? '' : 'hidden' ?>>
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro" for="uniqueness-get-parameter">GET parameter:</label></div>
                            <div class="col-lg-4"><input id="uniqueness-get-parameter" type="text" name="uniqueness.get_parameter" class="form-control" value="<?= htmlspecialchars($uniqueness->getParameter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" /></div>
                        </div>
                    </div>
                    <div class="form-group-inner">
                        <div class="row">
                            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro" for="uniqueness-ttl">TTL (hours):</label></div>
                            <div class="col-lg-2"><input id="uniqueness-ttl" type="number" min="1" max="720" step="1" required name="uniqueness.ttl_hours" class="form-control" value="<?= $uniqueness->ttlHours ?>" /></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flow-group">
                <span class="flow-group-title">Statistics</span>
                <div class="form-group-inner">
                    <div class="row">
                        <div class="col-lg-3"><label class="login2 pull-left pull-left-pro" for="campaign-statistics-timezone">Campaign timezone:</label></div>
                        <div class="col-lg-5">
                            <select id="campaign-statistics-timezone" name="statistics.timezone" class="form-select">
                                <?php $timezoneLabelDate = new DateTimeImmutable('now'); ?>
                                <?php foreach (DateTimeZone::listIdentifiers() as $timezoneId) { ?>
                                <option value="<?= htmlspecialchars($timezoneId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $c->statistics->timezone === $timezoneId ? 'selected' : '' ?>><?= htmlspecialchars(get_timezone_option_label($timezoneId, $timezoneLabelDate), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                <?php } ?>
                            </select>
                            <small class="form-text campaign-setting-note">Used by campaign reports and daily conversion caps. Offsets reflect the current date.</small>
                        </div>
                    </div>
                </div>
            </div>
            </section>

            <section id="sec-conversions" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-bullseye" aria-hidden="true"></i> Conversions</h3>
                <p>Define conversion statuses, deduplication, and on-site tracking.</p>
            </div>
            <div class="flow-group">
                <div class="conversion-section-heading">
                    <div>
                        <span class="flow-group-title">Conversion statuses</span>
                        <p class="conversion-section-copy">Normalize incoming values into campaign statuses. Internal names are immutable after saving.</p>
                    </div>
                    <button type="button" id="add-conversion-status" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add status</button>
                </div>
                <div class="conversion-status-list-heading" aria-hidden="true">
                    <span>Internal status</span>
                    <span>Incoming aliases</span>
                    <span></span>
                </div>
                <div id="conversion-status-rows" class="conversion-status-list">
                <?php foreach ($c->conversions->statuses as $statusIndex => $conversionStatus) { ?>
                    <div class="conversion-status-row" data-status-row data-built-in="<?= $conversionStatus->isBuiltIn() ? '1' : '0' ?>">
                        <div class="conversion-status-identity">
                            <div class="conversion-status-name">
                                <?php if ($conversionStatus->isBuiltIn()) { ?><i class="bi bi-lock" title="Built-in status"></i><?php } ?>
                                <input type="text" class="form-control conversion-status-name-input" name="conversions.statuses[<?= $statusIndex ?>][name]" value="<?= htmlspecialchars($conversionStatus->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" readonly required maxlength="64">
                            </div>
                            <span class="conversion-status-kind"><?= $conversionStatus->isBuiltIn() ? 'Built-in' : 'Custom' ?></span>
                        </div>
                        <label class="visually-hidden" for="conversion-status-aliases-<?= $statusIndex ?>">Incoming aliases for <?= htmlspecialchars($conversionStatus->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input id="conversion-status-aliases-<?= $statusIndex ?>" type="text" class="form-control conversion-status-aliases" name="conversions.statuses[<?= $statusIndex ?>][aliases]" value="<?= htmlspecialchars(implode(', ', $conversionStatus->aliases), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div class="conversion-status-action">
                            <?php if (!$conversionStatus->isBuiltIn()) { ?>
                            <button type="button" class="btn btn-sm remove-conversion-status" title="Delete <?= htmlspecialchars($conversionStatus->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" aria-label="Delete <?= htmlspecialchars($conversionStatus->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><i class="bi bi-trash"></i></button>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
                </div>
            </div>

            <div class="flow-group">
                <span class="flow-group-title">Deduplication</span>
                <div class="campaign-setting-row">
                    <div class="campaign-setting-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="One transaction ID is one immutable transaction within each configured parameter. Reusing it through the same parameter is rejected." data-tooltip="One transaction ID is one immutable transaction within each configured parameter. Reusing it through the same parameter is rejected."></i>
                        <span>Transaction ID deduplication</span>
                    </div>
                    <input type="hidden" id="conversion-tid-dedup-value" name="conversions.deduplication.enabled" value="<?= $c->conversions->tidDeduplicationEnabled ? 'true' : 'false' ?>">
                    <label class="campaign-switch" for="conversion-tid-dedup-toggle">
                        <input type="checkbox" id="conversion-tid-dedup-toggle" class="campaign-switch-input" data-value-target="conversion-tid-dedup-value" aria-label="Transaction ID deduplication" <?= $c->conversions->tidDeduplicationEnabled ? 'checked' : '' ?>>
                        <span class="campaign-switch-track" aria-hidden="true"><span class="campaign-switch-option campaign-switch-option-off">Off</span><span class="campaign-switch-option campaign-switch-option-on">On</span><span class="campaign-switch-thumb"></span></span>
                    </label>
                </div>
                <div class="campaign-setting-row conversion-field-row">
                    <div class="campaign-setting-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Comma-separated query or form parameter names that may carry the transaction ID. Each name has its own deduplication namespace. Send only one non-empty configured parameter per postback." data-tooltip="Comma-separated query or form parameter names that may carry the transaction ID. Each name has its own deduplication namespace. Send only one non-empty configured parameter per postback."></i>
                        <label for="conversion-tid-parameters">Transaction ID parameters</label>
                    </div>
                    <input id="conversion-tid-parameters" type="text" name="conversions.deduplication.transaction_id_parameters" class="form-control conversion-compact-control conversion-tid-parameters-control" value="<?= htmlspecialchars(implode(', ', $c->conversions->transactionIdParameters), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="tid, transaction_id, order_id" maxlength="<?= ConversionSettings::MAX_TRANSACTION_ID_PARAMETER_INPUT_LENGTH ?>" autocomplete="off" spellcheck="false" required>
                </div>
                <div class="campaign-setting-row conversion-field-row" id="conversion-repeat-settings">
                    <div class="campaign-setting-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Used only while Transaction ID deduplication is Off." data-tooltip="Used only while Transaction ID deduplication is Off."></i>
                        <label for="conversion-repeat-mode">Paid repeat without transaction ID</label>
                    </div>
                    <input type="hidden" id="conversion-repeat-mode-value" name="conversions.deduplication.paid_repeat_without_tid" value="<?= htmlspecialchars($c->conversions->paidRepeatWithoutTid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <select id="conversion-repeat-mode" class="form-select conversion-compact-control" <?= $c->conversions->tidDeduplicationEnabled ? 'disabled' : '' ?>>
                        <option value="reject" <?= $c->conversions->paidRepeatWithoutTid === 'reject' ? 'selected' : '' ?>>Reject duplicate</option>
                        <option value="upsell" <?= $c->conversions->paidRepeatWithoutTid === 'upsell' ? 'selected' : '' ?>>Accept as upsell</option>
                    </select>
                </div>
            </div>

            <div class="flow-group">
                <span class="flow-group-title">Form submission</span>
                <div class="campaign-setting-row">
                    <div class="campaign-setting-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Creates a zero-payout record with source form_submit." data-tooltip="Creates a zero-payout record with source form_submit."></i>
                        <span>Create conversion after successful form submit</span>
                    </div>
                    <input type="hidden" id="conversion-form-enabled-value" name="conversions.form.enabled" value="<?= $c->conversions->formEnabled ? 'true' : 'false' ?>">
                    <label class="campaign-switch" for="conversion-form-enabled-toggle"><input type="checkbox" id="conversion-form-enabled-toggle" class="campaign-switch-input" data-value-target="conversion-form-enabled-value" data-controls="conversion-form-settings" aria-label="Create conversion after successful form submit" aria-controls="conversion-form-settings" <?= $c->conversions->formEnabled ? 'checked' : '' ?>><span class="campaign-switch-track" aria-hidden="true"><span class="campaign-switch-option campaign-switch-option-off">Off</span><span class="campaign-switch-option campaign-switch-option-on">On</span><span class="campaign-switch-thumb"></span></span></label>
                </div>
                <div class="campaign-setting-row" id="conversion-form-settings" <?= $c->conversions->formEnabled ? '' : 'hidden' ?>><div class="campaign-setting-label"><span>Status</span></div><select name="conversions.form.status" class="form-select conversion-compact-control conversion-status-select"><?php foreach ($c->conversions->statusNames() as $statusName) { ?><option value="<?= htmlspecialchars($statusName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $c->conversions->formStatus === $statusName ? 'selected' : '' ?>><?= htmlspecialchars($statusName) ?></option><?php } ?></select></div>
            </div>

            <div class="flow-group">
                <span class="flow-group-title">Website status tracking</span>
                <div class="campaign-setting-row">
                    <div class="campaign-setting-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Accepts internal names and aliases using the current clickid. Payout is not accepted." data-tooltip="Accepts internal names and aliases using the current clickid. Payout is not accepted."></i>
                        <span>Site tracking endpoint</span>
                    </div>
                    <input type="hidden" id="conversion-site-enabled-value" name="conversions.site.enabled" value="<?= $c->conversions->siteEnabled ? 'true' : 'false' ?>">
                    <label class="campaign-switch" for="conversion-site-enabled-toggle"><input type="checkbox" id="conversion-site-enabled-toggle" class="campaign-switch-input" data-value-target="conversion-site-enabled-value" aria-label="Enable website status tracking" <?= $c->conversions->siteEnabled ? 'checked' : '' ?>><span class="campaign-switch-track" aria-hidden="true"><span class="campaign-switch-option campaign-switch-option-off">Off</span><span class="campaign-switch-option campaign-switch-option-on">On</span><span class="campaign-switch-thumb"></span></span></label>
                </div>
                <div class="conversion-code-row"><code id="conversion-site-snippet">ytdsConversion('Reg');</code><button type="button" id="copy-conversion-snippet" class="btn btn-outline-light btn-sm"><i class="bi bi-copy"></i> Copy</button></div>
            </div>
            </section>

            <section id="sec-postbacks" class="camp-section">
            <div class="campaign-section-heading">
                <h3><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Postbacks</h3>
                <p>Receive conversion updates and send them to external services.</p>
            </div>
            <div class="flow-group">
            <span class="flow-group-title">Postback</span>
            <div class="form-group-inner">
                <div class="row">
                    <div class="col-lg-3 col-md-3 col-sm-3 col-xs-12">
                        <label class="login2 pull-left pull-left-pro">
                        <i class="bi bi-info-circle admin-info-icon" title="Clickid and status are required. Payout, currency, the configured transaction ID parameters and pbkey are optional unless their campaign settings require them."></i>
                        Your postback URL example:
                    </label>
                    </div>
                    <div class="col-lg-7 col-md-7 col-sm-7 col-xs-12">
                        <div class="input-group custom-go-button">
                            <?php $tdsRoot = rtrim(get_tds_path(), '/'); ?>
                            <?php $primaryTransactionIdParameter = $c->conversions->transactionIdParameters[0] ?? 'tid'; ?>
                            <?php $postbackUrlPrefix = $tdsRoot . '/api/postback.php?clickid={sub1}&status={status}&payout={payout}&currency=USD&'; ?>
                            <input id="postback-url-example" type="text" readonly class="form-control" data-url-prefix="<?= htmlspecialchars($postbackUrlPrefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars($postbackUrlPrefix . $primaryTransactionIdParameter . '={transaction_id}', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"/>
                        </div>
                    </div>
                </div>
            </div>
            <div class="campaign-setting-row">
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="When enabled, every incoming postback must include the pbkey parameter with one of the allowed values. Missing or invalid keys are rejected. Outside Debug Mode, every rejected postback is returned as a generic 404." data-tooltip="When enabled, every incoming postback must include the pbkey parameter with one of the allowed values. Missing or invalid keys are rejected. Outside Debug Mode, every rejected postback is returned as a generic 404."></i>
                    <span>Key protection</span>
                </div>
                <input type="hidden" id="postback-pbkey-enabled-value" name="postback.pbkey.enabled" value="<?= $c->postback->pbkeyEnabled ? 'true' : 'false' ?>">
                <label class="campaign-switch" for="postback-pbkey-enabled-toggle"><input type="checkbox" id="postback-pbkey-enabled-toggle" class="campaign-switch-input" data-value-target="postback-pbkey-enabled-value" data-controls="postback-pbkey-settings" aria-label="Enable key protection" aria-controls="postback-pbkey-settings" <?= $c->postback->pbkeyEnabled ? 'checked' : '' ?>><span class="campaign-switch-track" aria-hidden="true"><span class="campaign-switch-option campaign-switch-option-off">Off</span><span class="campaign-switch-option campaign-switch-option-on">On</span><span class="campaign-switch-thumb"></span></span></label>
            </div>
            <div id="postback-pbkey-settings" class="campaign-setting-row" <?= $c->postback->pbkeyEnabled ? '' : 'hidden' ?>>
                <div class="campaign-setting-label">
                    <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Comma-separated values accepted in the pbkey parameter. Matching is exact and case-sensitive; at least one value is required while Key protection is On." data-tooltip="Comma-separated values accepted in the pbkey parameter. Matching is exact and case-sensitive; at least one value is required while Key protection is On."></i>
                    <label for="postback-pbkey-values">Allowed key values</label>
                </div>
                <input id="postback-pbkey-values" type="text" name="postback.pbkey.keys" class="form-control conversion-compact-control" value="<?= htmlspecialchars(implode(', ', $c->postback->pbkeys), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="off" spellcheck="false">
            </div>
            </div>

            <div class="flow-group s2s-group">
                <div class="s2s-section-heading">
                    <div>
                        <span class="flow-group-title">S2S</span>
                        <p>Send outgoing postbacks for selected conversion statuses or landing events.</p>
                    </div>
                    <button type="button" id="add-s2s-item" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add postback</button>
                </div>
                <div id="s2s-empty-state" class="events-empty-state">No S2S postbacks configured.</div>
                <div id="s2s_container" class="s2s-rules"></div>
                <template id="s2s-rule-template">
                    <section class="s2s-rule s2s" data-s2s-rule>
                        <div class="s2s-rule-heading">
                            <strong data-s2s-rule-title>S2S postback</strong>
                            <button type="button" class="btn btn-danger campaign-icon-btn remove-s2s-item" title="Delete S2S postback" aria-label="Delete S2S postback"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </div>
                        <div class="s2s-rule-field">
                            <div class="s2s-rule-label">
                                <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="The address can use standard click macros such as {clickid}, {userid}, {domain} and {status}. Event triggers also support {event}, {event_value}, {step_index}, {variant} and {trigger_type}." data-tooltip="The address can use standard click macros such as {clickid}, {userid}, {domain} and {status}. Event triggers also support {event}, {event_value}, {step_index}, {variant} and {trigger_type}."></i>
                                <label data-s2s-url-label>Address</label>
                            </div>
                            <input type="url" class="form-control s2s-url-input" data-s2s-url placeholder="https://s2s-postback.example/goal?clickid={clickid}" maxlength="<?= PostbackSettings::MAX_S2S_URL_LENGTH ?>" required>
                        </div>
                        <div class="s2s-rule-field">
                            <label class="s2s-rule-label" data-s2s-method-label>Send method</label>
                            <select class="form-select s2s-method-select" data-s2s-method>
                                <option value="GET">GET</option>
                                <option value="POST">POST</option>
                            </select>
                        </div>
                        <div class="s2s-rule-field s2s-trigger-field">
                            <div class="s2s-rule-label">
                                <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Send this S2S postback when a conversion reaches one of the selected normalized campaign statuses." data-tooltip="Send this S2S postback when a conversion reaches one of the selected normalized campaign statuses."></i>
                                <label data-s2s-statuses-label>Conversion statuses</label>
                            </div>
                            <div class="s2s-chip-field" data-s2s-chip-field="statuses">
                                <div class="s2s-chip-control" data-s2s-chip-control>
                                    <div class="s2s-chip-list" data-s2s-chip-list></div>
                                    <input type="search" class="s2s-chip-input" data-s2s-chip-input role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" autocomplete="off" spellcheck="false" placeholder="Add status…">
                                </div>
                                <ul class="s2s-chip-options" data-s2s-chip-options role="listbox" hidden></ul>
                                <span class="visually-hidden" data-s2s-chip-live aria-live="polite"></span>
                            </div>
                        </div>
                        <div class="s2s-rule-field s2s-trigger-field">
                            <div class="s2s-rule-label">
                                <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Send this S2S postback when one of the selected enabled landing events is first recorded. Performance metrics are excluded." data-tooltip="Send this S2S postback when one of the selected enabled landing events is first recorded. Performance metrics are excluded."></i>
                                <label data-s2s-events-label>Events</label>
                            </div>
                            <div class="s2s-chip-field" data-s2s-chip-field="events">
                                <div class="s2s-chip-control" data-s2s-chip-control>
                                    <div class="s2s-chip-list" data-s2s-chip-list></div>
                                    <input type="search" class="s2s-chip-input" data-s2s-chip-input role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" autocomplete="off" spellcheck="false" placeholder="Add event…">
                                </div>
                                <ul class="s2s-chip-options" data-s2s-chip-options role="listbox" hidden></ul>
                                <span class="visually-hidden" data-s2s-chip-live aria-live="polite"></span>
                            </div>
                        </div>
                    </section>
                </template>
                <script type="application/json" id="s2s-initial-data"><?= json_encode(
                    $c->postback->s2sPostbacks,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                ) ?></script>
            </div>
            </section>

            <section id="sec-api" class="camp-section">
            <?php
                $tdsRoot = rtrim(get_tds_path(), '/');
                $phpConnectUrl = $tdsRoot . '/api/phpconnect.php';
                $jsConnectUrl = $tdsRoot . '/js/index.php';
                $jsConnectSnippet = '<script src="' . $jsConnectUrl . '"></script>';
            ?>
            <div class="campaign-section-heading">
                <h3><i class="bi bi-plug" aria-hidden="true"></i> Integration</h3>
                <p>Choose how an external website connects to this campaign.</p>
            </div>

            <div class="flow-group integration-group">
            <span class="flow-group-title">PHP Connect</span>
                <p class="integration-description">Use the bundled <code>phpclient.php</code> on a PHP website. Set its API URL and API key to the values below.</p>
                <div class="integration-field">
                    <label for="php-connect-url">API URL</label>
                    <input id="php-connect-url" type="text" readonly class="form-control integration-code" value="<?= htmlspecialchars($phpConnectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                </div>
                <div class="integration-field">
                    <label for="php-connect-key">Campaign API key</label>
                    <input id="php-connect-key" type="text" readonly class="form-control integration-code" value="<?= htmlspecialchars($c->apiKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                </div>
            </div>

            <div class="flow-group integration-group">
            <span class="flow-group-title">JavaScript Connect</span>
                <p class="integration-description">Add this script before the closing <code>&lt;/body&gt;</code> tag of the external website.</p>
                <div class="integration-field">
                    <label for="js-connect-code">Embed code</label>
                    <input id="js-connect-code" type="text" readonly class="form-control integration-code" value="<?= htmlspecialchars($jsConnectSnippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                </div>
                <div class="integration-action-row">
                    <div class="integration-action-label">
                        <i class="bi bi-info-circle admin-info-icon setting-help-icon" tabindex="0" role="img" aria-label="Controls how JavaScript Connect opens the routed page." data-tooltip="Controls how JavaScript Connect opens the routed page."></i>
                        <span>JavaScript Connect Action</span>
                    </div>
                    <div class="ywb-radios integration-action-options">
                        <label class="ywb-radio-label"><input type="radio" <?= $c->black->jsconnectAction === 'replace' ? 'checked' : '' ?> value="replace" name="black.jsconnect" /> Content replace</label>
                        <label class="ywb-radio-label"><input type="radio" <?= $c->black->jsconnectAction === 'iframe' ? 'checked' : '' ?> value="iframe" name="black.jsconnect" /> IFrame</label>
                        <label class="ywb-radio-label"><input type="radio" <?= $c->black->jsconnectAction === 'redirect' ? 'checked' : '' ?> value="redirect" name="black.jsconnect" /> Redirect</label>
                    </div>
                </div>
            </div>
            </section>

            <div class="camp-save-bar">
                <button class="btn btn-lg btn-primary" type="submit" id="save-settings-btn">
                    <strong>Save settings</strong>
                </button>
            </div>
            <div id="save-settings-overlay" class="save-settings-overlay" aria-hidden="true">
                <div class="save-settings-overlay-card">
                    <div class="save-settings-overlay-spinner"></div>
                    <div class="save-settings-overlay-text">Saving settings...</div>
                </div>
            </div>
            <div id="save-settings-toast" class="save-settings-toast" aria-live="polite" aria-atomic="true"></div>
        </form>
            </div><!-- .camp-content -->
        </div><!-- .camp-layout -->
    </div><!-- .all-content-wrapper -->
    <!--cloneData-->
    <script src="js/cloneData.js?v=<?= filemtime(__DIR__ . '/js/cloneData.js') ?>"></script>
    <script>
        $('#add-redirect-item').cloneData({
            mainContainerId: 'redirect_container',
            cloneContainer: 'redirect-item',
            removeButtonClass: 'remove-redirect-item',
            maxLimit: 5,
            minLimit: 1
        });
       
        $('#add-curl-item').cloneData({
            mainContainerId: 'curl_container',
            cloneContainer: 'curl-item',
            removeButtonClass: 'remove-curl-item',
            maxLimit: 5,
            minLimit: 1
        });

        $('#add-errorcode-item').cloneData({
            mainContainerId: 'errorcodes_container',
            cloneContainer: 'errorcode-item',
            removeButtonClass: 'remove-errorcode-item',
            maxLimit: 5,
            minLimit: 1
        });

        $('#add-backfix-url-item').cloneData({
            mainContainerId: 'backfix_urls_container',
            cloneContainer: 'backfix-url-item',
            removeButtonClass: 'remove-backfix-url-item',
            maxLimit: 10,
            minLimit: 0
        });

        
        window.scriptRedirectFlowStepCounts = <?= json_encode($scriptFlowStepCounts) ?>;
        window.scriptRedirectMaxStepCount = <?= (int)$scriptMaxStepCount ?>;
        function getRuleTemplateHtml(kind, index) {
            const templateId = kind === 'submit' ? 'script-rule-template-submit' : 'script-rule-template-next';
            return document.getElementById(templateId).innerHTML
                .replaceAll('__KIND__', kind)
                .replaceAll('__INDEX__', String(index));
        }

        function updateScriptRuleNames(kind) {
            document.querySelectorAll(`[data-rule-kind="${kind}"]`).forEach((item, index) => {
                item.querySelectorAll('input[name], select[name]').forEach((input) => {
                    input.name = input.name.replace(/rules\[\d+\]/, `rules[${index}]`);
                });
            });
        }

        function parseRuleSteps(value) {
            if (!value || value === '*') return '*';
            const steps = String(value)
                .split(',')
                .map((s) => s.trim())
                .filter((s) => s !== '' && !Number.isNaN(Number(s)))
                .map((s) => Number(s));
            if (steps.length === 0) return '*';
            return [...new Set(steps)].sort((a, b) => a - b);
        }

        function serializeRuleSteps(steps) {
            if (steps === '*' || !Array.isArray(steps) || steps.length === 0) return '*';
            return steps.join(',');
        }

        function renderStepChipsForRule(item) {
            if (!item) return;
            const flowSelect = item.querySelector('.script-rule-flow');
            const hiddenInput = item.querySelector('.script-rule-steps-value');
            const chipsContainer = item.querySelector('.script-step-chips');
            if (!flowSelect || !hiddenInput || !chipsContainer) return;

            const flowName = flowSelect.value;
            const stepCount = flowName === '*'
                ? Number(window.scriptRedirectMaxStepCount || 0)
                : Number(window.scriptRedirectFlowStepCounts[flowName] || 0);
            let selectedSteps = parseRuleSteps(hiddenInput.value);

            if (stepCount <= 0) {
                hiddenInput.value = '*';
                chipsContainer.innerHTML = '<button type="button" class="script-step-chip active" data-steps-any="true">Any step</button>';
                return;
            }

            if (selectedSteps !== '*') {
                selectedSteps = selectedSteps.filter((step) => step >= 0 && step < stepCount);
                if (selectedSteps.length === 0) {
                    selectedSteps = '*';
                }
            }

            let html = '<button type="button" class="script-step-chip' + (selectedSteps === '*' ? ' active' : '') + '" data-steps-any="true">Any</button>';
            for (let step = 0; step < stepCount; step++) {
                const active = selectedSteps !== '*' && selectedSteps.includes(step);
                html += '<button type="button" class="script-step-chip' + (active ? ' active' : '') + '" data-step-value="' + step + '">Step ' + (step + 1) + '</button>';
            }
            chipsContainer.innerHTML = html;
            hiddenInput.value = serializeRuleSteps(selectedSteps);
        }

        function initializeScriptRuleSelects(kind) {
            document.querySelectorAll(`.script-rule-item[data-rule-kind="${kind}"]`).forEach(renderStepChipsForRule);
        }

        function addScriptRule(kind) {
            const container = document.getElementById(`${kind}_redirect_rules_container`);
            if (!container) return;
            const index = container.querySelectorAll('.script-rule-item').length;
            container.insertAdjacentHTML('beforeend', getRuleTemplateHtml(kind, index));
            updateScriptRuleNames(kind);
            initializeScriptRuleSelects(kind);
        }

        function collectRulesForKind(kind) {
            return Array.from(document.querySelectorAll(`.script-rule-item[data-rule-kind="${kind}"]`)).map((item) => {
                const flow = item.querySelector('.script-rule-flow')?.value || '*';
                const stepsInput = item.querySelector('.script-rule-steps-value');
                const stepsRaw = (stepsInput?.value || '').trim();
                const url = (item.querySelector('input[name*="[url]"]')?.value || '').trim();
                if (!url) return null;

                const steps = kind === 'submit' ? '*' : parseRuleSteps(stepsRaw);

                return { flow, steps, url };
            }).filter(Boolean);
        }

        window.collectScriptRedirectRules = function () {
            return {
                next: collectRulesForKind('next'),
                submit: collectRulesForKind('submit'),
            };
        };

        document.getElementById('add-next-redirect-rule')?.addEventListener('click', () => addScriptRule('next'));
        document.getElementById('add-submit-redirect-rule')?.addEventListener('click', () => addScriptRule('submit'));

        document.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.remove-script-rule-item');
            if (removeBtn) {
                const ruleItem = removeBtn.closest('.script-rule-item');
                if (!ruleItem) return;
                const kind = ruleItem.dataset.ruleKind;
                ruleItem.remove();
                if (kind) updateScriptRuleNames(kind);
                return;
            }

            const moveUpBtn = e.target.closest('.script-rule-move-up');
            if (moveUpBtn) {
                const ruleItem = moveUpBtn.closest('.script-rule-item');
                const kind = ruleItem?.dataset.ruleKind;
                if (!ruleItem || !kind) return;
                const prev = ruleItem.previousElementSibling;
                if (prev) {
                    ruleItem.parentNode.insertBefore(ruleItem, prev);
                    updateScriptRuleNames(kind);
                }
                return;
            }

            const moveDownBtn = e.target.closest('.script-rule-move-down');
            if (moveDownBtn) {
                const ruleItem = moveDownBtn.closest('.script-rule-item');
                const kind = ruleItem?.dataset.ruleKind;
                if (!ruleItem || !kind) return;
                const next = ruleItem.nextElementSibling;
                if (next) {
                    ruleItem.parentNode.insertBefore(next, ruleItem);
                    updateScriptRuleNames(kind);
                }
                return;
            }

            const chip = e.target.closest('.script-step-chip');
            if (chip) {
                const ruleItem = chip.closest('.script-rule-item');
                const hiddenInput = ruleItem?.querySelector('.script-rule-steps-value');
                if (!ruleItem || !hiddenInput) return;

                let selected = parseRuleSteps(hiddenInput.value);
                const isAny = chip.dataset.stepsAny === 'true';

                if (isAny) {
                    hiddenInput.value = '*';
                    renderStepChipsForRule(ruleItem);
                    return;
                }

                const stepValue = Number(chip.dataset.stepValue);
                if (selected === '*') {
                    selected = [];
                }
                if (!selected.includes(stepValue)) {
                    selected.push(stepValue);
                } else {
                    selected = selected.filter((step) => step !== stepValue);
                }
                hiddenInput.value = serializeRuleSteps(selected);
                renderStepChipsForRule(ruleItem);
                return;
            }
        });

        document.addEventListener('change', (e) => {
            if (e.target.matches('.script-rule-flow')) {
                renderStepChipsForRule(e.target.closest('.script-rule-item'));
            }
        });

        initializeScriptRuleSelects('next');
        initializeScriptRuleSelects('submit');
        
    </script>
    <script type="module" src="js/campsettings/load-mode.js"></script>
    <script type="module" src="js/campsettings/white-pages.js?v=<?= filemtime(__DIR__ . '/js/campsettings/white-pages.js') ?>"></script>
    <script type="module" src="js/campsettings/domain-specific.js?v=<?= filemtime(__DIR__ . '/js/campsettings/domain-specific.js') ?>"></script>
    <script>window._dwsCounterInit = <?= count($c->domains) ?>;</script>
    <script type="module" src="js/campsettings/dws-sync.js?v=<?= filemtime(__DIR__ . '/js/campsettings/dws-sync.js') ?>"></script>
    <script type="module" src="js/campsettings/domains.js"></script>
    <script type="module" src="js/campsettings/toggles.js?v=<?= filemtime(__DIR__ . '/js/campsettings/toggles.js') ?>"></script>
    <script src="js/campsettings/events.js?v=<?= filemtime(__DIR__ . '/js/campsettings/events.js') ?>"></script>
    <script src="js/campsettings/conversions.js?v=<?= filemtime(__DIR__ . '/js/campsettings/conversions.js') ?>"></script>
    <script src="js/campsettings/postbacks.js?v=<?= filemtime(__DIR__ . '/js/campsettings/postbacks.js') ?>"></script>
    <script type="module" src="js/campsettings/form-submit.js"></script>
    <script>window.campaignConversionStatuses = <?= json_encode($c->conversions->statusNames(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
    <script src="js/filters.js?v=<?= filemtime(__DIR__ . '/js/filters.js') ?>"></script>
    <script>
        var rules_basic = <?=json_encode($c->white->filters)?>;

        $('#filtersbuilder').queryBuilder({
            operators: $.fn.queryBuilder.constructor.DEFAULTS.operators.concat(paramOperators),
            filters: tdsFilters,
            <?php
            if (!empty($c->white->filters['rules']) && is_array($c->white->filters['rules'])) {
                echo 'rules: rules_basic,';
            }
            ?>
        });

        <?php foreach ($c->black->flows as $fi => $flow) { ?>
        var flow_rules_<?= $fi ?> = <?= json_encode($flow->filters) ?>;
        initFlowFilterBuilder('#flow-filters-<?= $fi ?>', flow_rules_<?= $fi ?>);
        <?php } ?>

        const uniquenessToggle = document.getElementById('uniqueness-counting-toggle');
        uniquenessToggle?.addEventListener('change', function () {
            if (!this.checked) {
                const affected = getUniquenessRuleFlowNames();
                if (affected.length) {
                    this.checked = true;
                    document.getElementById('uniqueness-enabled-value').value = 'true';
                    document.getElementById('uniqueness-settings').hidden = false;
                    alert('Remove uniqueness rules before disabling uniqueness counting. Affected flows: ' + affected.join(', '));
                    return;
                }
            }
            refreshFlowFilterBuilders();
        });

    </script>
    <!-- Folder Picker Modal -->
    <div id="folderPickerModal" class="ywbmodal" style="max-width:420px !important;">
        <div class="fp-modal-content">
            <div class="fp-modal-header"><h5 style="margin:0;font-size:18px;color:#e2e8f0;">Select Folders</h5></div>
            <div class="fp-modal-body">
                <input type="text" id="fp-search" placeholder="Search..." class="fp-search-input">
                <div id="fp-list" class="fp-list-wrap"></div>
                <div id="fp-empty" style="display:none;color:#94a3b8;text-align:center;padding:20px 0;">No folders found. Upload a ZIP first.</div>
            </div>
            <div class="fp-modal-footer">
                <button type="button" class="btn btn-secondary campaign-action-btn" id="fp-cancel">Cancel</button>
                <button type="button" class="btn btn-info campaign-action-btn" id="fp-ok" disabled>Add selected</button>
            </div>
        </div>
    </div>
    <style>
        #folderPickerModal{background:#151b2d !important;padding:0 !important;border-radius:12px !important;overflow:hidden !important;}
        .fp-modal-content{display:grid;grid-template-rows:auto minmax(0,1fr) auto;max-height:80vh;min-height:0;}
        .fp-modal-header{padding:12px 16px;border-bottom:1px solid #2a3245;}
        .fp-modal-body{padding:12px 16px;min-height:0;overflow:hidden;display:flex;flex-direction:column;}
        .fp-search-input{width:100%;padding:6px 12px;background:#1a2235;border:1px solid #2a3245;border-radius:6px;color:#e2e8f0;font-size:14px;margin-bottom:10px;}
        .fp-search-input:focus{outline:none;border-color:#0084ff;}
        .fp-list-wrap{min-height:0;max-height:min(480px,50vh);overflow-y:auto;overscroll-behavior:contain;scrollbar-gutter:stable;padding-right:4px;}
        .fp-list-wrap::-webkit-scrollbar{width:8px;}
        .fp-list-wrap::-webkit-scrollbar-track{background:#1a2235;border-radius:4px;}
        .fp-list-wrap::-webkit-scrollbar-thumb{background:#2d3748;border-radius:4px;}
        .fp-modal-footer{padding:12px 16px;border-top:1px solid #2a3245;display:flex;justify-content:flex-end;gap:8px;}
        #fp-list label{display:flex;align-items:center;gap:10px;padding:8px 12px;margin:0;border-radius:6px;cursor:pointer;color:#e2e8f0;font-size:14px;transition:background .15s}
        #fp-list label:hover{background:#1e2a3f}
        #fp-list input[type=checkbox]{appearance:none;-webkit-appearance:none;position:relative;flex:0 0 auto;width:18px;height:18px;margin:0;border:2px solid #667a9b;border-radius:4px;background:transparent;cursor:pointer}
        #fp-list input[type=checkbox]:checked{border-color:#0084ff;background:#0084ff}
        #fp-list input[type=checkbox]:checked::after{content:"";position:absolute;left:5px;top:1px;width:5px;height:9px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
        #fp-list input[type=checkbox]:focus-visible{outline:2px solid rgba(64,153,255,.65);outline-offset:2px}
        .fp-folder-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        #fp-list label.fp-selected{background:#1a2a45}
    </style>

    <!-- Load Mode Modal -->
    <div id="loadModeModal" class="ywbmodal" style="max-width:380px !important;">
        <div class="fp-modal-content">
            <div class="fp-modal-header"><h5 style="margin:0;font-size:18px;color:#e2e8f0;">Loading Mode</h5></div>
            <div class="fp-modal-body" id="lm-body"></div>
            <div class="fp-modal-footer">
                <button type="button" class="btn btn-default campaign-action-btn" id="lm-cancel">Cancel</button>
                <button type="button" class="btn btn-info campaign-action-btn" id="lm-ok">OK</button>
            </div>
        </div>
    </div>
    <style>
        #loadModeModal{background:#151b2d !important;padding:0 !important;border-radius:12px !important;overflow:hidden !important;}
        .lm-option{display:block;padding:10px 14px;margin:0 0 4px;border-radius:6px;cursor:pointer;color:#e2e8f0;font-size:14px;transition:background .15s}
        .lm-option:hover{background:#1e2a3f}
        .lm-option input[type=radio]{margin-right:10px;accent-color:#0084ff}
        .lm-option.lm-selected{background:#1a2a45}
        .lm-desc{display:block;margin-left:26px;font-size:12px;color:#94a3b8;margin-top:2px}
    </style>

    <script>window.LANDING_FOLDER = <?= json_encode(get_cache_path('landings')) ?>;window.WHITE_FOLDER = <?= json_encode(get_cache_path('whites')) ?>;</script>
    <!-- CodeMirror 6 local bundles -->
    <script src="js/cm6/html.min.js"></script>
    <script>window.CM6_HTML = cm6;</script>
    <script src="js/cm6/css.min.js"></script>
    <script>window.CM6_CSS = cm6;</script>
    <script src="js/cm6/javascript.min.js"></script>
    <script>window.CM6_JS = cm6;</script>
    <script src="js/cm6/php.min.js"></script>
    <script>window.CM6_PHP = cm6;</script>
    <script type="module" src="js/fileeditor.js"></script>
    <?php
    $flowModuleVersion = 0;
    foreach (glob(__DIR__ . '/js/flows/*.js') ?: [] as $flowModuleFile) {
        $flowModuleVersion = max($flowModuleVersion, (int) filemtime($flowModuleFile));
    }
    ?>
    <script type="module" src="js/flows/index.js?v=<?= $flowModuleVersion ?>"></script>
    <script type="module" src="js/campsettings-nav.js?v=<?= filemtime(__DIR__ . '/js/campsettings-nav.js') ?>"></script>

    <!-- ── Flow templates (used by js/flows/ modules) ── -->
    <template id="tpl-folder-row">
        <div class="form-group-inner flow-path-item" data-mvt='{"enabled":false,"tests":[]}'><div class="row">
            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro" data-role="folder-label">Folder:</label></div>
            <div class="col-lg-3"><input type="text" class="form-control folder-value-input" data-role="folder-input" value="" placeholder="folder" readonly tabindex="-1" /></div>
            <div class="col-lg-2 flow-weight-col" style="display:none">
                <input type="number" step="1" class="form-control" data-role="weight-input" value="" placeholder="%" style="width:70px" /></div>
            <div class="col-lg-3"><div class="btn-group campaign-icon-group">
                <a href="javascript:void(0)" class="btn btn-outline-secondary load-mode-btn" data-role="mode-btn" data-mode="base" data-modes="base,direct" title="Loading mode"><i class="bi bi-house-door"></i></a>
                <a href="javascript:void(0)" class="btn btn-warning flow-edit-folder" title="Edit files"><i class="bi bi-pencil-square"></i></a>
                <a href="javascript:void(0)" class="btn btn-danger" data-role="remove-btn" title="Delete"><i class="bi bi-trash"></i></a>
            </div></div>
        </div><div class="flow-mvt-panel"></div></div>
    </template>

    <template id="tpl-redirect-row">
        <div class="form-group-inner flow-path-item"><div class="row">
            <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect URL:</label></div>
            <div class="col-lg-4"><input type="text" class="form-control flow-step-redirect" value="" placeholder="https://..." /></div>
            <div class="col-lg-2 flow-weight-col" style="display:none">
                <input type="number" step="1" class="form-control flow-step-weight" value="" placeholder="%" style="width:70px" /></div>
            <div class="col-lg-1"><a href="javascript:void(0)" class="btn btn-danger campaign-icon-btn flow-remove-step-item" title="Delete"><i class="bi bi-trash"></i></a></div>
        </div></div>
    </template>

    <template id="tpl-flow-section">
        <section id="sec-flow-__FI__" class="camp-section flow-section" data-flow-index="__FI__">
        <h5 class="flow-section-title">__FLOWNAME__</h5>

        <div class="flow-group"><span class="flow-group-title">Flow Filters</span>
        <div class="form-group-inner">
            <div class="row"><div id="flow-filters-__FI__"></div></div>
        </div></div>

        <div class="flow-group"><span class="flow-group-title">Distribution</span>
        <div class="form-group-inner">
            <select class="form-select flow-dist" data-fi="__FI__">
                <option value="equal" selected>Equal</option><option value="weighted">Weighted</option><option value="thompson">Thompson Sampling</option></select></div>

        <div class="flow-thompson-opts" id="flow-thompson-opts-__FI__" style="display:none">
            <div class="form-group-inner"><label class="login2 pull-left pull-left-pro">Optimize for:</label>
            <div class="ywb-radios">
                <label class="ywb-radio-label"><input type="radio" checked value="Lead" name="flow___FI___optimize_for" class="flow-optimize-for" data-fi="__FI__" /> Lead</label>
                <label class="ywb-radio-label"><input type="radio" value="Purchase" name="flow___FI___optimize_for" class="flow-optimize-for" data-fi="__FI__" /> Purchase</label>
            </div></div>
            <div class="form-group-inner flow-optimize-mode-wrap" id="flow-optimize-mode-wrap-__FI__" style="display:none">
            <label class="login2 pull-left pull-left-pro">Optimize mode:</label>
            <div class="ywb-radios">
                <label class="ywb-radio-label"><input type="radio" checked value="funnels" name="flow___FI___optimize_mode" class="flow-optimize-mode" data-fi="__FI__" /> Funnels (step combos)</label>
                <label class="ywb-radio-label"><input type="radio" value="separate" name="flow___FI___optimize_mode" class="flow-optimize-mode" data-fi="__FI__" /> Separate (independent per step)</label>
            </div></div></div></div>

        <div class="flow-group"><span class="flow-group-title">Steps</span>
        <div id="steps-list-__FI__" class="steps-list"></div>
        <div style="margin-top:10px;">
            <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-add-step" data-fi="__FI__"><i class="bi bi-plus-circle"></i> Add Step</a>
        </div>
        </div>

        </section>
    </template>

    <template id="tpl-step-section">
        <section id="sec-step-__FI__-__SI__" class="camp-section step-section" data-flow-index="__FI__" data-step-index="__SI__">
        <h5 class="flow-section-title">__FLOWNAME__ &rsaquo; Step __STEPNUM__</h5>

        <div class="flow-group"><span class="flow-group-title">Action</span>
        <div class="form-group-inner">
            <div class="ywb-radios">
                <label class="ywb-radio-label">
                    <input type="radio" checked value="folder" name="flow___FI___step___SI___action" class="flow-step-action" data-fi="__FI__" data-si="__SI__" /> Local page(s) from folder
                </label>
                <label class="ywb-radio-label">
                    <input type="radio" value="redirect" name="flow___FI___step___SI___action" class="flow-step-action" data-fi="__FI__" data-si="__SI__" /> Redirect(s)
                </label>
            </div>
        </div></div>

        <div class="flow-step-folders" style="display:block">
        <div class="flow-group"><span class="flow-group-title">Folders</span>
            <div class="flow-step-folder-items"></div>
            <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-step-add-existing" data-fi="__FI__" data-si="__SI__"><i class="bi bi-folder-symlink"></i> Add Existing</a>
            <a href="javascript:void(0)" class="btn btn-info campaign-action-btn flow-step-upload-zip" data-fi="__FI__" data-si="__SI__"><i class="bi bi-upload"></i> Upload ZIP</a>
        </div></div>

        <div class="flow-step-redirects" style="display:none">
        <div class="flow-group"><span class="flow-group-title">Redirects</span>
            <div class="flow-step-redirect-items"></div>
            <a href="javascript:void(0)" class="btn btn-primary campaign-action-btn flow-step-add-redirect" data-fi="__FI__" data-si="__SI__">+ Add Redirect</a>
            <div class="form-group-inner" style="margin-top:10px"><div class="row">
                <div class="col-lg-3"><label class="login2 pull-left pull-left-pro">Redirect type:</label></div>
                <div class="col-lg-3"><select class="form-select flow-step-redirect-type" data-fi="__FI__" data-si="__SI__">
                    <option value="301">301</option><option value="302" selected>302</option><option value="303">303</option><option value="307">307</option>
                </select></div></div></div>
        </div></div>

        </section>
    </template>

</body>
</html>
