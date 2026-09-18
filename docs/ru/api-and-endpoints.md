# API и endpoints

## Основные runtime endpoints

- `index.php`
- `js/index.php`
- `api/phpconnect.php`
- `api/postback.php`
- `api/conversion.php`
- `api/manage.php`
- `send.php`
- `next.php`
- `api/updateparams.php`

## Management API

`api/manage.php` — скрытый JSON API для серверных интеграций. Глобальный ключ задаётся в Settings → Security. Пустой ключ выключает API. Ключ передаётся заголовком `X-Ytds-Key` или `Authorization: Bearer`, либо полем `key` в JSON. Без ключа, с неверным ключом и для не-POST запросов ответ — `404 Not Found`. В Debug mode те же ошибки возвращаются JSON.

Тело запроса — JSON с полем `action`:

- `click.get` — `{clickid}` или `{subid}`
- `click.update` — `{clickid, params?, cost?}`. `params` мержатся, `cost` заменяет расход клика. `null` в params удаляет метку.
- `cost.distribute` — `{campaign_id, from, to, amount, filters?}`. Размазывает сумму поровну по кликам периода. `from`/`to` — `YYYY-MM-DD` в timezone кампании или unix timestamp. Фильтр меток: `{"params":{"utm_campaign":"fb"}}`.
- `campaigns.list` — `[{id, name}]`
- `stats.get` — `{campaign_id, from, to, columns?, groupby?, filters?}`. Обёртка над статистикой кампании.

Это не CRUD кампаний и не замена postback.

`api/updateparams.php` остаётся публичным GET-пикселем с лендинга: обновляет только метки по `clickid`, расход не принимает. Ошибки вне Debug mode маскируются как `404 Not Found`.

## Endpoints конверсий

`api/postback.php` принимает `clickid`, `status`, необязательные `payout`, `currency`, настроенный в кампании параметр Transaction ID и campaign `pbkey`. По умолчанию Transaction ID приходит в `tid`; для разных партнёрских программ можно разрешить несколько имён, но в одном запросе непустым должно быть только одно. Query и form fields читаются явно: cookies не используются, а один field одновременно в GET и POST отклоняется. Обычно endpoint возвращает структурированный JSON; при включённой pbkey-защите ошибка маскируется как `404 Not Found`. Подробнее: [Конверсии и Postbacks](postbacks.md).

`api/conversion.php` — same-origin POST endpoint для инжектируемой функции `ytdsConversion(status)`. В кампании должен быть включён Website status tracking. Endpoint принимает только текущий `clickid` и внутреннее имя статуса или alias; payout через браузер не передаётся.

Все источники конверсий атомарно пишут одну историю `conversions` и обновляют snapshot клика.

## Интеграция кампании

В разделе **Integration** редактора кампании собраны оба способа внешнего запуска:

- PHP Connect: адрес endpoint и API-ключ кампании для комплектного `phpclient.php`.
- JavaScript Connect: готовый тег со скриптом `js/index.php` и выбор способа открытия результата.

JavaScript-действие хранится на уровне кампании и поддерживает замену содержимого, iframe и redirect.

## Admin endpoints

- `admin/login.php`
- `admin/campeditor.php`
- `admin/clmnseditor.php`
- `admin/clicksdata.php`
- `admin/fileeditor.php`
- `admin/listfolders.php`
- `admin/zipupload.php`
