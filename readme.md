# Cinch

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](https://www.php.net/)
[![WordPress 6.5+](https://img.shields.io/badge/wordpress-6.5%2B-21759b.svg)](https://wordpress.org/)

> **Cut request count, drop unused bytes, and ship smaller files — without a build step.**

A surgical CSS + JS optimizer for WordPress. Dequeues what shouldn't load, concatenates what should, minifies through the best engine on the host, and pre-compresses to `.br` + `.gz` on disk so the edge cache hits stay cheap.

## What it does (v0.2)

Four layers, each independently toggleable:

1. **Dequeue** — strips dashicons, admin-bar, emoji, optionally the block library, and any operator-supplied handle list. Runs on `wp_enqueue_scripts` priority 999. Anonymous-only variants gate on `is_user_logged_in()`.
2. **Per-page concat** — groups `WP_Styles::$queue` + `WP_Scripts::$queue` into media / location / strategy buckets, walks dependencies in resolved order, fuses each bucket into a single bundle file. Cache key is `sha1(handle versions + source mtimes + source sizes + inline data)`.
3. **Minifier chain** — `esbuild → matthiasmullie/minify → regex fallback`. The chain picks the first engine that's available + supports the type; on exception or empty output, falls through. Regex is the always-available terminator.
4. **Pre-compression** — every cached file gets a `.br` (quality 11) + `.gz` (level 9) sibling on disk. A self-written `.htaccess` in the cache root negotiates `Content-Encoding` from the client's `Accept-Encoding`.

## Quick start

1. Drop the plugin into `wp-content/plugins/cinch/` and activate.
2. `composer install --no-dev` inside the plugin dir to enable the matthiasmullie strategy. (Optional — the regex fallback works without composer.)
3. (Optional) `export CINCH_ESBUILD_BIN=/usr/local/bin/esbuild` or drop a binary at `wp-content/cinch/bin/esbuild` to engage the esbuild strategy.
4. **Cinch** in the admin menu — overview shows which engine is active per type, dequeue toggles, concat status, and brotli/gzip support.

## Layers in detail

### Dequeue

| Setting | Default | Effect |
|---|---|---|
| `dequeue_dashicons_for_anonymous` | on | Removes the 35 KiB unused dashicons CSS for anonymous visitors |
| `dequeue_admin_bar_for_anonymous` | on | Hides admin bar + its CSS for anonymous visitors |
| `dequeue_emoji` | on | Removes `print_emoji_detection_script` + `wp-emoji-styles` |
| `dequeue_block_library_for_classic_theme` | off | Removes `wp-block-library` + `global-styles` (off because block patterns on classic themes break) |
| `dequeue_extra_handles` | empty | Comma- or newline-separated handle names |

### Concatenation

Bundles live at `wp-content/uploads/cinch/bundles/{css,js}/<hash>.<ext>` plus `.br` / `.gz` siblings.

Bucketing:
- **CSS** — `(media, conditional)`. Conditional-comment stylesheets (IE-only) never bundle.
- **JS** — `(in_footer, strategy)`. Head + footer passes run separately so DOM-mutating footer scripts don't get hoisted. `async` scripts always bypass.

What bypasses the bundler (each handle stays as its own tag):
- External URLs (off-host)
- `.min.css` / `.min.js`
- Handles on the global skip list or `concat_skip_handles`
- Scripts with `async` strategy
- Handles whose source can't be resolved to a local file

Each bundled handle's inline data is preserved:
- `wp_add_inline_style(..., 'after')` → appended after the source
- `wp_localize_script` (`data`) → emitted before the source
- `wp_add_inline_script('before' | 'after')` → emitted in position

### Minifier chain

| Strategy | Detection | Notes |
|---|---|---|
| `esbuild` | `CINCH_ESBUILD_BIN` env → PATH → `wp-content/cinch/bin/esbuild` | AST-based, fastest, best output |
| `matthiasmullie` | `class_exists` on the composer package | Pure PHP, no native dep |
| `regex` | Always | Comment + whitespace pass, conservative on JS |

The chain falls through on exception OR on empty output. Output larger than input is accepted by the chain but discarded by the per-handle filters / bundler.

### Pre-compression

When `function_exists('brotli_compress')` (PHP ext-brotli, usually a separate install), every write produces a `.br` sibling at quality 11. `gzencode` is core, so `.gz` siblings always work. The `.htaccess` snippet in the cache root rewrites requests to the matching sibling when the client's `Accept-Encoding` includes the codec. Cloudflare (or any edge) sees a pre-encoded response and caches it as-is — no on-the-fly recompression.

## Configuration

Cinch → admin menu (option key `cinch_settings`). Every visible toggle is listed there with inline help; this readme covers the wiring.

## REST API

`GET /wp-json/cinch/v1/stats` (requires `manage_options`):

```json
{
  "cached_files":   42,
  "total_bytes":    234567,
  "bundles":        8,
  "precompressed":  84,
  "hits_session":   312,
  "minifier_css":   "esbuild",
  "minifier_js":    "esbuild",
  "brotli_support": true,
  "gzip_support":   true
}
```

## Architecture

```
front-end request
       │
       ▼
   ┌────────────────────────┐
   │ DequeueManager         │ wp_enqueue_scripts @ 999
   │   dashicons / admin-bar│ → remove unused handles
   │   emoji / block-lib    │
   └────────────────────────┘
       │
       ▼
   ┌────────────────────────┐
   │ Bundler                │ wp_print_styles @ 1
   │   resolve deps         │ wp_print_(footer_)scripts @ 1
   │   group buckets        │
   │   minify each source   │
   │   write bundle + .br/.gz
   │   dequeue originals    │
   │   register synthetic   │
   └────────────────────────┘
       │
       ▼
   ┌────────────────────────┐
   │ StyleFilter /           │ style_loader_src   @ 99
   │ ScriptFilter            │ script_loader_src  @ 99
   │   per-handle rewrite    │
   │   for anything Bundler  │
   │   left in the queue     │
   └────────────────────────┘
       │
       ▼
   ┌────────────────────────┐
   │ Cache (uploads/cinch/) │
   │   sha1(src) keyed       │
   │   atomic write          │
   │   .br + .gz siblings    │
   │   .htaccess negotiator  │
   └────────────────────────┘
```

## v0.2 deferred → v0.3

- **Per-page CSS tree-shaking** — render the page in a headless browser, capture used selectors, emit a per-route CSS slice. Big lift (requires Chromium-class runtime), big win (the 11 KiB ~66% unused theme CSS on EUPD would drop to ~4 KiB).
- **HTTP/3 server push hints** — `Link: rel=preload` in headers for the synthetic bundle URLs. Worth measuring but only marginal once concat is on.
- **JS module bundling** — `<script type="module">` chains aren't bucketed yet. Most v0.2 sites don't use them; revisit when usage grows.
- **Source maps for bundles** — currently bare. The chain knows enough to emit them when esbuild is the active engine; gated behind a setting because they leak source paths.

## Known limitations

- **Old files stay on disk** until Purge. Intentional — concurrent in-flight requests on a deploy boundary must never get a zero-byte file.
- **`WP_DEBUG = true`** bypasses minify + concat (dequeue still runs) so developers see the raw enqueue list.
- **`.br` siblings require ext-brotli.** PHP's gzip is core; brotli is a separate native ext that some hosts don't ship. When missing, only `.gz` siblings are written.
- **Bundler runs at print-time.** Themes / plugins that enqueue from `wp_head` or `wp_footer` actions later than priority 1 will miss the bundle. Move enqueues to `wp_enqueue_scripts`.

## Author

[renner.dev](https://renner.dev) · [@rennerdo30](https://github.com/rennerdo30)

## License

MIT.
