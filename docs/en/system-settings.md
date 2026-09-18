# System Settings

The **Settings** button in the header opens instance-wide YellowTDS settings. These values are separate from campaign settings.

![System settings modal](../assets/screenshots/settings-modal-general.png)

## Tabs

- **General** — UTP, debug mode, log retention, the default TDS timezone for new campaigns, and global **Conversion attribution** (`Click time` or `Conversion time`).
- **Security** — new admin password, admin path, domain/IP access restrictions, and the Management API key.
- **Storage** — SQLite file name, backup folder, and cache root. Cache subfolders use fixed system names and are not shown in the UI. **Randomize main paths** generates new unpredictable names for the database, backup folder, and cache root; the rename is applied after **Save settings**.
- **Backups** — create Full snapshots with SQLite or faster Quick snapshots without SQLite, then view, restore, or delete them.
- **Plugins** — currency sources and VPN/proxy detectors, preferred currencies, and the VPN/proxy decision mode. **Any positive** treats a visitor as VPN/proxy when any enabled detector returns a positive result. **Majority** requires positive results from more than half of the detectors that returned a valid response. With one responding detector the modes are equivalent; when every detector fails, YellowTDS treats the visitor as VPN/proxy.
- **Updates** — check and install a YellowTDS update or refresh GeoBases.

![Security settings tab](../assets/screenshots/settings-modal-security.png)

The **TDS timezone** selector keeps the IANA identifier and shows its current UTC offset, for example `Europe/Samara (UTC+04:00)`. The offset can change with daylight-saving rules.

**Management API key** on the **Security** tab enables `api/manage.php`. An empty field disables the API. **Generate** creates a new key, **Copy** copies it, and **Save settings** applies it. Requests without this key return `404 Not Found` unless Debug mode is on. The campaign PHP Connect key and postback `pbkey` are separate secrets.

On the **Security** tab, the server-detected current domain and IP are shown below **Allowed admin domain** and **Allowed admin IPs**. **Allowed admin IPs** accepts multiple IPv4 and IPv6 addresses in one comma-separated line, for example `198.51.100.10, 203.0.113.15, 2001:db8::10`; access is allowed from any address in the list. An empty field disables the IP restriction. **Add current IP** appends the detected address with a comma only when the list is not empty and does not add a duplicate. **Add current domain** inserts the current domain without a port number. On the **Plugins** tab, every plugin has an explicit switch and an **Enabled** or **Disabled** label; options belonging to a disabled plugin are inactive.

Changing the admin path, database file name, backup folder, or cache root physically renames the corresponding files and directories. Existing destinations are treated as conflicts and are never overwritten or merged. After an admin path change, the browser automatically redirects to the new URL.

## Backups and updates

**Full backup** includes the application code, `settings.local.php`, uploaded landing and safe pages, runtime/cache content, and a consistent SQLite snapshot. **Quick backup** includes the same system files and cache but excludes only SQLite. The buttons show explanatory tooltips, and every archive row is marked with a Full database icon or a Quick lightning icon.

The archive location is configured with **Backup folder** on the **Storage** tab. YellowTDS retains the five newest Full and five newest Quick archives independently. Restoring a Full backup replaces SQLite; restoring a Quick backup restores files, settings, and cache while preserving the current SQLite database. Before restore, YellowTDS creates a matching safety backup: Full before Full restore and Quick before Quick restore. Updates use Quick backups because application updates do not modify SQLite.

Large Full backups may outlive a shared hosting HTTP timeout. YellowTDS records the operation state on disk and automatically checks it after a gateway or network error instead of reporting an immediate failure. If PHP stops without publishing an archive, the operation is reported as interrupted. After five minutes, the UI warns that creation is taking unusually long but continues checking while the backup lock remains active.

![Backups tab](../assets/screenshots/settings-modal-backups.png)

## Storage format

`settings.php` contains defaults and the settings manager. UI changes are written to the adjacent `settings.local.php`, which returns a PHP array and produces no direct output. System settings do not depend on the database.

The current password is never returned to the browser. An empty password field preserves the current value; a non-empty value replaces it.

Conversion attribution is instance-wide and cannot be overridden per table. It changes reporting only; conversion CAP always uses conversion-row time and the campaign timezone.

Plugins are discovered automatically from `*Plugin.php` files in `plugins/currency/` and `plugins/vpn/`. Newly discovered plugins are disabled. Removing a plugin file removes its settings the next time Settings is opened or saved.
