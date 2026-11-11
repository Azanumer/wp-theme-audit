# WP Theme Audit

Scans the active theme for the mistakes that break sites quietly — deprecated WordPress functions, missing template files, text-domain mismatches, non-HTTPS URLs and insecure patterns — and reports them on one page under **Tools → Theme Audit**.

## What it checks

| Check | Severity |
|---|---|
| `style.css` missing or missing Theme Name / Version / Text Domain headers | Error / warning |
| Required files missing (`index.php`, `functions.php`) | Error |
| `header.php` never calls `wp_head()`, `footer.php` never calls `wp_footer()` | Error |
| Deprecated functions (`wp_title`, `get_currentuserinfo`, `force_ssl_login`, `screen_icon`, `create_function`, …) | Error |
| Removed `mysql_*` functions (gone since PHP 7.0) | Error |
| `eval()` in theme code | Error |
| `query_posts()` (breaks the main query — use `WP_Query` / `pre_get_posts`) | Warning |
| Translation calls using a different text domain than the theme declares | Warning |
| Non-HTTPS (`http://`) URLs in theme files (localhost, `127.0.0.1`, W3C namespaces excluded) | Warning |
| No `screenshot.png` (blank theme preview in Appearance → Themes) | Info |

## Usage

1. Upload the plugin folder to `/wp-content/plugins/` and activate it.
2. Go to **Tools → Theme Audit** and click **Run audit**.
3. Fix the errors first — deprecated functions and missing `wp_head()` / `wp_footer()` are the ones that actually break a site.

The audit runs on demand and stores nothing in the database, so it adds zero overhead to the front end.

## Filters

- `wta_deprecated_functions` — add or remove entries from the deprecated-function list (`function => version`).
- `wta_exclude_dirs` — directories to skip while scanning (default: `node_modules`, `vendor`, `.git`).
- `wta_findings` — filter the final findings list.

## License

MIT — see [LICENSE](LICENSE).
