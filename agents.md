# YellowTDS — Project Overview

YellowTDS — PHP Traffic Distribution System для арбитража трафика. Фильтрует входящий трафик: нежелательный трафик отправляет на "белую" страницу, реальных пользователей — на оффер (мультишаговые воронки). Работает на чистом PHP + SQLite, без фреймворков.

## Публикация в GitHub

- Основная и публикуемая ветка репозитория — `multipleconfigs`.
- Если пользователь просит отправить готовые изменения на GitHub, коммить изменения задачи непосредственно в `multipleconfigs` и выполняй push в `origin/multipleconfigs`.
- Не создавай ветки `agent/*`, feature-ветки и pull request, если пользователь явно не попросил отдельную ветку или PR.
- Перед коммитом всё равно проверь scope через `git status` и diff, не добавляй runtime-файлы, секреты и посторонние пользовательские изменения.
- Перед коммитом измени версию системы, поставь текущую дату в формате dd.MM.yy

## Архитектура (request flow)

```
index.php                     — точка входа, роутинг
  ├─ directload.php           — direct load: раздача статики лендингов/вайтов напрямую
  ├─ tds.php (class Tds)      — Traffic Distribution System, главная логика маршрутизации
  │    ├─ core.php (FiltrationCore)  — сбор параметров клика + матчинг фильтров
  │    ├─ main.php                   — функции white(), black(), jscheck(), traficback()
  │    │    ├─ abtest.php (AbTest)   — A/B тестирование: equal, weighted, Thompson Sampling
  │    │    ├─ htmlprocessing.php    — загрузка и обработка HTML лендингов/вайтов
  │    │    └─ macros.php            — подстановка макросов ({clickid}, {country}, {c.*}, ...)
  │    └─ campaign.php               — все модели настроек кампании (Campaign, WhiteSettings, BlackSettings, FlowSettings, ...)
  └─ actions.php              — TdsAction, JsAction, PhpAction — выполнение результата (html/redirect/error)
```

## Режимы подключения

- **Прямой (index.php)** — TDS стоит на домене, трафик идёт напрямую
- **JS Connect (js/index.php → js/connect.js)** — подключение через `<script>` тег на белой странице. Ответ — JS-код, который заменяет контент или показывает iframe
- **PHP Connect (`api/phpconnect.php`)** — серверный API, POST JSON с api_key. Ответ — JSON с действием

## Структура файлов

Корень Git-репозитория содержит `code/` (самостоятельный дистрибутив), `docs/`, `tests/` и `temp/`. Все пути runtime ниже отсчитываются от `code/`.

```
code/
├── index.php              — главная точка входа
├── settings.php           — SettingsManager: defaults + локальная конфигурация, валидация, ревизии и безопасное переименование runtime-путей
├── core.php               — FiltrationCore: сбор click_params, матчинг фильтров (GEO, device, OS, ISP, referer, URL params, VPN/proxy)
├── tds.php                — Tds: маршрутизация (getAction, getJsAction, processJsCheck, getPhpAction, pick_flow_index)
├── main.php               — white(), black(), jscheck(), traficback() — основные действия
├── campaign.php           — Модели: Campaign, WhiteSettings, BlackSettings, FlowSettings, PrelandSettings, LandingSettings, JsBotDetection, ScriptsSettings, PostbackSettings, StatisticsSettings, StatisticsTable, S2sPostback, DomainWhiteSettings
├── actions.php            — TdsAction (html/redirect/error/die), JsAction (replace/iframe/metaredirect), PhpAction (JSON API)
├── abtest.php             — AbTest: equal/weighted/Thompson Sampling (Beta distribution, Marsaglia & Tsang), compute_win_probabilities (Monte Carlo)
├── htmlprocessing.php     — Загрузка и обработка HTML шагов воронки и white-страниц (макросы, backfix, rewrite, sanitize)
├── directload.php         — Direct Load режим: раздача статики через 404 catch-all (black шаги через `__dl/<clickid>/<step>/...`, white/white_curl)
├── cookies.php            — Работа с cookies и сессиями (set_cookie, get_cookie, session_write/read/remove, userid, clickid, px, conversion dedup)
├── macros.php             — MacrosProcessor: подстановка макросов в URL и HTML ({clickid}, {userid}, {domain}, {ip}, {country}, {c.*}, {hash:*}, {random:*})
├── paths.php              — get_tds_path(), admin path helpers, is_https()
├── currency.php           — CurrencyConverter: конвертация валют в USD (Frankfurter API + Turkish Central Bank XML), с файловым кэшем
├── send.php               — Отправка формы лендинга на партнёрку (POST proxy), дедупликация, UTP (Universal Thank You Page)
├── next.php               — Переход между шагами воронки (`clickid` + `step`)
├── redirect.php           — Функция redirect() (301/302/meta/js)
├── requestfunc.php        — HTTP-функции: get(), post() через cURL
├── htmlinject.php         — Вставка скриптов в HTML (insert_after_tag, insert_before_tag, insert_file_content)
├── logging.php            — add_log(), add_error_log()
├── debug.php              — DebugMethods: замер времени, debug-заголовки
│
├── managementapi.php      — Management API: клики, расход, список кампаний, статистика
├── api/                   — HTTP API
│   ├── manage.php         — management API, POST JSON с глобальным apiKey
│   ├── events.php         — события клиентских скриптов
│   ├── phpconnect.php     — PHP Connect, POST JSON с api_key
│   ├── postback.php       — входящие статусы и payout, S2S postbacks
│   └── updateparams.php   — legacy pixel обновления params клика
│
├── js/                    — Клиентские JS-скрипты
│   ├── index.php          — JS Connect точка входа
│   ├── connect.js         — JS Connect клиент
│   ├── detect.js          — BotDetector: JS-проверки (pointerdown, touchstart, mousemove, keydown, scroll, devicemotion, timezone)
│   ├── jscheckui.js       — UI для JS-проверки (спиннер)
│   ├── jscheck.html       — HTML-шаблон для JS-проверки
│   ├── replace.js         — Замена контента страницы (для JS Connect)
│   ├── iframe.js          — Показ iframe (для JS Connect)
│   ├── metaredirect.js    — Meta-redirect
│   └── obfuscator.php     — HunterObfuscator: обфускация JS-кода
│
├── scripts/               — Инжектируемые скрипты
│   ├── backfix.js/php     — Back button fix (подмена history)
│   ├── fixanchors.js      — Фикс якорных ссылок
│   ├── replacelanding.js  — Замена лендинга при переходе на Thank You
│   └── replaceprelanding.js — Замена transit-шага при переходе дальше по воронке
│
├── bases/                 — Базы данных для фильтрации
│   ├── device/            — DeviceDetector (UA parsing, device/OS/browser detection) + Doctrine cache
│   ├── bots.txt           — Список ботов
│   ├── ipcountry.php      — GEO-определение по IP (MaxMind GeoIP2)
│   ├── iputils.php        — IP-утилиты (CIDR matching)
│   └── language.php       — Определение языка по Accept-Language
│
├── plugins/               — Расширяемые источники данных
│   ├── registry.php       — discovery, enable/disable и конфигурация плагинов
│   ├── currency/          — Frankfurter и Turkish Central Bank
│   └── vpn/               — Blackbox и GetIPIntel proxy/VPN проверки
│
├── db/                    — База данных
│   ├── db.php             — Класс Db (SQLite3): CRUD кампаний, кликов, статистики, постбэков
│   ├── db.sql             — SQL-схема (таблицы: campaigns, clicks, click_steps, blocked, trafficback, common)
│   ├── default.json       — Дефолтные настройки новой кампании
│   ├── common.json        — Общие настройки (trafficback, statistics columns)
│   └── clicks.db          — SQLite база данных (runtime)
│
├── caching/               — Кэш и страницы с фиксированными системными подпапками
│   ├── landings/          — Лендинги и прелендинги (ZIP-загрузка через админку)
│   ├── whites/            — Белые страницы (папки)
│   ├── whites_curl/       — Кэш CURL-проксированных белых страниц
│   ├── devices/           — Кэш DeviceDetector
│   └── currency/          — Кэш курсов валют
│
├── reverse/               — Reverse proxy (для nginx)
├── thankyou/              — Universal Thank You Page (UTP)
├── admin/                 — Админ-панель (см. admin/agents.md)
├── logs/                  — Логи
├── ycclogs/               — Логи YCC
└── tmp/                   — Временные файлы
```

## Ключевые модели (campaign.php)

- **Campaign** — корневая модель: domains, saveUserFlow, apiKey, white, black, scripts, postback, statistics
- **WhiteSettings** — фильтры, действие (folder/curl/redirect/error), domain-specific настройки, loadMode
- **BlackSettings** — jsconnectAction (replace/iframe), jsBotDetection, flows[]
- **FlowSettings** — name, filters, steps[], distribution (equal/weighted/thompson), optimize_for, optimize_mode
- **StepSettings** — action, folders и redirectUrls; folder/redirect entries хранят loadtype, weight и MVT рядом с вариантом
- **JsBotDetection** — enabled, events[], timeout, timezone range
- **ScriptsSettings** — backfix, replacePrelanding, replaceLanding, imagesLazyLoad
- **PostbackSettings** — s2s postbacks[], event name mapping (lead/purchase/reject/trash)
- **StatisticsSettings** — timezone, allowed/leads/blocked column sets, custom tables[]

## База данных (SQLite)

Таблицы:
- **campaigns** — id, name, settings (JSON)
- **clicks** — один клик на проход по black-воронке (`userid`, `clickid`, `flow`, `path`, `step`, `params`, `cost`, `payout`, `status`)
- **click_steps** — факт входа в конкретный шаг (`clickid`, `step`, `variant`, `time`, `mvt`)
- **common** — общие настройки (JSON)

## A/B тестирование

- **Equal** — равномерное распределение (random)
- **Weighted** — взвешенное распределение (веса нормализуются до 100%)
- **Thompson Sampling** — байесовская оптимизация через Beta-распределение (Marsaglia & Tsang gamma sampling)
  - **Separate mode** — оптимизация вариантов в каждом шаге независимо
  - **Funnel mode** — оптимизация полного пути (`path`) как единого фаннела
  - **Win probabilities** — Monte Carlo симуляция для отображения вероятностей победы в UI

## Кэширование

Единая настраиваемая корневая папка `caching/`; системные подпапки `landings`, `whites`, `whites_curl`, `devices`, `currency`, `proxyvpn` имеют фиксированные имена и не входят в настройки. Хелпер `get_cache_path($subdirectory)` возвращает путь к разрешённой системной подпапке.

## Документация и UI

- Основная пользовательская документация хранится в `docs/ru/` и `docs/en/`.
- UI-скриншоты для документации хранятся в `docs/assets/screenshots/`.
- Для пояснения отдельного UI-элемента предпочитай иконку `i` перед названием функции с tooltip-описанием вместо постоянного дополнительного текста мелким шрифтом рядом с элементом. Оставляй мелкий текст видимым только тогда, когда информация важна для выполнения действия и должна читаться без наведения.
- После любого изменения UI обязательно проверь результат в браузере и отправь пользователю скриншот как подтверждение выполненной работы.
- Если изменение затрагивает интерфейс админки, тексты, структуру экранов, навигацию, кнопки, модалки, таблицы или поведение настроек, нужно обновить соответствующие страницы документации.
- Если изменение делает существующие UI-скриншоты неактуальными, нужно переснять только релевантные изображения и сохранить их под понятными именами, связанными с документом или разделом.
- Не добавляй скриншоты массово; добавляй их только там, где они реально улучшают понимание UI.
