# Black Settings and Flows

## Main Areas

- JS Connect action
- JS bot detection
- flows

## JS Bot Detection

This is an additional verification stage that runs in the visitor's browser. Before black content is shown, the page loads the `js/detect.js` script, which executes the selected tests and requests the real content only after all of them pass.

When any test fails, the content is never requested, the visitor stays on the white page, and the click is stored in Blocked clicks with a reason equal to the failed test name (for example, `browserscreen`), or `timeout` if an interactive test received no visitor action in time.

Tests come in two kinds:

- **Non-interactive** run instantly on page load and require no visitor participation.
- **Interactive** wait for a real visitor action until **Timeout** expires. Every enabled interactive test must pass: content is requested only when all of them are done.

| Test | Kind | What It Checks |
| --- | --- | --- |
| **Mouse click / Touch start** (`pointerdown`) | Interactive | A real mouse click or screen touch. Bots without user input emulation are cut off by the timeout. |
| **Text typing** (`keydown`) | Interactive | Any key press. Suitable for desktop traffic; causes false positives on mobile traffic without keyboard input. |
| **Device motion** (`devicemotion`) | Interactive, Android only | Real accelerometer reading changes. Passes automatically on non-Android devices, so it does not disturb desktop and iOS traffic. |
| **Device orientation** (`deviceorientation`) | Interactive, Android only | Real gyroscope reading changes. Passes automatically on non-Android devices. |
| **Audio engine existence** (`audiocontext`) | Non-interactive | Presence of `AudioContext`. A missing audio stack is typical for stripped-down headless environments and emulators. |
| **Time zone** (`timezone`) | Non-interactive | The browser's timezone offset must fall within the **Minimum/Maximum allowed timezone** range. Catches bots whose server timezone does not match the campaign GEO. The range fields are shown only while this test is enabled. |
| **Browser screen** (`browserscreen`) | Non-interactive | Screen presence and size consistency. Failure means: zero browser window size (`outerWidth`/`outerHeight` — a typical sign of headless without window emulation), zero `screen.width`/`screen.height`, or a viewport wider than the physical screen. |
| **WebDriver automation** (`webdriver`) | Non-interactive | The `navigator.webdriver` flag, which the browser sets when driven via CDP/WebDriver (Puppeteer, Playwright, Selenium without stealth). Also verifies that the flag getter is not rewritten by a JS wrapper — this catches bots that suppressed the flag with a stealth plugin. |

## Flows

A flow contains:

- name
- filters
- steps
- distribution
- optimization settings

Flows are evaluated in order. Drag the handle to the left of a flow name to reorder it. When the handle has keyboard focus, `↑` and `↓` provide the same control.

When [uniqueness counting](uniqueness.md) is enabled, flow filters include Campaign and Flow uniqueness conditions. Safe Page never exposes this filter.

## Filter Value Format

For fields with **in** and **not in**, enter multiple values as a comma-separated list without quotes: `en,it`, `desktop,mobile,other`, `Android,iOS`. Spaces around commas are ignored, and matching is case-insensitive. These operators require an exact value match; use **contains** or **not contains** for partial matching when the selected filter supports them.

**Safe Page** filters and **Flow Filters** share the same core rule set. **Uniqueness**, **Conversion cap (campaign)**, and **Conversion cap (flow)** are available only in flows.

| Filter | What It Checks | Operators | What To Enter |
| --- | --- | --- | --- |
| **OS** | Operating system from User-Agent/Client Hints. | **in**, **not in** | DeviceDetector OS names: `Android`, `iOS`, `Windows`, `Mac`, `GNU/Linux`, `Ubuntu`. Use commas for multiple values. |
| **OS version** | Operating system version. | **in**, **not in**, **<=**, **>=** | Version numbers: `10`, `11`, `14`, `17.1`. Comparisons use version comparison. |
| **Device** | Device type from DeviceDetector. | **in**, **not in** | `desktop`, `mobile`, `tablet`, `other`, or an exact device type from the list below. |
| **Bot** | Server-side bot detection by User-Agent. | **=** | Select **Yes** or **No** in the UI. Saved rules use `yes` and `no`. |
| **Brand** | Device manufacturer. | **contains**, **not contains**, **in**, **not in** | Examples: `Apple`, `Samsung`, `Xiaomi`, `Huawei`. The value may be empty when the brand is unknown. |
| **Model** | Device model. | **contains**, **not contains**, **in**, **not in** | Examples: `iPhone`, `SM-G991B`, `Pixel 8`. Desktop traffic often has an empty model. |
| **Client** | Client/browser from User-Agent. | **contains**, **not contains**, **in**, **not in** | Examples: `Chrome`, `Mobile Safari`, `Firefox`, `Facebook`, `Instagram`. Use **contains** for broad browser families. |
| **ClientVer** | Client/browser version. | **<=**, **>=**, **in**, **not in** | Examples: `120`, `120.0`, `17.4`. Comparisons use version comparison. |
| **Country** | Visitor country by IP. | **in**, **not in** | Two-letter [ISO 3166-1 alpha-2 country codes](https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2): `US`, `IT`, `RU`, `DE`, `BR`. |
| **Language** | Primary browser language from `Accept-Language`. | **in**, **not in** | Two-letter [ISO 639-1 language codes](https://en.wikipedia.org/wiki/List_of_ISO_639_language_codes): `en`, `it`, `ru`, `de`. |
| **UserAgent** | Full User-Agent string. | **contains**, **not contains** | String fragments: `facebook`, `facebot`, `curl`, `gce-spider`, `yandex.com`, `odklbot`. |
| **ISP** | ISP/ASN organization by IP. | **contains**, **not contains** | Provider or datacenter name fragments: `facebook`, `google`, `amazon`, `azure`, `digitalocean`, `microsoft`. |
| **Referer** | HTTP `Referer` header. | **=**, **!=**, **contains**, **not contains** | A full URL or source fragment. Empty value is allowed when you need to check missing referer. |
| **Domain** | Campaign domain receiving the request. | **in**, **not in** | Current request host, for example `example.com`, `promo.example.com`. |
| **Host** | Incoming request host or explicit `tds_host` prefill. | **in**, **not in** | Usually the same as **Domain** for direct traffic. Useful for server-side integrations that pass host explicitly. |
| **VPN&Tor** | IP proxy/VPN plugin result. | **=** | Select **Detected** or **NOT Detected** in the UI. |
| **IP Base** | Whether the IP is present in a text base under `bases/`. | **in**, **not in** | One or more base filenames: `bots1.txt,bots2.txt`. Files may contain IP addresses and CIDR ranges. |
| **URL Parameter** | URL query parameter existence or value. | **in**, **not in**, **exists**, **not exists** | First field: parameter name, such as `utm_source`; second field for **in/not in**: comma-separated values, such as `fb,tt`. |
| **Uniqueness** | Visitor uniqueness in campaign or flow scope. | **is unique**, **is not unique** | Flow-only. Available after [uniqueness counting](uniqueness.md) is enabled. Scope: **Campaign** or **Flow**. |
| **Conversion cap (campaign)** | Daily conversion cap across the whole campaign. | `<`, `<=`, `=`, `!=`, `>=`, `>` | Flow-only. Select campaign statuses and a numeric daily limit. |
| **Conversion cap (flow)** | Daily conversion cap for the current flow. | `<`, `<=`, `=`, `!=`, `>=`, `>` | Flow-only. Counts only rows attributed to the current flow. |

**Language** checks the browser's primary language from the HTTP `Accept-Language` header. YellowTDS takes the highest-priority language, lowercases it, and keeps the first two characters. Use two-letter [ISO 639-1 language codes](https://en.wikipedia.org/wiki/List_of_ISO_639_language_codes): `en`, `it`, `ru`, `de`, `fr`, `es`, `pt`, `tr`, and so on. Region subtags are not stored: `en-US` and `en-GB` are matched as `en`.

**Country** checks the visitor country by IP and uses two-letter uppercase [ISO 3166-1 alpha-2 country codes](https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2): `US`, `IT`, `RU`, `DE`, `BR`.

**Device** checks the device type returned by the bundled DeviceDetector library. Accepted values are:

`desktop`, `smartphone`, `tablet`, `feature phone`, `console`, `tv`, `car browser`, `smart display`, `camera`, `portable media player`, `phablet`, `smart speaker`, `wearable`, `peripheral`.

The filter also supports the convenience alias `mobile`: it means phone traffic and expands to `smartphone`, `feature phone`, and `phablet`. A `feature phone` is a basic phone with a browser; a `phablet` is a large smartphone between a phone and a tablet. Tablets are not included in `mobile`; add `tablet` separately when needed. For example, `mobile,tablet` matches phones and tablets, while `mobile` matches phones only.

The `other` alias groups the remaining rare device types: `console`, `tv`, `car browser`, `smart display`, `camera`, `portable media player`, `smart speaker`, `wearable`, and `peripheral`. `other` does not include `desktop`, `smartphone`, `feature phone`, `phablet`, or `tablet`.

The **Bot** filter uses DeviceDetector's server-side User-Agent classification and accepts **Yes** or **No**. It runs before browser checks and does not replace the separate **JS bot detection** stage. When a Safe Page rule blocks a bot, Blocked clicks keeps reason `bot` and the complete original User-Agent.

Every flow also has two separate daily conversion rules:

- **Conversion cap (campaign)** counts all accepted history rows for the selected statuses across the campaign.
- **Conversion cap (flow)** counts only rows attributed to the current flow.

Choose one of `<`, `<=`, `=`, `!=`, `>=`, or `>`, one or more campaign statuses, and a numeric value. Counts include accepted paid-repeat or upsell rows. A day is bounded by the campaign timezone and always uses conversion-row time, independent of the statistics attribution setting. When a flow no longer matches its cap rule, YellowTDS evaluates the next flow and then TrafficBack.

![Conversion cap filter](../assets/screenshots/conversion-cap-filter.png)

## Steps

Steps use the same handle to keep ordering consistent. A redirect is a terminal action, so its step remains locked in the last position.

Each step can contain folders or redirect URLs. Weight is stored with the corresponding folder or URL. A folder also stores its load type and its own MVT settings.

## Landing MVT

Every folder entry has an **MVT** section. The Copy button beside each Test name copies its placeholder, such as `#TEST1#`; put it into the landing HTML and add text or HTML Values to the Test. YellowTDS selects one Value independently and uniformly for every active TEST and performs a trusted string replacement. The same placeholder may occur more than once in the HTML.

TEST numbers follow creation order. Values use A, B … Z, AA, and subsequent codes. A saved Value is read-only: archive it and append another Value when the content must change. Archived TESTs and Values stay in the configuration with their original number or code, so numbering never rolls back.

![MVT settings for a folder entry](../assets/screenshots/campaign-settings-mvt.png)

One `clickid + step` always keeps the same landing and MVT combination. A new click first reuses the current PHP-session assignment. When **Save user path** is enabled, the existing `saved_paths` cookie provides a five-day fallback. Direct Load refreshes and Back navigation read the assignment already recorded for the reached step instead of selecting again.

Only active TESTs and Values participate in new assignments. A previously assigned archived Value continues to render for an existing click, session, or Sticky path while MVT remains enabled. When MVT is disabled, YellowTDS does not replace placeholders and does not scan or repair the landing HTML.
