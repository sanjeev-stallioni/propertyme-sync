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
- **Agents** — properties link to the site's agent post type by manager name; unknown managers get an agent post auto-created (name only — photo/phone/email added once in wp-admin)
- **Photos**
  - API images (`lots/{id}/images`) imported per lot when present
  - **REA XML importer**: drop realestate.com.au-format feed files into `uploads/propertyme-feed/` (auto-created); imports photos (`url=` or `file=` alongside the XML), floorplans, listing descriptions, and inspection times; files archived to `processed/`; hash-based dedupe prevents re-imports
  - Featured image + gallery field + floorplan file field populated
- **Detail-page layout cloning** — new posts receive the site's per-post Elementor layout from a designated template post (all widgets dynamic-tag bound); posts with their own layout are never touched
- **Logging** — ring-buffer log on the settings page, including a raw sample of the first lot each sync for mapping verification
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
3. Click **Connect to PropertyMe**, log in with the portfolio account, and approve.
4. Click **Sync now** and review the log and results.
5. Enable **automatic sync via cron** and choose an interval.

> **Cron note:** if `DISABLE_WP_CRON` is set, trigger `wp-cron.php` (or `wp cron event run pms_sync_event`) from a system cron.

## REA XML feeds

Place feed files (and any photos they reference by `file=`) in `wp-content/uploads/propertyme-feed/`, then click **Import REA XML now** — the import also runs automatically after each scheduled sync. Listings are matched to posts by previously imported unique id → PropertyMe lot id → street address. Override the drop directory with the `pms_reaxml_dir` filter.

## Data storage

| Location | Contents |
|---|---|
| `wp_pms_properties` (custom table) | One row per lot: normalized columns + full raw JSON + linked post ID + timestamps |
| `wp_options` | `pms_settings` (secret encrypted), `pms_tokens` (encrypted), `pms_log`, `pms_last_sync`, `pms_db_version`, `pms_layout_template_post` |
| `wp_posts` / `wp_postmeta` | The projected content the theme renders; sync markers `_pms_lot_id`, `_pms_synced_at`, `_pms_rea_unique_id`, `_pms_src` (attachment dedupe) |

## Site-specific configuration

This plugin was built against one site's data model and needs adapting for reuse:

- **Field keys** — `PMS_Sync::PROPERTY_FIELDS` maps field names to that site's ACF field keys. Point these at your own field group.
- **Layout template** — the `pms_layout_template_post` option holds the post ID whose Elementor layout is cloned onto new properties.
- **Post types** — properties are regular posts; agents are an `agents` custom post type.
- **Status field** — the listing filter reads a `leased_type` meta field with values `For Lease` / `Leased`.

## Security notes

- Never commit real credentials, tokens, or database dumps to this repository.
- The encryption key derives from the site's salts: encrypted values are per-site and cannot be copied between installs — re-enter credentials on each environment.
- All admin actions are nonce- and capability-checked; OAuth state is single-use with expiry.
- PropertyMe's internal `Notes` field is deliberately never synced to the public site.

## License

Proprietary — all rights reserved. (Update before making this repository public.)
