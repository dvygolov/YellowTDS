# API and Endpoints

## Main Runtime Endpoints

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

`api/manage.php` is a hidden JSON API for server-side integrations. The global key is set in Settings → Security. An empty key disables the API. Send it as `X-Ytds-Key`, `Authorization: Bearer`, or JSON `key`. Requests without a valid key, and non-POST requests, return `404 Not Found`. Debug mode returns JSON errors instead.

The JSON body must include `action`:

- `click.get` — `{clickid}` or `{subid}`
- `click.update` — `{clickid, params?, cost?}`. `params` are merged; `cost` replaces the click cost. A `null` param value deletes that token.
- `cost.distribute` — `{campaign_id, from, to, amount, filters?}`. Splits the amount evenly across matching clicks. `from`/`to` are `YYYY-MM-DD` in the campaign timezone or unix timestamps. Token filter: `{"params":{"utm_campaign":"fb"}}`.
- `campaigns.list` — `[{id, name}]`
- `stats.get` — `{campaign_id, from, to, columns?, groupby?, filters?}`. Read-only campaign statistics.

This is not campaign CRUD and does not replace postbacks.

`api/updateparams.php` remains a public landing-page GET pixel: it updates tokens by `clickid` and never writes cost. Outside Debug mode, failures are masked as `404 Not Found`.

## Conversion Endpoints

`api/postback.php` accepts `clickid`, `status`, optional `payout`, `currency`, a campaign-configured transaction ID parameter, and campaign `pbkey`. The default transaction ID name is `tid`; campaigns can allow several names for different affiliate programs. Send only one non-empty configured name per request. Query and form fields are read explicitly, while cookies and a field duplicated between GET and POST are rejected or ignored as described in [Conversions and Postbacks](postbacks.md). The endpoint returns a structured JSON result unless pbkey protection masks a rejected request as `404 Not Found`.

`api/conversion.php` is a same-origin POST endpoint used by the injected `ytdsConversion(status)` helper. Website status tracking must be enabled in the campaign. It accepts only the current `clickid` and an internal status name or alias; payout is not exposed to the browser.

All conversion sources write the same `conversions` history and update the click snapshot atomically.

## Campaign Integration

The campaign editor's **Integration** section keeps both external launch methods together:

- PHP Connect: copy the endpoint and campaign API key into the bundled `phpclient.php`.
- JavaScript Connect: embed the displayed `js/index.php` script tag and select how the routed page is opened.

The JavaScript action is stored per campaign and supports content replacement, iframe, and redirect modes.

## Admin Endpoints

- `admin/login.php`
- `admin/campeditor.php`
- `admin/clmnseditor.php`
- `admin/clicksdata.php`
- `admin/fileeditor.php`
- `admin/listfolders.php`
- `admin/zipupload.php`
