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
     */
    public function write(string $cached_path, string $content): bool
    {
        if (!$this->ensure_dir()) {
            return false;
        }
        $dir = dirname($cached_path);
        $tmp = $dir . '/.' . basename($cached_path) . '.' . getmypid() . '.tmp';
        if (false === @file_put_contents($tmp, $content)) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $cached_path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Sweep every file under the cache dir. Returns the number of files
     * deleted. Safe across re-runs.
     */
    public function purge(): int
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach ((array) glob($dir . '/*') as $entry) {
            if (is_file($entry) && @unlink($entry)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Snapshot of the cache for the settings page + REST endpoint.
     *
     * @return array{count:int, bytes:int}
     */
    public function stats(): array
    {
        $dir   = $this->dir();
        $count = 0;
        $bytes = 0;
        if (!is_dir($dir)) {
            return ['count' => 0, 'bytes' => 0];
        }
        foreach ((array) glob($dir . '/*') as $entry) {
            if (is_file($entry)) {
                $count++;
                $bytes += (int) @filesize($entry);
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
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
