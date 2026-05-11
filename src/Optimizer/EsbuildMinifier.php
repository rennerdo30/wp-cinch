<?php

declare(strict_types=1);

namespace Cinch\Optimizer;

/**
 * Pipes code through the `esbuild` binary via `proc_open()`.
 *
 * Resolution order for the binary:
 *   1. env CINCH_ESBUILD_BIN
 *   2. PATH (which / command -v)
 *   3. WP_CONTENT_DIR/cinch/bin/esbuild (drop-in install)
 *
 * If `proc_open` is disabled (some shared hosts) or no binary is found,
 * {@see available()} returns false and the chain falls through. We
 * cache the resolved path in a static so we only probe once per
 * request.
 */
final class EsbuildMinifier implements MinifierInterface
{
    private ?string $bin = null;
    private bool $probed = false;
    private string $version = '';

    public function id(): string
    {
        return 'esbuild';
    }

    public function available(): bool
    {
        return $this->resolve_bin() !== null;
    }

    public function supports(string $type): bool
    {
        return $type === 'css' || $type === 'js';
    }

    public function bin_path(): ?string
    {
        return $this->resolve_bin();
    }

    public function version(): string
    {
        $this->resolve_bin();
        return $this->version;
    }

    public function minify(string $code, string $type): string
    {
        if ($code === '') {
            return '';
        }
        $bin = $this->resolve_bin();
        if ($bin === null) {
            throw new \RuntimeException('esbuild not available');
        }
        $loader = $type === 'css' ? 'css' : 'js';
        $args   = [
            $bin,
            '--minify',
            '--loader=' . $loader,
            '--log-level=silent',
            '--target=es2018',
        ];
        // proc_open with array form avoids shell quoting issues on PHP 7.4+.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($args, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('esbuild proc_open failed');
        }
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($status !== 0) {
            throw new \RuntimeException('esbuild exit ' . $status . ': ' . $stderr);
        }
        if ($stdout === '') {
            throw new \RuntimeException('esbuild emitted empty output');
        }
        return $stdout;
    }

    private function resolve_bin(): ?string
    {
        if ($this->probed) {
            return $this->bin;
        }
        $this->probed = true;

        if (!function_exists('proc_open')) {
            return $this->bin = null;
        }

        $candidates = [];
        $env = getenv('CINCH_ESBUILD_BIN');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        // Drop-in install location — only useful when wp-content is writable.
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = rtrim((string) WP_CONTENT_DIR, '/') . '/cinch/bin/esbuild';
        }

        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path) && is_executable($path)) {
                $this->bin = $path;
                $this->probe_version($path);
                return $this->bin;
            }
        }

        // Fall back to PATH lookup. `command -v` is portable across sh / bash.
        $which = $this->which('esbuild');
        if ($which !== null) {
            $this->bin = $which;
            $this->probe_version($which);
            return $this->bin;
        }

        return $this->bin = null;
    }

    private function which(string $name): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(['sh', '-c', 'command -v ' . escapeshellarg($name)], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        if ($status !== 0 || $out === '') {
            return null;
        }
        return is_file($out) ? $out : null;
    }

    private function probe_version(string $bin): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open([$bin, '--version'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            return;
        }
        fclose($pipes[0]);
        $out = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $this->version = $out;
    }
}
