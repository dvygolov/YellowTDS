<?php

// ── Server vars needed by the dependency chain ──
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['HTTPS'] = 'off';
$_SERVER['SERVER_PORT'] = 80;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';

// ── Set $cloSettings globally before any file reads it ──
$GLOBALS['cloSettings'] = [
    'adminPassword' => 'test',
    'adminDomain' => '',
    'adminIp' => '',
    'adminPath' => 'admin',
    'apiKey' => '',
    'dbConnection' => 'test_dummy.db',
    'useUTP' => false,
    'debug' => false,
    'logRetentionDays' => 30,
    'cachingDir' => 'caching',
    'plugins' => [
        'currency' => [
            'items' => [
                'frankfurter' => ['enabled' => true, 'preferredCurrencies' => []],
                'turkish' => ['enabled' => true, 'preferredCurrencies' => ['RUB', 'THB']],
            ],
        ],
        'vpn' => [
            'mode' => 'any',
            'items' => [
                'blackbox' => ['enabled' => true],
                'ipintel' => ['enabled' => true],
            ],
        ],
    ],
];

// ── Load the full dependency chain that db.php needs ──
// settings.php → already loaded
// debug.php → needs settings.php (loaded)
// logging.php → needs debug.php + ipcountry.php
// cookies.php → standalone
// paths.php → standalone
// These are all loaded via require_once inside db.php, so just load db.php:
require_once __DIR__ . '/../../code/db/db.php';

// Redirect all caching to a temp dir so tests don't pollute the project.
// Runtime files resolve relative cache paths from code/, so tests use an absolute temp directory.
$tmpCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ywb_test_cache';
if (!is_dir($tmpCache)) @mkdir($tmpCache, 0755, true);
// Override get_cache_path to return absolute temp paths
$GLOBALS['_ywb_test_cache_root'] = $tmpCache;
$GLOBALS['cloSettings']['cachingDir'] = $tmpCache;

require_once __DIR__ . '/../../code/campaign.php';
require_once __DIR__ . '/../../code/abtest.php';
require_once __DIR__ . '/../../code/core.php';
require_once __DIR__ . '/../../code/tds.php';
