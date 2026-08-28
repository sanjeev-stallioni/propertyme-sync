# PropertyMe Sync

WordPress plugin that syncs property, agent, photo, and listing data from the [PropertyMe](https://www.propertyme.com/) property-management platform into an existing WordPress website — without changing the site's frontend design or functionality.

Property data is managed entirely in PropertyMe; the website updates automatically on a schedule.

## How it works

```
PropertyMe API  ──►  wp_pms_properties  ──►  WordPress posts + fields
(REST, OAuth 2.0)    (custom table:          (the site's existing
                      normalized columns      structure — posts, ACF-
REA XML files   ──►   + raw JSON per lot)     format meta, categories,
(listing feeds)                               Elementor detail layout)
```

Two-stage design: every lot fetched from the API is stored first in the plugin's own table (`wp_pms_properties`) — normalized columns for querying plus the **complete raw JSON payload** — and then *projected* into the post structure the theme renders. Because the raw payload is kept, mapping changes can be re-applied locally without re-calling the API.

No third-party plugin APIs are used anywhere: storage is `$wpdb`/`dbDelta`, field writes are core `update_post_meta()` in the exact layout the theme's fields expect.

## Features

- **OAuth 2.0** (authorization-code flow) against the PropertyMe Identity Service
  - Callback served at `/<site>/home/callback` (intercepted before WP routing)
  - CSRF `state` validation; token exchange via HTTP Basic auth (PropertyMe requirement) with body-credential fallback
  - Access tokens auto-refresh via the `offline_access` refresh token — syncs run unattended
- **Credential security**
  - Client ID/secret entered in wp-admin, never in code
  - Secret and tokens encrypted at rest (libsodium, key derived from the site's `AUTH_KEY`/`AUTH_SALT` — a DB dump alone exposes nothing)
  - Write-only secret field; over-length paste guard
- **Scheduled sync** — WP-Cron at 6/8/12-hour intervals, plus a "Sync now" button; overlap lock prevents concurrent runs
- **Property projection**
  - Posts created/updated in place, matched by PropertyMe lot id (`_pms_lot_id` meta) with address-based adoption of pre-existing manual posts (hijack-guarded)
  - Address titles built in the site's style (unit/number + street-type abbreviations expanded: "Rd" → "Road")
  - Vacancy status → "For Lease"/"Leased" field + category
  - Google Map field populated from the lot's coordinates
  - Existing property URLs preserved (SEO)
- **Agents** — properties link to the site's agent post type **by email address** (from the manager's PropertyMe staff profile via `/v1/members`), so a name spelt differently on the two systems still resolves to the same agent. Job title, phone and email are imported; values already entered by hand in wp-admin are never overwritten. An unknown email gets an agent post auto-created with a placeholder portrait — photos are added once in wp-admin, as PropertyMe's API cannot supply them. A manager with no email on their PropertyMe profile is left unlinked and logged, rather than guessed at by name
- **Incremental sync** — uses the API's `Timestamp` cursor to fetch only properties changed since the last run (a quiet portfolio costs one request returning nothing). Every 8th run does a full sweep, which also **moves properties archived in PropertyMe to Draft** — archiving removes a lot from `/lots` entirely, so only a full listing reveals it has gone. Posts are drafted, never deleted, so photos, layout and manual edits survive. Toggle in Settings; the cursor resets on disconnect.
- **Photos come from REAXML only.** The API cannot supply them: `/v1/lots/{Id}/images` returns document metadata (`FileName`, `Size`, `Status`) with **no URL of any kind**, and the file is served from a host that accepts only a browser login session — never an API token. Confirmed 2026-08-28 against a real uploaded photo. The API-photo code has been removed rather than left dormant.
- **Photos**
  - **REA XML importer**: drop realestate.com.au-format feed files into `uploads/propertyme-feed/` (auto-created); imports photos (`url=` or `file=` alongside the XML), floorplans, listing descriptions, and inspection times; files archived to `processed/`; hash-based dedupe prevents re-imports
  - Featured image + gallery field + floorplan file field populated
- **Detail-page layout** — prefers an Elementor **Theme Builder** "Single" template whose display condition covers property posts: Elementor renders every property from that one template, so nothing is stored per post and a design change reaches all properties at once. If no such template applies, the plugin falls back to cloning a layout (chosen in Settings, or auto-detected) onto the new post so properties are never left unstyled. Posts with their own layout are never touched.
- **Logging** — activity log in the plugin's own `wp_pms_log` table (never autoloaded, capped at 1000 rows, All/Errors filter on the settings page), including a raw sample of the first lot each sync for mapping verification
- **Clean uninstall** — custom table, settings, tokens, and logs removed

## Requirements

- WordPress ≥ 6.0, PHP ≥ 7.4 (with libsodium, standard in PHP ≥ 7.2)
- A PropertyMe subscription with API access (Advanced package) and OAuth client credentials issued by PropertyMe
- ACF-style field storage on the property post type (see *Site-specific configuration*)

## Installation

1. Copy the `propertyme-sync/` folder to `wp-content/plugins/` and activate it.
2. Open **Settings → PropertyMe Sync**:
   - Enter the **Client ID** and **Client Secret** exactly as issued (case-sensitive).
   - Confirm the **Redirect URI** — it must exactly match the redirect URL registered with PropertyMe for your client.
   - Leave endpoints and scopes at their defaults unless PropertyMe instructs otherwise. `offline_access` must stay enabled for unattended syncs.
   - Only three scopes are requested — `property:read`, `contact:read`, `offline_access` — because only `/lots`, `/lots/{id}/images` and `/members` are ever called. The `activity:read`, `communication:read` and `transaction:read` scopes PropertyMe also documents were removed in 2026-08 (they cover activity feeds, messages and financial transactions the site has no use for). A saved six-scope value from an earlier version is migrated automatically on the next admin page load; existing tokens keep working, and the narrower set applies at the next authorisation.
3. Click **Connect to PropertyMe**, log in with the portfolio account, and approve.
4. Click **Sync now** and review the log and results.
5. Enable **automatic sync via cron** and choose an interval.

> **Cron note:** if `DISABLE_WP_CRON` is set, trigger `wp-cron.php` (or `wp cron event run pms_sync_event`) from a system cron.

## REA XML feeds

PropertyMe delivers one REAXML file per listing by FTP, typically into the **site root**. Set **Settings → PropertyMe Sync → REAXML drop directory** to that folder (path relative to the WordPress root; leave empty for the site root), then click **Import REA XML now** — the import also runs automatically after each scheduled sync. Listings are matched to posts by previously imported unique id → PropertyMe lot id → street address.

Because that directory is usually public and holds unrelated files, the importer only touches files matching PropertyMe's `{id}-{timestamp}.xml` naming *and* containing a `<propertyList>` listing; each file is moved out of the public directory into `uploads/propertyme-feed/processed/` (deny-listed via `.htaccess`) **before** parsing, and XML is parsed with `LIBXML_NONET`. The directory is validated to sit inside the WordPress install. Override programmatically with the `pms_reaxml_dir` filter.

## Data storage

| Location | Contents |
|---|---|
| `wp_pms_properties` (custom table) | One row per lot: normalized columns + full raw JSON + linked post ID + timestamps |
| `wp_pms_log` (custom table) | Activity log: one row per entry (time, level, message, JSON context), pruned to the newest 1000 |
| `wp_options` | `pms_settings` (secret encrypted; includes `feed_dir` and `layout_template`), `pms_tokens` (encrypted), `pms_last_sync`, `pms_db_version`, legacy `pms_layout_template_post` |
| `wp_posts` / `wp_postmeta` | The projected content the theme renders; sync markers `_pms_lot_id`, `_pms_synced_at`, `_pms_rea_unique_id`, `_pms_src` (attachment dedupe), `_pms_layout_backup` (pre-Theme-Builder layout, kept for rollback) |

## Site-specific configuration

This plugin was built against one site's data model and needs adapting for reuse:

- **Field keys** — `PMS_Sync::PROPERTY_FIELDS` maps field names to that site's ACF field keys. Point these at your own field group.
- **Detail-page design** — preferred: an Elementor Theme Builder *Single* template conditioned on your property posts (nothing to configure in the plugin). Otherwise pick a clone source under **Settings → PropertyMe Sync → Detail page template**.
- **Post types** — properties are regular posts; agents are an `agents` custom post type.
- **Status field** — the listing filter reads a `leased_type` meta field with values `For Lease` / `Leased`.

## Security notes

- Never commit real credentials, tokens, or database dumps to this repository.
- The encryption key derives from the site's salts: encrypted values are per-site and cannot be copied between installs — re-enter credentials on each environment.
- All admin actions are nonce- and capability-checked; OAuth state is single-use with expiry.
- PropertyMe's internal `Notes` field is deliberately never synced to the public site.

## License

Proprietary — all rights reserved. (Update before making this repository public.)
