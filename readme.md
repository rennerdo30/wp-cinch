# Cinch

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)

> **Tighten up CSS and JS without a build step.**

A surgical asset minifier and on-disk cache for enqueued styles and scripts.

## What it does

Cinch hooks the standard `style_loader_src` and `script_loader_src` filters and rewrites every local asset URL to a pre-minified copy under `wp-content/uploads/cinch/<sha1>.<ext>`. The hash is over the source file's content, so editing a theme file auto-invalidates the cache — the next request computes a new hash, misses, regenerates, and writes a fresh entry.

No Composer dependencies. No Node. No build step. Pure PHP regex with carefully scoped passes.

## Quick start

1. Drop the plugin into `wp-content/plugins/cinch/` and activate.
2. Defaults are sane: CSS + JS both on, bypass for admins off, jQuery skipped.
3. **Cinch → Settings** to tune. Click **Purge cache** after a major theme refactor.

## What gets minified

**CSS** — comment stripping, whitespace collapse, structural-token trim, trailing-`;`-before-`}` drop. Strings inside `url()` and `content:` are protected from collapse. `/*! ... */` license banners survive.

**JS** — comment stripping only. Conservative on purpose — mangling identifiers or rewriting expressions with regex is a footgun (regex literals look like division, ASI rules are subtle, template strings span lines). Cinch leaves identifiers alone and lets gzip/Brotli compress the denser result. The big win is removing the JSDoc walls in jQuery-flavoured third-party scripts.

## What gets skipped

Hard-coded skip rules (can't be disabled):

- **External hosts** — Cinch doesn't proxy cross-origin assets.
- **Already-minified files** — anything matching `*.min.css` or `*.min.js`.
- **wp-admin / REST / AJAX requests** — front-end only.
- **`WP_DEBUG = true`** — devs see raw source so source maps + debuggers work.

User-configurable skip rules:

- **Skip handles** — paste the wp_register_script / wp_register_style handle of any asset Cinch should leave alone. Defaults: `jquery-core`, `jquery-migrate` (WP ships them already minified).
- **Bypass for admins** — when on, logged-in users with `manage_options` see un-minified source so you can debug a customizer issue without purging the cache.

## Configuration

Cinch → Settings (option key `cinch_settings`):

| Field | Default | Purpose |
|---|---|---|
| CSS minification | on | Toggle the `style_loader_src` rewriter |
| JS minification | on | Toggle the `script_loader_src` rewriter |
| Bypass for admins | off | Serve raw source to logged-in admins |
| Skip handles | `jquery-core`, `jquery-migrate` | One handle per line; comments start with `#` |

## REST API

`GET /wp-json/cinch/v1/stats` (requires `manage_options`):

```json
{
  "cached_files": 12,
  "total_bytes":  148293,
  "hits_session": 87
}
```

Useful for monitoring or for a headless dashboard.

## Architecture

```
        front-end request
                │
                ▼
   ┌──────────────────────────┐
   │ Plugin::should_optimize  │  WP_DEBUG off? not admin/REST/AJAX?
   │                          │  not logged-in admin (when bypassed)?
   └────────────┬─────────────┘
                │ yes
                ▼
   ┌──────────────────────────────────────────────┐
   │ StyleFilter / ScriptFilter                   │
   │  ├ style_loader_src   (priority 99)          │
   │  └ script_loader_src  (priority 99)          │
   │                                              │
   │   For each src:                              │
   │     1. skip if external / .min / blank       │
   │     2. resolve to local path (sandboxed)     │
   │     3. sha1(source) → cache key              │
   │     4. cache miss? CssMinifier/JsMinifier    │
   │        → atomic write under uploads/cinch/   │
   │     5. return cached URL                     │
   └──────────────────────────────────────────────┘
```

## Known limitations

- **No concatenation.** Each asset is minified standalone. Bundling N stylesheets into one is on the roadmap for v0.2 but is a big change in failure mode (one broken file breaks every page; one source change invalidates the bundle), so v0.1 keeps them separate.
- **Comment-stripping only on JS.** Identifier mangling or DCE require a real parser; that's deliberately out of scope for a zero-dependency plugin. If you need Terser-class minification, build your assets in CI and ship the `.min.js` files (Cinch will skip them).
- **No source maps.** The cache files are bare. Set `WP_DEBUG = true` to bypass Cinch entirely while debugging.
- **Old files stay on disk** until **Purge cache**. This is intentional — concurrent in-flight requests during a deploy must never get a zero-byte file.
- **No HTTP/2 push or `<link rel="preload">` injection.** Out of scope.

## Author

[renner.dev](https://renner.dev) · [@rennerdo30](https://github.com/rennerdo30)

## License

MIT.
