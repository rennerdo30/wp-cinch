<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Disk-cache helpers for minified assets.
 *
 * Layout:
 *   wp-content/uploads/<slug>/<sha1>.<ext>
 *
 * The hash is over the SOURCE bytes, so editing a file invalidates
 * the cache automatically — the next request computes a new hash,
 * misses, regenerates, and writes a fresh entry. Old entries stay
 * on disk until "Purge cache" is clicked; this is intentional so
 * concurrent in-flight requests on a deploy boundary never serve
 * a zero-byte file.
 */
final class Cache
{
    private string $slug;
    /** @var array{basedir:string, baseurl:string} */
    private array $uploads;

    public function __construct(string $slug)
    {
        $this->slug    = $slug;
        $this->uploads = $this->resolve_uploads();
    }

    /**
     * @return array{basedir:string, baseurl:string}
     */
    private function resolve_uploads(): array
    {
        $u = wp_get_upload_dir();
        return [
            'basedir' => isset($u['basedir']) ? (string) $u['basedir'] : WP_CONTENT_DIR . '/uploads',
            'baseurl' => isset($u['baseurl']) ? (string) $u['baseurl'] : content_url('uploads'),
        ];
    }

    public function dir(): string
    {
        return rtrim($this->uploads['basedir'], '/') . '/' . $this->slug;
    }

    public function url_base(): string
    {
        return rtrim($this->uploads['baseurl'], '/') . '/' . $this->slug;
    }

    public function ensure_dir(): bool
    {
        $dir = $this->dir();
        if (is_dir($dir)) {
            return true;
        }
        return wp_mkdir_p($dir);
    }

    /**
     * Bundle layout: <cache>/bundles/<type>/<hash>.<ext>
     *
     * @return array{path:string, url:string}
     */
    public function bundle_entry(string $type, string $hash, string $ext): array
    {
        $rel = 'bundles/' . $type . '/' . $hash . '.' . ltrim($ext, '.');
        return [
            'path' => $this->dir() . '/' . $rel,
            'url'  => $this->url_base() . '/' . $rel,
        ];
    }

    public function ensure_bundle_dir(string $type): bool
    {
        $dir = $this->dir() . '/bundles/' . $type;
        if (is_dir($dir)) {
            return true;
        }
        return wp_mkdir_p($dir);
    }

    /**
     * Hash a source path's content + key it under the requested extension.
     * Returns null when the source can't be read.
     *
     * @return array{path:string, url:string, hash:string}|null
     */
    public function entry_for(string $source_path, string $ext, ?string $source_content = null): ?array
    {
        if ($source_content === null) {
            if (!is_readable($source_path)) {
                return null;
            }
            $source_content = (string) @file_get_contents($source_path);
            if ($source_content === '') {
                return null;
            }
        }
        $hash = sha1($source_content);
        $file = $hash . '.' . ltrim($ext, '.');
        return [
            'path' => $this->dir() . '/' . $file,
            'url'  => $this->url_base() . '/' . $file,
            'hash' => $hash,
        ];
    }

    public function is_fresh(string $cached_path): bool
    {
        return is_file($cached_path) && filesize($cached_path) > 0;
    }

    /**
     * Atomic-ish write: write to a temp file first, then rename. Avoids
     * concurrent readers seeing a half-written file.
     *
     * When $precompress is true (default) and the corresponding PHP
     * function is available, also write `<path>.br` (quality 11) and
     * `<path>.gz` (level 9) next to the raw file. Apache/nginx are
     * expected to negotiate Content-Encoding from those siblings (the
     * .htaccess snippet that {@see ensure_htaccess()} writes does
     * exactly that on Apache).
     */
    public function write(string $cached_path, string $content, bool $precompress = true): bool
    {
        if (!$this->ensure_dir()) {
            return false;
        }
        $dir = dirname($cached_path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $tmp = $dir . '/.' . basename($cached_path) . '.' . getmypid() . '.tmp';
        if (false === @file_put_contents($tmp, $content)) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $cached_path)) {
            @unlink($tmp);
            return false;
        }
        if ($precompress) {
            $this->write_precompressed($cached_path, $content);
            $this->ensure_htaccess();
        }
        return true;
    }

    /**
     * Best-effort sibling writers — silent on missing extensions.
     */
    private function write_precompressed(string $cached_path, string $content): void
    {
        if (function_exists('brotli_compress')) {
            $br = @\brotli_compress($content, 11);
            if (is_string($br) && $br !== '') {
                $this->atomic_write($cached_path . '.br', $br);
            }
        }
        if (function_exists('gzencode')) {
            $gz = @gzencode($content, 9);
            if (is_string($gz) && $gz !== '') {
                $this->atomic_write($cached_path . '.gz', $gz);
            }
        }
    }

    private function atomic_write(string $path, string $content): bool
    {
        $dir = dirname($path);
        $tmp = $dir . '/.' . basename($path) . '.' . getmypid() . '.tmp';
        if (false === @file_put_contents($tmp, $content)) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Drop an Apache .htaccess into the cache root that serves the .br
     * and .gz siblings when the client's Accept-Encoding asks for them.
     * Idempotent — re-run is a no-op once the file exists.
     */
    public function ensure_htaccess(): bool
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            if (!$this->ensure_dir()) {
                return false;
            }
        }
        $path = $dir . '/.htaccess';
        if (is_file($path)) {
            return true;
        }
        $body = <<<HT
# wp-cinch — serve precompressed siblings when the client accepts them.
<IfModule mod_rewrite.c>
  RewriteEngine On

  # Brotli first.
  RewriteCond %{HTTP:Accept-Encoding} br
  RewriteCond %{REQUEST_FILENAME}.br -f
  RewriteRule ^(.+)\.(css|js)$ \$1.\$2.br [L]

  # Gzip fallback.
  RewriteCond %{HTTP:Accept-Encoding} gzip
  RewriteCond %{REQUEST_FILENAME}.gz -f
  RewriteRule ^(.+)\.(css|js)$ \$1.\$2.gz [L]
</IfModule>

<IfModule mod_headers.c>
  <FilesMatch "\.css\.br$">
    Header set Content-Type "text/css"
    Header set Content-Encoding "br"
    Header append Vary Accept-Encoding
  </FilesMatch>
  <FilesMatch "\.js\.br$">
    Header set Content-Type "application/javascript"
    Header set Content-Encoding "br"
    Header append Vary Accept-Encoding
  </FilesMatch>
  <FilesMatch "\.css\.gz$">
    Header set Content-Type "text/css"
    Header set Content-Encoding "gzip"
    Header append Vary Accept-Encoding
  </FilesMatch>
  <FilesMatch "\.js\.gz$">
    Header set Content-Type "application/javascript"
    Header set Content-Encoding "gzip"
    Header append Vary Accept-Encoding
  </FilesMatch>
</IfModule>

# Long cache lives at the edge — hashes change on every source edit.
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType text/css "access plus 1 year"
  ExpiresByType application/javascript "access plus 1 year"
</IfModule>
HT;
        return false !== @file_put_contents($path, $body);
    }

    /**
     * @return array{br:bool, gz:bool}
     */
    public function precompression_support(): array
    {
        return [
            'br' => function_exists('brotli_compress'),
            'gz' => function_exists('gzencode'),
        ];
    }

    /**
     * Sweep every file under the cache dir (recursively, so bundles/
     * subdirs are covered). Preserves .htaccess so the negotiation
     * rules survive a purge. Returns the number of files deleted.
     */
    public function purge(): int
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iter  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            if ($entry->isDir()) {
                @rmdir($path);
                continue;
            }
            if ($entry->getFilename() === '.htaccess') {
                continue;
            }
            if (@unlink($path)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Snapshot of the cache for the settings page + REST endpoint.
     * Recursive so bundles + .br + .gz siblings count.
     *
     * @return array{count:int, bytes:int, bundles:int, precompressed:int}
     */
    public function stats(): array
    {
        $dir   = $this->dir();
        $count = 0;
        $bytes = 0;
        $bundles = 0;
        $precompressed = 0;
        if (!is_dir($dir)) {
            return ['count' => 0, 'bytes' => 0, 'bundles' => 0, 'precompressed' => 0];
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            if ($entry->getFilename() === '.htaccess') {
                continue;
            }
            $name = $entry->getFilename();
            $count++;
            $bytes += (int) $entry->getSize();
            if (str_ends_with($name, '.br') || str_ends_with($name, '.gz')) {
                $precompressed++;
            }
            $rel = substr($entry->getPathname(), strlen($dir) + 1);
            if (str_starts_with($rel, 'bundles/')) {
                $bundles++;
            }
        }
        return [
            'count' => $count,
            'bytes' => $bytes,
            'bundles' => $bundles,
            'precompressed' => $precompressed,
        ];
    }

    /**
     * Resolve a URL on the same host to its absolute filesystem path.
     * Returns null when the URL is off-host or doesn't map into the WP
     * install. Strips query strings.
     */
    public function url_to_path(string $url): ?string
    {
        // Strip the version query early; everything else is decoration.
        $url = (string) preg_replace('/\?.*$/', '', $url);

        $home    = home_url();
        $site    = site_url();
        $candidates = [];
        foreach ([$home, $site] as $base) {
            if ($base === '') {
                continue;
            }
            $parsed = wp_parse_url($base);
            if (!is_array($parsed) || empty($parsed['host'])) {
                continue;
            }
            $candidates[] = $parsed['host'];
        }
        $u = wp_parse_url($url);
        if (!is_array($u) || empty($u['host']) || empty($u['path'])) {
            return null;
        }
        if (!in_array($u['host'], $candidates, true)) {
            return null;
        }

        // Try uploads → content → ABSPATH so we never traverse outside.
        $path = (string) $u['path'];

        $tries = [
            [$this->uploads['baseurl'], $this->uploads['basedir']],
            [content_url(),             WP_CONTENT_DIR],
            [includes_url(),            ABSPATH . WPINC],
            [site_url(),                rtrim(ABSPATH, '/')],
        ];
        foreach ($tries as [$base_url, $base_dir]) {
            $base_parsed = wp_parse_url($base_url);
            if (!is_array($base_parsed) || empty($base_parsed['path'])) {
                continue;
            }
            $base_path = (string) $base_parsed['path'];
            if (str_starts_with($path, $base_path)) {
                $rel = substr($path, strlen($base_path));
                $abs = rtrim($base_dir, '/') . '/' . ltrim($rel, '/');
                $real = realpath($abs);
                if ($real !== false && is_file($real)) {
                    // Hard guard: must live under base_dir.
                    $base_real = realpath($base_dir);
                    if ($base_real !== false && str_starts_with($real, $base_real)) {
                        return $real;
                    }
                }
            }
        }
        return null;
    }
}
